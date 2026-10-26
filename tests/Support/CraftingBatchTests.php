<?php
declare(strict_types=1);

require_once __DIR__ . '/CraftingBatchFixture.php';
require_once __DIR__ . '/StorageTestPdo.php';
require_once __DIR__ . '/MiningRetryFixture.php';

(static function () use ($test): void {
    $directory = sys_get_temp_dir() . '/vng-crafting-batch-' . bin2hex(random_bytes(6));
    $run = static function (array $f, array $recipes, bool $optimized = true): array {
        $actors = array_slice($f['actors'], 0, count($recipes));
        return $f['mining']->withPreparedBatch($f['probe'], static function ($probe) use ($f, $actors, $recipes): array {
            $result = [];
            foreach ($actors as $i => $actor) { $result[] = $f['mining']->startCrafting($probe, $actor->uid, $recipes[$i]); }
            return $result;
        }, [], false, $optimized ? array_column($actors, 'uid') : []);
    };
    try {
        // Compare the normal sequential path with shared inventory, including component exhaustion,
        // reserved resources/items, foreign components, placement rules and an existing incoming move.
        $outcomes = [];
        foreach ([false, true] as $optimized) {
            $db = new MiningRetryFaultPdo();
            (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
            $f = craftingBatchFixture($db, $directory, 'equivalence', 8, 5);
            $containers = new \VonNeumannGame\Repository\StorageContainerRepository($db);
            $extra = $containers->createDetachedRestoredContainer($f['probe']->id, 'batch-extra', 'Extra', 1, 1, ['electric_motor'], [], []);
            $db->exec("UPDATE probe_items SET reserved_transfer_id='test-reservation' WHERE id=(SELECT MIN(id) FROM probe_items WHERE type='steel_bar')");
            $db->exec("UPDATE probe_items SET fabricator='others' WHERE id=(SELECT MAX(id) FROM probe_items WHERE type='steel_bar')");
            $db->prepare("UPDATE storage_container_resources SET reserved_amount=99.99 WHERE container_id=? AND resource_type='metals'")->execute([$f['core']->id]);
            $containers->setResourceAmount($extra->id, 'metals', 0.5);
            $moving = $f['mannies']->findById($f['actors'][7]->id);
            $moving->currentTask = \VonNeumannGame\Domain\Manny::TASK_MOVING_STORAGE;
            $moving->taskEndsAt = gmdate('c', time() + 600);
            $moving->taskPayload = ['kind' => 'resource', 'fromContainerId' => $f['core']->uid, 'toContainerId' => $extra->uid, 'amount' => 0.49];
            $f['mannies']->save($moving);
            $orders = $run($f, ['electric_motor', 'electric_motor', 'battery_pack', 'battery_pack', 'battery_pack'], $optimized);
            $normalized = [];
            foreach ($orders as $manny) {
                $f['mannies']->loadConsumedItems($manny);
                $payload = $manny->taskPayload;
                $normalized[] = [$payload['durationSeconds'], $payload['resourceCosts'], array_column($payload['consumedItems'], 'type'),
                    $manny->reservedCargoSpace, $manny->reservedStorageContainerId];
            }
            $test->assert($normalized[0][0] < $normalized[1][0], 'later batch recipe rebuilds exhausted components');
            $test->assertEquals(5, count(array_unique(array_map(fn($m) => $m->taskPayload['craftingRunId'], $orders))), 'each craft retains a distinct run identity');
            $test->assertEquals(5, count(array_unique(array_column($orders, 'taskScheduledEventId'))), 'each craft retains its scheduler event');
            $test->assertEquals(2, count($f['items']->findByProbeId($f['probe']->id)), 'reserved and Others components remain untouched');
            $outcomes[] = [$normalized, $containers->resourceAmountsByContainer($f['probe']->id),
                $f['probes']->findById($f['probe']->id)->deuteriumStock, $f['storage']->freeCargoCapacity($f['probe'])];
        }
        $test->assertEquals($outcomes[0], $outcomes[1], 'shared inventory preserves sequential costs, fuel, duration, placement and capacity');

        // Consuming an item also removes its internal-move destination reservation. Conversely,
        // the first craft's output reservation must prevent a second output from taking that space.
        foreach ([false, true] as $optimized) {
            $db = new MiningRetryFaultPdo();
            (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
            $f = craftingBatchFixture($db, $directory, 'output-capacity', 3, 2);
            $containers = new \VonNeumannGame\Repository\StorageContainerRepository($db);
            $extra = $containers->createDetachedRestoredContainer($f['probe']->id, 'output-only', 'Output', 1, 0.01, ['electric_motor'], [], []);
            $containers->updateRules($f['core'], [], [], ['electric_motor']);
            $moving = $f['mannies']->findById($f['actors'][2]->id);
            $moving->currentTask = \VonNeumannGame\Domain\Manny::TASK_MOVING_STORAGE;
            $moving->taskEndsAt = gmdate('c', time() + 600);
            $moving->taskPayload = ['kind' => 'item', 'toContainerId' => $extra->uid, 'itemIds' => [$f['items']->findByProbeId($f['probe']->id)[0]->uid]];
            $f['mannies']->save($moving);
            $test->assertThrows(fn() => $run($f, ['electric_motor', 'electric_motor'], $optimized), \VonNeumannGame\Service\MannyActionException::class, 'second output cannot reuse the first craft reservation');
            $test->assertEquals(null, $f['mannies']->findById($f['actors'][0]->id)->currentTask, 'capacity rejection rolls back the first output reservation');
            $result = $run($f, ['electric_motor'], $optimized);
            $test->assertEquals($extra->id, $result[0]->reservedStorageContainerId, 'consumed components release their incoming move capacity before output placement');
        }

        foreach (['resource', 'recipe', 'capacity', 'sql', 'commit'] as $failure) {
            $db = new MiningRetryFaultPdo();
            (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
            $f = craftingBatchFixture($db, $directory, 'rollback-' . $failure, 3, 2);
            $containers = new \VonNeumannGame\Repository\StorageContainerRepository($db);
            $recipes = ['electric_motor', 'electric_motor'];
            if ($failure === 'resource') { $containers->setResourceAmount($f['core']->id, 'metals', 0.05); }
            if ($failure === 'recipe') { $recipes[1] = 'no-such-recipe'; }
            if ($failure === 'capacity') {
                // Global free space must not bypass strict per-container placement rules.
                $recipes = ['battery_pack', 'steel_plate'];
                $containers->updateRules($f['core'], [], [], ['steel_plate']);
            }
            if ($failure === 'sql') { $db->faultSql = 'UPDATE mannies SET'; }
            if ($failure === 'commit') {
                $db->beforeCommit = static function ($db): void { $db->beforeCommit = null; throw new PDOException('Injected commit failure'); };
            }
            $stock = $containers->resourceAmountsByContainer($f['probe']->id);
            $fuel = $f['probes']->findById($f['probe']->id)->deuteriumStock;
            $test->assertThrows(fn() => $run($f, $recipes), Throwable::class, 'craft batch aborts on ' . $failure);
            $test->assertEquals($stock, $containers->resourceAmountsByContainer($f['probe']->id), 'batch rollback restores resources after ' . $failure);
            $test->assertEquals($fuel, $f['probes']->findById($f['probe']->id)->deuteriumStock, 'batch rollback restores fuel after ' . $failure);
            $test->assertEquals(2, count($f['items']->findByProbeId($f['probe']->id)), 'batch rollback restores consumed components after ' . $failure);
            $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM scheduled_events')->fetchColumn(), 'batch rollback removes scheduler events after ' . $failure);
            $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM mannies WHERE current_task IS NOT NULL OR reserved_cargo_space>0')->fetchColumn(), 'batch rollback removes tasks and reservations after ' . $failure);
            $containers->setResourceAmount($f['core']->id, 'metals', 100);
            $containers->updateRules($f['core'], [], [], []);
            $test->assertEquals(2, count($run($f, ['electric_motor', 'electric_motor'])), 'fresh batch succeeds after discarded context: ' . $failure);
        }

        // Count database work, rather than depending on machine timing.
        $metrics = [];
        foreach ([false, true] as $optimized) {
            $raw = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $db = new StorageTestPdo($raw);
            (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
            $f = craftingBatchFixture($db, $directory, 'performance', 2000, 1000);
            $db->metrics->reset();
            $run($f, array_fill(0, 100, 'steel_plate'), $optimized);
            $metrics[] = $db->metrics->snapshot();
        }
        $test->assert($metrics[1]['rows'] < $metrics[0]['rows'] / 50, '100 crafts avoid repeated full-inventory SQL reads');
        $test->assert($metrics[1]['queries'] < $metrics[0]['queries'] / 2, '100 crafts reduce SQL round trips while preserving individual writes');
    } finally {
        if (is_dir($directory)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
            rmdir($directory);
        }
    }
})();
