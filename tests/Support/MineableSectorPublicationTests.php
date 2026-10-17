<?php

declare(strict_types=1);

use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Database\Migration\SectorCreationSchemaMigration;
use VonNeumannGame\Domain\MineableResourceAmounts;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\Storage\SectorEffectRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\MineableDescriptors;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\SectorStorageException;
use VonNeumannGame\Service\SectorEffectService;

(static function () use ($test): void {
    require_once __DIR__ . '/StorageTestPdo.php';
    $db = new StorageTestPdo(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    $db->exec('PRAGMA foreign_keys=ON');
    (new SchemaInitializer('sqlite'))->initialize($db);
    $directory = sys_get_temp_dir() . '/vng-mineable-publication-' . bin2hex(random_bytes(6));
    $files = new SectorFileRepository($directory);
    $generator = new SectorContentGenerator(['categoryWeights' => ['empty' => 0, 'stellar_simple' => 0, 'stellar_binary' => 0, 'asteroids' => 100, 'dust_cloud' => 0, 'dead_star' => 0, 'black_hole' => 0]]);
    $sectors = new SectorService($files, $generator, 'publication-test', $db);
    $effects = new SectorEffectRepository($db);
    $outbox = new SectorEffectService($effects, new ScheduledEventRepository($db), $sectors);
    $stocks = $sectors->mineableStocks();
    $transaction = new StorageTransaction($db);
    $zero = MineableResourceAmounts::zero();
    $metals = static fn(float $amount): array => array_replace($zero, ['metals' => $amount]);
    $rock = static fn(string $id): Asteroid => new Asteroid($id, 'Preserved name', 'iron', ['metals'], 'small', 0.001, 0.01, resourceAmounts: $metals(10));
    $coordinates = new SectorCoordinates(0, 0, 0);
    try {
        $sector = $sectors->getOrCreateSector($coordinates);
        $test->assert(count($sector->mineableIds()) > 0, 'generated sources have SQL stocks before publication');
        foreach ($sector->mineableIds() as $id) {
            $test->assertEquals($stocks->find($id)['amounts'], $sector->findObjectById($id)->getResourceAmounts(), 'generated source is hydrated from its committed SQL stock');
        }
        $test->assert(!str_contains((string) file_get_contents($files->getPath($coordinates)), 'resourceAmounts'), 'generated asteroid sector contains descriptors only');
        $originalName = $sector->findObjectById($sector->mineableIds()[0])->getName();
        $id = $sector->mineableIds()[0];
        $transaction->run(fn() => $stocks->delete($id));
        $loaded = $sectors->getOrCreateSector($coordinates);
        $test->assertEquals($zero, $loaded->findObjectById($id)->getResourceAmounts(), 'missing SQL row hydrates zeros without reconstructing from mass');
        $test->assertEquals($originalName, $loaded->findObjectById($id)->getName(), 'exhaustion never renames an existing source');
        $test->assertEquals(null, $stocks->find($id)['version'], 'reading a missing stock never repairs it');

        // Raw PDO commit models a process that never runs an afterCommit callback.
        $pendingCoordinates = new SectorCoordinates(100, 100, 0);
        $db->beginTransaction();
        $prepared = $sectors->getSectorForEffects($pendingCoordinates);
        $pendingIds = $prepared->mineableIds();
        $test->assert(!$files->exists($pendingCoordinates), 'preparing a new sector in a transaction writes no file');
        $test->assertThrows(fn() => $sectors->getOrCreateSector($pendingCoordinates), SectorStorageException::class, 'game reads cannot treat a prepared sector as published');
        $db->commit();
        $pending = iterator_to_array($effects->pendingInSector($pendingCoordinates));
        $test->assertCount(1, $pending, 'committed creation has a durable publication intention');
        $test->assertEquals(1, (int) $db->query("SELECT COUNT(*) FROM scheduled_events WHERE entity_type='sector_effect' AND entity_id=" . $pending[0]['id'])->fetchColumn(), 'scheduler recovery is committed with creation');
        $outbox->apply((int) $pending[0]['id']);
        $test->assertEquals($pendingIds, $sectors->getOrCreateSector($pendingCoordinates)->mineableIds(), 'recovery publishes exactly the recorded IDs');
        $db->prepare("UPDATE sector_effects SET status='pending' WHERE id=?")->execute([$pending[0]['id']]);
        $first = $pendingIds[0];
        $transaction->run(fn() => $stocks->delete($first));
        $outbox->apply((int) $pending[0]['id']);
        $test->assertEquals(null, $stocks->find($first)['version'], 'replay after file publication never restores a deleted stock');

        $rollbackCoordinates = new SectorCoordinates(200, 200, 0);
        $before = (int) $db->query('SELECT COUNT(*) FROM mineable_object_stocks')->fetchColumn();
        $db->beginTransaction();
        $sectors->getSectorForEffects($rollbackCoordinates);
        $db->rollBack();
        $test->assert(!$files->exists($rollbackCoordinates), 'rollback before commit leaves no sector file');
        $test->assertEquals($before, (int) $db->query('SELECT COUNT(*) FROM mineable_object_stocks')->fetchColumn(), 'rollback removes generated stocks and identities');
        $test->assertCount(0, iterator_to_array($effects->pendingInSector($rollbackCoordinates)), 'rollback leaves no orphaned creation intention');

        $transaction->run(fn() => $stocks->reserveHistoricalIdentity('collision-rock'));
        $created = $sectors->createMineable($coordinates, 'explicit-collision', $rock('collision-rock'));
        $test->assert($created->getId() !== 'collision-rock', 'explicit creation resolves a reserved historical ID');
        $test->assert($files->load($coordinates)->findObjectById($created->getId()) instanceof Asteroid, 'publication uses the final collision-resolved ID');
        $transaction->run(fn() => $stocks->debit($created->getId(), $metals(3)));
        $sectors->createMineable($coordinates, 'explicit-collision', $rock('collision-rock'));
        $test->assertEquals(7.0, $stocks->find($created->getId())['amounts']['metals'], 'explicit creation replay cannot refill a mined source');

        $db->beginTransaction();
        $sectors->createMineable($coordinates, 'unpublished-rock', $rock('unpublished-rock'));
        $db->commit();
        $test->assertEquals(null, $sectors->getOrCreateSector($coordinates)->findObjectById('unpublished-rock'), 'pending add_object is not proof of mineable presence');
        $pendingRock = $effects->findByOperation('unpublished-rock');
        $outbox->apply((int) $pendingRock['id']);
        $test->assert($sectors->getOrCreateSector($coordinates)->findObjectById('unpublished-rock') instanceof Asteroid, 'published descriptor establishes mineable presence');
        $files->mutate($coordinates, static fn(SectorContent $content) => $content->removeObjectById('unpublished-rock'));
        $db->prepare("UPDATE sector_effects SET status='pending' WHERE id=?")->execute([$pendingRock['id']]);
        $outbox->apply((int) $pendingRock['id']);
        $test->assertEquals(null, $sectors->getOrCreateSector($coordinates)->findObjectById('unpublished-rock'), 'obsolete publication cannot resurrect a subsequently removed object');

        $planet = new Planet('kept-planet', 'Planet', 'rocky', 1, 1, true, 0, ['metals'], resourceAmounts: $metals(42));
        $mixed = new SectorContent(new SectorCoordinates(300, 300, 0), [$planet, $rock('unregistered-rock'), DormantConstruct::fromOthersAuxiliary('kept-aux')]);
        $files->save($mixed);
        $read = $sectors->getOrCreateSector($mixed->getCoordinates());
        $test->assertEquals($planet->getResourceAmounts(), $read->findObjectById('kept-planet')->getResourceAmounts(), 'planet stock is retained outside the SQL stock scope');
        $test->assertEquals(5.0, (float) $read->findObjectById('dormant-others-auxiliary-kept-aux')->getResourceAmounts()['metals'], 'auxiliary construct stock remains in its descriptor');
        $test->assertEquals($zero, $read->findObjectById('unregistered-rock')->getResourceAmounts(), 'generic file save never initializes a SQL stock');
        $read->replaceObject($rock('unregistered-rock'));
        $sectors->saveSector($read);
        $test->assertEquals($zero, $sectors->getOrCreateSector($mixed->getCoordinates())->findObjectById('unregistered-rock')->getResourceAmounts(), 'saving an in-memory stock cannot overwrite SQL or leak it to JSON');
        $test->assertThrows(fn() => SectorContent::fromDescriptor($mixed->toArray()), SectorStorageException::class, 'canonical decoder rejects old mineable JSON stocks');

        $nestedRock = $rock('nested-rock');
        $transaction->run(fn() => $stocks->create('nested-fixture', $nestedRock->getId(), $metals(6)));
        $system = new \VonNeumannGame\Sector\SolarSystem('nested-system', 'System', new \VonNeumannGame\Sector\Star('nested-star', 'Star', 'G', 1, 5000, 1, 1), null, [
            new \VonNeumannGame\Sector\OrbitingBody($nestedRock, new \VonNeumannGame\Sector\OrbitDescriptor(1, 0, 0)),
            new \VonNeumannGame\Sector\OrbitingBody($planet, new \VonNeumannGame\Sector\OrbitDescriptor(2, 0, 0)),
        ], 1, 2);
        $nested = new SectorContent(new SectorCoordinates(400, 400, 0), [$system]);
        $files->save($nested);
        $nestedLoaded = $sectors->getOrCreateSector($nested->getCoordinates());
        $test->assertEquals(6.0, $nestedLoaded->findObjectById('nested-rock')->getResourceAmounts()['metals'], 'SQL hydration reaches orbital asteroids');
        $test->assertEquals(42.0, (float) $nestedLoaded->findObjectById('kept-planet')->getResourceAmounts()['metals'], 'orbital planet resources survive descriptor serialization');
        $beforeGeneration = $generator->generate(new SectorCoordinates(402, 400, 0), 'same-seed', [$nestedLoaded])->toDescriptor();
        $transaction->run(fn() => $stocks->debit('nested-rock', $metals(1)));
        $sectors->hydrateStocks($nestedLoaded);
        $test->assertEquals($beforeGeneration, $generator->generate(new SectorCoordinates(402, 400, 0), 'same-seed', [$nestedLoaded])->toDescriptor(), 'neighbor generation does not depend on hydrated resource amounts');
        $batch = new SectorContent(new SectorCoordinates(500, 500, 0), array_map($rock, array_map(static fn(int $i): string => 'batch-' . $i, range(0, 400))));
        $db->metrics->reset();
        $sectors->hydrateStocks($batch);
        $test->assertEquals(2, $db->metrics->queries, 'sector hydration batches 401 sources in two SQL queries');

        $failedDirectory = $directory . '/publication-failure';
        mkdir($failedDirectory);
        file_put_contents($failedDirectory . '/sectors', 'blocks creation of the sector directory');
        $failedFiles = new SectorFileRepository($failedDirectory);
        $failedSectors = new SectorService($failedFiles, $generator, 'failure-world', $db);
        $failedCoordinates = new SectorCoordinates(700, 700, 0);
        @$failedSectors->createMineable($failedCoordinates, 'write-failure', $rock('write-failure-rock'));
        $failedEffect = $effects->findByOperation('write-failure');
        $test->assertEquals('pending', $failedEffect['status'], 'failed file publication leaves a durable retry intention');
        $test->assertEquals(10.0, $stocks->find('write-failure-rock')['amounts']['metals'], 'publication failure retains committed stock without repeating its creation');
        $eventRepository = new ScheduledEventRepository($db);
        $failedOutbox = new SectorEffectService($effects, $eventRepository, $failedSectors);
        $event = $eventRepository->findPendingByTypeAndEntity('sector.effect', 'sector_effect', (int) $failedEffect['id']);
        $test->assertEquals(false, @$failedOutbox->applyScheduled($eventRepository->claim($event)), 'scheduler defers failed publication without failing the committed business action');
        $test->assertEquals('pending', $eventRepository->findById($event->id)->status, 'publication event remains queued for an independent retry');
        unlink($failedDirectory . '/sectors');
        $failedOutbox->apply((int) $failedEffect['id']);
        $test->assertEquals(10.0, $failedSectors->getOrCreateSector($failedCoordinates)->findObjectById('write-failure-rock')->getResourceAmounts()['metals'], 'independent publisher recovers both sector creation and object addition');

        require_once __DIR__ . '/OthersTestFixture.php';
        [$others, $othersService, $events] = othersTestFixture($db, $directory);
        $db->exec("INSERT INTO players(username,created_at,updated_at) VALUES('publication-others','now','now')");
        $fleet = $others->createFleet((int) $db->lastInsertId(), 0, 0, 0);
        $ship = $others->findShipsByFleetId((int) $fleet['id'])[0];
        $auxiliary = $others->createAuxiliary((int) $ship['id']);
        $db->beginTransaction();
        $sectors->createMineable($coordinates, 'others-pending-source', $rock('others-pending-source'));
        $db->commit();
        $request = ['objectId' => 'others-pending-source', 'resources' => 'metals', 'targetAmount' => 2];
        $test->assertThrows(fn() => $othersService->startAuxiliaryTask($ship, $auxiliary, 'mine', $request), \VonNeumannGame\Service\OthersActionException::class, 'Others cannot mine an unpublished source through the structural projection');
        $outbox->apply((int) $effects->findByOperation('others-pending-source')['id']);
        $action = $othersService->startAuxiliaryTask($ship, $auxiliary, 'mine', $request);
        $event = $events->findById((int) $action['scheduled_event_id']);
        $db->exec("CREATE TRIGGER reject_auxiliary_delivery BEFORE UPDATE OF cargo_metals ON others_auxiliaries BEGIN SELECT RAISE(ABORT,'injected delivery failure'); END");
        $test->assertThrows(fn() => $othersService->processScheduledAction($event), PDOException::class, 'failed Others delivery aborts its source debit');
        $test->assertEquals(10.0, $stocks->find('others-pending-source')['amounts']['metals'], 'Others delivery rollback restores the shared SQL source');
        $db->exec('DROP TRIGGER reject_auxiliary_delivery');
        $othersService->processScheduledAction($event);
        $test->assertEquals(8.0, $stocks->find('others-pending-source')['amounts']['metals'], 'Others mining debits the shared SQL source');
        $test->assertEquals(2.0, (float) $others->findAuxiliaryByPublicId($auxiliary['public_id'])['cargo_metals'], 'Others receives exactly the debited quantity');
        $othersService->processScheduledAction($event);
        $test->assertEquals(8.0, $stocks->find('others-pending-source')['amounts']['metals'], 'replayed Others action cannot debit twice');

        $definition = (string) $db->query("SELECT sql FROM sqlite_master WHERE name='sector_effects'")->fetchColumn();
        $db->exec('DROP TABLE sector_effects');
        $db->exec(str_replace(",'create_sector'", '', $definition));
        $db->exec('CREATE INDEX effect_migration_fixture ON sector_effects(status)');
        $db->exec("INSERT INTO sector_effects(operation_id,sector_x,sector_y,sector_z,effect_type,object_id,payload_json,created_at,updated_at) VALUES('preserved',0,0,0,'consume_object','old','{}','now','now')");
        $migration = new SectorCreationSchemaMigration($db);
        $test->assertEquals(['apply' => false, 'changeRequired' => true], $migration->run(false), 'schema dry run identifies the old constraint');
        $migration->run(true);
        $test->assertEquals('preserved', $db->query('SELECT operation_id FROM sector_effects')->fetchColumn(), 'schema upgrade preserves existing effects');
        $test->assertEquals(1, (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE name='effect_migration_fixture'")->fetchColumn(), 'schema upgrade preserves indexes');
        $test->assertEquals(['apply' => true, 'changeRequired' => false], $migration->run(true), 'schema upgrade is resumable');
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
})();
