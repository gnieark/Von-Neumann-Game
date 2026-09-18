<?php

declare(strict_types=1);

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Service\MannyService;

$prepareBlockedMining = static function (string $name, float $stock = 0.5) use (
    $auth, $probes, $mannies, $storage, $storageContainers, $saveSectorFixture,
    $mannyService, $processScheduledMannyNow, $test,
): array {
    static $sectorIndex = 0;
    $owner = $auth->registerPlayerWithPassword($name, 'secret', $name);
    $probe = $probes->findByPlayerId($owner->id);
    $probe->currentSector = new SectorCoordinates(9400 + 2 * $sectorIndex++, 0, 0);
    $probes->save($probe);
    $saveSectorFixture(new SectorContent($probe->currentSector, [
        new Asteroid('timeout-rock', null, 'iron', ['iron'], 'small', 0.000001, 0.001),
    ]));
    $probe = setProbeTestStoredResources($storage, $storageContainers, $probes, $probe, ['metals' => 0]);
    $manny = $mannies->findByProbeId($probe->id)[0];
    $manny = $mannyService->startMining($probe, $manny->uid, 'timeout-rock', 'metals', 0.2);
    $probe = setProbeTestStoredResources($storage, $storageContainers, $probes, $probe, ['metals' => $stock]);
    $manny->taskStartedAt = gmdate('c', time() - 10000);
    $manny->taskEndsAt = gmdate('c', time() - 1);
    $manny->taskPayload[Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY] = $manny->taskEndsAt;
    $mannies->save($manny);
    $stats = $processScheduledMannyNow($manny->id);
    $test->assertEquals(1, $stats['deferred'], 'blocked mining is deferred before its storage timeout');
    $manny = $mannies->findById($manny->id);
    $test->assertEquals(Manny::TASK_MINING, $manny->currentTask, 'blocked mining retains its extraction task');
    $test->assertEquals('storage_space', $manny->taskPayload['waitingFor'] ?? null, 'blocked mining reports its storage wait');
    $test->assert(is_string($manny->taskPayload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY] ?? null), 'blocked mining starts its canonical timeout');
    return [$probe, $manny];
};

[$resumeProbe, $resumeManny] = $prepareBlockedMining('mining-wait-resume');
$waitingSince = $resumeManny->taskPayload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY];
$resumeManny->taskPayload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY] = gmdate('c', time() - 3600);
$mannies->save($resumeManny);
$waitingSince = $resumeManny->taskPayload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY];
$processScheduledMannyNow($resumeManny->id);
$resumeManny = $mannies->findById($resumeManny->id);
$test->assertEquals($waitingSince, $resumeManny->taskPayload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY], 'repeated deferrals preserve the original storage wait timestamp');
$publicMining = $mannyService->publicArray($resumeProbe, $resumeManny);
$test->assertEquals($waitingSince, $publicMining['task']['waitingForSpaceSince'] ?? null, 'mining API payload exposes its storage wait timestamp');
$test->assert(!isset($publicMining['task'][Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY]), 'storage wait does not expose the internal scheduler timestamp');
$test->assertEquals(1.0, $sectorRepository->load($resumeProbe->currentSector)->findObjectById('timeout-rock')->getResourceAmounts()['metals'], 'waiting mining has not depleted its source');
$resumeProbe = setProbeTestStoredResources($storage, $storageContainers, $probes, $resumeProbe, ['metals' => 0.2]);
$resumeEventId = $resumeManny->taskScheduledEventId;
$processScheduledMannyNow($resumeManny->id);
$resumeManny = $mannies->findById($resumeManny->id);
$test->assertEquals(null, $resumeManny->currentTask, 'mining completes when space is freed before timeout');
$test->assertEquals(Manny::LOCATION_PROBE, $resumeManny->locationType, 'successful delayed mining returns its Manny');
$test->assertEquals(0.4, $storage->resourceStock($resumeProbe, ResourceComposition::METALS), 'resumed mining delivers its resources once');
$test->assertEquals(0.8, $sectorRepository->load($resumeProbe->currentSector)->findObjectById('timeout-rock')->getResourceAmounts()['metals'], 'resumed mining depletes only its requested amount');
$test->assertEquals('done', $scheduledEvents->findById($resumeEventId)->status, 'resumed mining completes its scheduler event');
$test->assert(!isset($resumeManny->taskPayload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY]), 'completed mining clears its wait timestamp');

