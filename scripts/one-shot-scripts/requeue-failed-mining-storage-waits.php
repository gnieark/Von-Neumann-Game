<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\Manny;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = 'config/database.json';
$dryRun = false;
$backupPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif (str_starts_with($argument, '--backup=')) {
        $backupPath = substr($argument, strlen('--backup='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/requeue-failed-mining-storage-waits.php [--dry-run] [--database-config=PATH] [--backup=NEW_FILE]\n";
        echo "Repair failed mining storage waits and subsequent recalls. Applying requires a new backup file.\n";
        echo "Only failed events with the missing-wait-timestamp error are selected; locked rows are repaired atomically.\n";
        exit(0);
    } else {
        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }
}
if (!$dryRun && ($backupPath === null || $backupPath === '')) {
    throw new InvalidArgumentException('--backup=NEW_FILE is required when applying.');
}

$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$missingTimestampError = 'Storage wait is missing its canonical start timestamp; run the migration for waiting-for-space or blocked mining tasks.';
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$pdo->beginTransaction();
try {
    // Failed events cannot be claimed by workers. Lock both event and Manny rows
    // while applying so a concurrent recall cannot change the planned task.
    $sql = "SELECT e.*, m.id AS manny_id, m.probe_id, m.name AS manny_name,
                   m.current_task, m.task_ends_at
            FROM scheduled_events e JOIN mannies m ON m.task_scheduled_event_id=e.id AND m.id=e.entity_id
            WHERE e.type='manny.task' AND e.entity_type='manny'
              AND e.status='failed' AND e.last_error=:error
              AND m.current_task IN ('mining','returning')
            ORDER BY e.id";
    if (!$dryRun && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql .= ' FOR UPDATE';
    }
    $statement = $pdo->prepare($sql);
    $statement->execute(['error' => $missingTimestampError]);
    $plans = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        if (($payload['waitingFor'] ?? null) !== 'storage_space' || ($payload['reason'] ?? null) !== 'mining_output') {
            continue;
        }
        if (!is_string($row['task_ends_at']) || trim($row['task_ends_at']) === '') {
            throw new RuntimeException('Missing task end for Manny ' . $row['manny_id']);
        }
        $endsAt = new DateTimeImmutable($row['task_ends_at']);
        if ($row['current_task'] === Manny::TASK_MINING) {
            if ($endsAt > $now) {
                throw new RuntimeException('Blocked mining has a future end for Manny ' . $row['manny_id']);
            }
            if (array_key_exists(Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY, $payload)) {
                $since = $payload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY];
                if (!is_string($since) || trim($since) === '' || new DateTimeImmutable($since) > $now) {
                    throw new RuntimeException('Invalid existing wait timestamp for Manny ' . $row['manny_id']);
                }
            } else {
                // Use the same historical start as the original v132 migration.
                $payload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY] = $endsAt->setTimezone(new DateTimeZone('UTC'))->format('c');
            }
        } else {
            // Preserve the player's recall; do not resume its canceled mining.
            unset($payload['waitingFor'], $payload['reason'], $payload['failureReason'], $payload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY]);
            $payload[Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY] = $endsAt->setTimezone(new DateTimeZone('UTC'))->format('c');
        }
        $runAt = ($endsAt > $now ? $endsAt : $now)->setTimezone(new DateTimeZone('UTC'))->format('c');
        $plans[] = ['before' => $row, 'payload' => $payload, 'runAt' => $runAt];
        printf("event=%d manny=%d probe=%s task=%s waiting_since=%s run_at=%s\n",
            $row['id'], $row['manny_id'], $row['probe_id'] ?? 'none', $row['current_task'],
            $payload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY] ?? 'none', $runAt);
    }
    printf("Failed mining storage waits: events=%d dry_run=%s\n", count($plans), $dryRun ? 'yes' : 'no');
    if ($dryRun || $plans === []) {
        $pdo->rollBack();
        exit(0);
    }

    $backup = json_encode(['createdAt' => $now->format('c'), 'plans' => $plans], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $previousUmask = umask(0077);
    try {
        $handle = fopen($backupPath, 'x');
    } finally {
        umask($previousUmask);
    }
    if ($handle === false) {
        throw new RuntimeException('Cannot create exclusive backup: ' . $backupPath);
    }
    try {
        if (fwrite($handle, $backup) !== strlen($backup) || !fflush($handle) || !fsync($handle)) {
            throw new RuntimeException('Failed to persist backup; no events changed.');
        }
    } finally {
        fclose($handle);
    }

    $update = $pdo->prepare(
        "UPDATE scheduled_events
         SET payload_json=:payload, status='pending', run_at=:run_at, attempts=0,
             locked_at=NULL, locked_by=NULL, processed_at=NULL, last_error=NULL, updated_at=:updated
         WHERE id=:id AND status='failed' AND last_error=:error AND payload_json=:old_payload
           AND EXISTS (SELECT 1 FROM mannies m WHERE m.id=:manny_id
                       AND m.task_scheduled_event_id=scheduled_events.id
                       AND m.current_task=:task AND m.task_ends_at=:ends_at)"
    );
    foreach ($plans as $plan) {
        $row = $plan['before'];
        $update->execute([
            'payload' => json_encode($plan['payload'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'run_at' => $plan['runAt'], 'updated' => $now->format('c'), 'id' => $row['id'],
            'error' => $missingTimestampError, 'old_payload' => $row['payload_json'],
            'manny_id' => $row['manny_id'], 'task' => $row['current_task'], 'ends_at' => $row['task_ends_at'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Concurrent change for event ' . $row['id'] . '; migration rolled back.');
        }
    }
    $pdo->commit();
    echo "Migration completed; backup: {$backupPath}\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
