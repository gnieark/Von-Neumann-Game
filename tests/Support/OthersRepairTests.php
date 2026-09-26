<?php

declare(strict_types=1);

(static function () use ($test, $pdo, $players, $auth, $others, $kernel, $othersService, $scheduledEvents, $reinstantiation, $openApiOthersDocument): void {
    $coordinates = new \VonNeumannGame\Sector\SectorCoordinates(317, 7, -8);
    $player = $players->createPlayer('repair-http-owner', 'Repair HTTP owner', null, $coordinates);
    $player->canControlOthers = true;
    $players->save($player);
    $headers = ['Authorization' => 'Bearer ' . $auth->createSessionForPlayer($player)['token']];
    $fleet = $others->createFleet($player->id, 317, 7, -8);
    $ship = $others->findShipsByFleetId((int) $fleet['id'])[0];
    $aux = $others->createAuxiliary((int) $ship['id']);
    $otherAux = $others->createAuxiliary((int) $ship['id']);
    $path = '/api/others/ships/' . $ship['public_id'] . '/auxiliaries/' . $aux['public_id'] . '/repair';
    $stock = static fn(): float => $others->inventory((int) $ship['id'])['resources']['metals']['amount'];
    $integrity = static fn(): int => (int) $others->findShipByPublicId($ship['public_id'])['integrity'];
    $complete = static function (array $response) use ($others, $scheduledEvents, $othersService): void {
        $action = $others->findActionByPublicId($response['action']['id']);
        $event = $scheduledEvents->findById((int) $action['scheduled_event_id']);
        $othersService->processScheduledAction($event);
    };

    $test->assertEquals(401, $kernel->handle('POST', $path, [], '{"integrityPercent":2}')->status, 'Others repair requires authentication');
    $foreign = $players->createPlayer('repair-http-foreign', 'Repair foreign', null, $coordinates);
    $foreignHeaders = ['Authorization' => 'Bearer ' . $auth->createSessionForPlayer($foreign)['token']];
    $test->assertEquals(403, $kernel->handle('POST', $path, $foreignHeaders, '{"integrityPercent":2}')->status, 'Others repair requires operator permission');
    $foreign->canControlOthers = true;
    $players->save($foreign);
    $test->assertEquals(404, $kernel->handle('POST', $path, $foreignHeaders, '{"integrityPercent":2}')->status, 'Others repair hides foreign ships');
    $test->assertEquals(404, $kernel->handle('POST', str_replace($aux['public_id'], 'missing-aux', $path), $headers, '{"integrityPercent":2}')->status, 'Others repair requires an auxiliary belonging to the ship');
    $pdo->prepare("UPDATE others_inventory_resources SET amount=1 WHERE ship_id=? AND resource_type='metals'")->execute([$ship['id']]);
    foreach (['{}', '[]', 'null', '{', '{"percent":2}', '{"integrityPercent":0}', '{"integrityPercent":-1}', '{"integrityPercent":0.5}', '{"integrityPercent":true}', '{"integrityPercent":"NaN"}', '{"integrityPercent":1e999}', '{"integrityPercent":2,"metalsCost":0}'] as $body) {
        $test->assertEquals(400, $kernel->handle('POST', $path, $headers, $body)->status, 'Others repair rejects invalid payload ' . $body);
    }
    $full = $kernel->handle('POST', $path, $headers, '{"integrityPercent":2}');
    $test->assertEquals('others_ship_integrity_full', $full->body['error']['code'] ?? null, 'Others repair rejects full integrity');
    $pdo->prepare('UPDATE others_ships SET integrity=max_integrity-5 WHERE id=?')->execute([$ship['id']]);
    $pdo->prepare("UPDATE others_auxiliaries SET location_type='deployed' WHERE id=?")->execute([$aux['id']]);
    $test->assertEquals('others_auxiliary_not_embarked', $kernel->handle('POST', $path, $headers, '{"integrityPercent":2}')->body['error']['code'] ?? null, 'Others repair requires embarked auxiliary');
    $pdo->prepare("UPDATE others_auxiliaries SET location_type='embarked' WHERE id=?")->execute([$aux['id']]);
    $pdo->prepare("UPDATE others_inventory_resources SET reserved_amount=1 WHERE ship_id=? AND resource_type='metals'")->execute([$ship['id']]);
    $test->assertEquals(422, $kernel->handle('POST', $path, $headers, '{"integrityPercent":2}')->status, 'Others repair cannot spend reserved metals');
    $test->assertEquals(1.0, $stock(), 'Rejected repair never consumes metals');
    $test->assertEquals(null, $others->findAuxiliaryForShip($aux['public_id'], (int) $ship['id'])['current_action_id'], 'Rejected repair leaves auxiliary idle');
    $pdo->prepare("UPDATE others_inventory_resources SET reserved_amount=0 WHERE ship_id=? AND resource_type='metals'")->execute([$ship['id']]);

    $keyHeaders = $headers + ['Idempotency-Key' => 'others-repair-once'];
    $accepted = $kernel->handle('POST', $path, $keyHeaders, '{"integrityPercent":2}');
    $test->assertEquals(202, $accepted->status, 'Others repair queues an action');
    if ($accepted->status !== 202) { throw new RuntimeException(json_encode($accepted->body)); }
    $test->assertEquals('auxiliary_repair', $accepted->body['action']['type'], 'Others repair exposes its action type');
    $test->assertEquals(['integrityPercent' => 2, 'metalsCost' => 0.02], $accepted->body['action']['repair'], 'Others repair exposes the actual plan and Manny metal cost');
    $test->assertEquals(1200, strtotime($accepted->body['action']['endsAt']) - strtotime($accepted->body['action']['createdAt']), 'Two repair points take the same 1200 seconds as Manny repair');
    $test->assertEquals(95, $integrity(), 'Repair does not immediately restore integrity');
    $test->assertEquals(0.98, $stock(), 'Repair consumes metals on acceptance');
    $test->assertEquals($accepted->body, $kernel->handle('POST', $path, $keyHeaders, '{"integrityPercent":2}')->body, 'Idempotent repair retry returns original action');
    $test->assertEquals(0.98, $stock(), 'Idempotent repair retry does not consume metals twice');
    $test->assertEquals(409, $kernel->handle('POST', $path, $keyHeaders, '{"integrityPercent":3}')->status, 'Repair rejects conflicting idempotency key');
    $test->assertEquals('others_auxiliary_busy', $kernel->handle('POST', $path, $headers, '{"integrityPercent":2}')->body['error']['code'] ?? null, 'Busy auxiliary cannot repair again');
    $action = $others->findActionByPublicId($accepted->body['action']['id']);
    $early = $scheduledEvents->findById((int) $action['scheduled_event_id']);
    $early->runAt = $action['created_at'];
    $othersService->processScheduledAction($early);
    $test->assertEquals(95, $integrity(), 'Early event does not repair the ship');
    $complete($accepted->body);
    $complete($accepted->body);
    $test->assertEquals(97, $integrity(), 'Scheduled repair restores integrity exactly once');
    $test->assertEquals(null, $others->findAuxiliaryForShip($aux['public_id'], (int) $ship['id'])['current_action_id'], 'Completed repair releases its auxiliary');
    $tracked = $kernel->handle('GET', '/api/others/actions/' . $action['public_id'], $headers);
    $test->assertEquals(['outcome' => 'repaired', 'integrityPercent' => 2, 'integrity' => 97], $tracked->body['action']['result'] ?? null, 'Action lookup exposes the restored points and resulting integrity');

    $clamped = $kernel->handle('POST', $path, $headers, '{"integrityPercent":1000}');
    $test->assertEquals(['integrityPercent' => 3, 'metalsCost' => 0.03], $clamped->body['action']['repair'] ?? null, 'Repair plan is capped at missing integrity');
    $parallel = $kernel->handle('POST', str_replace($aux['public_id'], $otherAux['public_id'], $path), $headers, '{"integrityPercent":3}');
    $complete($clamped->body);
    $complete($parallel->body);
    $test->assertEquals(100, $integrity(), 'Parallel repairs cannot exceed maximum integrity');
    $test->assertEquals(0, $kernel->handle('GET', '/api/others/actions/' . $parallel->body['action']['id'], $headers)->body['action']['result']['integrityPercent'] ?? null, 'Parallel repair reports actual restoration');

    $pdo->prepare('UPDATE others_ships SET integrity=99 WHERE id=?')->execute([$ship['id']]);
    $beforeBatchStock = $stock();
    $batch = $kernel->handle('POST', '/api/others/ships/' . $ship['public_id'] . '/auxiliaries/tasks', $headers, json_encode(['tasks' => [
        ['auxiliaryId' => $aux['public_id'], 'task' => 'repair', 'payload' => ['integrityPercent' => 1]],
        ['auxiliaryId' => $otherAux['public_id'], 'task' => 'repair', 'payload' => ['integrityPercent' => 0]],
    ]], JSON_THROW_ON_ERROR));
    $test->assertEquals(400, $batch->status, 'Invalid second repair rejects the entire task batch');
    $test->assertEquals($beforeBatchStock, $stock(), 'Failed task batch rolls back repair metals');
    $test->assertEquals(null, $others->findAuxiliaryForShip($aux['public_id'], (int) $ship['id'])['current_action_id'], 'Failed task batch rolls back the first auxiliary reservation');

    $standard = $others->createStandardShip($ship);
    $standardAux = $others->createAuxiliary((int) $standard['id']);
    $pdo->prepare('UPDATE others_ships SET integrity=18 WHERE id=?')->execute([$standard['id']]);
    $pdo->prepare("UPDATE others_inventory_resources SET amount=1 WHERE ship_id=? AND resource_type='metals'")->execute([$standard['id']]);
    $standardPath = '/api/others/ships/' . $standard['public_id'] . '/auxiliaries/' . $standardAux['public_id'] . '/repair';
    $standardRepair = $kernel->handle('POST', $standardPath, $headers, '{"integrityPercent":1}');
    $complete($standardRepair->body);
    $test->assertEquals(19, (int) $others->findShipByPublicId($standard['public_id'])['integrity'], 'Standard ship repair restores one whole point, not one percent of twenty');
    $customService = new \VonNeumannGame\Service\OthersService($others, $scheduledEvents, $reinstantiation, new \VonNeumannGame\Repository\Others\OthersPersistence($pdo), ['manny' => ['actions' => ['repairSecondsPerIntegrityPercent' => 7, 'repairMetalsPerIntegrityPercent' => 0.2]]]);
    $customAction = $customService->startAuxiliaryTask($standard, $standardAux, 'repair', ['integrityPercent' => '1']);
    $test->assertEquals(7, strtotime($customAction['ends_at']) - strtotime($customAction['created_at']), 'Others repair reads the configured Manny duration');
    $test->assertEquals(0.2, json_decode($customAction['payload_json'], true)['metalsCost'], 'Others repair reads the configured Manny metal cost');
    $othersService->damageShip($standard['public_id'], 100, 'repair-destroyed-ship', ['type' => 'test']);
    $customService->processScheduledAction($scheduledEvents->findById((int) $customAction['scheduled_event_id']));
    $test->assertEquals('failed', $others->findActionByPublicId($customAction['public_id'])['status'], 'Ship destruction fails queued repair');
    $test->assertEquals(0, (int) $others->findShipByPublicId($standard['public_id'])['integrity'], 'Replayed repair event cannot revive a destroyed ship');

    $operation = $openApiOthersDocument['paths']['/api/others/ships/{shipId}/auxiliaries/{auxiliaryId}/repair']['post'] ?? [];
    $test->assertEquals(['integrityPercent'], $operation['requestBody']['content']['application/json']['schema']['required'] ?? null, 'Others repair OpenAPI requires the canonical field');
    $test->assertEquals('#/components/schemas/OthersRepairResponse', $operation['responses']['202']['content']['application/json']['schema']['$ref'] ?? null, 'Others repair OpenAPI describes the accepted action');
})();
