<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\ScheduledEventRepository;

require_once __DIR__ . '/../vendor/autoload.php';

$options = [
    'databaseConfig' => null,
    'dryRun' => false,
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $options['dryRun'] = true;
        continue;
    }
    if (str_starts_with($argument, '--database-config=')) {
        $options['databaseConfig'] = substr($argument, strlen('--database-config='));
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
}

$root = dirname(__DIR__);
$factory = new AppFactory($root);
$pdo = $factory->pdo($options['databaseConfig'], initializeSchema: true);

$rows = $pdo->query(
    "SELECT id, current_task, task_ends_at, task_payload_json, task_scheduled_event_id
     FROM mannies
     WHERE current_task IS NOT NULL
     ORDER BY id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$toCreate = [];
$alreadyMigrated = 0;
foreach ($rows as $row) {
    if ($row['task_scheduled_event_id'] !== null) {
        $alreadyMigrated++;
        continue;
    }

    $payload = json_decode((string) ($row['task_payload_json'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $toCreate[] = [
        'id' => (int) $row['id'],
        'runAt' => $row['task_ends_at'] !== null && trim((string) $row['task_ends_at']) !== ''
            ? (string) $row['task_ends_at']
            : ScheduledEventRepository::UNSCHEDULED_RUN_AT,
        'payload' => $payload,
    ];
}

printf(
    "Manny task migration: active=%d already_migrated=%d to_migrate=%d dry_run=%s\n",
    count($rows),
    $alreadyMigrated,
    count($toCreate),
    $options['dryRun'] ? 'yes' : 'no',
);

if ($options['dryRun'] || $toCreate === []) {
    exit(0);
}

$now = gmdate('c');
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        'INSERT INTO scheduled_events
         (type, entity_type, entity_id, run_at, status, payload_json, attempts, locked_at, processed_at, last_error, created_at, updated_at)
         VALUES (:type, :entity_type, :entity_id, :run_at, :status, :payload_json, 0, NULL, NULL, NULL, :created_at, :updated_at)'
    );
    $updateManny = $pdo->prepare(
        'UPDATE mannies
         SET task_scheduled_event_id = :task_scheduled_event_id,
             task_payload_json = :task_payload_json,
             updated_at = :updated_at
         WHERE id = :id AND current_task IS NOT NULL AND task_scheduled_event_id IS NULL'
    );
    $deleteEvent = $pdo->prepare('DELETE FROM scheduled_events WHERE id = :id');

    $migrated = 0;
    foreach ($toCreate as $task) {
        $insert->execute([
            'type' => 'manny.task',
            'entity_type' => 'manny',
            'entity_id' => $task['id'],
            'run_at' => $task['runAt'],
            'status' => 'pending',
            'payload_json' => json_encode($task['payload'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $eventId = (int) $pdo->lastInsertId();
        $updateManny->execute([
            'id' => $task['id'],
            'task_scheduled_event_id' => $eventId,
            'task_payload_json' => '{}',
            'updated_at' => $now,
        ]);
        if ($updateManny->rowCount() === 1) {
            $migrated++;
        } else {
            $deleteEvent->execute(['id' => $eventId]);
        }
    }

    $pdo->commit();
    printf("Manny task migration complete: migrated=%d\n", $migrated);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Manny task migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
