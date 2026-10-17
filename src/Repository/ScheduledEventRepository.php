<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ScheduledEvent;

final class ScheduledEventRepository
{
    public const UNSCHEDULED_RUN_AT = '9999-12-31T00:00:00+00:00';

    private readonly string $workerId;

    public function __construct(private readonly PDO $pdo, ?string $workerId = null)
    {
        $this->workerId = $workerId ?? ('worker-' . bin2hex(random_bytes(12)));
    }

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
             (type, entity_type, entity_id, run_at, status, payload_json, attempts, locked_at, locked_by, processed_at, last_error, created_at, updated_at)
             VALUES (:type, :entity_type, :entity_id, :run_at, :status, :payload_json, 0, NULL, NULL, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'run_at' => $runAt,
            'status' => 'pending',
            'payload_json' => json_encode($this->persistedPayload($type, $payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
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
            'SELECT se.* ' . $this->pendingFromSql() . ' AND se.run_at <= ' . $this->pdo->quote($now)
            . " ORDER BY CASE WHEN se.type='probe.loss' THEN 0 ELSE 1 END, se.run_at, se.id LIMIT $limit"
        );
        return array_map(fn(array $row): ScheduledEvent => $this->hydrate($row), $stmt->fetchAll());
    }

    public function findNextPendingRunAt(): ?string
    {
        $value = $this->pdo->query('SELECT se.run_at ' . $this->pendingFromSql() . ' ORDER BY se.run_at,se.id LIMIT 1')->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    /** Selection avoids starving other probes during a drain; admission remains authoritative. */
    private function pendingFromSql(): string
    {
        $mode = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? "JSON_UNQUOTE(JSON_EXTRACT(se.payload_json,'$.mode'))" : "JSON_EXTRACT(se.payload_json,'$.mode')";
        $integer = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'UNSIGNED' : 'INTEGER';
        return "FROM scheduled_events se
            LEFT JOIN mannies m ON se.entity_type='manny' AND m.id=se.entity_id
            LEFT JOIN manny_tasks mt ON mt.scheduled_event_id=se.id AND mt.manny_id=m.id
            LEFT JOIN probe_movements pm ON se.entity_type='probe_movement' AND pm.id=se.entity_id
            LEFT JOIN probe_damage_warnings pw ON se.entity_type='probe_damage_warning' AND pw.id=se.entity_id
            LEFT JOIN probe_operation_gates actor_gate ON actor_gate.probe_id=COALESCE(m.probe_id,pm.probe_id,pw.probe_id,CASE WHEN se.entity_type='probe' THEN se.entity_id END)
            LEFT JOIN probe_operation_gates target_gate ON target_gate.probe_id=COALESCE(mt.target_probe_id,CASE WHEN $mode='attach_to_probe' THEN CAST(mt.target_object_id AS $integer) END)
            WHERE se.status='pending' AND COALESCE(actor_gate.closed,0)=0 AND COALESCE(target_gate.closed,0)=0";
    }

    public function recoverExpiredAdmissions(): int
    {
        return (new ProbeOperationRepository($this->pdo))->recoverExpired();
    }

    /** Probe actor plus receiving probe, never the initiator of an in-flight projectile. */
    public function probeIdsFor(ScheduledEvent $event): array
    {
        $sql = match ($event->entityType) {
            'probe' => 'SELECT id FROM neumann_probes WHERE id=?',
            'probe_movement' => 'SELECT probe_id FROM probe_movements WHERE id=?',
            'probe_damage_warning' => 'SELECT probe_id FROM probe_damage_warnings WHERE id=?',
            'manny' => 'SELECT probe_id FROM mannies WHERE id=?',
            default => null,
        };
        if ($sql === null) { return []; }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$event->entityId]);
        $id = $stmt->fetchColumn();
        $ids = $id ? [(int) $id] : [];
        $payload = $event->entityType === 'manny' ? ((new MannyRepository($this->pdo))->findById($event->entityId)?->taskPayload ?? []) : $event->payload;
        $target = self::targetProbeId($payload);
        if (is_numeric($target) && (int) $target > 0) { $ids[] = (int) $target; }
        sort($ids, SORT_NUMERIC);
        return array_values(array_unique($ids));
    }

    public static function targetProbeId(array $payload): ?int
    {
        $target = $payload['targetProbeId'] ?? (($payload['mode'] ?? null) === 'attach_to_probe' ? ($payload['targetObjectId'] ?? null) : null);
        return is_numeric($target) && (int) $target > 0 ? (int) $target : null;
    }

    public function withAdmission(ScheduledEvent $event, callable $operation): mixed
    {
        // Movement/storage-break handlers prepare sectors before owning their protected transaction.
        if ($event->entityType !== 'manny') { return $operation(); }
        $ids = $this->probeIdsFor($event);
        return $ids === [] ? $operation() : (new ProbeOperationRepository($this->pdo))->run($ids, $operation);
    }

    public function claim(ScheduledEvent $event): ?ScheduledEvent
    {
        return (new \VonNeumannGame\Database\StorageTransaction($this->pdo, maxAttempts: 1))->run(function () use ($event): ?ScheduledEvent {
            (new ProbeOperationRepository($this->pdo))->protect($this->probeIdsFor($event));
            $now = gmdate('c');
            $stmt = $this->pdo->prepare(
                "UPDATE scheduled_events
                 SET status = 'running', locked_at = :locked_at, locked_by = :locked_by, attempts = attempts + 1, updated_at = :updated_at
                 WHERE id = :id AND status = 'pending'"
            );
            $stmt->execute([
                'id' => $event->id,
                'locked_at' => $now,
                'locked_by' => $this->workerId,
                'updated_at' => $now,
            ]);

            if ($stmt->rowCount() !== 1) {
                return null;
            }
            $event->status = 'running';
            $event->lockedAt = $now;
            $event->lockedBy = $this->workerId;
            $event->attempts++;
            $event->updatedAt = $now;

            return $event;
        });
    }

