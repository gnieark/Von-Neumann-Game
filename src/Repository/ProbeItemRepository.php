<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ProbeItem;

final class ProbeItemRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(int $probeId, string $type, string $name, float $containerSpace, array $metadata = [], ?int $storageContainerId = null, ?string $uid = null): ProbeItem
    {
        $now = gmdate('c');
        $uid ??= $this->uniqueUid();
        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM probe_items WHERE uid = :uid');
        $exists->execute(['uid' => $uid]);
        if ((int) $exists->fetchColumn() > 0) {
            throw new \RuntimeException('Probe item uid already exists.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_items
             (uid, probe_id, storage_container_id, type, name, container_space, metadata_json, created_at, updated_at)
             VALUES (:uid, :probe_id, :storage_container_id, :type, :name, :container_space, :metadata_json, :created_at, :updated_at)'
        );
        $stmt->execute([
            'uid' => $uid,
            'probe_id' => $probeId,
            'storage_container_id' => $storageContainerId,
            'type' => $type,
            'name' => $name,
            'container_space' => $containerSpace,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Probe item creation failed.');
    }

    public function saveStorageContainer(ProbeItem $item, ?int $storageContainerId): void
    {
        $item->storageContainerId = $storageContainerId;
        $item->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_items SET storage_container_id = :storage_container_id, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $item->id,
            'storage_container_id' => $storageContainerId,
            'updated_at' => $item->updatedAt,
        ]);
    }

    /**
     * @return array<ProbeItem>
     */
    public function findByProbeId(int $probeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_items WHERE probe_id = :probe_id ORDER BY created_at ASC, id ASC');
        $stmt->execute(['probe_id' => $probeId]);

        return array_map(fn(array $row): ProbeItem => $this->hydrate($row), $stmt->fetchAll());
    }

    public function findByUidForProbe(int $probeId, string $uid): ?ProbeItem
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_items WHERE probe_id = :probe_id AND uid = :uid');
        $stmt->execute(['probe_id' => $probeId, 'uid' => $uid]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function delete(ProbeItem $item): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM probe_items WHERE id = :id');
        $stmt->execute(['id' => $item->id]);
    }

    private function findById(int $id): ?ProbeItem
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    private function hydrate(array $row): ProbeItem
    {
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        return new ProbeItem(
            (int) $row['id'],
            (string) $row['uid'],
            (int) $row['probe_id'],
            isset($row['storage_container_id']) && $row['storage_container_id'] !== null ? (int) $row['storage_container_id'] : null,
            (string) $row['type'],
            (string) $row['name'],
            (float) $row['container_space'],
            $metadata,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    private function uniqueUid(): string
    {
        do {
            $uid = 'itm_' . bin2hex(random_bytes(12));
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM probe_items WHERE uid = :uid');
            $stmt->execute(['uid' => $uid]);
        } while ((int) $stmt->fetchColumn() > 0);

        return $uid;
    }
}
