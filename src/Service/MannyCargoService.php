<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Domain\ScutRelay;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Sector\SectorDriftingItem;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\UniverseObject;

final class MannyCargoService
{
    public function __construct(
        private readonly MannyRepository $mannies,
        private readonly SectorService $sectors,
        private readonly ProbeStorageService $storage,
        private readonly array $config = [],
        private readonly ?ScutNetworkService $scut = null,
    ) {
    }

    public function isSalvageableTarget(UniverseObject|ScutRelay $object): bool
    {
        return ($object instanceof SectorManny && $object->getState() === SectorManny::STATE_ABANDONED)
            || ($object instanceof SectorDriftingItem && $object->getQuantity() > 0 && $object->getContainerSpace() > 0.0)
            || ($object instanceof SectorDetachedContainer && $object->getMode() === SectorDetachedContainer::MODE_DRIFTING)
            || ($object instanceof ScutRelay && !$object->isOn());
    }

    /**
     * @return array<string, mixed>
     */
    public function salvageTargetArray(UniverseObject|ScutRelay $object): array
    {
        if ($object instanceof ScutRelay) {
            return [
                'id' => (string) $object->id,
                'type' => ProbeItem::TYPE_SCUT_RELAY,
                'name' => ProbeItem::SCUT_RELAY_NAME,
                'status' => $object->status,
                'containerSpace' => CraftingRecipeCatalog::SCUT_RELAY_CONTAINER_SPACE,
                'capacityUnit' => ProbeInventory::CAPACITY_UNIT,
            ];
        }

        return [
            'id' => $object->getId(),
            'type' => $object->getType()->value,
            'name' => $object->getName(),
        ] + ($object instanceof SectorManny ? [
            'mannyUid' => $object->getMannyUid(),
            'mannyState' => $object->getState(),
        ] : []) + ($object instanceof SectorDriftingItem ? [
            'itemType' => $object->getItemType(),
            'quantity' => $object->getQuantity(),
            'containerSpace' => $object->getContainerSpace(),
            'capacityUnit' => $object->getCapacityUnit(),
        ] : []) + ($object instanceof SectorDetachedContainer ? [
            'mode' => $object->getMode(),
            'capacity' => $object->getCapacity(),
            'capacityUnit' => $object->getCapacityUnit(),
            'targetObjectId' => $object->getTargetObjectId(),
        ] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function completeSalvageTarget(NeumannProbe $probe, SectorContent $sector, UniverseObject|ScutRelay $target): array
    {
        if ($target instanceof ScutRelay) {
            return $this->completeScutRelaySalvageTarget($sector, $target);
        }

        if (!$target instanceof SectorManny || $target->getState() !== SectorManny::STATE_ABANDONED) {
            return [
                'result' => 'failed',
                'failureReason' => 'target_unavailable',
            ];
        }

        $recovered = $this->mannies->findByUid($target->getMannyUid());
        if (
            $recovered === null
            || $recovered->probeId !== null
            || $recovered->sector === null
            || !$recovered->sector->equals($sector->getCoordinates())
        ) {
            return [
                'result' => 'failed',
                'failureReason' => 'target_unavailable',
            ];
        }

        if (!$sector->removeObjectById($target->getId())) {
            return [
                'result' => 'failed',
                'failureReason' => 'target_unavailable',
            ];
        }
        $this->sectors->saveSector($sector);

        $recovered->probeId = $probe->id;
        $recovered->name = $this->uniqueMannyNameForProbe($probe, $recovered->name, $recovered->id);
        $recovered->locationType = Manny::LOCATION_SECTOR;
        $recovered->sector = $sector->getCoordinates();
        $this->clearTask($recovered);
        $this->mannies->save($recovered);

        return [
            'result' => 'success',
            'salvaged' => [
                'type' => 'manny',
                'id' => $recovered->uid,
                'name' => $recovered->name,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $resultPayload
     */
    public function finishSalvageActor(Manny $manny, NeumannProbe $probe, array $resultPayload): void
    {
        if (!$this->canAcceptMannyDocking($probe, $manny, $resultPayload)) {
            $this->waitForStorageSpace($manny, ['reason' => 'salvage_return'] + $resultPayload);
            return;
        }

        $this->transferMannyCargoToProbe($manny, $probe);
        $this->deliverReservedSalvageItems($probe, $manny, $resultPayload);
        $this->deliverReservedDetachedContainer($probe, $resultPayload);
        if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
            $this->waitForStorageSpace($manny, ['reason' => 'salvage_return'] + $resultPayload);
            return;
        }
        $this->removeMannyFromSector($manny);
        $manny->locationType = Manny::LOCATION_PROBE;
        $manny->sector = null;
        $this->clearTask($manny, $resultPayload);
    }

    /**
     * @return array<string, mixed>
     */
    public function reserveDriftingItemForSalvage(NeumannProbe $probe, SectorDriftingItem $target): array
    {
        $containerSpace = round(max(0.0, $target->getContainerSpace()), 4);
        if ($containerSpace <= 0.0) {
            throw new MannyActionException(422, 'invalid_salvage_target', 'This object cannot be recovered by a Manny.');
        }

        $maximumQuantity = (int) floor(($this->mannyCargoCapacity() + 0.00001) / $containerSpace);
        if ($maximumQuantity <= 0) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'This drifting object is too large for a Manny to carry.');
        }

        $quantity = min($target->getQuantity(), $maximumQuantity);
        if ($quantity <= 0) {
            throw new MannyActionException(422, 'invalid_salvage_target', 'This object cannot be recovered by a Manny.');
        }

        $sector = $this->sectors->getOrCreateSector($probe->currentSector);
        $remaining = $target->getQuantity() - $quantity;
        if ($remaining > 0) {
            $sector->replaceObject($target->withQuantity($remaining));
        } else {
            $sector->removeObjectById($target->getId());
        }
        $this->sectors->saveSector($sector);

        return [
            'objectId' => $target->getId(),
            'type' => $target->getItemType(),
            'name' => $target->getName() ?? $this->itemDisplayName($target->getItemType()),
            'quantity' => $quantity,
            'containerSpace' => $containerSpace,
            'capacityUnit' => $target->getCapacityUnit(),
        ];
    }

    public function restoreReservedSalvageItem(Manny $manny): void
    {
        $reservedItem = $this->reservedSalvageItemPayload($manny);
        if ($reservedItem === null || $manny->sector === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        $this->addDriftingItemToSector(
            $sector,
            (string) $reservedItem['type'],
            (string) $reservedItem['name'],
            (float) $reservedItem['containerSpace'],
            (int) $reservedItem['quantity'],
        );
        $this->sectors->saveSector($sector);
    }

    /**
     * @return array<string, mixed>
     */
    public function reserveDetachedContainerForSalvage(NeumannProbe $probe, SectorDetachedContainer $target): array
    {
        $sector = $this->sectors->getOrCreateSector($probe->currentSector);
        if ($target->getMode() === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID) {
            $removed = $sector->removeHiddenDetachedContainerById($target->getId());
        } else {
            $removed = $sector->removeObjectById($target->getId()) ? $target : null;
        }
        if (!$removed instanceof SectorDetachedContainer) {
            throw new MannyActionException(422, 'detached_container_not_recoverable', 'This detached container is no longer recoverable.');
        }
        $this->sectors->saveSector($sector);

        return $this->detachedContainerReservedPayload($removed);
    }

    public function restoreReservedDetachedContainer(Manny $manny): void
    {
        $reserved = $this->reservedDetachedContainerPayload($manny);
        if ($reserved === null || $manny->sector === null) {
            return;
        }

        $container = SectorDetachedContainer::fromArray($reserved['object']);
        $sector = $this->sectors->getOrCreateSector($manny->sector);
        if ($container->getMode() === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID) {
            if ($sector->findHiddenDetachedContainerById($container->getId()) === null) {
                $sector->addHiddenDetachedContainer($container);
            }
        } elseif (!$sector->replaceObject($container)) {
            $sector->addObject($container);
        }
        $this->sectors->saveSector($sector);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function restoreProbeAssemblyIngredientsAsDrifting(Manny $manny): array
    {
        if ($manny->sector === null) {
            return [];
        }

        $ingredients = [];
        $consumedItems = is_array($manny->taskPayload['consumedItems'] ?? null) ? $manny->taskPayload['consumedItems'] : [];
        foreach ($consumedItems as $item) {
            if (is_array($item)) {
                $ingredients[] = $item;
            }
        }

        $consumedContainers = is_array($manny->taskPayload['consumedContainers'] ?? null) ? $manny->taskPayload['consumedContainers'] : [];
        foreach ($consumedContainers as $container) {
            if (is_array($container) && is_array($container['item'] ?? null)) {
                $ingredients[] = $container['item'];
            }
        }

        if ($ingredients === []) {
            return [];
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        $dropped = [];
        foreach ($ingredients as $item) {
            $type = (string) ($item['type'] ?? '');
            if ($type === '') {
                continue;
            }

            $name = (string) ($item['name'] ?? $this->itemDisplayName($type));
            $containerSpace = round(max(0.0, (float) ($item['containerSpace'] ?? 0.0)), 4);
            $drifting = $this->addDriftingItemToSector($sector, $type, $name, $containerSpace, 1);
            $dropped[] = [
                'type' => 'drifting_item',
                'itemType' => $type,
                'name' => $drifting->getName(),
                'quantity' => 1,
                'driftingQuantity' => $drifting->getQuantity(),
                'objectId' => $drifting->getId(),
                'containerSpace' => $drifting->getContainerSpace(),
                'capacityUnit' => $drifting->getCapacityUnit(),
            ];
        }

        if ($dropped !== []) {
            $this->sectors->saveSector($sector);
        }

        return $dropped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dropWaitingMannyCargo(Manny $manny): array
    {
        $dropped = [];
        $resourceCargo = $this->mannyResourceCargo($manny);
        if ($resourceCargo !== []) {
            $dropped[] = [
                'type' => 'resources',
                'resources' => $resourceCargo,
            ];
        }

        $reservedItem = $this->reservedSalvageItemPayload($manny);
        if ($reservedItem !== null) {
            $this->restoreReservedSalvageItem($manny);
            $dropped[] = [
                'type' => 'drifting_item',
                'objectId' => $reservedItem['objectId'],
                'itemType' => $reservedItem['type'],
                'name' => $reservedItem['name'],
                'quantity' => $reservedItem['quantity'],
                'containerSpace' => $reservedItem['containerSpace'],
                'capacityUnit' => $reservedItem['capacityUnit'],
            ];
        }

        $reservedDetachedContainer = $this->reservedDetachedContainerPayload($manny);
        if ($reservedDetachedContainer !== null) {
            $this->restoreReservedDetachedContainer($manny);
            $dropped[] = [
                'type' => 'detached_storage_container',
                'objectId' => $reservedDetachedContainer['objectId'],
                'mode' => $reservedDetachedContainer['mode'],
                'capacity' => $reservedDetachedContainer['capacity'],
                'capacityUnit' => $reservedDetachedContainer['capacityUnit'],
                'targetObjectId' => $reservedDetachedContainer['targetObjectId'],
            ];
        }

        $salvagedManny = $this->restoreSalvagedMannyCargo($manny);
        if ($salvagedManny !== null) {
            $dropped[] = $salvagedManny;
        }

        return $dropped;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function canAcceptMannyDocking(NeumannProbe $probe, Manny $manny, array $payload = []): bool
    {
        return $this->storage->canStoreIncoming(
            $probe,
            [
                ResourceComposition::METALS => $manny->cargoMetals,
                ResourceComposition::ICE => $manny->cargoIce,
                ResourceComposition::CARBON_COMPOUNDS => $manny->cargoOrganicCompounds,
            ],
            [
                ...$this->reservedSalvageItemUnits($payload),
                ['type' => 'manny', 'space' => $this->mannyContainerSpace()],
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function waitForStorageSpace(Manny $manny, array $payload = []): void
    {
        if ($manny->sector === null) {
            return;
        }

        $manny->currentTask = Manny::TASK_WAITING_FOR_SPACE;
        $manny->taskStartedAt ??= gmdate('c');
        $manny->taskEndsAt = null;
        $manny->taskPayload = array_merge($manny->taskPayload, [
            'reason' => 'return_to_probe',
            'waitingFor' => 'storage_space',
        ], $payload);
    }

    public function transferMannyCargoToProbe(Manny $manny, NeumannProbe $probe): void
    {
        $manny->cargoDeuterium = round($manny->cargoDeuterium - $this->transferResourceToProbe($probe, 'deuterium', $manny->cargoDeuterium), 4);
        $manny->cargoMetals = round($manny->cargoMetals - $this->transferResourceToProbe($probe, 'metals', $manny->cargoMetals), 4);
        $manny->cargoIce = round($manny->cargoIce - $this->transferResourceToProbe($probe, 'ice', $manny->cargoIce), 4);
        $manny->cargoOrganicCompounds = round($manny->cargoOrganicCompounds - $this->transferResourceToProbe($probe, 'carbon_compounds', $manny->cargoOrganicCompounds), 4);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function deliverReservedSalvageItems(NeumannProbe $probe, Manny $manny, array $payload): void
    {
        $reservedItem = $this->reservedSalvageItemPayloadFrom($payload);
        if ($reservedItem === null) {
            return;
        }

        $quantity = (int) $reservedItem['quantity'];
        for ($index = 0; $index < $quantity; $index++) {
            $this->storage->addItem(
                $probe,
                (string) $reservedItem['type'],
                (string) $reservedItem['name'],
                (float) $reservedItem['containerSpace'],
                [
                    'source' => 'salvaged_drifting_item',
                    'recoveredByMannyId' => $manny->uid,
                    'recoveredByMannyName' => $manny->name,
                    'recoveredAt' => gmdate('c'),
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function deliverReservedDetachedContainer(NeumannProbe $probe, array $payload): void
    {
        $reserved = $this->reservedDetachedContainerPayloadFrom($payload);
        if ($reserved === null) {
            return;
        }

        $object = is_array($reserved['object'] ?? null) ? $reserved['object'] : [];
        $snapshot = is_array($object['payload'] ?? null) ? $object['payload'] : [];
        $this->storage->restoreDetachedContainerSnapshot($probe, $snapshot);
    }

    public function reservedSalvageItemPayload(Manny $manny): ?array
    {
        return $this->reservedSalvageItemPayloadFrom($manny->taskPayload);
    }

    public function reservedDetachedContainerPayload(Manny $manny): ?array
    {
        return $this->reservedDetachedContainerPayloadFrom($manny->taskPayload);
    }

    public function hasReservedDeliveryPayload(Manny $manny): bool
    {
        return $this->reservedSalvageItemPayload($manny) !== null
            || $this->reservedDetachedContainerPayload($manny) !== null;
    }

    public function clearMannyCargo(Manny $manny): void
    {
        $manny->cargoDeuterium = 0.0;
        $manny->cargoMetals = 0.0;
        $manny->cargoIce = 0.0;
        $manny->cargoOrganicCompounds = 0.0;
    }

    public function mannyCargoIsEmpty(Manny $manny): bool
    {
        return $manny->cargoDeuterium <= 0.0001
            && $manny->cargoMetals <= 0.0001
            && $manny->cargoIce <= 0.0001
            && $manny->cargoOrganicCompounds <= 0.0001;
    }

    /**
     * @return array<string, mixed>
     */
    private function completeScutRelaySalvageTarget(SectorContent $sector, ScutRelay $target): array
    {
        if ($this->scut === null) {
            return [
                'result' => 'failed',
                'failureReason' => 'target_unavailable',
            ];
        }
        $relay = $this->scut->relayById($target->id);
        if ($relay === null || $relay->isOn() || !$relay->sector->equals($sector->getCoordinates())) {
            return [
                'result' => 'failed',
                'failureReason' => 'target_unavailable',
            ];
        }

        $this->scut->deleteRelay($relay->id);
        $reservedItem = $this->scutRelayReservedItemPayload($relay);

        return [
            'result' => 'success',
            'reservedItem' => $reservedItem,
            'salvaged' => [
                'type' => ProbeItem::TYPE_SCUT_RELAY,
                'name' => ProbeItem::SCUT_RELAY_NAME,
                'quantity' => 1,
                'containerSpace' => $reservedItem['containerSpace'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scutRelayReservedItemPayload(ScutRelay $relay): array
    {
        return [
            'objectId' => (string) $relay->id,
            'type' => ProbeItem::TYPE_SCUT_RELAY,
            'name' => ProbeItem::SCUT_RELAY_NAME,
            'quantity' => 1,
            'containerSpace' => CraftingRecipeCatalog::SCUT_RELAY_CONTAINER_SPACE,
            'capacityUnit' => ProbeInventory::CAPACITY_UNIT,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function mannyResourceCargo(Manny $manny): array
    {
        $resources = [
            ResourceComposition::DEUTERIUM => $manny->cargoDeuterium,
            ResourceComposition::METALS => $manny->cargoMetals,
            ResourceComposition::ICE => $manny->cargoIce,
            ResourceComposition::CARBON_COMPOUNDS => $manny->cargoOrganicCompounds,
        ];

        return array_filter(
            array_map(static fn(float $amount): float => round(max(0.0, $amount), 4), $resources),
            static fn(float $amount): bool => $amount > 0.0001,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoreSalvagedMannyCargo(Manny $manny): ?array
    {
        $salvaged = $manny->taskPayload['salvaged'] ?? null;
        if (!is_array($salvaged) || ($salvaged['type'] ?? null) !== 'manny' || !is_string($salvaged['id'] ?? null) || $manny->sector === null) {
            return null;
        }

        $recovered = $this->mannies->findByUid($salvaged['id']);
        if ($recovered === null || $recovered->id === $manny->id || $recovered->sector === null || !$recovered->sector->equals($manny->sector)) {
            return null;
        }

        $recovered->probeId = null;
        $recovered->storageContainerId = null;
        $recovered->locationType = Manny::LOCATION_SECTOR;
        $recovered->sector = $manny->sector;
        $this->clearTask($recovered);
        $this->mannies->save($recovered);

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        $object = new SectorManny(
            SectorManny::objectIdForUid($recovered->uid),
            $recovered->name,
            $recovered->uid,
            SectorManny::STATE_ABANDONED,
            $this->mannyCargoArray($recovered),
            'Manny abandoned after its carrier dropped cargo.',
        );
        if (!$sector->replaceObject($object)) {
            $sector->addObject($object);
        }
        $this->sectors->saveSector($sector);

        return [
            'type' => 'manny',
            'id' => $recovered->uid,
            'name' => $recovered->name,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function reservedSalvageItemPayloadFrom(array $payload): ?array
    {
        $reservedItem = $payload['reservedItem'] ?? null;
        if (!is_array($reservedItem)) {
            return null;
        }

        $type = (string) ($reservedItem['type'] ?? '');
        $name = (string) ($reservedItem['name'] ?? $this->itemDisplayName($type));
        $quantity = max(0, (int) ($reservedItem['quantity'] ?? 0));
        $containerSpace = round(max(0.0, (float) ($reservedItem['containerSpace'] ?? 0.0)), 4);
        if ($type === '' || $quantity <= 0 || $containerSpace <= 0.0) {
            return null;
        }

        return [
            'objectId' => (string) ($reservedItem['objectId'] ?? SectorDriftingItem::objectIdForItemType($type)),
            'type' => $type,
            'name' => $name,
            'quantity' => $quantity,
            'containerSpace' => $containerSpace,
            'capacityUnit' => (string) ($reservedItem['capacityUnit'] ?? ProbeInventory::CAPACITY_UNIT),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function reservedDetachedContainerPayloadFrom(array $payload): ?array
    {
        $reserved = $payload['reservedDetachedContainer'] ?? null;
        if (!is_array($reserved) || !is_array($reserved['object'] ?? null)) {
            return null;
        }
        $object = SectorDetachedContainer::fromArray($reserved['object']);

        return [
            'objectId' => $object->getId(),
            'mode' => $object->getMode(),
            'capacity' => $object->getCapacity(),
            'capacityUnit' => $object->getCapacityUnit(),
            'targetObjectId' => $object->getTargetObjectId(),
            'object' => $object->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detachedContainerReservedPayload(SectorDetachedContainer $container): array
    {
        return [
            'objectId' => $container->getId(),
            'mode' => $container->getMode(),
            'capacity' => $container->getCapacity(),
            'capacityUnit' => $container->getCapacityUnit(),
            'targetObjectId' => $container->getTargetObjectId(),
            'object' => $container->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{type:string, space:float}>
     */
    private function reservedSalvageItemUnits(array $payload): array
    {
        $reservedItem = $this->reservedSalvageItemPayloadFrom($payload);
        if ($reservedItem === null) {
            return [];
        }

        $units = [];
        for ($index = 0; $index < (int) $reservedItem['quantity']; $index++) {
            $units[] = [
                'type' => (string) $reservedItem['type'],
                'space' => (float) $reservedItem['containerSpace'],
            ];
        }

        return $units;
    }

    private function uniqueMannyNameForProbe(NeumannProbe $probe, string $name, int $exceptId): string
    {
        $base = trim($name) !== '' ? trim($name) : 'Manny récupérée';
        if (!$this->mannies->nameExistsForProbe($probe->id, $base, $exceptId)) {
            return $base;
        }

        for ($index = 2; $index <= 999; $index++) {
            $candidate = $base . ' ' . $index;
            if (!$this->mannies->nameExistsForProbe($probe->id, $candidate, $exceptId)) {
                return $candidate;
            }
        }

        return $base . ' ' . bin2hex(random_bytes(3));
    }

    private function transferResourceToProbe(NeumannProbe $probe, string $resourceType, float $amount): float
    {
        $amount = round(max(0.0, $amount), 4);
        if ($amount <= 0.0) {
            return 0.0;
        }

        return $this->storage->addResource($probe, $resourceType, $amount);
    }

    private function removeMannyFromSector(Manny $manny): void
    {
        if ($manny->sector === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        if ($sector->removeObjectById(SectorManny::objectIdForUid($manny->uid))) {
            $this->sectors->saveSector($sector);
        }
    }

    private function addDriftingItemToSector(SectorContent $sector, string $itemType, string $name, float $containerSpace, int $quantity): SectorDriftingItem
    {
        $quantity = max(0, $quantity);
        $objectId = SectorDriftingItem::objectIdForItemType($itemType);
        $existing = $sector->findObjectById($objectId);
        if ($existing instanceof SectorDriftingItem) {
            $drifting = $existing->withQuantity($existing->getQuantity() + $quantity);
            $sector->replaceObject($drifting);

            return $drifting;
        }

        $drifting = new SectorDriftingItem(
            $objectId,
            $this->itemDisplayName($itemType, $name),
            $itemType,
            $quantity,
            round(max(0.0, $containerSpace), 4),
            ProbeInventory::CAPACITY_UNIT,
            'Inventory items drifting in open space.',
        );
        $sector->addObject($drifting);

        return $drifting;
    }

    private function itemDisplayName(string $type, ?string $fallback = null): string
    {
        return match ($type) {
            ProbeItem::TYPE_WAYPOINT_BOOKMARK => ProbeItem::WAYPOINT_BOOKMARK_NAME,
            ProbeItem::TYPE_STEEL_BAR => ProbeItem::STEEL_BAR_NAME,
            ProbeItem::TYPE_STEEL_PLATE => ProbeItem::STEEL_PLATE_NAME,
            ProbeItem::TYPE_ADDITIONAL_CONTAINER => ProbeItem::ADDITIONAL_CONTAINER_NAME,
            ProbeItem::TYPE_MICRO_CONDUCTOR => ProbeItem::MICRO_CONDUCTOR_NAME,
            ProbeItem::TYPE_CERAMIC_INSULATOR => ProbeItem::CERAMIC_INSULATOR_NAME,
            ProbeItem::TYPE_CRYSTAL_SUBSTRATE => ProbeItem::CRYSTAL_SUBSTRATE_NAME,
            ProbeItem::TYPE_DOPANT_MATRIX => ProbeItem::DOPANT_MATRIX_NAME,
            ProbeItem::TYPE_INTEGRATED_CIRCUIT => ProbeItem::INTEGRATED_CIRCUIT_NAME,
            ProbeItem::TYPE_ELECTRIC_MOTOR => ProbeItem::ELECTRIC_MOTOR_NAME,
            ProbeItem::TYPE_BATTERY_PACK => ProbeItem::BATTERY_PACK_NAME,
            ProbeItem::TYPE_LINEAR_ACTUATOR => ProbeItem::LINEAR_ACTUATOR_NAME,
            ProbeItem::TYPE_ATOMIC_PRINTER_PART => ProbeItem::ATOMIC_PRINTER_PART_NAME,
            ProbeItem::TYPE_DEUTERIUM_ENGINE => ProbeItem::DEUTERIUM_ENGINE_NAME,
            ProbeItem::TYPE_SOLAR_PANEL => ProbeItem::SOLAR_PANEL_NAME,
            ProbeItem::TYPE_SCUT_RELAY => ProbeItem::SCUT_RELAY_NAME,
            ProbeItem::TYPE_SCUT_TRANSIT_BEACON => ProbeItem::SCUT_TRANSIT_BEACON_NAME,
            ProbeItem::TYPE_THERMAL_PROTECTION_SHELL => ProbeItem::THERMAL_PROTECTION_SHELL_NAME,
            ProbeItem::TYPE_PARACHUTE_PACK => ProbeItem::PARACHUTE_PACK_NAME,
            ProbeItem::TYPE_DESCENT_GUIDANCE_MODULE => ProbeItem::DESCENT_GUIDANCE_MODULE_NAME,
            ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT => ProbeItem::ATMOSPHERIC_DROP_KIT_NAME,
            default => $fallback !== null && trim($fallback) !== '' ? $fallback : $type,
        };
    }

    private function clearTask(Manny $manny, array $payload = []): void
    {
        $manny->currentTask = null;
        $manny->taskStartedAt = null;
        $manny->taskEndsAt = null;
        $manny->taskPayload = $payload;
    }

    private function mannyCargoArray(Manny $manny): array
    {
        return array_replace($manny->cargoArray(), ['capacity' => $this->mannyCargoCapacity()]);
    }

    private function mannyCargoCapacity(): float
    {
        return max(0.0001, Config::float($this->config, 'manny.cargoCapacity', Manny::CARGO_CAPACITY));
    }

    private function mannyContainerSpace(): float
    {
        return max(0.0, Config::float($this->config, 'manny.containerSpace', Manny::CONTAINER_SPACE));
    }
}
