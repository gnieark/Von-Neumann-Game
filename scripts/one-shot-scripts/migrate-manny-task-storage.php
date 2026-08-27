<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Database\SchemaInitializer;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = 'config/database.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-manny-task-storage.php [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$root = dirname(__DIR__, 2);
$pdo = (new AppFactory($root))->pdo($databaseConfig, initializeSchema: false);
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

$hasLegacyColumn = $driver === 'sqlite'
    ? in_array('task_payload_json', array_map(static fn(array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(mannies)')->fetchAll(PDO::FETCH_ASSOC)), true)
    : (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mannies' AND COLUMN_NAME = 'task_payload_json'")->fetchColumn() === 1;
if (!$hasLegacyColumn) {
    fwrite(STDERR, "mannies.task_payload_json is absent; migration has already run.\n");
    exit(2);
}

(new SchemaInitializer($driver))->initialize($pdo);

$rows = $pdo->query(
    "SELECT m.id AS manny_id, m.current_task, m.task_scheduled_event_id, m.task_payload_json, se.payload_json
     FROM mannies m
     LEFT JOIN scheduled_events se ON se.id = m.task_scheduled_event_id
     WHERE m.task_scheduled_event_id IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);

$pdo->beginTransaction();
try {
    $insertTask = $pdo->prepare(
        'INSERT INTO manny_tasks
         (manny_id, scheduled_event_id, task_type, recipe, crafting_run_id, resource_type, target_amount, extracted_amount, object_id, target_object_id, target_container_id, source_container_id, destination_container_id, target_probe_id, relay_id, improvement, created_at, updated_at)
         VALUES (:manny_id, :event_id, :task_type, :recipe, :run_id, :resource_type, :target_amount, :extracted_amount, :object_id, :target_object_id, :target_container_id, :source_container_id, :destination_container_id, :target_probe_id, :relay_id, :improvement, :created_at, :updated_at)'
    );
    $insertItem = $pdo->prepare(
        'INSERT INTO manny_task_consumed_items
         (manny_task_id, sort_order, uid, type, name, container_space, storage_container_id, metadata_json)
         VALUES (:task_id, :sort_order, :uid, :type, :name, :space, :container_id, :metadata)'
    );
    $updateEvent = $pdo->prepare('UPDATE scheduled_events SET payload_json = :payload WHERE id = :id');
    $migrated = 0;
    $consumed = 0;
    foreach ($rows as $row) {
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = json_decode((string) ($row['task_payload_json'] ?? '{}'), true);
        }
        $payload = is_array($payload) ? $payload : [];
        $now = gmdate('c');
        $insertTask->execute([
            'manny_id'=>(int)$row['manny_id'], 'event_id'=>(int)$row['task_scheduled_event_id'],
            'task_type'=>(string)($row['current_task'] ?? $payload['lastTask'] ?? 'completed'),
            'recipe'=>$payload['recipe']??null, 'run_id'=>$payload['craftingRunId']??null,
            'resource_type'=>$payload['resourceType']??null, 'target_amount'=>$payload['targetAmount']??null,
            'extracted_amount'=>$payload['extractedAmount']??null, 'object_id'=>$payload['objectId']??null,
            'target_object_id'=>$payload['targetObjectId']??null,
            'target_container_id'=>is_array($payload['targetContainer']??null) ? ($payload['targetContainer']['id']??null) : ($payload['targetContainerId']??null),
            'source_container_id'=>$payload['fromContainerId']??null, 'destination_container_id'=>$payload['toContainerId']??null,
            'target_probe_id'=>$payload['targetProbeId']??null, 'relay_id'=>$payload['relayId']??null,
            'improvement'=>$payload['improvement']??null, 'created_at'=>$now, 'updated_at'=>$now,
        ]);
        $taskId = (int) $pdo->lastInsertId();
        foreach (array_values(is_array($payload['consumedItems'] ?? null) ? $payload['consumedItems'] : []) as $order => $item) {
            if (!is_array($item)) continue;
            $insertItem->execute([
                'task_id'=>$taskId, 'sort_order'=>$order, 'uid'=>(string)($item['uid']??''),
                'type'=>(string)($item['type']??''), 'name'=>(string)($item['name']??''),
                'space'=>(float)($item['containerSpace']??0), 'container_id'=>$item['storageContainerId']??null,
                'metadata'=>json_encode(is_array($item['metadata']??null)?$item['metadata']:[], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ]);
            $consumed++;
        }
        foreach (['recipe','craftingRunId','resourceType','targetAmount','extractedAmount','objectId','targetObjectId','targetContainerId','fromContainerId','toContainerId','targetProbeId','relayId','improvement','consumedItems'] as $key) {
            unset($payload[$key]);
        }
        $updateEvent->execute(['id'=>(int)$row['task_scheduled_event_id'], 'payload'=>json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        $migrated++;
    }
    $pdo->commit();
    $pdo->exec('ALTER TABLE mannies DROP COLUMN task_payload_json');
    echo "Migrated Manny tasks: {$migrated}; consumed items: {$consumed}; dropped mannies.task_payload_json.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
