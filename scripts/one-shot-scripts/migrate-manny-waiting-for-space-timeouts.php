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
        echo "Usage: php scripts/one-shot-scripts/migrate-manny-waiting-for-space-timeouts.php [--dry-run] [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$root = dirname(__DIR__, 2);
$pdo = (new AppFactory($root))->pdo($databaseConfig, initializeSchema: false);
$rows = $pdo->query(
    "SELECT m.id AS manny_id, m.uid AS manny_uid, se.id AS event_id,
            se.status AS event_status, se.payload_json
     FROM mannies m
     LEFT JOIN scheduled_events se ON se.id = m.task_scheduled_event_id
     WHERE m.current_task = 'waiting_for_space'
     ORDER BY m.id"
)->fetchAll(PDO::FETCH_ASSOC);

$startedAt = gmdate('c');
$plans = [];
foreach ($rows as $row) {
    if ($row['event_id'] === null) {
        throw new RuntimeException('Waiting Manny ' . $row['manny_uid'] . ' has no canonical scheduler event.');
    }
    if ($row['event_status'] === 'running') {
        throw new RuntimeException('Scheduler event ' . $row['event_id'] . ' is running; stop the scheduler before this migration.');
    }
    if ($row['event_status'] !== 'pending') {
        throw new RuntimeException('Waiting Manny ' . $row['manny_uid'] . ' has non-pending scheduler event ' . $row['event_id'] . '.');
    }

    $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $existing = $payload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY] ?? null;
    if (is_string($existing) && trim($existing) !== '') {
        try {
            new DateTimeImmutable($existing);
        } catch (Exception $error) {
            throw new RuntimeException('Waiting Manny ' . $row['manny_uid'] . ' has an invalid timeout timestamp.', previous: $error);
        }
        continue;
    }

    $payload[Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY] = $startedAt;
    $plans[] = [
        'eventId' => (int) $row['event_id'],
        'oldPayload' => (string) $row['payload_json'],
        'newPayload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ];
}

printf(
    "Manny waiting-for-space timeout migration: waiting=%d initialized=%d start_at=%s dry_run=%s\n",
    count($rows),
    count($plans),
    $startedAt,
    $dryRun ? 'yes' : 'no',
);
if ($dryRun || $plans === []) {
    exit(0);
}

$pdo->beginTransaction();
try {
    $update = $pdo->prepare(
        "UPDATE scheduled_events
         SET payload_json = :new_payload, updated_at = :updated_at
         WHERE id = :id AND status = 'pending' AND payload_json = :old_payload"
    );
    foreach ($plans as $plan) {
        $update->execute([
            'id' => $plan['eventId'],
            'old_payload' => $plan['oldPayload'],
            'new_payload' => $plan['newPayload'],
            'updated_at' => $startedAt,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Concurrent change detected for scheduler event ' . $plan['eventId'] . '.');
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

echo "Manny waiting-for-space timeout migration completed.\n";
