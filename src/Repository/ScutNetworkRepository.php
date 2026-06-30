<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ScutNetwork;

final class ScutNetworkRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<array{x:int,y:int,z:int}> $coveredSectors
     */
    public function create(string $name, array $coveredSectors, ?string $createdAt = null): ScutNetwork
    {
        $now = gmdate('c');
        $createdAt ??= $now;
        $stmt = $this->pdo->prepare(
            'INSERT INTO scut_networks (name, created_at, updated_at)
             VALUES (:name, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => $name,
            'created_at' => $createdAt,
            'updated_at' => $now,
        ]);

        $network = $this->findById((int) $this->pdo->lastInsertId())
            ?? throw new \RuntimeException('SCUT network creation failed.');
        $network->coveredSectors = $coveredSectors;

        return $network;
    }

    public function findById(int $id): ?ScutNetwork
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scut_networks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @param array<int> $ids
     * @return array<ScutNetwork>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('SELECT * FROM scut_networks WHERE id IN (' . $placeholders . ') ORDER BY id ASC');
        $stmt->execute($ids);

        return array_map(fn(array $row): ScutNetwork => $this->hydrate($row), $stmt->fetchAll());
    }

    public function save(ScutNetwork $network): void
    {
        $network->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE scut_networks SET
                name = :name,
                created_at = :created_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $network->id,
            'name' => $network->name,
            'created_at' => $network->createdAt,
            'updated_at' => $network->updatedAt,
        ]);
    }

    public function delete(int $id): void
    {
        $coverageStmt = $this->pdo->prepare('DELETE FROM scut_covered_sectors WHERE scut_network_id = :id');
        $coverageStmt->execute(['id' => $id]);

        $stmt = $this->pdo->prepare('DELETE FROM scut_networks WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function hydrate(array $row): ScutNetwork
    {
        return new ScutNetwork(
            (int) $row['id'],
            (string) $row['name'],
            $this->coverageForNetwork((int) $row['id']),
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /**
     * @return array<array{x:int,y:int,z:int}>
     */
    private function coverageForNetwork(int $networkId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sector_x, sector_y, sector_z
             FROM scut_covered_sectors
             WHERE scut_network_id = :network_id
             GROUP BY sector_x, sector_y, sector_z
             ORDER BY sector_x ASC, sector_y ASC, sector_z ASC'
        );
        $stmt->execute(['network_id' => $networkId]);

        return array_map(
            static fn(array $row): array => [
                'x' => (int) $row['sector_x'],
                'y' => (int) $row['sector_y'],
                'z' => (int) $row['sector_z'],
            ],
            $stmt->fetchAll(),
        );
    }
}
