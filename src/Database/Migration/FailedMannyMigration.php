<?php
declare(strict_types=1);
namespace VonNeumannGame\Database\Migration;

use PDO;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeOperationRepository;
use VonNeumannGame\Service\MannyService;

final class FailedMannyMigration
{
    public function __construct(private readonly PDO $pdo, private readonly MannyService $service) {}

    public function apply(string $migrationId, array $events): array
    {
        $results = [];
        foreach ($events as $snapshot) {
            $stmt = $this->pdo->prepare('SELECT result_json FROM storage_migration_recalls WHERE event_id=? AND migration_id=?');
            $stmt->execute([$snapshot['id'], $migrationId]); $saved = $stmt->fetchColumn();
            if ($saved !== false) { $results[] = json_decode($saved, true, 512, JSON_THROW_ON_ERROR); continue; }
            $mannies = new MannyRepository($this->pdo);
            $candidate = $mannies->findById((int) $snapshot['entity_id']);
            $operation = function () use ($snapshot, $migrationId, $mannies): array {
                $manny = $mannies->lockForTask((int) $snapshot['entity_id']);
                $stmt = $this->pdo->prepare('SELECT * FROM scheduled_events WHERE id=?' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                $stmt->execute([$snapshot['id']]); $event = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($event === false || $event['status'] !== 'failed' || $event['type'] !== 'manny.task' || $event['entity_type'] !== 'manny' || (int) $event['entity_id'] !== (int) $snapshot['entity_id']) { throw new \RuntimeException('Failed event changed outside maintenance: ' . $snapshot['id']); }
                $result = ['eventId' => (int) $event['id'], 'mannyId' => (int) $event['entity_id'], 'taskBefore' => $manny?->currentTask,
                    'eventBefore' => $manny?->taskScheduledEventId, 'result' => 'missing_manny'];
                if ($manny !== null) {
                    if ($manny->taskScheduledEventId !== (int) $event['id']) { $result['result'] = 'superseded_task'; }
                    elseif ($manny->currentTask === null) { $result['result'] = 'already_idle'; }
                    else {
                        $probe = $manny->probeId === null ? null : (new NeumannProbeRepository($this->pdo))->findById($manny->probeId);
                        if ($probe === null) { throw new \RuntimeException('Failed Manny has no carrier; resolve its loss before migration: ' . $manny->uid); }
                        $manny = $this->service->recallFailedTask($probe, $manny, (int) $event['id']);
                        $result['result'] = 'recalled'; $result['taskAfter'] = $manny->currentTask; $result['returnEventId'] = $manny->taskScheduledEventId;
                    }
                }
                $this->pdo->prepare("UPDATE scheduled_events SET status='cancelled',locked_at=NULL,locked_by=NULL,processed_at=? WHERE id=? AND status='failed'")->execute([gmdate('c'), $event['id']]);
                $after = $this->pdo->prepare('SELECT * FROM mannies WHERE id=?');
                $after->execute([$event['entity_id']]);
                $result['mannyAfter'] = $after->fetch(PDO::FETCH_ASSOC) ?: null;
                $after = $this->pdo->prepare('SELECT * FROM manny_tasks WHERE manny_id=?'); $after->execute([$event['entity_id']]);
                $result['taskProjectionAfter'] = $after->fetch(PDO::FETCH_ASSOC) ?: null;
                $after = $this->pdo->prepare('SELECT * FROM scheduled_events WHERE id=?'); $after->execute([$result['mannyAfter']['task_scheduled_event_id'] ?? null]);
                $result['eventAfter'] = $after->fetch(PDO::FETCH_ASSOC) ?: null;
                $this->pdo->prepare('INSERT INTO storage_migration_recalls(event_id,migration_id,result_json) VALUES(?,?,?)')->execute([$event['id'], $migrationId, json_encode($result, JSON_THROW_ON_ERROR)]);
                return $result;
            };
            $results[] = $candidate?->probeId !== null && $candidate->currentTask !== null && $candidate->taskScheduledEventId === (int) $snapshot['id']
                ? (new ProbeOperationRepository($this->pdo))->run([$candidate->probeId], $operation)
                : (new StorageTransaction($this->pdo, maxAttempts: 1))->run($operation);
        }
        return $results;
    }
}
