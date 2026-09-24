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
