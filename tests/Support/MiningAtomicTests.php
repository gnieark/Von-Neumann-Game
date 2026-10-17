<?php

declare(strict_types=1);

use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\MineableResourceAmounts;
use VonNeumannGame\Repository\ScheduledEventLeaseLostException;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorDetachedContainer;

require_once __DIR__ . '/MiningAtomicFixture.php';
(static function () use ($test): void {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON');
    (new SchemaInitializer('sqlite'))->initialize($db);
    $directory = sys_get_temp_dir() . '/vng-mining-atomic-' . bin2hex(random_bytes(6));
    $f = miningAtomicFixture($db, $directory);
    extract($f);
    $tx = new StorageTransaction($db);
    $sector = new SectorCoordinates(850, 900, 0);
    $amounts = array_fill_keys(array_keys(MineableResourceAmounts::zero()), 1.0);
    $rock = new Asteroid('atomic-rock', 'Rock', 'iron', array_keys($amounts), 'small', 1, 1, resourceAmounts: $amounts);
    $files->save(new SectorContent($sector, [$rock]));
    $tx->run(fn() => $stocks->create('atomic-rock-create', $rock->getId(), $amounts));
    $hash = hash_file('sha256', $files->getPath($sector));
    [$probe, $manny] = miningAtomicActor($f, 'atomic-miner', $sector);
    $destination = 'atomic-container';
    $detached->save($sector, new SectorDetachedContainer($destination, 'Container', 'drifting', $probe->id, $probe->playerId, null, null, 1, 'ECE', gmdate('c'), ['sourceContainerId' => 'container-atomic-item', 'containerItem' => ['uid' => 'atomic-item', 'type' => 'additional_container', 'name' => 'Container', 'containerSpace' => 0.0, 'metadata' => ['capacityBonus' => 1.0]], 'resources' => ['metals' => 0.8]]));
    $stock = static fn(): array => $stocks->find($rock->getId())['amounts'];
    $destinationStock = static fn(): float => $detached->findByObjectId($destination)->getPayload()['resources']['metals'] ?? 0;
    try {
        $manny = $mining->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.15, $destination);
        $event = miningAtomicDue($f, $manny);
        // A failure after resource writes must roll back destination, source, task and event.
        $db->exec("CREATE TRIGGER fail_mining_save BEFORE UPDATE ON mannies WHEN OLD.current_task='mining' AND NEW.current_task IS NULL BEGIN SELECT RAISE(ABORT,'injected mining save failure'); END");
        try { $mining->processScheduledMiningTask($event); $test->assert(false, 'injected post-credit failure is thrown'); }
        catch (PDOException $e) { $test->assert(str_contains($e->getMessage(), 'injected mining'), 'fault occurs after mining credit'); }
        $test->assertEquals($amounts, $stock(), 'failed delivery restores all four source amounts');
        $test->assertEquals(0.8, $destinationStock(), 'failed delivery restores destination');
        $test->assertEquals('running', $events->findById($event->id)->status, 'rollback preserves the claimed event');
        $test->assertEquals(Manny::TASK_MINING, $mannies->findById($manny->id)->currentTask, 'rollback preserves the mining task');
        $db->exec('DROP TRIGGER fail_mining_save');
        $test->assert($mining->processScheduledMiningTask($event), 'retry commits the terminal delivery');
        $test->assertEquals(0.85, $stock()['metals'], 'source is debited once');
        $test->assertEquals(0.95, $destinationStock(), 'destination is credited once');
        $test->assertEquals('done', $events->findById($event->id)->status, 'event is already done before scheduler acknowledgement');
        $mining->refreshScheduledMannyTask($event);
        $events->markDone($event);
        $test->assertEquals(0.95, $destinationStock(), 'crash after commit/replay cannot credit twice');
        $test->assertEquals($hash, hash_file('sha256', $files->getPath($sector)), 'launch and harvest never rewrite the sector');
        $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM sector_effect_locks')->fetchColumn(), 'ordinary harvest acquires no sector lock');

        // Capacity changes after launch: actual destination capacity wins.
        $manny = $mining->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.04, $destination);
        $db->prepare('UPDATE detached_storage_container_resources SET amount=0.99 WHERE container_object_id=?')->execute([$destination]);
        $event = miningAtomicDue($f, $manny);
        $mining->processScheduledMiningTask($event);
        $test->assertEquals(1.0, $destinationStock(), 'delivery respects capacity consumed after launch');
        $test->assertEquals(0.84, $stock()['metals'], 'only delivered capacity is extracted');

        // Incoming transfer reservations share the same capacity root.
        $db->prepare('UPDATE detached_storage_container_resources SET amount=0.9 WHERE container_object_id=?')->execute([$destination]);
        $manny = $mining->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.04, $destination);
        $transfers = new \VonNeumannGame\Repository\Storage\SectorStorageTransferRepository($db);
        $transferId = $transfers->createManny('atomic-reservation', $probe->playerId, $manny->uid, $probe->id, $manny->id, 'detached', $destination, $destination, null, null, 'to_storage', [], [], [], gmdate('c'), gmdate('c'));
        $db->prepare("INSERT INTO sector_storage_capacity_reservations VALUES(?,'detached',?,0.09)")->execute([$transferId, $destination]);
        $event = miningAtomicDue($f, $manny);
        $before = $stock()['metals'];
        $mining->processScheduledMiningTask($event);
        $test->assertEquals(0.91, $destinationStock(), 'mining preserves space reserved for an incoming transfer');
        $test->assertEquals(round($before - 0.01, 4), $stock()['metals'], 'reserved capacity is not extracted');
        $db->prepare('DELETE FROM sector_storage_capacity_reservations WHERE transfer_id=?')->execute([$transferId]);
        $db->prepare("UPDATE sector_storage_transfers SET status='succeeded' WHERE id=?")->execute([$transferId]);

        // Vanished destination is a permanent business error, with no debit.
        $db->prepare('UPDATE detached_storage_container_resources SET amount=0 WHERE container_object_id=?')->execute([$destination]);
        $manny = $mining->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.1, $destination);
        $event = miningAtomicDue($f, $manny);
        $detached->delete($destination);
        try { $mining->processScheduledMiningTask($event); $test->assert(false, 'missing container rejects delivery'); }
        catch (\VonNeumannGame\Service\MannyActionException $e) { $test->assertEquals('detached_container_not_found', $e->errorCode, 'missing container retains its business error'); }
        $test->assertEquals(0.83, $stock()['metals'], 'missing destination never consumes source');
        $mining->recallManny($probe, $manny->uid);

        [$probe2, $manny2] = miningAtomicActor($f, 'atomic-probe-miner', $sector);
        $manny2 = $mining->startMining($probe2, $manny2->uid, $rock->getId(), array_keys($amounts), 0.07);
        $event = miningAtomicDue($f, $manny2);
        // Even another claim by the same worker invalidates the original claim token.
        $db->prepare("UPDATE scheduled_events SET status='pending',locked_by=NULL WHERE id=?")->execute([$event->id]);
        $newClaim = $events->claim($events->findById($event->id));
        $before = $stock();
        try { $mining->processScheduledMiningTask($event); $test->assert(false, 'stale attempt is fenced'); }
        catch (ScheduledEventLeaseLostException) { $test->assert(true, 'same-worker stale claim cannot mine'); }
        $test->assertEquals($before, $stock(), 'lost lease has no debit');
        $mining->processScheduledMiningTask($newClaim);
        foreach ($before as $type => $balance) {
            $test->assertEquals(round($balance - $stock()[$type], 4), $storage->resourceStock($probes->findById($probe2->id), $type), 'exact rounded debit equals probe credit: ' . $type);
        }
        $test->assertEquals(Manny::LOCATION_PROBE, $mannies->findById($manny2->id)->locationType, 'probe delivery docks the Manny in the same commit');

        // A deleted descriptor never yields resources even if the stock row remains.
        $manny2 = $mining->startMining($probe2, $manny2->uid, $rock->getId(), 'metals', 0.02);
        $event = miningAtomicDue($f, $manny2);
        $before = $stock();
        $withoutRock = $files->load($sector); $withoutRock->removeObjectById($rock->getId()); $files->save($withoutRock);
        $mining->processScheduledMiningTask($event);
        $test->assertEquals($before, $stock(), 'absent object never consumes a surviving stock row');
        $withRock = $files->load($sector); $withRock->addObject($rock); $files->save($withRock);
        $hash = hash_file('sha256', $files->getPath($sector));

        // Fuel capacity already committed to a transfer cannot be stolen by mining.
        $manny2 = $mining->startMining($probe2, $manny2->uid, $rock->getId(), 'deuterium', 0.1);
        $transferId = $transfers->createManny('atomic-tank-reservation', $probe2->playerId, $manny2->uid, $probe2->id, $manny2->id, 'detached', $destination, $destination, null, null, 'from_storage', [], [], [], gmdate('c'), gmdate('c'));
        $freshProbe = $probes->findById($probe2->id);
        $freeTank = ($storage->maxDeuteriumPercent($freshProbe) - $freshProbe->deuteriumStock) / \VonNeumannGame\Domain\ResourceComposition::DEUTERIUM_TANK_POINTS_PER_ECE;
        (new \VonNeumannGame\Repository\Storage\StorageReservationRepository($db))->reserveProbeTank($transferId, $probe2->id, $freeTank - 0.0001);
        $event = miningAtomicDue($f, $manny2); $before = $stock();
        $mining->processScheduledMiningTask($event);
        $test->assertEquals(0.0001, round($before['deuterium'] - $stock()['deuterium'], 4), 'mining consumes only the last unreserved fuel unit');
        $db->prepare('DELETE FROM sector_storage_capacity_reservations WHERE transfer_id=?')->execute([$transferId]);
        $db->prepare("UPDATE sector_storage_transfers SET status='succeeded' WHERE id=?")->execute([$transferId]);

        // Half-unit rounding of several components must not inflate a tiny harvest.
        $tinyAmounts = array_fill_keys(array_keys(MineableResourceAmounts::zero()), 0.0001);
        $tiny = new Asteroid('atomic-tiny', 'Tiny', 'iron', array_keys($tinyAmounts), 'small', 1, 1, resourceAmounts: $tinyAmounts);
        $withTiny = $files->load($sector); $withTiny->addObject($tiny); $files->save($withTiny);
        $tx->run(fn() => $stocks->create('atomic-tiny-create', $tiny->getId(), $tinyAmounts));
        $manny2 = $mining->startMining($probe2, $manny2->uid, $tiny->getId(), array_keys($tinyAmounts), 0.0002);
        $event = miningAtomicDue($f, $manny2);
        $before = [];
        foreach ($tinyAmounts as $type => $_) { $before[$type] = $storage->resourceStock($probes->findById($probe2->id), $type); }
        $mining->processScheduledMiningTask($event);
        $delivered = 0.0;
        foreach ($tinyAmounts as $type => $balance) {
            $credit = round($storage->resourceStock($probes->findById($probe2->id), $type) - $before[$type], 4);
            $test->assertEquals(round($balance - $stocks->find($tiny->getId())['amounts'][$type], 4), $credit, 'tiny rounded component conserves resources: ' . $type);
            $delivered += $credit;
        }
        $test->assertEquals(0.0002, round($delivered, 4), 'tiny harvest never rounds above its total');
        $hash = hash_file('sha256', $files->getPath($sector));

        // Present descriptor without SQL stock is exhausted and never reconstructed.
        $manny2 = $mining->startMining($probe2, $manny2->uid, $rock->getId(), 'metals', 0.02);
        $event = miningAtomicDue($f, $manny2);
        $beforeDestination = $storage->resourceStock($probe2, 'metals');
        $tx->run(fn() => $stocks->delete($rock->getId()));
        $mining->processScheduledMiningTask($event);
        $test->assertEquals($beforeDestination, $storage->resourceStock($probe2, 'metals'), 'missing stock cannot mint probe resources');
        $test->assertEquals(null, $stocks->find($rock->getId())['version'], 'missing stock stays absent');
        $test->assertEquals($hash, hash_file('sha256', $files->getPath($sector)), 'all ordinary deliveries leave the descriptor untouched');
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
})();
