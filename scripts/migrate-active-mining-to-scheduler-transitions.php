<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\Manny;

require_once __DIR__ . '/../vendor/autoload.php';

$databaseConfig = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
        continue;
    }
    if ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/migrate-active-mining-to-scheduler-transitions.php [--dry-run] [--database-config=PATH]\n";
        exit(0);
    }
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
}

$factory = new AppFactory(dirname(__DIR__));
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$rows = $pdo->query(
    "SELECT se.id, se.payload_json
     FROM scheduled_events se
     INNER JOIN mannies m ON m.task_scheduled_event_id = se.id
     WHERE m.current_task = 'mining'
       AND se.type = 'manny.task'
       AND se.entity_type = 'manny'
       AND se.status = 'pending'
     ORDER BY se.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

printf(
    "Active mining scheduler migration: pending=%d dry_run=%s\n",
    count($rows),
    $dryRun ? 'yes' : 'no',
);
if ($dryRun || $rows === []) {
    exit(0);
}

$now = gmdate('c');
$update = $pdo->prepare(
    "UPDATE scheduled_events
     SET run_at = :run_at,
         payload_json = :payload_json,
         updated_at = :updated_at
     WHERE id = :id AND status = 'pending'"
);

$pdo->beginTransaction();
try {
    $migrated = 0;
    foreach ($rows as $row) {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload[Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY] = $now;
        $update->execute([
            'id' => (int) $row['id'],
            'run_at' => $now,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]);
        $migrated += $update->rowCount();
    }
    $pdo->commit();
    printf("Active mining scheduler migration complete: migrated=%d\n", $migrated);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Active mining scheduler migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
