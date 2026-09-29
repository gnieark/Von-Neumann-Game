<?php

declare(strict_types=1);

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Domain\StorageContainer;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Service\MannyActionException;

(function () use ($test, $auth, $probes, $mannies, $storage, $storageContainers, $movementService, $mannyService, $kernel, $pdo, $sectorService, $detachedStorageContainers, $processScheduledMannyNow, $othersService, $items): void {
    $player = $auth->registerPlayerWithPassword('container-mannies', 'secret', 'Container Mannies', 'Carrier');
    $probe = $probes->findByPlayerId($player->id);
    $session = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'container-mannies', 'password' => 'secret']));
    $headers = ['Authorization' => 'Bearer ' . $session->body['token']];
    $core = $storageContainers->findByUidForProbe($probe->id, StorageContainer::CORE_UID);
    $item = $storage->addItem($probe, ProbeItem::TYPE_ADDITIONAL_CONTAINER, 'Container', 0, ['capacityBonus' => 1]);
    $uid = 'container-' . $item->uid;
    $container = $storageContainers->findByUidForProbe($probe->id, $uid);
    $crew = $mannies->findByProbeId($probe->id);
    foreach ($crew as $manny) { $manny->storageContainerId = $container->id; $mannies->save($manny); }
    $test->assert(!$storage->canLoseContainerAccidentally($probe, $uid), 'container containing every onboard Manny is protected');
    $crew[0]->locationType = Manny::LOCATION_SECTOR;
    $crew[0]->storageContainerId = null;
    $crew[0]->sector = $probe->currentSector;
    $mannies->save($crew[0]);
    $test->assert(!$storage->canLoseContainerAccidentally($probe, $uid), 'a Manny outside the probe does not remove container protection');
    $crew[0]->locationType = Manny::LOCATION_PROBE;
    $crew[0]->storageContainerId = $core->id;
    $crew[0]->sector = null;
    $mannies->save($crew[0]);
    $test->assert($storage->canLoseContainerAccidentally($probe, $uid), 'an onboard Manny in core storage allows accidental loss');
    // Ordinary detachment still forbids onboard occupants.
    try { $storage->detachAdditionalContainerSnapshot($probe, $uid, $player->id); $test->assert(false, 'manual occupied detachment must fail'); }
    catch (MannyActionException $e) { $test->assertEquals('storage_container_not_detachable', $e->errorCode, 'manual detachment rules are unchanged'); }
    $manual = $kernel->handle('POST', '/api/probe/mannies/' . $crew[0]->uid . '/detach-storage-container', $headers, json_encode(['containerId' => $uid, 'mode' => 'drifting']));
    $test->assertEquals(422, $manual->status, 'API refuses voluntary detachment of an occupied container even with another Manny in core');
    $crew[1]->cargoIce = 0.025;
    $crew[1]->currentTask = Manny::TASK_REPAIR;
    $crew[1]->taskStartedAt = gmdate('c');
    $crew[1]->taskEndsAt = gmdate('c', time() + 3600);
    $crew[1]->taskPayload = ['metalsCost' => 0.01];
    $mannies->save($crew[1]);
    $eventId = $crew[1]->taskScheduledEventId;
    $missile = $items->create($probe->id, ProbeItem::TYPE_MISSILE, 'Missile', 0.05, storageContainerId: $container->id);
    $combat = new \VonNeumannGame\Repository\Others\CombatRepository($pdo);
    $combat->createProbeLaunch([
        'public_id' => 'lost-container-missile', 'launcher' => (string) $probe->id, 'player_id' => $player->id,
        'probe_id' => $probe->id, 'manny_id' => $crew[2]->id, 'item_id' => $missile->id,
        'target' => 'lost-target', 'kind' => 'probe', 'x' => 0, 'y' => 0, 'z' => 0,
        'launch_at' => gmdate('c', time()+60), 'created_at' => gmdate('c'), 'updated_at' => gmdate('c'),
    ]);
    $crew[2]->currentTask = Manny::TASK_PREPARING_MISSILE;
    $crew[2]->taskStartedAt = gmdate('c');
    $crew[2]->taskEndsAt = gmdate('c', time()+60);
    $crew[2]->taskPayload = ['missileLaunchId' => 'lost-container-missile'];
    $mannies->save($crew[2]);
    $objectId = SectorDetachedContainer::objectIdForContainer($uid);
    $payload = ['probeId' => $probe->id, 'playerId' => $player->id, 'containerId' => $uid, 'objectId' => $objectId, 'sector' => $probe->currentSector->toArray()];
    $movementService->breakStorageContainerFromScheduledWarning($payload, $mannyService, $othersService);
    $test->assert($storageContainers->findByUidForProbe($probe->id, $uid) === null, 'accidental loss removes occupied container from attached storage');
    $test->assertEquals(count($crew) - 1, count($mannies->findInDetachedContainer($objectId)), 'all lost occupants remain single Manny entities inside the detached container');
    $lost = $mannies->findById($crew[1]->id);
    $test->assertEquals(null, $lost->probeId, 'lost Manny no longer belongs to a probe');
    $test->assertEquals(Manny::LOCATION_DETACHED_CONTAINER, $lost->locationType, 'lost Manny has explicit detached-container location');
    $test->assertEquals(null, $lost->currentTask, 'loss cancels occupant tasks');
    $test->assertEquals(0.025, $lost->cargoIce, 'loss preserves occupant cargo');
    $test->assertEquals('done', $pdo->query('SELECT status FROM scheduled_events WHERE id=' . $eventId)->fetchColumn(), 'loss disables the old task event');
    $test->assertEquals('failed', $pdo->query("SELECT status FROM missile_launches WHERE public_id='lost-container-missile'")->fetchColumn(), 'loss cancels missile preparation and releases the stored missile');
    $test->assertEquals(1, count($mannies->findByProbeId($probe->id)), 'last onboard Manny is retained');
    $movementService->breakStorageContainerFromScheduledWarning($payload, $mannyService, $othersService);
    $test->assertEquals(count($crew) - 1, count($mannies->findInDetachedContainer($objectId)), 'replaying loss does not duplicate occupants');
    $scan = $kernel->handle('GET', '/api/probe/sector', $headers);
    $objects = array_column($scan->body['sector']['objects'], null, 'id');
    $test->assert(!isset($objects[$objectId]['abandonedMannies']), 'uninspected container does not reveal occupants in scans');
    $test->assert(!isset($objects['manny-' . $lost->uid]), 'contained Manny is not also exposed as a drifting Manny');
    $response = $kernel->handle('POST', '/api/probe/mannies/' . $crew[0]->uid . '/recover-storage-container', $headers, json_encode(['objectId' => $objectId, 'mannyId' => $lost->uid]));
    $test->assertEquals(404, $response->status, 'individual recovery requires inspection');
    $inspect = $mannyService->startInspectSectorObject($probe, $crew[0]->uid, $objectId);
    $pdo->prepare('UPDATE mannies SET task_ends_at=? WHERE id=?')->execute([gmdate('c', time()-1), $inspect->id]);
    $processScheduledMannyNow($inspect->id);
    $reporter = $mannies->findById($inspect->id);
    $test->assertEquals(count($crew)-1, count($reporter->taskPayload['containerReport']['mannies'] ?? []), 'inspection report reveals occupant identities');
    $scan = $kernel->handle('GET', '/api/probe/sector', $headers);
    $objects = array_column($scan->body['sector']['objects'], null, 'id');
    $test->assertEquals(count($crew)-1, count($objects[$objectId]['abandonedMannies'] ?? []), 'local scans reveal occupants to the player after inspection');
    $test->assert($detachedStorageContainers->occupiedSpace($objectId) >= 0.05*(count($crew)-1), 'occupants consume detached storage capacity');

    $response = $kernel->handle('POST', '/api/probe/mannies/' . $crew[0]->uid . '/recover-storage-container', $headers, json_encode(['objectId' => $objectId, 'mannyId' => $lost->uid]));
    $test->assertEquals(202, $response->status, 'API accepts individual occupant recovery');
    $storage->updateContainerRules($probe, StorageContainer::CORE_UID, [], [], ['manny']);
    $test->assert(!$sectorService->reserveDetachedContainer($objectId, $crew[2]->id), 'individual recovery excludes competing whole-container reservation');
    $pdo->prepare('UPDATE mannies SET task_ends_at=? WHERE id=?')->execute([gmdate('c', time()-1), $crew[0]->id]);
    $processScheduledMannyNow($crew[0]->id);
    $test->assertEquals(Manny::TASK_WAITING_FOR_SPACE, $mannies->findById($crew[0]->id)->currentTask, 'individual recovery waits when storage cannot accept Mannies');
    $test->assertEquals(null, $mannies->findById($lost->id)->probeId, 'waiting does not recruit the occupant prematurely');
    $mannyService->dropMannyCargo($probe, $crew[0]->uid);
    $test->assertEquals(Manny::LOCATION_DETACHED_CONTAINER, $mannies->findById($lost->id)->locationType, 'cancelling waiting recovery leaves occupant inside container');
    $test->assertEquals(count($crew)-1, count($mannies->findInDetachedContainer($objectId)), 'cancelling recovery preserves all occupants');
    $test->assert($sectorService->reserveDetachedContainer($objectId, $crew[2]->id), 'cancelling waiting recovery releases the container reservation');
    $sectorService->releaseDetachedContainerReservation($objectId, $crew[2]->id);
    $storage->updateContainerRules($probe, StorageContainer::CORE_UID, [], [], []);
    $mannyService->dropMannyCargo($probe, $crew[0]->uid);
    $mannyService->startRecoverDetachedContainer($probe, $crew[0]->uid, $objectId, $lost->uid);
    $pdo->prepare('UPDATE mannies SET task_ends_at=? WHERE id=?')->execute([gmdate('c', time()-1), $crew[0]->id]);
    $processScheduledMannyNow($crew[0]->id);
    $recovered = $mannies->findById($lost->id);
    $test->assertEquals($probe->id, $recovered->probeId, 'individual recovery recruits the same Manny');
    $test->assertEquals(Manny::LOCATION_PROBE, $recovered->locationType, 'individual recovery docks the occupant');
    $test->assertEquals(0.025, $recovered->cargoIce, 'individual recovery preserves occupant cargo');
    $test->assert($detachedStorageContainers->findByObjectId($objectId) !== null, 'individual recovery leaves the container in the sector');
    $test->assertEquals(count($crew)-2, count($mannies->findInDetachedContainer($objectId)), 'individual recovery removes only the chosen occupant');

    // Give the rescuer a colliding name before restoring the remaining occupants.
    $actor = $mannies->findById($crew[0]->id);
    $actor->name = $crew[2]->name;
    $mannies->save($actor);
    $recover = $mannyService->startRecoverDetachedContainer($probe, $actor->uid, $objectId);
    $test->assert(!$sectorService->reserveDetachedContainer($objectId, $recovered->id), 'whole-container reservation excludes individual recovery');
    $pdo->prepare('UPDATE mannies SET task_ends_at=? WHERE id=?')->execute([gmdate('c', time()-1), $recover->id]);
    $processScheduledMannyNow($recover->id);
    $attached = $storageContainers->findByUidForProbe($probe->id, $uid);
    $test->assert($attached !== null, 'whole recovery reattaches container');
    $test->assertEquals(null, $detachedStorageContainers->findByObjectId($objectId), 'whole recovery removes detached storage after adopting occupants');
    $test->assertEquals(count($crew), count($mannies->findByProbeId($probe->id)), 'all recovered Mannies return to the pool without duplication');
    $restored = $mannies->findById($crew[2]->id);
    $test->assertEquals($attached?->id, $restored->storageContainerId, 'whole recovery keeps occupants in their container');
    $test->assert($restored->name !== $actor->name, 'whole recovery resolves Manny name collisions');
    // Protection is checked again when the event executes, not only when scheduled.
    foreach ($mannies->findByProbeId($probe->id) as $manny) { $manny->storageContainerId = $attached->id; $mannies->save($manny); }
    $movementService->breakStorageContainerFromScheduledWarning($payload, $mannyService, $othersService);
    $test->assert($storageContainers->findByUidForProbe($probe->id, $uid) !== null, 'scheduled loss cannot remove the last occupied container');
    // Another attached external container counts as a safe home, too.
    $safeItem = $storage->addItem($probe, ProbeItem::TYPE_ADDITIONAL_CONTAINER, 'Safe container', 0, ['capacityBonus' => 1]);
    $safe = $storageContainers->findByUidForProbe($probe->id, 'container-' . $safeItem->uid);
    $actor = $mannies->findById($crew[0]->id);
    $actor->storageContainerId = $safe->id;
    $mannies->save($actor);
    $test->assert($storage->canLoseContainerAccidentally($probe, $uid), 'a Manny in another external container also permits accidental loss');
    $movementService->breakStorageContainerFromScheduledWarning($payload, $mannyService, $othersService);
    $otherPlayer = $auth->registerPlayerWithPassword('container-rescuer', 'secret', 'Rescuer', 'Rescue carrier');
    $otherProbe = $probes->findByPlayerId($otherPlayer->id);
    $otherProbe->currentSector = $probe->currentSector;
    $probes->save($otherProbe);
    $test->assert(!$mannies->hasInspectedContainer($objectId, $otherPlayer->id), 'inspection knowledge is private to each player');
    $rescuer = $mannies->findByProbeId($otherProbe->id)[0];
    $mannyService->startRecoverDetachedContainer($otherProbe, $rescuer->uid, $objectId);
    $pdo->prepare('UPDATE mannies SET task_ends_at=? WHERE id=?')->execute([gmdate('c', time()-1), $rescuer->id]);
    $processScheduledMannyNow($rescuer->id);
    $test->assertEquals($otherProbe->id, $mannies->findById($crew[1]->id)->probeId, 'whole recovery by a different player recruits the abandoned occupants');
    $test->assertEquals(1, count($mannies->findByProbeId($probe->id)), 'old owner cannot retain recovered occupants in its pool');
})();
