<?php
declare(strict_types=1);

(static function () use ($test): void {
    $root = sys_get_temp_dir() . '/vng-cutover-' . bin2hex(random_bytes(6));
    mkdir($root . '/universe/sectors', 0700, true);
    mkdir($root . '/job', 0700);
    $db = new PDO('sqlite:' . $root . '/world.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
    $monitor = new \VonNeumannGame\Operations\MineableCutoverMonitor($db);
    $gate = new \VonNeumannGame\Repository\StorageMigrationRepository($db);
    $now = time();
    try {
        file_put_contents($root . '/job/plan.json', '{}');
        $meta = ['complete' => true, 'migrationId' => 'cutover-test', 'fingerprint' => (new \VonNeumannGame\Database\Migration\WorldMaintenanceBackup($db, $root . '/universe'))->fingerprint()];
        file_put_contents($root . '/job/manifest.json', json_encode($meta));
        $test->assertThrows(fn() => $monitor->assertManifest($root . '/job', $root . '/universe'), RuntimeException::class, 'cutover refuses an absent SQL migration proof');
        $gate->begin('cutover-test');
        $gate->bindPlan('cutover-test', hash_file('sha256', $root . '/job/plan.json'));
        $test->assertThrows(fn() => $monitor->assertManifest($root . '/job', $root . '/universe'), RuntimeException::class, 'cutover refuses a blocked migration');
        $gate->verified('cutover-test');
        $test->assertEquals('cutover-test', $monitor->assertManifest($root . '/job', $root . '/universe'), 'cutover matches the verified SQL/files pair');
        file_put_contents($root . '/job/plan.json', '{"modified":true}');
        $test->assertThrows(fn() => $monitor->assertManifest($root . '/job', $root . '/universe'), RuntimeException::class, 'cutover refuses a modified plan');
        file_put_contents($root . '/job/plan.json', '{}');
        mkdir($root . '/other-universe');
        $test->assertThrows(fn() => $monitor->assertManifest($root . '/job', $root . '/other-universe'), RuntimeException::class, 'cutover refuses another universe');
        $db->exec("INSERT INTO mineable_object_identities(object_id) VALUES('changed-after-opening')");
        $db->exec("INSERT INTO mineable_object_stocks VALUES('changed-after-opening',0,42,0,0,3)");
        $test->assertEquals('ok', $monitor->collect($now, $now - 60)['status'], 'live monitoring accepts legitimate stock/version evolution');

        file_put_contents($root . '/database.json', json_encode(['driver' => 'sqlite', 'path' => $root . '/world.sqlite']));
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-mineable-cutover.php', '--database-config=' . $root . '/database.json', '--universe-path=' . $root . '/universe', '--manifest-dir=' . $root . '/job', '--since=' . gmdate('c', $now - 60)];
        $run = static function (array $args): array {
            $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            return [proc_close($process), $out, $err];
        };
        [$status, $out] = $run($command);
        $test->assertEquals(0, $status, 'read-only cutover CLI succeeds on a verified live world');
        $test->assertEquals(1, json_decode($out, true)['counters']['stocks'] ?? null, 'cutover CLI reports current stocks');
        [$status] = $run([...$command, '--apply']);
        $test->assertEquals(2, $status, 'cutover CLI rejects a mutation flag');

        $insert = $db->prepare("INSERT INTO scheduled_events(type,entity_type,entity_id,run_at,status,payload_json,processed_at,created_at,updated_at,locked_at,conflict_count,conflict_started_at) VALUES('manny.task','manny',1,?,?,'{}',?,?,?,NULL,?,?)");
        $insert->execute([gmdate('c', $now - 3600), 'failed', gmdate('c', $now - 3600), gmdate('c', $now), gmdate('c', $now), 0, null]);
        $insert->execute([gmdate('c', $now - 1000), 'pending', null, gmdate('c', $now), gmdate('c', $now), 2, gmdate('c', $now - 1000)]);
        $db->exec("INSERT INTO probe_operations VALUES('expired-test',1," . ($now - 10) . ")");
        $db->prepare("INSERT INTO probe_losses(probe_id,reason,causal_at,state,updated_at) VALUES(1,'test',?,'pending',?)")->execute([gmdate('c', $now - 3600), gmdate('c', $now - 3600)]);
        $report = $monitor->collect($now, $now - 60);
        $test->assertEquals('warning', $report['status'], 'historical failure and prolonged contention require review, not a false new incident');
        $test->assertEquals(0, $report['counters']['failedEventsSince'], 'cutover separates failures before reopening');
        $test->assertEquals(1, $report['counters']['oldMiningConflicts'], 'cutover detects prolonged mining contention');
        $test->assertEquals(1, $report['counters']['expiredAdmissions'], 'cutover reports expired tickets without recovering them');
        $test->assertEquals(1, $report['counters']['oldPendingProbeLosses'], 'cutover detects stalled probe destruction cleanup');
        [$status] = $run($command);
        $test->assertEquals(1, $status, 'cutover warning has a non-green exit status');
        $insert->execute([gmdate('c', $now), 'failed', gmdate('c', $now), gmdate('c', $now), gmdate('c', $now), 0, null]);
        $test->assertEquals('critical', $monitor->collect($now, $now - 60)['status'], 'new failure raises a critical signal');
        [$status] = $run($command);
        $test->assertEquals(2, $status, 'cutover critical signal has exit status two');
        $changes = $db->query('SELECT total_changes()')->fetchColumn();
        $db->exec('PRAGMA query_only=ON');
        $monitor->assertManifest($root . '/job', $root . '/universe');
        $monitor->collect($now, $now - 60);
        $test->assertEquals($changes, $db->query('SELECT total_changes()')->fetchColumn(), 'monitor performs no SQL mutation');
        $test->assertThrows(fn() => $monitor->collect($now, $now + 1), InvalidArgumentException::class, 'monitor rejects a future observation window');
    } finally {
        $db = null;
        \VonNeumannGame\Sector\Tests\removeDirectory($root);
    }
})();

(static function () use ($test): void {
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, "ordinary log line\n");
    for ($i = 1; $i <= 100; $i++) {
        fwrite($stream, '[PHP] ' . json_encode(['metric' => 'scheduler_mining', 'outcome' => 'success', 'duration_ms' => $i, 'completed' => $i <= 60, 'recovered_conflicts' => $i === 1 ? 2 : 0]) . "\n");
    }
    fwrite($stream, json_encode(['metric' => 'scheduler_mining', 'outcome' => 'sql_conflict', 'duration_ms' => 1001, 'transaction_ms' => 1000, 'sql_code' => 1205, 'lock_family' => 'mineable_stock', 'alert' => true, 'payload' => 'SECRET']) . "\n");
    fwrite($stream, '{"metric":"scheduler_mining","outcome":"success","duration_ms":-1}' . "\n");
    fwrite($stream, '{"metric":"scheduler_mining",broken' . "\n");
    rewind($stream);
    $result = (new \VonNeumannGame\Operations\MiningMetricsSummary())->read($stream, 120);
    fclose($stream);
    $test->assertEquals(101, $result['records'], 'metrics ignore noise and count valid executions');
    $test->assertEquals(2, $result['malformedRecords'], 'metrics expose malformed and negative-duration records');
    $test->assertEquals(95.0, $result['durationsMs']['success']['duration_ms']['p95'], 'metrics compute nearest-rank p95');
    $test->assertEquals(99.0, $result['durationsMs']['success']['duration_ms']['p99'], 'metrics compute nearest-rank p99');
    $test->assertEquals(0.5, $result['completedExecutionsPerSecond'], 'metrics exclude successful deferrals from completed throughput');
    $test->assertEquals(1, $result['successfulExecutionsAfterConflict'], 'metrics count recovered executions rather than conflict attempts');
    $test->assertEquals(1, $result['sqlCodes'][1205], 'metrics preserve the SQL conflict code');
    $test->assertEquals(1, $result['conflictAlerts'], 'metrics count conflict alerts');
    $test->assert(!str_contains(json_encode($result), 'SECRET'), 'metrics do not disclose raw payloads');
    $stream = fopen('php://temp', 'w+');
    $empty = (new \VonNeumannGame\Operations\MiningMetricsSummary())->read($stream);
    fclose($stream);
    $test->assertEquals([], $empty['durationsMs'], 'empty journal does not fabricate percentiles');
    $test->assertEquals(null, $empty['completedExecutionsPerSecond'], 'unknown log duration does not fabricate throughput');
})();