foreach (['docks' => 0.5, 'abandoned' => 0.55, 'remote' => 0.5] as $outcome => $stock) {
    [$timeoutProbe, $timeoutManny] = $prepareBlockedMining('mining-wait-' . $outcome, $stock);
    $miningSector = $timeoutProbe->currentSector;
    $timeoutEventId = $timeoutManny->taskScheduledEventId;
    $timeoutManny->taskPayload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY] = gmdate('c', time() - MannyService::WAITING_FOR_SPACE_TIMEOUT_SECONDS);
    $mannies->save($timeoutManny);
    if ($outcome === 'remote') {
        $timeoutProbe->currentSector = new SectorCoordinates(9600, 0, 0);
        $probes->save($timeoutProbe);
    }
    $stats = $processScheduledMannyNow($timeoutManny->id);
    $expired = $mannies->findById($timeoutManny->id);
    $test->assertEquals(1, $stats['processed'], 'expired mining storage wait is processed, not deferred');
    $test->assertEquals(null, $expired->currentTask, 'expired mining clears its task');
    $test->assertEquals('storage_space_timeout', $expired->taskPayload['reason'] ?? null, 'expired mining records its timeout');
    $test->assertEquals('mining', $expired->taskPayload['lastTask'] ?? null, 'expired mining records the canceled task type');
    $test->assertEquals('done', $scheduledEvents->findById($timeoutEventId)->status, 'expired mining completes its scheduler event');
    $test->assertEquals($stock, $storage->resourceStock($timeoutProbe, ResourceComposition::METALS), 'expired mining does not credit undelivered resources');
    $test->assertEquals(1.0, $sectorRepository->load($miningSector)->findObjectById('timeout-rock')->getResourceAmounts()['metals'], 'expired mining does not deplete unextracted resources');
    if ($outcome === 'docks') {
        $test->assertEquals(Manny::LOCATION_PROBE, $expired->locationType, 'timed-out miner docks when its own slot is available');
        $test->assertEquals($timeoutProbe->id, $expired->probeId, 'docked miner keeps its probe');
        $test->assertEquals('returned_after_cargo_abandonment', $expired->taskPayload['result'] ?? null, 'mining timeout uses the shared cargo abandonment outcome');
    } else {
        $test->assertEquals(null, $expired->probeId, 'miner without a local docking slot becomes unowned');
        $test->assertEquals($miningSector->toArray(), $expired->sector?->toArray(), 'abandoned miner stays in its mining sector');
        $test->assertEquals(SectorManny::STATE_ABANDONED, $sectorRepository->load($miningSector)->findObjectById(SectorManny::objectIdForUid($expired->uid))?->toArray()['state'] ?? null, 'expired miner becomes a recoverable abandoned sector object');
    }
    $mannyService->refreshMannyState($expired, $timeoutProbe);
    $test->assertEquals($stock, $storage->resourceStock($timeoutProbe, ResourceComposition::METALS), 'replaying an expired mining refresh cannot deliver resources');
}

// Exercise the explicit migration against a separate database, including dry run and replay.
$miningMigrationDb = $tmp . '/blocked-mining-migration.sqlite';
$miningMigrationPdo = new PDO('sqlite:' . $miningMigrationDb);
$miningMigrationPdo->exec('CREATE TABLE mannies (id INTEGER PRIMARY KEY,current_task TEXT,task_ends_at TEXT,task_scheduled_event_id INTEGER)');
$miningMigrationPdo->exec('CREATE TABLE scheduled_events (id INTEGER PRIMARY KEY,status TEXT,run_at TEXT,attempts INTEGER,payload_json TEXT,updated_at TEXT)');
$insertMigrationEvent = $miningMigrationPdo->prepare('INSERT INTO scheduled_events VALUES (?, ?, ?, 10, ?, ?)');
$insertMigrationManny = $miningMigrationPdo->prepare('INSERT INTO mannies VALUES (?, ?, ?, ?)');
foreach ([1 => ['waitingFor' => 'storage_space', 'reason' => 'mining_output'], 2 => [], 3 => ['waitingFor' => 'storage_space', 'reason' => 'mining_output', 'waitingForSpaceSince' => '2026-01-02T00:00:00+00:00']] as $id => $payload) {
    $insertMigrationEvent->execute([$id, 'pending', '2026-01-01T00:00:00+00:00', json_encode($payload), '2026-01-01T00:00:00+00:00']);
    $insertMigrationManny->execute([$id, 'mining', '2026-01-01T00:00:00+00:00', $id]);
}
$miningMigrationConfig = $tmp . '/blocked-mining-migration.json';
file_put_contents($miningMigrationConfig, json_encode(['driver' => 'sqlite', 'path' => $miningMigrationDb]));
$migrationCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/one-shot-scripts/migrate-manny-blocked-mining-timeouts.php') . ' ' . escapeshellarg('--database-config=' . $miningMigrationConfig);
$originalRows = $miningMigrationPdo->query('SELECT * FROM scheduled_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
exec($migrationCommand . ' --dry-run 2>&1', $migrationOutput, $migrationStatus);
$test->assertEquals(0, $migrationStatus, 'blocked mining migration dry run succeeds');
$test->assertEquals($originalRows, $miningMigrationPdo->query('SELECT * FROM scheduled_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'blocked mining dry run does not modify events');
exec($migrationCommand . ' 2>&1', $migrationOutput, $migrationStatus);
$test->assertEquals(0, $migrationStatus, 'blocked mining migration succeeds');
$migratedRows = $miningMigrationPdo->query('SELECT * FROM scheduled_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$test->assertEquals('2026-01-01T00:00:00+00:00', json_decode($migratedRows[0]['payload_json'], true)['waitingForSpaceSince'] ?? null, 'existing mining waits start from their original mining end time');
$test->assertEquals($originalRows[1], $migratedRows[1], 'migration leaves unblocked mining unchanged');
$test->assertEquals($originalRows[2], $migratedRows[2], 'migration preserves an existing canonical wait timestamp');
exec($migrationCommand . ' 2>&1', $migrationOutput, $migrationStatus);
$test->assertEquals(0, $migrationStatus, 'blocked mining migration can be replayed');
$test->assertEquals($migratedRows, $miningMigrationPdo->query('SELECT * FROM scheduled_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'migration replay does not reset storage wait deadlines');

require __DIR__ . '/FailedMiningStorageWaitMigrationTests.php';
