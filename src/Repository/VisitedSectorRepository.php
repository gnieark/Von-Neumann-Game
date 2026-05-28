<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\VisitedSector;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;

final class VisitedSectorRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function markVisited(Player $player, SectorCoordinates $coordinates): VisitedSector
    {
        return $this->markVisitedByPlayerId($player->id, $coordinates);
    }

    public function markVisitedByPlayerId(int $playerId, SectorCoordinates $coordinates): VisitedSector
    {
        $existing = $this->getVisitedSectorByPlayerId($playerId, $coordinates);
        $now = gmdate('c');

        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE visited_sectors SET last_visited_at = :last_visited_at, visit_count = visit_count + 1 WHERE id = :id'
            );
            $stmt->execute(['id' => $existing->id, 'last_visited_at' => $now]);

            return $this->getVisitedSectorByPlayerId($playerId, $coordinates) ?? $existing;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO visited_sectors (player_id, sector_x, sector_y, sector_z, first_visited_at, last_visited_at, visit_count)
             VALUES (:player_id, :x, :y, :z, :first_visited_at, :last_visited_at, 1)'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'x' => $coordinates->getX(),
            'y' => $coordinates->getY(),
            'z' => $coordinates->getZ(),
            'first_visited_at' => $now,
            'last_visited_at' => $now,
        ]);

        return $this->getVisitedSectorByPlayerId($playerId, $coordinates) ?? throw new \RuntimeException('Unable to mark sector visited.');
    }

    public function hasVisited(Player $player, SectorCoordinates $coordinates): bool
    {
        return $this->getVisitedSector($player, $coordinates) !== null;
    }

    public function getVisitedSector(Player $player, SectorCoordinates $coordinates): ?VisitedSector
    {
        return $this->getVisitedSectorByPlayerId($player->id, $coordinates);
    }

    public function getVisitedSectorByPlayerId(int $playerId, SectorCoordinates $coordinates): ?VisitedSector
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM visited_sectors
             WHERE player_id = :player_id AND sector_x = :x AND sector_y = :y AND sector_z = :z'
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

    /**
     * @return array<VisitedSector>
     */
    public function listVisitedAround(Player $player, SectorCoordinates $center, int $maxDistance): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM visited_sectors
             WHERE player_id = :player_id
             AND sector_x BETWEEN :min_x AND :max_x
             AND sector_y BETWEEN :min_y AND :max_y
             AND sector_z BETWEEN :min_z AND :max_z'
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
            new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']),
            (string) $row['first_visited_at'],
            (string) $row['last_visited_at'],
            (int) $row['visit_count'],
        );
    }
}
