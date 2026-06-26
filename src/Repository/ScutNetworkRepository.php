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
            'INSERT INTO scut_networks (name, covered_sectors_json, created_at, updated_at)
             VALUES (:name, :covered_sectors_json, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => $name,
            'covered_sectors_json' => json_encode($coveredSectors, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('SCUT network creation failed.');
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
                covered_sectors_json = :covered_sectors_json,
                created_at = :created_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $network->id,
            'name' => $network->name,
            'covered_sectors_json' => json_encode($network->coveredSectors, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $network->createdAt,
            'updated_at' => $network->updatedAt,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM scut_networks WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function hydrate(array $row): ScutNetwork
    {
        $covered = json_decode((string) ($row['covered_sectors_json'] ?? '[]'), true);
        if (!is_array($covered)) {
            $covered = [];
        }

        return new ScutNetwork(
            (int) $row['id'],
            (string) $row['name'],
            array_values(array_filter($covered, static fn(mixed $sector): bool => is_array($sector))),
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
