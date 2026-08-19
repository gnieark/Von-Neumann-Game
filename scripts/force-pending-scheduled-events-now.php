<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Service\SchedulerService;

require_once __DIR__ . '/../vendor/autoload.php';

$databaseConfig = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/force-pending-scheduled-events-now.php [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$pdo = (new AppFactory(dirname(__DIR__)))->pdo($databaseConfig, initializeSchema: false);
$now = gmdate('c', time() - 1);

$pdo->beginTransaction();

try {
    $pendingEvents = $pdo->query(
        "SELECT id, type, entity_id, payload_json
         FROM scheduled_events
         WHERE status = 'pending'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $activeMovements = $pdo->query(
        "SELECT id, probe_id
         FROM probe_movements
         WHERE status IN ('preparing', 'accelerating', 'cruising', 'decelerating')"
    )->fetchAll(PDO::FETCH_ASSOC);

    $updateManny = $pdo->prepare(
        "UPDATE mannies
         SET task_ends_at = :ended_at, updated_at = :updated_at
         WHERE id = :id AND current_task IS NOT NULL"
    );
    $updateMovement = $pdo->prepare(
        "UPDATE probe_movements
         SET preparation_ends_at = :ended_at,
             acceleration_ends_at = :ended_at,
             cruise_ends_at = :ended_at,
             deceleration_ends_at = :ended_at,
             arrival_at = :ended_at,
             updated_at = :updated_at
         WHERE id = :id
           AND status IN ('preparing', 'accelerating', 'cruising', 'decelerating')"
    );
    $updatePayload = $pdo->prepare(
        "UPDATE scheduled_events
         SET payload_json = :payload_json
         WHERE id = :id AND status = 'pending'"
    );

    foreach ($pendingEvents as $event) {
        $type = (string) $event['type'];
        $entityId = (int) $event['entity_id'];

        if ($type === SchedulerService::MANNY_TASK) {
            $updateManny->execute(['id' => $entityId, 'ended_at' => $now, 'updated_at' => $now]);

            $payload = json_decode((string) $event['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid payload for scheduled event ' . $event['id'] . '.');
            }
            $payload[Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY] = $now;
            $updatePayload->execute([
                'id' => (int) $event['id'],
                'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        }
    }

    $pendingMovementIds = [];
    foreach ($pendingEvents as $event) {
        if ((string) $event['type'] === SchedulerService::PROBE_MOVEMENT_PHASE) {
            $pendingMovementIds[(int) $event['entity_id']] = true;
        }
    }

    $scheduledEvents = new ScheduledEventRepository($pdo);
    foreach ($activeMovements as $movement) {
        $movementId = (int) $movement['id'];
        $updateMovement->execute(['id' => $movementId, 'ended_at' => $now, 'updated_at' => $now]);

        // An older version of this script could consume every phase event
        // without advancing the movement timeline. Recreate a terminal wake-up
        // event when the active movement no longer has a pending one.
        if (!isset($pendingMovementIds[$movementId])) {
            $scheduledEvents->schedule(
                SchedulerService::PROBE_MOVEMENT_PHASE,
                'probe_movement',
                $movementId,
                $now,
                ['probeId' => (int) $movement['probe_id'], 'phase' => 'arrived'],
            );
        }
    }

    $statement = $pdo->prepare(
        "UPDATE scheduled_events
         SET run_at = :run_at, updated_at = :updated_at
         WHERE status = 'pending'"
    );
    $statement->execute([
        'run_at' => $now,
        'updated_at' => $now,
    ]);

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

echo sprintf("Pending scheduled events moved to %s: %d\n", $now, $statement->rowCount());
