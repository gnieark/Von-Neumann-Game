<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$databaseConfig = null;
$dryRun = false;
$confirmed = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--dry-run') {
        $dryRun = true;
    } elseif ($argument === '--all-running') {
        $confirmed = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/recover-running-scheduled-events.php --all-running [--dry-run] [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

if (!$confirmed) {
    fwrite(STDERR, "Refusing to continue without --all-running. Stop every scheduler worker first.\n");
    exit(2);
}

$factory = new AppFactory(dirname(__DIR__));
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$count = (int) $pdo->query("SELECT COUNT(*) FROM scheduled_events WHERE status = 'running'")->fetchColumn();
echo sprintf("Running scheduled events found: %d%s\n", $count, $dryRun ? ' (dry run)' : '');

if ($dryRun || $count === 0) {
    exit(0);
}

$now = gmdate('c');
$stmt = $pdo->prepare(
    "UPDATE scheduled_events
     SET status = 'pending', locked_at = NULL, locked_by = NULL, processed_at = NULL,
         last_error = :last_error, updated_at = :updated_at
     WHERE status = 'running'"
);
$stmt->execute([
    'last_error' => 'Recovered manually while scheduler workers were stopped.',
    'updated_at' => $now,
]);

echo sprintf("Recovered scheduled events: %d\n", $stmt->rowCount());
