<?php
declare(strict_types=1);

use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\MineableResourceAmounts;
use VonNeumannGame\Database\SchedulerRetryPolicy;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;

require_once __DIR__ . '/MiningRetryFixture.php';
(static function () use ($test): void {
    $policy = new SchedulerRetryPolicy();
    foreach ([1 => [1, 1], 2 => [4, 6], 3 => [12, 18], 4 => [48, 72], 5 => [240, 300], 100 => [240, 300]] as $count => [$min, $max]) {
        $delays = array_map(fn() => $policy->delay($count), range(1, 30));
        $test->assert(min($delays) >= $min && max($delays) <= $max, 'bounded jitter at conflict ' . $count);
    }
    foreach ([1205, 1213, 3572, 1062, 1452, 2006] as $code) {
        $error = new PDOException('secret'); $error->errorInfo = ['HY000', $code, 'secret'];
        $wrapped = new \VonNeumannGame\Database\StorageBusyException('generic', 0, $error);
        $test->assertEquals(in_array($code, [1205, 1213, 3572], true), SchedulerRetryPolicy::conflict($wrapped, 'mysql') !== null, 'classify native SQL code ' . $code);
    }
    $test->assertEquals(null, SchedulerRetryPolicy::conflict(new RuntimeException('1213 deadlock'), 'mysql'), 'message matching never retries business errors');

    $db = new MiningRetryFaultPdo(); (new SchemaInitializer('sqlite'))->initialize($db);
    $directory = sys_get_temp_dir() . '/vng-mining-retry-' . bin2hex(random_bytes(6));
    $f = miningAtomicFixture($db, $directory);
    $records = [];
    $scheduler = miningRetryScheduler($db, $f, static function (array $record) use (&$records): void { $records[] = $record; });
    $sector = new SectorCoordinates(822, 822, 0);
    $rock = new Asteroid('retry-rock', 'Rock', 'iron', ['metals'], 'small', 1, 1, resourceAmounts: array_replace(MineableResourceAmounts::zero(), ['metals' => 10.0]));
    $f['files']->save(new SectorContent($sector, [$rock]));
    (new StorageTransaction($db))->run(fn() => $f['stocks']->create('retry-rock-create', $rock->getId(), $rock->getResourceAmounts()));
    [$probe, $manny] = miningAtomicActor($f, 'retry-miner', $sector);
    $hash = hash_file('sha256', $f['files']->getPath($sector));
    $manny = $f['mining']->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.1);
    $event = miningRetryDue($db, $f, $manny);
    $taskEndsAt = $f['mannies']->findById($manny->id)->taskEndsAt;
    $before = clone $event;
    $context = $f['events']->retryableTaskContext($event);
    try {
        for ($i = 1; $i <= 6; $i++) {
            if ($i > 1) { $db->prepare('UPDATE scheduled_events SET run_at=? WHERE id=?')->execute([gmdate('c', time() - 1), $event->id]); }
            if ($i === 5) { $db->prepare('UPDATE scheduled_events SET conflict_started_at=? WHERE id=?')->execute([gmdate('c', time() - 901), $event->id]); }
            $db->faultSql = 'UPDATE mineable_object_stocks SET';
            $stats = $scheduler->processDueEvents();
            $event = $f['events']->findById($event->id);
            $test->assertEquals(1, $stats['deferred'], 'SQL conflict defers the event ' . $i);
            $test->assertEquals($i, $event->conflictCount, 'consecutive conflicts survive rollback ' . $i);
            $test->assertEquals(0, $event->attempts, 'rolled back claim does not masquerade as a business execution');
            $test->assertEquals('pending', $event->status, 'temporary failure stays pending');
            $test->assertEquals(10.0, $f['stocks']->find($rock->getId())['amounts']['metals'], 'source debit rolled back');
            $test->assertEquals(0.0, $f['storage']->resourceStock($probe, 'metals'), 'no partial destination credit');
            $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM probe_operations')->fetchColumn(), 'no admission retained during backoff');
            $test->assertEquals($taskEndsAt, $f['mannies']->findById($manny->id)->taskEndsAt, 'retry preserves business deadline');
        }
        $test->assertEquals(1, count(array_filter($records, fn($r) => $r['alert'] ?? false)), 'one alert after fifteen minutes, retries continue');
        $test->assert(!str_contains(json_encode($records), 'secret'), 'diagnostics exclude SQL messages and payloads');
        $test->assertEquals('mineable_stock', $records[0]['lock_family'], 'diagnostic identifies the failed stock lock family');
        $test->assertEquals(null, $f['events']->deferTaskConflict($before, $context, ['sql_code' => 5], $policy), 'stale report cannot overwrite a later retry');

        $db->prepare('UPDATE scheduled_events SET run_at=? WHERE id=?')->execute([gmdate('c', time() - 1), $event->id]);
        $stats = $scheduler->processDueEvents();
        $event = $f['events']->findById($event->id);
        $test->assertEquals(1, $stats['processed'], 'successful retry completes mining');
        $test->assertEquals([0, null, null], [$event->conflictCount, $event->conflictStartedAt, $event->conflictAlertedAt], 'success resets the complete conflict series');
        $test->assertEquals(9.9, $f['stocks']->find($rock->getId())['amounts']['metals'], 'successful retry debits exactly once');
        $test->assertEquals(0.1, $f['storage']->resourceStock($probe, 'metals'), 'successful retry credits exactly once');
        $test->assertEquals(6, end($records)['recovered_conflicts'], 'recovery metric includes the previous series');
        $test->assertEquals($hash, hash_file('sha256', $f['files']->getPath($sector)), 'retry never writes stock JSON');

        // Admission and commit failures also roll back safely, before/after the handler.
        foreach (['admission', 'commit'] as $phase) {
            [$probe, $manny] = miningAtomicActor($f, 'retry-' . $phase, $sector);
            $manny = $f['mining']->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.1);
            $event = miningRetryDue($db, $f, $manny);
            if ($phase === 'admission') { $db->faultSql = 'INSERT INTO probe_operations'; }
            else {
                $db->beforeCommit = static function (MiningRetryFaultPdo $db): void {
                    if ((float) $db->query("SELECT metals FROM mineable_object_stocks WHERE object_id='retry-rock'")->fetchColumn() >= 9.9) { return; }
                    $db->beforeCommit = null; $db->faultSql = 'commit'; $db->inject('commit');
                };
            }
            $test->assertEquals(1, $scheduler->processDueEvents()['deferred'], $phase . ' conflict is deferred');
            $test->assertEquals(9.9, $f['stocks']->find($rock->getId())['amounts']['metals'], $phase . ' conflict has no debit');
            $f['mining']->recallManny($probe, $manny->uid);
            $test->assertEquals(null, $f['events']->deferTaskConflict($event, $context, ['sql_code' => 5], $policy), 'recall dominates a late report');
        }

        // A normal incomplete task clears the series without postponing its business end.
        [$probe, $manny] = miningAtomicActor($f, 'retry-deferred', $sector);
        $manny = $f['mining']->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.1);
        $event = $f['events']->findById($manny->taskScheduledEventId);
        $db->prepare('UPDATE scheduled_events SET run_at=?,conflict_count=3,conflict_started_at=? WHERE id=?')->execute([gmdate('c', time()-1), gmdate('c', time()-10), $event->id]);
        $test->assertEquals(1, $scheduler->processDueEvents()['deferred'], 'business deferral is successful execution');
        $test->assertEquals(0, $f['events']->findById($event->id)->conflictCount, 'business deferral resets SQL conflicts');
        $f['mining']->recallManny($probe, $manny->uid);

        // Permanent SQL errors never become retries, even after a real stock write.
        [$probe, $manny] = miningAtomicActor($f, 'retry-permanent', $sector);
        $manny = $f['mining']->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.1);
        $event = miningRetryDue($db, $f, $manny);
        $db->faultCode = 19; $db->faultSql = 'UPDATE mineable_object_stocks SET';
        $test->assertEquals(1, $scheduler->processDueEvents()['failed'], 'integrity failure remains permanent');
        $test->assertEquals('failed', $f['events']->findById($event->id)->status, 'permanent error is recorded without retry');
        $test->assertEquals(0, $f['events']->findById($event->id)->conflictCount, 'integrity failure does not increment conflicts');
        $test->assertEquals(9.9, $f['stocks']->find($rock->getId())['amounts']['metals'], 'permanent failure also rolls back stocks');
        $f['mining']->recallManny($probe, $manny->uid);

        // A vanished container is permanent; a prior conflict must not hide it.
        [$probe, $manny] = miningAtomicActor($f, 'retry-container', $sector);
        $container = new \VonNeumannGame\Sector\SectorDetachedContainer('retry-container', 'Box', 'drifting', $probe->id, $probe->playerId, null, null, 1, 'ECE', gmdate('c'),
            ['sourceContainerId' => 'container-retry-item', 'containerItem' => ['uid' => 'retry-item', 'type' => 'additional_container', 'name' => 'Box', 'containerSpace' => 0.0, 'metadata' => ['capacityBonus' => 1.0]], 'resources' => []]);
        $f['detached']->save($sector, $container);
        $manny = $f['mining']->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.1, $container->getId());
        $event = miningRetryDue($db, $f, $manny);
        $db->faultCode = 5; $db->faultSql = 'UPDATE mineable_object_stocks SET';
        $scheduler->processDueEvents();
        $f['detached']->delete($container->getId());
        $db->prepare('UPDATE scheduled_events SET run_at=? WHERE id=?')->execute([gmdate('c', time()-1), $event->id]);
        $test->assertEquals(1, $scheduler->processDueEvents()['failed'], 'container disappearance is permanent after a conflict');
        $test->assertEquals('detached_container_not_found', $f['events']->findById($event->id)->lastError, 'missing container keeps its business diagnostic');
        $test->assertEquals(9.9, $f['stocks']->find($rock->getId())['amounts']['metals'], 'vanished container consumes nothing');
        $f['mining']->recallManny($probe, $manny->uid);

        // Expiration wins on the next attempt even if resources are now available.
        [$probe, $manny] = miningAtomicActor($f, 'retry-expired', $sector);
        $manny = $f['mining']->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.1);
        $event = miningRetryDue($db, $f, $manny);
        $db->faultSql = 'UPDATE mineable_object_stocks SET'; $scheduler->processDueEvents();
        $manny = $f['mannies']->findById($manny->id);
        $manny->taskPayload['waitingFor'] = 'storage_space';
        $manny->taskPayload[\VonNeumannGame\Domain\Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY] = gmdate('c', time() - 604801);
        $f['mannies']->save($manny);
        $db->prepare('UPDATE scheduled_events SET run_at=? WHERE id=?')->execute([gmdate('c', time()-1), $event->id]);
        $test->assertEquals(1, $scheduler->processDueEvents()['processed'], 'existing storage deadline ends the mining mission');
        $test->assertEquals(9.9, $f['stocks']->find($rock->getId())['amounts']['metals'], 'expiration prevents extraction on retry');
        $test->assertEquals(null, $f['mannies']->findById($manny->id)->currentTask, 'expired mission is not prolonged by retry');

        // A SQL conflict in an unproven handler is not automatically replayed.
        $event = $f['events']->schedule('unknown.handler', 'test', 1, gmdate('c', time()-1));
        $db->faultSql = "SET status = 'running'";
        $test->assertEquals(1, $scheduler->processDueEvents()['failed'], 'legacy handler conflict stays outside automatic mining retries');
        $test->assertEquals(0, $f['events']->findById($event->id)->conflictCount, 'legacy handler has no mining counter');

        // A newer lease, including one held by the same worker, fences old reports.
        [$probe, $manny] = miningAtomicActor($f, 'retry-lease', $sector);
        $manny = $f['mining']->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.1);
        $event = miningRetryDue($db, $f, $manny); $context = $f['events']->retryableTaskContext($event);
        $old = clone $event; $claimed = $f['events']->claim($event);
        $test->assertEquals(null, $f['events']->deferTaskConflict($old, $context, ['sql_code' => 5], $policy), 'new live claim fences old report');
        $f['events']->release($claimed, $old->runAt, $claimed->payload);
        $test->assertEquals(null, $f['events']->deferTaskConflict($old, $context, ['sql_code' => 5], $policy), 'released newer claim still fences old report');
        $newClaim = $f['events']->claim($f['events']->findById($event->id));
        foreach (['release', 'done', 'failed'] as $transition) {
            try {
                match ($transition) {
                    'release' => $f['events']->release($claimed, $old->runAt, []),
                    'done' => $f['events']->markDone($claimed),
                    'failed' => $f['events']->markFailed($claimed, new RuntimeException('old failure')),
                };
                $test->assert(false, 'stale same-worker transition is fenced: ' . $transition);
            } catch (\VonNeumannGame\Repository\ScheduledEventLeaseLostException) {
                $test->assert(true, 'stale same-worker transition is fenced: ' . $transition);
            }
        }
        $test->assert(!$f['events']->renewLease($claimed), 'stale same-worker lease cannot be renewed');
        $f['events']->release($newClaim, $old->runAt, $newClaim->payload);
        $old = $f['events']->findById($event->id);
        (new \VonNeumannGame\Repository\ProbeOperationRepository($db))->close($probe->id);
        $test->assertEquals(null, $f['events']->deferTaskConflict($old, $context, ['sql_code' => 5], $policy), 'closed admissions prevent new retry');
        $test->assertEquals(0, $scheduler->processDueEvents()['due'], 'closed probe is left to destruction cleanup');
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
})();

