<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

$eventId = null;
$databaseConfig = 'config/database.json';
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--event-id=')) {
        $eventId = filter_var(substr($argument, strlen('--event-id=')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    } elseif (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--apply') {
        $apply = true;
    } else {
        fwrite(STDERR, "Usage: php scripts/one-shot-scripts/requeue-failed-oracle-container-drop.php --event-id=ID [--database-config=PATH] [--apply]\n");
        exit(2);
    }
}
if (!is_int($eventId)) {
    fwrite(STDERR, "A positive --event-id is required.\n");
    exit(2);
}

$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$pdo->beginTransaction();
try {
    $sql = "SELECT e.id, e.status, e.type, e.entity_type, e.last_error, e.payload_json,
                   m.id AS manny_id, m.probe_id, m.current_task, m.task_ends_at,
                   m.task_scheduled_event_id, t.object_id, t.target_object_id,
                   p.player_id, p.sector_x, p.sector_y, p.sector_z
            FROM scheduled_events e
            JOIN mannies m ON m.id = e.entity_id AND e.entity_type = 'manny'
            JOIN manny_tasks t ON t.manny_id = m.id AND t.scheduled_event_id = e.id
            JOIN neumann_probes p ON p.id = m.probe_id
            WHERE e.id = :id";
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $query = $pdo->prepare($sql);
    $query->execute(['id' => $eventId]);
    $event = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($event)
        || $event['type'] !== 'manny.task'
        || $event['status'] !== 'failed'
        || $event['last_error'] !== 'Sector changed concurrently; reload before applying the operation.'
        || $event['current_task'] !== 'dropping_storage_container'
        || (int) $event['task_scheduled_event_id'] !== $eventId
        || !is_string($event['task_ends_at'])
        || strtotime($event['task_ends_at']) === false
        || strtotime($event['task_ends_at']) > time()) {
        throw new RuntimeException('Event is not an eligible failed container drop.');
    }

    $payload = json_decode((string) $event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $items = $payload['snapshot']['items'] ?? [];
    if (!is_array($items) || !in_array('biological_archive', array_column($items, 'type'), true)) {
        throw new RuntimeException('Drop snapshot has no biological archive.');
    }
    $missionQuery = $pdo->prepare("SELECT metadata_json FROM probe_missions
        WHERE player_id = :player_id AND type = 'first_contact.oracle' AND status = 'active'");
    $missionQuery->execute(['player_id' => $event['player_id']]);
    $sameOrigin = false;
    foreach ($missionQuery->fetchAll(PDO::FETCH_COLUMN) as $metadataJson) {
        $mission = json_decode((string) $metadataJson, true, 512, JSON_THROW_ON_ERROR);
        $sector = $mission['sector'] ?? [];
        if (($mission['planetId'] ?? null) === $event['target_object_id']
            && ($sector['x'] ?? null) == $event['sector_x']
            && ($sector['y'] ?? null) == $event['sector_y']
            && ($sector['z'] ?? null) == $event['sector_z']) {
            $sameOrigin = true;
            break;
        }
    }
    if (!$sameOrigin) {
        throw new RuntimeException('Active Oracle origin does not match the drop target and probe sector.');
    }
    $containerQuery = $pdo->prepare('SELECT COUNT(*) FROM detached_storage_containers WHERE object_id = :object_id');
    $containerQuery->execute(['object_id' => $event['object_id']]);
    if ((int) $containerQuery->fetchColumn() !== 0) {
        throw new RuntimeException('Dropped container already exists.');
    }

    printf("event=%d probe=%d manny=%d status=failed container_missing=yes apply=%s\n",
        $eventId, $event['probe_id'], $event['manny_id'], $apply ? 'yes' : 'no');
    if ($apply) {
        $now = gmdate('c');
        $update = $pdo->prepare("UPDATE scheduled_events
            SET status = 'pending', run_at = :now, locked_at = NULL, locked_by = NULL,
                processed_at = NULL, last_error = NULL, updated_at = :now
            WHERE id = :id AND status = 'failed'");
        $update->execute(['id' => $eventId, 'now' => $now]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Event changed while requeuing.');
        }
        $pdo->commit();
        echo "Event requeued.\n";
    } else {
        $pdo->rollBack();
    }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
