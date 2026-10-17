<?php

declare(strict_types=1);

use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Database\Migration\AsteroidSnapshotDescriptorMigration;
use VonNeumannGame\Domain\AsteroidTrajectory;
use VonNeumannGame\Domain\MineableResourceAmounts;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\BlackHole;
use VonNeumannGame\Sector\DeterministicRandom;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\Star;
use VonNeumannGame\Service\AsteroidTrajectory\ImpactDamageResolver;

require_once __DIR__ . '/MineableLifecycleFixture.php';
require_once __DIR__ . '/OthersTestFixture.php';
(static function () use ($test): void {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    (new SchemaInitializer('sqlite'))->initialize($db);
    $directory = sys_get_temp_dir() . '/vng-lifecycle-' . bin2hex(random_bytes(6));
    extract(mineableLifecycleFixture($db, $directory));
    $tx = new StorageTransaction($db);
    $zero = MineableResourceAmounts::zero();
    $amounts = static fn(float $metals): array => array_replace($zero, ['metals' => $metals]);
    $rock = static fn(string $id, float $metals = 10.1234): Asteroid => new Asteroid($id, 'Original name', 'iron', ['metals'], 'small', 0.001, 0.01, resourceAmounts: $amounts($metals));
    $seed = static function (SectorCoordinates $coords, array $objects) use ($files, $tx, $stocks): void {
        $files->save(new SectorContent($coords, $objects));
        $tx->run(function () use ($objects, $stocks): void {
            foreach ($objects as $object) {
                if ($object instanceof Asteroid) { $stocks->create('fixture-' . $object->getId(), $object->getId(), $object->getResourceAmounts()); }
            }
        });
    };
    $impact = static function (SectorCoordinates $coords, Asteroid $source, string $targetId, float $speed) use ($trajectories): AsteroidTrajectory {
        return $trajectories->create(['asteroidId' => $source->getId(), 'asteroidSnapshot' => $source->toDescriptor(), 'mode' => 'system_impact', 'status' => 'coasting',
            'originSector' => $coords, 'targetObjectId' => $targetId, 'targetSpeedC' => $speed, 'asteroidMassEarth' => $source->getMass(), 'nextTransitionAt' => gmdate('c'), 'maximumSectorCrossings' => 10]);
    };
    $flush = static function (SectorCoordinates $coords) use ($effects, $outbox): void {
        foreach ($effects->pendingInSector($coords) as $effect) { $outbox->apply((int) $effect['id']); }
    };
    try {
        $coords = new SectorCoordinates(800, 800, 0);
        $a = $rock('fusion-a'); $b = $rock('fusion-b', 20.9876);
        $seed($coords, [$a, $b]);
        $trajectory = $impact($coords, $a, $b->getId(), 1.0e-10);
        $tx->run(fn() => $stocks->debit($a->getId(), $amounts(3)));
        $hash = hash_file('sha256', $files->getPath($coords));
        $db->beginTransaction();
        $processor->process($trajectory->id, 'coasting');
        $test->assertEquals($hash, hash_file('sha256', $files->getPath($coords)), 'fusion writes no file before SQL commit');
        $db->rollBack();
        $test->assertEquals('coasting', $trajectories->findById($trajectory->id)->status, 'rollback preserves the trajectory phase');
        $test->assertEquals(7.1234, $stocks->find('fusion-a')['amounts']['metals'], 'rollback preserves the already-mined source stock');
        $test->assertCount(0, iterator_to_array($effects->pendingInSector($coords)), 'rollback leaves no transformation intention');
        $db->beginTransaction();
        $processor->process($trajectory->id, 'coasting');
        $db->commit(); // Simulate death before callbacks/publication.
        $pending = iterator_to_array($effects->pendingInSector($coords));
        $test->assertCount(1, $pending, 'fusion commits one durable exact descriptor');
        $test->assert(!str_contains($pending[0]['payload_json'], 'resourceAmounts'), 'transformation intentions contain no stock snapshots');
        $resultId = 'ast_merge_' . substr(hash('sha256', $trajectory->uid), 0, 20);
        $test->assertEquals(28.111, $stocks->find($resultId)['amounts']['metals'], 'fusion sums current SQL stock, not launch snapshots');
        $test->assertEquals(null, $stocks->find('fusion-a')['version'], 'fusion consumes the first source stock');
        $test->assertEquals(null, $stocks->find('fusion-b')['version'], 'fusion consumes the second source stock');
        $test->assertEquals($zero, $sectors->getOrCreateSector($coords)->findObjectById('fusion-a')->getResourceAmounts(), 'unpublished fusion leaves old descriptor with zero available resources');
        $test->assertEquals(null, $sectors->getOrCreateSector($coords)->findObjectById($resultId), 'unpublished result is not announced as present');
        $flush($coords);
        $tx->run(fn() => $stocks->debit($resultId, $amounts(1)));
        $processor->process($trajectory->id, 'coasting');
        $db->prepare("UPDATE sector_effects SET status='pending' WHERE id=?")->execute([$pending[0]['id']]);
        $flush($coords);
        $test->assertEquals(27.111, $stocks->find($resultId)['amounts']['metals'], 'business and publication replays do not refill the merged stock');

        // Missing row, allocation collision and deterministic four-decimal loss/remainder.
        $coords2 = new SectorCoordinates(802, 800, 0);
        $a = $rock('fragments-a', 0.0007); $b = $rock('fragments-b', 900);
        $seed($coords2, [$a, $b]);
        $tx->run(fn() => $stocks->delete('fragments-b'));
        $trajectory = $impact($coords2, $a, $b->getId(), 0.1);
        $resolution = (new ImpactDamageResolver())->resolve($a, $b, 0.1, new DeterministicRandom('asteroid-impact:' . $trajectory->uid));
        $collision = 'ast_frag_' . substr(hash('sha256', $trajectory->uid . ':0'), 0, 20);
        $tx->run(fn() => $stocks->reserveHistoricalIdentity($collision));
        $processor->process($trajectory->id, 'coasting');
        $fragments = $sectors->getOrCreateSector($coords2);
        $test->assertCount($resolution['fragmentCount'], $fragments->mineableIds(), 'fragmentation publishes the exact result count');
        $test->assert(!in_array($collision, $fragments->mineableIds(), true), 'fragment publication uses collision-resolved identities');
        $total = array_sum(array_map(fn(string $id): float => $stocks->find($id)['amounts']['metals'], $fragments->mineableIds()));
        $test->assertEquals(round(0.0007 * (1 - $resolution['unrecoverableMassFraction']), 4), round($total, 4), 'fragment totals preserve recoverable SQL resources with deterministic rounding residue');
        $test->assertEquals(null, $stocks->find('fragments-a')['version'], 'fragmentation consumes source only once');
        $test->assertEquals(null, $stocks->find('fragments-b')['version'], 'absent source contributes zero without being restored');
        $processor->process($trajectory->id, 'coasting');
        $test->assertEquals($fragments->mineableIds(), $sectors->getOrCreateSector($coords2)->mineableIds(), 'fragment replay does not allocate more identities');

        $planetCoords = new SectorCoordinates(804, 800, 0);
        $a = $rock('planet-impactor');
        $planet = new Planet('fragmented-planet', 'Planet', 'rocky', 0.1, 0.1, false, 0, ['metals']);
        $seed($planetCoords, [$a, $planet]);
        $trajectory = $impact($planetCoords, $a, $planet->getId(), 0.1);
        $processor->process($trajectory->id, 'coasting');
        $planetFragments = $sectors->getOrCreateSector($planetCoords)->mineableIds();
        $test->assert(count($planetFragments) > 0, 'planet disruption explicitly creates asteroid fragments');
        $test->assert(count(array_filter($stocks->findMany($planetFragments), static fn(array $s): bool => $s['version'] === 1 && array_sum($s['amounts']) > 0)) === count($planetFragments), 'new planet fragments initialize their SQL stocks exactly once');
        $test->assertEquals(null, $stocks->find('planet-impactor')['version'], 'planet impact consumes the impactor stock');

        // Launch, delayed origin publication, capture into each supported body and absorption.
        $player = $players->createPlayer('lifecycle-player', null);
        $probe = $probes->createForPlayer($player->id, 'Lifecycle probe');
        foreach (['star', 'planet', 'hole', 'empty'] as $index => $kind) {
            $origin = new SectorCoordinates(820 + 4 * $index, 800, 0);
            $destination = $origin->add(1, 1, 0);
            $a = $rock('transit-' . $kind)->withDeuteriumEngine('transit-' . $kind);
            $seed($origin, [$a]);
            $body = match ($kind) {
                'star' => new Star('capture-star', 'Star', 'G', 1, 5000, 1, 1),
                'planet' => new Planet('capture-planet', 'Planet', 'rocky', 4, 1, false, 0, ['metals']),
                'hole' => new BlackHole('capture-hole', 'Hole', 10, 0.1, false, 1),
                default => null,
            };
            $seed($destination, $body === null ? [] : [$body]);
            $probe->currentSector = $origin; $probes->save($probe);
            $relative = $destination->subtract($player->homeSector);
            $db->beginTransaction();
            $trajectory = $launches->create($player, $probe, $a->getId(), ['mode' => 'sector_transfer', 'target' => $relative]);
            $db->commit();
            $test->assert($files->load($origin)->findObjectById($a->getId()) instanceof Asteroid, 'delayed departure retains the old descriptor until publication: ' . $kind);
            $test->assertEquals(null, $tx->run(fn() => $sectors->lockMineableForMining($origin, $a->getId())), 'transit authority forbids mining a stale origin descriptor: ' . $kind);
            $test->assert(!array_key_exists('resourceAmounts', $trajectory->asteroidSnapshot), 'operational trajectory snapshot is a descriptor: ' . $kind);
            $test->assertEquals(10.1234, $stocks->find($a->getId())['amounts']['metals'], 'departure preserves the source stock: ' . $kind);
            if ($kind === 'empty') { $db->prepare('UPDATE asteroid_trajectories SET maximum_sector_crossings=1 WHERE id=?')->execute([$trajectory->id]); }
            $processor->process($trajectory->id, 'crossing_sector', 0);
            $updated = $trajectories->findById($trajectory->id);
            if ($kind === 'empty') {
                $test->assertEquals('lost', $updated->status, 'crossing limit terminates the transit');
                $test->assertEquals(null, $stocks->find($a->getId())['version'], 'crossing limit removes the lost source stock');
            } else {
                $captured = $sectors->getOrCreateSector($destination)->findObjectById($a->getId());
                $test->assert($captured instanceof Asteroid, 'capture publishes an asteroid with the same identity: ' . $kind);
                $test->assertEquals(10.1234, $captured->getResourceAmounts()['metals'], 'capture hydrates the live SQL stock: ' . $kind);
                $test->assertEquals(null, $tx->run(fn() => $sectors->lockMineableForMining($origin, $a->getId())), 'captured trajectory still rejects the unpublished old origin: ' . $kind);
                if ($kind === 'hole') {
                    $db->beginTransaction(); $processor->process($trajectory->id, 'orbiting_black_hole'); $db->commit();
                    $test->assertEquals($zero, $sectors->getOrCreateSector($destination)->findObjectById($a->getId())->getResourceAmounts(), 'absorption makes stock unavailable before file removal');
                    $flush($destination);
                    $test->assertEquals(null, $sectors->getOrCreateSector($destination)->findObjectById($a->getId()), 'absorption publication removes the descriptor');
                }
            }
            $flush($origin);
            $test->assertEquals(null, $sectors->getOrCreateSector($origin)->findObjectById($a->getId()), 'departure publication removes the origin descriptor: ' . $kind);
        }

        // Actual Manny handlers: missing stocks, reference changes, delayed publication and replay.
        $taskService = new \VonNeumannGame\Service\MannyService($mannies, $probes, $sectors, $items, $storage, scheduledEvents: $events, asteroidTrajectories: $trajectories);
        $taskCoords = new SectorCoordinates(846, 800, 0);
        $probe->currentSector = $taskCoords; $probes->save($probe);
        $taskRock = $rock('task-empty-source'); $seed($taskCoords, [$taskRock]);
        $tx->run(fn() => $stocks->delete($taskRock->getId()));
        $completeTask = static function (string $task, string $objectId) use ($mannies, $probe, $taskCoords, $events, $taskService): array {
            $manny = $mannies->createForProbe($probe->id, 'Lifecycle ' . $task);
            $manny->locationType = \VonNeumannGame\Domain\Manny::LOCATION_SECTOR;
            $manny->sector = $taskCoords;
            $manny->currentTask = $task;
            $manny->taskStartedAt = gmdate('c', time() - 100);
            $manny->taskEndsAt = gmdate('c', time() - 1);
            $manny->taskPayload = ['objectId' => $objectId];
            $mannies->save($manny);
            $event = $events->findById($manny->taskScheduledEventId);
            return [$event, $taskService->refreshScheduledMannyTask($event)];
        };
        [$othersRepo, $othersService] = othersTestFixture($db, $directory);
        $fleet = $othersRepo->createFleet($player->id, 846, 800, 0);
        $ship = $othersRepo->findShipsByFleetId((int) $fleet['id'])[0];
        $auxiliary = $othersRepo->createAuxiliary((int) $ship['id']);
        $action = $othersRepo->createAction($ship, 'auxiliary_mine', 'auxiliary', $auxiliary['public_id'], ['objectId' => $taskRock->getId()], auxiliaryId: (int) $auxiliary['id']);
        $db->prepare('UPDATE others_auxiliaries SET object_id=? WHERE id=?')->execute([$taskRock->getId(), $auxiliary['id']]);
        $observer = $mannies->createForProbe($probe->id, 'Watching Manny');
        $observer->locationType = \VonNeumannGame\Domain\Manny::LOCATION_SECTOR;
        $observer->sector = $taskCoords;
        $observer->currentTask = \VonNeumannGame\Domain\Manny::TASK_INSPECTING_SECTOR_OBJECT;
        $observer->taskEndsAt = gmdate('c', time() + 3600);
        $observer->taskPayload = ['objectId' => $taskRock->getId(), 'targetObjectId' => $taskRock->getId(), 'target' => $taskRock->toArray()];
        $mannies->save($observer);
        $historicalEvent = $events->schedule('historical.test', 'manny', $observer->id, gmdate('c'), ['objectId' => $taskRock->getId()]);
        $events->markDoneById($historicalEvent->id);
        $targeted = $impact($taskCoords, $rock('other-impactor'), $taskRock->getId(), 0.1);
        $db->beginTransaction();
        [$event, $completed] = $completeTask(\VonNeumannGame\Domain\Manny::TASK_MOTORIZING_ASTEROID, $taskRock->getId());
        $motorizedId = $completed->taskPayload['objectId'];
        $test->assertEquals(null, $files->load($taskCoords)->findObjectById($motorizedId), 'motorization does not publish before the Manny transaction commits');
        $db->commit();
        $test->assertEquals($zero, $stocks->find($motorizedId)['amounts'], 'motorization of missing stock creates an explicit zero result');
        $test->assertEquals($motorizedId, $mannies->findById($observer->id)->taskPayload['objectId'], 'motorization updates normalized active Manny object references');
        $test->assertEquals($motorizedId, $mannies->findById($observer->id)->taskPayload['targetObjectId'], 'motorization updates normalized active Manny target references');
        $test->assertEquals($motorizedId, $mannies->findById($observer->id)->taskPayload['target']['id'], 'motorization updates active nested target descriptors');
        $test->assertEquals($taskRock->getId(), $events->findById($historicalEvent->id)->payload['objectId'], 'motorization leaves terminal event history unchanged');
        $test->assertEquals($motorizedId, $trajectories->findById($targeted->id)->targetObjectId, 'incoming active asteroid trajectory follows motorized identity');
        $query = $db->prepare('SELECT object_id FROM others_auxiliaries WHERE id=?'); $query->execute([$auxiliary['id']]);
        $test->assertEquals($motorizedId, $query->fetchColumn(), 'deployed Others auxiliary follows motorized identity');
        $query = $db->prepare('SELECT payload_json FROM others_actions WHERE id=?'); $query->execute([$action['id']]);
        $test->assertEquals($motorizedId, json_decode($query->fetchColumn(), true)['objectId'], 'active Others mining action follows motorized identity');

        $trajectories->transition($targeted->id, ['coasting'], ['status' => 'failed']);

        $test->assertEquals(null, $stocks->find($taskRock->getId())['version'], 'motorization leaves the absent old stock absent');
        $test->assertEquals(\VonNeumannGame\Domain\Manny::TASK_RETURNING, $mannies->findById($completed->id)->currentTask, 'Manny result and stock identity commit together');
        $taskService->refreshScheduledMannyTask($event);
        $flush($taskCoords);
        $test->assert($sectors->getOrCreateSector($taskCoords)->findObjectById($motorizedId)->isMotorized(), 'motorization publication is recoverable independently of the Manny task');
        $tx->run(fn() => $stocks->delete($motorizedId));
        $sectors->changeMineablesAfterCommit($taskCoords, [$motorizedId], static fn(SectorContent $sector) => $sector->replaceObject($sector->findObjectById($motorizedId)->withMotorFuelStatus(Asteroid::MOTOR_FUEL_EMPTY)));
        foreach ([\VonNeumannGame\Domain\Manny::TASK_SCULPTING_DUCK_ASTEROID, \VonNeumannGame\Domain\Manny::TASK_REFUELING_MOTORIZED_ASTEROID] as $task) {
            $before = hash_file('sha256', $files->getPath($taskCoords));
            $db->beginTransaction(); [$event, $completed] = $completeTask($task, $motorizedId); $db->commit();
            $test->assertEquals($before, hash_file('sha256', $files->getPath($taskCoords)), 'Manny metadata awaits publication after commit: ' . $task);
            $test->assertEquals(null, $stocks->find($motorizedId)['version'], 'metadata task does not recreate an absent stock: ' . $task);
            $flush($taskCoords);
        }
        $updated = $sectors->getOrCreateSector($taskCoords)->findObjectById($motorizedId);
        $test->assert($updated->isDuckShaped() && $updated->getMotorFuelStatus() === Asteroid::MOTOR_FUEL_FULL, 'sculpture and refueling preserve each other through durable patches');
        $bookmark = new \VonNeumannGame\Service\WaypointBookmarkService($items, $sectors);
        $bookmark->deployForPlayer($probe, $player->id, 'Player', $motorizedId, 'Canard sans provisions');
        $test->assertEquals('Canard sans provisions', $sectors->getOrCreateSector($taskCoords)->findObjectById($motorizedId)->getName(), 'bookmark rename uses the durable descriptor path');
        $test->assertEquals(null, $stocks->find($motorizedId)['version'], 'renaming does not recreate absent resources');

        // Missile destruction shares the stock/descriptor transaction; replay cannot consume twice.
        $missileCoords = new SectorCoordinates(848, 800, 0);
        $impactor = $rock('missile-impactor')->withDeuteriumEngine('missile-impactor');
        $seed($missileCoords, [$impactor]);
        $trajectory = $impact($missileCoords, $impactor, 'future-target', 0.1);
        $missileImpact = new ReflectionMethod($othersService, 'applyMissileImpact');
        $target = ['kind' => 'motorized_asteroid', 'id' => $impactor->getId(), 'trajectory_id' => $trajectory->id];
        for ($hit = 1; $hit <= 3; ++$hit) {
            $projectile = ['public_id' => 'stock-hit-' . $hit, 'sector_x' => 848, 'sector_y' => 800, 'sector_z' => 0, 'impact_at' => gmdate('c')];
            $db->beginTransaction(); $missileImpact->invoke($othersService, $projectile, $target); $db->commit();
        }
        $test->assertEquals('destroyed', $trajectories->findById($trajectory->id)->status, 'third missile hit terminates the asteroid trajectory');
        $test->assertEquals(null, $stocks->find($impactor->getId())['version'], 'third missile hit deletes the minable stock before publication');
        $test->assertEquals($zero, $sectors->getOrCreateSector($missileCoords)->findObjectById($impactor->getId())->getResourceAmounts(), 'missile destruction never leaves exploitable stock in an old descriptor');
        $tx->run(fn() => $missileImpact->invoke($othersService, $projectile, $target));
        $flush($missileCoords);
        $test->assertEquals(null, $sectors->getOrCreateSector($missileCoords)->findObjectById($impactor->getId()), 'missile destruction publication is idempotent');

        // Strict active snapshot conversion: no stock inference, backup before atomic SQL change.
        $coords3 = new SectorCoordinates(850, 800, 0);
        $a = $rock('snapshot-source'); $seed($coords3, [$a]);
        $trajectory = $impact($coords3, $a, 'snapshot-target', 0.1);
        $legacy = json_encode($a->withResourceAmounts($amounts(999))->toArray(), JSON_THROW_ON_ERROR);
        $db->prepare('UPDATE asteroid_trajectories SET asteroid_snapshot_json=? WHERE id=?')->execute([$legacy, $trajectory->id]);
        $migration = new AsteroidSnapshotDescriptorMigration($db);
        $test->assertEquals(1, $migration->run()['snapshotsToConvert'], 'snapshot dry run inventories the old active format');
        $test->assertEquals(999, $trajectories->findById($trajectory->id)->asteroidSnapshot['resourceAmounts']['metals'], 'snapshot dry run writes nothing');
        $test->assertThrows(fn() => $migration->run(true), InvalidArgumentException::class, 'snapshot conversion requires a backup');
        $migration->run(true, $directory . '/snapshot-backup.json');
        $test->assertEquals(10.1234, $stocks->find('snapshot-source')['amounts']['metals'], 'conversion never restores historical stock quantities');
        $test->assertEquals(0, $migration->run(true, $directory . '/snapshot-backup.json')['snapshotsToConvert'], 'snapshot conversion reruns without overwriting its backup');
        $test->assertEquals($legacy, json_decode(file_get_contents($directory . '/snapshot-backup.json'), true)['rows'][0]['asteroid_snapshot_json'], 'backup preserves the original snapshot bytes');
        $tx->run(fn() => $stocks->delete('snapshot-source'));
        $test->assertThrows(fn() => $migration->run(), RuntimeException::class, 'migration verification blocks missing active source stocks');
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
})();
