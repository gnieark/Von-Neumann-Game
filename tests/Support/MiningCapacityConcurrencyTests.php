<?php
declare(strict_types=1);

require_once __DIR__ . '/MiningCapacityFixture.php';

(static function () use ($connect, $race, $assert, $directory, $mysqlConfig): void {
    foreach (['last-slot', 'reverse-batches'] as $index => $scenario) {
        $db = $connect(); $f = miningAtomicFixture($db, $directory);
        $sector = new \VonNeumannGame\Sector\SectorCoordinates(1800 + 2 * $index, 1800, 0);
        $amounts = array_replace(\VonNeumannGame\Domain\MineableResourceAmounts::zero(), ['metals' => 10.0]);
        $ids = ['capacity-' . $scenario . '-a', 'capacity-' . $scenario . '-b'];
        $objects = array_map(static fn($id) => new \VonNeumannGame\Sector\Asteroid($id, 'Rock', 'iron', ['metals'], 'small', 1, 1, resourceAmounts: $amounts), $ids);
        $f['files']->save(new \VonNeumannGame\Sector\SectorContent($sector, $objects));
        (new \VonNeumannGame\Database\StorageTransaction($db))->run(function () use ($f, $ids, $amounts): void {
            foreach ($ids as $id) { $f['stocks']->create('fixture-' . $id, $id, $amounts); }
        });
        $actors = [];
        for ($i = 0; $i < 2; $i++) {
            [$probe, $manny] = miningAtomicActor($f, 'capacity-' . $scenario . '-' . $i, $sector);
            $second = $f['mannies']->createForProbe($probe->id, 'Second');
            $f['storage']->repairProbeStorage($probe);
            $actors[] = [$probe->id, $manny->uid, $second->uid];
        }
        $f = $db = null;
        $operations = [];
        foreach ($actors as $i => [$probeId, $uid, $secondUid]) {
            $operations[] = static function (PDO $db) use ($directory, $scenario, $ids, $i, $probeId, $uid, $secondUid): bool {
                $f = miningAtomicFixture($db, $directory);
                $service = miningCapacityService($f, 1);
                $probe = $f['probes']->findById($probeId);
                if ($scenario === 'last-slot') {
                    $service->startMining($probe, $uid, $ids[0], 'metals', 0.01);
                } else {
                    $targets = $i === 0 ? $ids : array_reverse($ids);
                    $service->withPreparedBatch($probe, function () use ($service, $probe, $uid, $secondUid, $targets): void {
                        $service->startMining($probe, $uid, $targets[0], 'metals', 0.01);
                        usleep(50000);
                        $service->startMining($probe, $secondUid, $targets[1], 'metals', 0.01);
                    }, $targets, true);
                }
                return true;
            };
        }
        $results = $race($operations);
        $assert(count(array_filter($results, static fn($r) => $r['ok'])) === 1, 'exactly one capacity contender commits: ' . $scenario . ' ' . json_encode($results));
        $loser = array_values(array_filter($results, static fn($r) => !$r['ok']))[0];
        $assert(($loser['code'] ?? null) === 'mining_target_capacity_reached', 'capacity loser observes committed occupancy, without SQL conflict: ' . $scenario);
        $db = $connect(); $f = miningAtomicFixture($db, $directory);
        $assert($f['mannies']->countMiningAssignments($ids[0]) === 1, 'concurrent admission never exceeds the cap: ' . $scenario);
        if ($scenario === 'reverse-batches') { $assert($f['mannies']->countMiningAssignments($ids[1]) === 1, 'reverse batches commit both objects atomically'); }
        $f = $db = null;
    }

    // SQLite serializes writers globally. Verify row-level isolation with actual InnoDB connections.
    if ($mysqlConfig === null) { return; }
    $db = $connect(); $f = miningAtomicFixture($db, $directory);
    $sector = new \VonNeumannGame\Sector\SectorCoordinates(1810, 1810, 0);
    $ids = ['capacity-independent-a', 'capacity-independent-b'];
    $amounts = array_replace(\VonNeumannGame\Domain\MineableResourceAmounts::zero(), ['metals' => 1.0]);
    $f['files']->save(new \VonNeumannGame\Sector\SectorContent($sector, array_map(static fn($id) => new \VonNeumannGame\Sector\Asteroid($id, 'Rock', 'iron', ['metals'], 'small', 1, 1, resourceAmounts: $amounts), $ids)));
    (new \VonNeumannGame\Database\StorageTransaction($db))->run(function () use ($f, $ids, $amounts): void {
        foreach ($ids as $id) { $f['stocks']->create('fixture-' . $id, $id, $amounts); }
        $f['mannies']->lockMiningAdmissions($ids);
    });
    [$probe, $manny] = miningAtomicActor($f, 'capacity-scheduler-independent', $sector);
    $manny = miningCapacityService($f, 1)->startMining($probe, $manny->uid, $ids[0], 'metals', 0.01);
    $manny->taskEndsAt = gmdate('c', time() - 1);
    $manny->taskPayload[\VonNeumannGame\Domain\Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY] = $manny->taskEndsAt;
    $f['mannies']->save($manny);
    $eventId = $manny->taskScheduledEventId;
    [$other, $candidate] = miningAtomicActor($f, 'capacity-other-independent', $sector);
    $probeId = $other->id; $uid = $candidate->uid;
    $f = $db = null;
    $held = $directory . '/capacity-admission-held'; $done = $directory . '/capacity-independent-done';
    $waitFor = static function (string $path): void {
        $until = microtime(true) + 8;
        while (!is_file($path)) {
            if (microtime(true) >= $until) { throw new RuntimeException('Unrelated work blocked behind mining admission.'); }
            usleep(10000); clearstatcache(true, $path);
        }
    };
    $results = $race([
        static function (PDO $db) use ($directory, $ids, $held, $done, $waitFor): bool {
            $f = miningAtomicFixture($db, $directory);
            return (new \VonNeumannGame\Database\StorageTransaction($db))->run(function () use ($f, $ids, $held, $done, $waitFor): bool {
                $f['mannies']->lockMiningAdmissions([$ids[0]]);
                file_put_contents($held, 'held'); $waitFor($done);
                return true;
            });
        },
        static function (PDO $db) use ($directory, $ids, $held, $done, $waitFor, $probeId, $uid, $eventId): bool {
            $waitFor($held);
            $f = miningAtomicFixture($db, $directory);
            $service = miningCapacityService($f, 1);
            $service->startMining($f['probes']->findById($probeId), $uid, $ids[1], 'metals', 0.01);
            $event = $f['events']->claim($f['events']->findById($eventId));
            $service->processScheduledMiningTask($event);
            file_put_contents($done, 'done');
            return true;
        },
    ]);
    $assert($results[0]['ok'] && $results[1]['ok'], 'held admission permits another source to start and the same source to finish mining: ' . json_encode($results));
})();
