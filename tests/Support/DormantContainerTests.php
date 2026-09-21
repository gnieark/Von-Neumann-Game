<?php

declare(strict_types=1);

use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;

(static function () use ($test, $pdo, $auth, $probes, $kernel, $storage, $storageContainers, $mannies, $sectorService, $saveSectorFixture, $processScheduledMannyNow, $universePath): void {
    $coordinates = new SectorCoordinates(416, 17, -29);
    $wreck = DormantConstruct::fromOthersMothership('hidden-container-fixture', ['metals' => 0.02]);
    $fuelWreck = DormantConstruct::fromOthersMothership('hidden-container-fuel', ['deuterium' => 0.01]);
    $saveSectorFixture(new SectorContent($coordinates, [
        $wreck, $fuelWreck, new DormantConstruct('ordinary-dormant'),
        DormantConstruct::fromOthersAuxiliary('hidden-container-auxiliary'),
        new Asteroid('hidden-container-rock', null, 'iron', ['iron'], 'small', 0.000001, 0.001),
    ]));
    $actors = [];
    foreach (['owner', 'inspector', 'miner'] as $role) {
        $player = $auth->registerPlayerWithPassword('dormant-cache-' . $role, 'secret', 'Cache ' . $role, 'Cache probe');
        $probe = $probes->findByPlayerId($player->id);
        $probe->currentSector = $coordinates;
        $probes->save($probe);
        $headers = ['Authorization' => 'Bearer ' . $auth->createSessionForPlayer($player)['token']];
        $list = $kernel->handle('GET', '/api/probe/mannies', $headers);
        $uid = $list->body['mannies'][0]['id'];
        $actors[$role] = [$probe, $headers, $uid, $player->id];
    }
    [$probe, $headers, $uid] = $actors['owner'];
    $path = '/api/probe/' . $probe->id . '/mannies/' . rawurlencode($uid);
    $finish = static function (string $mannyUid) use ($pdo, $mannies, $processScheduledMannyNow): void {
        $id = $mannies->findByUid($mannyUid)->id;
        $pdo->prepare('UPDATE mannies SET task_started_at=?,task_ends_at=? WHERE id=?')
            ->execute([gmdate('c', time() - 7200), gmdate('c', time() - 1), $id]);
        $processScheduledMannyNow($id);
    };
    $post = static fn(string $endpoint, array $body, array $requestHeaders): \VonNeumannGame\Http\ApiResponse =>
        $kernel->handle('POST', $endpoint, $requestHeaders, json_encode($body, JSON_THROW_ON_ERROR));
    $item = $storage->addItem($probe, ProbeItem::TYPE_ADDITIONAL_CONTAINER, ProbeItem::ADDITIONAL_CONTAINER_NAME, 0.0, ['capacityBonus' => 1.0]);
    $containerUid = 'container-' . $item->uid;
    $detach = ['containerId' => $containerUid, 'mode' => 'hidden_on_dormant_construct', 'objectId' => $wreck->getId()];
    foreach (['missing', 'ordinary-dormant', 'hidden-container-rock', DormantConstruct::fromOthersAuxiliary('hidden-container-auxiliary')->getId()] as $invalidId) {
        $response = $post($path . '/detach-storage-container', array_replace($detach, ['objectId' => $invalidId]), $headers);
        $test->assertEquals(422, $response->status, 'dormant cache rejects invalid support ' . $invalidId);
        $test->assertEquals('invalid_dormant_construct_target', $response->body['error']['code'] ?? null, 'invalid dormant cache target has a specific error');
    }
    $test->assert($storageContainers->findByUidForProbe($probe->id, $containerUid) !== null, 'rejected dormant detach preserves attached storage');
    $response = $post($path . '/detach-storage-container', $detach, $headers);
    $test->assertEquals(202, $response->status, 'dormant cache detach is accepted');
    $test->assertEquals('hidden_on_dormant_construct', $response->body['manny']['task']['artificialObjectDetected']['detection'] ?? null, 'detach detection exposes canonical dormant mode');
    $test->assert(!str_contains(json_encode($response->body['manny']['task'], JSON_THROW_ON_ERROR), '"sector"'), 'dormant detach does not expose absolute sector coordinates');
    $containerId = $response->body['manny']['task']['objectId'];
    $finish($uid);
    $sector = $sectorService->getOrCreateSector($coordinates);
    $container = $sector->findHiddenDetachedContainerById($containerId);
    $test->assertEquals('hidden_on_dormant_construct', $container?->getMode(), 'SQL reload classifies dormant cache as hidden');
    $test->assertEquals($wreck->getId(), $container?->getTargetObjectId(), 'dormant cache preserves its support id');
    $visible = static function (array $requestHeaders) use ($kernel, $containerId): bool {
        $objects = $kernel->handle('GET', '/api/probe/sector', $requestHeaders)->body['sector']['objects'] ?? [];
        return in_array($containerId, array_column($objects, 'id'), true);
    };
    $test->assert($visible($headers), 'owner sees dormant cache');
    foreach (['inspector', 'miner'] as $role) {
        $test->assert(!$visible($actors[$role][1]), 'undiscovered dormant cache is invisible to ' . $role);
        $privateInventory = $kernel->handle('GET', '/api/probe/' . $actors[$role][0]->id . '/sector-objects/' . $containerId . '/inventory', $actors[$role][1]);
        $test->assertEquals(404, $privateInventory->status, 'unknown cache content remains private to ' . $role);
    }
    [$inspector, $inspectHeaders, $inspectUid] = $actors['inspector'];
    $inspect = $post('/api/probe/mannies/' . $inspectUid . '/inspect-sector-object', ['objectId' => $wreck->getId()], $inspectHeaders);
    $test->assertEquals(202, $inspect->status, 'another player can inspect the cache support');
    $test->assertEquals('hidden_on_dormant_construct', $inspect->body['manny']['task']['artificialObjectDetected']['detection'] ?? null, 'inspection discovers dormant cache');
    $finish($inspectUid);
    $test->assert($visible($inspectHeaders), 'inspection persists cache discovery for its player');
    $test->assert(!$visible($actors['miner'][1]), 'inspection does not reveal cache to a third player');

    [$miner, $mineHeaders, $mineUid] = $actors['miner'];
    $mine = ['objectId' => $wreck->getId(), 'resource' => 'metals', 'targetAmount' => 0.005];
    $response = $post('/api/probe/mannies/' . $mineUid . '/mine', $mine, $mineHeaders);
    $test->assertEquals(202, $response->status, 'another player can mine the cache support');
    $test->assertEquals($containerId, $response->body['manny']['task']['artificialObjectDetected']['objectId'] ?? null, 'mining discovers dormant cache');
    $finish($mineUid);
    $test->assert($visible($mineHeaders), 'mining persists cache discovery');

    $mine['targetContainerId'] = $containerId;
    $fuel = $post($path . '/mine', array_replace($mine, ['objectId' => $fuelWreck->getId(), 'resource' => 'deuterium']), $headers);
    $test->assertEquals(422, $fuel->status, 'dormant cache cannot hold deuterium');
    $test->assertEquals('invalid_storage_container', $fuel->body['error']['code'] ?? null, 'deuterium is rejected as container storage');
    $otherTarget = $post($path . '/mine', array_replace($mine, ['objectId' => 'hidden-container-rock']), $headers);
    $test->assertEquals(202, $otherTarget->status, 'cache can receive mining from another object');
    $test->assertEquals(false, $otherTarget->body['manny']['task']['targetContainer']['travelDeducted'] ?? null, 'another support keeps travel time');
    $finish($uid);
    $sector = $sectorService->getOrCreateSector($coordinates);
    $container = $sector->findHiddenDetachedContainerById($containerId);
    $originalPayload = $container->getPayload();
    $sector->replaceDetachedContainer($container->withPayload(array_replace($originalPayload, ['resources' => ['metals' => 1.0]])));
    $sectorService->saveSector($sector);
    $full = $post($path . '/mine', $mine, $headers);
    $test->assertEquals(422, $full->status, 'full dormant cache rejects mining');
    $test->assertEquals('insufficient_cargo_capacity', $full->body['error']['code'] ?? null, 'full dormant cache returns capacity error');
    $sector = $sectorService->getOrCreateSector($coordinates);
    $container = $sector->findHiddenDetachedContainerById($containerId);
    $sector->replaceDetachedContainer($container->withPayload($originalPayload));
    $sectorService->saveSector($sector);

    $mine['targetAmount'] = 0.015;
    $response = $post($path . '/mine', $mine, $headers);
    $test->assertEquals(202, $response->status, 'wreck mining into its hidden cache is accepted');
    $test->assertEquals(0, $response->body['manny']['task']['miningTravelSeconds'] ?? null, 'same-wreck mining deducts travel');
    $test->assertEquals(true, $response->body['manny']['task']['targetContainer']['travelDeducted'] ?? null, 'same-wreck mining exposes travel deduction');
    $finish($uid);
    $sector = $sectorService->getOrCreateSector($coordinates);
    $test->assertEquals(0.0, (float) ($sector->findObjectById($wreck->getId())->getResourceAmounts()['metals'] ?? -1), 'mining exhausts the wreck without removing it');
    $test->assertEquals(0.02, $sector->findHiddenDetachedContainerById($containerId)->getPayload()['resources']['metals'] ?? null, 'cache receives exactly the mined resources');
    $inventory = $kernel->handle('GET', '/api/probe/' . $probe->id . '/sector-objects/' . $containerId . '/inventory', $headers);
    $test->assertEquals(200, $inventory->status, 'dormant cache supports sector storage inventory');
    $recover = $post($path . '/recover-storage-container', ['objectId' => $containerId], $headers);
    $test->assertEquals(202, $recover->status, 'cache remains recoverable on an exhausted wreck');
    $finish($uid);
    $restored = $storageContainers->findByUidForProbe($probe->id, $containerUid);
    $test->assert($restored !== null, 'recovery restores attached storage');
    $test->assertEquals(0.02, $restored ? ($storageContainers->resourceAmounts($restored->id)['metals'] ?? null) : null, 'recovery preserves mined resources');
    $test->assertEquals(202, $post($path . '/detach-storage-container', $detach, $headers)->status, 'new cache can be placed on an exhausted wreck');
    $finish($uid);
    $expectedHidden = (int) $pdo->query("SELECT COUNT(*) FROM detached_storage_containers WHERE mode IN ('hidden_on_asteroid', 'hidden_on_dormant_construct')")->fetchColumn();
    $stats = (new \VonNeumannGame\Service\UniverseStatsService($pdo, $universePath))->collect();
    $test->assertEquals($expectedHidden, $stats['metrics']['hiddenContainers'], 'statistics sum asteroid and dormant caches');
})();
