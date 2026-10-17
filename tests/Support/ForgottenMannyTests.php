<?php

declare(strict_types=1);

use VonNeumannGame\Database\Migration\ForgottenMannyMigration;
use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;

(static function () use ($test): void {
    require_once __DIR__ . '/StorageTestPdo.php';
    $db = new StorageTestPdo(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    (new SchemaInitializer('sqlite'))->initialize($db);
    $directory = sys_get_temp_dir() . '/vng-forgotten-' . bin2hex(random_bytes(6));
    $files = new SectorFileRepository($directory);
    $sector = new SectorCoordinates(0, 0, 0);
    $files->save(new SectorContent($sector));
    $path = $files->getPath($sector);
    $db->exec("INSERT INTO players(username,created_at,updated_at) VALUES('forgotten','now','now')");
    $probe = (new NeumannProbeRepository($db))->createForPlayer((int) $db->lastInsertId(), 'test', $sector);
    $mannies = new MannyRepository($db);
    $manny = $mannies->createForProbe($probe->id, 'forgotten');
    $other = $mannies->createForProbe($probe->id, 'deployed');
    $transaction = new StorageTransaction($db);
    $services = new SectorService($files, new SectorContentGenerator(), 'forgotten-test', $db);
    try {
        $test->assertEquals(false, $manny->isForgotten, 'new Mannies are not forgotten');
        foreach ([$manny, $other] as $unit) { $unit->locationType = Manny::LOCATION_SECTOR; $unit->sector = $sector; $mannies->save($unit); }
        $stale = $mannies->findById($manny->id);
        $transaction->run(fn() => $mannies->setForgotten($manny, true));
        $mannies->save($stale);
        $test->assertEquals(true, $mannies->findById($manny->id)->isForgotten, 'saving a stale deployed object does not erase a newer forgotten flag');
        $test->assertEquals(false, $mannies->findById($other->id)->isForgotten, 'deployment alone does not imply forgotten');
        $before = file_get_contents($path);
        $view = $services->getOrCreateSector($sector);
        $test->assertEquals(SectorManny::STATE_FORGOTTEN, $view->findObjectById('manny-' . $manny->uid)?->getState(), 'forgotten SQL presence supports object lookup');
        $test->assertEquals(1, count($view->getObjects()), 'sector composes a forgotten Manny exactly once');
        $services->saveSector($view);
        $test->assertEquals(false, str_contains(file_get_contents($path), $manny->uid), 'saving an observation never persists forgotten presence');
        $test->assertEquals(null, $files->load($sector)->findObjectById('manny-' . $manny->uid), 'raw descriptor has no forgotten Manny');
        $transaction->run(function () use ($mannies, $probe, $sector): void {
            for ($index = 0; $index < 100; $index++) {
                $unit = $mannies->createForProbe($probe->id, 'dense-' . $index);
                $unit->locationType = Manny::LOCATION_SECTOR; $unit->sector = $sector; $mannies->save($unit);
                $mannies->setForgotten($unit, true);
            }
        });
        $db->metrics->reset();
        $dense = $mannies->forgottenProjections($sector);
        $test->assertEquals(1, $db->metrics->queries, 'forgotten observations use one grouped query');
        $test->assertEquals(101, count($dense), 'dense sector reads every forgotten Manny without per-unit queries');
        $queryPlan = $db->query('EXPLAIN QUERY PLAN SELECT * FROM mannies WHERE is_forgotten=1 AND sector_x=0 AND sector_y=0 AND sector_z=0')->fetchAll(PDO::FETCH_ASSOC);
        $test->assert(str_contains(json_encode($queryPlan), 'idx_mannies_sector'), 'forgotten selection uses the existing sector index');
        $test->assertThrows(function () use ($transaction, $mannies, $manny): void {
            $transaction->run(function () use ($mannies, $manny): void {
                $manny->locationType = Manny::LOCATION_PROBE; $manny->sector = null; $mannies->save($manny);
                throw new RuntimeException('rollback');
            });
        }, RuntimeException::class, 'failed boarding rolls back location and forgotten state together');
        $manny = $mannies->findById($manny->id);
        $test->assertEquals(true, $manny->isForgotten, 'boarding rollback restores forgotten flag');
        $transaction->run(fn() => $mannies->markSectorForgottenForProbe($probe, false));
        $test->assertEquals(false, $mannies->findById($manny->id)->isForgotten, 'movement cancellation clears forgotten while leaving Manny deployed');
        $manny->locationType = Manny::LOCATION_PROBE; $manny->sector = null; $mannies->save($manny);
        $test->assertEquals(false, $mannies->findById($manny->id)->isForgotten, 'successful boarding clears forgotten in SQL on both drivers');
        $transaction->run(fn() => $mannies->markSectorForgottenForProbe($probe, true));
        $test->assertEquals(false, $mannies->findById($manny->id)->isForgotten, 'departure cannot mark an already boarded Manny forgotten');

        $abandonObject = new SectorManny('manny-abandon-test', 'abandon test', 'abandon-test', SectorManny::STATE_ABANDONED);
        $beforeAbandon = file_get_contents($path);
        $test->assertThrows(function () use ($transaction, $services, $sector, $abandonObject): void {
            $transaction->run(function () use ($services, $sector, $abandonObject): void {
                $services->publishAbandonedManny($sector, $abandonObject);
                throw new RuntimeException('rollback abandonment');
            });
        }, RuntimeException::class, 'abandonment failure rolls back its publication intention');
        $test->assertEquals($beforeAbandon, file_get_contents($path), 'rolled back abandonment cannot leak into the JSON file');
        $db->beginTransaction();
        $services->publishAbandonedManny($sector, $abandonObject);
        $db->commit(); // no callback, simulate process loss after commit
        $test->assertEquals(null, $files->load($sector)->findObjectById($abandonObject->getId()), 'abandonment waits for publication after commit');
        $test->assertEquals(SectorManny::STATE_ABANDONED, $services->getOrCreateSector($sector)->findObjectById($abandonObject->getId())->getState(), 'committed pending abandonment remains visible through canonical effects');
        $effectId = (int) $db->query("SELECT id FROM sector_effects WHERE status='pending' ORDER BY id DESC LIMIT 1")->fetchColumn();
        (new \VonNeumannGame\Service\SectorEffectService(new \VonNeumannGame\Repository\Storage\SectorEffectRepository($db), new \VonNeumannGame\Repository\ScheduledEventRepository($db), $services))->apply($effectId);
        $test->assertEquals(SectorManny::STATE_ABANDONED, $files->load($sector)->findObjectById($abandonObject->getId())->getState(), 'independent publisher completes the abandoned descriptor');

        // Legacy data is written directly: the live file repository rejects this format.
        $manny->locationType = Manny::LOCATION_SECTOR; $manny->sector = $sector; $manny->cargoMetals = 0.01; $mannies->save($manny);
        $object = new SectorManny('manny-' . $manny->uid, $manny->name, $manny->uid, SectorManny::STATE_FORGOTTEN, $manny->cargoArray());
        $abandoned = new SectorManny('manny-old', 'old', 'old', SectorManny::STATE_ABANDONED);
        $raw = (new SectorContent($sector, [$abandoned, $object]))->toArray();
        unset($raw['detachedContainers'], $raw['hiddenDetachedContainers'], $raw['planetDroppedContainers']);
        $write = static function (array $data) use ($path): void { file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR)); };
        $write($raw);
        $test->assertThrows(fn() => $files->load($sector), \VonNeumannGame\Sector\SectorStorageException::class, 'runtime rejects legacy forgotten JSON instead of falling back');
        $migration = new ForgottenMannyMigration($db, $directory);
        $plan = $migration->plan();
        $test->assertEquals(1, count($plan['entries']), 'migration selects forgotten JSON only');
        $test->assertEquals(false, $mannies->findById($manny->id)->isForgotten, 'dry run leaves SQL unchanged');
        $test->assertEquals(json_encode($raw, JSON_THROW_ON_ERROR), file_get_contents($path), 'dry run leaves JSON unchanged');
        $duplicate = $raw; $duplicate['objects'][] = $object->toArray(); $write($duplicate);
        $test->assertThrows(fn() => $migration->plan(), RuntimeException::class, 'duplicate forgotten occurrences block migration');
        $mixedDuplicate = $raw;
        $mixedDuplicate['objects'][] = array_replace($object->toArray(), ['state' => 'abandoned']); $write($mixedDuplicate);
        $test->assertThrows(fn() => $migration->plan(), RuntimeException::class, 'conflicting abandoned and forgotten descriptors block conversion');
        $divergent = $raw; $divergent['objects'][1]['cargo']['metals'] = 0.02; $write($divergent);
        $test->assertThrows(fn() => $migration->plan(), RuntimeException::class, 'unexplained cargo divergence blocks migration');
        $explained = $migration->plan([$manny->uid => ['acceptSqlCargo' => true, 'reason' => 'Verified older JSON snapshot.']]);
        $test->assertEquals(['metals'], $explained['entries'][$manny->uid]['cargoDifferences'], 'explicit SQL-authority resolution records the stale JSON difference');
        $write($raw);
        $db->prepare('UPDATE mannies SET probe_id=NULL WHERE id=?')->execute([$manny->id]);
        $test->assertThrows(fn() => $migration->plan(), RuntimeException::class, 'unresolved lost owners block conversion instead of vanishing');
        $db->prepare('UPDATE mannies SET probe_id=? WHERE id=?')->execute([$probe->id, $manny->id]);
        $db->prepare("INSERT INTO sector_effects(operation_id,sector_x,sector_y,sector_z,effect_type,object_id,payload_json,created_at,updated_at) VALUES('old-forgotten',0,0,0,'add_object',? ,?,'now','now')")->execute([$object->getId(), json_encode($object->toArray(), JSON_THROW_ON_ERROR)]);
        $test->assertThrows(fn() => $migration->plan(), RuntimeException::class, 'pending old presence effects block conversion');
        $db->exec("DELETE FROM sector_effects WHERE operation_id='old-forgotten'");
        $manifest = $directory . '/manifest.json';
        file_put_contents($manifest, json_encode($plan, JSON_THROW_ON_ERROR));
        $transaction->run(fn() => $mannies->setForgotten($manny, true)); // crash after SQL commit
        $migration->apply($manifest);
        $test->assertEquals(0.01, $mannies->findById($manny->id)->cargoMetals, 'migration preserves authoritative cargo without credit');
        $test->assertEquals(true, $mannies->findById($manny->id)->isForgotten, 'migration initializes forgotten SQL state');
        $test->assertEquals(SectorManny::STATE_ABANDONED, $files->load($sector)->findObjectById('manny-old')->getState(), 'migration preserves abandoned descriptors');
        $test->assertEquals(null, $files->load($sector)->findObjectById($object->getId()), 'migration removes only forgotten descriptors');
        $test->assertEquals(true, $migration->apply($manifest)['complete'], 'resume after file publication verifies the same result');
        $test->assertEquals(true, json_decode(file_get_contents($manifest), true)['complete'], 'manifest records completion after publication');
        $orphan = $other;
        $orphan->probeId = null; $mannies->save($orphan);
        $orphanObject = new SectorManny('manny-' . $orphan->uid, $orphan->name, $orphan->uid, SectorManny::STATE_FORGOTTEN, $orphan->cargoArray());
        $orphanRaw = $raw; $orphanRaw['objects'] = [$abandoned->toArray(), $orphanObject->toArray()]; $write($orphanRaw);
        $orphanResolution = [$orphan->uid => ['action' => 'abandon', 'reason' => 'Owner loss confirmed on maintenance snapshot.']];
        $orphanManifest = $directory . '/orphan-manifest.json';
        $migration->apply($orphanManifest, $orphanResolution);
        $test->assertEquals(SectorManny::STATE_ABANDONED, $files->load($sector)->findObjectById($orphanObject->getId())->getState(), 'explicit orphan resolution publishes an abandoned descriptor');
        $test->assertEquals(false, $mannies->findById($orphan->id)->isForgotten, 'orphan abandonment clears forgotten flag without recreating ownership');
        $test->assertEquals(true, $migration->apply($orphanManifest)['complete'], 'orphan abandonment is resumable without duplication');
        $missing = $orphanRaw; $missing['objects'][1]['id'] = 'manny-missing'; $missing['objects'][1]['mannyUid'] = 'missing'; $write($missing);
        $test->assertThrows(fn() => $migration->plan(), RuntimeException::class, 'missing SQL Manny blocks migration without an explicit orphan decision');
        $migration->apply($directory . '/missing-manifest.json', ['missing' => ['action' => 'abandon', 'reason' => 'Owner and SQL row no longer exist.']]);
        $test->assertEquals(null, $mannies->findByUid('missing'), 'orphan migration does not invent a replacement SQL Manny');
        $test->assertEquals(SectorManny::STATE_ABANDONED, $files->load($sector)->findObjectById('manny-missing')->getState(), 'resolved JSON-only orphan keeps its abandoned representation');
        $db->exec('ALTER TABLE mannies DROP COLUMN is_forgotten');
        $test->assertEquals(true, $migration->ensureSchema(), 'explicit migration adds missing forgotten column');
        $test->assertEquals(false, $migration->ensureSchema(), 'forgotten schema upgrade is resumable');
        $sqlitePath = $directory . '/cli.sqlite';
        $db->exec('VACUUM INTO ' . $db->quote($sqlitePath));
        $configPath = $directory . '/database.json';
        file_put_contents($configPath, json_encode(['driver' => 'sqlite', 'path' => $sqlitePath], JSON_THROW_ON_ERROR));
        $hashBeforeCli = hash_file('sha256', $sqlitePath);
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/one-shot-scripts/migrate-forgotten-mannies.php')
            . ' --database-config=' . escapeshellarg($configPath) . ' --universe-path=' . escapeshellarg($directory);
        exec($command . ' 2>&1', $output, $exitCode);
        $test->assertEquals(0, $exitCode, 'migration CLI dry run accepts a canonical SQLite universe');
        $test->assertEquals(false, json_decode(implode("\n", $output), true)['apply'] ?? null, 'migration CLI defaults to simulation');
        $test->assertEquals($hashBeforeCli, hash_file('sha256', $sqlitePath), 'migration CLI simulation leaves the database file unchanged');

    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
})();
