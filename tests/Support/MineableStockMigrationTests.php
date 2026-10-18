<?php
declare(strict_types=1);

use VonNeumannGame\Database\Migration\FailedMannyMigration;
use VonNeumannGame\Database\Migration\MaintenanceFiles;
use VonNeumannGame\Database\Migration\MineableStockMigration;
use VonNeumannGame\Database\Migration\MineableStockMigrationPlan;
use VonNeumannGame\Database\Migration\WorldMaintenanceBackup;
use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\MineableResourceAmounts;
use VonNeumannGame\Repository\StorageMigrationRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;

require_once __DIR__ . '/MiningRetryFixture.php';
(static function () use ($test): void {
    $directory = sys_get_temp_dir() . '/vng-full-stock-migration-' . bin2hex(random_bytes(6));
    mkdir($directory . '/universe', 0700, true);
    $universe = $directory . '/universe';
    $db = new PDO('sqlite:' . $directory . '/world.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON'); (new SchemaInitializer('sqlite'))->initialize($db);
    $db->exec('CREATE TABLE migration_precision_fixture(id INTEGER PRIMARY KEY AUTOINCREMENT, amount REAL NOT NULL)');
    $db->exec('INSERT INTO migration_precision_fixture(id,amount) VALUES(1,1.2345678901234567),(50,0)');
    $db->exec('DELETE FROM migration_precision_fixture WHERE id=50');
    $exactFloat = $db->query('SELECT amount FROM migration_precision_fixture WHERE id=1')->fetchColumn();
    $f = miningAtomicFixture($db, $universe);
    $a = new SectorCoordinates(20, 20, 0); $b = new SectorCoordinates(22, 20, 0);
    $amounts = array_replace(MineableResourceAmounts::zero(), ['metals' => 10.1234]);
    $rock = new Asteroid('migration-rock', 'Rock', 'iron', ['metals'], 'small', 1, 1, resourceAmounts: $amounts);
    $f['files']->save(new SectorContent($a, [$rock]));
    (new StorageTransaction($db))->run(fn() => $f['stocks']->create('migration-rock-create', $rock->getId(), $amounts));
    [$probe, $manny] = miningAtomicActor($f, 'migration-failed-miner', $a);
    $manny = $f['mining']->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.1);
    $event = miningRetryDue($db, $f, $manny);
    $f['events']->markFailed($f['events']->claim($event), new RuntimeException('Historical failure'));
    // Recreate the actual old representation; no SQL stock/identity existed before the migration.
    $db->exec('DELETE FROM mineable_object_stocks'); $db->exec('DELETE FROM mineable_object_identities');
    $legacyA = ['coordinates' => $a->toArray(), 'objects' => [$rock->toArray()]];
    $legacyB = ['coordinates' => $b->toArray(), 'objects' => [array_replace($rock->toArray(), ['resourceAmounts' => array_replace($amounts, ['metals' => 20.0])]),
        ['id' => 'planet-preserved', 'type' => 'planet', 'resourceAmounts' => ['metals' => 123.0]]]];
    MaintenanceFiles::json($f['files']->getPath($a), $legacyA); MaintenanceFiles::json($f['files']->getPath($b), $legacyB);
    $pathA = $f['files']->getPath($a); $pathB = $f['files']->getPath($b);
    $hashA = hash_file('sha256', $pathA); $hashB = hash_file('sha256', $pathB);
    $job = $directory . '/manifest';
    $recall = fn(string $id, array $events): array => (new FailedMannyMigration($db, $f['mining']))->apply($id, $events);
    try {
        $plan = (new MineableStockMigrationPlan($db, $universe))->build('dry-run');
        $test->assertEquals(2, count($plan['sources']), 'migration plans both duplicate source occurrences');
        $test->assertEquals(1, count(array_filter($plan['sources'], fn($s) => $s['newId'] !== $s['objectId'])), 'migration preserves exactly one deterministic occurrence');
        $test->assertEquals($hashA, hash_file('sha256', $pathA), 'planning never rewrites files');
        $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM mineable_object_stocks')->fetchColumn(), 'planning never imports stocks');

        foreach (['stocks:batch', 'descriptors:file', 'recalls:committed'] as $point) {
            $stopped = false;
            $migration = new MineableStockMigration($db, $universe, static function (string $at) use ($point, &$stopped): void {
                if ($at === $point && !$stopped) { $stopped = true; throw new RuntimeException('injected interruption'); }
            });
            try { $migration->apply($job, [], $recall); $test->assert(false, 'migration interruption reached ' . $point); }
            catch (RuntimeException $error) { $test->assertEquals('injected interruption', $error->getMessage(), 'migration interruption reached ' . $point); }
            try { (new StorageMigrationRepository($db))->assertReady(); $test->assert(false, 'startup must refuse partial migration'); }
            catch (RuntimeException) { $test->assert(true, 'startup refuses partial migration at ' . $point); }
            try { (new \VonNeumannGame\AppFactory(dirname(__DIR__, 2)))->apiKernel($db); $test->assert(false, 'API factory must refuse a partial migration'); }
            catch (RuntimeException $e) { $test->assert(str_contains($e->getMessage(), 'incomplete'), 'API factory checks maintenance gate before exposing the world'); }
        }
        $migration = new MineableStockMigration($db, $universe);
        $result = $migration->apply($job, [], $recall);
        $test->assert($result['complete'], 'interrupted migration completes with its original manifest');
        (new StorageMigrationRepository($db))->assertReady();
        $test->assertEquals(1, $result['verification']['renamed'], 'resume keeps the same renaming plan');
        $test->assertEquals(2, (int) $db->query('SELECT COUNT(*) FROM mineable_object_stocks')->fetchColumn(), 'resume imports each source exactly once');
        $newA = MaintenanceFiles::read($pathA); $newB = MaintenanceFiles::read($pathB);
        $test->assert($newA['objects'][0]['id'] !== $newB['objects'][0]['id'], 'duplicate sector sources now have distinct IDs');
        $test->assert(!isset($newA['objects'][0]['resourceAmounts']) && !isset($newB['objects'][0]['resourceAmounts']), 'covered stocks are removed from descriptors');
        $test->assertEquals($legacyB['objects'][1], $newB['objects'][1], 'planet stocks and descriptor remain unchanged');
        $test->assertEquals(10.1234, $f['stocks']->find($newA['objects'][0]['id'])['amounts']['metals'], 'first source stock is conserved individually');
        $test->assertEquals(20.0, $f['stocks']->find($newB['objects'][0]['id'])['amounts']['metals'], 'second source stock is conserved individually');
        $test->assertEquals(0.0, $f['storage']->resourceStock($probe, 'metals'), 'recalling failed overdue mining never replays the harvest');
        $returned = $f['mannies']->findById($manny->id);
        $test->assertEquals('returning', $returned->currentTask, 'failed mining is recalled through business rules');
        $test->assert($returned->taskScheduledEventId !== $event->id, 'recall has a new event');
        $test->assertEquals('cancelled', $f['events']->findById($event->id)->status, 'failed event is cancelled');
        $returnEvent = $returned->taskScheduledEventId;
        $migration->apply($job, [], $recall);
        $test->assertEquals($returnEvent, $f['mannies']->findById($manny->id)->taskScheduledEventId, 'repeating migration never schedules a second recall');

        (new StorageTransaction($db))->run(fn() => $f['stocks']->debit($newA['objects'][0]['id'], array_replace(MineableResourceAmounts::zero(), ['metals' => 0.0001])));
        try { $migration->apply($job, [], $recall); $test->assert(false, 'resume must refuse a changed stock'); }
        catch (RuntimeException $error) { $test->assert(str_contains($error->getMessage(), 'stock'), 'resume refuses replenishing a modified SQL stock'); }
        (new WorldMaintenanceBackup($db, $universe))->restore($job . '/backup');
        $test->assertEquals($hashA, hash_file('sha256', $pathA), 'coordinated restore recovers exact first JSON bytes');
        $test->assertEquals($hashB, hash_file('sha256', $pathB), 'coordinated restore recovers exact second JSON bytes');
        $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM mineable_object_stocks')->fetchColumn(), 'coordinated restore recovers the SQL inventory before import');
        $test->assertEquals('failed', $f['events']->findById($event->id)->status, 'restore recovers the original scheduler state');
        $test->assertEquals('mining', $f['mannies']->findById($manny->id)->currentTask, 'restore recovers the original Manny task');
        $test->assertEquals([], $db->query('PRAGMA foreign_key_check')->fetchAll(), 'restored SQL preserves all foreign keys');
        $test->assert($exactFloat === $db->query('SELECT amount FROM migration_precision_fixture WHERE id=1')->fetchColumn(), 'restoration preserves full floating-point precision beyond four resource decimals');
        $test->assertEquals(50, (int) $db->query("SELECT seq FROM sqlite_sequence WHERE name='migration_precision_fixture'")->fetchColumn(), 'restoration preserves the deleted high-water autoincrement ID');

        // Same-sector duplicate references must be explicitly resolved by occurrence.
        $duplicate = $legacyA; $duplicate['objects'][] = $rock->toArray();
        MaintenanceFiles::json($pathA, $duplicate);
        try { (new MineableStockMigrationPlan($db, $universe))->build('ambiguity'); $test->assert(false, 'ambiguous active task must block'); }
        catch (RuntimeException $error) { $test->assert(str_contains($error->getMessage(), 'Ambiguous reference'), 'same-sector duplicate target blocks without a resolution'); }
        $db->beginTransaction();
        $audit = (new \VonNeumannGame\Database\Migration\MineableObjectStockAudit(100, 10000, true))->inspect($db, $universe);
        $db->rollBack();
        $occurrence = substr($pathA, strlen($universe . '/sectors/')) . '#/objects/0';
        $resolutions = [];
        foreach ($audit['references'] as $reference) {
            if ($reference['active'] && $reference['objectId'] === $rock->getId()) { $resolutions['references'][$reference['location']] = ['occurrence' => $occurrence, 'reason' => 'Reviewed fixture target']; }
        }
        $resolved = (new MineableStockMigrationPlan($db, $universe))->build('resolved', resolutions: $resolutions);
        $test->assertEquals(3, count($resolved['sources']), 'explicit occurrence decisions resolve same-sector duplicates');
        $test->assert(count($resolved['decisions']) > 0, 'ambiguous reference decisions are retained in the immutable plan');
    } finally {
        $db = null;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
})();

