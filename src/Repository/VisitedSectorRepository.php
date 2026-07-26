<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\VisitedSector;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;

final class VisitedSectorRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function markVisited(Player $player, NeumannProbe $probe, SectorCoordinates $coordinates): VisitedSector
    {
        return $this->markVisitedByProbe($player->id, $probe->id, $coordinates);
    }

    public function markVisitedByProbe(int $playerId, int $probeId, SectorCoordinates $coordinates): VisitedSector
    {
        $existing = $this->getVisitedSectorByProbeId($probeId, $coordinates);
        $now = gmdate('c');

        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE visited_sectors SET last_visited_at = :last_visited_at, visit_count = visit_count + 1 WHERE id = :id'
            );
            $stmt->execute(['id' => $existing->id, 'last_visited_at' => $now]);

            return $this->getVisitedSectorByProbeId($probeId, $coordinates) ?? $existing;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO visited_sectors (player_id, probe_id, sector_x, sector_y, sector_z, first_visited_at, last_visited_at, visit_count)
             VALUES (:player_id, :probe_id, :x, :y, :z, :first_visited_at, :last_visited_at, 1)'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'probe_id' => $probeId,
            'x' => $coordinates->getX(),
            'y' => $coordinates->getY(),
            'z' => $coordinates->getZ(),
            'first_visited_at' => $now,
            'last_visited_at' => $now,
        ]);

        return $this->getVisitedSectorByProbeId($probeId, $coordinates) ?? throw new \RuntimeException('Unable to mark sector visited.');
    }

    public function hasVisited(Player $player, SectorCoordinates $coordinates): bool
    {
        return $this->getVisitedSector($player, $coordinates) !== null;
    }

    public function countVisited(Player $player): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM (
                 SELECT 1
                 FROM visited_sectors
                 WHERE player_id = :player_id
                 GROUP BY sector_x, sector_y, sector_z
             ) visited_by_player'
        );
        $stmt->execute(['player_id' => $player->id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<int> $networkIds
     * @return array<int>
     */
    public function knownScutNetworkIds(Player $player, array $networkIds): array
    {
        $networkIds = array_values(array_unique(array_map('intval', $networkIds)));
        if ($networkIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($networkIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT coverage.scut_network_id
             FROM visited_sectors visited
             INNER JOIN scut_covered_sectors coverage
                ON coverage.sector_x = visited.sector_x
               AND coverage.sector_y = visited.sector_y
               AND coverage.sector_z = visited.sector_z
             WHERE visited.player_id = ?
               AND coverage.scut_network_id IN (' . $placeholders . ')
             ORDER BY coverage.scut_network_id ASC'
        );
        $stmt->execute([$player->id, ...$networkIds]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function getVisitedSector(Player $player, SectorCoordinates $coordinates): ?VisitedSector
    {
        return $this->getVisitedSectorByPlayerId($player->id, $coordinates);
    }

    public function getVisitedSectorByPlayerId(int $playerId, SectorCoordinates $coordinates): ?VisitedSector
    {
        $stmt = $this->pdo->prepare(
            'SELECT MIN(id) AS id, player_id, MIN(probe_id) AS probe_id,
                    sector_x, sector_y, sector_z,
                    MIN(first_visited_at) AS first_visited_at,
                    MAX(last_visited_at) AS last_visited_at,
                    SUM(visit_count) AS visit_count
             FROM visited_sectors
             WHERE player_id = :player_id AND sector_x = :x AND sector_y = :y AND sector_z = :z
             GROUP BY player_id, sector_x, sector_y, sector_z'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'x' => $coordinates->getX(),
            'y' => $coordinates->getY(),
            'z' => $coordinates->getZ(),
        ]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function getVisitedSectorByProbeId(int $probeId, SectorCoordinates $coordinates): ?VisitedSector
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM visited_sectors
             WHERE probe_id = :probe_id AND sector_x = :x AND sector_y = :y AND sector_z = :z'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'x' => $coordinates->getX(),
            'y' => $coordinates->getY(),
            'z' => $coordinates->getZ(),
        ]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return array<VisitedSector>
     */
    public function listVisited(Player $player): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT MIN(id) AS id, player_id, MIN(probe_id) AS probe_id,
                    sector_x, sector_y, sector_z,
                    MIN(first_visited_at) AS first_visited_at,
                    MAX(last_visited_at) AS last_visited_at,
                    SUM(visit_count) AS visit_count
             FROM visited_sectors
             WHERE player_id = :player_id
             GROUP BY player_id, sector_x, sector_y, sector_z
             ORDER BY last_visited_at DESC, id DESC'
        );
        $stmt->execute(['player_id' => $player->id]);

        return array_map(fn(array $row): VisitedSector => $this->hydrate($row), $stmt->fetchAll());
    }

    /**
     * @return array<VisitedSector>
     */
    public function listVisitedByProbe(NeumannProbe $probe): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM visited_sectors
             WHERE probe_id = :probe_id
             ORDER BY last_visited_at DESC, id DESC'
        );
        $stmt->execute(['probe_id' => $probe->id]);

        return array_map(fn(array $row): VisitedSector => $this->hydrate($row), $stmt->fetchAll());
    }

    /**
     * @return array<VisitedSector>
     */
    public function listVisitedAround(Player $player, SectorCoordinates $center, int $maxDistance): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT MIN(id) AS id, player_id, MIN(probe_id) AS probe_id,
                    sector_x, sector_y, sector_z,
                    MIN(first_visited_at) AS first_visited_at,
                    MAX(last_visited_at) AS last_visited_at,
                    SUM(visit_count) AS visit_count
             FROM visited_sectors
             WHERE player_id = :player_id
             AND sector_x BETWEEN :min_x AND :max_x
             AND sector_y BETWEEN :min_y AND :max_y
             AND sector_z BETWEEN :min_z AND :max_z
             GROUP BY player_id, sector_x, sector_y, sector_z'
        );
        $stmt->execute([
            'player_id' => $player->id,
            'min_x' => $center->getX() - $maxDistance,
            'max_x' => $center->getX() + $maxDistance,
            'min_y' => $center->getY() - $maxDistance,
            'max_y' => $center->getY() + $maxDistance,
            'min_z' => $center->getZ() - $maxDistance,
            'max_z' => $center->getZ() + $maxDistance,
        ]);

        $grid = new SectorGrid();
        $visited = [];
        foreach ($stmt->fetchAll() as $row) {
            $sector = $this->hydrate($row);
            if ($grid->getDistance($center, $sector->coordinates) <= $maxDistance) {
                $visited[] = $sector;
            }
        }

        return $visited;
    }

    private function hydrate(array $row): VisitedSector
    {
        return new VisitedSector(
            (int) $row['id'],
            (int) $row['player_id'],
            (int) $row['probe_id'],
            new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']),
            (string) $row['first_visited_at'],
            (string) $row['last_visited_at'],
            (int) $row['visit_count'],
        );
    }
}
