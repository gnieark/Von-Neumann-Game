<?php

declare(strict_types=1);
require_once __DIR__ . '/OthersTestFixture.php';
require_once __DIR__ . '/StorageTestPdo.php';

(static function () use ($test): void {
    $db = new StorageTestPdo(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
    (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
    $directory = sys_get_temp_dir() . '/vng-others-persistence-' . bin2hex(random_bytes(6));
    $files = new \VonNeumannGame\Sector\SectorFileRepository($directory);
    $coordinates = new \VonNeumannGame\Sector\SectorCoordinates(3, 4, 5);
    $files->save(new \VonNeumannGame\Sector\SectorContent($coordinates));
    try {
        $player = (new \VonNeumannGame\Repository\PlayerRepository($db))->createPlayer('durable-others', 'Durable Others', null, $coordinates);
        [$repo, $service, $events, $sectors, $effects] = othersTestFixture($db, $directory);
        $fleet = $repo->createFleet($player->id, 3, 4, 5);
        $ship = $fleet['ship'];
        $now = gmdate('c');
        $insert = $db->prepare("INSERT INTO others_inventory_items(public_id,ship_id,type,container_space,created_at,updated_at) VALUES(?,?,'missile',2,?,?)");
        $insert->execute(['fault-missile', $ship['id'], $now, $now]);
        $db->beginTransaction();
        $service->jettisonInventory($ship, ['kind' => 'item', 'itemId' => 'fault-missile']);
        $test->assertEquals([], $files->load($coordinates)->getObjects(), 'jettison does not publish a file inside its SQL transaction');
        $db->rollBack();
        $test->assertEquals(1, (int) $db->query("SELECT COUNT(*) FROM others_inventory_items WHERE public_id='fault-missile'")->fetchColumn(), 'jettison rollback restores the item');
        $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM sector_effects')->fetchColumn(), 'jettison rollback removes its intention and event');
        $staleSector = $sectors->getOrCreateSector($coordinates);
        $db->beginTransaction();
        $service->jettisonInventory($ship, ['kind' => 'item', 'itemId' => 'fault-missile']);
        $db->commit(); // Simulate process loss before the transaction owner's delivery callback.
        $id = (int) $db->query('SELECT MAX(id) FROM sector_effects')->fetchColumn();
        $test->assertThrows(fn() => $sectors->saveSector($staleSector), 'an older sector writer cannot overwrite a newly committed Others intention');
        $objectId = \VonNeumannGame\Sector\SectorDriftingItem::objectIdForItemType('missile');
        $test->assertEquals([], $files->load($coordinates)->getObjects(), 'committed but undelivered intention leaves the file unchanged');
        $test->assertEquals(1, $sectors->getOrCreateSector($coordinates)->findObjectById($objectId)?->getQuantity(), 'committed intentions are visible in sector observations before delivery');
        [, , , , $restarted] = othersTestFixture($db, $directory);
        $restarted->apply($id);
        $db->prepare("UPDATE sector_effects SET status='pending' WHERE id=?")->execute([$id]); // crash after JSON rename, before acknowledgement
        $restarted->apply($id);
        $test->assertEquals(1, $files->load($coordinates)->findObjectById($objectId)?->getQuantity(), 'recovery after file write does not duplicate the drifting item');
        foreach (['fault-second', 'fault-third'] as $uid) {
            $insert->execute([$uid, $ship['id'], $now, $now]);
            $db->beginTransaction();
            $service->jettisonInventory($ship, ['kind' => 'item', 'itemId' => $uid]);
            $db->commit();
        }
        $last = (int) $db->query('SELECT MAX(id) FROM sector_effects')->fetchColumn();
        $restarted->apply($last);
        $test->assertEquals(3, $files->load($coordinates)->findObjectById($objectId)?->getQuantity(), 'out-of-order delivery applies earlier sector intentions first');
        $insert->execute(['fault-retry-file', $ship['id'], $now, $now]);
        $db->beginTransaction();
        $service->jettisonInventory($ship, ['kind' => 'item', 'itemId' => 'fault-retry-file']);
        $db->commit();
        $retryId = (int) $db->query('SELECT MAX(id) FROM sector_effects')->fetchColumn();
        $path = $files->getPath($coordinates);
        $savedJson = file_get_contents($path);
        file_put_contents($path, '{invalid json');
        $test->assertThrows(fn() => $restarted->apply($retryId), 'a file failure leaves the committed intention retryable');
        $test->assertEquals('pending', $db->query('SELECT status FROM sector_effects WHERE id=' . $retryId)->fetchColumn(), 'failed publication is not acknowledged');
        file_put_contents($path, $savedJson);
        $restarted->apply($retryId);
        $test->assertEquals(4, $files->load($coordinates)->findObjectById($objectId)?->getQuantity(), 'publication succeeds once after file recovery');
        $db->beginTransaction();
        $service->damageShip($ship['public_id'], 100, 'rollback-kill', ['type' => 'missile', 'missileId' => 'fault']);
        $test->assert($files->load($coordinates)->findObjectById(\VonNeumannGame\Sector\DormantConstruct::fromOthersMothership($ship['public_id'])->getId()) === null, 'destruction cannot publish a wreck before commit');
        $db->rollBack();
        $test->assertEquals(100, (int) $repo->findShipByPublicId($ship['public_id'])['integrity'], 'destruction rollback restores the living carrier');

        // Destruction must release a reservation held on a surviving destination.
        $source = $repo->createStandardShip($ship);
        $target = $repo->createStandardShip($ship);
        $aux = $db->query('SELECT * FROM others_auxiliaries WHERE ship_id=' . (int) $source['id'])->fetch();
        $db->prepare("UPDATE others_inventory_resources SET amount=1 WHERE ship_id=? AND resource_type='metals'")->execute([$source['id']]);
        $transfer = $service->createInventoryTransfer($source, ['actorAuxiliaryId' => $aux['public_id'], 'targetShipId' => $target['public_id'], 'kind' => 'resource', 'resourceType' => 'metals', 'amount' => 1]);
        $service->damageShip($source['public_id'], 20, 'transfer-kill', ['type' => 'missile', 'missileId' => 'fault']);
        $test->assertEquals(0.0, (float) $repo->findShipByPublicId($target['public_id'])['inventory_reserved'], 'destruction releases capacity on the surviving transfer destination');
        $service->processScheduledAction($events->findById((int) $transfer['action']['scheduled_event_id']));
        $test->assertEquals('failed', $repo->findActionByPublicId($transfer['action']['public_id'])['status'], 'an interrupted transfer cannot complete after destruction');
        $test->assertEquals(0.0, (float) $db->query("SELECT amount FROM others_inventory_resources WHERE ship_id=" . (int) $target['id'] . " AND resource_type='metals'")->fetchColumn(), 'destroyed carrier cargo is not duplicated at the destination');

        // Collection budgets cover complete public projections, identity batches and writes.
        $measurements = [];
        $count = 0;
        $ids = [];
        foreach ([1, 200, 201, 500] as $size) {
            while ($count < $size) { $uid = 'budget-missile-' . ++$count; $ids[] = $uid; $insert->execute([$uid, $ship['id'], $now, $now]); }
            $db->metrics->reset();
            $rows = $repo->inventoryItemsByPublicIds((int) $ship['id'], $ids);
            $measurement = $db->metrics->snapshot();
            $measurements['identities'][$size] = $measurement;
            $test->assertEquals((int) ceil($size / 200), $measurement['queries'], 'Others inventory identities have a 200-item query batch at ' . $size);
            $test->assertEquals($size, count($rows), 'Others identity batching preserves every requested item');
            $test->assert($measurement['maxParameters'] <= 201, 'Others identity parameters are bounded');
        }
        foreach ([1, 10, 100] as $size) {
            $budgetFleet = $repo->createFleet($player->id, 3, 4, 5);
            for ($i = 1; $i < $size; $i++) { $repo->createStandardShip($budgetFleet['ship']); }
            $db->prepare('UPDATE others_ships SET deuterium_stock=10 WHERE fleet_id=?')->execute([$budgetFleet['id']]);
            $db->metrics->reset();
            $ships = $repo->findShipsByFleetId((int) $budgetFleet['id']);
            $test->assertEquals(1, $db->metrics->queries, 'Others fleet projection includes relations in one query for ' . $size . ' ships');
            $db->metrics->reset();
            $commands = new \VonNeumannGame\Service\OthersCommandService(new \VonNeumannGame\Database\StorageTransaction($db), new \VonNeumannGame\Repository\OthersIdempotencyRepository($db), new \VonNeumannGame\Repository\OthersAuditRepository($db));
            $response = $commands->execute($player, 'POST', '/api/others/fleets/' . $budgetFleet['public_id'] . '/move', 'budget-move-' . $size, hash('sha256', 'budget-move'), fn(): \VonNeumannGame\Http\ApiResponse => new \VonNeumannGame\Http\ApiResponse(202, $service->moveFleet($budgetFleet, ['target' => ['x' => 1, 'y' => 1, 'z' => 0]], $coordinates)));
            $result = $response->body;
            $measurement = $db->metrics->snapshot();
            $measurements['fleetMove'][$size] = $measurement;
            $test->assertEquals($size, count($result['created']), 'collective command accepts each eligible ship');
            $test->assert($measurement['queries'] <= 30 * $size + 10, 'fleet movement query cost is bounded per independent actor');
            $test->assert($measurement['maxParameters'] <= 20, 'fleet movement never builds an unbounded statement');
        }
        echo 'OTHERS SQL BUDGETS ' . json_encode($measurements, JSON_THROW_ON_ERROR) . PHP_EOL;

        $attempts = 0;
        try {
            (new \VonNeumannGame\Database\StorageTransaction($db))->run(static function () use (&$attempts): void {
                $attempts++;
                $error = new PDOException('injected deadlock');
                $error->errorInfo = ['40001', 1213, 'injected deadlock'];
                throw $error;
            });
        } catch (\VonNeumannGame\Database\StorageBusyException) {}
        $test->assertEquals(3, $attempts, 'technical retries stop after three complete attempts');
        $test->assert(!$db->inTransaction(), 'exhausted retries leave no transaction open');

        $migration = new \VonNeumannGame\Database\Migration\OthersPersistenceMigration($db, $sectors);
        $test->assertEquals(0, $migration->run(false)['pendingOperations'], 'Others persistence migration dry run has no side effects on canonical data');
        $test->assertEquals(0, $migration->run(true)['migratedOperations'], 'Others persistence migration accepts fresh canonical schema');
        $test->assertEquals(0, $migration->run(true)['migratedOperations'], 'Others persistence migration can be replayed');

        $legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($legacy);
        $oldDefinition = str_replace(",'patch_objects'", '', (string) $legacy->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='sector_effects'")->fetchColumn());
        $legacy->exec('DROP TABLE sector_effects');
        $legacy->exec($oldDefinition);
        $legacy->exec('DROP TABLE sector_effect_locks');
        $legacyCoordinates = new \VonNeumannGame\Sector\SectorCoordinates(17, 18, 19);
        $files->save(new \VonNeumannGame\Sector\SectorContent($legacyCoordinates));
        $legacy->prepare("INSERT INTO others_cross_store_operations(public_id,operation_type,payload_json,sql_applied,sector_applied,status,created_at,updated_at) VALUES('legacy-pending','dormant_auxiliaries',?,1,0,'pending',?,?)")->execute([json_encode(['shipId' => 'removed-legacy-carrier', 'sector' => ['x' => 17, 'y' => 18, 'z' => 19], 'auxiliaryIds' => ['legacy-auxiliary']], JSON_THROW_ON_ERROR), $now, $now]);
        $legacySectors = new \VonNeumannGame\Sector\SectorService($files, new \VonNeumannGame\Sector\SectorContentGenerator(), 'migration-test');
        $legacyMigration = new \VonNeumannGame\Database\Migration\OthersPersistenceMigration($legacy, $legacySectors);
        $test->assertEquals(1, $legacyMigration->run(false)['pendingOperations'], 'legacy migration audits a pending committed cross-store operation');
        $test->assertEquals([], $files->load($legacyCoordinates)->getObjects(), 'migration dry run does not publish sector objects');
        $test->assertEquals(1, $legacyMigration->run(true)['migratedOperations'], 'legacy migration rebuilds the SQLite constraint and creates a durable intention');
        $test->assertEquals(0, $legacyMigration->run(true)['migratedOperations'], 'legacy migration does not duplicate converted intentions on replay');
        $legacyEffects = new \VonNeumannGame\Service\SectorEffectService(new \VonNeumannGame\Repository\Storage\SectorEffectRepository($legacy), new \VonNeumannGame\Repository\ScheduledEventRepository($legacy), $legacySectors);
        $legacyId = (int) $legacy->query('SELECT MAX(id) FROM sector_effects')->fetchColumn();
        $legacyEffects->apply($legacyId);
        $legacyEffects->apply($legacyId);
        $test->assertEquals(1, count($files->load($legacyCoordinates)->getObjects()), 'migrated dormant objects are delivered exactly once');
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { if ($entry->isDir()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); } }
        rmdir($directory);
    }
})();
