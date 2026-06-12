<?php

declare(strict_types=1);

namespace VonNeumannGame\Auth;

use PDO;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;

final class AccountDeletionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly NeumannProbeRepository $probes,
        private readonly MannyRepository $mannies,
        private readonly ?SectorService $sectors = null,
    ) {}

    /**
     * @return array<string, int>
     */
    public function deletePlayer(Player $player, bool $dryRun = false): array
    {
        $probe = $this->probes->findByPlayerId($player->id);
        $probeId = $probe?->id;
        $probeMannies = $probeId === null ? [] : $this->mannies->findByProbeId($probeId);
        $detachedMannies = array_values(array_filter(
            $probeMannies,
            static fn(Manny $manny): bool => !$manny->isOnProbe() && $manny->sector !== null,
        ));

        $stats = $this->deletionStats($player->id, $probeId, count($detachedMannies));
        if ($dryRun) {
            return $stats;
        }

        foreach ($detachedMannies as $manny) {
            $this->registerAbandonedManny($manny);
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($detachedMannies as $manny) {
                $this->detachManny($manny);
            }

            if ($probeId !== null) {
                $this->deleteProbeData($probeId);
            }

            $this->deletePlayerData($player->id);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return $stats;
    }

    /**
     * @return array<string, int>
     */
    private function deletionStats(int $playerId, ?int $probeId, int $detachedMannyCount): array
    {
        $stats = [
            'players' => $this->count('SELECT COUNT(*) FROM players WHERE id = :player_id', ['player_id' => $playerId]),
            'authMethods' => $this->count('SELECT COUNT(*) FROM player_auth_methods WHERE player_id = :player_id', ['player_id' => $playerId]),
            'sessions' => $this->count('SELECT COUNT(*) FROM sessions WHERE player_id = :player_id', ['player_id' => $playerId]),
            'apiKeys' => $this->count('SELECT COUNT(*) FROM api_keys WHERE player_id = :player_id', ['player_id' => $playerId]),
            'visitedSectors' => $this->count('SELECT COUNT(*) FROM visited_sectors WHERE player_id = :player_id', ['player_id' => $playerId]),
            'probes' => $probeId === null ? 0 : 1,
            'manniesDetachedAsAbandoned' => $detachedMannyCount,
            'manniesDeleted' => 0,
            'probeItems' => 0,
            'storageContainers' => 0,
            'storageContainerResources' => 0,
            'probeMovements' => 0,
            'probeMessagesSent' => 0,
            'probeMessagesReceived' => 0,
            'probeDamageWarnings' => 0,
            'scheduledEvents' => 0,
        ];

        if ($probeId === null) {
            return $stats;
        }

        $stats['manniesDeleted'] = $this->count(
            'SELECT COUNT(*) FROM mannies WHERE probe_id = :probe_id AND NOT (location_type = :sector_location AND sector_x IS NOT NULL AND sector_y IS NOT NULL AND sector_z IS NOT NULL)',
            ['probe_id' => $probeId, 'sector_location' => Manny::LOCATION_SECTOR],
        );
        $stats['probeItems'] = $this->count('SELECT COUNT(*) FROM probe_items WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $stats['storageContainers'] = $this->count('SELECT COUNT(*) FROM storage_containers WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $stats['storageContainerResources'] = $this->count(
            'SELECT COUNT(*) FROM storage_container_resources WHERE container_id IN (SELECT id FROM storage_containers WHERE probe_id = :probe_id)',
            ['probe_id' => $probeId],
        );
        $stats['probeMovements'] = $this->count('SELECT COUNT(*) FROM probe_movements WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $stats['probeMessagesSent'] = $this->count('SELECT COUNT(*) FROM probe_messages WHERE sender_probe_id = :probe_id', ['probe_id' => $probeId]);
        $stats['probeMessagesReceived'] = $this->count('SELECT COUNT(*) FROM probe_messages WHERE recipient_probe_id = :probe_id', ['probe_id' => $probeId]);
        $stats['probeDamageWarnings'] = $this->count('SELECT COUNT(*) FROM probe_damage_warnings WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $stats['scheduledEvents'] = $this->countScheduledEvents($probeId);

        return $stats;
    }

    private function registerAbandonedManny(Manny $manny): void
    {
        if ($this->sectors === null || $manny->sector === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        $object = new SectorManny(
            SectorManny::objectIdForUid($manny->uid),
            $manny->name,
            $manny->uid,
            SectorManny::STATE_ABANDONED,
            $manny->cargoArray(),
            'Manny abandoned after its owner account was deleted.',
        );

        if (!$sector->replaceObject($object)) {
            $sector->addObject($object);
        }
        $this->sectors->saveSector($sector);
    }

    private function detachManny(Manny $manny): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mannies
             SET probe_id = NULL,
                 storage_container_id = NULL,
                 location_type = :location_type,
                 current_task = NULL,
                 task_started_at = NULL,
                 task_ends_at = NULL,
                 task_payload_json = :task_payload_json,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $manny->id,
            'location_type' => Manny::LOCATION_SECTOR,
            'task_payload_json' => '{}',
            'updated_at' => gmdate('c'),
        ]);
    }

    private function deleteProbeData(int $probeId): void
    {
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
        $this->execute('DELETE FROM probe_movements WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM probe_damage_warnings WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute(
            'DELETE FROM mannies WHERE probe_id = :probe_id',
            ['probe_id' => $probeId],
        );
        $this->execute(
            'DELETE FROM probe_messages WHERE sender_probe_id = :probe_id OR recipient_probe_id = :probe_id',
            ['probe_id' => $probeId],
        );
        $this->execute('DELETE FROM probe_items WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute(
            'DELETE FROM storage_container_resources
             WHERE container_id IN (SELECT id FROM storage_containers WHERE probe_id = :probe_id)',
            ['probe_id' => $probeId],
        );
        $this->execute('DELETE FROM storage_containers WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM neumann_probes WHERE id = :probe_id', ['probe_id' => $probeId]);
    }

    private function deletePlayerData(int $playerId): void
    {
        $params = ['player_id' => $playerId];
        $this->execute('DELETE FROM sessions WHERE player_id = :player_id', $params);
        $this->execute('DELETE FROM api_keys WHERE player_id = :player_id', $params);
        $this->execute('DELETE FROM visited_sectors WHERE player_id = :player_id', $params);
        $this->execute('DELETE FROM player_auth_methods WHERE player_id = :player_id', $params);
        $this->execute('DELETE FROM players WHERE id = :player_id', $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function count(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function countScheduledEvents(int $probeId): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM scheduled_events
             WHERE (entity_type = :probe_entity_type AND entity_id = :probe_id)
             OR (entity_type = :movement_entity_type AND entity_id IN (SELECT id FROM probe_movements WHERE probe_id = :movement_probe_id))
             OR (entity_type = :damage_warning_entity_type AND entity_id IN (SELECT id FROM probe_damage_warnings WHERE probe_id = :damage_warning_probe_id))',
            [
                'probe_entity_type' => 'probe',
                'movement_entity_type' => 'probe_movement',
                'damage_warning_entity_type' => 'probe_damage_warning',
                'probe_id' => $probeId,
                'movement_probe_id' => $probeId,
                'damage_warning_probe_id' => $probeId,
            ],
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function execute(string $sql, array $params): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
