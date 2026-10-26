<?php
declare(strict_types=1);

require_once __DIR__ . '/MiningCapacityFixture.php';

// Uses the existing batch fixture, whose first two Mannies are already mining.
(static function () use ($test, $pdo, $players, $probes, $mannies, $storage, $kernel, $batchProbe, $batchHeaders, $openApiDocument): void {
    $owner = $players->createPlayer('capacity-api-owner', null);
    $otherProbe = $probes->createForPlayer($owner->id, 'Capacity API owner', $batchProbe->currentSector);
    $pdo->beginTransaction();
    $seeded = miningCapacitySeed($mannies, $otherProbe, 'batch-mine-rock', 1998);
    $pdo->commit();
    $first = $mannies->createForProbe($batchProbe->id, 'Capacity API first');
    $second = $mannies->createForProbe($batchProbe->id, 'Capacity API second');
    $storage->repairProbeStorage($batchProbe);
    $path = '/api/probe/' . $batchProbe->id . '/mannies';
    $payload = ['objectId' => 'batch-mine-rock', 'resource' => 'metals', 'targetAmount' => 0.01];
    try {
        $response = $kernel->handle('POST', $path . '/' . $first->uid . '/mine', $batchHeaders, json_encode($payload));
        $test->assertEquals(409, $response->status, 'API refuses a full mining object across players');
        $test->assertEquals('mining_target_capacity_reached', $response->body['error']['code'] ?? null, 'API exposes the capacity error');
        $codes = $openApiDocument['components']['schemas']['ErrorResponse']['properties']['error']['properties']['code']['enum'] ?? [];
        $test->assert(in_array('mining_target_capacity_reached', $codes, true), 'OpenAPI enumerates the capacity error');
        $pdo->prepare('UPDATE mannies SET current_task=NULL WHERE id=?')->execute([$seeded[0]]);
        $before = (int) $pdo->query('SELECT COUNT(*) FROM scheduled_events')->fetchColumn();
        $response = $kernel->handle('POST', $path . '/tasks', $batchHeaders, json_encode(['tasks' => [
            ['mannyId' => $first->uid, 'task' => 'mine', 'payload' => $payload],
            ['mannyId' => $second->uid, 'task' => 'mine', 'payload' => $payload],
        ]]));
        $test->assertEquals(409, $response->status, 'API rejects the whole batch if only one mining slot remains');
        $test->assertEquals('mining_target_capacity_reached', $response->body['error']['code'] ?? null, 'batch capacity error remains specific');
        $test->assertEquals(['taskIndex' => 1, 'mannyId' => $second->uid], $response->body['error']['details'] ?? null, 'capacity error identifies the overflowing batch member');
        $test->assertEquals($before, (int) $pdo->query('SELECT COUNT(*) FROM scheduled_events')->fetchColumn(), 'API capacity rejection rolls back all scheduler inserts');
        $test->assertEquals(null, $mannies->findById($first->id)->currentTask, 'API rejection leaves the first Manny idle');
        $test->assertEquals(null, $mannies->findById($second->id)->currentTask, 'API rejection leaves the second Manny idle');
        $response = $kernel->handle('POST', $path . '/' . $first->uid . '/mine', $batchHeaders, json_encode($payload));
        $test->assertEquals(202, $response->status, 'a single order can use the slot after batch rollback');
    } finally {
        foreach (array_merge($seeded, [$first->id, $second->id]) as $id) {
            $pdo->prepare("DELETE FROM scheduled_events WHERE entity_type='manny' AND entity_id=?")->execute([$id]);
            $pdo->prepare('DELETE FROM manny_tasks WHERE manny_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM mannies WHERE id=?')->execute([$id]);
        }
    }
})();
