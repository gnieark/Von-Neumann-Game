<?php
declare(strict_types=1);

require_once __DIR__ . '/CraftingRetryFixture.php';

(static function () use ($connect, $race, $assert, $directory, $mysqlConfig): void {
    if ($mysqlConfig === null) { return; }
    $wait = static function (string $path): void {
        $until = microtime(true) + 15;
        while (!is_file($path)) {
            if (microtime(true) > $until) { throw new RuntimeException('Timed out waiting for crafting contention fixture.'); }
            usleep(10000); clearstatcache(true, $path);
        }
    };
    foreach ([1205, 1213] as $code) {
        $db = $connect(); $f = miningAtomicFixture($db, $directory);
        $db->exec("UPDATE scheduled_events SET run_at='9999-12-31T00:00:00+00:00' WHERE status='pending'");
        [$probe, $manny] = craftingRetryOrder($f, 'crafting-real-' . $code, $code === 1213);
        $event = miningRetryDue($db, $f, $manny);
        $eventId = $event->id; $probeId = $probe->id; $containerId = $manny->reservedStorageContainerId;
        $stock = $f['storage']->resourceStock($probe, 'metals');
        $held = $directory . '/crafting-held-' . $code;
        $requested = $directory . '/crafting-requested-' . $code;
        $finished = $directory . '/crafting-finished-' . $code;
        $f = $db = null;
        $results = $race([
            static function (PDO $db) use ($code, $containerId, $eventId, $held, $requested, $finished, $wait): bool {
                $db->beginTransaction();
                try {
                    $db->prepare('SELECT id FROM storage_containers WHERE id=? FOR UPDATE')->execute([$containerId]);
                    if ($code === 1213) {
                        // Make the blocker heavier so InnoDB chooses crafting as the deadlock victim.
                        $insert = $db->prepare('INSERT INTO sector_effect_locks(sector_x,sector_y,sector_z) VALUES(?,?,?)');
                        for ($i = 0; $i < 100; $i++) { $insert->execute([981213, $i, 0]); }
                    }
                    file_put_contents($held, 'held');
                    if ($code === 1205) { $wait($finished); }
                    else {
                        $wait($requested); usleep(100000);
                        $db->prepare('UPDATE scheduled_events SET updated_at=updated_at WHERE id=?')->execute([$eventId]);
                    }
                    $db->commit();
                } finally { if ($db->inTransaction()) { $db->rollBack(); } }
                return true;
            },
            static function (PDO $db) use ($directory, $containerId, $code, $held, $requested, $finished, $wait): array {
                $wait($held);
                $db->exec('SET SESSION innodb_lock_wait_timeout=' . ($code === 1205 ? 1 : 10));
                $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [MiningRetrySignalStatement::class, [$containerId, $requested]]);
                $records = []; $f = miningAtomicFixture($db, $directory);
                $scheduler = craftingRetryScheduler($db, $f, static function ($row) use (&$records): void { $records[] = $row; });
                try { $stats = $scheduler->processDueEvents(1); }
                finally { file_put_contents($finished, 'done'); }
                return ['stats' => $stats, 'records' => $records];
            },
        ]);
        $assert($results[0]['ok'] && $results[1]['ok'], 'real crafting SQL conflict workers settle ' . $code . ': ' . json_encode($results));
        $result = $results[1]['result'];
        $assert($result['stats']['deferred'] === 1 && $result['records'][0]['sql_code'] === $code, 'crafting reports actual MariaDB ' . $code);
        $db = $connect(); $f = miningAtomicFixture($db, $directory);
        $event = $f['events']->findById($eventId);
        $assert($event->status === 'pending' && $event->conflictCount === 1 && $event->attempts === 0, 'crafting conflict survives claim rollback ' . $code);
        $assert(craftingRetryOutputCount($db, $probeId) === 0, 'SQL victim creates no crafting output ' . $code);
        $current = $f['mannies']->findById($manny->id);
        $assert($current->reservedStorageContainerId === $containerId && $current->taskPayload['craftingRunId'] === $manny->taskPayload['craftingRunId'], 'SQL victim preserves fabrication and reservation ' . $code);
        $stmt = $db->prepare('SELECT COUNT(*) FROM probe_operations WHERE probe_id=?'); $stmt->execute([$probeId]);
        $assert((int) $stmt->fetchColumn() === 0, 'crafting SQL victim releases admission ' . $code);
        $db->prepare('UPDATE scheduled_events SET run_at=? WHERE id=?')->execute([gmdate('c', time()-1), $eventId]);
        $stats = craftingRetryScheduler($db, $f, static function (): void {})->processDueEvents(1);
        $assert($stats['processed'] === 1 && $f['events']->findById($eventId)->conflictCount === 0, 'fresh admission completes crafting SQL retry ' . $code);
        $assert(craftingRetryOutputCount($db, $probeId) === 1 && $f['storage']->resourceStock($f['probes']->findById($probeId), 'metals') === $stock, 'crafting SQL retry delivers once without consuming ingredients again ' . $code);
        $stmt = $f = $db = null;
    }
})();
