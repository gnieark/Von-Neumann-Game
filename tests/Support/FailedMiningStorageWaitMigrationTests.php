<?php

declare(strict_types=1);

// Isolate migration fixtures from the API suite's live repositories.
(static function () use ($tmp, $root, $test): void {
    $dbPath = $tmp . '/failed-mining-migration.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE mannies (id INTEGER PRIMARY KEY,probe_id INTEGER,name TEXT,current_task TEXT,task_ends_at TEXT,task_scheduled_event_id INTEGER)');
    $pdo->exec('CREATE TABLE scheduled_events (id INTEGER PRIMARY KEY,type TEXT,entity_type TEXT,entity_id INTEGER,status TEXT,run_at TEXT,attempts INTEGER,payload_json TEXT,locked_at TEXT,locked_by TEXT,processed_at TEXT,last_error TEXT,updated_at TEXT)');
    $error = 'Storage wait is missing its canonical start timestamp; run the migration for waiting-for-space or blocked mining tasks.';
    $oldEnd = '2026-01-01T00:00:00+00:00';
    $futureEnd = gmdate('c', time() + 3600);
    $wait = ['waitingFor' => 'storage_space', 'reason' => 'mining_output', '_scheduledRunAt' => $oldEnd, 'resourceProfile' => ['metals' => 1], 'custom' => 'preserved'];
    $insertEvent = $pdo->prepare('INSERT INTO scheduled_events VALUES (?, ?, ?, ?, ?, ?, 522, ?, NULL, NULL, ?, ?, ?)');
    $insertManny = $pdo->prepare('INSERT INTO mannies VALUES (?, 752, ?, ?, ?, ?)');
    foreach (range(1, 9) as $id) {
        $task = in_array($id, [2, 8], true) ? 'returning' : 'mining';
        $payload = $id === 6 ? ['reason' => 'unrelated'] : $wait;
        if ($id === 5) {
            $payload['waitingForSpaceSince'] = '2026-01-02T00:00:00+00:00';
        }
        $insertManny->execute([$id, 'manny-' . $id, $task, $id === 8 ? $futureEnd : $oldEnd, $id === 9 ? 999 : $id]);
        $insertEvent->execute([$id, 'manny.task', 'manny', $id, $id === 4 ? 'pending' : 'failed', $oldEnd,
            json_encode($payload), $oldEnd, $id === 3 ? 'Unrelated failure' : $error, $oldEnd]);
    }
    // A failure with a different task type must not be rearmed.
    $pdo->exec("UPDATE mannies SET current_task='crafting' WHERE id=7");
    $configPath = $tmp . '/failed-mining-migration.json';
    file_put_contents($configPath, json_encode(['driver' => 'sqlite', 'path' => $dbPath]));
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/one-shot-scripts/requeue-failed-mining-storage-waits.php')
        . ' ' . escapeshellarg('--database-config=' . $configPath);
    $read = static fn(): array => $pdo->query('SELECT * FROM scheduled_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $run = static function (string $args) use ($command): int {
        exec($command . ' ' . $args . ' 2>&1', $output, $status);
        return $status;
    };
    $before = $read();
    $test->assertEquals(0, $run('--dry-run'), 'failed mining repair dry run succeeds');
    $test->assertEquals($before, $read(), 'failed mining dry run changes no events');
    $test->assert($run('') !== 0, 'failed mining repair requires backup');
    $test->assertEquals($before, $read(), 'missing backup leaves all events unchanged');
    $backupPath = $tmp . '/failed-mining-backup.json';
    file_put_contents($backupPath, 'do not overwrite');
    $test->assert($run(escapeshellarg('--backup=' . $backupPath)) !== 0, 'existing backup prevents migration');
    $test->assertEquals('do not overwrite', file_get_contents($backupPath), 'existing backup is preserved');
    $test->assertEquals($before, $read(), 'backup failure rolls back all events');
    $backupPath = $tmp . '/failed-mining-backup-new.json';
    $test->assertEquals(0, $run(escapeshellarg('--backup=' . $backupPath)), 'failed mining repair succeeds');
    $after = $read();
    $backup = json_decode(file_get_contents($backupPath), true, 512, JSON_THROW_ON_ERROR);
    $test->assertEquals(4, count($backup['plans']), 'backup contains each of the four selected events');
    $test->assertEquals($before[0]['payload_json'], $backup['plans'][0]['before']['payload_json'], 'backup preserves original payload');
    $test->assertEquals($oldEnd, json_decode($after[0]['payload_json'], true)['waitingForSpaceSince'], 'repair uses historical mining end, not migration time');
    foreach ([0, 1, 4, 7] as $index) {
        $test->assertEquals('pending', $after[$index]['status'], 'selected event requeued');
        $test->assertEquals(0, (int) $after[$index]['attempts'], 'retry attempts reset');
        $test->assertEquals(null, $after[$index]['last_error'], 'previous error cleared');
        $test->assertEquals(null, $after[$index]['processed_at'], 'previous processed timestamp cleared');
        $test->assertEquals('preserved', json_decode($after[$index]['payload_json'], true)['custom'], 'unrelated payload data preserved');
    }
    foreach ([2, 3, 5, 6, 8] as $index) {
        $test->assertEquals($before[$index], $after[$index], 'unrelated, pending, or detached event unchanged');
    }
    $test->assertEquals('2026-01-02T00:00:00+00:00', json_decode($after[4]['payload_json'], true)['waitingForSpaceSince'], 'existing wait start preserved');
    $return = json_decode($after[1]['payload_json'], true);
    $test->assert(!isset($return['waitingFor'], $return['reason'], $return['waitingForSpaceSince']), 'recall clears stale mining wait');
    $test->assertEquals($futureEnd, $after[7]['run_at'], 'future recall is not executed early');
    $test->assertEquals($futureEnd, json_decode($after[7]['payload_json'], true)['_scheduledRunAt'], 'recall scheduler timestamp follows return task');
    $test->assertEquals('returning', $pdo->query('SELECT current_task FROM mannies WHERE id=2')->fetchColumn(), 'player recall stays a return task');
    $test->assertEquals(0, $run(escapeshellarg('--backup=' . $backupPath)), 'repair can be replayed without replacing backup');
    $test->assertEquals($after, $read(), 'repair replay does not change repaired events');
})();
