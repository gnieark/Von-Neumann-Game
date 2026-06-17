<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeExternalTank;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeInventoryItem;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Domain\StorageContainer;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\StorageContainerRepository;

final class ProbeStorageService
{
    private const ATOMIC_PRINTER_SPACE = 0.3;
    private const ATOMIC_PRINTER_TASK = 'atomic_printing';
    private const EPSILON = 0.00001;

    public function __construct(
        private readonly StorageContainerRepository $containers,
        private readonly ProbeItemRepository $items,
        private readonly MannyRepository $mannies,
        private readonly NeumannProbeRepository $probes,
        private readonly array $config = [],
    ) {}

    public function ensureProbeStorage(NeumannProbe $probe): void
    {
        $core = $this->containers->ensureCoreContainer($probe);
        foreach ($this->items->findByProbeId($probe->id) as $item) {
            if ($item->type === ProbeItem::TYPE_ADDITIONAL_CONTAINER) {
                $this->containers->ensureContainerForItem($probe->id, $item->uid);
            }
        }

        $knownContainerIds = $this->knownContainerIds($probe);
        $this->assignUnplacedItems($probe, $core, $knownContainerIds);
        $this->assignUnplacedMannies($probe, $core, $knownContainerIds);
        if ($this->resourceRowsDifferFromProbeTotals($probe)) {
            $this->migrateLegacyProbe($probe);
        }
    }

    public function migrateLegacyProbe(NeumannProbe $probe): void
    {
        $this->containers->ensureCoreContainer($probe);
        foreach ($this->items->findByProbeId($probe->id) as $item) {
            if ($item->type === ProbeItem::TYPE_ADDITIONAL_CONTAINER) {
                $this->containers->ensureContainerForItem($probe->id, $item->uid);
            }
        }
        $this->containers->clearResourcesForProbe($probe->id);
        foreach ($this->legacyResourceTotals($probe) as $type => $amount) {
            if ($amount > 0.0) {
                $this->placeResourceAmount($probe, $type, $amount);
            }
        }
        $this->syncLegacyResourceTotals($probe);
    }

