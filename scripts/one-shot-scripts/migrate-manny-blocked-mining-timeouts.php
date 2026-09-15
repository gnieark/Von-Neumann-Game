<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\Manny;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = 'config/database.json';
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-manny-blocked-mining-timeouts.php [--dry-run] [--database-config=PATH]\n";
        echo "Initialize existing mining storage waits from their mining end time. Pause scheduler workers before applying.\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$rows = $pdo->query(
    "SELECT m.id AS manny_id,m.task_ends_at,e.id AS event_id,e.status,e.run_at,e.attempts,e.payload_json
     FROM mannies m JOIN scheduled_events e ON e.id=m.task_scheduled_event_id
     WHERE m.current_task='mining' ORDER BY m.id"
)->fetchAll(PDO::FETCH_ASSOC);
$now = gmdate('c');
$plans = [];
foreach ($rows as $row) {
    $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    if (($payload['waitingFor'] ?? null) !== 'storage_space' || ($payload['reason'] ?? null) !== 'mining_output') {
        continue;
    }
    if ($row['status'] !== 'pending') {
        throw new RuntimeException('Blocked mining event ' . $row['event_id'] . ' is not pending; pause scheduler workers before migrating.');
    }
    if (array_key_exists(Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY, $payload)) {
        $existing = $payload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY];
        if (!is_string($existing) || trim($existing) === '') {
            throw new RuntimeException('Invalid storage wait timestamp for Manny ' . $row['manny_id'] . '.');
        }
        new DateTimeImmutable($existing);
        continue;
    }
    if (!is_string($row['task_ends_at']) || trim($row['task_ends_at']) === '') {
        throw new RuntimeException('Missing mining end timestamp for Manny ' . $row['manny_id'] . '.');
    }
    $since = new DateTimeImmutable($row['task_ends_at']);
    if ($since > new DateTimeImmutable($now)) {
        throw new RuntimeException('Blocked mining has a future end timestamp for Manny ' . $row['manny_id'] . '.');
    }
    $payload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY] = $since->setTimezone(new DateTimeZone('UTC'))->format('c');
    $plans[] = $row + ['new_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
}
printf("Blocked mining timeout migration: initialized=%d dry_run=%s\n", count($plans), $dryRun ? 'yes' : 'no');
if ($dryRun || $plans === []) {
    exit(0);
}

$pdo->beginTransaction();
try {
    $update = $pdo->prepare(
        "UPDATE scheduled_events SET payload_json=:new_payload,run_at=:now,updated_at=:updated
         WHERE id=:id AND status='pending' AND payload_json=:old_payload AND run_at=:old_run_at AND attempts=:attempts
         AND EXISTS (SELECT 1 FROM mannies m WHERE m.id=:manny_id AND m.current_task='mining'
                     AND m.task_scheduled_event_id=scheduled_events.id AND m.task_ends_at=:task_ends_at)"
    );
    foreach ($plans as $plan) {
        $update->execute([
            'new_payload' => $plan['new_payload'], 'now' => $now, 'updated' => $now,
            'id' => $plan['event_id'], 'old_payload' => $plan['payload_json'],
            'old_run_at' => $plan['run_at'], 'attempts' => $plan['attempts'],
            'manny_id' => $plan['manny_id'], 'task_ends_at' => $plan['task_ends_at'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Concurrent change detected for event ' . $plan['event_id'] . '; migration rolled back.');
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
echo "Blocked mining timeout migration completed.\n";
