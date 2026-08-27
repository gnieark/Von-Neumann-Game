<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Config\Config;
use VonNeumannGame\Service\MannyService;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = 'config/database.json';
$playerUsername = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif (str_starts_with($argument, '--player=')) {
        $playerUsername = trim(substr($argument, strlen('--player=')));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/recall-failed-container-miners.php --player=USERNAME [--dry-run] [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}
if ($playerUsername === null || $playerUsername === '') {
    fwrite(STDERR, "--player is required.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$factory = new AppFactory($root);
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$travelSeconds = max(0, Config::int(
    $factory->gameplayConfig(),
    'manny.actions.miningTravelSeconds',
    MannyService::MINING_TRAVEL_SECONDS,
));
$statement = $pdo->prepare(
    "SELECT m.id AS manny_id, m.name AS manny_name, m.task_started_at,
            m.cargo_deuterium, m.cargo_metals, m.cargo_ice, m.cargo_organic_compounds,
            se.id AS event_id, mt.id AS task_id, mt.target_container_id
     FROM players p
     INNER JOIN neumann_probes np ON np.player_id = p.id
     INNER JOIN mannies m ON m.probe_id = np.id
     INNER JOIN scheduled_events se ON se.id = m.task_scheduled_event_id
     INNER JOIN manny_tasks mt
        ON mt.manny_id = m.id
       AND mt.scheduled_event_id = se.id
     WHERE p.username = :username
       AND m.current_task = 'mining'
       AND se.type = 'manny.task'
       AND se.entity_type = 'manny'
       AND se.status = 'failed'
       AND se.last_error = 'Detached storage container not found.'
       AND mt.task_type = 'mining'
       AND mt.target_container_id IS NOT NULL
     ORDER BY m.id"
);
$statement->execute(['username' => $playerUsername]);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$plans = [];
foreach ($rows as $row) {
    $cargo = round(
        (float) $row['cargo_deuterium']
        + (float) $row['cargo_metals']
        + (float) $row['cargo_ice']
        + (float) $row['cargo_organic_compounds'],
        4,
    );
    if ($cargo !== 0.0) {
        throw new RuntimeException('Refusing to recall Manny ' . $row['manny_id'] . ': non-empty cargo requires an explicit sector drop.');
    }
    $startedAt = is_string($row['task_started_at']) && trim($row['task_started_at']) !== ''
        ? new DateTimeImmutable($row['task_started_at'])
        : null;
    $elapsed = $startedAt !== null ? max(0, $now->getTimestamp() - $startedAt->getTimestamp()) : $travelSeconds;
    $duration = min($travelSeconds, $elapsed);
    $plans[] = $row + [
        'duration' => $duration,
        'endsAt' => $now->modify('+' . $duration . ' seconds')->format('c'),
    ];
}

printf("Failed container-miner recall: player=%s mannies=%d dry_run=%s\n", $playerUsername, count($plans), $dryRun ? 'yes' : 'no');
foreach ($plans as $plan) {
    printf(
        "manny=%d name=%s event=%d duration=%d target_container=%s\n",
        $plan['manny_id'],
        $plan['manny_name'],
        $plan['event_id'],
        $plan['duration'],
        $plan['target_container_id'],
    );
}
if ($dryRun || $plans === []) {
    exit(0);
}

$pdo->beginTransaction();
try {
    $updateManny = $pdo->prepare(
        "UPDATE mannies
         SET current_task = 'returning', task_started_at = :started_at, task_ends_at = :ends_at,
             reserved_cargo_type = NULL, reserved_cargo_space = 0, reserved_storage_container_id = NULL,
             cargo_deuterium = 0, cargo_metals = 0, cargo_ice = 0, cargo_organic_compounds = 0,
             updated_at = :updated_at
         WHERE id = :id AND current_task = 'mining' AND task_scheduled_event_id = :event_id"
    );
    $updateEvent = $pdo->prepare(
        "UPDATE scheduled_events
         SET status = 'pending', run_at = :run_at, payload_json = :payload_json,
             locked_at = NULL, locked_by = NULL, processed_at = NULL, last_error = NULL,
             updated_at = :updated_at
         WHERE id = :id AND status = 'failed' AND type = 'manny.task' AND entity_type = 'manny'"
    );
    $updateTask = $pdo->prepare(
        "UPDATE manny_tasks
         SET task_type = 'returning', recipe = NULL, crafting_run_id = NULL,
             resource_type = NULL, target_amount = NULL, extracted_amount = NULL,
             object_id = NULL, target_object_id = NULL, source_container_id = NULL,
             destination_container_id = NULL, target_probe_id = NULL, relay_id = NULL,
             improvement = NULL, updated_at = :updated_at
         WHERE id = :id AND scheduled_event_id = :event_id AND task_type = 'mining'"
    );
    $updatedAt = $now->format('c');
    foreach ($plans as $plan) {
        $payload = json_encode([
            'reason' => 'target_container_unavailable',
            'lastTask' => 'mining',
            'result' => 'cancelled',
            'droppedCargo' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $updateManny->execute([
            'id' => $plan['manny_id'],
            'event_id' => $plan['event_id'],
            'started_at' => $updatedAt,
            'ends_at' => $plan['endsAt'],
            'updated_at' => $updatedAt,
        ]);
        $updateEvent->execute([
            'id' => $plan['event_id'],
            'run_at' => $plan['endsAt'],
            'payload_json' => $payload,
            'updated_at' => $updatedAt,
        ]);
        $updateTask->execute([
            'id' => $plan['task_id'],
            'event_id' => $plan['event_id'],
            'updated_at' => $updatedAt,
        ]);
        if ($updateManny->rowCount() !== 1 || $updateEvent->rowCount() !== 1 || $updateTask->rowCount() !== 1) {
            throw new RuntimeException('Concurrent change detected while recalling Manny ' . $plan['manny_id']);
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

echo "Failed container miners recalled.\n";
