<?php

declare(strict_types=1);

(static function () use ($test, $pdo, $others, $ship, $depot, $kernel, $headers, $storageTransfers, $othersService, $scheduledEvents): void {
    $path = '/api/others/ships/' . $ship['public_id'] . '/depot-deposits';
    $payload = ['depotId' => $depot['public_id'], 'resources' => ['metals' => 10], 'itemIds' => [], 'auxiliaryCount' => 2];
    $test->assertEquals(401, $kernel->handle('POST', $path, [], json_encode($payload))->status, 'collective deposit requires authentication');
    $test->assertEquals(404, $kernel->handle('POST', '/api/others/ships/missing/depot-deposits', $headers, json_encode($payload))->status, 'collective deposit checks carrier ownership');
    foreach ([null, 0, -1, 1.5, '2', true] as $count) {
        $invalid = array_replace($payload, ['auxiliaryCount' => $count]);
        if ($count === null) { unset($invalid['auxiliaryCount']); }
        $test->assertEquals(400, $kernel->handle('POST', $path, $headers, json_encode($invalid))->status, 'collective deposit requires a positive integer count');
    }
    foreach ([['resources' => []], ['capacityEce' => 100], ['durationSeconds' => 1], ['itemIds' => ['same', 'same']]] as $invalid) {
        $test->assertEquals(400, $kernel->handle('POST', $path, $headers, json_encode(array_replace($payload, $invalid)))->status, 'collective deposit rejects invalid or client-controlled transfer parameters');
    }
    $rejected = $kernel->handle('POST', $path, $headers, json_encode(array_replace($payload, ['auxiliaryCount' => 1000000])));
    $test->assertEquals(422, $rejected->status, 'collective deposit refuses unavailable participant count');
    $test->assertEquals('insufficient_resources', $rejected->body['error']['code'], 'collective count error follows the harvest count error code');
    $others->createAuxiliary((int) $ship['id']);
    $others->createAuxiliary((int) $ship['id']);
    $requestHeaders = $headers + ['Idempotency-Key' => 'collective-depot-http'];
    $body = json_encode($payload);
    $created = $kernel->handle('POST', $path, $requestHeaders, $body);
    $test->assertEquals(202, $created->status, 'HTTP accepts one collective deposit command');
    if ($created->status !== 202) { throw new RuntimeException(json_encode($created->body)); }
    $test->assertEquals(['kind' => 'others_ship', 'id' => $ship['public_id']], $created->body['action']['actor'], 'collective action identifies its carrier');
    $test->assertEquals(2, $created->body['action']['transfer']['auxiliaryCount'], 'HTTP exposes collective count');
    $test->assertEquals(2, count($created->body['action']['transfer']['auxiliaryIds']), 'HTTP exposes public participant ids');
    $test->assertEquals(1800, $created->body['action']['transfer']['durationSeconds'], 'HTTP exposes whole-round collective duration');
    $test->assertEquals($created->body, $kernel->handle('POST', $path, $requestHeaders, $body)->body, 'collective idempotency replay preserves the accepted response');
    $test->assertEquals(409, $kernel->handle('POST', $path, $requestHeaders, json_encode(array_replace($payload, ['auxiliaryCount' => 1])))->status, 'idempotency rejects a changed collective count');
    $action = $others->findActionByPublicId($created->body['action']['id']);
    $test->assertEquals(1, (int) $pdo->query('SELECT COUNT(*) FROM scheduled_events WHERE entity_type=\'others_action\' AND entity_id=' . (int) $action['id'])->fetchColumn(), 'one collective command creates only one scheduled event');
    $lookup = '/api/others/actions/' . $action['public_id'];
    $test->assertEquals($created->body, $kernel->handle('GET', $lookup, $headers)->body, 'shared action endpoint returns the collective projection');
    $storageTransfers->completeOthers((int) $action['id'], $action['ends_at']);
    $result = $kernel->handle('GET', $lookup, $headers)->body['action'];
    $test->assertEquals('succeeded', $result['status'], 'collective HTTP action remains readable after completion');
    $test->assertEquals([], $result['result']['destroyedAuxiliaryIds'], 'collective result exposes its destroyed participants');
    $test->assertEquals('{}', json_encode($result['result']['lost']['resources']), 'collective empty resource maps remain JSON objects');
    $test->assertEquals($created->body, $kernel->handle('POST', $path, $requestHeaders, $body)->body, 'replay after completion does not schedule another delivery');
    foreach (['sector_x', 'sector_y', 'sector_z', 'manifest_json', 'player_id'] as $internal) {
        $test->assert(!str_contains(json_encode($result), $internal), 'collective API hides internal field ' . $internal);
    }

    // Exercise the real missile destruction hook, including detachment and scheduler replay.
    $ship = $others->findShipByPublicId($ship['public_id']);
    $attacked = $storageTransfers->startCollectiveDeposit($ship, array_replace($payload, ['resources' => ['metals' => 4]]));
    $victim = json_decode($attacked['payload_json'], true)['auxiliaryIds'][0];
    $impactAt = (new DateTimeImmutable($attacked['created_at']))->modify('+1 second')->format('c');
    for ($i = 0; ; $i++) {
        $missile = 'collective-depot-missile-' . $i;
        $roll = hexdec(substr(hash('sha256', $missile . '|' . $victim . '|' . $impactAt . '|hit'), 0, 8)) / 4294967296;
        if ($roll < 0.95) { break; }
    }
    $pdo->prepare("INSERT INTO missile_launches(public_id,launcher_kind,launcher_public_id,player_id,target_public_id,target_kind,sector_x,sector_y,sector_z,status,projectile_public_id,launch_at,impact_at,created_at,updated_at) VALUES(?,'probe','missing-probe',?,?,'others_auxiliary',315,7,-8,'launched',?,?,?,?,?)")
        ->execute([$missile, $ship['player_id'], $victim, $missile, $impactAt, $impactAt, $impactAt, $impactAt]);
    $launchId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO others_projectiles(public_id,launch_id,launcher_kind,launcher_public_id,target_public_id,target_kind,sector_x,sector_y,sector_z,status,launched_at,impact_at,created_at,updated_at) VALUES(?,?,'probe','missing-probe',?,'others_auxiliary',315,7,-8,'moving',?,?,?,?)")
        ->execute([$missile, $launchId, $victim, $impactAt, $impactAt, $impactAt, $impactAt]);
    $event = $scheduledEvents->schedule(\VonNeumannGame\Service\SchedulerService::MISSILE_PROJECTILE, 'missile_projectile', (int) $pdo->lastInsertId(), $impactAt);
    $othersService->processScheduledProjectile($event);
    $othersService->processScheduledProjectile($event);
    $test->assertEquals('destroyed', $others->findAuxiliaryByPublicId($victim)['status'], 'real missile destroys its collective participant');
    $test->assertEquals('queued', $others->findActionByPublicId($attacked['public_id'])['status'], 'destruction hook preserves the collective action for survivors');
    $othersService->processScheduledAction($scheduledEvents->findById((int) $attacked['scheduled_event_id']));
    $finished = $others->findActionByPublicId($attacked['public_id']);
    $test->assertEquals('succeeded', $finished['status'], 'scheduler completes surviving collective participants');
    $accounting = json_decode($finished['result_json'], true);
    $test->assertEquals(['metals' => 2], $accounting['delivered']['resources'], 'missile destruction removes precisely one participant share');
    $test->assertEquals(['metals' => 2], $accounting['lost']['resources'], 'missile replay cannot multiply collective losses');
}) ();
