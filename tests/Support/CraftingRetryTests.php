<?php
declare(strict_types=1);

require_once __DIR__ . '/CraftingRetryFixture.php';

(static function () use ($test): void {
    $db = new MiningRetryFaultPdo();
    (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
    $directory = sys_get_temp_dir() . '/vng-crafting-retry-' . bin2hex(random_bytes(6));
    $f = miningAtomicFixture($db, $directory);
    $records = [];
    $scheduler = craftingRetryScheduler($db, $f, static function (array $record) use (&$records): void { $records[] = $record; });
    $policy = new \VonNeumannGame\Database\SchedulerRetryPolicy();
    $due = static function (int $eventId) use ($db): void {
        $db->prepare('UPDATE scheduled_events SET run_at=? WHERE id=?')->execute([gmdate('c', time() - 1), $eventId]);
    };
    try {
        foreach ([false, true] as $printer) {
            $records = [];
            [$probe, $manny] = craftingRetryOrder($f, $printer ? 'retry-printer' : 'retry-crafter', $printer);
            $event = miningRetryDue($db, $f, $manny);
            $task = $f['mannies']->findById($manny->id);
            $stock = $f['storage']->resourceStock($probe, 'metals');
            $fuel = $f['probes']->findById($probe->id)->deuteriumStock;
            $context = $f['events']->retryableTaskContext($event);
            for ($i = 1; $i <= 6; $i++) {
                $due($event->id);
                if ($i === 5) { $db->prepare('UPDATE scheduled_events SET conflict_started_at=? WHERE id=?')->execute([gmdate('c', time() - 901), $event->id]); }
                // Output has already been inserted when completion clears the Manny task.
                $db->faultSql = 'UPDATE mannies SET';
                $test->assertEquals(1, $scheduler->processDueEvents()['deferred'], 'crafting conflict is deferred');
                $current = $f['events']->findById($event->id);
                $test->assertEquals(['pending', 0, $i], [$current->status, $current->attempts, $current->conflictCount], 'crafting claim rolls back, conflict persists');
                $test->assertEquals(0, craftingRetryOutputCount($db, $probe->id), 'partial crafted output rolls back');
                $test->assertEquals($stock, $f['storage']->resourceStock($probe, 'metals'), 'retry does not consume ingredients again');
                $test->assertEquals($fuel, $f['probes']->findById($probe->id)->deuteriumStock, 'retry does not consume printer fuel again');
                $after = $f['mannies']->findById($manny->id);
                $test->assertEquals([$task->taskEndsAt, $task->taskPayload, $task->reservedCargoSpace, $task->reservedStorageContainerId],
                    [$after->taskEndsAt, $after->taskPayload, $after->reservedCargoSpace, $after->reservedStorageContainerId], 'crafting order and reservation survive rollback unchanged');
                $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM probe_operations')->fetchColumn(), 'crafting releases admission during backoff');
            }
            $test->assertEquals(1, count(array_filter($records, fn($r) => $r['alert'] ?? false)), 'crafting alerts once after fifteen minutes and keeps retrying');
            $test->assertEquals('scheduler_crafting', $records[0]['metric'], 'crafting has distinct diagnostics');
            $test->assertEquals($task->taskPayload['craftingRunId'], $records[0]['crafting_run_id'], 'diagnostic identifies the fabrication');
            $test->assert(!str_contains(json_encode($records), 'secret'), 'crafting diagnostics exclude raw SQL messages');
            $test->assertEquals(null, $f['events']->deferTaskConflict($event, $context, ['sql_code' => 5], $policy), 'stale crafting report cannot overwrite a later retry');
            $due($event->id);
            $test->assertEquals(1, $scheduler->processDueEvents()['processed'], 'crafting recovers without operator action');
            $current = $f['events']->findById($event->id);
            $test->assertEquals(['done', 0, null, null], [$current->status, $current->conflictCount, $current->conflictStartedAt, $current->conflictAlertedAt], 'crafting success resets conflict series');
            $test->assertEquals(1, craftingRetryOutputCount($db, $probe->id), 'repeated crafting conflicts deliver exactly once');
            $test->assertEquals(null, $f['mannies']->findById($manny->id)->currentTask, 'crafting releases Manny');
            $test->assertEquals(6, end($records)['recovered_conflicts'], 'crafting recovery reports previous conflicts');
            $test->assertEquals(0, $scheduler->processDueEvents()['due'], 'completed fabrication is not replayed');
        }

        foreach (['admission' => 'INSERT INTO probe_operations', 'claim' => "SET status = 'running'", 'acknowledgement' => "SET status = 'done'", 'commit' => null] as $phase => $sql) {
            [$probe, $manny] = craftingRetryOrder($f, 'crafting-' . $phase);
            $event = miningRetryDue($db, $f, $manny);
            $db->faultSql = $sql;
            if ($phase === 'commit') {
                $db->beforeCommit = static function (MiningRetryFaultPdo $db) use ($probe): void {
                    if (craftingRetryOutputCount($db, $probe->id) === 0) { return; }
                    $db->beforeCommit = null; $db->faultSql = 'commit'; $db->inject('commit');
                };
            }
            $test->assertEquals(1, $scheduler->processDueEvents()['deferred'], 'crafting retries SQL conflict at ' . $phase);
            $test->assertEquals(0, craftingRetryOutputCount($db, $probe->id), 'crafting rollback includes output at ' . $phase);
            $due($event->id);
            $test->assertEquals(1, $scheduler->processDueEvents()['processed'], 'crafting recovers after ' . $phase);
            $test->assertEquals(1, craftingRetryOutputCount($db, $probe->id), 'crafting delivers once after ' . $phase);
        }

        [$probe, $manny] = miningAtomicActor($f, 'crafting-new-manny', new \VonNeumannGame\Sector\SectorCoordinates(837, 837, 0));
        $recipe = \VonNeumannGame\Domain\CraftingRecipeCatalog::find('manny');
        foreach ($recipe['ingredients'] as $ingredient) {
            for ($i = 0; $i < $ingredient['quantity']; $i++) {
                $f['items']->create($probe->id, $ingredient['type'], $ingredient['type'], 0.01, storageContainerId: $manny->storageContainerId);
            }
        }
        $manny = $f['mining']->startCrafting($probe, $manny->uid, 'manny');
        $event = miningRetryDue($db, $f, $manny);
        $db->beforeCommit = static function (MiningRetryFaultPdo $db) use ($probe): void {
            $stmt = $db->prepare('SELECT COUNT(*) FROM mannies WHERE probe_id=?'); $stmt->execute([$probe->id]);
            if ((int) $stmt->fetchColumn() !== 2) { return; }
            $db->beforeCommit = null; $db->faultSql = 'commit'; $db->inject('commit');
        };
        $test->assertEquals(1, $scheduler->processDueEvents()['deferred'], 'new Manny fabrication retries failed commit');
        $test->assertEquals(1, count($f['mannies']->findByProbeId($probe->id)), 'new Manny creation and placement roll back together');
        $due($event->id);
        $test->assertEquals(1, $scheduler->processDueEvents()['processed'], 'new Manny fabrication recovers');
        $test->assertEquals(2, count($f['mannies']->findByProbeId($probe->id)), 'retry creates exactly one new Manny');

        [$probe, $manny] = craftingRetryOrder($f, 'crafting-not-finished');
        $event = $f['events']->findById($manny->taskScheduledEventId);
        $db->prepare('UPDATE scheduled_events SET run_at=?,conflict_count=3,conflict_started_at=? WHERE id=?')->execute([gmdate('c', time()-1), gmdate('c', time()-10), $event->id]);
        $test->assertEquals(1, $scheduler->processDueEvents()['deferred'], 'unfinished crafting retains its business deadline');
        $test->assertEquals(0, $f['events']->findById($event->id)->conflictCount, 'normal crafting deferral clears SQL conflicts');
        $test->assertEquals($manny->taskEndsAt, $f['mannies']->findById($manny->id)->taskEndsAt, 'conflict recovery does not extend crafting duration');
        miningRetryDue($db, $f, $manny);
        $throwingObserver = craftingRetryScheduler($db, $f, static function (): void { throw new RuntimeException('monitoring unavailable'); });
        $test->assertEquals(1, $throwingObserver->processDueEvents()['processed'], 'monitoring failure cannot undo committed fabrication');
        $test->assertEquals('done', $f['events']->findById($event->id)->status, 'monitoring failure never requeues delivery');
        $test->assertEquals(1, craftingRetryOutputCount($db, $probe->id), 'monitoring failure preserves single delivery');

        [$probe, $manny] = craftingRetryOrder($f, 'crafting-permanent');
        $event = miningRetryDue($db, $f, $manny);
        $db->faultCode = 19; $db->faultSql = 'UPDATE mannies SET';
        $test->assertEquals(1, $scheduler->processDueEvents()['failed'], 'crafting integrity error is permanent');
        $test->assertEquals('failed', $f['events']->findById($event->id)->status, 'crafting integrity error is not silently retried');
        $test->assertEquals(0, craftingRetryOutputCount($db, $probe->id), 'permanent error rolls back output too');
        $db->faultCode = 5;

        [$probe, $manny] = craftingRetryOrder($f, 'crafting-recalled');
        $event = miningRetryDue($db, $f, $manny);
        $db->faultSql = 'UPDATE mannies SET'; $scheduler->processDueEvents();
        $before = $f['events']->findById($event->id); $context = $f['events']->retryableTaskContext($before);
        $f['mining']->recallManny($probe, $manny->uid);
        $test->assertEquals(null, $f['events']->deferTaskConflict($before, $context, ['sql_code' => 5], $policy), 'recall wins over late crafting conflict');
        $test->assert(!$f['events']->failRolledBackTask($before, $context, new RuntimeException('old error')), 'late failure cannot overwrite recall');
        $scheduler->processDueEvents();
        $test->assertEquals(0, craftingRetryOutputCount($db, $probe->id), 'recalled crafting never delivers');

        [$probe, $manny] = craftingRetryOrder($f, 'crafting-replaced');
        $event = miningRetryDue($db, $f, $manny); $context = $f['events']->retryableTaskContext($event);
        $db->prepare('UPDATE manny_tasks SET crafting_run_id=? WHERE id=?')->execute(['different-fabrication', $context['task_id']]);
        $test->assertEquals(null, $f['events']->deferTaskConflict($event, $context, ['sql_code' => 5], $policy), 'changed fabrication identity rejects old conflict');
        $test->assert(!$f['events']->failRolledBackTask($event, $context, new RuntimeException('old error')), 'changed fabrication identity rejects old failure');
        $db->prepare('UPDATE manny_tasks SET crafting_run_id=? WHERE id=?')->execute([$context['crafting_run_id'], $context['task_id']]);
        (new \VonNeumannGame\Repository\ProbeOperationRepository($db))->close($probe->id);
        $test->assertEquals(null, $f['events']->deferTaskConflict($event, $context, ['sql_code' => 5], $policy), 'closed probe prevents crafting retry');
        $test->assertEquals(0, $scheduler->processDueEvents()['due'], 'closed probe remains with destruction cleanup');
    } finally {
        if (is_dir($directory)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
            rmdir($directory);
        }
    }
})();
