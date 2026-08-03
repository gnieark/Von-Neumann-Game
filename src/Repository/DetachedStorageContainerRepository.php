<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorDetachedContainer;

final class DetachedStorageContainerRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return list<SectorDetachedContainer>
     */
    public function findBySector(SectorCoordinates $sector): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM detached_storage_containers
             WHERE sector_x = :x AND sector_y = :y AND sector_z = :z
               AND status = \'available\'
             ORDER BY created_at ASC, object_id ASC'
        );
        $stmt->execute($this->sectorParams($sector));

        return $this->hydrateRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findByObjectId(string $objectId): ?SectorDetachedContainer
    {
        $stmt = $this->pdo->prepare('SELECT * FROM detached_storage_containers WHERE object_id = :object_id');
        $stmt->execute(['object_id' => $objectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrateRows([$row])[0] : null;
    }

    public function objectIdExistsOutsideSector(string $objectId, SectorCoordinates $sector): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM detached_storage_containers
             WHERE object_id = :object_id
               AND (sector_x <> :x OR sector_y <> :y OR sector_z <> :z)'
        );
        $stmt->execute(['object_id' => $objectId] + $this->sectorParams($sector));

        return (int) $stmt->fetchColumn() > 0;
    }

    public function save(SectorCoordinates $sector, SectorDetachedContainer $container): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->upsertContainer($sector, $container);
            $this->replaceChildren($container);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function reserve(string $objectId, int $mannyId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE detached_storage_containers
             SET status = 'reserved', reserved_by_manny_id = :manny_id, updated_at = :updated_at
             WHERE object_id = :object_id AND status = 'available'"
        );
        $stmt->execute([
            'object_id' => $objectId,
            'manny_id' => $mannyId,
            'updated_at' => gmdate('c'),
        ]);

        return $stmt->rowCount() > 0;
    }

    public function releaseReservation(string $objectId, int $mannyId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE detached_storage_containers
             SET status = 'available', reserved_by_manny_id = NULL, updated_at = :updated_at
             WHERE object_id = :object_id AND status = 'reserved' AND reserved_by_manny_id = :manny_id"
        );
        $stmt->execute([
            'object_id' => $objectId,
            'manny_id' => $mannyId,
            'updated_at' => gmdate('c'),
        ]);

        return $stmt->rowCount() > 0;
    }

    public function findReservedByObjectId(string $objectId, int $mannyId): ?SectorDetachedContainer
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM detached_storage_containers
             WHERE object_id = :object_id AND status = 'reserved' AND reserved_by_manny_id = :manny_id"
        );
        $stmt->execute(['object_id' => $objectId, 'manny_id' => $mannyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrateRows([$row])[0] : null;
    }

    public function delete(string $objectId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM detached_storage_containers WHERE object_id = :object_id');
        $stmt->execute(['object_id' => $objectId]);

        return $stmt->rowCount() > 0;
    }

    public function countByMode(string $mode): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM detached_storage_containers WHERE mode = :mode');
        $stmt->execute(['mode' => $mode]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    public function countsByMode(): array
    {
        $rows = $this->pdo->query(
            'SELECT mode, COUNT(*) AS container_count
             FROM detached_storage_containers
             GROUP BY mode'
        )->fetchAll(PDO::FETCH_ASSOC);
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['mode']] = (int) $row['container_count'];
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<SectorDetachedContainer>
     */
    private function hydrateRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn(array $row): string => (string) $row['object_id'], $rows);
        $resources = $this->childrenByContainer(
            'SELECT container_object_id, resource_type, amount
             FROM detached_storage_container_resources
             WHERE container_object_id IN (' . $this->placeholders($ids) . ')',
            $ids,
        );
        $items = $this->childrenByContainer(
            'SELECT container_object_id, uid, type, name, container_space, recipe, crafting_run_id,
                    crafted_by_manny_id, crafted_by_manny_name, crafted_at, fabricator, capacity_bonus,
                    restored_detached_container_source_uid, audit_metadata_json, is_backing_item
             FROM detached_storage_container_items
             WHERE container_object_id IN (' . $this->placeholders($ids) . ')
             ORDER BY is_backing_item DESC, id ASC',
            $ids,
        );
        $rules = $this->childrenByContainer(
            'SELECT container_object_id, rule_kind, resource_type, sort_order
             FROM detached_storage_container_rules
             WHERE container_object_id IN (' . $this->placeholders($ids) . ')
             ORDER BY rule_kind ASC, sort_order ASC',
            $ids,
        );
        $discoveries = $this->childrenByContainer(
            'SELECT container_object_id, player_id
             FROM detached_storage_container_discoveries
             WHERE container_object_id IN (' . $this->placeholders($ids) . ')
             ORDER BY player_id ASC',
            $ids,
        );
        $bookmarks = $this->childrenByContainer(
            'SELECT container_object_id, player_id, name, player_name, created_at
             FROM detached_storage_container_bookmarks
             WHERE container_object_id IN (' . $this->placeholders($ids) . ')
             ORDER BY created_at ASC, player_id ASC, name ASC',
            $ids,
        );

        $containers = [];
        foreach ($rows as $row) {
            $id = (string) $row['object_id'];
            $resourcePayload = [];
            foreach ($resources[$id] ?? [] as $resource) {
                $resourcePayload[(string) $resource['resource_type']] = round(max(0.0, (float) $resource['amount']), 4);
            }
            $backingItem = null;
            $containedItems = [];
            foreach ($items[$id] ?? [] as $item) {
                $itemPayload = [
                    'uid' => (string) $item['uid'],
                    'type' => (string) $item['type'],
                    'name' => (string) $item['name'],
                    'containerSpace' => round(max(0.0, (float) $item['container_space']), 4),
                    'metadata' => ItemMetadataColumns::metadata($item),
                ];
                if ((int) $item['is_backing_item'] === 1) {
                    $backingItem = $itemPayload;
                } else {
                    $containedItems[] = $itemPayload;
                }
            }
            $rulePayload = ['priority' => [], 'exclusion' => [], 'strictExclusion' => []];
            foreach ($rules[$id] ?? [] as $rule) {
                $kind = (string) $rule['rule_kind'];
                if (array_key_exists($kind, $rulePayload)) {
                    $rulePayload[$kind][] = (string) $rule['resource_type'];
                }
            }
            $discoveredBy = array_map(
                static fn(array $discovery): int => (int) $discovery['player_id'],
                $discoveries[$id] ?? [],
            );
            $bookmarkPayload = array_map(static fn(array $bookmark): array => [
                'name' => (string) $bookmark['name'],
                'playerId' => (int) $bookmark['player_id'],
                'playerName' => (string) $bookmark['player_name'],
                'createdAt' => (string) $bookmark['created_at'],
            ], $bookmarks[$id] ?? []);

            $sourceUid = (string) $row['source_container_uid'];
            $payload = [
                'sourceContainerId' => $sourceUid,
                'ownerProbeId' => (int) $row['owner_probe_id'],
                'ownerPlayerId' => (int) $row['owner_player_id'],
                'container' => [
                    'id' => $sourceUid,
                    'kind' => (string) $row['container_kind'],
                    'label' => (string) $row['container_label'],
                    'sortOrder' => (int) $row['container_sort_order'],
                    'capacity' => round(max(0.0, (float) $row['capacity']), 4),
                    'capacityUnit' => (string) $row['capacity_unit'],
                    'rules' => $rulePayload,
                ],
                'containerItem' => $backingItem ?? [
                    'uid' => str_starts_with($sourceUid, 'container-') ? substr($sourceUid, 10) : '',
                    'type' => 'additional_container',
                    'name' => 'Additional container',
                    'containerSpace' => 0.0,
                    'metadata' => ['capacityBonus' => (float) $row['capacity']],
                ],
                'resources' => $resourcePayload,
                'items' => $containedItems,
            ];

            $containers[] = new SectorDetachedContainer(
                $id,
                $row['name'] !== null ? (string) $row['name'] : null,
                (string) $row['mode'],
                (int) $row['owner_probe_id'],
                (int) $row['owner_player_id'],
                $row['origin_probe_id'] !== null ? (int) $row['origin_probe_id'] : null,
                $row['target_object_id'] !== null ? (string) $row['target_object_id'] : null,
                (float) $row['capacity'],
                (string) ($row['capacity_unit'] ?? ProbeInventory::CAPACITY_UNIT),
                (string) $row['created_at'],
                $payload,
                $row['description'] !== null ? (string) $row['description'] : null,
                $bookmarkPayload,
                $discoveredBy,
            );
        }

        return $containers;
    }

    private function upsertContainer(SectorCoordinates $sector, SectorDetachedContainer $container): void
    {
        $payload = $container->getPayload();
        $containerData = is_array($payload['container'] ?? null) ? $payload['container'] : [];
        $sourceUid = trim((string) ($payload['sourceContainerId'] ?? $containerData['id'] ?? ''));
        if ($sourceUid === '') {
            throw new \RuntimeException("Detached container '{$container->getId()}' has no source container uid.");
        }
        $now = gmdate('c');
        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM detached_storage_containers WHERE object_id = :object_id');
        $exists->execute(['object_id' => $container->getId()]);
        $params = [
            'object_id' => $container->getId(),
            'source_container_uid' => $sourceUid,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'mode' => $container->getMode(),
            'owner_probe_id' => $container->getOwnerProbeId(),
            'owner_player_id' => $container->getOwnerPlayerId(),
            'origin_probe_id' => $container->getOriginProbeId(),
            'target_object_id' => $container->getTargetObjectId(),
            'name' => $container->getName(),
            'description' => $container->getDescription(),
            'container_kind' => (string) ($containerData['kind'] ?? 'container'),
            'container_label' => (string) ($containerData['label'] ?? $container->getName() ?? 'Container'),
            'container_sort_order' => max(1, (int) ($containerData['sortOrder'] ?? 1)),
            'capacity' => round(max(0.0, $container->getCapacity()), 4),
            'capacity_unit' => $container->getCapacityUnit(),
            'created_at' => $container->getCreatedAt(),
            'updated_at' => $now,
        ];
        if ((int) $exists->fetchColumn() > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE detached_storage_containers SET
                    source_container_uid = :source_container_uid,
                    sector_x = :sector_x, sector_y = :sector_y, sector_z = :sector_z,
                    mode = :mode, owner_probe_id = :owner_probe_id, owner_player_id = :owner_player_id,
                    origin_probe_id = :origin_probe_id, target_object_id = :target_object_id,
                    name = :name, description = :description, container_kind = :container_kind,
                    container_label = :container_label, container_sort_order = :container_sort_order,
                    capacity = :capacity, capacity_unit = :capacity_unit, created_at = :created_at,
                    updated_at = :updated_at
                 WHERE object_id = :object_id'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO detached_storage_containers
                 (object_id, source_container_uid, sector_x, sector_y, sector_z, mode,
                  status, reserved_by_manny_id, owner_probe_id, owner_player_id, origin_probe_id, target_object_id,
                  name, description, container_kind, container_label, container_sort_order,
                  capacity, capacity_unit, created_at, updated_at)
                 VALUES
                 (:object_id, :source_container_uid, :sector_x, :sector_y, :sector_z, :mode,
                  \'available\', NULL, :owner_probe_id, :owner_player_id, :origin_probe_id, :target_object_id,
                  :name, :description, :container_kind, :container_label, :container_sort_order,
                  :capacity, :capacity_unit, :created_at, :updated_at)'
            );
        }
        $stmt->execute($params);
    }

    private function replaceChildren(SectorDetachedContainer $container): void
    {
        $id = $container->getId();
        foreach ([
            'detached_storage_container_resources',
            'detached_storage_container_items',
            'detached_storage_container_rules',
            'detached_storage_container_discoveries',
            'detached_storage_container_bookmarks',
        ] as $table) {
            $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE container_object_id = :container_object_id");
            $stmt->execute(['container_object_id' => $id]);
        }

        $payload = $container->getPayload();
        $resources = is_array($payload['resources'] ?? null) ? $payload['resources'] : [];
        $resourceInsert = $this->pdo->prepare(
            'INSERT INTO detached_storage_container_resources (container_object_id, resource_type, amount)
             VALUES (:container_object_id, :resource_type, :amount)'
        );
        foreach ($resources as $type => $amount) {
            if (!is_numeric($amount) || (float) $amount <= 0.0) {
                continue;
            }
            $resourceInsert->execute([
                'container_object_id' => $id,
                'resource_type' => (string) $type,
                'amount' => round((float) $amount, 4),
            ]);
        }

        $itemInsert = $this->pdo->prepare(
            'INSERT INTO detached_storage_container_items
             (container_object_id, uid, type, name, container_space, recipe, crafting_run_id,
              crafted_by_manny_id, crafted_by_manny_name, crafted_at, fabricator, capacity_bonus,
              restored_detached_container_source_uid, audit_metadata_json, is_backing_item)
             VALUES (:container_object_id, :uid, :type, :name, :container_space, :recipe, :crafting_run_id,
              :crafted_by_manny_id, :crafted_by_manny_name, :crafted_at, :fabricator, :capacity_bonus,
              :restored_detached_container_source_uid, :audit_metadata_json, :is_backing_item)'
        );
        $backing = is_array($payload['containerItem'] ?? null) ? $payload['containerItem'] : null;
        if ($backing !== null) {
            $this->insertItem($itemInsert, $id, $backing, true);
        }
        foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $item) {
            if (is_array($item)) {
                $this->insertItem($itemInsert, $id, $item, false);
            }
        }

        $containerData = is_array($payload['container'] ?? null) ? $payload['container'] : [];
        $rules = is_array($containerData['rules'] ?? null) ? $containerData['rules'] : [];
        $ruleInsert = $this->pdo->prepare(
            'INSERT INTO detached_storage_container_rules
             (container_object_id, rule_kind, resource_type, sort_order)
             VALUES (:container_object_id, :rule_kind, :resource_type, :sort_order)'
        );
        foreach (['priority', 'exclusion', 'strictExclusion'] as $kind) {
            foreach (array_values(array_unique(is_array($rules[$kind] ?? null) ? $rules[$kind] : [])) as $index => $type) {
                $ruleInsert->execute([
                    'container_object_id' => $id,
                    'rule_kind' => $kind,
                    'resource_type' => (string) $type,
                    'sort_order' => $index,
                ]);
            }
        }

        $discoveryInsert = $this->pdo->prepare(
            'INSERT INTO detached_storage_container_discoveries
             (container_object_id, player_id, discovered_at)
             VALUES (:container_object_id, :player_id, :discovered_at)'
        );
        foreach ($container->getDiscoveredByPlayerIds() as $playerId) {
            $discoveryInsert->execute([
                'container_object_id' => $id,
                'player_id' => $playerId,
                'discovered_at' => gmdate('c'),
            ]);
        }

        $bookmarkInsert = $this->pdo->prepare(
            'INSERT INTO detached_storage_container_bookmarks
             (container_object_id, player_id, name, player_name, created_at)
             VALUES (:container_object_id, :player_id, :name, :player_name, :created_at)'
        );
        foreach ($container->getWaypointBookmarks() as $bookmark) {
            if (!is_array($bookmark) || trim((string) ($bookmark['name'] ?? '')) === '') {
                continue;
            }
            $bookmarkInsert->execute([
                'container_object_id' => $id,
                'player_id' => max(0, (int) ($bookmark['playerId'] ?? 0)),
                'name' => (string) $bookmark['name'],
                'player_name' => (string) ($bookmark['playerName'] ?? ''),
                'created_at' => (string) ($bookmark['createdAt'] ?? gmdate('c')),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function insertItem(\PDOStatement $stmt, string $containerId, array $item, bool $backing): void
    {
        $uid = trim((string) ($item['uid'] ?? ''));
        if ($uid === '') {
            throw new \RuntimeException("Detached container '{$containerId}' contains an item without uid.");
        }
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $stmt->execute([
            'container_object_id' => $containerId,
            'uid' => $uid,
            'type' => (string) ($item['type'] ?? ''),
            'name' => (string) ($item['name'] ?? $item['type'] ?? ''),
            'container_space' => round(max(0.0, (float) ($item['containerSpace'] ?? 0.0)), 4),
            'is_backing_item' => $backing ? 1 : 0,
        ] + ItemMetadataColumns::parameters($metadata));
    }

    /**
     * @param list<string> $ids
     * @return array<string, list<array<string, mixed>>>
     */
    private function childrenByContainer(string $sql, array $ids): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[(string) $row['container_object_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param list<string> $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{x:int,y:int,z:int}
     */
    private function sectorParams(SectorCoordinates $sector): array
    {
        return ['x' => $sector->getX(), 'y' => $sector->getY(), 'z' => $sector->getZ()];
    }
}