(static function () use ($test): void {
    $directory = sys_get_temp_dir() . '/vng-migration-restitution-' . bin2hex(random_bytes(6));
    $universe = $directory . '/universe'; mkdir($universe . '/sectors', 0700, true);
    $db = new MiningRetryFaultPdo(); (new SchemaInitializer('sqlite'))->initialize($db);
    $f = miningAtomicFixture($db, $universe); $sector = new SectorCoordinates(10, 10, 0);
    $rock = new Asteroid('assembly-stock', 'Rock', 'iron', ['metals'], 'small', 1, 1, resourceAmounts: MineableResourceAmounts::zero());
    $legacy = (new SectorContent($sector, [$rock]))->toDescriptor();
    $legacy['objects'][0] = $rock->toArray();
    MaintenanceFiles::json($f['files']->getPath($sector), $legacy);
    [$probe, $manny] = miningAtomicActor($f, 'failed-assembly', $sector);
    $manny->locationType = \VonNeumannGame\Domain\Manny::LOCATION_SECTOR; $manny->sector = $sector; $manny->storageContainerId = null;
    $manny->currentTask = \VonNeumannGame\Domain\Manny::TASK_ASSEMBLING_PROBE;
    $manny->taskStartedAt = gmdate('c', time() - 60); $manny->taskEndsAt = gmdate('c', time() - 1);
    $manny->taskPayload = ['consumedItems' => [['uid' => 'assembly-reserved', 'type' => 'atomic_printer', 'name' => 'Printer', 'containerSpace' => 1, 'metadata' => []]]];
    $f['mannies']->save($manny);
    $event = $f['events']->findById($f['mannies']->findById($manny->id)->taskScheduledEventId);
    $f['events']->markFailed($f['events']->claim($event), new RuntimeException('Historical assembly failure'));
    $migration = new MineableStockMigration($db, $universe); $job = $directory . '/job';
    $recall = fn(string $id, array $events): array => (new FailedMannyMigration($db, $f['mining']))->apply($id, $events);
    try {
        $db->faultSql = 'INSERT INTO storage_migration_recalls'; $db->faultCode = 19;
        try { $migration->apply($job, [], $recall); $test->assert(false, 'recall receipt failure must roll back restitution'); }
        catch (PDOException) { $test->assert(true, 'recall receipt failure rolls back its business transaction'); }
        $objects = MaintenanceFiles::read($f['files']->getPath($sector))['objects'];
        $test->assertEquals(1, count($objects), 'failed recall never publishes assembly ingredients before commit');
        $test->assertEquals('failed', $f['events']->findById($event->id)->status, 'failed recall leaves the original failed event available for retry');
        $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM storage_migration_recalls')->fetchColumn(), 'failed recall leaves no success receipt');
        $migration->apply($job, [], $recall);
        $objects = MaintenanceFiles::read($f['files']->getPath($sector))['objects'];
        $test->assertEquals(2, count($objects), 'successful assembly recall publishes its ingredient exactly once');
        $test->assertEquals('drifting_item', $objects[1]['type'], 'business recall preserves assembly ingredient as a drifting item');
        $test->assertEquals(1, count(MaintenanceFiles::read($job . '/recall-files.json')), 'structural recall has an independently replayed publication proof');
        $returnEvent = $f['mannies']->findById($manny->id)->taskScheduledEventId;
        $migration->apply($job, [], $recall);
        $test->assertEquals($returnEvent, $f['mannies']->findById($manny->id)->taskScheduledEventId, 'replayed structural recall keeps one return');
        $test->assertEquals($objects, MaintenanceFiles::read($f['files']->getPath($sector))['objects'], 'replayed structural recall does not duplicate its ingredient');
    } finally {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
})();

(static function () use ($test): void {
    $directory = sys_get_temp_dir() . '/vng-migration-matrix-' . bin2hex(random_bytes(6));
    $universe = $directory . '/universe'; mkdir($universe . '/sectors', 0700, true);
    $db = new PDO('sqlite:' . $directory . '/world.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new SchemaInitializer('sqlite'))->initialize($db);
    $files = new \VonNeumannGame\Sector\SectorFileRepository($universe);
    $a = new SectorCoordinates(0, 0, 0); $b = new SectorCoordinates(2, 0, 0);
    $amounts = array_replace(MineableResourceAmounts::zero(), ['metals' => 17.123456]);
    $rock = (new Asteroid('mtr_0123456789abcdef', 'Rock', 'iron', ['metals'], 'small', 1, 1, resourceAmounts: $amounts))->toArray();
    $empty = array_replace($rock, ['id' => 'empty']); unset($empty['resourceAmounts']);
    $partial = array_replace($rock, ['id' => 'partial']); unset($partial['resourceAmounts']['ice']);
    $wreck = ['id' => 'wreck', 'type' => 'dormant_construct', 'subtype' => 'others_mothership_wreck', 'resourceAmounts' => MineableResourceAmounts::zero()];
    $excluded = ['id' => 'excluded', 'type' => 'dormant_construct', 'resourceAmounts' => ['metals' => 999]];
    $nested = ['id' => 'system', 'type' => 'solar_system', 'orbitalBodies' => [['object' => $partial]]];
    $legacy = ['coordinates' => $a->toArray(), 'objects' => [$rock, $empty, $wreck, $excluded, $nested]];
    MaintenanceFiles::json($files->getPath($a), $legacy);
    MaintenanceFiles::json($files->getPath($b), ['coordinates' => $b->toArray(), 'objects' => [$rock]]);
    $trajectory = $db->prepare('INSERT INTO asteroid_trajectories(uid,asteroid_id,mode,status,origin_sector_x,origin_sector_y,origin_sector_z,current_sector_x,current_sector_y,current_sector_z,asteroid_mass_earth,next_transition_at,maximum_sector_crossings,asteroid_snapshot_json,attachments_snapshot_json,created_at,updated_at) VALUES(?,?,?,?,0,0,0,?,0,0,1,?,20,?,?,?,?)');
    $now = gmdate('c');
    $transit = array_replace($rock, ['id' => 'transit']);
    $trajectory->execute(['transit-trajectory', 'transit', 'launch', 'crossing_sector', 4, $now, json_encode($transit), '[]', $now, $now]);
    $trajectory->execute(['duplicate-trajectory', $rock['id'], 'launch', 'coasting', 2, $now, json_encode(array_replace($rock, ['resourceAmounts' => array_replace($amounts, ['metals' => 999])])), '[]', $now, $now]);
    $history = array_replace($rock, ['id' => 'historical-only']);
    $trajectory->execute(['history-trajectory', 'historical-only', 'launch', 'lost', 2, $now, json_encode($history), '[]', $now, $now]);
    $effects = new \VonNeumannGame\Repository\Storage\SectorEffectRepository($db);
    $added = array_replace($rock, ['id' => 'added']);
    $effectId = $effects->create('migration-add', $a, 'add_object', 'added', $added, $now);
    $job = $directory . '/job';
    try {
        try { (new MineableStockMigrationPlan($db, $universe))->build('pending'); $test->assert(false, 'pending effects block a plain dry run'); }
        catch (RuntimeException $e) { $test->assert(str_contains($e->getMessage(), 'pending_sector_effect'), 'pending effects are diagnosed before planning'); }
        $drain = new \VonNeumannGame\Database\Migration\LegacySectorEffectDrain($db, $universe, static fn() => throw new RuntimeException('effect interrupted'));
        try { $drain->apply($directory); } catch (RuntimeException $e) { $test->assertEquals('effect interrupted', $e->getMessage(), 'interrupt legacy intention between file and SQL receipt'); }
        (new \VonNeumannGame\Database\Migration\LegacySectorEffectDrain($db, $universe))->apply($directory);
        $test->assertEquals('applied', $effects->find($effectId)['status'], 'legacy effect resumes after file publication');
        $test->assertEquals(1, count(array_filter(MaintenanceFiles::read($files->getPath($a))['objects'], fn($o) => $o['id'] === 'added')), 'legacy replay never adds an object twice');
        $patched = array_replace($added, ['name' => 'Reviewed name']);
        $effects->create('migration-patch', $a, 'patch_objects', 'added', ['changes' => [['id' => 'added', 'before' => $added, 'after' => $patched]]], $now);
        $temporarySector = new SectorCoordinates(6, 0, 0);
        $effects->create('migration-create', $temporarySector, 'create_sector', '', ['coordinates' => $temporarySector->toArray(), 'objects' => [['id' => 'temporary-system', 'type' => 'solar_system', 'orbitalBodies' => [['object' => array_replace($rock, ['id' => 'consumed'])]]]]], $now);
        $effects->create('migration-consume', $temporarySector, 'consume_object', 'consumed', [], $now);
        (new \VonNeumannGame\Database\Migration\LegacySectorEffectDrain($db, $universe))->apply($directory);
        $test->assertEquals('Reviewed name', MaintenanceFiles::read($files->getPath($a))['objects'][5]['name'], 'legacy patch preserves the intended before/after transformation');
        $test->assertEquals([], MaintenanceFiles::read($files->getPath($temporarySector))['objects'][0]['orbitalBodies'], 'legacy consumption removes the orbital wrapper from a newly published sector');
        $plan = (new MineableStockMigrationPlan($db, $universe))->build('matrix');
        $test->assertEquals(7, count($plan['sources']), 'nested, transit, exhausted and wreck sources are inventoried');
        $test->assert(in_array('historical-only', $plan['reservedIds'], true), 'historical-only identity is permanently reserved');
        $migration = new MineableStockMigration($db, $universe);
        $migration->apply($job, [], static fn() => []);
        $stocks = new \VonNeumannGame\Repository\MineableObjectStockRepository($db);
        $test->assertEquals(MineableResourceAmounts::zero(), $stocks->find('empty')['amounts'], 'missing asteroid resources explicitly migrate to zero');
        $test->assertEquals(17.1235, $stocks->find('partial')['amounts']['metals'], 'partial resources preserve metals at business precision');
        $test->assertEquals(0.0, $stocks->find('partial')['amounts']['ice'], 'only absent resource becomes zero');
        $test->assertEquals(MineableResourceAmounts::zero(), $stocks->find('wreck')['amounts'], 'exhausted wreck retains a zero SQL stock');
        $test->assertEquals(17.1235, $stocks->find('transit')['amounts']['metals'], 'transit snapshot is imported once');
        $new = MaintenanceFiles::read($files->getPath($b))['objects'][0]['id'];
        $test->assert((bool) preg_match('/^mtr_[a-f0-9]{16}$/D', $new), 'duplicate motorized asteroid keeps its canonical ID prefix');
        $row = $db->query("SELECT * FROM asteroid_trajectories WHERE uid='duplicate-trajectory'")->fetch();
        $test->assertEquals($new, $row['asteroid_id'], 'trajectory reference resolves to its sector occurrence');
        $snapshot = json_decode($row['asteroid_snapshot_json'], true);
        $test->assertEquals($new, $snapshot['id'], 'trajectory snapshot follows the same occurrence');
        $test->assert(!array_key_exists('resourceAmounts', $snapshot), 'active stale snapshot cannot reintroduce its larger stock');
        $test->assertEquals(17.1235, $stocks->find($new)['amounts']['metals'], 'current sector stock wins over non-transit snapshot');
        $test->assertEquals(json_encode($history), $db->query("SELECT asteroid_snapshot_json FROM asteroid_trajectories WHERE uid='history-trajectory'")->fetchColumn(), 'terminal snapshot remains historical');
        $data = MaintenanceFiles::read($files->getPath($a));
        $test->assertEquals($excluded, $data['objects'][3], 'excluded construct keeps positive JSON resources');
        $test->assert(!isset($data['objects'][4]['orbitalBodies'][0]['object']['resourceAmounts']), 'nested source descriptor is canonical');
        $data['objects'][3]['resourceAmounts']['metals']++;
        MaintenanceFiles::json($files->getPath($a), $data);
        try { $migration->verify($job); $test->assert(false, 'unplanned sector mutation must fail verification'); }
        catch (RuntimeException $e) { $test->assert(str_contains($e->getMessage(), 'transformations'), 'verification protects excluded resources too'); }
        $other = new PDO('sqlite:' . $directory . '/other.sqlite');
        try { (new MineableStockMigration($other, $universe))->verify($job); $test->assert(false, 'wrong SQL/universe pair must block'); }
        catch (RuntimeException $e) { $test->assert(str_contains($e->getMessage(), 'pair'), 'verification refuses a manifest for another database'); }
        $backup = MaintenanceFiles::read($job . '/backup/backup.json');
        file_put_contents($job . '/backup/sectors.rows', 'broken');
        try { (new WorldMaintenanceBackup($db, $universe))->restore($job . '/backup'); $test->assert(false, 'corrupt backup must block restoration'); }
        catch (RuntimeException $e) { $test->assert(str_contains($e->getMessage(), 'checksum'), 'corrupt backup is rejected before any SQL destruction'); }
        $test->assertEquals(7, (int) $db->query('SELECT COUNT(*) FROM mineable_object_stocks')->fetchColumn(), 'failed restoration leaves SQL intact');
    } finally {
        $db = null;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
})();
