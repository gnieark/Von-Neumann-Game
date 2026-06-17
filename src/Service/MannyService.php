<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Sector\SectorDriftingItem;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\UniverseObject;

final class MannyService
{
    public const REPAIR_SECONDS_PER_INTEGRITY_PERCENT = 600;
    public const REPAIR_METALS_PER_INTEGRITY_PERCENT = 0.01;
    public const MINING_TRAVEL_SECONDS = 900;
    public const MINING_AMOUNT_PER_TICK = 0.01;
    public const MINING_TICK_SECONDS = 300;
    public const SALVAGE_SECONDS = 300;
    public const STORAGE_MOVE_SECONDS_PER_UNIT = 10;
    public const STORAGE_MOVE_ECE_STEP = 0.05;
    public const WAYPOINT_BOOKMARK_INSTALL_SECONDS = 10;
    public const MANNY_CARGO_CAPACITY = Manny::CARGO_CAPACITY;
    public const MANNY_CONTAINER_SPACE = Manny::CONTAINER_SPACE;
    public const MOON_MASS_EARTH_UNITS = 0.0123;
    public const MAX_INTEGRITY_PERCENT = 100.0;
    public const WAYPOINT_BOOKMARK_METALS_COST = CraftingRecipeCatalog::WAYPOINT_BOOKMARK_METALS_COST;
    public const WAYPOINT_BOOKMARK_CONTAINER_SPACE = CraftingRecipeCatalog::WAYPOINT_BOOKMARK_CONTAINER_SPACE;
    public const WAYPOINT_BOOKMARK_CRAFTING_SECONDS = CraftingRecipeCatalog::WAYPOINT_BOOKMARK_CRAFTING_SECONDS;

    private readonly WaypointBookmarkService $bookmarks;

    public function __construct(
        private readonly MannyRepository $mannies,
        private readonly NeumannProbeRepository $probes,
        private readonly SectorService $sectors,
        private readonly ProbeItemRepository $items,
        private readonly ProbeStorageService $storage,
        private readonly array $config = [],
        ?WaypointBookmarkService $bookmarks = null,
    ) {
        $this->bookmarks = $bookmarks ?? new WaypointBookmarkService($items, $sectors);
    }

    /**
     * @return array<Manny>
     */
    public function manniesForProbe(NeumannProbe $probe): array
    {
        $this->storage->ensureProbeStorage($probe);
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            $this->refreshMannyState($manny, $probe);
        }
        $this->recoverForgottenManniesInCurrentSector($probe);

