<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = 'config/database.json';
$playerId = null;
$backupPath = null;
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif (str_starts_with($argument, '--player-id=')) {
        $playerId = filter_var(substr($argument, strlen('--player-id=')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    } elseif (str_starts_with($argument, '--backup=')) {
        $backupPath = substr($argument, strlen('--backup='));
    } elseif ($argument === '--apply') {
        $apply = true;
    } elseif ($argument === '--dry-run') {
        // Simulation is the default.
    } elseif ($argument === '--help') {
        echo "Usage: php scripts/one-shot-scripts/requeue-failed-manny-storage-transactions.php --player-id=ID [--database-config=PATH] [--apply --backup=NEW_FILE]\n";
        exit(0);
    } else {
        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }
}
if (!is_int($playerId) || ($apply && ($backupPath === null || $backupPath === ''))) {
    throw new InvalidArgumentException('A positive --player-id is required; applying also requires --backup=NEW_FILE.');
}

$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$pdo->beginTransaction();
try {
    $sql = "SELECT e.*, m.id AS manny_id, m.name AS manny_name, m.probe_id,
                   m.current_task, m.task_ends_at
            FROM neumann_probes p
            JOIN mannies m ON m.probe_id=p.id
            JOIN scheduled_events e ON e.id=m.task_scheduled_event_id AND e.entity_id=m.id
            JOIN manny_tasks t ON t.manny_id=m.id AND t.scheduled_event_id=e.id AND t.task_type=m.current_task
            WHERE p.player_id=:player_id AND m.current_task IS NOT NULL
              AND e.type='manny.task' AND e.entity_type='manny' AND e.status='failed'
              AND e.locked_at IS NULL AND e.locked_by IS NULL
              AND e.last_error IN (:storage_error, :lock_error)
            ORDER BY e.id";
    if ($apply && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql .= ' FOR UPDATE';
    }
    $query = $pdo->prepare($sql);
    $query->execute([
        'player_id' => $playerId,
        'storage_error' => 'Storage transaction retries exhausted.',
        'lock_error' => 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction',
    ]);
    $plans = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_string($row['task_ends_at']) || new DateTimeImmutable($row['task_ends_at']) > $now) {
            throw new RuntimeException('Missing or future task end for Manny ' . $row['manny_id']);
        }
        // Spread the retries to avoid immediately contending on the same probe.
        $runAt = $now->modify('+' . count($plans) . ' seconds')->format('c');
        $plans[] = ['before' => $row, 'runAt' => $runAt];
        printf("event=%d manny=%d probe=%d task=%s run_at=%s\n", $row['id'], $row['manny_id'], $row['probe_id'], $row['current_task'], $runAt);
    }
    printf("player=%d events=%d apply=%s\n", $playerId, count($plans), $apply ? 'yes' : 'no');
    if (!$apply || $plans === []) {
        $pdo->rollBack();
        exit(0);
    }

    $backup = json_encode(['createdAt' => $now->format('c'), 'playerId' => $playerId, 'plans' => $plans], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
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

    $update = $pdo->prepare("UPDATE scheduled_events
        SET status='pending',run_at=:run_at,locked_at=NULL,locked_by=NULL,
            processed_at=NULL,last_error=NULL,updated_at=:updated
        WHERE id=:id AND status='failed' AND last_error=:error
          AND EXISTS (SELECT 1 FROM mannies m WHERE m.id=:manny_id
                      AND m.task_scheduled_event_id=scheduled_events.id AND m.current_task=:task)");
    foreach ($plans as $plan) {
        $row = $plan['before'];
        $update->execute([
            'run_at' => $plan['runAt'], 'updated' => $now->format('c'), 'id' => $row['id'],
            'error' => $row['last_error'], 'manny_id' => $row['manny_id'], 'task' => $row['current_task'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Concurrent change for event ' . $row['id'] . '; rolled back.');
        }
    }
    $pdo->commit();
    echo "Events requeued; backup: {$backupPath}\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