    public function markDone(ScheduledEvent $event): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events SET status = 'done', processed_at = :processed_at,
             locked_at = NULL, locked_by = NULL, last_error = NULL, updated_at = :updated_at
             WHERE id = :id AND status = 'running' AND locked_by = :locked_by"
        );
        $stmt->execute(['id' => $event->id, 'locked_by' => $this->workerId, 'processed_at' => $now, 'updated_at' => $now]);
        if ($stmt->rowCount() !== 1) {
            $current = $this->findById($event->id);
            // Some terminal handlers complete the linked event themselves or
            // delete it through an entity cascade. Both outcomes are idempotent.
            if ($current !== null && $current->status !== 'done') {
                $this->throwLeaseLost($event, 'complete');
            }
        }
    }

    /** Fence a claimed execution for the complete resource/task transaction. */
    public function lockOwned(ScheduledEvent $event): void
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Event fencing requires a transaction.'); }
        $statement = $this->pdo->prepare('SELECT status,locked_by,attempts FROM scheduled_events WHERE id=?'
            . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->execute([$event->id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'running' || $row['locked_by'] !== $this->workerId
            || $event->lockedBy !== $this->workerId || (int) $row['attempts'] !== $event->attempts) {
            $this->throwLeaseLost($event, 'execute');
        }
    }

    public function markDoneById(int $id): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events
             SET status = 'done', processed_at = :processed_at, locked_at = NULL, locked_by = NULL, last_error = NULL, updated_at = :updated_at
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
            'payload_json' => json_encode($this->persistedPayload('manny.task', $payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]);
    }

    public function release(ScheduledEvent $event, string $runAt, array $payload): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events
             SET status = 'pending', run_at = :run_at, payload_json = :payload_json, locked_at = NULL, locked_by = NULL, updated_at = :updated_at
             WHERE id = :id AND status = 'running' AND locked_by = :locked_by"
        );
        $stmt->execute([
            'id' => $event->id,
            'locked_by' => $this->workerId,
            'run_at' => $runAt,
            'payload_json' => json_encode($this->persistedPayload($event->type, $payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]);
        $this->assertOwnedTransition($stmt->rowCount(), $event, 'release');
    }

    public function markFailed(ScheduledEvent $event, \Throwable $error): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events
             SET status = 'failed', processed_at = :processed_at, locked_at = NULL, locked_by = NULL, last_error = :last_error, updated_at = :updated_at
             WHERE id = :id AND status = 'running' AND locked_by = :locked_by"
        );
        $stmt->execute([
            'id' => $event->id,
            'locked_by' => $this->workerId,
            'processed_at' => $now,
            'last_error' => substr($error->getMessage(), 0, 1000),
            'updated_at' => $now,
        ]);
        $this->assertOwnedTransition($stmt->rowCount(), $event, 'fail');
    }

    public function cancelPending(string $type, string $entityType, int $entityId): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events
             SET status = 'cancelled', processed_at = :processed_at, locked_at = NULL, locked_by = NULL, updated_at = :updated_at
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

    public function renewLease(ScheduledEvent $event): bool
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events SET locked_at = :locked_at, updated_at = :updated_at
             WHERE id = :id AND status = 'running' AND locked_by = :locked_by"
        );
        $stmt->execute(['id' => $event->id, 'locked_by' => $this->workerId, 'locked_at' => $now, 'updated_at' => $now]);

        if ($stmt->rowCount() === 1) {
            return true;
        }

        // MariaDB reports zero affected rows when locked_at and updated_at already
        // contain the current second. Verify ownership instead of treating that
        // harmless no-op as a lost lease.
        $check = $this->pdo->prepare(
            "SELECT COUNT(*) FROM scheduled_events
             WHERE id = :id AND status = 'running' AND locked_by = :locked_by"
        );
        $check->execute(['id' => $event->id, 'locked_by' => $this->workerId]);

        return (int) $check->fetchColumn() === 1;
    }

    public function recoverExpiredLeases(string $expiredBefore): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_events
             SET status = 'pending', locked_at = NULL, locked_by = NULL, processed_at = NULL,
                 last_error = :last_error, updated_at = :updated_at
             WHERE status = 'running' AND (locked_at IS NULL OR locked_at < :expired_before)"
        );
        $stmt->execute([
            'expired_before' => $expiredBefore,
            'last_error' => 'Recovered automatically after scheduler worker lease expired.',
            'updated_at' => $now,
        ]);

        return $stmt->rowCount();
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
            $row['locked_by'] !== null ? (string) $row['locked_by'] : null,
            $row['processed_at'] !== null ? (string) $row['processed_at'] : null,
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /** @return array<string, mixed> */
    private function persistedPayload(string $type, array $payload): array
    {
        if ($type !== 'manny.task') {
            return $payload;
        }
        foreach (['recipe','craftingRunId','resourceType','targetAmount','extractedAmount','objectId','targetObjectId','targetContainerId','fromContainerId','toContainerId','targetProbeId','relayId','improvement','consumedItems'] as $key) {
            unset($payload[$key]);
        }
        return $payload;
    }

    private function assertOwnedTransition(int $affectedRows, ScheduledEvent $event, string $action): void
    {
        if ($affectedRows === 1) {
            return;
        }

        $this->throwLeaseLost($event, $action);
    }

    private function throwLeaseLost(ScheduledEvent $event, string $action): never
    {
        throw new ScheduledEventLeaseLostException(sprintf(
            'Cannot %s scheduled event %d: worker lease is no longer owned.',
            $action,
            $event->id,
        ));
    }
}
