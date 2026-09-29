<?php

declare(strict_types=1);

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Sector\SectorManny;

(static function () use ($test, $auth, $probes, $mannies, $storage, $storageContainers, $kernel, $pdo, $scut, $saveSectorFixture, $sectorService, $detachedStorageContainers, $processScheduledMannyNow): void {
    $player = $auth->registerPlayerWithPassword('mining-delivery-range', 'secret', 'Mining delivery range');
    $headers = ['Authorization' => 'Bearer ' . $auth->createSessionForPlayer($player)['token']];
    $probe = $probes->findByPlayerId($player->id);
    $origin = new SectorCoordinates(98300, 0, 0);
    $away = new SectorCoordinates(98302, 0, 0);
    $probe->currentSector = $origin;
    $probe->deuteriumStock = 50.0;
    $probes->save($probe);
    $probe = setProbeTestStoredResources($storage, $storageContainers, $probes, $probe, []);
    $actor = $mannies->findByProbeId($probe->id)[0];
    $sourceStock = static fn(string $id, string $resource): float => (float) $sectorService->getOrCreateSector($origin)->findObjectById($id)->getResourceAmounts()[$resource];
    $probeStock = static fn(string $resource): float => $storage->resourceStock($probes->findById($probe->id), $resource);
    $moveProbe = static function (SectorCoordinates $sector) use ($probes, $probe): void {
        $fresh = $probes->findById($probe->id);
        $fresh->currentSector = $sector;
        $probes->save($fresh);
    };
    $makeDue = static function () use ($pdo, $actor): void {
        $pdo->prepare('UPDATE mannies SET task_ends_at=? WHERE id=?')->execute([gmdate('c', time()-1), $actor->id]);
    };
    // Presence of another probe does not replace the Manny's own carrier.
    $probes->createForPlayer($player->id, 'Other local probe', $origin);
    foreach (['metals', 'deuterium'] as $resource) {
        $objectId = 'delivery-range-' . $resource;
        $saveSectorFixture(new SectorContent($origin, [new Asteroid($objectId, 'Mining source', 'metallic', [$resource], 'small', 1.0, 1.0, resourceAmounts: [$resource => 1.0])]));
        $initialStock = $probeStock($resource);
        $start = $kernel->handle('POST', '/api/probe/' . $probe->id . '/mannies/' . $actor->uid . '/mine', $headers, json_encode(['objectId' => $objectId, 'resource' => $resource, 'targetAmount' => 0.02]));
        $test->assertEquals(202, $start->status, 'probe-bound mining starts for range test: ' . $resource);
        $eventId = $mannies->findById($actor->id)->taskScheduledEventId;
        $makeDue();
        foreach ([ProbeStatus::Preparing, ProbeStatus::Accelerating, ProbeStatus::Cruising, ProbeStatus::Decelerating] as $status) {
            $moving = $probes->findById($probe->id);
            $moving->status = $status;
            $probes->save($moving);
            $processScheduledMannyNow($actor->id);
            $test->assertEquals($initialStock, $probeStock($resource), 'mining cannot deliver during ' . $status->value . ': ' . $resource);
            $test->assertEquals(1.0, $sourceStock($objectId, $resource), 'mining preserves source during ' . $status->value . ': ' . $resource);
        }
        $moving = $probes->findById($probe->id);
        $moving->status = ProbeStatus::Idle;
        $probes->save($moving);
        $moveProbe($away);
        $test->assert(!$scut->canSectorsCommunicate($origin, $away), 'range fixture starts outside SCUT communication');
        $stats = $processScheduledMannyNow($actor->id);
        $test->assertEquals(1, $stats['deferred'], 'remote probe-bound mining is deferred by scheduler: ' . $resource);
        $test->assertEquals($initialStock, $probeStock($resource), 'absent carrier receives no mined resources: ' . $resource);
        $test->assertEquals(1.0, $sourceStock($objectId, $resource), 'waiting for carrier does not deplete source: ' . $resource);
        $test->assertEquals(Manny::TASK_MINING, $mannies->findById($actor->id)->currentTask, 'waiting order is preserved: ' . $resource);
        $test->assertEquals(Manny::LOCATION_SECTOR, $mannies->findById($actor->id)->locationType, 'waiting miner remains outside: ' . $resource);
        $relay = $scut->createOffRelay($origin, $probe->id);
        $scut->turnOnRelay($relay->id, 'Delivery range SCUT');
        $test->assert($scut->canSectorsCommunicate($origin, $away), 'range fixture enables SCUT communication');
        $processScheduledMannyNow($actor->id);
        $test->assertEquals($initialStock, $probeStock($resource), 'SCUT cannot transport mined resources to absent carrier: ' . $resource);
        $test->assertEquals(1.0, $sourceStock($objectId, $resource), 'repeated remote execution preserves source: ' . $resource);
        $scut->deleteRelay($relay->id);
        $moveProbe($origin);
        $processScheduledMannyNow($actor->id);
        $test->assertEquals(round($initialStock + 0.02, 4), $probeStock($resource), 'returning carrier receives pending mining delivery: ' . $resource);
        $test->assertEquals(0.98, $sourceStock($objectId, $resource), 'source is depleted exactly once after carrier returns: ' . $resource);
        $test->assertEquals(null, $mannies->findById($actor->id)->currentTask, 'pending order finishes after carrier returns: ' . $resource);
        $test->assertEquals(Manny::LOCATION_PROBE, $mannies->findById($actor->id)->locationType, 'miner docks with its returned carrier: ' . $resource);
        $test->assertEquals('done', $pdo->query('SELECT status FROM scheduled_events WHERE id=' . $eventId)->fetchColumn(), 'pending mining event completes after carrier returns: ' . $resource);
        $processScheduledMannyNow($actor->id);
        $test->assertEquals(round($initialStock + 0.02, 4), $probeStock($resource), 'completed mining delivery cannot be duplicated: ' . $resource);
    }
    // A sector container is still a valid destination without the carrier or SCUT.
    $objectId = 'delivery-range-container-rock';
    $containerId = 'delivery-range-detached';
    $saveSectorFixture(new SectorContent($origin, [new Asteroid($objectId, 'Container mining source', 'metallic', ['metals'], 'small', 1.0, 1.0, resourceAmounts: ['metals' => 1.0])]));
    $detachedStorageContainers->save($origin, new SectorDetachedContainer($containerId, 'Mining storage', 'drifting', $probe->id, $player->id, null, null, 1.0, ProbeInventory::CAPACITY_UNIT, gmdate('c'), [
        'sourceContainerId' => 'container-range-test',
        'containerItem' => ['uid' => 'range-test', 'type' => 'additional_container', 'name' => 'Mining storage', 'containerSpace' => 0.0, 'metadata' => ['capacityBonus' => 1.0]],
    ]));
    $initialStock = $probeStock('metals');
    $start = $kernel->handle('POST', '/api/probe/' . $probe->id . '/mannies/' . $actor->uid . '/mine', $headers, json_encode(['objectId' => $objectId, 'resource' => 'metals', 'targetAmount' => 0.02, 'targetContainerId' => $containerId]));
    $test->assertEquals(202, $start->status, 'container-bound mining starts before carrier departure');
    $moveProbe($away);
    $makeDue();
    $completionStats = $processScheduledMannyNow($actor->id);
    $error = $pdo->query('SELECT last_error FROM scheduled_events WHERE id=' . (int) $mannies->findById($actor->id)->taskScheduledEventId)->fetchColumn();
    $test->assertEquals(0, $completionStats['failed'], 'remote container delivery has no scheduler error: ' . ($error ?: 'none'));
    $test->assertEquals(0.02, $detachedStorageContainers->findByObjectId($containerId)->getPayload()['resources']['metals'] ?? null, 'detached container receives mining output without carrier or SCUT');
    $test->assertEquals($initialStock, $probeStock('metals'), 'container-bound mining does not credit remote carrier');
    $test->assertEquals(0.98, $sourceStock($objectId, 'metals'), 'container-bound mining depletes its local source');
    $test->assertEquals(null, $mannies->findById($actor->id)->currentTask, 'container-bound mining completes while carrier is away');
    $test->assertEquals(SectorManny::STATE_FORGOTTEN, $sectorService->getOrCreateSector($origin)->findObjectById(SectorManny::objectIdForUid($actor->uid))?->getState(), 'completed container miner stays in its original sector');
    $moveProbe($origin);
    $start = $kernel->handle('POST', '/api/probe/' . $probe->id . '/mannies/' . $actor->uid . '/mine', $headers, json_encode(['objectId' => $objectId, 'resource' => 'metals', 'targetAmount' => 0.02, 'targetContainerId' => $containerId]));
    $test->assertEquals(202, $start->status, 'container miner can receive another local order');
    $moving = $probes->findById($probe->id);
    $moving->status = ProbeStatus::Cruising;
    $probes->save($moving);
    $makeDue();
    $processScheduledMannyNow($actor->id);
    $test->assertEquals(0.04, $detachedStorageContainers->findByObjectId($containerId)->getPayload()['resources']['metals'] ?? null, 'container delivery completes during carrier transit');
    $test->assertEquals(Manny::LOCATION_SECTOR, $mannies->findById($actor->id)->locationType, 'container miner cannot dock with an in-transit carrier still reporting origin coordinates');
    $test->assertEquals(null, $mannies->findById($actor->id)->currentTask, 'container miner finishes work during carrier transit');
})();
