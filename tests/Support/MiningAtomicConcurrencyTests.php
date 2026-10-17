<?php

declare(strict_types=1);

use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\MineableResourceAmounts;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorDetachedContainer;

require_once __DIR__ . '/MiningAtomicFixture.php';
(static function () use ($connect, $race, $assert, $directory, $mysqlConfig): void {
    foreach (['asteroid', 'wreck', 'shared-destination', 'independent'] as $index => $scenario) {
        if ($scenario === 'independent' && $mysqlConfig === null) { continue; }
        $db = $connect(); $f = miningAtomicFixture($db, $directory);
        $sector = new SectorCoordinates(980 + 2 * $index, 980, 0);
        $zero = MineableResourceAmounts::zero();
        $amounts = array_replace($zero, ['metals' => 0.1]);
        $sourceIds = ['atomic-race-' . $scenario . '-a', 'atomic-race-' . $scenario . '-b'];
        $objects = [];
        foreach ($sourceIds as $id) {
            $objects[] = $scenario === 'wreck'
                ? new DormantConstruct($id, 'Wreck', subtype: DormantConstruct::SUBTYPE_OTHERS_MOTHERSHIP_WRECK, resourceAmounts: $amounts)
                : new Asteroid($id, 'Rock', 'iron', ['metals'], 'small', 1, 1, resourceAmounts: $amounts);
        }
        $f['files']->save(new SectorContent($sector, $objects));
        (new StorageTransaction($db))->run(function () use ($f, $sourceIds, $amounts): void {
            foreach ($sourceIds as $id) { $f['stocks']->create('fixture-' . $id, $id, $amounts); }
        });
        $actors = []; $destinations = [];
        for ($i = 0; $i < 2; ++$i) {
            [$probe, $manny] = miningAtomicActor($f, 'atomic-race-' . $scenario . '-' . $i, $sector);
            $destinations[$i] = 'atomic-destination-' . $scenario . '-' . ($scenario === 'shared-destination' ? 0 : $i);
            if ($i === 0 || $scenario !== 'shared-destination') {
                $f['detached']->save($sector, new SectorDetachedContainer($destinations[$i], 'Destination', 'drifting', $probe->id, $probe->playerId, null, null, 0.1, 'ECE', gmdate('c'), [
                    'sourceContainerId' => 'container-' . $destinations[$i],
                    'containerItem' => ['uid' => $destinations[$i], 'type' => 'additional_container', 'name' => 'Destination', 'containerSpace' => 0.0, 'metadata' => ['capacityBonus' => 0.1]],
                ]));
            }
            $id = in_array($scenario, ['asteroid', 'wreck'], true) ? $sourceIds[0] : $sourceIds[$i];
            $manny = $f['mining']->startMining($probe, $manny->uid, $id, 'metals', 0.06, $destinations[$i]);
            $manny->taskEndsAt = gmdate('c', time() - 1);
            $manny->taskPayload[\VonNeumannGame\Domain\Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY] = $manny->taskEndsAt;
            $f['mannies']->save($manny);
            $probe->currentSector = $sector->add(10, 0, 0); $f['probes']->save($probe);
            $actors[] = [$manny->id, $manny->taskScheduledEventId];
        }
        $hash = hash_file('sha256', $f['files']->getPath($sector));
        $f = $db = null;
        $held = $directory . '/mining-atomic-held'; $done = $directory . '/mining-atomic-done';
        $waitFor = static function (string $path): void {
            $deadline = microtime(true) + 8;
            while (!is_file($path)) {
                if (microtime(true) > $deadline) { throw new RuntimeException('Independent mining blocked behind an unrelated transaction.'); }
                usleep(10000); clearstatcache(true, $path);
            }
        };
        $operations = [];
        foreach ($actors as $i => [$mannyId, $eventId]) {
            $operations[] = static function (PDO $db) use ($directory, $eventId, $scenario, $i, $held, $done, $waitFor): bool {
                $f = miningAtomicFixture($db, $directory);
                $event = $f['events']->claim($f['events']->findById($eventId));
                if ($scenario === 'independent' && $i === 1) { $waitFor($held); }
                $result = (new StorageTransaction($db, maxAttempts: 1, readCommitted: true))->run(function () use ($f, $event, $scenario, $i, $held, $done, $waitFor): bool {
                    $result = $f['mining']->processScheduledMiningTask($event);
                    if ($scenario === 'independent' && $i === 0) {
                        file_put_contents($held, 'held'); $waitFor($done);
                    }
                    return $result;
                });
                if ($scenario === 'independent' && $i === 1) { file_put_contents($done, 'done'); }
                return $result;
            };
        }
        $results = $race($operations);
        $assert($results[0]['ok'] && $results[1]['ok'], 'full mining transactions settle: ' . $scenario . ' ' . json_encode($results));
        $db = $connect(); $f = miningAtomicFixture($db, $directory);
        $delivered = 0.0;
        foreach (array_unique($destinations) as $id) { $delivered += $f['detached']->findByObjectId($id)->getPayload()['resources']['metals'] ?? 0; }
        $remaining = array_sum(array_column(array_column($f['stocks']->findMany($sourceIds), 'amounts'), 'metals'));
        $assert(round($remaining + $delivered, 4) === 0.2, 'concurrent delivery conserves source plus destination: ' . $scenario);
        $assert(round($delivered, 4) === ($scenario === 'independent' ? 0.12 : 0.1), 'source/destination capacity limits both real workers: ' . $scenario);
        foreach ($actors as [$mannyId, $eventId]) {
            $assert($f['mannies']->findById($mannyId)->isForgotten && $f['events']->findById($eventId)->status === 'done', 'remote miner and event commit together: ' . $scenario);
        }
        $assert($hash === hash_file('sha256', $f['files']->getPath($sector)), 'concurrent harvesting leaves sector JSON untouched: ' . $scenario);
        $query = $db->prepare('SELECT COUNT(*) FROM sector_effect_locks WHERE sector_x=? AND sector_y=? AND sector_z=?');
        $query->execute([$sector->getX(), $sector->getY(), $sector->getZ()]);
        $assert((int) $query->fetchColumn() === 0, 'no sector lock row used by mining: ' . $scenario);
        $query->closeCursor(); $query = null;
        $f = $db = null;
    }
    // Same-probe launches must observe one another's outstanding commitment.
    $db = $connect(); $f = miningAtomicFixture($db, $directory);
    $sector = new SectorCoordinates(990, 990, 0);
    $sourceId = 'atomic-order-source';
    $amounts = array_replace(MineableResourceAmounts::zero(), ['metals' => 0.1]);
    $f['files']->save(new SectorContent($sector, [new Asteroid($sourceId, 'Rock', 'iron', ['metals'], 'small', 1, 1, resourceAmounts: $amounts)]));
    (new StorageTransaction($db))->run(fn() => $f['stocks']->create('fixture-' . $sourceId, $sourceId, $amounts));
    [$probe, $manny] = miningAtomicActor($f, 'atomic-orders', $sector);
    $second = $f['mannies']->createForProbe($probe->id, 'Second miner');
    $f['storage']->repairProbeStorage($probe);
    $probeId = $probe->id;
    $uids = [$manny->uid, $second->uid];
    $f = $db = null;
    $operations = [];
    foreach ($uids as $uid) {
        $operations[] = static function (PDO $db) use ($directory, $probeId, $sourceId, $uid): string {
            $f = miningAtomicFixture($db, $directory);
            return $f['mining']->startMining($f['probes']->findById($probeId), $uid, $sourceId, 'metals', 0.06)->uid;
        };
    }
    $results = $race($operations);
    $assert(count(array_filter($results, static fn(array $r): bool => $r['ok'])) === 1, 'same-probe concurrent launches admit only the available commitment: ' . json_encode($results));
    $refused = array_values(array_filter($results, static fn(array $r): bool => !$r['ok']))[0];
    $assert($refused['code'] === 'insufficient_target_resources', 'competing launch is refused for resources, not a SQL error');
    $db = $connect(); $f = miningAtomicFixture($db, $directory);
    $assert($f['stocks']->find($sourceId)['amounts']['metals'] === 0.1, 'launch commitments never debit or globally reserve source resources');
    $active = array_values(array_filter($f['mannies']->findByProbeId($probeId), static fn($m): bool => $m->currentTask === 'mining'))[0];
    $active->taskEndsAt = gmdate('c', time() - 1); $f['mannies']->save($active);
    $eventId = $active->taskScheduledEventId; $uid = $active->uid;
    $f = $db = null;
    $results = $race([
        static function (PDO $db) use ($directory, $eventId): bool {
            $f = miningAtomicFixture($db, $directory);
            $event = $f['events']->claim($f['events']->findById($eventId));
            if ($event !== null) { $f['mining']->processScheduledMiningTask($event); }
            return true;
        },
        static function (PDO $db) use ($directory, $probeId, $uid): bool {
            $f = miningAtomicFixture($db, $directory);
            $f['mining']->recallManny($f['probes']->findById($probeId), $uid);
            return true;
        },
    ]);
    $assert($results[0]['ok'] && $results[1]['ok'], 'recall and harvesting serialize the current Manny task: ' . json_encode($results));
    $db = $connect(); $f = miningAtomicFixture($db, $directory);
    $remaining = $f['stocks']->find($sourceId)['amounts']['metals'];
    $delivered = $f['storage']->resourceStock($f['probes']->findById($probeId), 'metals');
    $assert(round($remaining + $delivered, 4) === 0.1 && in_array($delivered, [0.0, 0.06], true), 'recall commits either before or after one complete harvest, never a partial delivery');

})();
