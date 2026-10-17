<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Database\StorageTransaction;

final class ProbeReinstantiationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function transaction(callable $operation): mixed
    {
        return (new StorageTransaction($this->pdo))->run($operation);
    }

    public function operations(): ProbeOperationRepository { return new ProbeOperationRepository($this->pdo); }

    public function loss(int $probeId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_losses WHERE probe_id=?');
        $stmt->execute([$probeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function requestLoss(int $probeId, string $reason, callable $afterCommit, ?string $causalAt = null): void
    {
        $this->transaction(function () use ($probeId, $reason, $afterCommit, $causalAt): void {
            $sql = 'INSERT INTO probe_losses(probe_id,reason,causal_at,state,updated_at) VALUES(?,?,?,?,?)';
            $sql .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE probe_id=VALUES(probe_id)' : ' ON CONFLICT(probe_id) DO NOTHING';
            $this->pdo->prepare($sql)->execute([$probeId, $reason, $causalAt ?? gmdate('c'), 'requested', gmdate('c')]);
            $loss = $this->loss($probeId);
            if ($loss['scheduled_event_id'] === null && $loss['state'] !== 'completed') {
                $event = (new ScheduledEventRepository($this->pdo))->schedule('probe.loss', 'probe_loss', $probeId, gmdate('c'));
                $this->pdo->prepare('UPDATE probe_losses SET scheduled_event_id=? WHERE probe_id=?')->execute([$event->id, $probeId]);
            }
            StorageTransaction::afterCommit($this->pdo, $afterCommit);
        });
    }

    public function finalizeLoss(int $probeId, callable $finalize): mixed
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Loss finalization must own its transaction.'); }
        $operations = $this->operations();
        if (!$operations->close($probeId)) { $this->diagnoseLongDrain($probeId); return null; }
        return (new StorageTransaction($this->pdo, maxAttempts: 1, readCommitted: true))->run(function () use ($probeId, $operations, $finalize): mixed {
            if (!$operations->tryLock('probe_losses', 'probe_id', $probeId)) { return null; }
            $loss = $this->loss($probeId);
            if ($loss['state'] === 'completed') { return $loss['replacement_probe_id']; }
            $this->pdo->prepare("UPDATE probe_losses SET state='draining',updated_at=? WHERE probe_id=?")->execute([gmdate('c'), $probeId]);
            if (!$operations->drained($probeId)) { $this->diagnoseLongDrain($probeId); return null; }
            // Serialize losses within a player's fleet before choosing the surviving instance.
            $stmt = $this->pdo->prepare('SELECT player_id FROM neumann_probes WHERE id=?');
            $stmt->execute([$probeId]);
            $playerId = $stmt->fetchColumn();
            if ($playerId !== false) {
                $this->pdo->prepare('UPDATE players SET updated_at=updated_at WHERE id=?')->execute([$playerId]);
            }
            $this->pdo->prepare('UPDATE neumann_probes SET updated_at=updated_at WHERE id=?')->execute([$probeId]);
            $result = $operations->internal([$probeId], fn() => $finalize($loss));
            $this->pdo->prepare("UPDATE probe_losses SET state='completed',replacement_probe_id=?,updated_at=? WHERE probe_id=?")->execute([$result, gmdate('c'), $probeId]);
            if ($loss['scheduled_event_id'] !== null) { (new ScheduledEventRepository($this->pdo))->markDoneById((int) $loss['scheduled_event_id']); }
            return $result;
        });
    }

    public function completed(int $probeId): bool { return ($this->loss($probeId)['state'] ?? null) === 'completed'; }

    private function diagnoseLongDrain(int $probeId): void
    {
        $loss = $this->loss($probeId);
        if ($loss === null || time() - strtotime($loss['causal_at']) < 900) { return; }
        $stmt = $this->pdo->prepare('SELECT token,expires_at FROM probe_operations WHERE probe_id=? ORDER BY token');
        $stmt->execute([$probeId]);
        error_log('Probe loss draining for over 15 minutes: ' . json_encode(['probeId' => $probeId, 'state' => $loss['state'], 'operations' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'legacyGuardMayBeHeld' => true], JSON_THROW_ON_ERROR));
    }

    public function recordReassignment(int $previousId, int $replacementId): void
    {
        $this->execute('UPDATE probe_losses SET replacement_probe_id=?,updated_at=? WHERE probe_id=? AND state=?', [$replacementId, gmdate('c'), $previousId, 'completed']);
    }

    public function incomingMannyIds(int $probeId): array
    {
        // Destruction is rare: enumerate current task payloads here, never in harvests.
        $stmt = $this->pdo->prepare('SELECT manny_id FROM manny_tasks WHERE target_probe_id=? OR target_object_id=? ORDER BY manny_id');
        $stmt->execute([$probeId, (string) $probeId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $manny = (new MannyRepository($this->pdo))->findById((int) $id);
            if ($manny?->currentTask !== null && ScheduledEventRepository::targetProbeId($manny->taskPayload) === $probeId) { $ids[] = (int) $id; }
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public function cancelDeferredSchedules(int $probeId): void
    {
        $this->execute("UPDATE scheduled_events SET status='done',locked_by=NULL,locked_at=NULL,processed_at=?,updated_at=? WHERE
            (entity_type='probe' AND entity_id=?) OR
            (entity_type='probe_movement' AND entity_id IN (SELECT id FROM probe_movements WHERE probe_id=?)) OR
            (entity_type='probe_damage_warning' AND entity_id IN (SELECT id FROM probe_damage_warnings WHERE probe_id=?)) OR
            (entity_type='manny' AND entity_id IN (SELECT id FROM mannies WHERE probe_id=?))", [gmdate('c'), gmdate('c'), $probeId, $probeId, $probeId, $probeId]);
        $this->execute("UPDATE missile_launches SET status='failed',result='carrier_destroyed',scheduled_event_id=NULL,updated_at=? WHERE probe_id=? AND status IN ('preparing','queued')", [gmdate('c'), $probeId]);
    }

    public function latestMovementAttemptForProbe(int $probeId): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT origin_x, origin_y, origin_z, target_x, target_y, target_z
             FROM probe_movements
             WHERE probe_id = :probe_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['probe_id' => $probeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function deleteProbeData(int $probeId): void
    {
        $guard = $this->pdo->prepare('SELECT closed FROM probe_operation_gates WHERE probe_id=?');
        $guard->execute([$probeId]);
        $active = $this->pdo->prepare('SELECT COUNT(*) FROM probe_operations WHERE probe_id=?');
        $active->execute([$probeId]);
        if (!$this->pdo->inTransaction() || (int) $guard->fetchColumn() !== 1 || (int) $active->fetchColumn() !== 0) {
            throw new \LogicException('Physical probe deletion requires closed, drained admissions and an owning transaction.');
        }
        $this->execute(
            "UPDATE missile_launches
             SET status = 'failed', result = 'carrier_destroyed', scheduled_event_id = NULL, updated_at = :now
             WHERE probe_id = :probe_id AND status IN ('preparing', 'queued')",
            ['probe_id' => $probeId, 'now' => gmdate('c')],
        );
        // Preserve missile history and projectiles already in flight after their carrier is gone.
        $this->execute(
            'UPDATE missile_launches SET probe_id = NULL, manny_id = NULL, probe_item_id = NULL WHERE probe_id = :probe_id',
            ['probe_id' => $probeId],
        );
        $this->execute('DELETE FROM probe_logbook_pages WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM visited_sectors WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute(
            'DELETE FROM scheduled_events
             WHERE entity_type = :entity_type
             AND entity_id IN (SELECT id FROM probe_movements WHERE probe_id = :probe_id)',
            ['entity_type' => 'probe_movement', 'probe_id' => $probeId],
        );
        $this->execute(
            'DELETE FROM scheduled_events WHERE entity_type = :entity_type AND entity_id = :probe_id',
            ['entity_type' => 'probe', 'probe_id' => $probeId],
        );
        $this->execute(
            'DELETE FROM scheduled_events
             WHERE entity_type = :entity_type
             AND entity_id IN (SELECT id FROM probe_damage_warnings WHERE probe_id = :probe_id)',
            ['entity_type' => 'probe_damage_warning', 'probe_id' => $probeId],
        );
        $this->execute(
            'DELETE FROM scheduled_events
             WHERE entity_type = :entity_type
             AND entity_id IN (SELECT id FROM mannies WHERE probe_id = :probe_id)',
            ['entity_type' => 'manny', 'probe_id' => $probeId],
        );
        $this->execute('DELETE FROM probe_damage_warnings WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM probe_movements WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM mannies WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('UPDATE probe_messages SET sender_probe_id = NULL WHERE sender_probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('UPDATE probe_messages SET recipient_probe_id = NULL WHERE recipient_probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM probe_items WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute(
            'DELETE FROM storage_container_resources
             WHERE container_id IN (SELECT id FROM storage_containers WHERE probe_id = :probe_id)',
            ['probe_id' => $probeId],
        );
        $this->execute('DELETE FROM storage_containers WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM probe_improvement_installations WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM neumann_probes WHERE id = :probe_id', ['probe_id' => $probeId]);
    }

    public function detachManny(Manny $manny): void
    {
        $this->execute(
            'DELETE FROM scheduled_events WHERE entity_type = :entity_type AND entity_id = :manny_id',
            ['entity_type' => 'manny', 'manny_id' => $manny->id],
        );
        $this->execute(
            'UPDATE mannies
             SET probe_id = NULL,
                 is_forgotten = 0,
                 reserved_cargo_type = NULL,
                 reserved_cargo_space = 0,
                 reserved_storage_container_id = NULL,
                 storage_container_id = NULL,
                 location_type = :location_type,
                 current_task = NULL,
                 task_started_at = NULL,
                 task_ends_at = NULL,
                 task_scheduled_event_id = NULL,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $manny->id,
                'location_type' => Manny::LOCATION_SECTOR,
                'updated_at' => gmdate('c'),
            ],
        );
    }

    public function deleteVisitedSectors(int $playerId): void
    {
        $this->execute('DELETE FROM visited_sectors WHERE player_id = :player_id', ['player_id' => $playerId]);
    }

    private function execute(string $sql, array $params): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
