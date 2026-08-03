<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$databaseConfig = null;
$retentionDays = 30;
$batchSize = 1000;
$dryRun = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif (str_starts_with($argument, '--retention-days=')) {
        $retentionDays = (int) substr($argument, strlen('--retention-days='));
    } elseif (str_starts_with($argument, '--batch-size=')) {
        $batchSize = (int) substr($argument, strlen('--batch-size='));
    } elseif ($argument === '--dry-run') {
        $dryRun = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/purge-scheduled-events.php [--dry-run] [--retention-days=30] [--batch-size=1000] [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

if ($retentionDays < 1) {
    fwrite(STDERR, "Retention must be at least one day.\n");
    exit(2);
}
if ($batchSize < 1 || $batchSize > 10000) {
    fwrite(STDERR, "Batch size must be between 1 and 10000.\n");
    exit(2);
}

$projectRoot = dirname(__DIR__);
$pdo = (new AppFactory($projectRoot))->pdo($databaseConfig, initializeSchema: false);
$cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify(sprintf('-%d days', $retentionDays))
    ->format('c');

$eligibleWhere = "status IN ('done', 'failed', 'cancelled')
    AND COALESCE(processed_at, updated_at) < :cutoff
    AND NOT EXISTS (
        SELECT 1 FROM mannies WHERE mannies.task_scheduled_event_id = scheduled_events.id
    )";

$countStatement = $pdo->prepare(
    "SELECT status, COUNT(*) AS event_count
     FROM scheduled_events
     WHERE {$eligibleWhere}
     GROUP BY status"
);
$countStatement->execute(['cutoff' => $cutoff]);
$counts = array_fill_keys(['done', 'failed', 'cancelled'], 0);
foreach ($countStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $counts[(string) $row['status']] = (int) $row['event_count'];
}
$eligibleCount = array_sum($counts);

echo sprintf(
    "Scheduled events eligible before %s: %d (done=%d, failed=%d, cancelled=%d)%s\n",
    $cutoff,
    $eligibleCount,
    $counts['done'],
    $counts['failed'],
    $counts['cancelled'],
    $dryRun ? ' (dry run)' : '',
);

if ($dryRun || $eligibleCount === 0) {
    exit(0);
}

$selectStatement = $pdo->prepare(
    "SELECT id
     FROM scheduled_events
     WHERE {$eligibleWhere}
     ORDER BY COALESCE(processed_at, updated_at) ASC, id ASC
     LIMIT {$batchSize}"
);
$deletedCount = 0;

do {
    $selectStatement->execute(['cutoff' => $cutoff]);
    $ids = array_map('intval', $selectStatement->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) {
        break;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $deleteStatement = $pdo->prepare(
        "DELETE FROM scheduled_events
         WHERE id IN ({$placeholders})
           AND status IN ('done', 'failed', 'cancelled')
           AND COALESCE(processed_at, updated_at) < ?
           AND NOT EXISTS (
               SELECT 1 FROM mannies WHERE mannies.task_scheduled_event_id = scheduled_events.id
           )"
    );
    $deleteStatement->execute([...$ids, $cutoff]);
    $deletedInBatch = $deleteStatement->rowCount();
    $deletedCount += $deletedInBatch;
} while ($deletedInBatch > 0);

echo sprintf("Purged scheduled events: %d\n", $deletedCount);

