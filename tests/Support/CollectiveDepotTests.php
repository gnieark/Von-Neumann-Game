<?php

declare(strict_types=1);

// Runs inside the isolated germination-depot fixture, after its individual-transfer checks.
(static function () use ($test, $db, $others, $ship, $depot, $transfers, $transferRows, $now): void {
    $db->prepare("UPDATE others_inventory_resources SET amount=100 WHERE ship_id=? AND resource_type='metals'")->execute([$ship['id']]);
    for ($i = 0; $i < 4; $i++) { $others->createAuxiliary((int) $ship['id']); }
    $payload = ['depotId' => $depot['public_id'], 'resources' => ['metals' => 10], 'itemIds' => [], 'auxiliaryCount' => 2];
    $available = (int) $db->query("SELECT COUNT(*) FROM others_auxiliaries WHERE ship_id=" . (int) $ship['id'] . " AND destroyed_at IS NULL AND location_type='embarked' AND current_action_id IS NULL AND status IN ('inactive','available')")->fetchColumn();
    $beforeActions = (int) $db->query('SELECT COUNT(*) FROM others_actions')->fetchColumn();
    try {
        $transfers->startCollectiveDeposit($ship, array_replace($payload, ['auxiliaryCount' => $available + 1]));
        $test->assert(false, 'collective deposit must reject insufficient auxiliaries');
    } catch (\VonNeumannGame\Service\OthersActionException $error) {
        $test->assertEquals(422, $error->httpStatus, 'collective deposit rejects insufficient auxiliaries');
    }
    $test->assertEquals($beforeActions, (int) $db->query('SELECT COUNT(*) FROM others_actions')->fetchColumn(), 'rejected collective deposit creates no action');
    $test->assertEquals(0.0, (float) $others->inventory((int) $ship['id'])['resources']['metals']['reserved'], 'rejected collective deposit reserves no cargo');

    $action = $transfers->startCollectiveDeposit($ship, $payload);
    $projection = json_decode($action['payload_json'], true);
    $test->assertEquals(1800, $projection['durationSeconds'], 'five trips on two auxiliaries take three whole rounds');
    $test->assertEquals(5, $projection['roundTrips'], 'collective deposit preserves total indivisible trip count');
    $test->assertEquals(null, $others->findShipByPublicId($ship['public_id'])['current_action_id'], 'collective deposit leaves its carrier unoccupied');
    $test->assertEquals(10.0, (float) $others->inventory((int) $ship['id'])['resources']['metals']['reserved'], 'all collective cargo is reserved at acceptance');
    $concurrent = $transfers->startCollectiveDeposit($ship, array_replace($payload, ['resources' => ['metals' => 1], 'auxiliaryCount' => 1]));
    $test->assert(!in_array(json_decode($concurrent['payload_json'], true)['auxiliaryIds'][0], $projection['auxiliaryIds'], true), 'simultaneous deposits select disjoint participants');
    $transfers->completeOthers((int) $concurrent['id'], $concurrent['ends_at']);

    $transfer = $transferRows->findByActionId((int) $action['id']);
    $plan = json_decode($transfer['manifest_json'], true);
    $test->assertEquals([6, 4], array_column(array_column($plan['participants'], 'resources'), 'metals'), 'whole trips define fixed shares');
    $victim = $plan['participants'][0];
    $storageActions = (new \VonNeumannGame\Repository\Others\DestructionRepository($db))->findAuxiliaryStorageActions([$victim['id']]);
    $test->assertEquals((int) $action['id'], (int) $storageActions[0]['id'], 'combat lookup finds the collective action from a participant');
    $early = $now->modify('+601 seconds')->format('c');
    $beforeDepot = (float) $db->query("SELECT amount FROM germination_depot_resources WHERE depot_id=" . (int) $depot['id'] . " AND resource_type='metals'")->fetchColumn();
    $transfers->completeOthers((int) $action['id'], $early, 'auxiliary_destroyed', $victim['id']);
    $transfers->completeOthers((int) $action['id'], $early, 'auxiliary_destroyed', $victim['id']);
    $db->prepare("UPDATE others_auxiliaries SET destroyed_at=?,current_action_id=NULL,status='destroyed' WHERE id=?")->execute([$early, $victim['id']]);
    $test->assertEquals('queued', $others->findActionByPublicId($action['public_id'])['status'], 'one destroyed participant does not stop survivors');
    $test->assertEquals($beforeDepot, (float) $db->query("SELECT amount FROM germination_depot_resources WHERE depot_id=" . (int) $depot['id'] . " AND resource_type='metals'")->fetchColumn(), 'destruction does not credit intermediate deliveries');
    $test->assertEquals(10.0, (float) $others->inventory((int) $ship['id'])['resources']['metals']['reserved'], 'pending collective cargo stays reserved until final settlement');
    $transfers->completeOthers((int) $action['id'], $action['ends_at']);
    $transfers->completeOthers((int) $action['id'], $action['ends_at']);
    $finished = $others->findActionByPublicId($action['public_id']);
    $result = json_decode($finished['result_json'], true);
    $test->assertEquals('succeeded', $finished['status'], 'surviving collective delivery succeeds');
    $test->assertEquals(4.0, (float) $result['delivered']['resources']['metals'], 'final delivery excludes the destroyed auxiliary entire share');
    $test->assertEquals(6.0, (float) $result['lost']['resources']['metals'], 'destruction loses the full assigned share, including earlier virtual trips');
    $test->assertEquals(89.0, (float) $others->inventory((int) $ship['id'])['resources']['metals']['amount'], 'delivered and lost cargo is debited exactly once');
    $test->assertEquals(0.0, (float) $others->inventory((int) $ship['id'])['resources']['metals']['reserved'], 'terminal collective deposit releases reservations');

    // Fault after debit must roll back participant release and accounting together.
    $fault = $transfers->startCollectiveDeposit($ship, $payload);
    $db->exec("CREATE TRIGGER collective_fail_credit BEFORE INSERT ON germination_depot_resources BEGIN SELECT RAISE(ABORT,'collective credit fault'); END");
    try { $transfers->completeOthers((int) $fault['id'], $fault['ends_at']); $test->assert(false, 'collective injected credit must fail'); }
    catch (PDOException) {}
    $test->assertEquals('queued', $others->findActionByPublicId($fault['public_id'])['status'], 'collective accounting failure stays retryable');
    $test->assertEquals(2, (int) $db->query('SELECT COUNT(*) FROM others_auxiliaries WHERE current_action_id=' . (int) $fault['id'])->fetchColumn(), 'rollback preserves both participant reservations');
    $db->exec('DROP TRIGGER collective_fail_credit');
    $transfers->completeOthers((int) $fault['id'], $fault['ends_at']);

    // Fewer trips than auxiliaries still reserve exactly the requested count.
    $small = $transfers->startCollectiveDeposit($ship, array_replace($payload, ['resources' => ['metals' => 0.0001], 'auxiliaryCount' => 3]));
    $test->assertEquals(600, json_decode($small['payload_json'], true)['durationSeconds'], 'collective deposit never takes less than one round trip');
    $test->assertEquals(3, (int) $db->query('SELECT COUNT(*) FROM others_auxiliaries WHERE current_action_id=' . (int) $small['id'])->fetchColumn(), 'even a small deposit reserves the exact requested auxiliary count');
    $transfers->completeOthers((int) $small['id'], $small['ends_at'], 'auxiliary_destroyed', 0);
    $test->assertEquals('succeeded', $others->findActionByPublicId($small['public_id'])['status'], 'completion at the destruction deadline wins');

    // Items remain indivisible, retain identity, and are removed with their participant's share.
    $insert = $db->prepare('INSERT INTO others_inventory_items(ship_id,public_id,type,name,container_space,metadata_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
    foreach (['collective-a', 'collective-b'] as $id) { $insert->execute([$ship['id'], $id, 'steel_bar', $id, 1.5, '{}', $now->format('c'), $now->format('c')]); }
    $items = $transfers->startCollectiveDeposit($ship, array_replace($payload, ['resources' => ['metals' => 1], 'itemIds' => ['collective-a', 'collective-b']]));
    $parts = json_decode($transferRows->findByActionId((int) $items['id'])['manifest_json'], true)['participants'];
    $transfers->completeOthers((int) $items['id'], $now->modify('+1 second')->format('c'), 'auxiliary_destroyed', $parts[0]['id']);
    $db->prepare("UPDATE others_auxiliaries SET destroyed_at=?,current_action_id=NULL,status='destroyed' WHERE id=?")->execute([$early, $parts[0]['id']]);
    $transfers->completeOthers((int) $items['id'], $items['ends_at']);
    $test->assertEquals('succeeded', $others->findActionByPublicId($items['public_id'])['status'], 'surviving item delivery completes');
    $test->assertEquals(1, (int) $db->query("SELECT COUNT(*) FROM germination_depot_items WHERE public_id IN ('collective-a','collective-b')")->fetchColumn(), 'only the surviving participant item reaches the depot');
    $result = json_decode($others->findActionByPublicId($items['public_id'])['result_json'], true);
    $test->assertEquals(['collective-a'], $result['lost']['itemIds'], 'destroyed item identity remains in the action result');
    $test->assertEquals(['collective-b'], $result['delivered']['itemIds'], 'surviving item identity is preserved');

    $allLost = $transfers->startCollectiveDeposit($ship, $payload);
    $parts = json_decode($transferRows->findByActionId((int) $allLost['id'])['manifest_json'], true)['participants'];
    foreach ($parts as $part) {
        $transfers->completeOthers((int) $allLost['id'], $early, 'auxiliary_destroyed', $part['id']);
        $db->prepare("UPDATE others_auxiliaries SET destroyed_at=?,current_action_id=NULL,status='destroyed' WHERE id=?")->execute([$early, $part['id']]);
    }
    $lostAction = $others->findActionByPublicId($allLost['public_id']);
    $test->assertEquals('failed', $lostAction['status'], 'destruction of every participant settles immediately');
    $test->assertEquals(10.0, (float) json_decode($lostAction['result_json'], true)['lost']['resources']['metals'], 'all-destroyed collective loses exactly its original content');

    foreach (['carrier_departure', 'carrier_destroyed'] as $reason) {
        $others->createAuxiliary((int) $ship['id']); $others->createAuxiliary((int) $ship['id']);
        $interrupted = $transfers->startCollectiveDeposit($ship, $payload);
        $transfers->completeOthers((int) $interrupted['id'], $early, $reason);
        $transfers->completeOthers((int) $interrupted['id'], $interrupted['ends_at']);
        $settled = $others->findActionByPublicId($interrupted['public_id']);
        $result = json_decode($settled['result_json'], true);
        $test->assertEquals('canceled', $settled['status'], $reason . ' settles the collective action once');
        $test->assertEquals(2, count(array_unique($result['dormantAuxiliaryIds'])), $reason . ' produces one distinct dormant object per survivor');
        $test->assertEquals(2, (int) $db->query("SELECT COUNT(*) FROM sector_effects WHERE operation_id LIKE 'storage-dormant-" . (int) $interrupted['id'] . "-%'")->fetchColumn(), $reason . ' persists every dormant auxiliary effect');
    }
}) ();
