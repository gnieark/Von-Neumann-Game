<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\StorageContainer;

final class StorageContainerRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function ensureCoreContainer(NeumannProbe $probe): StorageContainer
    {
        $existing = $this->findByUidForProbe($probe->id, StorageContainer::CORE_UID);
        if ($existing !== null) {
            return $existing;
        }

        return $this->create($probe->id, StorageContainer::CORE_UID, StorageContainer::KIND_PROBE, 'Sonde', 0, 1.0);
    }

    public function ensureContainerForItem(int $probeId, string $itemUid): StorageContainer
    {
        $existing = $this->findByUidForProbe($probeId, $this->containerUidForItem($itemUid));
        if ($existing !== null) {
            return $existing;
        }

        $number = $this->nextContainerNumber($probeId);

        return $this->create(
            $probeId,
            $this->containerUidForItem($itemUid),
            StorageContainer::KIND_CONTAINER,
            'Container ' . $number,
            $number,
            1.0,
        );
    }

    /**
     * @return array<StorageContainer>
     */
    public function findByProbeId(int $probeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM storage_containers WHERE probe_id = :probe_id ORDER BY sort_order ASC, id ASC');
        $stmt->execute(['probe_id' => $probeId]);

        return array_map(fn(array $row): StorageContainer => $this->hydrate($row), $stmt->fetchAll());
    }

    public function findByUidForProbe(int $probeId, string $uid): ?StorageContainer
    {
        $stmt = $this->pdo->prepare('SELECT * FROM storage_containers WHERE probe_id = :probe_id AND uid = :uid');
        $stmt->execute(['probe_id' => $probeId, 'uid' => $uid]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByDatabaseIdForProbe(int $probeId, int $id): ?StorageContainer
    {
        $stmt = $this->pdo->prepare('SELECT * FROM storage_containers WHERE probe_id = :probe_id AND id = :id');
        $stmt->execute(['probe_id' => $probeId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return array<string, float>
     */
    public function resourceAmounts(int $containerId): array
    {
        $stmt = $this->pdo->prepare('SELECT resource_type, amount FROM storage_container_resources WHERE container_id = :container_id');
        $stmt->execute(['container_id' => $containerId]);
        $amounts = [];
        foreach ($stmt->fetchAll() as $row) {
            $amounts[(string) $row['resource_type']] = round(max(0.0, (float) $row['amount']), 4);
        }

        return $amounts;
    }

    /**
     * @return array<int, array<string, float>>
     */
    public function resourceAmountsByContainer(int $probeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id AS container_id, r.resource_type, r.amount
             FROM storage_containers c
             LEFT JOIN storage_container_resources r ON r.container_id = c.id
             WHERE c.probe_id = :probe_id'
        );
        $stmt->execute(['probe_id' => $probeId]);
        $amounts = [];
        foreach ($stmt->fetchAll() as $row) {
            $containerId = (int) $row['container_id'];
            $amounts[$containerId] ??= [];
            if ($row['resource_type'] === null) {
                continue;
            }
            $amounts[$containerId][(string) $row['resource_type']] = round(max(0.0, (float) $row['amount']), 4);
        }

        return $amounts;
    }

    public function setResourceAmount(int $containerId, string $resourceType, float $amount): void
    {
        $amount = round(max(0.0, $amount), 4);
        if ($amount <= 0.0) {
            $stmt = $this->pdo->prepare('DELETE FROM storage_container_resources WHERE container_id = :container_id AND resource_type = :resource_type');
            $stmt->execute(['container_id' => $containerId, 'resource_type' => $resourceType]);
            return;
        }

        $now = gmdate('c');
        $exists = $this->pdo->prepare(
            'SELECT COUNT(*) FROM storage_container_resources WHERE container_id = :container_id AND resource_type = :resource_type'
        );
        $exists->execute(['container_id' => $containerId, 'resource_type' => $resourceType]);
        if ((int) $exists->fetchColumn() > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE storage_container_resources
                 SET amount = :amount, updated_at = :updated_at
                 WHERE container_id = :container_id AND resource_type = :resource_type'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO storage_container_resources (container_id, resource_type, amount, updated_at)
                 VALUES (:container_id, :resource_type, :amount, :updated_at)'
            );
        }
        $stmt->execute([
            'container_id' => $containerId,
            'resource_type' => $resourceType,
            'amount' => $amount,
            'updated_at' => $now,
        ]);
    }

    public function clearResourcesForProbe(int $probeId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM storage_container_resources
             WHERE container_id IN (SELECT id FROM storage_containers WHERE probe_id = :probe_id)'
        );
        $stmt->execute(['probe_id' => $probeId]);
    }

    public function updateRules(StorageContainer $container, array $priority, array $exclusion, array $strictExclusion): StorageContainer
    {
        $stmt = $this->pdo->prepare(
            'UPDATE storage_containers
             SET priority_filter_json = :priority, exclusion_filter_json = :exclusion, strict_exclusion_filter_json = :strict_exclusion, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $container->id,
            'priority' => json_encode(array_values($priority), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'exclusion' => json_encode(array_values($exclusion), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'strict_exclusion' => json_encode(array_values($strictExclusion), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => gmdate('c'),
        ]);

        return $this->findByUidForProbe($container->probeId, $container->uid) ?? $container;
    }

    private function create(int $probeId, string $uid, string $kind, string $label, int $sortOrder, float $capacity): StorageContainer
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO storage_containers
             (uid, probe_id, kind, label, sort_order, capacity, priority_filter_json, exclusion_filter_json, strict_exclusion_filter_json, created_at, updated_at)
             VALUES (:uid, :probe_id, :kind, :label, :sort_order, :capacity, :priority, :exclusion, :strict_exclusion, :created_at, :updated_at)'
        );
        $stmt->execute([
            'uid' => $uid,
            'probe_id' => $probeId,
            'kind' => $kind,
            'label' => $label,
            'sort_order' => $sortOrder,
            'capacity' => $capacity,
            'priority' => '[]',
            'exclusion' => '[]',
            'strict_exclusion' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByUidForProbe($probeId, $uid) ?? throw new \RuntimeException('Storage container creation failed.');
    }

    private function nextContainerNumber(int $probeId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM storage_containers WHERE probe_id = :probe_id');
        $stmt->execute(['probe_id' => $probeId]);

        return max(1, (int) $stmt->fetchColumn());
    }

    private function containerUidForItem(string $itemUid): string
    {
        return 'container-' . $itemUid;
    }

    private function hydrate(array $row): StorageContainer
    {
        return new StorageContainer(
            (int) $row['id'],
            (string) $row['uid'],
            (int) $row['probe_id'],
            (string) $row['kind'],
            (string) $row['label'],
            (int) $row['sort_order'],
            (float) $row['capacity'],
            $this->stringList($row['priority_filter_json'] ?? '[]'),
            $this->stringList($row['exclusion_filter_json'] ?? '[]'),
            $this->stringList($row['strict_exclusion_filter_json'] ?? '[]'),
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /**
     * @return array<string>
     */
    private function stringList(mixed $json): array
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string) $value)),
            $data,
        ))));
    }
}
