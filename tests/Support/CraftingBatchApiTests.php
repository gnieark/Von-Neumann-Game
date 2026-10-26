<?php
declare(strict_types=1);

require_once __DIR__ . '/CraftingBatchFixture.php';

(static function () use ($test, $pdo, $kernel, $auth): void {
    $f = craftingBatchFixture($pdo, sys_get_temp_dir() . '/vng-crafting-api-unused', 'craft-batch-api', 103, 3);
    $probe = $f['probe'];
    $headers = ['Authorization' => 'Bearer ' . $auth->createSessionForPlayer($f['players']->findById($probe->playerId))['token']];
    $path = '/api/probe/' . $probe->id . '/mannies/tasks';
    $tasks = array_map(fn($m) => ['mannyId' => $m->uid, 'task' => 'craft', 'payload' => ['recipe' => 'electric_motor']], array_slice($f['actors'], 0, 100));
    foreach (['recipe', 'missing'] as $failure) {
        $invalid = array_slice($tasks, 0, 2);
        if ($failure === 'recipe') { $invalid[1]['payload']['recipe'] = 'no-such-recipe'; }
        else { $invalid[1]['mannyId'] = 'missing-craft-manny'; }
        $before = (int) $pdo->query('SELECT COUNT(*) FROM scheduled_events')->fetchColumn();
        $response = $kernel->handle('POST', $path, $headers, json_encode(['tasks' => $invalid]));
        $test->assertEquals($failure === 'recipe' ? 400 : 404, $response->status, 'pure craft batch preserves ' . $failure . ' error');
        $test->assertEquals(['taskIndex' => 1, 'mannyId' => $invalid[1]['mannyId']], $response->body['error']['details'] ?? null, 'craft batch identifies failing member in request order');
        $test->assertEquals($before, (int) $pdo->query('SELECT COUNT(*) FROM scheduled_events')->fetchColumn(), 'rejected craft batch leaves no events');
        $test->assertEquals(3, count($f['items']->findByProbeId($probe->id)), 'rejected craft batch restores its components');
    }
    $response = $kernel->handle('POST', $path, $headers, json_encode(['tasks' => $tasks]));
    $test->assertEquals(202, $response->status, 'API accepts 100 crafts using shared inventory');
    $test->assertEquals(array_column($tasks, 'mannyId'), array_column(array_column($response->body['results'] ?? [], 'manny'), 'id'), '100 craft results retain request order');
    $first = $f['mannies']->findById($f['actors'][0]->id);
    $second = $f['mannies']->findById($f['actors'][1]->id);
    $test->assert($first->taskPayload['durationSeconds'] < $second->taskPayload['durationSeconds'], 'API batch costs account for exhausted components');
    $busy = [$tasks[100 - 1], array_replace($tasks[0], ['mannyId' => $f['actors'][100]->uid])];
    $response = $kernel->handle('POST', $path, $headers, json_encode(['tasks' => array_reverse($busy)]));
    $test->assertEquals(409, $response->status, 'busy actor still rejects entire craft batch');
    $test->assertEquals(1, $response->body['error']['details']['taskIndex'] ?? null, 'busy actor is reported at its input index');
    $test->assertEquals(null, $f['mannies']->findById($f['actors'][100]->id)->currentTask, 'busy actor rolls back preceding craft');

    // A recall restores components: the following craft must observe the updated SQL inventory.
    $mixed = [
        ['mannyId' => $first->uid, 'task' => 'recall', 'payload' => new stdClass()],
        ['mannyId' => $f['actors'][100]->uid, 'task' => 'craft', 'payload' => ['recipe' => 'electric_motor']],
    ];
    $response = $kernel->handle('POST', $path, $headers, json_encode(['tasks' => $mixed]));
    $test->assertEquals(202, $response->status, 'mixed recall/craft batch continues to use sequential inventory');
    $last = $f['mannies']->findById($f['actors'][100]->id);
    $test->assertEquals($first->taskPayload['durationSeconds'], $last->taskPayload['durationSeconds'], 'mixed craft consumes the components restored by preceding recall');
})();
