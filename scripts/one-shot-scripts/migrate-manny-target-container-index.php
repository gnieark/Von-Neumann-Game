<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = 'config/database.json';
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-manny-target-container-index.php [--dry-run] [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$root = dirname(__DIR__, 2);
$pdo = (new AppFactory($root))->pdo($databaseConfig, initializeSchema: false);
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$rows = $pdo->query(
    "SELECT mt.id, mt.scheduled_event_id, mt.target_container_id, se.payload_json
     FROM manny_tasks mt
     INNER JOIN scheduled_events se ON se.id = mt.scheduled_event_id
     WHERE mt.task_type = 'mining'
     ORDER BY mt.id"
)->fetchAll(PDO::FETCH_ASSOC);

$plans = [];
foreach ($rows as $row) {
    $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $targetContainer = is_array($payload['targetContainer'] ?? null) ? $payload['targetContainer'] : null;
    $targetContainerId = is_string($targetContainer['id'] ?? null) ? trim($targetContainer['id']) : '';
    if ($targetContainerId === '' || $targetContainerId === (string) ($row['target_container_id'] ?? '')) {
        continue;
    }
    $plans[] = [
        'taskId' => (int) $row['id'],
        'eventId' => (int) $row['scheduled_event_id'],
        'targetContainerId' => $targetContainerId,
    ];
}

$indexExists = $driver === 'sqlite'
    ? (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'idx_manny_tasks_target_container'")->fetchColumn() === 1
    : (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manny_tasks' AND INDEX_NAME = 'idx_manny_tasks_target_container'")->fetchColumn() > 0;

printf(
    "Manny target-container migration: projections=%d index_exists=%s dry_run=%s\n",
    count($plans),
    $indexExists ? 'yes' : 'no',
    $dryRun ? 'yes' : 'no',
);
if ($dryRun) {
    exit(0);
}

if ($plans !== []) {
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE manny_tasks
             SET target_container_id = :target_container_id, updated_at = :updated_at
             WHERE id = :id AND scheduled_event_id = :event_id'
        );
        $now = gmdate('c');
        foreach ($plans as $plan) {
            $update->execute([
                'id' => $plan['taskId'],
                'event_id' => $plan['eventId'],
                'target_container_id' => $plan['targetContainerId'],
                'updated_at' => $now,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Concurrent change detected for Manny task ' . $plan['taskId']);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

if (!$indexExists) {
    $pdo->exec('CREATE INDEX idx_manny_tasks_target_container ON manny_tasks(target_container_id)');
}

echo "Manny target-container migration completed.\n";
