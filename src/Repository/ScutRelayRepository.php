<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ScutRelay;
use VonNeumannGame\Sector\SectorCoordinates;

final class ScutRelayRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(?int $createdByProbeId, SectorCoordinates $sector): ScutRelay
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO scut_relays
             (created_by_probe_id, sector_x, sector_y, sector_z, status, network_id, covered_sectors_json, created_at, activated_at, updated_at)
             VALUES (:created_by_probe_id, :x, :y, :z, :status, NULL, :covered_sectors_json, :created_at, NULL, :updated_at)'
        );
        $stmt->execute([
            'created_by_probe_id' => $createdByProbeId,
            'x' => $sector->getX(),
            'y' => $sector->getY(),
            'z' => $sector->getZ(),
            'status' => ScutRelay::STATUS_OFF,
            'covered_sectors_json' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('SCUT relay creation failed.');
    }

    public function findById(int $id): ?ScutRelay
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scut_relays WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return array<ScutRelay>
     */
    public function findBySector(SectorCoordinates $sector): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scut_relays
             WHERE sector_x = :x AND sector_y = :y AND sector_z = :z
             ORDER BY id ASC'
        );
        $stmt->execute([
            'x' => $sector->getX(),
            'y' => $sector->getY(),
            'z' => $sector->getZ(),
        ]);

        return array_map(fn(array $row): ScutRelay => $this->hydrate($row), $stmt->fetchAll());
    }

    /**
     * @return array<ScutRelay>
     */
    public function findOnRelaysCoveringSector(SectorCoordinates $sector, int $radius = ScutRelay::RADIUS_SECTORS): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scut_relays
             WHERE status = :status
               AND sector_x BETWEEN :min_x AND :max_x
               AND sector_y BETWEEN :min_y AND :max_y
               AND sector_z BETWEEN :min_z AND :max_z
             ORDER BY activated_at ASC, id ASC'
        );
        $stmt->execute([
            'status' => ScutRelay::STATUS_ON,
            'min_x' => $sector->getX() - $radius,
            'max_x' => $sector->getX() + $radius,
            'min_y' => $sector->getY() - $radius,
            'max_y' => $sector->getY() + $radius,
            'min_z' => $sector->getZ() - $radius,
            'max_z' => $sector->getZ() + $radius,
        ]);

        return array_values(array_filter(
            array_map(fn(array $row): ScutRelay => $this->hydrate($row), $stmt->fetchAll()),
            static fn(ScutRelay $relay): bool => max(
                abs($relay->sector->getX() - $sector->getX()),
                abs($relay->sector->getY() - $sector->getY()),
                abs($relay->sector->getZ() - $sector->getZ()),
            ) <= $radius,
        ));
    }

    /**
     * @return array<ScutRelay>
     */
    public function findByNetworkId(int $networkId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scut_relays WHERE network_id = :network_id ORDER BY activated_at ASC, id ASC'
        );
        $stmt->execute(['network_id' => $networkId]);

        return array_map(fn(array $row): ScutRelay => $this->hydrate($row), $stmt->fetchAll());
    }

    /**
     * @param array<int> $networkIds
     * @return array<ScutRelay>
     */
    public function findByNetworkIds(array $networkIds): array
    {
        $networkIds = array_values(array_unique(array_map('intval', $networkIds)));
        if ($networkIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($networkIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scut_relays WHERE network_id IN (' . $placeholders . ') ORDER BY activated_at ASC, id ASC'
        );
        $stmt->execute($networkIds);

        return array_map(fn(array $row): ScutRelay => $this->hydrate($row), $stmt->fetchAll());
    }

    public function save(ScutRelay $relay): void
    {
        $relay->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE scut_relays SET
                status = :status,
                network_id = :network_id,
                covered_sectors_json = :covered_sectors_json,
                activated_at = :activated_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $relay->id,
            'status' => $relay->status,
            'network_id' => $relay->networkId,
            'covered_sectors_json' => json_encode($relay->coveredSectors, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'activated_at' => $relay->activatedAt,
            'updated_at' => $relay->updatedAt,
        ]);
    }

    public function reassignNetwork(int $fromNetworkId, int $toNetworkId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE scut_relays SET network_id = :to_network_id, updated_at = :updated_at WHERE network_id = :from_network_id'
        );
        $stmt->execute([
            'from_network_id' => $fromNetworkId,
            'to_network_id' => $toNetworkId,
            'updated_at' => gmdate('c'),
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM scut_relays WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function hydrate(array $row): ScutRelay
    {
        $covered = json_decode((string) ($row['covered_sectors_json'] ?? '[]'), true);
        if (!is_array($covered)) {
            $covered = [];
        }

        return new ScutRelay(
            (int) $row['id'],
            $row['created_by_probe_id'] !== null ? (int) $row['created_by_probe_id'] : null,
            new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']),
            (string) $row['status'],
            $row['network_id'] !== null ? (int) $row['network_id'] : null,
            array_values(array_filter($covered, static fn(mixed $sector): bool => is_array($sector))),
            (string) $row['created_at'],
            $row['activated_at'] !== null ? (string) $row['activated_at'] : null,
            (string) $row['updated_at'],
        );
    }
}
