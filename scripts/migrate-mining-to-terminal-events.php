<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Service\MannyService;

require_once __DIR__ . '/../vendor/autoload.php';

$databaseConfig = null;
$universePath = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif (str_starts_with($argument, '--universe-path=')) {
        $universePath = substr($argument, strlen('--universe-path='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/migrate-mining-to-terminal-events.php [--dry-run] [--database-config=PATH] [--universe-path=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$root = dirname(__DIR__);
$factory = new AppFactory($root);
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$appConfig = $factory->appConfig();
$gameplay = $factory->gameplayConfig();
$configuredUniversePath = $universePath ?? (string) ($appConfig['universePath'] ?? 'data/universe');
$absoluteUniversePath = str_starts_with($configuredUniversePath, DIRECTORY_SEPARATOR)
    ? $configuredUniversePath
    : $root . DIRECTORY_SEPARATOR . $configuredUniversePath;
$sectors = new SectorFileRepository($absoluteUniversePath);

$rows = $pdo->query(
    "SELECT m.id, m.sector_x, m.sector_y, m.sector_z, m.task_started_at, m.task_ends_at,
            m.cargo_deuterium, m.cargo_metals, m.cargo_ice, m.cargo_organic_compounds,
            se.id AS event_id, se.status AS event_status, se.payload_json
     FROM mannies m
     INNER JOIN scheduled_events se ON se.id = m.task_scheduled_event_id
     WHERE m.current_task = 'mining'
       AND se.type = 'manny.task'
       AND se.entity_type = 'manny'
       AND se.status IN ('pending', 'running', 'failed')
     ORDER BY m.id"
)->fetchAll(PDO::FETCH_ASSOC);

$travel = max(0, Config::int($gameplay, 'manny.actions.miningTravelSeconds', MannyService::MINING_TRAVEL_SECONDS));
$perTick = max(0.0001, Config::float($gameplay, 'manny.actions.miningAmountPerTick', MannyService::MINING_AMOUNT_PER_TICK));
$tickSeconds = max(1, Config::int($gameplay, 'manny.actions.miningTickSeconds', MannyService::MINING_TICK_SECONDS));
$cargoCapacity = max(0.0001, Config::float($gameplay, 'manny.cargoCapacity', MannyService::MANNY_CARGO_CAPACITY));
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$plans = [];
$sectorChanges = [];

foreach ($rows as $row) {
    $payload = json_decode((string) $row['payload_json'], true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid mining payload for Manny ' . $row['id']);
    }
    $target = round(max(0.0, (float) ($payload['targetAmount'] ?? 0.0)), 4);
    $extracted = round(max(0.0, (float) ($payload['extractedAmount'] ?? 0.0)), 4);
    $deposited = round(max(0.0, (float) ($payload['depositedAmount'] ?? 0.0)), 4);
    $inFlight = round(max(0.0, $extracted - $deposited), 4);
    $remaining = round(max(0.0, $target - $deposited), 4);
    $taskTravel = max(0, (int) ($payload['miningTravelSeconds'] ?? $travel));
    $duration = terminalMiningDuration($remaining, $taskTravel, $cargoCapacity, $perTick, $tickSeconds);

    if ($inFlight > 0.0 && $row['sector_x'] !== null && $row['sector_y'] !== null && $row['sector_z'] !== null) {
        $coordinates = new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);
        if ($sectors->exists($coordinates)) {
            $key = $coordinates->toKey();
            $sector = $sectorChanges[$key] ?? $sectors->load($coordinates);
            $object = $sector->findObjectById((string) ($payload['objectId'] ?? ''));
            if ($object instanceof Asteroid) {
                $amounts = $object->getResourceAmounts();
                $profile = is_array($payload['resourceProfile'] ?? null) ? $payload['resourceProfile'] : [];
                foreach ($profile as $type => $ratio) {
                    $amounts[$type] = round((float) ($amounts[$type] ?? 0.0) + ($inFlight * max(0.0, (float) $ratio)), 4);
                }
                if (!$sector->replaceObject($object->withResourceAmounts($amounts))) {
                    throw new RuntimeException('Unable to restore in-flight cargo for Manny ' . $row['id']);
                }
                $sectorChanges[$key] = $sector;
            }
        }
    }

    $payload['targetAmount'] = $remaining;
    $payload['depositedAmount'] = 0.0;
    $payload['depositedResources'] = [];
    $payload['extractedAmount'] = 0.0;
    $payload['extractedResources'] = [];
    unset($payload['phase'], $payload['tripIndex'], $payload['waitingFor'], $payload['reason'], $payload['failureReason'], $payload['sourceExhausted'], $payload['targetContainerFull']);
    $endsAt = $now->modify('+' . $duration . ' seconds')->format('c');
    $payload[Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY] = $endsAt;
    $plans[] = [
        'mannyId' => (int) $row['id'],
        'eventId' => (int) $row['event_id'],
        'eventStatus' => (string) $row['event_status'],
        'remaining' => $remaining,
        'restoredInFlight' => $inFlight,
        'endsAt' => $endsAt,
        'payload' => $payload,
    ];
}

printf("Terminal mining migration: active=%d sectors=%d dry_run=%s\n", count($plans), count($sectorChanges), $dryRun ? 'yes' : 'no');
foreach ($plans as $plan) {
    printf("manny=%d event=%d previous_status=%s remaining=%.4f restored=%.4f ends_at=%s\n", $plan['mannyId'], $plan['eventId'], $plan['eventStatus'], $plan['remaining'], $plan['restoredInFlight'], $plan['endsAt']);
}
if ($dryRun || $plans === []) {
    exit(0);
}

foreach ($sectorChanges as $sector) {
    $sectors->save($sector);
}
$pdo->beginTransaction();
try {
    $updateManny = $pdo->prepare(
        "UPDATE mannies SET task_started_at = :started_at, task_ends_at = :ends_at,
         cargo_deuterium = 0, cargo_metals = 0, cargo_ice = 0, cargo_organic_compounds = 0,
         updated_at = :updated_at WHERE id = :id AND current_task = 'mining'"
    );
    $updateEvent = $pdo->prepare(
        "UPDATE scheduled_events SET status = 'pending', run_at = :run_at, payload_json = :payload_json,
         locked_at = NULL, processed_at = NULL, last_error = NULL, updated_at = :updated_at
         WHERE id = :id AND status IN ('pending', 'running', 'failed')"
    );
    foreach ($plans as $plan) {
        $updateManny->execute(['id' => $plan['mannyId'], 'started_at' => $now->format('c'), 'ends_at' => $plan['endsAt'], 'updated_at' => $now->format('c')]);
        $updateEvent->execute(['id' => $plan['eventId'], 'run_at' => $plan['endsAt'], 'payload_json' => json_encode($plan['payload'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => $now->format('c')]);
        if ($updateManny->rowCount() !== 1 || $updateEvent->rowCount() !== 1) {
            throw new RuntimeException('Concurrent change detected while migrating Manny ' . $plan['mannyId']);
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
printf("Terminal mining migration complete: migrated=%d\n", count($plans));

function terminalMiningDuration(float $amount, int $travel, float $capacity, float $perTick, int $tickSeconds): int
{
    $remaining = round($amount, 4);
    $duration = 0;
    while ($remaining > 0.0001) {
        $trip = min($capacity, $remaining);
        $duration += $travel + ((int) ceil($trip / $perTick) * $tickSeconds) + $travel;
        $remaining = round($remaining - $trip, 4);
    }
    return $duration;
}