    /**
     * @param array<Manny>|null $mannies
     * @param array<ProbeItem>|null $probeItems
     */
    public function inventoryForProbe(NeumannProbe $probe, ?array $mannies = null, ?array $probeItems = null): ProbeInventory
    {
        $this->ensureProbeStorage($probe);
        $mannies ??= $this->mannies->findByProbeId($probe->id);
        $probeItems ??= $this->items->findByProbeId($probe->id);
        $containers = $this->containers->findByProbeId($probe->id);
        $used = $this->usedCapacityByContainer($probe, $containers, $mannies, $probeItems);
        $containerById = $this->containersByDatabaseId($containers);
        $printerAssistant = $this->atomicPrinterAssistant($mannies);

        $items = [
            new ProbeInventoryItem(
                'probe-' . $probe->id . '-atomic-3d-printer',
                'atomic_3d_printer',
                'Imprimante atomique',
                $this->atomicPrinterSpace(),
                $printerAssistant !== null ? self::ATOMIC_PRINTER_TASK : null,
                $printerAssistant?->taskProgressPercent() ?? 0.0,
                null,
                null,
                $this->atomicPrinterMetadata($printerAssistant),
                $this->containerSummary($containerById[$this->coreContainer($containers)->id] ?? null),
            ),
        ];

        foreach ($mannies as $manny) {
            $container = $manny->isOnProbe() && $manny->storageContainerId !== null
                ? ($containerById[$manny->storageContainerId] ?? null)
                : null;
            $items[] = new ProbeInventoryItem(
                $manny->uid,
                'manny',
                $manny->name,
                $manny->isOnProbe() ? $this->mannyContainerSpace() : 0.0,
                $manny->currentTask,
                $manny->taskProgressPercent(),
                ['type' => $manny->locationType],
                $this->mannyCargoArray($manny),
                ['movable' => $manny->isOnProbe()],
                $this->containerSummary($container),
            );
        }

        foreach ($probeItems as $item) {
            $items[] = $item->inventoryItem($this->containerSummary(
                $item->storageContainerId !== null ? ($containerById[$item->storageContainerId] ?? null) : null,
            ));
        }

        $externalTanks = [
            new ProbeExternalTank(
                'probe-' . $probe->id . '-deuterium-tank',
                'deuterium',
                'Cuve externe de deutérium',
                $probe->deuteriumStock,
            ),
        ];

        return new ProbeInventory(
            $this->totalCapacity($containers),
            $items,
            $externalTanks,
            $this->resourceStocks($probe, $containers),
            array_map(
                static fn(StorageContainer $container): array => $container->toArray((float) ($used[$container->id] ?? 0.0)),
                $containers,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function containerInventory(NeumannProbe $probe, string $containerUid): array
    {
        $this->ensureProbeStorage($probe);
        $container = $this->containers->findByUidForProbe($probe->id, $containerUid)
            ?? throw new MannyActionException(404, 'storage_container_not_found', 'Storage container not found.');
        $containers = $this->containers->findByProbeId($probe->id);
        $containerById = $this->containersByDatabaseId($containers);
        $mannies = $this->mannies->findByProbeId($probe->id);
        $probeItems = $this->items->findByProbeId($probe->id);
        $used = $this->usedCapacityByContainer($probe, $containers, $mannies, $probeItems);
        $summary = $container->toArray((float) ($used[$container->id] ?? 0.0));
        $items = [];
        if ($container->uid === StorageContainer::CORE_UID) {
            $printerAssistant = $this->atomicPrinterAssistant($mannies);
            $items[] = (new ProbeInventoryItem(
                'probe-' . $probe->id . '-atomic-3d-printer',
                'atomic_3d_printer',
                'Imprimante atomique',
                $this->atomicPrinterSpace(),
                $printerAssistant !== null ? self::ATOMIC_PRINTER_TASK : null,
                $printerAssistant?->taskProgressPercent() ?? 0.0,
                null,
                null,
                $this->atomicPrinterMetadata($printerAssistant),
                $this->containerSummary($container),
            ))->toArray();
        }
        foreach ($mannies as $manny) {
            if (!$manny->isOnProbe() || $manny->storageContainerId !== $container->id) {
                continue;
            }
            $items[] = (new ProbeInventoryItem(
                $manny->uid,
                'manny',
                $manny->name,
                $this->mannyContainerSpace(),
                $manny->currentTask,
                $manny->taskProgressPercent(),
                ['type' => $manny->locationType],
                $this->mannyCargoArray($manny),
                ['movable' => true],
                $this->containerSummary($container),
            ))->toArray();
        }
        foreach ($probeItems as $item) {
            if ($item->storageContainerId !== $container->id) {
                continue;
            }
            $items[] = $item->inventoryItem($this->containerSummary($containerById[$item->storageContainerId] ?? null))->toArray();
        }

        return [
            'container' => $summary,
            'inventory' => [
                'capacityUnit' => ProbeInventory::CAPACITY_UNIT,
                'items' => $items,
                'resourceStocks' => $this->resourceStocks($probe, [$container]),
            ],
        ];
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function containersForProbe(NeumannProbe $probe): array
    {
        $this->ensureProbeStorage($probe);
        $containers = $this->containers->findByProbeId($probe->id);
        $used = $this->usedCapacityByContainer($probe, $containers, $this->mannies->findByProbeId($probe->id), $this->items->findByProbeId($probe->id));

        return array_map(
            static fn(StorageContainer $container): array => $container->toArray((float) ($used[$container->id] ?? 0.0)),
            $containers,
        );
    }

    /**
     * @return array<array{id:string,label:string,sortOrder:int,capacity:float}>
     */
    public function additionalContainerCandidates(NeumannProbe $probe): array
    {
        $this->ensureProbeStorage($probe);
        $candidates = [];
        foreach ($this->containers->findByProbeId($probe->id) as $container) {
            if ($container->kind !== StorageContainer::KIND_CONTAINER || !str_starts_with($container->uid, 'container-')) {
                continue;
            }
            $candidates[] = [
                'id' => $container->uid,
                'label' => $container->label,
                'sortOrder' => $container->sortOrder,
                'capacity' => $container->capacity,
            ];
        }

        return $candidates;
    }

    public function additionalContainerCount(NeumannProbe $probe): int
    {
        return count($this->additionalContainerCandidates($probe));
    }

    public function updateContainerRules(NeumannProbe $probe, string $containerUid, array $priority, array $exclusion, array $strictExclusion): array
    {
        $this->ensureProbeStorage($probe);
        $container = $this->containers->findByUidForProbe($probe->id, $containerUid)
            ?? throw new MannyActionException(404, 'storage_container_not_found', 'Storage container not found.');
        $container = $this->containers->updateRules(
            $container,
            $this->normalizedFilter($priority),
            $this->normalizedFilter($exclusion),
            $this->normalizedFilter($strictExclusion),
        );
        $used = $this->usedCapacityByContainer($probe, [$container], $this->mannies->findByProbeId($probe->id), $this->items->findByProbeId($probe->id));

        return $container->toArray((float) ($used[$container->id] ?? 0.0));
    }

    public function addResource(NeumannProbe $probe, string $type, float $amount): float
    {
        $type = $this->normalizeResourceType($type);
        $amount = round(max(0.0, $amount), 4);
        if ($amount <= 0.0) {
            return 0.0;
        }
        if ($type === ResourceComposition::DEUTERIUM) {
            $before = $probe->deuteriumStock;
            $probe->deuteriumStock = round(min($this->maxDeuteriumPercent(), $probe->deuteriumStock + ($amount * $this->maxDeuteriumPercent())), 4);
            $this->probes->save($probe);

            return round(($probe->deuteriumStock - $before) / $this->maxDeuteriumPercent(), 4);
        }

        $this->ensureProbeStorage($probe);
        $accepted = $this->placeResourceAmount($probe, $type, $amount);
        if ($accepted > 0.0) {
            $this->syncLegacyResourceTotals($probe);
        }

        return $accepted;
    }

    private function placeResourceAmount(NeumannProbe $probe, string $type, float $amount): float
    {
        $accepted = 0.0;
        foreach ($this->placementCandidates($probe, $type) as $container) {
            $free = $this->freeCapacityForContainer($probe, $container);
            if ($free <= self::EPSILON) {
                continue;
            }
            $added = round(min($free, $amount - $accepted), 4);
            if ($added <= 0.0) {
                continue;
            }
            $resources = $this->containers->resourceAmounts($container->id);
            $this->containers->setResourceAmount($container->id, $type, round((float) ($resources[$type] ?? 0.0) + $added, 4));
            $accepted = round($accepted + $added, 4);
            if ($accepted + self::EPSILON >= $amount) {
                break;
            }
        }

        return $accepted;
    }

    public function consumeResource(NeumannProbe $probe, string $type, float $amount): float
    {
        $type = $this->normalizeResourceType($type);
        $amount = round(max(0.0, $amount), 4);
        if ($amount <= 0.0) {
            return 0.0;
        }
        if ($type === ResourceComposition::DEUTERIUM) {
            $consumed = min($amount, round(max(0.0, $probe->deuteriumStock / $this->maxDeuteriumPercent()), 4));
            $probe->deuteriumStock = round(max(0.0, $probe->deuteriumStock - ($consumed * $this->maxDeuteriumPercent())), 4);
            $this->probes->save($probe);

            return $consumed;
        }

        $this->ensureProbeStorage($probe);
        $remaining = $amount;
        foreach ($this->containers->findByProbeId($probe->id) as $container) {
            $resources = $this->containers->resourceAmounts($container->id);
            $available = round(max(0.0, (float) ($resources[$type] ?? 0.0)), 4);
            if ($available <= 0.0) {
                continue;
            }
            $taken = min($available, $remaining);
            $this->containers->setResourceAmount($container->id, $type, round($available - $taken, 4));
            $remaining = round($remaining - $taken, 4);
            if ($remaining <= self::EPSILON) {
                break;
            }
        }
        $consumed = round($amount - max(0.0, $remaining), 4);
        if ($consumed > 0.0) {
            $this->syncLegacyResourceTotals($probe);
        }

        return $consumed;
    }

    public function consumeResourceFromContainer(NeumannProbe $probe, string $type, float $amount, string $containerUid): float
    {
        $type = $this->normalizeResourceType($type);
        $amount = round(max(0.0, $amount), 4);
        if ($amount <= 0.0) {
            return 0.0;
        }
        if ($type === ResourceComposition::DEUTERIUM) {
            return $this->consumeResource($probe, $type, $amount);
        }

        $this->ensureProbeStorage($probe);
        $container = $this->requiredContainer($probe, $containerUid);
        $resources = $this->containers->resourceAmounts($container->id);
        $available = round(max(0.0, (float) ($resources[$type] ?? 0.0)), 4);
        $consumed = min($amount, $available);
        if ($consumed <= 0.0) {
            return 0.0;
        }

        $this->containers->setResourceAmount($container->id, $type, round($available - $consumed, 4));
        $this->syncLegacyResourceTotals($probe);

        return round($consumed, 4);
    }

    public function resourceStock(NeumannProbe $probe, string $type): float
    {
        $type = $this->normalizeResourceType($type);
        if ($type === ResourceComposition::DEUTERIUM) {
            return round(max(0.0, $probe->deuteriumStock / $this->maxDeuteriumPercent()), 4);
        }

        $this->ensureProbeStorage($probe);
        $total = 0.0;
        foreach ($this->containers->resourceAmountsByContainer($probe->id) as $resources) {
            $total += (float) ($resources[$type] ?? 0.0);
        }

        return round($total, 4);
    }

    public function resourceStockInContainer(NeumannProbe $probe, string $type, string $containerUid): float
    {
        $type = $this->normalizeResourceType($type);
        if ($type === ResourceComposition::DEUTERIUM) {
            return $probe->deuteriumStock;
        }

        $this->ensureProbeStorage($probe);
        $container = $this->requiredContainer($probe, $containerUid);
        $resources = $this->containers->resourceAmounts($container->id);

        return round(max(0.0, (float) ($resources[$type] ?? 0.0)), 4);
    }

    public function freeCargoCapacity(NeumannProbe $probe): float
    {
        $this->ensureProbeStorage($probe);
        $containers = $this->containers->findByProbeId($probe->id);
        $used = $this->usedCapacityByContainer($probe, $containers, $this->mannies->findByProbeId($probe->id), $this->items->findByProbeId($probe->id));

        return round(array_reduce(
            $containers,
            static fn(float $total, StorageContainer $container): float => $total + max(0.0, $container->capacity - (float) ($used[$container->id] ?? 0.0)),
            0.0,
        ), 4);
    }

    /**
     * @param array<string, float> $resources
     * @param list<array{type:string, space:float}> $units
     */
    public function canStoreIncoming(NeumannProbe $probe, array $resources = [], array $units = []): bool
    {
        $this->ensureProbeStorage($probe);
        $containers = $this->containers->findByProbeId($probe->id);
        $used = $this->usedCapacityByContainer($probe, $containers, $this->mannies->findByProbeId($probe->id), $this->items->findByProbeId($probe->id));
        foreach ($resources as $type => $amount) {
            $type = $this->normalizeResourceType((string) $type);
            if ($type === ResourceComposition::DEUTERIUM || (float) $amount <= 0.0) {
                continue;
            }
            if (!$this->simulateResourcePlacement($probe, $containers, $used, $type, (float) $amount)) {
                return false;
            }
        }
        foreach ($units as $unit) {
            if (!$this->simulateUnitPlacement($probe, $containers, $used, $unit['type'], $unit['space'])) {
                return false;
            }
        }

        return true;
    }

    public function addItem(NeumannProbe $probe, string $type, string $name, float $containerSpace, array $metadata = [], ?string $uid = null): ProbeItem
    {
        $this->ensureProbeStorage($probe);
        $container = $this->placeUnit($probe, $type, $containerSpace)
            ?? throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for this inventory item.');
        $item = $this->items->create($probe->id, $type, $name, $containerSpace, $metadata, $container->id, $uid);
        if ($type === ProbeItem::TYPE_ADDITIONAL_CONTAINER) {
            $this->containers->ensureContainerForItem($probe->id, $item->uid);
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    public function detachAdditionalContainerSnapshot(NeumannProbe $probe, string $containerUid, int $ownerPlayerId): array
    {
        $this->ensureProbeStorage($probe);
        if ($containerUid === StorageContainer::CORE_UID) {
            throw new MannyActionException(422, 'storage_container_not_detachable', 'The probe core storage cannot be detached.');
        }

        $container = $this->containers->findByUidForProbe($probe->id, $containerUid)
            ?? throw new MannyActionException(404, 'invalid_storage_container', 'Storage container not found.');
        if ($container->kind !== StorageContainer::KIND_CONTAINER || !str_starts_with($container->uid, 'container-')) {
            throw new MannyActionException(422, 'storage_container_not_detachable', 'Only additional storage containers can be detached.');
        }

        $containerItemUid = substr($container->uid, strlen('container-'));
        $containerItem = $this->items->findByUidForProbe($probe->id, $containerItemUid)
            ?? throw new MannyActionException(422, 'storage_container_not_detachable', 'The backing additional container item is missing.');
        if ($containerItem->type !== ProbeItem::TYPE_ADDITIONAL_CONTAINER) {
            throw new MannyActionException(422, 'storage_container_not_detachable', 'Only additional storage containers can be detached.');
        }

        $items = [];
        foreach ($this->items->findByProbeId($probe->id) as $item) {
            if ($item->storageContainerId !== $container->id || $item->uid === $containerItem->uid) {
                continue;
            }
            if ($item->type === ProbeItem::TYPE_ADDITIONAL_CONTAINER) {
                throw new MannyActionException(422, 'storage_container_not_detachable', 'Nested additional containers cannot be detached.');
            }
            $items[] = $this->probeItemPayload($item);
        }

        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if ($manny->isOnProbe() && $manny->storageContainerId === $container->id) {
                throw new MannyActionException(422, 'storage_container_not_detachable', 'Move Mannies out of this container before detaching it.');
            }
        }

        $resources = $this->containers->resourceAmounts($container->id);
        $snapshot = [
            'sourceContainerId' => $container->uid,
            'ownerProbeId' => $probe->id,
            'ownerPlayerId' => $ownerPlayerId,
            'container' => $container->toArray(),
            'containerItem' => $this->probeItemPayload($containerItem),
            'resources' => $resources,
            'items' => $items,
            'detachedAt' => gmdate('c'),
        ];

        foreach ($items as $item) {
            $stored = $this->items->findByUidForProbe($probe->id, (string) $item['uid']);
            if ($stored !== null) {
                $this->items->delete($stored);
            }
        }
        $this->items->delete($containerItem);
        $this->containers->delete($container);
        $this->syncLegacyResourceTotals($probe);

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function restoreDetachedContainerSnapshot(NeumannProbe $probe, array $snapshot): void
    {
        $this->ensureProbeStorage($probe);
        $containerData = is_array($snapshot['container'] ?? null) ? $snapshot['container'] : [];
        $containerItemData = is_array($snapshot['containerItem'] ?? null) ? $snapshot['containerItem'] : [];
        $containerUid = (string) ($snapshot['sourceContainerId'] ?? $containerData['id'] ?? '');
        $itemUid = (string) ($containerItemData['uid'] ?? ($containerUid !== '' && str_starts_with($containerUid, 'container-') ? substr($containerUid, 10) : ''));
        if ($containerUid === '' || $itemUid === '') {
            throw new MannyActionException(422, 'detached_container_not_recoverable', 'Detached container data is incomplete.');
        }
        if ($this->containers->findByUidForProbe($probe->id, $containerUid) !== null || $this->items->findByUidForProbe($probe->id, $itemUid) !== null) {
            throw new MannyActionException(409, 'detached_container_not_recoverable', 'A storage container with this identifier already exists on the probe.');
        }

        $itemContainer = $this->placeUnit($probe, ProbeItem::TYPE_ADDITIONAL_CONTAINER, (float) ($containerItemData['containerSpace'] ?? 0.0))
            ?? throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity to attach this container.');
        $this->items->create(
            $probe->id,
            ProbeItem::TYPE_ADDITIONAL_CONTAINER,
            (string) ($containerItemData['name'] ?? ProbeItem::ADDITIONAL_CONTAINER_NAME),
            round(max(0.0, (float) ($containerItemData['containerSpace'] ?? 0.0)), 4),
            is_array($containerItemData['metadata'] ?? null) ? $containerItemData['metadata'] : [],
            $itemContainer->id,
            $itemUid,
        );

        $rules = is_array($containerData['rules'] ?? null) ? $containerData['rules'] : [];
        $restoredContainer = $this->containers->createDetachedRestoredContainer(
            $probe->id,
            $containerUid,
            (string) ($containerData['label'] ?? 'Container'),
            (int) ($containerData['sortOrder'] ?? 1),
            (float) ($containerData['capacity'] ?? 0.0),
            is_array($rules['priority'] ?? null) ? $rules['priority'] : [],
            is_array($rules['exclusion'] ?? null) ? $rules['exclusion'] : [],
            is_array($rules['strictExclusion'] ?? null) ? $rules['strictExclusion'] : [],
        );

        $resources = is_array($snapshot['resources'] ?? null) ? $snapshot['resources'] : [];
        foreach ($resources as $type => $amount) {
            if ((float) $amount > 0.0) {
                $this->containers->setResourceAmount($restoredContainer->id, (string) $type, (float) $amount);
            }
        }

        $items = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            if ($type === '') {
                continue;
            }
            $uid = trim((string) ($item['uid'] ?? ''));
            $this->items->create(
                $probe->id,
                $type,
                (string) ($item['name'] ?? $type),
                round(max(0.0, (float) ($item['containerSpace'] ?? 0.0)), 4),
                is_array($item['metadata'] ?? null) ? $item['metadata'] : [],
                $restoredContainer->id,
                $uid !== '' ? $uid : null,
            );
        }

        $this->syncLegacyResourceTotals($probe);
    }

    public function placeMannyOnProbe(NeumannProbe $probe, Manny $manny): bool
    {
        $this->ensureProbeStorage($probe);
        $container = $this->placeUnit($probe, 'manny', $this->mannyContainerSpace());
        if ($container === null) {
            return false;
        }

        $manny->storageContainerId = $container->id;
        return true;
    }

    public function releaseMannyFromStorage(Manny $manny): void
    {
        $manny->storageContainerId = null;
    }

    public function moveResource(NeumannProbe $probe, string $type, float $amount, string $fromContainerUid, string $toContainerUid): void
    {
        $move = $this->assertCanMoveResource($probe, $type, $amount, $fromContainerUid, $toContainerUid);
        if ($move['from']->id === $move['to']->id) {
            return;
        }
        $targetResources = $this->containers->resourceAmounts($move['to']->id);
        $this->containers->setResourceAmount($move['from']->id, $move['type'], round($move['available'] - $move['amount'], 4));
        $this->containers->setResourceAmount($move['to']->id, $move['type'], round((float) ($targetResources[$move['type']] ?? 0.0) + $move['amount'], 4));
        $this->syncLegacyResourceTotals($probe);
    }

    public function moveItem(NeumannProbe $probe, string $itemUid, string $toContainerUid): void
    {
        $move = $this->assertCanMoveItem($probe, $itemUid, $toContainerUid);
        if ($move['item']->storageContainerId === $move['to']->id) {
            return;
        }

        $this->items->saveStorageContainer($move['item'], $move['to']->id);
    }

    /**
     * @param array<string> $itemUids
     */
    public function moveItems(NeumannProbe $probe, array $itemUids, string $toContainerUid): void
    {
        $move = $this->assertCanMoveItems($probe, $itemUids, $toContainerUid);
        foreach ($move['items'] as $item) {
            if ($item->storageContainerId === $move['to']->id) {
                continue;
            }
            $this->items->saveStorageContainer($item, $move['to']->id);
        }
    }

    public function moveStoredManny(NeumannProbe $probe, string $mannyUid, string $toContainerUid): void
    {
        $move = $this->assertCanMoveManny($probe, $mannyUid, $toContainerUid);
        if ($move['manny']->storageContainerId === $move['to']->id) {
            return;
        }

        $move['manny']->storageContainerId = $move['to']->id;
        $this->mannies->save($move['manny']);
    }

    /**
     * @param array<string> $mannyUids
     */
    public function moveStoredMannies(NeumannProbe $probe, array $mannyUids, string $toContainerUid): void
    {
        $move = $this->assertCanMoveMannies($probe, $mannyUids, $toContainerUid);
        foreach ($move['mannies'] as $manny) {
            if ($manny->storageContainerId === $move['to']->id) {
                continue;
            }
            $manny->storageContainerId = $move['to']->id;
            $this->mannies->save($manny);
        }
    }

    /**
     * @return array{type:string, amount:float, from:StorageContainer, to:StorageContainer, available:float}
     */
    public function assertCanMoveResource(NeumannProbe $probe, string $type, float $amount, string $fromContainerUid, string $toContainerUid): array
    {
        $type = $this->normalizeResourceType($type);
        if ($type === ResourceComposition::DEUTERIUM) {
            throw new MannyActionException(422, 'item_not_movable', 'Deuterium is stored in its external tank.');
        }
        $amount = round(max(0.0, $amount), 4);
        if ($amount <= 0.0) {
            throw new MannyActionException(400, 'bad_request', 'Storage move amount must be greater than zero.');
        }
        $this->ensureProbeStorage($probe);
        $from = $this->requiredContainer($probe, $fromContainerUid);
        $to = $this->requiredContainer($probe, $toContainerUid);
        $resources = $this->containers->resourceAmounts($from->id);
        $available = round(max(0.0, (float) ($resources[$type] ?? 0.0)), 4);
        if ($available + self::EPSILON < $amount) {
            throw new MannyActionException(422, 'insufficient_inventory_amount', 'The requested storage move amount is not available.');
        }
        if ($from->id !== $to->id && $this->freeCapacityForContainer($probe, $to) + self::EPSILON < $amount) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Destination container does not have enough free capacity.');
        }

        return ['type' => $type, 'amount' => $amount, 'from' => $from, 'to' => $to, 'available' => $available];
    }

    /**
     * @return array{item:ProbeItem, to:StorageContainer}
     */
    public function assertCanMoveItem(NeumannProbe $probe, string $itemUid, string $toContainerUid): array
    {
        $move = $this->assertCanMoveItems($probe, [$itemUid], $toContainerUid);

        return ['item' => $move['items'][0], 'to' => $move['to']];
    }

    /**
     * @param array<string> $itemUids
     * @return array{items:array<ProbeItem>, to:StorageContainer}
     */
    public function assertCanMoveItems(NeumannProbe $probe, array $itemUids, string $toContainerUid): array
    {
        $this->ensureProbeStorage($probe);
        $to = $this->requiredContainer($probe, $toContainerUid);
        $items = [];
        $seen = [];
        $requiredSpace = 0.0;
        foreach ($itemUids as $itemUid) {
            $itemUid = trim($itemUid);
            if ($itemUid === '' || isset($seen[$itemUid])) {
                continue;
            }
            $seen[$itemUid] = true;
            if ($itemUid === 'probe-' . $probe->id . '-atomic-3d-printer') {
                throw new MannyActionException(422, 'item_not_movable', 'The atomic printer is stored inside the probe and cannot be moved.');
            }
            $item = $this->items->findByUidForProbe($probe->id, $itemUid)
                ?? throw new MannyActionException(404, 'not_found', 'Inventory item not found.');
            $items[] = $item;
            if ($item->storageContainerId !== $to->id) {
                $requiredSpace = round($requiredSpace + max(0.0, $item->containerSpace), 4);
            }
        }
        if ($items === []) {
            throw new MannyActionException(400, 'bad_request', 'Item storage move requires at least one item.');
        }
        if ($this->freeCapacityForContainer($probe, $to) + self::EPSILON < $requiredSpace) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Destination container does not have enough free capacity.');
        }

        return ['items' => $items, 'to' => $to];
    }

    /**
     * @return array{manny:Manny, to:StorageContainer}
     */
    public function assertCanMoveManny(NeumannProbe $probe, string $mannyUid, string $toContainerUid): array
    {
        $move = $this->assertCanMoveMannies($probe, [$mannyUid], $toContainerUid);

        return ['manny' => $move['mannies'][0], 'to' => $move['to']];
    }

    /**
     * @param array<string> $mannyUids
     * @return array{mannies:array<Manny>, to:StorageContainer}
     */
    public function assertCanMoveMannies(NeumannProbe $probe, array $mannyUids, string $toContainerUid): array
    {
        $this->ensureProbeStorage($probe);
        $to = $this->requiredContainer($probe, $toContainerUid);
        $mannies = [];
        $seen = [];
        $requiredSpace = 0.0;
        foreach ($mannyUids as $mannyUid) {
            $mannyUid = trim($mannyUid);
            if ($mannyUid === '' || isset($seen[$mannyUid])) {
                continue;
            }
            $seen[$mannyUid] = true;
            $manny = $this->mannies->findByUidForProbe($probe->id, $mannyUid)
                ?? throw new MannyActionException(404, 'manny_not_found', 'Manny not found.');
            if (!$manny->isOnProbe()) {
                throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny is outside the probe.');
            }
            $mannies[] = $manny;
            if ($manny->storageContainerId !== $to->id) {
                $requiredSpace = round($requiredSpace + $this->mannyContainerSpace(), 4);
            }
        }
        if ($mannies === []) {
            throw new MannyActionException(400, 'bad_request', 'Manny storage move requires at least one Manny.');
        }
        if ($this->freeCapacityForContainer($probe, $to) + self::EPSILON < $requiredSpace) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Destination container does not have enough free capacity.');
        }

        return ['mannies' => $mannies, 'to' => $to];
    }

    public function storageMoveDurationSeconds(string $kind, float $amount = 1.0): int
    {
        if ($kind === 'resource') {
            return (int) ceil(max(0.0, $amount) / $this->storageMoveEceStep()) * $this->storageMoveSecondsPerUnit();
        }

        return max(1, (int) ceil(max(1.0, $amount))) * $this->storageMoveSecondsPerUnit();
    }

    /**
     * @param array<int, true> $knownContainerIds
     */
    private function assignUnplacedItems(NeumannProbe $probe, StorageContainer $fallback, array $knownContainerIds): void
    {
        foreach ($this->items->findByProbeId($probe->id) as $item) {
            if ($item->storageContainerId !== null && isset($knownContainerIds[$item->storageContainerId])) {
                continue;
            }
            $container = $this->placeUnit($probe, $item->type, $item->containerSpace) ?? $fallback;
            $this->items->saveStorageContainer($item, $container->id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function probeItemPayload(ProbeItem $item): array
    {
        return [
            'uid' => $item->uid,
            'type' => $item->type,
            'name' => $item->name,
            'containerSpace' => $item->containerSpace,
            'metadata' => $item->metadata,
            'createdAt' => $item->createdAt,
        ];
    }

    /**
     * @param array<int, true> $knownContainerIds
     */
    private function assignUnplacedMannies(NeumannProbe $probe, StorageContainer $fallback, array $knownContainerIds): void
    {
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if (!$manny->isOnProbe()) {
                continue;
            }
            if ($manny->storageContainerId !== null && isset($knownContainerIds[$manny->storageContainerId])) {
                continue;
            }
            $container = $this->placeUnit($probe, 'manny', $this->mannyContainerSpace()) ?? $fallback;
            $manny->storageContainerId = $container->id;
            $this->mannies->save($manny);
        }
    }

    /**
     * @return array<int, true>
     */
    private function knownContainerIds(NeumannProbe $probe): array
    {
        $known = [];
        foreach ($this->containers->findByProbeId($probe->id) as $container) {
            $known[$container->id] = true;
        }

        return $known;
    }

    private function resourceRowsDifferFromProbeTotals(NeumannProbe $probe): bool
    {
        $storageTotals = [
            ResourceComposition::METALS => 0.0,
            ResourceComposition::ICE => 0.0,
            ResourceComposition::CARBON_COMPOUNDS => 0.0,
        ];
        foreach ($this->containers->resourceAmountsByContainer($probe->id) as $resources) {
            foreach ($storageTotals as $type => $_amount) {
                $storageTotals[$type] = round($storageTotals[$type] + (float) ($resources[$type] ?? 0.0), 4);
            }
        }

        foreach ($this->legacyResourceTotals($probe) as $type => $legacyAmount) {
            if (abs($storageTotals[$type] - $legacyAmount) > 0.0001) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, float>
     */
    private function legacyResourceTotals(NeumannProbe $probe): array
    {
        return [
            ResourceComposition::METALS => round(max(0.0, $probe->metalsStock), 4),
            ResourceComposition::ICE => round(max(0.0, $probe->iceStock), 4),
            ResourceComposition::CARBON_COMPOUNDS => round(max(0.0, $probe->organicCompoundsStock), 4),
        ];
    }

    private function syncLegacyResourceTotals(NeumannProbe $probe): void
    {
        $totals = [
            ResourceComposition::METALS => 0.0,
            ResourceComposition::ICE => 0.0,
            ResourceComposition::CARBON_COMPOUNDS => 0.0,
        ];
        foreach ($this->containers->resourceAmountsByContainer($probe->id) as $resources) {
            foreach ($totals as $type => $_amount) {
                $totals[$type] = round($totals[$type] + (float) ($resources[$type] ?? 0.0), 4);
            }
        }

        $probe->metalsStock = $totals[ResourceComposition::METALS];
        $probe->iceStock = $totals[ResourceComposition::ICE];
        $probe->organicCompoundsStock = $totals[ResourceComposition::CARBON_COMPOUNDS];
        $this->probes->save($probe);
    }

    /**
     * @param array<StorageContainer> $containers
     * @param array<Manny> $mannies
     * @param array<ProbeItem> $items
     * @return array<int, float>
     */
    private function usedCapacityByContainer(NeumannProbe $probe, array $containers, array $mannies, array $items): array
    {
        $used = [];
        foreach ($containers as $container) {
            $used[$container->id] = $container->uid === StorageContainer::CORE_UID ? $this->atomicPrinterSpace() : 0.0;
        }
        foreach ($this->containers->resourceAmountsByContainer($probe->id) as $containerId => $resources) {
            foreach ($resources as $amount) {
                $used[$containerId] = round((float) ($used[$containerId] ?? 0.0) + max(0.0, $amount), 4);
            }
        }
        foreach ($items as $item) {
            if ($item->storageContainerId !== null) {
                $used[$item->storageContainerId] = round((float) ($used[$item->storageContainerId] ?? 0.0) + max(0.0, $item->containerSpace), 4);
            }
        }
        foreach ($mannies as $manny) {
            if ($manny->isOnProbe() && $manny->storageContainerId !== null) {
                $used[$manny->storageContainerId] = round((float) ($used[$manny->storageContainerId] ?? 0.0) + $this->mannyContainerSpace(), 4);
            }
        }

        return $used;
    }

    /**
     * @param array<StorageContainer> $containers
     */
    private function totalCapacity(array $containers): float
    {
        return round(array_reduce(
            $containers,
            static fn(float $total, StorageContainer $container): float => $total + max(0.0, $container->capacity),
            0.0,
        ), 4);
    }

    /**
     * @param array<StorageContainer> $containers
     */
    private function coreContainer(array $containers): StorageContainer
    {
        foreach ($containers as $container) {
            if ($container->uid === StorageContainer::CORE_UID) {
                return $container;
            }
        }

        return $containers[0] ?? throw new \RuntimeException('Probe storage has no container.');
    }

    /**
     * @param array<StorageContainer> $containers
     * @return array<int, StorageContainer>
     */
    private function containersByDatabaseId(array $containers): array
    {
        $byId = [];
        foreach ($containers as $container) {
            $byId[$container->id] = $container;
        }

        return $byId;
    }

    private function containerSummary(?StorageContainer $container): ?array
    {
        if ($container === null) {
            return null;
        }

        return [
            'id' => $container->uid,
            'kind' => $container->kind,
            'label' => $container->label,
            'sortOrder' => $container->sortOrder,
        ];
    }

    /**
     * @param array<Manny> $mannies
     */
    private function atomicPrinterAssistant(array $mannies): ?Manny
    {
        foreach ($mannies as $manny) {
            if ($manny->currentTask === Manny::TASK_ASSISTING_ATOMIC_PRINTER) {
                return $manny;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function atomicPrinterMetadata(?Manny $assistant): array
    {
        $metadata = ['movable' => false];
        if ($assistant === null) {
            return $metadata;
        }

        return $metadata + [
            'assistantMannyId' => $assistant->uid,
            'assistantMannyName' => $assistant->name,
            'task' => $assistant->taskPayload,
        ];
    }

    /**
     * @param array<StorageContainer> $containers
     * @return array<array<string, mixed>>
     */
    private function resourceStocks(NeumannProbe $probe, array $containers): array
    {
        $labels = [
            ResourceComposition::METALS => 'Métaux',
            ResourceComposition::ICE => 'Glace',
            ResourceComposition::CARBON_COMPOUNDS => 'Composés organiques',
        ];
        $stocks = [];
        foreach ($labels as $type => $name) {
            $placements = [];
            $total = 0.0;
            foreach ($containers as $container) {
                $amount = round(max(0.0, (float) ($this->containers->resourceAmounts($container->id)[$type] ?? 0.0)), 4);
                if ($amount <= 0.0) {
                    continue;
                }
                $total = round($total + $amount, 4);
                $placements[] = [
                    'container' => $this->containerSummary($container),
                    'amount' => $amount,
                    'containerSpace' => $amount,
                    'capacityUnit' => ProbeInventory::CAPACITY_UNIT,
                ];
            }
            $stocks[] = [
                'id' => 'probe-' . $probe->id . '-stock-' . str_replace('_', '-', $type),
                'type' => $type,
                'name' => $name,
                'amount' => $total,
                'containerSpace' => $total,
                'capacityUnit' => ProbeInventory::CAPACITY_UNIT,
                'containers' => $placements,
            ];
        }

        return $stocks;
    }

    private function freeCapacityForContainer(NeumannProbe $probe, StorageContainer $container): float
    {
        $used = $this->usedCapacityByContainer($probe, [$container], $this->mannies->findByProbeId($probe->id), $this->items->findByProbeId($probe->id));

        return round(max(0.0, $container->capacity - (float) ($used[$container->id] ?? 0.0)), 4);
    }

    private function placeUnit(NeumannProbe $probe, string $type, float $space): ?StorageContainer
    {
        $space = round(max(0.0, $space), 4);
        foreach ($this->placementCandidates($probe, $type) as $container) {
            if ($this->freeCapacityForContainer($probe, $container) + self::EPSILON >= $space) {
                return $container;
            }
        }

        return null;
    }

    /**
     * @param array<StorageContainer> $containers
     * @param array<int, float> $used
     */
    private function simulateUnitPlacement(NeumannProbe $probe, array $containers, array &$used, string $type, float $space): bool
    {
        $space = round(max(0.0, $space), 4);
        foreach ($this->placementCandidatesFrom($containers, $type) as $container) {
            $free = round(max(0.0, $container->capacity - (float) ($used[$container->id] ?? 0.0)), 4);
            if ($free + self::EPSILON < $space) {
                continue;
            }
            $used[$container->id] = round((float) ($used[$container->id] ?? 0.0) + $space, 4);

            return true;
        }

        return false;
    }

    /**
     * @param array<StorageContainer> $containers
     * @param array<int, float> $used
     */
    private function simulateResourcePlacement(NeumannProbe $probe, array $containers, array &$used, string $type, float $amount): bool
    {
        $remaining = round(max(0.0, $amount), 4);
        foreach ($this->placementCandidatesFrom($containers, $type) as $container) {
            $free = round(max(0.0, $container->capacity - (float) ($used[$container->id] ?? 0.0)), 4);
            if ($free <= self::EPSILON) {
                continue;
            }
            $added = round(min($free, $remaining), 4);
            $used[$container->id] = round((float) ($used[$container->id] ?? 0.0) + $added, 4);
            $remaining = round($remaining - $added, 4);
            if ($remaining <= self::EPSILON) {
                return true;
            }
        }

        return $remaining <= self::EPSILON;
    }

    /**
     * @return array<StorageContainer>
     */
    private function placementCandidates(NeumannProbe $probe, string $type): array
    {
        return $this->placementCandidatesFrom($this->containers->findByProbeId($probe->id), $type);
    }

    /**
     * @param array<StorageContainer> $containers
     * @return array<StorageContainer>
     */
    private function placementCandidatesFrom(array $containers, string $type): array
    {
        $type = $this->normalizeItemType($type);
        $priority = [];
        $normal = [];
        $excluded = [];
        foreach ($containers as $container) {
            if (in_array($type, $container->strictExclusionFilter, true)) {
                continue;
            }
            if (in_array($type, $container->priorityFilter, true)) {
                $priority[] = $container;
                continue;
            }
            if (in_array($type, $container->exclusionFilter, true)) {
                $excluded[] = $container;
                continue;
            }
            $normal[] = $container;
        }

        return [...$priority, ...$normal, ...$excluded];
    }

    private function requiredContainer(NeumannProbe $probe, string $containerUid): StorageContainer
    {
        return $this->containers->findByUidForProbe($probe->id, $containerUid)
            ?? throw new MannyActionException(404, 'storage_container_not_found', 'Storage container not found.');
    }

    private function normalizeResourceType(string $type): string
    {
        try {
            return ResourceComposition::normalizeSelection($type)[0];
        } catch (\InvalidArgumentException) {
            return $this->normalizeItemType($type);
        }
    }

    private function normalizeItemType(string $type): string
    {
        return strtolower(str_replace(['-', ' '], '_', trim($type)));
    }

    /**
     * @param array<mixed> $filter
     * @return array<string>
     */
    private function normalizedFilter(array $filter): array
    {
        $normalized = [];
        foreach ($filter as $value) {
            $type = $this->normalizeItemType((string) $value);
            if ($type === '') {
                continue;
            }
            $normalized[] = $type;
        }

        return array_values(array_unique($normalized));
    }

    private function atomicPrinterSpace(): float
    {
        return max(0.0, Config::float($this->config, 'probe.atomicPrinterContainerSpace', self::ATOMIC_PRINTER_SPACE));
    }

    private function mannyContainerSpace(): float
    {
        return max(0.0, Config::float($this->config, 'manny.containerSpace', Manny::CONTAINER_SPACE));
    }

    private function mannyCargoCapacity(): float
    {
        return max(0.0001, Config::float($this->config, 'manny.cargoCapacity', Manny::CARGO_CAPACITY));
    }

    private function mannyCargoArray(Manny $manny): array
    {
        return array_replace($manny->cargoArray(), ['capacity' => $this->mannyCargoCapacity()]);
    }

    private function maxDeuteriumPercent(): float
    {
        return max(0.0001, Config::float($this->config, 'probe.maxDeuteriumPercent', 100.0));
    }

    private function storageMoveSecondsPerUnit(): int
    {
        return max(1, Config::int($this->config, 'manny.actions.storageMoveSecondsPerUnit', 10));
    }

    private function storageMoveEceStep(): float
    {
        return max(0.0001, Config::float($this->config, 'manny.actions.storageMoveEceStep', 0.05));
    }
}
