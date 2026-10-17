<?php

declare(strict_types=1);

use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\MineableResourceAmounts;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\Star;

require_once __DIR__ . '/MineableLifecycleFixture.php';
(static function () use ($connect, $race, $assert, $directory, $mysqlConfig): void {
    $zero = MineableResourceAmounts::zero();
    $amounts = static fn(float $value): array => array_replace($zero, ['metals' => $value]);
    foreach (['fusion', 'destruction', 'metadata', 'launch'] as $index => $operation) {
        $coords = new SectorCoordinates(900 + 4 * $index, 900, 0);
        $sourceId = 'lifecycle-race-' . $operation;
        $targetId = $sourceId . '-target';
        $db = $connect(); $f = mineableLifecycleFixture($db, $directory);
        $source = (new Asteroid($sourceId, 'Race rock', 'iron', ['metals'], 'small', 0.001, 0.01, resourceAmounts: $amounts(100)))->withDeuteriumEngine($sourceId);
        $target = $operation === 'fusion'
            ? new Asteroid($targetId, 'Target', 'iron', ['metals'], 'small', 0.001, 0.01, resourceAmounts: $amounts(100))
            : new Star($targetId, 'Star', 'G', 1, 5000, 1, 1);
        $f['files']->save(new SectorContent($coords, [$source, $target]));
        (new StorageTransaction($db))->run(function () use ($f, $sourceId, $targetId, $operation, $amounts): void {
            $f['stocks']->create('fixture-' . $sourceId, $sourceId, $amounts(100));
            if ($operation === 'fusion') { $f['stocks']->create('fixture-' . $targetId, $targetId, $amounts(100)); }
        });
        $trajectory = null; $playerId = $probeId = null;
        if (in_array($operation, ['fusion', 'destruction'], true)) {
            $trajectory = $f['trajectories']->create(['asteroidId' => $sourceId, 'asteroidSnapshot' => $source->toDescriptor(), 'mode' => 'system_impact', 'status' => 'coasting',
                'originSector' => $coords, 'targetObjectId' => $targetId, 'targetSpeedC' => 1.0e-10, 'asteroidMassEarth' => $source->getMass(), 'nextTransitionAt' => gmdate('c'), 'maximumSectorCrossings' => 10]);
        } elseif ($operation === 'launch') {
            $player = $f['players']->createPlayer('lifecycle-race-player', null);
            $probe = $f['probes']->createForPlayer($player->id, 'Race probe', $coords);
            $playerId = $player->id; $probeId = $probe->id;
        }
        $f = $db = null;
        $miner = static function (PDO $db) use ($directory, $coords, $sourceId, $amounts): float {
            return (new StorageTransaction($db, maxAttempts: 5))->run(function () use ($db, $directory, $coords, $sourceId, $amounts): float {
                $f = mineableLifecycleFixture($db, $directory);
                $current = $f['sectors']->lockMineableForMining($coords, $sourceId);
                $take = min(40.0, $current['amounts']['metals'] ?? 0.0);
                if ($take > 0) { $f['stocks']->debit($sourceId, $amounts($take)); }
                return $take;
            });
        };
        $transform = static function (PDO $db) use ($directory, $coords, $sourceId, $trajectory, $operation, $playerId, $probeId): bool {
            return (new StorageTransaction($db, maxAttempts: 5))->run(function () use ($db, $directory, $coords, $sourceId, $trajectory, $operation, $playerId, $probeId): bool {
                $f = mineableLifecycleFixture($db, $directory);
                if ($trajectory !== null) { $f['processor']->process($trajectory->id, 'coasting'); }
                elseif ($operation === 'metadata') {
                    $f['sectors']->changeMineablesAfterCommit($coords, [$sourceId], static function (SectorContent $sector) use ($sourceId): void {
                        $sector->replaceObject($sector->findObjectById($sourceId)->sculptedInTheShapeOfADuck());
                    });
                } else {
                    $player = $f['players']->findById($playerId); $probe = $f['probes']->findById($probeId);
                    $f['launches']->create($player, $probe, $sourceId, ['mode' => 'sector_transfer', 'target' => $coords->add(1, 1, 0)->subtract($player->homeSector)]);
                }
                return true;
            });
        };
        $results = $race([$miner, $transform]);
        $assert($results[0]['ok'] && $results[1]['ok'], 'mining and ' . $operation . ' settle on independent connections: ' . json_encode($results));
        $taken = (float) $results[0]['result'];
        $db = $connect(); $f = mineableLifecycleFixture($db, $directory);
        if ($operation === 'fusion') {
            $mergedId = 'ast_merge_' . substr(hash('sha256', $trajectory->uid), 0, 20);
            $assert($taken + $f['stocks']->find($mergedId)['amounts']['metals'] === 200.0, 'concurrent fusion preserves source+target stock minus the committed debit');
            $assert($f['stocks']->find($sourceId)['version'] === null && $f['stocks']->find($targetId)['version'] === null, 'fusion leaves neither old stock exploitable');
        } elseif ($operation === 'destruction') {
            $assert(in_array($taken, [0.0, 40.0], true) && $f['stocks']->find($sourceId)['version'] === null, 'destruction serializes the last debit and permanently removes the stock');
        } else {
            $assert($taken + $f['stocks']->find($sourceId)['amounts']['metals'] === 100.0, $operation . ' preserves the current stock without restoring a stale snapshot');
        }
        $f = $db = null;
    }

    if ($mysqlConfig === null) { return; }
    // Force a stale InnoDB repeatable-read snapshot before the source lock is acquired.
    $coords = new SectorCoordinates(940, 900, 0); $sourceId = 'lifecycle-stale-source';
    $db = $connect(); $f = mineableLifecycleFixture($db, $directory);
    $rock = new Asteroid($sourceId, 'Stale snapshot', 'iron', ['metals'], 'small', 0.001, 0.01, resourceAmounts: $amounts(100));
    $f['files']->save(new SectorContent($coords, [$rock]));
    (new StorageTransaction($db))->run(fn() => $f['stocks']->create('stale-source', $sourceId, $amounts(100)));
    (new \VonNeumannGame\Repository\DetachedStorageContainerRepository($db))->save($coords, new \VonNeumannGame\Sector\SectorDetachedContainer(
        'lifecycle-stale-container', 'Cargo', \VonNeumannGame\Sector\SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID,
        1, 1, 1, $sourceId, 1000, 'ECE', gmdate('c'),
        ['sourceContainerId' => 'container-lifecycle-stale-backing', 'container' => ['capacity' => 1000], 'resources' => ['metals' => 50]],
    ));
    $f = $db = null;
    $seen = $directory . '/lifecycle-stale-read'; $debited = $directory . '/lifecycle-stale-debit';
    $awaitFile = static function (string $path): void {
        $deadline = microtime(true) + 5;
        do { clearstatcache(true, $path); if (is_file($path)) { return; } usleep(10000); } while (microtime(true) < $deadline);
        throw new RuntimeException('Lifecycle concurrency barrier expired.');
    };
    $results = $race([
        static function (PDO $db) use ($directory, $coords, $sourceId, $seen, $debited, $awaitFile): array {
            $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            return (new StorageTransaction($db))->run(function () use ($db, $directory, $coords, $sourceId, $seen, $debited, $awaitFile): array {
                $f = mineableLifecycleFixture($db, $directory);
                $f['stocks']->find($sourceId);
                file_put_contents($seen, 'snapshot'); $awaitFile($debited);
                return $f['sectors']->changeMineablesAfterCommit($coords, [$sourceId], static function (SectorContent $sector) use ($f, $sourceId): array {
                    $stock = $sector->findObjectById($sourceId)->getResourceAmounts()['metals'];
                    $cargo = $sector->containersForObject($sourceId)[0]->getPayload()['resources']['metals'];
                    $newId = $f['sectors']->replaceMineableIdentity('stale-motorization', $sourceId, 'lifecycle-stale-renamed');
                    $sector->replaceObjectAndReferences($sourceId, $sector->findObjectById($sourceId)->withDeuteriumEngine($newId));
                    return ['stock' => $stock, 'cargo' => $cargo, 'id' => $newId];
                });
            });
        },
        static function (PDO $db) use ($directory, $sourceId, $seen, $debited, $awaitFile, $amounts): bool {
            $awaitFile($seen); $f = mineableLifecycleFixture($db, $directory);
            (new StorageTransaction($db))->run(function () use ($db, $f, $sourceId, $amounts): void {
                $f['stocks']->debit($sourceId, $amounts(80));
                $db->exec("UPDATE detached_storage_containers SET storage_version=storage_version+1 WHERE object_id='lifecycle-stale-container'");
                $db->exec("UPDATE detached_storage_container_resources SET amount=amount+80 WHERE container_object_id='lifecycle-stale-container' AND resource_type='metals'");
            });
            file_put_contents($debited, 'committed'); return true;
        },
    ]);
    $assert($results[0]['ok'] && $results[1]['ok'] && (float) $results[0]['result']['stock'] === 20.0, 'MariaDB lifecycle hydration uses the current locking read, not its earlier repeatable-read snapshot: ' . json_encode($results));
    $db = $connect();
    $container = (new \VonNeumannGame\Repository\DetachedStorageContainerRepository($db))->findByObjectId('lifecycle-stale-container');
    $assert((float) $results[0]['result']['cargo'] === 130.0 && (float) $container->getPayload()['resources']['metals'] === 130.0, 'motorization preserves attached cargo committed after its initial snapshot');
    $assert($container->getTargetObjectId() === $results[0]['result']['id'], 'motorization commits the current attachment with the new source identity');
})();