        return $this->mannies->findByProbeId($probe->id);
    }

    public function renameManny(NeumannProbe $probe, string $uid, string $name): Manny
    {
        $manny = $this->requiredManny($probe, $uid);
        $name = trim($name);
        if ($name === '' || strlen($name) > 40) {
            throw new MannyActionException(400, 'bad_request', 'Manny name must contain 1 to 40 characters.');
        }
        if ($this->mannies->nameExistsForProbe($probe->id, $name, $manny->id)) {
            throw new MannyActionException(409, 'duplicate_manny_name', 'Manny names must be unique for this probe.');
        }

        $manny->name = $name;
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startRepair(NeumannProbe $probe, string $uid, float $integrityPercent): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to repair it.');
        }

        $integrityPercent = round($integrityPercent, 2);
        if ($integrityPercent <= 0) {
            throw new MannyActionException(400, 'bad_request', 'Repair percent must be greater than zero.');
        }
        $missingIntegrity = round(max(0.0, $this->maxIntegrityPercent() - $probe->integrityPercent), 2);
        if ($missingIntegrity <= 0.0001) {
            throw new MannyActionException(409, 'probe_integrity_full', 'The probe integrity is already full.');
        }

        $integrityPercent = min($integrityPercent, $missingIntegrity);
        $metalsCost = round($integrityPercent * $this->repairMetalsPerIntegrityPercent(), 4);
        if ($this->storage->resourceStock($probe, ResourceComposition::METALS) + 0.00001 < $metalsCost) {
            throw new MannyActionException(422, 'insufficient_metals', 'Insufficient metals in probe inventory for this repair.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->storage->consumeResource($probe, ResourceComposition::METALS, $metalsCost);

        $manny->currentTask = Manny::TASK_REPAIR;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . (int) ceil($integrityPercent * $this->repairSecondsPerIntegrityPercent()) . ' seconds')->format('c');
        $manny->taskPayload = [
            'integrityPercent' => $integrityPercent,
            'metalsCost' => $metalsCost,
        ];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startMining(NeumannProbe $probe, string $uid, string $objectId, string|array $resourceTypes, float $targetAmount): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);

        try {
            $selectedResources = ResourceComposition::normalizeSelection($resourceTypes);
        } catch (\InvalidArgumentException $e) {
            throw new MannyActionException(400, 'bad_request', $e->getMessage());
        }
        $targetAmount = round($targetAmount, 4);
        if ($targetAmount <= 0) {
            throw new MannyActionException(400, 'bad_request', 'Mining target amount must be greater than zero.');
        }

        $target = $this->findObjectInCurrentSector($probe, $objectId);
        if ($target === null || !$this->isMineableObject($target)) {
            throw new MannyActionException(422, 'invalid_mining_target', 'This object cannot be mined by a Manny.');
        }

        $availableAmounts = $target instanceof Asteroid
            ? $this->availableAsteroidResourceAmountsForOrders($probe, $target)
            : null;
        $composition = $availableAmounts !== null
            ? ResourceComposition::fromAmounts($availableAmounts)
            : $this->resourceComposition($target);
        $available = ResourceComposition::availableTypes($composition);
        $unavailable = array_diff($selectedResources, $available);
        if ($unavailable !== []) {
            throw new MannyActionException(422, 'resource_unavailable', 'The requested resource is not present on this object.');
        }

        $resourceProfile = ResourceComposition::profileForSelection($composition, $selectedResources);
        if ($target instanceof Asteroid && $availableAmounts !== null) {
            $this->ensureAsteroidHasResources($availableAmounts, $resourceProfile, $targetAmount);
        }
        $artificialObjectDetected = $target instanceof Asteroid
            ? $this->hiddenDetachedContainerDetection($this->sectors->getOrCreateSector($probe->currentSector), $target->getId())
            : null;
        if (!$this->storage->canStoreIncoming(
            $probe,
            $this->resourceAmountsForTotal($targetAmount, $resourceProfile),
            [['type' => 'manny', 'space' => $this->mannyContainerSpace()]],
        )) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for this mining target.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $manny->currentTask = Manny::TASK_MINING;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $this->miningDurationSeconds($targetAmount) . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'resourceType' => $selectedResources[0],
            'resourceTypes' => $selectedResources,
            'targetAmount' => $targetAmount,
            'depositedAmount' => 0.0,
            'depositedResources' => [],
            'extractedAmount' => 0.0,
            'extractedResources' => [],
            'availableResources' => $available,
            'resourceComposition' => $composition,
            'resourceProfile' => $resourceProfile,
            'target' => $this->miningTargetArray($target),
        ] + ($artificialObjectDetected !== null ? ['artificialObjectDetected' => $artificialObjectDetected] : []);
        $manny->cargoDeuterium = 0.0;
        $manny->cargoMetals = 0.0;
        $manny->cargoIce = 0.0;
        $manny->cargoOrganicCompounds = 0.0;
        $this->storage->releaseMannyFromStorage($manny);
        $this->removeMannyFromSector($manny);
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startCrafting(NeumannProbe $probe, string $uid, string $recipe): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to craft.');
        }

        $recipe = CraftingRecipeCatalog::normalizeId($recipe);
        $recipeDefinition = CraftingRecipeCatalog::find($recipe, $this->craftingConfig());
        if ($recipeDefinition === null || !$this->recipeCraftableBy($recipeDefinition, CraftingRecipeCatalog::FABRICATOR_MANNY)) {
            throw new MannyActionException(400, 'invalid_recipe', 'Unknown crafting recipe.');
        }
        $craftingPlan = $this->craftingPlan($probe, $recipeDefinition);
        $freeAfterConsumption = round(
            $this->freeCargoCapacity($probe)
            + $this->cargoSpaceFreedByResourceCosts($craftingPlan['resourceCosts'])
            + $this->cargoSpaceFreedByConsumedItems($craftingPlan['consumedItems']),
            4,
        );
        if ($freeAfterConsumption + 0.00001 < (float) ($craftingPlan['output']['containerSpace'] ?? 0.0)) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for the crafted item.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->consumeCraftingPlan($probe, $craftingPlan);
        $this->probes->save($probe);

        $manny->currentTask = Manny::TASK_CRAFTING;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . (int) $craftingPlan['durationSeconds'] . ' seconds')->format('c');
        $manny->taskPayload = [
            'craftingRunId' => $this->newCraftingRunId(),
            'recipe' => $recipe,
            'recipeName' => (string) ($recipeDefinition['name'] ?? $recipe),
            'durationSeconds' => (int) $craftingPlan['durationSeconds'],
            'resourceCosts' => $craftingPlan['resourceCosts'],
            'metalsCost' => round((float) ($craftingPlan['resourceCosts']['metals'] ?? 0.0), 4),
            'consumedItems' => $craftingPlan['consumedItems'],
            'output' => $craftingPlan['output'],
            'containerSpace' => (float) ($craftingPlan['output']['containerSpace'] ?? 0.0),
        ];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startAtomicPrinterCrafting(NeumannProbe $probe, string $recipe): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $this->refreshAllMannyStates($probe);
        if ($this->atomicPrinterAssistant($probe) !== null) {
            throw new MannyActionException(409, 'atomic_printer_busy', 'The atomic printer is already executing an order.');
        }

        $manny = $this->availableAtomicPrinterAssistant($probe)
            ?? throw new MannyActionException(409, 'no_available_manny', 'No available Manny can assist the atomic printer.');

        $recipe = CraftingRecipeCatalog::normalizeId($recipe);
        $recipeDefinition = CraftingRecipeCatalog::find($recipe, $this->craftingConfig());
        if ($recipeDefinition === null || !$this->recipeCraftableBy($recipeDefinition, CraftingRecipeCatalog::FABRICATOR_ATOMIC_PRINTER)) {
            throw new MannyActionException(400, 'invalid_recipe', 'Unknown atomic-printer recipe.');
        }

        $craftingPlan = $this->craftingPlan($probe, $recipeDefinition);
        $freeAfterConsumption = round(
            $this->freeCargoCapacity($probe)
            + $this->cargoSpaceFreedByResourceCosts($craftingPlan['resourceCosts'])
            + $this->cargoSpaceFreedByConsumedItems($craftingPlan['consumedItems']),
            4,
        );
        if ($freeAfterConsumption + 0.00001 < (float) ($craftingPlan['output']['containerSpace'] ?? 0.0)) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for the crafted item.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->consumeCraftingPlan($probe, $craftingPlan);
        $this->probes->save($probe);

        $manny->currentTask = Manny::TASK_ASSISTING_ATOMIC_PRINTER;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . (int) $craftingPlan['durationSeconds'] . ' seconds')->format('c');
        $manny->taskPayload = [
            'craftingRunId' => $this->newCraftingRunId(),
            'recipe' => $recipe,
            'recipeName' => (string) ($recipeDefinition['name'] ?? $recipe),
            'durationSeconds' => (int) $craftingPlan['durationSeconds'],
            'resourceCosts' => $craftingPlan['resourceCosts'],
            'metalsCost' => round((float) ($craftingPlan['resourceCosts']['metals'] ?? 0.0), 4),
            'consumedItems' => $craftingPlan['consumedItems'],
            'output' => $craftingPlan['output'],
            'containerSpace' => (float) ($craftingPlan['output']['containerSpace'] ?? 0.0),
            'fabricator' => CraftingRecipeCatalog::FABRICATOR_ATOMIC_PRINTER,
            'printerId' => 'probe-' . $probe->id . '-atomic-3d-printer',
        ];
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    public function startSalvage(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);

        $target = $this->findObjectInCurrentSector($probe, $objectId);
        if ($target === null || !$this->isSalvageableObject($target)) {
            throw new MannyActionException(422, 'invalid_salvage_target', 'This object cannot be recovered by a Manny.');
        }

        $reservedItem = $target instanceof SectorDriftingItem
            ? $this->reserveDriftingItemForSalvage($probe, $target)
            : null;
        $reservedDetachedContainer = $target instanceof SectorDetachedContainer
            ? $this->reserveDetachedContainerForSalvage($probe, $target)
            : null;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $manny->currentTask = Manny::TASK_SALVAGE;
        $manny->taskStartedAt = $now->format('c');
        $salvageSeconds = $this->salvageSeconds();
        $manny->taskEndsAt = $now->modify('+' . $salvageSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'durationSeconds' => $salvageSeconds,
            'target' => $this->salvageTargetArray($target),
            'result' => 'pending',
        ] + ($reservedItem !== null ? ['reservedItem' => $reservedItem] : [])
            + ($reservedDetachedContainer !== null ? ['reservedDetachedContainer' => $reservedDetachedContainer] : []);
        $this->storage->releaseMannyFromStorage($manny);
        $this->removeMannyFromSector($manny);
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startDetachStorageContainer(NeumannProbe $probe, int $ownerPlayerId, string $uid, string $containerId, string $mode, ?string $objectId = null): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to detach storage.');
        }

        $mode = strtolower(trim($mode));
        if (!in_array($mode, [SectorDetachedContainer::MODE_DRIFTING, SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID], true)) {
            throw new MannyActionException(400, 'bad_request', 'Detach mode must be drifting or hidden_on_asteroid.');
        }

        $target = null;
        if ($mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID) {
            if ($objectId === null || trim($objectId) === '') {
                throw new MannyActionException(400, 'bad_request', 'objectId is required for hidden_on_asteroid mode.');
            }
            $target = $this->findObjectInCurrentSector($probe, $objectId);
            if (!$target instanceof Asteroid) {
                throw new MannyActionException(422, 'invalid_asteroid_target', 'Hidden containers must be attached to an asteroid in the current sector.');
            }
        }

        $snapshot = $this->storage->detachAdditionalContainerSnapshot($probe, $containerId, $ownerPlayerId);
        $objectId = $mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID ? $objectId : null;
        $detachedObjectId = SectorDetachedContainer::objectIdForContainer((string) $snapshot['sourceContainerId']);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $durationSeconds = $this->detachStorageContainerSeconds();

        $manny->currentTask = Manny::TASK_DETACHING_STORAGE_CONTAINER;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'containerId' => $containerId,
            'objectId' => $detachedObjectId,
            'mode' => $mode,
            'targetObjectId' => $objectId,
            'durationSeconds' => $durationSeconds,
            'snapshot' => $snapshot,
            'target' => $target instanceof Asteroid ? $this->bookmarkTargetArray($target) : null,
        ];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startDropStorageContainerOnPlanet(NeumannProbe $probe, int $ownerPlayerId, string $uid, string $containerId, string $planetId): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to drop storage.');
        }

        $target = $this->findObjectInCurrentSector($probe, $planetId);
        if (!$target instanceof Planet) {
            throw new MannyActionException(422, 'invalid_planet_target', 'Storage containers can only be dropped on a planet in the current sector.');
        }

        $kit = $this->firstItemOfType($probe, ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT);
        if ($kit === null) {
            throw new MannyActionException(422, 'missing_atmospheric_drop_kit', 'An atmospheric drop kit is required in probe inventory.');
        }

        $kitPayload = $this->consumedItemPayload($kit);
        $snapshot = $this->storage->detachAdditionalContainerSnapshot($probe, $containerId, $ownerPlayerId);
        $snapshot['items'] = array_values(array_filter(
            is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [],
            static fn(array $item): bool => ($item['uid'] ?? null) !== $kit->uid,
        ));
        $this->items->delete($kit);
        $detachedObjectId = SectorDetachedContainer::planetDropObjectIdForContainer((string) $snapshot['sourceContainerId']);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $durationSeconds = $this->dropStorageContainerSeconds();

        $manny->currentTask = Manny::TASK_DROPPING_STORAGE_CONTAINER;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'containerId' => $containerId,
            'objectId' => $detachedObjectId,
            'planetId' => $planetId,
            'targetObjectId' => $planetId,
            'durationSeconds' => $durationSeconds,
            'snapshot' => $snapshot,
            'consumedKit' => $kitPayload,
            'target' => $this->bookmarkTargetArray($target),
        ];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startInspectAsteroid(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);

        $target = $this->findObjectInCurrentSector($probe, $objectId);
        if (!$target instanceof Asteroid) {
            throw new MannyActionException(422, 'invalid_asteroid_target', 'This object cannot be inspected by a Manny.');
        }

        $sector = $this->sectors->getOrCreateSector($probe->currentSector);
        $detection = $this->hiddenDetachedContainerDetection($sector, $objectId);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $durationSeconds = $this->miningTravelSeconds() * 2;
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $manny->currentTask = Manny::TASK_INSPECTING_ASTEROID;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'durationSeconds' => $durationSeconds,
            'target' => $this->bookmarkTargetArray($target),
        ] + ($detection !== null ? ['artificialObjectDetected' => $detection] : []);
        $this->storage->releaseMannyFromStorage($manny);
        $this->removeMannyFromSector($manny);
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startRecoverDetachedContainer(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);

        $sector = $this->sectors->getOrCreateSector($probe->currentSector);
        $target = $sector->findObjectById($objectId);
        if (!$target instanceof SectorDetachedContainer) {
            $target = $sector->findHiddenDetachedContainerById($objectId);
        }
        if (!$target instanceof SectorDetachedContainer) {
            throw new MannyActionException(404, 'detached_container_not_found', 'Detached storage container not found.');
        }

        $reservedDetachedContainer = $this->reserveDetachedContainerForSalvage($probe, $target);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $salvageSeconds = $this->salvageSeconds();
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $manny->currentTask = Manny::TASK_SALVAGE;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $salvageSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'durationSeconds' => $salvageSeconds,
            'target' => $this->salvageTargetArray($target),
            'result' => 'pending',
            'reservedDetachedContainer' => $reservedDetachedContainer,
        ];
        $this->storage->releaseMannyFromStorage($manny);
        $this->removeMannyFromSector($manny);
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startWaypointBookmarkInstallation(NeumannProbe $probe, Player $player, string $uid, string $objectId, string $name): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to install a waypoint bookmark.');
        }

        $name = trim($name);
        if ($name === '' || strlen($name) > 80) {
            throw new MannyActionException(400, 'bad_request', 'Waypoint bookmark name must contain 1 to 80 characters.');
        }
        $item = $this->firstWaypointBookmarkItem($probe)
            ?? throw new MannyActionException(404, 'waypoint_bookmark_not_found', 'Waypoint bookmark not found in probe inventory.');
        $target = $this->bookmarks->deployableTarget($probe, $objectId);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->items->delete($item);

        $manny->currentTask = Manny::TASK_INSTALLING_WAYPOINT_BOOKMARK;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $this->waypointBookmarkInstallSeconds() . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'name' => $name,
            'durationSeconds' => $this->waypointBookmarkInstallSeconds(),
            'targetSector' => $probe->currentSector->toArray(),
            'target' => $this->bookmarkTargetArray($target),
            'playerId' => $player->id,
            'playerName' => $player->displayName ?? $player->username,
            'reservedItem' => $this->consumedItemPayload($item),
        ];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function startStorageMove(NeumannProbe $probe, string $uid, array $payload): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to move storage.');
        }

        $kind = strtolower(trim((string) ($payload['kind'] ?? '')));
        if ($kind === '') {
            $kind = isset($payload['resourceType']) ? 'resource' : (isset($payload['targetMannyId']) ? 'manny' : 'item');
        }
        $toContainerId = (string) ($payload['toContainerId'] ?? $payload['toContainer'] ?? '');
        if ($toContainerId === '') {
            throw new MannyActionException(400, 'bad_request', 'Storage move target container is required.');
        }

        $movePayload = [
            'kind' => $kind,
            'toContainerId' => $toContainerId,
        ];
        $durationSeconds = $this->storageMoveSecondsPerUnit();

        if ($kind === 'resource') {
            $fromContainerId = (string) ($payload['fromContainerId'] ?? $payload['fromContainer'] ?? '');
            $resourceType = (string) ($payload['resourceType'] ?? $payload['type'] ?? '');
            $amount = isset($payload['amount']) && is_numeric($payload['amount']) ? round((float) $payload['amount'], 4) : 0.0;
            if ($fromContainerId === '' || $resourceType === '' || $amount <= 0.0) {
                throw new MannyActionException(400, 'bad_request', 'Resource storage move requires fromContainerId, toContainerId, resourceType and amount.');
            }
            $this->storage->assertCanMoveResource($probe, $resourceType, $amount, $fromContainerId, $toContainerId);
            $durationSeconds = $this->storage->storageMoveDurationSeconds('resource', $amount);
            $movePayload += [
                'fromContainerId' => $fromContainerId,
                'resourceType' => $resourceType,
                'amount' => $amount,
            ];
        } elseif ($kind === 'item') {
            $itemIds = $this->stringListPayload($payload['itemIds'] ?? null);
            $itemId = (string) ($payload['itemId'] ?? $payload['targetId'] ?? '');
            if ($itemIds === [] && $itemId !== '') {
                $itemIds = [$itemId];
            }
            $quantity = isset($payload['quantity']) && is_numeric($payload['quantity'])
                ? max(1, (int) floor((float) $payload['quantity']))
                : count($itemIds);
            $itemIds = array_slice($itemIds, 0, $quantity);
            if ($itemIds === []) {
                throw new MannyActionException(400, 'bad_request', 'Item storage move requires itemId and toContainerId.');
            }
            $this->storage->assertCanMoveItems($probe, $itemIds, $toContainerId);
            $durationSeconds = $this->storage->storageMoveDurationSeconds('item', count($itemIds));
            $movePayload['itemIds'] = $itemIds;
            $movePayload['quantity'] = count($itemIds);
        } elseif ($kind === 'manny') {
            $targetMannyIds = $this->stringListPayload($payload['targetMannyIds'] ?? $payload['mannyIds'] ?? null);
            $targetMannyId = (string) ($payload['targetMannyId'] ?? $payload['mannyId'] ?? $payload['targetId'] ?? '');
            if ($targetMannyIds === [] && $targetMannyId !== '') {
                $targetMannyIds = [$targetMannyId];
            }
            $quantity = isset($payload['quantity']) && is_numeric($payload['quantity'])
                ? max(1, (int) floor((float) $payload['quantity']))
                : count($targetMannyIds);
            $targetMannyIds = array_slice($targetMannyIds, 0, $quantity);
            if ($targetMannyIds === []) {
                throw new MannyActionException(400, 'bad_request', 'Manny storage move requires targetMannyId and toContainerId.');
            }
            if (in_array($uid, $targetMannyIds, true)) {
                throw new MannyActionException(422, 'invalid_storage_move', 'A Manny cannot move its own storage slot while executing the order.');
            }
            $this->storage->assertCanMoveMannies($probe, $targetMannyIds, $toContainerId);
            $durationSeconds = $this->storage->storageMoveDurationSeconds('manny', count($targetMannyIds));
            $movePayload['targetMannyIds'] = $targetMannyIds;
            $movePayload['quantity'] = count($targetMannyIds);
        } else {
            throw new MannyActionException(400, 'bad_request', 'Storage move kind must be resource, item or manny.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->currentTask = Manny::TASK_MOVING_STORAGE;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = $movePayload + ['durationSeconds' => $durationSeconds];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function recallManny(NeumannProbe $probe, string $uid): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);

        if ($manny->currentTask === Manny::TASK_REPAIR) {
            $metalsCost = round(max(0.0, (float) ($manny->taskPayload['metalsCost'] ?? 0.0)), 4);
            if ($metalsCost > 0.0) {
                $this->transferResourceToProbe($probe, 'metals', $metalsCost);
                $this->probes->save($probe);
            }
            $this->clearTask($manny);
            $this->mannies->save($manny);

            return $this->requiredManny($probe, $uid);
        }
        if ($manny->currentTask === Manny::TASK_CRAFTING || $manny->currentTask === Manny::TASK_ASSISTING_ATOMIC_PRINTER) {
            $this->refundCraftingCommitment($probe, $manny);
            $this->clearTask($manny);
            $this->mannies->save($manny);

            return $this->requiredManny($probe, $uid);
        }
        if ($manny->currentTask === Manny::TASK_DETACHING_STORAGE_CONTAINER) {
            $snapshot = is_array($manny->taskPayload['snapshot'] ?? null) ? $manny->taskPayload['snapshot'] : null;
            if ($snapshot !== null) {
                $this->storage->restoreDetachedContainerSnapshot($probe, $snapshot);
            }
            $this->clearTask($manny);
            $this->mannies->save($manny);

            return $this->requiredManny($probe, $uid);
        }
        if ($manny->currentTask === Manny::TASK_DROPPING_STORAGE_CONTAINER) {
            $snapshot = is_array($manny->taskPayload['snapshot'] ?? null) ? $manny->taskPayload['snapshot'] : null;
            if ($snapshot !== null) {
                $this->storage->restoreDetachedContainerSnapshot($probe, $snapshot);
            }
            $this->restoreConsumedDropKit($probe, $manny);
            $this->clearTask($manny);
            $this->mannies->save($manny);

            return $this->requiredManny($probe, $uid);
        }
        if ($manny->currentTask === Manny::TASK_WAITING_FOR_SPACE && ($this->reservedSalvageItemPayload($manny) !== null || $this->reservedDetachedContainerPayload($manny) !== null)) {
            return $manny;
        }
        if ($manny->currentTask === Manny::TASK_SALVAGE) {
            $this->restoreReservedSalvageItem($manny);
            $this->restoreReservedDetachedContainer($manny);
        }
        if ($manny->isOnProbe()) {
            return $manny;
        }
        if ($manny->currentTask === Manny::TASK_RETURNING) {
            $this->removeMannyFromSector($manny);
            return $manny;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->currentTask = Manny::TASK_RETURNING;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $this->miningTravelSeconds() . ' seconds')->format('c');
        $manny->taskPayload = ['reason' => 'recall'];
        $this->removeMannyFromSector($manny);
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function jettisonMannyFromProbe(NeumannProbe $probe, string $uid): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny is already outside the probe.');
        }

        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $this->registerMannyInSector($manny, SectorManny::STATE_ABANDONED);
        $manny->probeId = null;
        $this->storage->releaseMannyFromStorage($manny);
        $this->mannies->save($manny);

        return $this->mannies->findByUid($uid) ?? $manny;
    }

    /**
     * @return array<string, mixed>
     */
    public function jettisonProbeItemFromProbe(NeumannProbe $probe, string $uid): array
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $item = $this->items->findByUidForProbe($probe->id, $uid)
            ?? throw new MannyActionException(404, 'not_found', 'Inventory item not found.');
        if (!$this->isJettisonableDriftingItemType($item->type)) {
            throw new MannyActionException(422, 'item_not_jettisonable', 'This inventory item cannot be jettisoned.');
        }

        $sector = $this->sectors->getOrCreateSector($probe->currentSector);
        $drifting = $this->addDriftingItemToSector($sector, $item->type, $this->itemDisplayName($item->type, $item->name), $item->containerSpace, 1);
        $this->sectors->saveSector($sector);
        $this->items->delete($item);

        return [
            'type' => $item->type,
            'name' => $drifting->getName(),
            'quantity' => 1,
            'driftingQuantity' => $drifting->getQuantity(),
            'objectId' => $drifting->getId(),
            'containerSpace' => $drifting->getContainerSpace(),
            'capacityUnit' => $drifting->getCapacityUnit(),
        ];
    }

    public function refreshMannyState(Manny $manny, NeumannProbe $probe): Manny
    {
        if ($manny->currentTask === null) {
            return $manny;
        }
        if (!$manny->isInSameSectorAs($probe)) {
            return $manny;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($manny->currentTask === Manny::TASK_REPAIR) {
            return $this->refreshRepair($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_MINING) {
            return $this->refreshMining($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_CRAFTING || $manny->currentTask === Manny::TASK_ASSISTING_ATOMIC_PRINTER) {
            return $this->refreshCrafting($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_SALVAGE) {
            return $this->refreshSalvage($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_INSTALLING_WAYPOINT_BOOKMARK) {
            return $this->refreshWaypointBookmarkInstallation($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_DETACHING_STORAGE_CONTAINER) {
            return $this->refreshDetachStorageContainer($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_DROPPING_STORAGE_CONTAINER) {
            return $this->refreshDropStorageContainer($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_INSPECTING_ASTEROID) {
            return $this->refreshInspectAsteroid($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_RETURNING) {
            return $this->refreshReturning($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_WAITING_FOR_SPACE) {
            return $this->refreshWaitingForSpace($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_MOVING_STORAGE) {
            return $this->refreshStorageMove($manny, $probe, $now);
        }

        return $manny;
    }

    public function publicArray(NeumannProbe $probe, Manny $manny, ?array $relativeSector = null): array
    {
        return [
            'id' => $manny->uid,
            'name' => $manny->name,
            'location' => $manny->isOnProbe()
                ? ['type' => Manny::LOCATION_PROBE]
                : ['type' => Manny::LOCATION_SECTOR, 'sector' => ['relative' => $relativeSector]],
            'currentTask' => $manny->currentTask,
            'taskProgressPercent' => $manny->taskProgressPercent(),
            'taskEstimatedEndTime' => $manny->taskEndsAt,
            'task' => $this->publicTaskPayload($manny),
            'cargo' => $this->mannyCargoArray($manny),
            'canReceiveOrders' => $manny->probeId === $probe->id && $manny->isInSameSectorAs($probe) && $manny->currentTask === null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicTaskPayload(Manny $manny): array
    {
        if ($manny->currentTask !== Manny::TASK_DROPPING_STORAGE_CONTAINER) {
            return $manny->taskPayload;
        }

        $payload = $manny->taskPayload;
        unset($payload['snapshot'], $payload['consumedKit']);

        return $payload;
    }

    private function requiredManny(NeumannProbe $probe, string $uid): Manny
    {
        return $this->mannies->findByUidForProbe($probe->id, $uid)
            ?? throw new MannyActionException(404, 'manny_not_found', 'Manny not found.');
    }

    private function refreshRepair(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $integrityPercent = (float) ($manny->taskPayload['integrityPercent'] ?? 0);
        $probe->integrityPercent = round(min($this->maxIntegrityPercent(), $probe->integrityPercent + $integrityPercent), 2);
        $this->probes->save($probe);

        $this->clearTask($manny);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshCrafting(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $this->createCraftingOutput($probe, $manny, $now);

        $this->clearTask($manny);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshSalvage(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $objectId = (string) ($manny->taskPayload['objectId'] ?? '');
        $sectorCoordinates = $manny->sector ?? $probe->currentSector;
        $sector = $this->sectors->getOrCreateSector($sectorCoordinates);
        $reservedItem = $this->reservedSalvageItemPayload($manny);
        $result = [
            'lastTask' => Manny::TASK_SALVAGE,
            'objectId' => $objectId,
            'target' => $manny->taskPayload['target'] ?? null,
        ];

        if ($reservedItem !== null) {
            $result['result'] = 'success';
            $result['reservedItem'] = $reservedItem;
            $result['salvaged'] = [
                'type' => $reservedItem['type'],
                'name' => $reservedItem['name'],
                'quantity' => $reservedItem['quantity'],
                'containerSpace' => $reservedItem['containerSpace'],
            ];
            $this->finishSalvageActor($manny, $probe, $result);
            $this->probes->save($probe);
            $this->mannies->save($manny);

            return $this->mannies->findById($manny->id) ?? $manny;
        }
        $reservedDetachedContainer = $this->reservedDetachedContainerPayload($manny);
        if ($reservedDetachedContainer !== null) {
            $result['result'] = 'success';
            $result['reservedDetachedContainer'] = $reservedDetachedContainer;
            $result['salvaged'] = [
                'type' => 'detached_storage_container',
                'id' => $reservedDetachedContainer['objectId'],
                'mode' => $reservedDetachedContainer['mode'],
                'capacity' => $reservedDetachedContainer['capacity'],
                'capacityUnit' => $reservedDetachedContainer['capacityUnit'],
            ];
            $this->finishSalvageActor($manny, $probe, $result);
            $this->probes->save($probe);
            $this->mannies->save($manny);

            return $this->mannies->findById($manny->id) ?? $manny;
        }

        $target = $objectId !== '' ? $sector->findObjectById($objectId) : null;
        if ($target === null || !$this->isSalvageableObject($target)) {
            $result['result'] = 'failed';
            $result['failureReason'] = 'target_unavailable';
            $this->finishSalvageActor($manny, $probe, $result);
            $this->probes->save($probe);
            $this->mannies->save($manny);

            return $this->mannies->findById($manny->id) ?? $manny;
        }

        $salvageResult = $this->completeSalvageTarget($probe, $sector, $target);
        $result = array_merge($result, $salvageResult);
        $this->finishSalvageActor($manny, $probe, $result);
        $this->probes->save($probe);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    /**
     * @param array<string, mixed> $recipeDefinition
     * @return array<string, mixed>
     */
    private function craftingPlan(NeumannProbe $probe, array $recipeDefinition): array
    {
        $output = $recipeDefinition['output'] ?? null;
        if (!is_array($output) || !isset($output['type'])) {
            throw new MannyActionException(400, 'invalid_recipe', 'Unknown crafting recipe.');
        }

        $resourceCosts = [];
        $itemsToConsume = [];
        $consumedItems = [];
        $itemsByType = $this->probeItemsByType($probe);
        $durationSeconds = $this->resolveCraftingRecipe(
            $recipeDefinition,
            $itemsByType,
            $itemsToConsume,
            $consumedItems,
            $resourceCosts,
        );

        $this->ensureResourceCostsAvailable($probe, $resourceCosts);

        return [
            'durationSeconds' => $durationSeconds,
            'resourceCosts' => $resourceCosts,
            'itemsToConsume' => $itemsToConsume,
            'consumedItems' => $consumedItems,
            'output' => $output,
        ];
    }

    /**
     * @param array<string, mixed> $recipeDefinition
     * @param array<string, array<ProbeItem>> $itemsByType
     * @param array<ProbeItem> $itemsToConsume
     * @param array<array<string, mixed>> $consumedItems
     * @param array<string, float> $resourceCosts
     * @param array<string> $path
     */
    private function resolveCraftingRecipe(
        array $recipeDefinition,
        array &$itemsByType,
        array &$itemsToConsume,
        array &$consumedItems,
        array &$resourceCosts,
        array $path = [],
    ): int {
        $recipeId = (string) ($recipeDefinition['id'] ?? ($recipeDefinition['output']['type'] ?? ''));
        if ($recipeId === '') {
            throw new MannyActionException(400, 'invalid_recipe', 'Unknown crafting recipe.');
        }
        if (in_array($recipeId, $path, true)) {
            throw new MannyActionException(422, 'invalid_recipe', 'Recursive crafting recipe cycle detected.');
        }

        $durationSeconds = max(0, (int) ($recipeDefinition['durationSeconds'] ?? 0));
        $ingredients = is_array($recipeDefinition['ingredients'] ?? null) ? $recipeDefinition['ingredients'] : [];
        foreach ($ingredients as $ingredient) {
            if (!is_array($ingredient)) {
                continue;
            }
            $type = (string) ($ingredient['type'] ?? '');
            if ($type === '') {
                continue;
            }

            if ($this->craftingIngredientKind($ingredient) !== 'item') {
                $this->addResourceCost(
                    $resourceCosts,
                    $this->normalizeCraftResourceType($type),
                    max(0.0, (float) ($ingredient['quantity'] ?? 0)),
                );
                continue;
            }

            $requiredCount = (int) ceil(max(0.0, (float) ($ingredient['quantity'] ?? 0)));
            $availableItems = $itemsByType[$type] ?? [];
            $consumedCount = min($requiredCount, count($availableItems));
            for ($index = 0; $index < $consumedCount; $index++) {
                $item = array_shift($availableItems);
                if (!$item instanceof ProbeItem) {
                    continue;
                }
                $itemsToConsume[] = $item;
                $consumedItems[] = $this->consumedItemPayload($item);
            }
            $itemsByType[$type] = $availableItems;

            $missingCount = $requiredCount - $consumedCount;
            if ($missingCount <= 0) {
                continue;
            }

            $componentRecipe = CraftingRecipeCatalog::find($type, $this->craftingConfig());
            if (
                $componentRecipe === null
                || (
                    !$this->recipeCraftableBy($componentRecipe, CraftingRecipeCatalog::FABRICATOR_MANNY)
                    && !$this->recipeCraftableBy($componentRecipe, CraftingRecipeCatalog::FABRICATOR_ATOMIC_PRINTER)
                )
            ) {
                throw new MannyActionException(422, 'insufficient_crafting_ingredients', 'Insufficient crafting ingredients for this recipe.');
            }

            for ($index = 0; $index < $missingCount; $index++) {
                $durationSeconds += $this->resolveCraftingRecipe(
                    $componentRecipe,
                    $itemsByType,
                    $itemsToConsume,
                    $consumedItems,
                    $resourceCosts,
                    [...$path, $recipeId],
                );
            }
        }

        return $durationSeconds;
    }

    /**
     * @param array<string, mixed> $ingredient
     */
    private function craftingIngredientKind(array $ingredient): string
    {
        if (isset($ingredient['kind'])) {
            return (string) $ingredient['kind'];
        }

        return ($ingredient['unit'] ?? null) === 'item' ? 'item' : 'resource';
    }

    /**
     * @return array<string, array<ProbeItem>>
     */
    private function probeItemsByType(NeumannProbe $probe): array
    {
        $itemsByType = [];
        foreach ($this->items->findByProbeId($probe->id) as $item) {
            $itemsByType[$item->type] ??= [];
            $itemsByType[$item->type][] = $item;
        }

        return $itemsByType;
    }

    private function firstWaypointBookmarkItem(NeumannProbe $probe): ?ProbeItem
    {
        return $this->firstItemOfType($probe, ProbeItem::TYPE_WAYPOINT_BOOKMARK);
    }

    private function firstItemOfType(NeumannProbe $probe, string $type): ?ProbeItem
    {
        foreach ($this->items->findByProbeId($probe->id) as $item) {
            if ($item->type === $type) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function consumedItemPayload(ProbeItem $item): array
    {
        return [
            'type' => $item->type,
            'name' => $item->name,
            'containerSpace' => $item->containerSpace,
            'metadata' => $item->metadata,
        ];
    }

    /**
     * @param array<string, float> $resourceCosts
     */
    private function addResourceCost(array &$resourceCosts, string $type, float $quantity): void
    {
        $quantity = round(max(0.0, $quantity), 4);
        if ($quantity <= 0.0) {
            return;
        }

        $resourceCosts[$type] = round((float) ($resourceCosts[$type] ?? 0.0) + $quantity, 4);
    }

    private function normalizeCraftResourceType(string $type): string
    {
        try {
            return ResourceComposition::normalizeSelection($type)[0];
        } catch (\InvalidArgumentException) {
            return $type;
        }
    }

    /**
     * @param array<string, float> $resourceCosts
     */
    private function ensureResourceCostsAvailable(NeumannProbe $probe, array $resourceCosts): void
    {
        foreach ($resourceCosts as $type => $quantity) {
            if ($this->resourceStock($probe, $type) + 0.00001 < $quantity) {
                throw new MannyActionException(422, 'insufficient_' . $type, 'Insufficient resources in probe inventory for this recipe.');
            }
        }
    }

    private function resourceStock(NeumannProbe $probe, string $type): float
    {
        return $this->storage->resourceStock($probe, $type);
    }

    /**
     * @param array<string, mixed> $recipeDefinition
     */
    private function recipeCraftableBy(array $recipeDefinition, string $fabricator): bool
    {
        $craftableBy = $recipeDefinition['craftableBy'] ?? [];
        if (!is_array($craftableBy)) {
            return false;
        }

        return in_array($fabricator, $craftableBy, true);
    }

    /**
     * @param array<string, mixed> $craftingPlan
     */
    private function consumeCraftingPlan(NeumannProbe $probe, array $craftingPlan): void
    {
        $resourceCosts = is_array($craftingPlan['resourceCosts'] ?? null) ? $craftingPlan['resourceCosts'] : [];
        foreach ($resourceCosts as $type => $quantity) {
            $this->subtractResourceFromProbe($probe, (string) $type, (float) $quantity);
        }

        $itemsToConsume = is_array($craftingPlan['itemsToConsume'] ?? null) ? $craftingPlan['itemsToConsume'] : [];
        foreach ($itemsToConsume as $item) {
            if ($item instanceof ProbeItem) {
                $this->items->delete($item);
            }
        }
    }

    private function subtractResourceFromProbe(NeumannProbe $probe, string $type, float $quantity): void
    {
        $quantity = round(max(0.0, $quantity), 4);
        if ($quantity <= 0.0) {
            return;
        }

        $this->storage->consumeResource($probe, $type, $quantity);
    }

    /**
     * @param array<string, float> $resourceCosts
     */
    private function cargoSpaceFreedByResourceCosts(array $resourceCosts): float
    {
        $freed = 0.0;
        foreach ($resourceCosts as $type => $quantity) {
            if ($type !== ResourceComposition::DEUTERIUM) {
                $freed += max(0.0, (float) $quantity);
            }
        }

        return round($freed, 4);
    }

    /**
     * @param array<array<string, mixed>> $consumedItems
     */
    private function cargoSpaceFreedByConsumedItems(array $consumedItems): float
    {
        return round(array_reduce(
            $consumedItems,
            static fn(float $total, array $item): float => $total + max(0.0, (float) ($item['containerSpace'] ?? 0.0)),
            0.0,
        ), 4);
    }

    private function createCraftingOutput(NeumannProbe $probe, Manny $manny, \DateTimeImmutable $now): void
    {
        $output = $manny->taskPayload['output'] ?? null;
        if (!is_array($output)) {
            $recipeDefinition = CraftingRecipeCatalog::find((string) ($manny->taskPayload['recipe'] ?? ''), $this->craftingConfig());
            $output = is_array($recipeDefinition) && is_array($recipeDefinition['output'] ?? null)
                ? $recipeDefinition['output']
                : null;
        }
        if (!is_array($output)) {
            return;
        }

        $type = (string) ($output['type'] ?? '');
        if ($type === '') {
            return;
        }
        if ($type === 'manny') {
            $this->createCraftedManny($probe, $manny, $now);
            return;
        }

        $outputUid = $this->craftingOutputUid($manny, $type, 'itm_craft_');
        if ($this->items->findByUidForProbe($probe->id, $outputUid) !== null) {
            return;
        }

        $metadata = [
            'recipe' => (string) ($manny->taskPayload['recipe'] ?? $type),
            'craftingRunId' => $this->craftingRunId($manny, $type),
            'craftedByMannyId' => $manny->uid,
            'craftedByMannyName' => $manny->name,
            'craftedAt' => $now->format('c'),
        ];
        if (isset($manny->taskPayload['fabricator']) && is_string($manny->taskPayload['fabricator'])) {
            $metadata['fabricator'] = $manny->taskPayload['fabricator'];
        }
        $capacityBonus = round(max(0.0, (float) ($output['capacityBonus'] ?? 0.0)), 4);
        if ($capacityBonus > 0.0) {
            $metadata['capacityBonus'] = $capacityBonus;
            $metadata['capacityBonusUnit'] = ProbeInventory::CAPACITY_UNIT;
        }

        try {
            $this->storage->addItem(
                $probe,
                $type,
                (string) ($output['name'] ?? $type),
                round(max(0.0, (float) ($output['containerSpace'] ?? 0.0)), 4),
                $metadata,
                $outputUid,
            );
        } catch (\RuntimeException $e) {
            if ($this->items->findByUidForProbe($probe->id, $outputUid) !== null) {
                return;
            }

            throw $e;
        }
    }

    private function createCraftedManny(NeumannProbe $probe, Manny $craftingManny, \DateTimeImmutable $now): void
    {
        $uid = $this->craftingOutputUid($craftingManny, 'manny', 'mny_craft_');
        $existing = $this->mannies->findByUid($uid);
        if ($existing !== null) {
            $this->ensureCraftedMannyStored($probe, $existing);
            return;
        }

        $name = $this->nextCraftedMannyName($probe);
        try {
            $newManny = $this->mannies->createForProbe($probe->id, $name, uid: $uid);
        } catch (\RuntimeException $e) {
            if ($this->mannies->findByUid($uid) !== null) {
                return;
            }

            throw $e;
        }
        if (!$this->storage->placeMannyOnProbe($probe, $newManny)) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for the crafted Manny.');
        }
        $newManny->taskPayload = [
            'craftedFromRecipe' => (string) ($craftingManny->taskPayload['recipe'] ?? 'manny'),
            'craftingRunId' => $this->craftingRunId($craftingManny, 'manny'),
            'craftedByMannyId' => $craftingManny->uid,
            'craftedByMannyName' => $craftingManny->name,
            'craftedAt' => $now->format('c'),
        ];
        $this->mannies->save($newManny);
    }

    private function ensureCraftedMannyStored(NeumannProbe $probe, Manny $manny): void
    {
        if ($manny->probeId !== $probe->id || !$manny->isOnProbe()) {
            throw new \RuntimeException('Crafted Manny uid already exists for another location.');
        }
        if ($manny->storageContainerId !== null) {
            return;
        }
        if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for the crafted Manny.');
        }

        $this->mannies->save($manny);
    }

    private function newCraftingRunId(): string
    {
        return 'craft_' . bin2hex(random_bytes(12));
    }

    private function craftingRunId(Manny $manny, string $outputType): string
    {
        $runId = $manny->taskPayload['craftingRunId'] ?? null;
        if (is_string($runId) && $runId !== '') {
            return $runId;
        }

        return hash('sha256', implode('|', [
            $manny->uid,
            (string) $manny->taskStartedAt,
            (string) ($manny->taskPayload['recipe'] ?? $outputType),
            $outputType,
            (string) ($manny->taskPayload['fabricator'] ?? CraftingRecipeCatalog::FABRICATOR_MANNY),
        ]));
    }

    private function craftingOutputUid(Manny $manny, string $outputType, string $prefix): string
    {
        return $prefix . substr(hash('sha256', $this->craftingRunId($manny, $outputType) . '|' . $outputType), 0, 24);
    }

    private function nextCraftedMannyName(NeumannProbe $probe): string
    {
        $names = array_fill_keys(array_map(
            static fn(Manny $manny): string => strtolower($manny->name),
            $this->mannies->findByProbeId($probe->id),
        ), true);

        for ($index = 1; $index <= 9999; $index++) {
            $name = 'manny-' . $index;
            if (!isset($names[strtolower($name)])) {
                return $name;
            }
        }

        return 'manny-' . bin2hex(random_bytes(4));
    }

    private function refundCraftingCommitment(NeumannProbe $probe, Manny $manny): void
    {
        $consumedItems = is_array($manny->taskPayload['consumedItems'] ?? null) ? $manny->taskPayload['consumedItems'] : [];
        foreach ($consumedItems as $item) {
            if (is_array($item)) {
                $this->restoreConsumedItem($probe, $item);
            }
        }

        $resourceCosts = is_array($manny->taskPayload['resourceCosts'] ?? null) ? $manny->taskPayload['resourceCosts'] : [];
        if ($resourceCosts === [] && (float) ($manny->taskPayload['metalsCost'] ?? 0.0) > 0.0) {
            $resourceCosts = [ResourceComposition::METALS => (float) $manny->taskPayload['metalsCost']];
        }

        $refundedResources = false;
        foreach ($resourceCosts as $type => $quantity) {
            if ((float) $quantity <= 0.0) {
                continue;
            }

            $this->transferResourceToProbe($probe, (string) $type, (float) $quantity);
            $refundedResources = true;
        }
        if ($refundedResources) {
            $this->probes->save($probe);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function restoreConsumedItem(NeumannProbe $probe, array $item): void
    {
        $type = (string) ($item['type'] ?? '');
        if ($type === '') {
            return;
        }

        $metadata = $item['metadata'] ?? [];
        $this->storage->addItem(
            $probe,
            $type,
            (string) ($item['name'] ?? $type),
            round(max(0.0, (float) ($item['containerSpace'] ?? 0.0)), 4),
            is_array($metadata) ? $metadata : [],
        );
    }

    private function refreshMining(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if ($manny->taskStartedAt === null) {
            return $manny;
        }

        $elapsed = max(0, $now->getTimestamp() - (new \DateTimeImmutable($manny->taskStartedAt))->getTimestamp());
        $targetAmount = (float) ($manny->taskPayload['targetAmount'] ?? 0);
        $progress = $this->miningProgress($targetAmount, $elapsed);
        $resourceProfile = $this->miningResourceProfile($manny);
        $plannedExtracted = round(min($targetAmount, (float) $progress['deliveredAmount'] + (float) $progress['cargoAmount']), 4);
        $extracted = round((float) ($manny->taskPayload['extractedAmount'] ?? 0), 4);
        if ($plannedExtracted > $extracted) {
            $requestedDelta = round($plannedExtracted - $extracted, 4);
            $actualDelta = $this->depleteMiningTarget($manny, $resourceProfile, $requestedDelta);
            $extracted = round($extracted + $actualDelta, 4);
            $manny->taskPayload['extractedAmount'] = $extracted;
            $manny->taskPayload['extractedResources'] = $this->resourceAmountsForTotal($extracted, $resourceProfile);
            if ($actualDelta + 0.00001 < $requestedDelta) {
                $manny->taskPayload['sourceExhausted'] = true;
            }
        }

        $deposited = (float) ($manny->taskPayload['depositedAmount'] ?? 0);
        $complete = $progress['phase'] === 'complete' || $this->isAtOrAfter($now, $manny->taskEndsAt);
        $delivered = $complete ? $deposited : round(min((float) $progress['deliveredAmount'], $extracted), 4);
        if ($delivered > $deposited) {
            $deliveryAmount = round($delivered - $deposited, 4);
            if (!$this->canAcceptMiningDelivery($probe, $resourceProfile, $deliveryAmount, false)) {
                $this->setMannyCargoProfile($manny, $resourceProfile, $deliveryAmount);
                $this->waitForStorageSpace($manny, [
                    'reason' => 'cargo_delivery',
                    'pendingAmount' => $deliveryAmount,
                    'resourceProfile' => $resourceProfile,
                ]);
                $this->probes->save($probe);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }

            $this->transferMiningResourcesToProbe($probe, $resourceProfile, $deliveryAmount);
            $manny->taskPayload['depositedAmount'] = $delivered;
            $manny->taskPayload['depositedResources'] = $this->resourceAmountsForTotal((float) $manny->taskPayload['depositedAmount'], $resourceProfile);
        }

        $cargoAmount = round(min((float) $progress['cargoAmount'], max(0.0, $extracted - $delivered)), 4);
        $this->setMannyCargoProfile($manny, $resourceProfile, $cargoAmount);
        $manny->taskPayload['phase'] = $progress['phase'];
        $manny->taskPayload['tripIndex'] = $progress['tripIndex'];

        if ($complete) {
            $remaining = round((float) ($manny->taskPayload['extractedAmount'] ?? 0) - (float) ($manny->taskPayload['depositedAmount'] ?? 0), 4);
            if (!$this->canAcceptMiningDelivery($probe, $resourceProfile, $remaining, true)) {
                $this->setMannyCargoProfile($manny, $resourceProfile, $remaining);
                $this->waitForStorageSpace($manny, [
                    'reason' => 'return_to_probe',
                    'pendingAmount' => $remaining,
                    'resourceProfile' => $resourceProfile,
                ]);
                $this->probes->save($probe);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }
            if ($remaining > 0) {
                $this->transferMiningResourcesToProbe($probe, $resourceProfile, $remaining);
                $manny->taskPayload['depositedAmount'] = round((float) ($manny->taskPayload['depositedAmount'] ?? 0) + $remaining, 4);
                $manny->taskPayload['depositedResources'] = $this->resourceAmountsForTotal((float) $manny->taskPayload['depositedAmount'], $resourceProfile);
            }
            $this->clearMannyCargo($manny);
            if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
                $this->waitForStorageSpace($manny, ['reason' => 'return_to_probe']);
                $this->probes->save($probe);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }
            $this->removeMannyFromSector($manny);
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
            $this->clearTask($manny);
        }

        $this->probes->save($probe);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshReturning(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        if (!$this->canAcceptMannyDocking($probe, $manny, $manny->taskPayload)) {
            $this->waitForStorageSpace($manny, ['reason' => 'return_to_probe']);
            $this->mannies->save($manny);

            return $this->mannies->findById($manny->id) ?? $manny;
        }

        $this->transferMannyCargoToProbe($manny, $probe);
        $this->deliverReservedSalvageItems($probe, $manny, $manny->taskPayload);
        $this->deliverReservedDetachedContainer($probe, $manny->taskPayload);
        if ($this->mannyCargoIsEmpty($manny)) {
            $finalPayload = ($this->reservedSalvageItemPayload($manny) !== null || $this->reservedDetachedContainerPayload($manny) !== null) ? $manny->taskPayload : [];
            if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
                $this->waitForStorageSpace($manny, ['reason' => 'return_to_probe']);
                $this->probes->save($probe);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }
            $this->removeMannyFromSector($manny);
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
            $this->clearTask($manny, $finalPayload);
        } else {
            $this->waitForStorageSpace($manny, ['reason' => 'cargo_delivery']);
        }
        $this->probes->save($probe);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshWaitingForSpace(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->canAcceptMannyDocking($probe, $manny, $manny->taskPayload)) {
            return $manny;
        }

        $this->transferMannyCargoToProbe($manny, $probe);
        $this->deliverReservedSalvageItems($probe, $manny, $manny->taskPayload);
        $this->deliverReservedDetachedContainer($probe, $manny->taskPayload);
        if ($this->mannyCargoIsEmpty($manny)) {
            $finalPayload = ($this->reservedSalvageItemPayload($manny) !== null || $this->reservedDetachedContainerPayload($manny) !== null) ? $manny->taskPayload : [];
            if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
                $this->waitForStorageSpace($manny, ['reason' => 'return_to_probe']);
                $this->probes->save($probe);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }
            $this->removeMannyFromSector($manny);
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
            $this->clearTask($manny, $finalPayload);
        } else {
            $this->waitForStorageSpace($manny, ['reason' => 'cargo_delivery']);
        }

        $this->probes->save($probe);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshStorageMove(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $kind = (string) ($manny->taskPayload['kind'] ?? '');
        try {
            if ($kind === 'resource') {
                $this->storage->moveResource(
                    $probe,
                    (string) ($manny->taskPayload['resourceType'] ?? ''),
                    (float) ($manny->taskPayload['amount'] ?? 0.0),
                    (string) ($manny->taskPayload['fromContainerId'] ?? ''),
                    (string) ($manny->taskPayload['toContainerId'] ?? ''),
                );
            } elseif ($kind === 'item') {
                $itemIds = $this->stringListPayload($manny->taskPayload['itemIds'] ?? null);
                if ($itemIds !== []) {
                    $this->storage->moveItems($probe, $itemIds, (string) ($manny->taskPayload['toContainerId'] ?? ''));
                } else {
                    $this->storage->moveItem(
                        $probe,
                        (string) ($manny->taskPayload['itemId'] ?? ''),
                        (string) ($manny->taskPayload['toContainerId'] ?? ''),
                    );
                }
            } elseif ($kind === 'manny') {
                $targetMannyIds = $this->stringListPayload($manny->taskPayload['targetMannyIds'] ?? null);
                if ($targetMannyIds !== []) {
                    $this->storage->moveStoredMannies($probe, $targetMannyIds, (string) ($manny->taskPayload['toContainerId'] ?? ''));
                } else {
                    $this->storage->moveStoredManny(
                        $probe,
                        (string) ($manny->taskPayload['targetMannyId'] ?? ''),
                        (string) ($manny->taskPayload['toContainerId'] ?? ''),
                    );
                }
            }
            $this->clearTask($manny);
        } catch (MannyActionException $exception) {
            $this->clearTask($manny, [
                'result' => 'failed',
                'failureReason' => $exception->errorCode,
            ]);
        }

        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshWaypointBookmarkInstallation(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $sectorCoordinates = $this->taskSectorCoordinates($manny->taskPayload['targetSector'] ?? null) ?? $probe->currentSector;
        $result = [
            'lastTask' => Manny::TASK_INSTALLING_WAYPOINT_BOOKMARK,
            'objectId' => (string) ($manny->taskPayload['objectId'] ?? ''),
            'name' => (string) ($manny->taskPayload['name'] ?? ''),
            'target' => $manny->taskPayload['target'] ?? null,
        ];

        try {
            $object = $this->bookmarks->deployForPlayer(
                $probe,
                (int) ($manny->taskPayload['playerId'] ?? 0),
                (string) ($manny->taskPayload['playerName'] ?? ''),
                (string) ($manny->taskPayload['objectId'] ?? ''),
                (string) ($manny->taskPayload['name'] ?? ''),
                $sectorCoordinates,
            );
            $result['result'] = 'success';
            $result['object'] = $object->toArray();
        } catch (MannyActionException $e) {
            $result['result'] = 'failed';
            $result['failureReason'] = $e->errorCode;
        }

        $this->clearTask($manny, $result);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshDetachStorageContainer(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $snapshot = is_array($manny->taskPayload['snapshot'] ?? null) ? $manny->taskPayload['snapshot'] : [];
        $mode = (string) ($manny->taskPayload['mode'] ?? SectorDetachedContainer::MODE_DRIFTING);
        $objectId = (string) ($manny->taskPayload['objectId'] ?? SectorDetachedContainer::objectIdForContainer((string) ($snapshot['sourceContainerId'] ?? 'storage')));
        $targetObjectId = isset($manny->taskPayload['targetObjectId']) ? (string) $manny->taskPayload['targetObjectId'] : null;
        $sectorCoordinates = $probe->currentSector;
        $sector = $this->sectors->getOrCreateSector($sectorCoordinates);
        $containerData = is_array($snapshot['container'] ?? null) ? $snapshot['container'] : [];

        $object = new SectorDetachedContainer(
            $objectId,
            (string) ($containerData['label'] ?? 'Detached storage container'),
            $mode,
            (int) ($snapshot['ownerProbeId'] ?? $probe->id),
            (int) ($snapshot['ownerPlayerId'] ?? $probe->playerId),
            null,
            $targetObjectId,
            (float) ($containerData['capacity'] ?? 0.0),
            ProbeInventory::CAPACITY_UNIT,
            gmdate('c'),
            $snapshot + [
                'mode' => $mode,
                'sector' => $sectorCoordinates->toArray(),
                'targetObjectId' => $targetObjectId,
            ],
            $mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID
                ? 'Detached storage container hidden on an asteroid.'
                : 'Detached storage container drifting in open space.',
        );

        if ($mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID) {
            $sector->addHiddenDetachedContainer($object);
        } else {
            if (!$sector->replaceObject($object)) {
                $sector->addObject($object);
            }
        }
        $this->sectors->saveSector($sector);

        $this->clearTask($manny, [
            'lastTask' => Manny::TASK_DETACHING_STORAGE_CONTAINER,
            'result' => 'success',
            'objectId' => $object->getId(),
            'mode' => $mode,
            'targetObjectId' => $targetObjectId,
            'detachedContainer' => $this->detachedContainerPublicArray($object),
        ]);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshDropStorageContainer(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $snapshot = is_array($manny->taskPayload['snapshot'] ?? null) ? $manny->taskPayload['snapshot'] : [];
        $objectId = (string) ($manny->taskPayload['objectId'] ?? SectorDetachedContainer::planetDropObjectIdForContainer((string) ($snapshot['sourceContainerId'] ?? 'storage')));
        $targetObjectId = (string) ($manny->taskPayload['targetObjectId'] ?? $manny->taskPayload['planetId'] ?? '');
        $sectorCoordinates = $probe->currentSector;
        $sector = $this->sectors->getOrCreateSector($sectorCoordinates);
        $containerData = is_array($snapshot['container'] ?? null) ? $snapshot['container'] : [];

        $object = new SectorDetachedContainer(
            $objectId,
            (string) ($containerData['label'] ?? 'Planet-dropped storage container'),
            SectorDetachedContainer::MODE_DROPPED_ON_PLANET,
            (int) ($snapshot['ownerProbeId'] ?? $probe->id),
            (int) ($snapshot['ownerPlayerId'] ?? $probe->playerId),
            $probe->id,
            $targetObjectId !== '' ? $targetObjectId : null,
            (float) ($containerData['capacity'] ?? 0.0),
            ProbeInventory::CAPACITY_UNIT,
            gmdate('c'),
            $snapshot + [
                'mode' => SectorDetachedContainer::MODE_DROPPED_ON_PLANET,
                'sector' => $sectorCoordinates->toArray(),
                'targetObjectId' => $targetObjectId,
                'originProbeId' => $probe->id,
                'consumedKit' => $manny->taskPayload['consumedKit'] ?? null,
            ],
            'Storage container dropped on a planet with an atmospheric descent kit.',
        );

        $sector->addPlanetDroppedContainer($object);
        $this->sectors->saveSector($sector);

        $this->clearTask($manny, [
            'lastTask' => Manny::TASK_DROPPING_STORAGE_CONTAINER,
            'result' => 'success',
            'objectId' => $object->getId(),
            'mode' => SectorDetachedContainer::MODE_DROPPED_ON_PLANET,
            'targetObjectId' => $targetObjectId,
        ]);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshInspectAsteroid(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector ?? $probe->currentSector);
        $detection = $this->hiddenDetachedContainerDetection($sector, (string) ($manny->taskPayload['objectId'] ?? ''));
        $result = [
            'lastTask' => Manny::TASK_INSPECTING_ASTEROID,
            'result' => 'success',
            'objectId' => (string) ($manny->taskPayload['objectId'] ?? ''),
            'target' => $manny->taskPayload['target'] ?? null,
        ] + ($detection !== null ? ['artificialObjectDetected' => $detection] : []);

        if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
            $this->waitForStorageSpace($manny, ['reason' => 'return_to_probe'] + $result);
            $this->mannies->save($manny);

            return $this->mannies->findById($manny->id) ?? $manny;
        }

        $this->removeMannyFromSector($manny);
        $manny->locationType = Manny::LOCATION_PROBE;
        $manny->sector = null;
        $this->clearTask($manny, $result);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    /**
     * @return array<string>
     */
    private function stringListPayload(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => trim((string) $item),
            $value,
        ), static fn(string $item): bool => $item !== '')));
    }

    private function taskSectorCoordinates(mixed $value): ?\VonNeumannGame\Sector\SectorCoordinates
    {
        if (!is_array($value)) {
            return null;
        }
        foreach (['x', 'y', 'z'] as $axis) {
            if (!isset($value[$axis]) || !is_numeric($value[$axis])) {
                return null;
            }
        }

        return new \VonNeumannGame\Sector\SectorCoordinates((int) $value['x'], (int) $value['y'], (int) $value['z']);
    }

    private function ensureProbeAcceptsMannyOrders(NeumannProbe $probe): void
    {
        if ($probe->status === ProbeStatus::Dead) {
            throw new MannyActionException(409, 'probe_dead', 'The probe is no longer operational.');
        }
        if ($probe->status === ProbeStatus::TrappedByBlackHole) {
            throw new MannyActionException(409, 'probe_trapped_by_black_hole', 'The probe is trapped beyond a black hole escape threshold.');
        }
    }

    private function ensureMannyInRange(Manny $manny, NeumannProbe $probe): void
    {
        if (!$manny->isInSameSectorAs($probe)) {
            throw new MannyActionException(409, 'manny_out_of_range', 'The Manny is outside the probe current sector.');
        }
    }

    private function ensureMannyIdle(Manny $manny): void
    {
        if ($manny->currentTask !== null) {
            throw new MannyActionException(409, 'manny_busy', 'The Manny is already executing an order.');
        }
    }

    private function refreshOtherMannyStates(NeumannProbe $probe, Manny $currentManny): void
    {
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if ($manny->id === $currentManny->id || $manny->currentTask === null) {
                continue;
            }

            $this->refreshMannyState($manny, $probe);
        }
    }

    private function refreshAllMannyStates(NeumannProbe $probe): void
    {
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if ($manny->currentTask !== null) {
                $this->refreshMannyState($manny, $probe);
            }
        }
    }

    private function atomicPrinterAssistant(NeumannProbe $probe): ?Manny
    {
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if ($manny->currentTask === Manny::TASK_ASSISTING_ATOMIC_PRINTER) {
                return $manny;
            }
        }

        return null;
    }

    private function availableAtomicPrinterAssistant(NeumannProbe $probe): ?Manny
    {
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if ($manny->isOnProbe() && $manny->currentTask === null) {
                return $manny;
            }
        }

        return null;
    }

    private function findObjectInCurrentSector(NeumannProbe $probe, string $objectId): ?UniverseObject
    {
        return $this->sectors->getOrCreateSector($probe->currentSector)->findObjectById($objectId);
    }

    private function isMineableObject(UniverseObject $object): bool
    {
        return $object instanceof Asteroid
            || ($object instanceof Planet && $object->getMass() <= $this->mineablePlanetMaxMass());
    }

    private function isSalvageableObject(UniverseObject $object): bool
    {
        return ($object instanceof SectorManny && $object->getState() === SectorManny::STATE_ABANDONED)
            || ($object instanceof SectorDriftingItem && $object->getQuantity() > 0 && $object->getContainerSpace() > 0.0)
            || $object instanceof SectorDetachedContainer;
    }

    private function salvageTargetArray(UniverseObject $object): array
    {
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

    private function bookmarkTargetArray(UniverseObject $object): array
    {
        return [
            'id' => $object->getId(),
            'type' => $object->getType()->value,
            'name' => $object->getName(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function hiddenDetachedContainerDetection(SectorContent $sector, string $objectId): ?array
    {
        $hidden = $sector->hiddenDetachedContainersForObject($objectId);
        if ($hidden === []) {
            return null;
        }

        return [
            'type' => 'detached_storage_container',
            'detection' => SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID,
            'objectId' => $hidden[0]->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detachedContainerPublicArray(SectorDetachedContainer $container): array
    {
        return [
            'id' => $container->getId(),
            'type' => $container->getType()->value,
            'name' => $container->getName(),
            'mode' => $container->getMode(),
            'targetObjectId' => $container->getTargetObjectId(),
            'capacity' => $container->getCapacity(),
            'capacityUnit' => $container->getCapacityUnit(),
            'salvageable' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSalvageTarget(NeumannProbe $probe, SectorContent $sector, UniverseObject $target): array
    {
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
    private function finishSalvageActor(Manny $manny, NeumannProbe $probe, array $resultPayload): void
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
    private function reserveDriftingItemForSalvage(NeumannProbe $probe, SectorDriftingItem $target): array
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

    private function restoreReservedSalvageItem(Manny $manny): void
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
    private function reserveDetachedContainerForSalvage(NeumannProbe $probe, SectorDetachedContainer $target): array
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

    private function restoreReservedDetachedContainer(Manny $manny): void
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

    private function restoreConsumedDropKit(NeumannProbe $probe, Manny $manny): void
    {
        $kit = $manny->taskPayload['consumedKit'] ?? null;
        if (!is_array($kit)) {
            return;
        }

        $this->storage->addItem(
            $probe,
            ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT,
            (string) ($kit['name'] ?? ProbeItem::ATMOSPHERIC_DROP_KIT_NAME),
            round(max(0.0, (float) ($kit['containerSpace'] ?? CraftingRecipeCatalog::ATMOSPHERIC_DROP_KIT_CONTAINER_SPACE)), 4),
            is_array($kit['metadata'] ?? null) ? $kit['metadata'] : [],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function deliverReservedSalvageItems(NeumannProbe $probe, Manny $manny, array $payload): void
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
    private function deliverReservedDetachedContainer(NeumannProbe $probe, array $payload): void
    {
        $reserved = $this->reservedDetachedContainerPayloadFrom($payload);
        if ($reserved === null) {
            return;
        }

        $object = is_array($reserved['object'] ?? null) ? $reserved['object'] : [];
        $snapshot = is_array($object['payload'] ?? null) ? $object['payload'] : [];
        $this->storage->restoreDetachedContainerSnapshot($probe, $snapshot);
    }

    private function reservedSalvageItemPayload(Manny $manny): ?array
    {
        return $this->reservedSalvageItemPayloadFrom($manny->taskPayload);
    }

    private function reservedDetachedContainerPayload(Manny $manny): ?array
    {
        return $this->reservedDetachedContainerPayloadFrom($manny->taskPayload);
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
     */
    private function reservedSalvageItemContainerSpace(array $payload): float
    {
        $reservedItem = $this->reservedSalvageItemPayloadFrom($payload);
        if ($reservedItem === null) {
            return 0.0;
        }

        return round((float) $reservedItem['containerSpace'] * (int) $reservedItem['quantity'], 4);
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

    /**
     * @return array<string, float>
     */
    private function resourceComposition(UniverseObject $object): array
    {
        if ($object instanceof Asteroid) {
            return ResourceComposition::fromAmounts($object->getResourceAmounts());
        }

        $data = $object->toArray();

        return ResourceComposition::fromHints($data['estimatedResources'] ?? $data['resourceHints'] ?? []);
    }

    /**
     * @return array<string, float>
     */
    private function availableAsteroidResourceAmountsForOrders(NeumannProbe $probe, Asteroid $asteroid): array
    {
        $availableAmounts = $asteroid->getResourceAmounts();
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if ($manny->currentTask !== Manny::TASK_MINING || ($manny->taskPayload['objectId'] ?? null) !== $asteroid->getId()) {
                continue;
            }

            $remainingTarget = round(
                max(0.0, (float) ($manny->taskPayload['targetAmount'] ?? 0.0) - (float) ($manny->taskPayload['extractedAmount'] ?? 0.0)),
                4,
            );
            if ($remainingTarget <= 0.0) {
                continue;
            }

            foreach ($this->resourceAmountsForTotal($remainingTarget, $this->miningResourceProfile($manny)) as $type => $reservedAmount) {
                $availableAmounts[$type] = round(max(0.0, (float) ($availableAmounts[$type] ?? 0.0) - $reservedAmount), 4);
            }
        }

        return $availableAmounts;
    }

    /**
     * @param array<string, float> $availableAmounts
     * @param array<string, float> $resourceProfile
     */
    private function ensureAsteroidHasResources(array $availableAmounts, array $resourceProfile, float $targetAmount): void
    {
        foreach ($this->resourceAmountsForTotal($targetAmount, $resourceProfile) as $type => $requiredAmount) {
            if ($requiredAmount > 0.0 && (float) ($availableAmounts[$type] ?? 0.0) + 0.00001 < $requiredAmount) {
                throw new MannyActionException(422, 'insufficient_target_resources', 'The asteroid does not contain enough of the requested material.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function miningTargetArray(UniverseObject $object): array
    {
        $data = $object->toArray();
        $resources = $data['estimatedResources'] ?? $data['resourceHints'] ?? [];
        $composition = $this->resourceComposition($object);

        $target = [
            'id' => $object->getId(),
            'type' => $object->getType()->value,
            'name' => $object->getName(),
            'mass' => $object->getMass(),
            'radius' => $object->getRadius(),
            'resources' => $resources,
            'resourceTypes' => ResourceComposition::availableTypes($composition),
            'resourceComposition' => $composition,
        ];

        if ($object instanceof Asteroid) {
            $target['composition'] = $data['composition'] ?? null;
            $target['sizeCategory'] = $data['sizeCategory'] ?? null;
            $target['resourceAmounts'] = $object->getResourceAmounts();
        }
        if ($object instanceof Planet) {
            $target['category'] = $object->getCategory();
        }

        return $target;
    }

    private function miningDurationSeconds(float $targetAmount): int
    {
        $remaining = round($targetAmount, 4);
        $duration = 0;
        while ($remaining > 0.0001) {
            $tripAmount = min($this->mannyCargoCapacity(), $remaining);
            $duration += $this->miningTravelSeconds();
            $duration += (int) ceil($tripAmount / $this->miningAmountPerTick()) * $this->miningTickSeconds();
            $duration += $this->miningTravelSeconds();
            $remaining = round($remaining - $tripAmount, 4);
        }

        return $duration;
    }

    private function miningProgress(float $targetAmount, int $elapsedSeconds): array
    {
        $remaining = round($targetAmount, 4);
        $cursor = 0;
        $delivered = 0.0;
        $tripIndex = 1;
        while ($remaining > 0.0001) {
            $tripAmount = min($this->mannyCargoCapacity(), $remaining);
            $outboundEnd = $cursor + $this->miningTravelSeconds();
            if ($elapsedSeconds < $outboundEnd) {
                return ['phase' => 'outbound', 'tripIndex' => $tripIndex, 'deliveredAmount' => $delivered, 'cargoAmount' => 0.0];
            }

            $miningTicks = (int) ceil($tripAmount / $this->miningAmountPerTick());
            $miningEnd = $outboundEnd + ($miningTicks * $this->miningTickSeconds());
            if ($elapsedSeconds < $miningEnd) {
                $ticksDone = (int) floor(($elapsedSeconds - $outboundEnd) / $this->miningTickSeconds());
                $cargo = min($tripAmount, $ticksDone * $this->miningAmountPerTick());

                return ['phase' => 'mining', 'tripIndex' => $tripIndex, 'deliveredAmount' => $delivered, 'cargoAmount' => round($cargo, 4)];
            }

            $returnEnd = $miningEnd + $this->miningTravelSeconds();
            if ($elapsedSeconds < $returnEnd) {
                return ['phase' => 'returning', 'tripIndex' => $tripIndex, 'deliveredAmount' => $delivered, 'cargoAmount' => round($tripAmount, 4)];
            }

            $delivered = round($delivered + $tripAmount, 4);
            $remaining = round($remaining - $tripAmount, 4);
            $cursor = $returnEnd;
            $tripIndex++;
        }

        return ['phase' => 'complete', 'tripIndex' => max(1, $tripIndex - 1), 'deliveredAmount' => round($targetAmount, 4), 'cargoAmount' => 0.0];
    }

    /**
     * @param array<string, float> $profile
     */
    private function depleteMiningTarget(Manny $manny, array $profile, float $amount): float
    {
        $amount = round(max(0.0, $amount), 4);
        $objectId = (string) ($manny->taskPayload['objectId'] ?? '');
        if ($amount <= 0.0 || $objectId === '' || $manny->sector === null) {
            return $amount;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        $object = $sector->findObjectById($objectId);
        if (!$object instanceof Asteroid) {
            return $amount;
        }

        $currentAmounts = $object->getResourceAmounts();
        $requestedAmounts = $this->resourceAmountsForTotal($amount, $profile);
        $actualAmount = $this->extractableTotalAmount($currentAmounts, $requestedAmounts, $amount);
        if ($actualAmount <= 0.0) {
            return 0.0;
        }

        $extractedAmounts = $this->resourceAmountsForTotal($actualAmount, $profile);
        foreach ($extractedAmounts as $type => $extractedAmount) {
            $currentAmounts[$type] = round(max(0.0, (float) ($currentAmounts[$type] ?? 0.0) - $extractedAmount), 4);
        }

        if ($sector->replaceObject($object->withResourceAmounts($currentAmounts))) {
            $this->sectors->saveSector($sector);
        }

        return $actualAmount;
    }

    /**
     * @param array<string, float> $availableAmounts
     * @param array<string, float> $requestedAmounts
     */
    private function extractableTotalAmount(array $availableAmounts, array $requestedAmounts, float $requestedTotal): float
    {
        $ratio = 1.0;
        $hasRequestedResource = false;
        foreach ($requestedAmounts as $type => $requestedAmount) {
            if ($requestedAmount <= 0.0) {
                continue;
            }

            $hasRequestedResource = true;
            $ratio = min($ratio, max(0.0, (float) ($availableAmounts[$type] ?? 0.0)) / $requestedAmount);
        }

        if (!$hasRequestedResource) {
            return round(max(0.0, $requestedTotal), 4);
        }

        return round(max(0.0, min(1.0, $ratio)) * max(0.0, $requestedTotal), 4);
    }

    private function canAcceptMannyDocking(NeumannProbe $probe, Manny $manny, array $payload = []): bool
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
     * @param array<string, float> $profile
     */
    private function canAcceptMiningDelivery(NeumannProbe $probe, array $profile, float $amount, bool $includeManny): bool
    {
        $units = $includeManny ? [['type' => 'manny', 'space' => $this->mannyContainerSpace()]] : [];

        return $this->storage->canStoreIncoming($probe, $this->resourceAmountsForTotal($amount, $profile), $units);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function waitForStorageSpace(Manny $manny, array $payload = []): void
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

    private function transferMannyCargoToProbe(Manny $manny, NeumannProbe $probe): void
    {
        $manny->cargoDeuterium = round($manny->cargoDeuterium - $this->transferResourceToProbe($probe, 'deuterium', $manny->cargoDeuterium), 4);
        $manny->cargoMetals = round($manny->cargoMetals - $this->transferResourceToProbe($probe, 'metals', $manny->cargoMetals), 4);
        $manny->cargoIce = round($manny->cargoIce - $this->transferResourceToProbe($probe, 'ice', $manny->cargoIce), 4);
        $manny->cargoOrganicCompounds = round($manny->cargoOrganicCompounds - $this->transferResourceToProbe($probe, 'carbon_compounds', $manny->cargoOrganicCompounds), 4);
    }

    private function transferResourceToProbe(NeumannProbe $probe, string $resourceType, float $amount): float
    {
        $amount = round(max(0.0, $amount), 4);
        if ($amount <= 0.0) {
            return 0.0;
        }

        return $this->storage->addResource($probe, $resourceType, $amount);
    }

    /**
     * @param array<string, float> $profile
     */
    private function transferMiningResourcesToProbe(NeumannProbe $probe, array $profile, float $amount): void
    {
        $amounts = $this->resourceAmountsForTotal($amount, $profile);
        foreach ($amounts as $type => $resourceAmount) {
            $this->transferResourceToProbe($probe, $type, $resourceAmount);
        }
    }

    private function freeCargoCapacity(NeumannProbe $probe): float
    {
        return $this->storage->freeCargoCapacity($probe);
    }

    /**
     * @param array<string, float> $profile
     */
    private function setMannyCargoProfile(Manny $manny, array $profile, float $amount): void
    {
        $this->clearMannyCargo($manny);
        $amounts = $this->resourceAmountsForTotal($amount, $profile);
        $manny->cargoDeuterium = $amounts[ResourceComposition::DEUTERIUM] ?? 0.0;
        $manny->cargoMetals = $amounts[ResourceComposition::METALS] ?? 0.0;
        $manny->cargoIce = $amounts[ResourceComposition::ICE] ?? 0.0;
        $manny->cargoOrganicCompounds = $amounts[ResourceComposition::CARBON_COMPOUNDS] ?? 0.0;
    }

    /**
     * @return array<string, float>
     */
    private function miningResourceProfile(Manny $manny): array
    {
        $profile = $manny->taskPayload['resourceProfile'] ?? null;
        if (is_array($profile)) {
            $normalized = array_fill_keys(ResourceComposition::TYPES, 0.0);
            foreach (ResourceComposition::TYPES as $type) {
                $normalized[$type] = round(max(0.0, (float) ($profile[$type] ?? 0.0)), 4);
            }
            $normalized[ResourceComposition::CARBON_COMPOUNDS] = round(
                $normalized[ResourceComposition::CARBON_COMPOUNDS] + max(0.0, (float) ($profile['other'] ?? 0.0)),
                4,
            );

            if (array_sum($normalized) > 0.0) {
                return $normalized;
            }
        }

        $resourceType = (string) ($manny->taskPayload['resourceType'] ?? 'metals');
        try {
            $resourceType = ResourceComposition::normalizeSelection($resourceType)[0];
        } catch (\InvalidArgumentException) {
            $resourceType = 'metals';
        }

        $legacyProfile = array_fill_keys(ResourceComposition::TYPES, 0.0);
        $legacyProfile[$resourceType] = 1.0;

        return $legacyProfile;
    }

    /**
     * @param array<string, float> $profile
     * @return array<string, float>
     */
    private function resourceAmountsForTotal(float $amount, array $profile): array
    {
        $amount = round(max(0.0, $amount), 4);
        $positiveTypes = array_values(array_filter(
            ResourceComposition::TYPES,
            static fn(string $type): bool => (float) ($profile[$type] ?? 0.0) > 0.0,
        ));
        if ($positiveTypes === []) {
            return array_fill_keys(ResourceComposition::TYPES, 0.0);
        }

        $amounts = array_fill_keys(ResourceComposition::TYPES, 0.0);
        $remaining = $amount;
        $lastIndex = count($positiveTypes) - 1;
        foreach ($positiveTypes as $index => $type) {
            if ($index === $lastIndex) {
                $amounts[$type] = round(max(0.0, $remaining), 4);
                break;
            }

            $resourceAmount = round($amount * (float) ($profile[$type] ?? 0.0), 4);
            $amounts[$type] = $resourceAmount;
            $remaining = round($remaining - $resourceAmount, 4);
        }

        return $amounts;
    }

    /**
     * @param array<string, float> $profile
     */
    private function cargoAmountForResourceProfile(float $amount, array $profile): float
    {
        return $this->cargoAmountForResources($this->resourceAmountsForTotal($amount, $profile));
    }

    /**
     * @param array<string, float> $amounts
     */
    private function cargoAmountForResources(array $amounts): float
    {
        $total = 0.0;
        foreach (ResourceComposition::TYPES as $type) {
            if ($type === ResourceComposition::DEUTERIUM) {
                continue;
            }

            $total += max(0.0, (float) ($amounts[$type] ?? 0.0));
        }

        return round($total, 4);
    }

    private function clearMannyCargo(Manny $manny): void
    {
        $manny->cargoDeuterium = 0.0;
        $manny->cargoMetals = 0.0;
        $manny->cargoIce = 0.0;
        $manny->cargoOrganicCompounds = 0.0;
    }

    private function mannyCargoIsEmpty(Manny $manny): bool
    {
        return $manny->cargoDeuterium <= 0.0001
            && $manny->cargoMetals <= 0.0001
            && $manny->cargoIce <= 0.0001
            && $manny->cargoOrganicCompounds <= 0.0001;
    }

    private function isJettisonableDriftingItemType(string $type): bool
    {
        return in_array($type, [
            ProbeItem::TYPE_WAYPOINT_BOOKMARK,
            ProbeItem::TYPE_STEEL_BAR,
            ProbeItem::TYPE_STEEL_PLATE,
            ProbeItem::TYPE_MICRO_CONDUCTOR,
            ProbeItem::TYPE_CERAMIC_INSULATOR,
            ProbeItem::TYPE_CRYSTAL_SUBSTRATE,
            ProbeItem::TYPE_DOPANT_MATRIX,
            ProbeItem::TYPE_INTEGRATED_CIRCUIT,
            ProbeItem::TYPE_ELECTRIC_MOTOR,
            ProbeItem::TYPE_BATTERY_PACK,
            ProbeItem::TYPE_LINEAR_ACTUATOR,
            ProbeItem::TYPE_THERMAL_PROTECTION_SHELL,
            ProbeItem::TYPE_PARACHUTE_PACK,
            ProbeItem::TYPE_DESCENT_GUIDANCE_MODULE,
            ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT,
        ], true);
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
            ProbeItem::TYPE_THERMAL_PROTECTION_SHELL => ProbeItem::THERMAL_PROTECTION_SHELL_NAME,
            ProbeItem::TYPE_PARACHUTE_PACK => ProbeItem::PARACHUTE_PACK_NAME,
            ProbeItem::TYPE_DESCENT_GUIDANCE_MODULE => ProbeItem::DESCENT_GUIDANCE_MODULE_NAME,
            ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT => ProbeItem::ATMOSPHERIC_DROP_KIT_NAME,
            default => $fallback !== null && trim($fallback) !== '' ? $fallback : $type,
        };
    }

    private function craftingConfig(): array
    {
        return Config::getArray($this->config, 'crafting');
    }

    private function repairSecondsPerIntegrityPercent(): int
    {
        return max(1, Config::int($this->config, 'manny.actions.repairSecondsPerIntegrityPercent', self::REPAIR_SECONDS_PER_INTEGRITY_PERCENT));
    }

    private function repairMetalsPerIntegrityPercent(): float
    {
        return max(0.0, Config::float($this->config, 'manny.actions.repairMetalsPerIntegrityPercent', self::REPAIR_METALS_PER_INTEGRITY_PERCENT));
    }

    private function miningTravelSeconds(): int
    {
        return max(0, Config::int($this->config, 'manny.actions.miningTravelSeconds', self::MINING_TRAVEL_SECONDS));
    }

    private function miningAmountPerTick(): float
    {
        return max(0.0001, Config::float($this->config, 'manny.actions.miningAmountPerTick', self::MINING_AMOUNT_PER_TICK));
    }

    private function miningTickSeconds(): int
    {
        return max(1, Config::int($this->config, 'manny.actions.miningTickSeconds', self::MINING_TICK_SECONDS));
    }

    private function salvageSeconds(): int
    {
        return max(1, Config::int($this->config, 'manny.actions.salvageSeconds', self::SALVAGE_SECONDS));
    }

    private function detachStorageContainerSeconds(): int
    {
        return $this->salvageSeconds() + 60;
    }

    private function dropStorageContainerSeconds(): int
    {
        return $this->salvageSeconds() + 120;
    }

    private function waypointBookmarkInstallSeconds(): int
    {
        return max(1, Config::int($this->config, 'manny.actions.waypointBookmarkInstallSeconds', self::WAYPOINT_BOOKMARK_INSTALL_SECONDS));
    }

    private function storageMoveSecondsPerUnit(): int
    {
        return max(1, Config::int($this->config, 'manny.actions.storageMoveSecondsPerUnit', self::STORAGE_MOVE_SECONDS_PER_UNIT));
    }

    private function mannyCargoCapacity(): float
    {
        return max(0.0001, Config::float($this->config, 'manny.cargoCapacity', self::MANNY_CARGO_CAPACITY));
    }

    private function mannyContainerSpace(): float
    {
        return max(0.0, Config::float($this->config, 'manny.containerSpace', self::MANNY_CONTAINER_SPACE));
    }

    private function mineablePlanetMaxMass(): float
    {
        return max(0.0, Config::float($this->config, 'manny.mineablePlanetMaxMassEarthUnits', self::MOON_MASS_EARTH_UNITS));
    }

    private function maxIntegrityPercent(): float
    {
        return max(0.0001, Config::float($this->config, 'probe.maxIntegrityPercent', self::MAX_INTEGRITY_PERCENT));
    }

    private function mannyCargoArray(Manny $manny): array
    {
        return array_replace($manny->cargoArray(), ['capacity' => $this->mannyCargoCapacity()]);
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

    private function registerMannyInSector(Manny $manny, string $state): void
    {
        if ($manny->sector === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        $object = new SectorManny(
            SectorManny::objectIdForUid($manny->uid),
            $manny->name,
            $manny->uid,
            $state,
            $this->mannyCargoArray($manny),
            $state === SectorManny::STATE_FORGOTTEN
                ? 'Manny left behind by its probe.'
                : 'Manny abandoned in open space.',
        );

        if (!$sector->replaceObject($object)) {
            $sector->addObject($object);
        }
        $this->sectors->saveSector($sector);
    }

    private function recoverForgottenManniesInCurrentSector(NeumannProbe $probe): void
    {
        $sector = $this->sectors->getOrCreateSector($probe->currentSector);
        $changed = false;
        foreach ($sector->getObjects() as $object) {
            if (!$object instanceof SectorManny || $object->getState() !== SectorManny::STATE_FORGOTTEN) {
                continue;
            }

            $manny = $this->mannies->findByUidForProbe($probe->id, $object->getMannyUid());
            if (
                $manny === null
                || !$manny->isInSameSectorAs($probe)
                || $manny->currentTask !== null
                || $manny->isOnProbe()
            ) {
                continue;
            }

            if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
                continue;
            }

            $sector->removeObjectById($object->getId());
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
            $this->clearTask($manny);
            $this->mannies->save($manny);
            $changed = true;
        }

        if ($changed) {
            $this->sectors->saveSector($sector);
        }
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

    private function clearTask(Manny $manny, array $payload = []): void
    {
        $manny->currentTask = null;
        $manny->taskStartedAt = null;
        $manny->taskEndsAt = null;
        $manny->taskPayload = $payload;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
