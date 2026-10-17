<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\Mission;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Domain\ScutRelay;

final class UniverseStatsRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function forgottenMannyCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM mannies WHERE is_forgotten=1')->fetchColumn();
    }

    public function destroyedShipsByPlayer(): array
    {
        $stmt = $this->pdo->query(
            'SELECT players.id AS player_id, players.username, players.display_name,
                    players.others_ships_destroyed, players.others_motherships_destroyed
             FROM players
             WHERE (players.others_ships_destroyed > 0 OR players.others_motherships_destroyed > 0)
               AND EXISTS (
                   SELECT 1
                   FROM neumann_probes
                   WHERE neumann_probes.player_id = players.id
                     AND neumann_probes.exclude_from_stats = 0
               )
             ORDER BY players.id ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function missionStatusCounts(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT probe_missions.status, COUNT(*) AS mission_count
             FROM probe_missions
             WHERE probe_missions.status IN (:completed_status, :failed_status)
               AND EXISTS (
                   SELECT 1
                   FROM neumann_probes
                   WHERE neumann_probes.player_id = probe_missions.player_id
                     AND neumann_probes.exclude_from_stats = 0
               )
             GROUP BY probe_missions.status'
        );
        $stmt->execute([
            'completed_status' => Mission::STATUS_COMPLETED,
            'failed_status' => Mission::STATUS_FAILED,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function probePositions(): array
    {
        $stmt = $this->pdo->query('SELECT player_id, sector_x, sector_y, sector_z FROM neumann_probes WHERE exclude_from_stats = 0 ORDER BY id ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function visitedSectorCoordinates(): array
    {
        $stmt = $this->pdo->query(
            'SELECT visited_sectors.sector_x, visited_sectors.sector_y, visited_sectors.sector_z
             FROM visited_sectors
             INNER JOIN neumann_probes ON neumann_probes.player_id = visited_sectors.player_id
             WHERE neumann_probes.exclude_from_stats = 0
             GROUP BY visited_sectors.sector_x, visited_sectors.sector_y, visited_sectors.sector_z'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function othersOccupiedSectorCount(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT DISTINCT sector_x, sector_y, sector_z
                FROM others_ships
                WHERE destroyed_at IS NULL AND status NOT IN ('removed', 'transit')
            ) occupied_sectors"
        );
        return (int) $stmt->fetchColumn();
    }

    public function topVisitedPlayers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT players.id AS player_id, players.username, players.display_name,
                    COALESCE(NULLIF(players.display_name, \'\'), players.username, \'\') AS player_name,
                    COUNT(visited_sectors.player_id) AS visited_count
             FROM players
             LEFT JOIN (
                 SELECT DISTINCT player_id, sector_x, sector_y, sector_z
                 FROM visited_sectors
             ) visited_sectors ON visited_sectors.player_id = players.id
             WHERE EXISTS (
                 SELECT 1
                 FROM neumann_probes
                 WHERE neumann_probes.player_id = players.id
                   AND neumann_probes.exclude_from_stats = 0
             )
             GROUP BY players.id, players.username, players.display_name
             ORDER BY visited_count DESC, player_name ASC, players.id ASC
             LIMIT ' . 9
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function livingProbePositions(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT players.id AS player_id, players.username, players.display_name,
                    players.home_sector_x, players.home_sector_y, players.home_sector_z,
                    neumann_probes.id AS probe_id, neumann_probes.name AS probe_name,
                    neumann_probes.sector_x, neumann_probes.sector_y, neumann_probes.sector_z
             FROM players
             INNER JOIN neumann_probes ON neumann_probes.player_id = players.id
             WHERE neumann_probes.exclude_from_stats = 0
               AND neumann_probes.status <> :dead_status
             ORDER BY players.id ASC, neumann_probes.id ASC'
        );
        $stmt->execute(['dead_status' => ProbeStatus::Dead->value]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function scutNetworks(): array
    {
        $networkStmt = $this->pdo->query('SELECT id, name FROM scut_networks ORDER BY id ASC');
        return $networkStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeScutRelays(): array
    {
        $relayStmt = $this->pdo->query(
            "SELECT scut_relays.id, scut_relays.created_by_probe_id,
                    neumann_probes.id AS probe_id,
                    neumann_probes.player_id,
                    neumann_probes.exclude_from_stats AS probe_excluded,
                    players.username,
                    players.display_name
             FROM scut_relays
             LEFT JOIN neumann_probes ON neumann_probes.id = scut_relays.created_by_probe_id
             LEFT JOIN players ON players.id = neumann_probes.player_id
             WHERE scut_relays.status = '" . ScutRelay::STATUS_ON . "'
             ORDER BY scut_relays.id ASC"
        );
        return $relayStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function scutCoverage(): array
    {
        $stmt = $this->pdo->query(
            'SELECT scut_network_id, sector_x, sector_y, sector_z
             FROM scut_covered_sectors
             WHERE scut_network_id IS NOT NULL
             GROUP BY scut_network_id, sector_x, sector_y, sector_z
             ORDER BY scut_network_id ASC, sector_x ASC, sector_y ASC, sector_z ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function visitsInDiscoveryOrder(): array
    {
        $stmt = $this->pdo->query(
            "SELECT visited_sectors.sector_x, visited_sectors.sector_y, visited_sectors.sector_z,
                    visited_sectors.player_id, players.username, players.display_name
             FROM visited_sectors
             INNER JOIN players ON players.id = visited_sectors.player_id
             ORDER BY visited_sectors.sector_x ASC, visited_sectors.sector_y ASC, visited_sectors.sector_z ASC,
                      visited_sectors.first_visited_at ASC, visited_sectors.id ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function excludedPlayerIds(): array
    {
        $stmt = $this->pdo->query('SELECT player_id FROM neumann_probes WHERE exclude_from_stats = 1');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function detachedContainerCounts(): array
    {
        $stmt = $this->pdo->query(
            "SELECT mode, COUNT(*) AS container_count
             FROM detached_storage_containers
             WHERE mode IN ('drifting', 'hidden_on_asteroid', 'hidden_on_dormant_construct')
             GROUP BY mode"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