(static function () use ($test): void {
    $directory = sys_get_temp_dir() . '/vng-retry-migration-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $path = $directory . '/database.sqlite';
    $config = $directory . '/database.json';
    file_put_contents($config, json_encode(['driver' => 'sqlite', 'path' => $path], JSON_THROW_ON_ERROR));
    $db = new PDO('sqlite:' . $path);
    // Partial migration: the first column exists and already holds a live series.
    $db->exec('CREATE TABLE scheduled_events(id INTEGER PRIMARY KEY, attempts INTEGER, conflict_count INTEGER NOT NULL DEFAULT 0)');
    $db->exec('INSERT INTO scheduled_events VALUES(1,42,3)');
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/one-shot-scripts/migrate-scheduler-mining-conflicts.php') . ' --database-config=' . escapeshellarg($config);
    try {
        $hash = hash_file('sha256', $path); $output = []; $code = 0;
        exec($command . ' --dry-run 2>&1', $output, $code);
        $test->assertEquals(0, $code, 'retry migration dry run succeeds');
        $test->assertEquals($hash, hash_file('sha256', $path), 'retry migration dry run leaves the database untouched');
        exec($command . ' --apply 2>&1', $output, $code);
        $test->assertEquals(0, $code, 'retry migration resumes partially added columns');
        $test->assertEquals([42, 3, null, null], $db->query('SELECT attempts,conflict_count,conflict_started_at,conflict_alerted_at FROM scheduled_events')->fetch(PDO::FETCH_NUM), 'migration preserves attempt and conflict counts');
        $db->exec("UPDATE scheduled_events SET conflict_started_at='2026-10-18T00:00:00+00:00',conflict_alerted_at='2026-10-18T00:15:00+00:00'");
        $before = $db->query('SELECT * FROM scheduled_events')->fetchAll(PDO::FETCH_ASSOC);
        exec($command . ' --apply 2>&1', $output, $code);
        $test->assertEquals(0, $code, 'retry migration can be applied twice');
        $test->assertEquals($before, $db->query('SELECT * FROM scheduled_events')->fetchAll(PDO::FETCH_ASSOC), 'repeated migration preserves active series and alerts');
        $db->exec('INSERT INTO scheduled_events(id,attempts) VALUES(2,0)');
        $test->assertEquals(0, (int) $db->query('SELECT conflict_count FROM scheduled_events WHERE id=2')->fetchColumn(), 'new events begin without conflicts');
    } finally {
        $db = null;
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
})();
