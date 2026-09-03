<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Service\SchedulerService;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = 'config/database.json';
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/requeue-failed-others-reservation-events.php [--dry-run] [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$factory = new AppFactory(dirname(__DIR__, 2));
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$statement = $pdo->prepare(
    "SELECT se.id AS event_id, a.id AS action_id, a.public_id AS action_public_id,
            a.type AS action_type, a.status AS action_status, se.last_error
     FROM scheduled_events se
     INNER JOIN others_actions a
        ON a.id = se.entity_id
       AND a.scheduled_event_id = se.id
     WHERE se.type = :event_type
       AND se.entity_type = 'others_action'
       AND se.status = 'failed'
       AND a.status IN ('queued', 'running', 'cancel_requested')
       AND se.last_error LIKE 'SQLSTATE[42000]: Syntax error or access violation:%'
       AND (
            REPLACE(se.last_error, ' ', '') LIKE '%inventory_reserved-%'
         OR REPLACE(se.last_error, ' ', '') LIKE '%reserved_amount-%'
         OR REPLACE(se.last_error, ' ', '') LIKE '%deuterium_reserved-%'
         OR REPLACE(se.last_error, ' ', '') LIKE '%deuterium_stock-%'
       )
     ORDER BY se.id"
);
$statement->execute(['event_type' => SchedulerService::OTHERS_ACTION]);
$events = $statement->fetchAll(PDO::FETCH_ASSOC);

printf(
    "Failed Others reservation events: events=%d dry_run=%s\n",
    count($events),
    $dryRun ? 'yes' : 'no',
);
foreach ($events as $event) {
    printf(
        "event=%d action=%s type=%s status=%s\n",
        $event['event_id'],
        $event['action_public_id'],
        $event['action_type'],
        $event['action_status'],
    );
}
if ($dryRun || $events === []) {
    exit(0);
}

$now = gmdate('c');
$update = $pdo->prepare(
    "UPDATE scheduled_events
     SET status = 'pending', run_at = :run_at, attempts = 0,
         locked_at = NULL, locked_by = NULL, processed_at = NULL, last_error = NULL,
         updated_at = :updated_at
     WHERE id = :id AND status = 'failed' AND type = :event_type AND entity_type = 'others_action'"
);
$pdo->beginTransaction();
try {
    foreach ($events as $event) {
        $update->execute([
            'id' => (int) $event['event_id'],
            'event_type' => SchedulerService::OTHERS_ACTION,
            'run_at' => $now,
            'updated_at' => $now,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Concurrent change detected while requeuing event ' . $event['event_id'] . '.');
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

echo "Failed Others reservation events requeued.\n";
