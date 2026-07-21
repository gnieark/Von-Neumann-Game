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
             (created_by_probe_id, sector_x, sector_y, sector_z, status, network_id, created_at, activated_at, updated_at)
             VALUES (:created_by_probe_id, :x, :y, :z, :status, NULL, :created_at, NULL, :updated_at)'
        );
        $stmt->execute([
            'created_by_probe_id' => $createdByProbeId,
            'x' => $sector->getX(),
            'y' => $sector->getY(),
            'z' => $sector->getZ(),
            'status' => ScutRelay::STATUS_OFF,
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
                is_transit_beacon = :is_transit_beacon,
                activated_at = :activated_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $relay->id,
            'status' => $relay->status,
            'network_id' => $relay->networkId,
            'is_transit_beacon' => $relay->isTransitBeacon ? 1 : 0,
            'activated_at' => $relay->activatedAt,
            'updated_at' => $relay->updatedAt,
        ]);
        $this->replaceCoverage($relay);
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

        $coverageStmt = $this->pdo->prepare(
            'UPDATE scut_covered_sectors SET scut_network_id = :to_network_id WHERE scut_network_id = :from_network_id'
        );
        $coverageStmt->execute([
            'from_network_id' => $fromNetworkId,
            'to_network_id' => $toNetworkId,
        ]);
    }

    public function delete(int $id): void
    {
        $coverageStmt = $this->pdo->prepare('DELETE FROM scut_covered_sectors WHERE scut_relay_id = :id');
        $coverageStmt->execute(['id' => $id]);

        $stmt = $this->pdo->prepare('DELETE FROM scut_relays WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function hydrate(array $row): ScutRelay
    {
        return new ScutRelay(
            (int) $row['id'],
            $row['created_by_probe_id'] !== null ? (int) $row['created_by_probe_id'] : null,
            new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']),
            (string) $row['status'],
            $row['network_id'] !== null ? (int) $row['network_id'] : null,
            (bool) ((int) ($row['is_transit_beacon'] ?? 0)),
            $this->coverageForRelay((int) $row['id']),
            (string) $row['created_at'],
            $row['activated_at'] !== null ? (string) $row['activated_at'] : null,
            (string) $row['updated_at'],
        );
    }

    /**
     * @return array<array{x:int,y:int,z:int}>
     */
    private function coverageForRelay(int $relayId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sector_x, sector_y, sector_z
             FROM scut_covered_sectors
             WHERE scut_relay_id = :relay_id
             ORDER BY sector_x ASC, sector_y ASC, sector_z ASC'
        );
        $stmt->execute(['relay_id' => $relayId]);

        return array_map(
            static fn(array $row): array => [
                'x' => (int) $row['sector_x'],
                'y' => (int) $row['sector_y'],
                'z' => (int) $row['sector_z'],
            ],
            $stmt->fetchAll(),
        );
    }

    private function replaceCoverage(ScutRelay $relay): void
    {
        $delete = $this->pdo->prepare('DELETE FROM scut_covered_sectors WHERE scut_relay_id = :relay_id');
        $delete->execute(['relay_id' => $relay->id]);

        if ($relay->coveredSectors === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO scut_covered_sectors
             (scut_network_id, scut_relay_id, sector_x, sector_y, sector_z)
             VALUES (:network_id, :relay_id, :x, :y, :z)'
        );
        foreach ($relay->coveredSectors as $sector) {
            if (!is_array($sector)) {
                continue;
            }
            $insert->execute([
                'network_id' => $relay->networkId,
                'relay_id' => $relay->id,
                'x' => (int) ($sector['x'] ?? 0),
                'y' => (int) ($sector['y'] ?? 0),
                'z' => (int) ($sector['z'] ?? 0),
            ]);
        }
    }
}
