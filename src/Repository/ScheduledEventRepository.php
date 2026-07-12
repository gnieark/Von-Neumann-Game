<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ScheduledEvent;

final class ScheduledEventRepository
{
    public const UNSCHEDULED_RUN_AT = '9999-12-31T00:00:00+00:00';

    public function __construct(private readonly PDO $pdo) {}

    public function schedule(
        string $type,
        string $entityType,
        int $entityId,
        string $runAt,
        array $payload = [],
    ): ScheduledEvent {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO scheduled_events
             (type, entity_type, entity_id, run_at, status, payload_json, attempts, locked_at, processed_at, last_error, created_at, updated_at)
             VALUES (:type, :entity_type, :entity_id, :run_at, :status, :payload_json, 0, NULL, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'run_at' => $runAt,
            'status' => 'pending',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Scheduled event creation failed.');
    }

    /**
     * @return array<ScheduledEvent>
     */
    public function findDuePending(string $now, int $limit): array
    {
        $limit = max(1, $limit);
        $stmt = $this->pdo->query(
            "SELECT * FROM scheduled_events
             WHERE status = 'pending' AND run_at <= " . $this->pdo->quote($now) . "
             ORDER BY run_at ASC, id ASC
             LIMIT $limit"
        );

        return array_map(fn(array $row): ScheduledEvent => $this->hydrate($row), $stmt->fetchAll());
    }

    public function claim(ScheduledEvent $event): ?ScheduledEvent
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events
             SET status = 'running', locked_at = :locked_at, attempts = attempts + 1, updated_at = :updated_at
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([
            'id' => $event->id,
            'locked_at' => $now,
            'updated_at' => $now,
        ]);

        if ($stmt->rowCount() !== 1) {
            return null;
        }

        return $this->findById($event->id);
    }

    public function markDone(ScheduledEvent $event): void
    {
        $this->markDoneById($event->id);
    }

    public function markDoneById(int $id): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events
             SET status = 'done', processed_at = :processed_at, locked_at = NULL, last_error = NULL, updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'processed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updateRunAtAndPayload(int $id, string $runAt, array $payload): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events
             SET run_at = :run_at, payload_json = :payload_json, updated_at = :updated_at
             WHERE id = :id AND status IN ('pending', 'running')"
        );
        $stmt->execute([
            'id' => $id,
            'run_at' => $runAt,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]);
    }

    public function release(ScheduledEvent $event, string $runAt, array $payload): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events
             SET status = 'pending', run_at = :run_at, payload_json = :payload_json, locked_at = NULL, updated_at = :updated_at
             WHERE id = :id AND status = 'running'"
        );
        $stmt->execute([
            'id' => $event->id,
            'run_at' => $runAt,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]);
    }

    public function markFailed(ScheduledEvent $event, \Throwable $error): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events
             SET status = 'failed', processed_at = :processed_at, locked_at = NULL, last_error = :last_error, updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $event->id,
            'processed_at' => $now,
            'last_error' => substr($error->getMessage(), 0, 1000),
            'updated_at' => $now,
        ]);
    }

    public function cancelPending(string $type, string $entityType, int $entityId): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events
             SET status = 'cancelled', processed_at = :processed_at, locked_at = NULL, updated_at = :updated_at
             WHERE type = :type AND entity_type = :entity_type AND entity_id = :entity_id AND status = 'pending'"
        );
        $stmt->execute([
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'processed_at' => $now,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount();
    }

    public function countByStatus(string $status): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM scheduled_events WHERE status = :status');
        $stmt->execute(['status' => $status]);

        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?ScheduledEvent
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scheduled_events WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findPendingByTypeAndEntity(string $type, string $entityType, int $entityId): ?ScheduledEvent
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM scheduled_events
             WHERE type = :type AND entity_type = :entity_type AND entity_id = :entity_id AND status = 'pending'
             ORDER BY run_at ASC, id ASC LIMIT 1"
        );
        $stmt->execute([
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    private function hydrate(array $row): ScheduledEvent
    {
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            $payload = [];
        }

        return new ScheduledEvent(
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['entity_type'],
            (int) $row['entity_id'],
            (string) $row['run_at'],
            (string) $row['status'],
            $payload,
            (int) $row['attempts'],
            $row['locked_at'] !== null ? (string) $row['locked_at'] : null,
            $row['processed_at'] !== null ? (string) $row['processed_at'] : null,
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
