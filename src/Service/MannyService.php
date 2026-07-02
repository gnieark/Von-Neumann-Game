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
use VonNeumannGame\Domain\ProbeImprovementCatalog;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Domain\ScutRelay;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\ProbeImprovementRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\DeuteriumRefuelStation;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorCoordinates;
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
    public const DEUTERIUM_TANK_REFILL_SECONDS = 60;
    public const SCUT_RELAY_TURN_ON_SECONDS = 300;
    public const MANNY_CARGO_CAPACITY = Manny::CARGO_CAPACITY;
    public const MANNY_CONTAINER_SPACE = Manny::CONTAINER_SPACE;
    public const MOON_MASS_EARTH_UNITS = 0.0123;
    public const MAX_INTEGRITY_PERCENT = 100.0;
    public const WAYPOINT_BOOKMARK_METALS_COST = CraftingRecipeCatalog::WAYPOINT_BOOKMARK_METALS_COST;
    public const WAYPOINT_BOOKMARK_CONTAINER_SPACE = CraftingRecipeCatalog::WAYPOINT_BOOKMARK_CONTAINER_SPACE;
    public const WAYPOINT_BOOKMARK_CRAFTING_SECONDS = CraftingRecipeCatalog::WAYPOINT_BOOKMARK_CRAFTING_SECONDS;
    public const PUBLIC_TASK_UNKNOWN_TOO_FAR = 'unknown_too_far';
    public const TASK_VISIBILITY_LOCAL = 'local';
    public const TASK_VISIBILITY_SCUT_NETWORK = 'scut_network';
    public const TASK_VISIBILITY_TOO_FAR = 'too_far';

    private readonly WaypointBookmarkService $bookmarks;

    public function __construct(
        private readonly MannyRepository $mannies,
        private readonly NeumannProbeRepository $probes,
        private readonly SectorService $sectors,
        private readonly ProbeItemRepository $items,
        private readonly ProbeStorageService $storage,
        private readonly array $config = [],
        ?WaypointBookmarkService $bookmarks = null,
        private readonly ?MissionService $missions = null,
        private readonly ?ScutNetworkService $scut = null,
        private readonly ?ProbeDamageWarningRepository $alerts = null,
        private readonly ?ProbeImprovementRepository $improvements = null,
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

    public function maxDeuteriumPercentForProbe(NeumannProbe $probe): float
    {
        return $this->maxDeuteriumPercent($probe);
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

    public function startMining(NeumannProbe $probe, string $uid, string $objectId, string|array $resourceTypes, float $targetAmount, ?string $targetContainerId = null): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);
        $remoteViaScut = !$manny->isInSameSectorAs($probe);
        if ($remoteViaScut) {
            if (!$this->canOrderRemoteMannyViaScut($probe, $manny)) {
                $this->ensureMannyInRange($manny, $probe);
            }
            if ($targetContainerId === null) {
                throw new MannyActionException(422, 'invalid_storage_container', 'Remote SCUT mining requires a detached target container in the Manny sector.');
            }
        }

        try {
            $selectedResources = ResourceComposition::normalizeSelection($resourceTypes);
        } catch (\InvalidArgumentException $e) {
            throw new MannyActionException(400, 'bad_request', $e->getMessage());
        }
        $targetAmount = round($targetAmount, 4);
        if ($targetAmount <= 0) {
            throw new MannyActionException(400, 'bad_request', 'Mining target amount must be greater than zero.');
        }

        $taskSector = $manny->sector ?? $probe->currentSector;
        $sector = $this->sectors->getOrCreateSector($taskSector);
        $target = $sector->findObjectById($objectId);
        if ($target === null || !$this->isMineableObject($target)) {
            throw new MannyActionException(422, 'invalid_mining_target', 'This object cannot be mined by a Manny.');
        }

        $availableAmounts = $target instanceof Asteroid
            ? $this->availableAsteroidResourceAmountsForOrders($probe, $target, $taskSector)
            : null;
        $composition = $availableAmounts !== null
            ? ResourceComposition::fromAmounts($availableAmounts)
            : $this->resourceComposition($target);
        $available = ResourceComposition::availableTypes($composition);
        $unavailable = array_diff($selectedResources, $available);
        if ($unavailable !== []) {
            throw new MannyActionException(422, 'resource_unavailable', 'The requested resource is not present on this object.');
        }

        $targetContainer = null;
        $miningTravelSeconds = $this->miningTravelSeconds();
        $requestedTargetAmount = $targetAmount;
        if ($targetContainerId !== null) {
            $targetContainer = $this->miningTargetContainer($sector, $targetContainerId, $objectId);
            if (in_array(ResourceComposition::DEUTERIUM, $selectedResources, true)) {
                throw new MannyActionException(422, 'invalid_storage_container', 'Detached storage containers cannot receive deuterium.');
            }
            $targetContainerFreeCapacity = $this->detachedContainerFreeCapacity($targetContainer['container']);
            if ($targetContainerFreeCapacity <= 0.0001) {
                throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Target detached container is full.');
            }
            $targetAmount = round(min($targetAmount, $targetContainerFreeCapacity), 4);
            $miningTravelSeconds = $targetContainer['sameAsteroid'] ? 0 : $miningTravelSeconds;
        }

        $resourceProfile = ResourceComposition::profileForSelection($composition, $selectedResources);
        if ($target instanceof Asteroid && $availableAmounts !== null) {
            $this->ensureAsteroidHasResources($availableAmounts, $resourceProfile, $targetAmount);
        }
        $artificialObjectDetected = $target instanceof Asteroid
            ? $this->hiddenDetachedContainerDetection($sector, $target->getId(), $probe->playerId)
            : null;
        $probeIncomingResources = $targetContainer === null ? $this->resourceAmountsForTotal($targetAmount, $resourceProfile) : [];
        if (!$this->storage->canStoreIncoming($probe, $probeIncomingResources, [['type' => 'manny', 'space' => $this->mannyContainerSpace()]])) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for this mining target.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $taskSector;
        $manny->currentTask = Manny::TASK_MINING;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $this->miningDurationSeconds($targetAmount, $miningTravelSeconds) . ' seconds')->format('c');
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
            'miningTravelSeconds' => $miningTravelSeconds,
        ]
            + ($requestedTargetAmount > $targetAmount ? ['requestedTargetAmount' => $requestedTargetAmount] : [])
            + ($targetContainer !== null ? ['targetContainer' => $this->miningTargetContainerPayload($targetContainer['container'], $targetContainer['sameAsteroid'])] : [])
            + ($artificialObjectDetected !== null ? ['artificialObjectDetected' => $artificialObjectDetected] : []);
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

    public function startProbeImprovement(NeumannProbe $probe, string $uid, string $improvement): Manny
    {
        if ($this->improvements === null) {
            throw new MannyActionException(500, 'internal_error', 'Probe improvement storage is unavailable.');
        }

        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to improve it.');
        }

        $improvement = ProbeImprovementCatalog::normalizeId($improvement);
        $definition = ProbeImprovementCatalog::find($improvement, $this->probeImprovementConfig());
        if ($definition === null) {
            throw new MannyActionException(400, 'invalid_probe_improvement', 'Unknown probe improvement.');
        }

        $state = $this->improvements->findForProbe($probe->id, $improvement);
        if ($state === null || (!$state->available && !$state->done)) {
            throw new MannyActionException(422, 'probe_improvement_unavailable', 'This probe improvement is not available yet.');
        }
        if ($state->done) {
            throw new MannyActionException(409, 'probe_improvement_already_done', 'This probe improvement has already been completed.');
        }

        $plan = $this->probeImprovementPlan($probe, $definition);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->consumeProbeImprovementPlan($probe, $plan);
        $this->probes->save($probe);

        $manny->currentTask = Manny::TASK_IMPROVING_PROBE;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . (int) $plan['durationSeconds'] . ' seconds')->format('c');
        $manny->taskPayload = [
            'improvement' => $improvement,
            'improvementName' => (string) ($definition['name'] ?? $improvement),
            'durationSeconds' => (int) $plan['durationSeconds'],
            'ingredients' => is_array($definition['ingredients'] ?? null) ? $definition['ingredients'] : [],
            'resourceCosts' => $plan['resourceCosts'],
            'consumedItems' => $plan['consumedItems'],
            'effects' => is_array($definition['effects'] ?? null) ? $definition['effects'] : [],
        ];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startSalvage(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);

        $target = $this->findObjectInCurrentSector($probe, $objectId) ?? $this->findScutRelayInCurrentSector($probe, $objectId);
        if ($target === null || !$this->isSalvageableTarget($target)) {
            throw new MannyActionException(422, 'invalid_salvage_target', 'This object cannot be recovered by a Manny.');
        }
        if ($target instanceof ScutRelay) {
            $this->ensureScutRelayNotAlreadyBeingSalvaged($probe, $target, $manny->id);
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
        ] + ($mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID
            ? ['artificialObjectDetected' => $this->hiddenDetachedContainerDetectionPayload($detachedObjectId, (string) $objectId)]
            : []);
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

    public function startInspectSectorObject(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);
        $remoteViaScut = !$manny->isInSameSectorAs($probe);
        if ($remoteViaScut && !$this->canOrderRemoteMannyViaScut($probe, $manny)) {
            $this->ensureMannyInRange($manny, $probe);
        } elseif (!$remoteViaScut) {
            $this->ensureMannyInRange($manny, $probe);
        }

        $taskSector = $manny->sector ?? $probe->currentSector;
        $sector = $this->sectors->getOrCreateSector($taskSector);
        $target = $this->findInspectableSectorObject($sector, $objectId, $probe->playerId);
        if ($target === null) {
            throw new MannyActionException(422, 'invalid_sector_object_target', 'This object cannot be inspected by a Manny.');
        }

        $detection = $target instanceof Asteroid
            ? $this->hiddenDetachedContainerDetection($sector, $objectId, $probe->playerId)
            : null;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $durationSeconds = $this->miningTravelSeconds() * 2;
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $taskSector;
        $manny->currentTask = Manny::TASK_INSPECTING_SECTOR_OBJECT;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'durationSeconds' => $durationSeconds,
            'target' => $this->bookmarkTargetArray($target),
            'targetMode' => $target instanceof SectorDetachedContainer ? $target->getMode() : null,
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
        $this->recallMiningManniesTargetingDetachedContainer($probe, $manny->id, $objectId);
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

    private function recallMiningManniesTargetingDetachedContainer(NeumannProbe $probe, int $recoveringMannyId, string $containerId): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if (
                $manny->id === $recoveringMannyId
                || $manny->currentTask !== Manny::TASK_MINING
                || $this->miningTaskTargetContainerId($manny) !== $containerId
            ) {
                continue;
            }

            $returnDurationSeconds = $this->recallReturnDurationSeconds($manny, $now);
            $droppedCargo = $this->dropWaitingMannyCargo($manny);
            $this->clearMannyCargo($manny);
            $manny->currentTask = Manny::TASK_RETURNING;
            $manny->taskStartedAt = $now->format('c');
            $manny->taskEndsAt = $now->modify('+' . $returnDurationSeconds . ' seconds')->format('c');
            $manny->taskPayload = [
                'reason' => 'target_container_recovered',
                'lastTask' => Manny::TASK_MINING,
                'result' => 'cancelled',
                'targetContainerId' => $containerId,
                'droppedCargo' => $droppedCargo,
            ];
            $this->removeMannyFromSector($manny);
            $this->mannies->save($manny);
        }
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

    public function startDeuteriumTankRefill(NeumannProbe $probe, string $uid): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $this->missions?->completeReadyReturnToSpacePrograms($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to refill its deuterium tank.');
        }
        if (!$this->currentSectorHasDeuteriumRefuelStation($probe)) {
            throw new MannyActionException(422, 'deuterium_refuel_station_not_found', 'No deuterium refuel station is available in the current sector.');
        }
        if ($probe->deuteriumStock >= $this->maxDeuteriumPercent($probe) - 0.0001) {
            throw new MannyActionException(409, 'probe_deuterium_full', 'The probe deuterium tank is already full.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->currentTask = Manny::TASK_REFILLING_DEUTERIUM_TANK;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . self::DEUTERIUM_TANK_REFILL_SECONDS . ' seconds')->format('c');
        $manny->taskPayload = [
            'durationSeconds' => self::DEUTERIUM_TANK_REFILL_SECONDS,
            'resourceType' => ResourceComposition::DEUTERIUM,
        ];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startScutRelayTurnOn(NeumannProbe $probe, string $uid, int $relayId, ?string $networkName = null): Manny
    {
        if ($this->scut === null) {
            throw new MannyActionException(500, 'internal_error', 'SCUT relay service is unavailable.');
        }

        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to turn on a SCUT relay.');
        }
        if (!$this->currentSectorHasStar($probe)) {
            throw new MannyActionException(422, 'scut_relay_requires_star', 'A SCUT relay needs solar energy and can only be turned on in a sector with a star.');
        }

        $relay = $this->scut->relayById($relayId)
            ?? throw new MannyActionException(404, 'scut_relay_not_found', 'SCUT relay not found.');
        if (!$relay->sector->equals($probe->currentSector)) {
            throw new MannyActionException(422, 'scut_relay_not_in_sector', 'SCUT relay must be in the current sector.');
        }
        if ($relay->isOn()) {
            throw new MannyActionException(409, 'scut_relay_already_on', 'SCUT relay is already on.');
        }

        $circuit = $this->firstItemOfType($probe, ProbeItem::TYPE_INTEGRATED_CIRCUIT);
        if ($circuit === null) {
            throw new MannyActionException(422, 'missing_electronic_card', 'A SCUT relay requires one integrated circuit.');
        }
        $this->items->delete($circuit);

        $durationSeconds = $this->scutRelayTurnOnSeconds();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->currentTask = Manny::TASK_TURNING_ON_SCUT_RELAY;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'relayId' => $relay->id,
            'networkName' => $networkName !== null ? trim($networkName) : null,
            'durationSeconds' => $durationSeconds,
            'consumedItem' => $this->consumedItemPayload($circuit),
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
        if (!$manny->isInSameSectorAs($probe) && $this->canRecallRemoteMannyViaScut($probe, $manny)) {
            return $this->abandonRemoteMannyTask($manny);
        }
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
        if ($manny->currentTask === Manny::TASK_IMPROVING_PROBE) {
            $this->refundProbeImprovementCommitment($probe, $manny);
            $this->clearTask($manny);
            $this->mannies->save($manny);

            return $this->requiredManny($probe, $uid);
        }
        if ($manny->currentTask === Manny::TASK_TURNING_ON_SCUT_RELAY) {
            $consumedItem = is_array($manny->taskPayload['consumedItem'] ?? null) ? $manny->taskPayload['consumedItem'] : null;
            if ($consumedItem !== null) {
                $this->restoreConsumedItem($probe, $consumedItem);
            }
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
        $returnDurationSeconds = $this->recallReturnDurationSeconds($manny, $now);
        $manny->currentTask = Manny::TASK_RETURNING;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $returnDurationSeconds . ' seconds')->format('c');
        $manny->taskPayload = ['reason' => 'recall'];
        $this->removeMannyFromSector($manny);
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    private function canRecallRemoteMannyViaScut(NeumannProbe $probe, Manny $manny): bool
    {
        return $manny->currentTask !== null
            && $manny->sector !== null
            && $this->scut !== null
            && $this->scut->canSectorsCommunicate($probe->currentSector, $manny->sector);
    }

    private function canOrderRemoteMannyViaScut(NeumannProbe $probe, Manny $manny): bool
    {
        return $manny->currentTask === null
            && $manny->sector !== null
            && $this->scut !== null
            && $this->scut->canSectorsCommunicate($probe->currentSector, $manny->sector);
    }

    private function canRefreshRemoteMiningViaScut(NeumannProbe $probe, Manny $manny): bool
    {
        return $manny->currentTask === Manny::TASK_MINING
            && $this->miningTaskTargetContainerId($manny) !== null
            && $manny->sector !== null
            && $this->scut !== null
            && $this->scut->canSectorsCommunicate($probe->currentSector, $manny->sector);
    }

    private function canRefreshRemoteInspectViaScut(NeumannProbe $probe, Manny $manny): bool
    {
        return $manny->currentTask === Manny::TASK_INSPECTING_SECTOR_OBJECT
            && $manny->sector !== null
            && $this->scut !== null
            && $this->scut->canSectorsCommunicate($probe->currentSector, $manny->sector);
    }

    private function abandonRemoteMannyTask(Manny $manny): Manny
    {
        $lastTask = $manny->currentTask;
        if ($manny->currentTask === Manny::TASK_SALVAGE) {
            $this->restoreReservedSalvageItem($manny);
            $this->restoreReservedDetachedContainer($manny);
        }

        $this->clearTask($manny, [
            'lastTask' => $lastTask,
            'result' => 'forgotten',
            'reason' => 'remote_scut_recall',
        ]);
        $manny->locationType = Manny::LOCATION_SECTOR;
        $this->registerMannyInSector($manny, SectorManny::STATE_FORGOTTEN);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    public function dropMannyCargo(NeumannProbe $probe, string $uid): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->requiredManny($probe, $uid);
        $this->ensureMannyInRange($manny, $probe);
        if ($manny->currentTask !== Manny::TASK_WAITING_FOR_SPACE) {
            throw new MannyActionException(409, 'manny_not_waiting_for_space', 'The Manny is not waiting for storage space.');
        }

        $droppedCargo = $this->dropWaitingMannyCargo($manny);
        $resultPayload = [
            'lastTask' => 'drop_manny_cargo',
            'result' => 'success',
            'droppedCargo' => $droppedCargo,
        ];
        $this->clearMannyCargo($manny);
        $manny->taskPayload = $resultPayload;

        if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
            $this->waitForStorageSpace($manny, ['reason' => 'return_to_probe'] + $resultPayload);
            $this->probes->save($probe);
            $this->mannies->save($manny);

            return $this->mannies->findById($manny->id) ?? $manny;
        }

        $this->removeMannyFromSector($manny);
        $manny->locationType = Manny::LOCATION_PROBE;
        $manny->sector = null;
        $this->clearTask($manny, $resultPayload);
        $this->probes->save($probe);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
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

        if ($item->type === ProbeItem::TYPE_SCUT_RELAY) {
            if ($this->scut === null) {
                throw new MannyActionException(500, 'internal_error', 'SCUT relay service is unavailable.');
            }
            $relay = $this->scut->createOffRelay($probe->currentSector, $probe->id);
            $this->items->delete($item);

            return [
                'type' => $item->type,
                'name' => $this->itemDisplayName($item->type, $item->name),
                'quantity' => 1,
                'objectId' => (string) $relay->id,
                'status' => $relay->status,
                'containerSpace' => $item->containerSpace,
                'capacityUnit' => ProbeInventory::CAPACITY_UNIT,
            ];
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
        if (
            !$manny->isInSameSectorAs($probe)
            && !$this->canRefreshRemoteMiningViaScut($probe, $manny)
            && !$this->canRefreshRemoteInspectViaScut($probe, $manny)
        ) {
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
        if ($this->isInspectingSectorObjectTask($manny->currentTask)) {
            return $this->refreshInspectSectorObject($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_REFILLING_DEUTERIUM_TANK) {
            return $this->refreshDeuteriumTankRefill($manny, $probe, $now);
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
        if ($manny->currentTask === Manny::TASK_TURNING_ON_SCUT_RELAY) {
            return $this->refreshScutRelayTurnOn($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_IMPROVING_PROBE) {
            return $this->refreshProbeImprovement($manny, $probe, $now);
        }

        return $manny;
    }

    public function publicArray(NeumannProbe $probe, Manny $manny, ?array $relativeSector = null): array
    {
        $taskVisibility = $this->taskVisibilityFor($probe, $manny);
        $currentTask = $manny->currentTask;
        $taskProgressPercent = $manny->taskProgressPercent();
        $taskEstimatedEndTime = $manny->taskEndsAt;
        $task = $this->publicTaskPayload($manny);
        if ($manny->currentTask !== null && $taskVisibility === self::TASK_VISIBILITY_TOO_FAR) {
            $currentTask = self::PUBLIC_TASK_UNKNOWN_TOO_FAR;
            $taskProgressPercent = 0.0;
            $taskEstimatedEndTime = null;
            $task = [];
        }

        return [
            'id' => $manny->uid,
            'name' => $manny->name,
            'location' => $manny->isOnProbe()
                ? ['type' => Manny::LOCATION_PROBE]
                : ['type' => Manny::LOCATION_SECTOR, 'sector' => ['relative' => $relativeSector]],
            'currentTask' => $currentTask,
            'taskProgressPercent' => $taskProgressPercent,
            'taskEstimatedEndTime' => $taskEstimatedEndTime,
            'task' => $task,
            'taskVisibility' => $taskVisibility,
            'cargo' => $this->mannyCargoArray($manny),
            'canReceiveOrders' => $manny->probeId === $probe->id && $manny->isInSameSectorAs($probe) && $manny->currentTask === null,
        ];
    }

    private function taskVisibilityFor(NeumannProbe $probe, Manny $manny): string
    {
        if ($manny->isInSameSectorAs($probe)) {
            return self::TASK_VISIBILITY_LOCAL;
        }
        if (
            $manny->sector !== null
            && $this->scut !== null
            && $this->scut->canSectorsCommunicate($probe->currentSector, $manny->sector)
        ) {
            return self::TASK_VISIBILITY_SCUT_NETWORK;
        }

        return self::TASK_VISIBILITY_TOO_FAR;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicTaskPayload(Manny $manny): array
    {
        $payload = $manny->taskPayload;
        unset($payload['snapshot'], $payload['consumedKit'], $payload['targetSector']);

        if (is_array($payload['reservedDetachedContainer'] ?? null)) {
            unset($payload['reservedDetachedContainer']['object']);
        }

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
        $probe->addIntegrityPercent($integrityPercent, $this->maxIntegrityPercent());
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

        try {
            $this->createCraftingOutput($probe, $manny, $now);
        } catch (MannyActionException $e) {
            if ($e->errorCode !== 'insufficient_cargo_capacity') {
                throw $e;
            }

            $manny->taskPayload = array_merge($manny->taskPayload, [
                'waitingFor' => 'storage_space',
                'reason' => 'crafting_output',
                'failureReason' => $e->errorCode,
            ]);
            $this->mannies->save($manny);

            return $this->mannies->findById($manny->id) ?? $manny;
        }

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
        if ($target === null) {
            $target = $this->findScutRelayInSector($sector, $objectId);
        }
        if ($target === null || !$this->isSalvageableTarget($target)) {
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
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function probeImprovementPlan(NeumannProbe $probe, array $definition): array
    {
        $resourceCosts = [];
        $itemsToConsume = [];
        $consumedItems = [];
        $itemsByType = $this->probeItemsByType($probe);
        $ingredients = is_array($definition['ingredients'] ?? null) ? $definition['ingredients'] : [];
        foreach ($ingredients as $ingredient) {
            if (!is_array($ingredient)) {
                continue;
            }
            $type = (string) ($ingredient['type'] ?? '');
            if ($type === '') {
                continue;
            }

            $quantity = max(0.0, (float) ($ingredient['quantity'] ?? 0));
            if ($this->craftingIngredientKind($ingredient) !== 'item') {
                $this->addResourceCost($resourceCosts, $this->normalizeCraftResourceType($type), $quantity);
                continue;
            }

            $requiredCount = (int) ceil($quantity);
            $availableItems = $itemsByType[$type] ?? [];
            if (count($availableItems) < $requiredCount) {
                throw new MannyActionException(422, 'insufficient_improvement_ingredients', 'Insufficient probe inventory for this improvement.');
            }
            for ($index = 0; $index < $requiredCount; $index++) {
                $item = array_shift($availableItems);
                if (!$item instanceof ProbeItem) {
                    continue;
                }
                $itemsToConsume[] = $item;
                $consumedItems[] = $this->consumedItemPayload($item);
            }
            $itemsByType[$type] = $availableItems;
        }

        $this->ensureResourceCostsAvailable($probe, $resourceCosts);

        return [
            'durationSeconds' => max(1, (int) ($definition['durationSeconds'] ?? 1)),
            'resourceCosts' => $resourceCosts,
            'itemsToConsume' => $itemsToConsume,
            'consumedItems' => $consumedItems,
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

    /**
     * @param array<string, mixed> $plan
     */
    private function consumeProbeImprovementPlan(NeumannProbe $probe, array $plan): void
    {
        $this->consumeCraftingPlan($probe, $plan);
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

    private function refundProbeImprovementCommitment(NeumannProbe $probe, Manny $manny): void
    {
        $this->refundCraftingCommitment($probe, $manny);
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
        return $this->withMannyRefreshLock(
            $manny,
            $probe,
            fn(Manny $lockedManny): Manny => $this->refreshMiningLocked($lockedManny, $probe, $now),
        );
    }

    /**
     * @param callable(Manny): Manny $callback
     */
    private function withMannyRefreshLock(Manny $manny, NeumannProbe $probe, callable $callback): Manny
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'vng-manny-refresh-' . md5(__DIR__) . '-' . $manny->id . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return $callback($manny);
        }

        $locked = false;
        try {
            $locked = flock($handle, LOCK_EX);
            if (!$locked) {
                return $callback($manny);
            }

            $fresh = $this->mannies->findById($manny->id) ?? $manny;
            if ($fresh->currentTask !== Manny::TASK_MINING || (!$fresh->isInSameSectorAs($probe) && !$this->canRefreshRemoteMiningViaScut($probe, $fresh))) {
                return $fresh;
            }

            return $callback($fresh);
        } finally {
            if ($locked) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    private function refreshMiningLocked(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if ($manny->taskStartedAt === null) {
            return $manny;
        }

        $elapsed = max(0, $now->getTimestamp() - (new \DateTimeImmutable($manny->taskStartedAt))->getTimestamp());
        $targetAmount = (float) ($manny->taskPayload['targetAmount'] ?? 0);
        $progress = $this->miningProgress($targetAmount, $elapsed, $this->miningTaskTravelSeconds($manny));
        $resourceProfile = $this->miningResourceProfile($manny);
        $targetContainerId = $this->miningTaskTargetContainerId($manny);
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
            if ($targetContainerId !== null) {
                $acceptedDelivery = $this->transferMiningResourcesToDetachedContainer($manny, $resourceProfile, $deliveryAmount);
                $delivered = round($deposited + $acceptedDelivery, 4);
                if ($acceptedDelivery + 0.00001 < $deliveryAmount) {
                    $complete = true;
                    $manny->taskPayload['targetContainerFull'] = true;
                }
            } elseif (!$this->canAcceptMiningDelivery($probe, $resourceProfile, $deliveryAmount, false)) {
                $this->setMannyCargoProfile($manny, $resourceProfile, $deliveryAmount);
                $this->waitForStorageSpace($manny, [
                    'reason' => 'cargo_delivery',
                    'pendingAmount' => $deliveryAmount,
                    'resourceProfile' => $resourceProfile,
                ]);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }

            if ($targetContainerId === null) {
                $this->transferMiningResourcesToProbe($probe, $resourceProfile, $deliveryAmount);
            }
            $manny->taskPayload['depositedAmount'] = $delivered;
            $manny->taskPayload['depositedResources'] = $this->resourceAmountsForTotal((float) $manny->taskPayload['depositedAmount'], $resourceProfile);
        }

        $cargoAmount = round(min((float) $progress['cargoAmount'], max(0.0, $extracted - $delivered)), 4);
        $this->setMannyCargoProfile($manny, $resourceProfile, $cargoAmount);
        $manny->taskPayload['phase'] = $progress['phase'];
        $manny->taskPayload['tripIndex'] = $progress['tripIndex'];

        if ($complete) {
            $remaining = round((float) ($manny->taskPayload['extractedAmount'] ?? 0) - (float) ($manny->taskPayload['depositedAmount'] ?? 0), 4);
            if ($targetContainerId !== null) {
                $acceptedRemaining = $this->transferMiningResourcesToDetachedContainer($manny, $resourceProfile, $remaining);
                if ($acceptedRemaining > 0.0) {
                    $manny->taskPayload['depositedAmount'] = round((float) ($manny->taskPayload['depositedAmount'] ?? 0) + $acceptedRemaining, 4);
                    $manny->taskPayload['depositedResources'] = $this->resourceAmountsForTotal((float) $manny->taskPayload['depositedAmount'], $resourceProfile);
                }
                if ($acceptedRemaining + 0.00001 < $remaining) {
                    $manny->taskPayload['targetContainerFull'] = true;
                }
            } elseif (!$this->canAcceptMiningDelivery($probe, $resourceProfile, $remaining, true)) {
                $this->setMannyCargoProfile($manny, $resourceProfile, $remaining);
                $this->waitForStorageSpace($manny, [
                    'reason' => 'return_to_probe',
                    'pendingAmount' => $remaining,
                    'resourceProfile' => $resourceProfile,
                ]);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }
            if ($targetContainerId === null && $remaining > 0) {
                $this->transferMiningResourcesToProbe($probe, $resourceProfile, $remaining);
                $manny->taskPayload['depositedAmount'] = round((float) ($manny->taskPayload['depositedAmount'] ?? 0) + $remaining, 4);
                $manny->taskPayload['depositedResources'] = $this->resourceAmountsForTotal((float) $manny->taskPayload['depositedAmount'], $resourceProfile);
            }
            $this->clearMannyCargo($manny);
            if (!$manny->isInSameSectorAs($probe)) {
                $this->clearTask($manny);
                $this->registerMannyInSector($manny, SectorManny::STATE_FORGOTTEN);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }
            if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
                $this->waitForStorageSpace($manny, ['reason' => 'return_to_probe']);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }
            $this->removeMannyFromSector($manny);
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
            $this->clearTask($manny);
        }

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

    private function refreshScutRelayTurnOn(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }
        if ($this->scut === null) {
            throw new MannyActionException(500, 'internal_error', 'SCUT relay service is unavailable.');
        }

        $relayId = (int) ($manny->taskPayload['relayId'] ?? 0);
        $networkName = isset($manny->taskPayload['networkName']) && is_string($manny->taskPayload['networkName'])
            ? $manny->taskPayload['networkName']
            : null;
        $result = [
            'lastTask' => Manny::TASK_TURNING_ON_SCUT_RELAY,
            'relayId' => $relayId,
        ];

        try {
            $relay = $this->scut->turnOnRelay($relayId, $networkName);
            $result['result'] = 'success';
            $result['networkId'] = $relay->networkId;
            $result['status'] = $relay->status;
        } catch (MannyActionException $e) {
            $result['result'] = 'failed';
            $result['failureReason'] = $e->errorCode;
        }

        $this->clearTask($manny, $result);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshProbeImprovement(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }
        if ($this->improvements === null) {
            throw new MannyActionException(500, 'internal_error', 'Probe improvement storage is unavailable.');
        }

        $improvement = ProbeImprovementCatalog::normalizeId((string) ($manny->taskPayload['improvement'] ?? ''));
        $result = [
            'lastTask' => Manny::TASK_IMPROVING_PROBE,
            'improvement' => $improvement,
        ];

        if (ProbeImprovementCatalog::find($improvement, $this->probeImprovementConfig()) === null) {
            $result['result'] = 'failed';
            $result['failureReason'] = 'invalid_probe_improvement';
        } else {
            $this->improvements->markDone($probe->id, $improvement);
            $result['result'] = 'success';
            $result['effects'] = is_array($manny->taskPayload['effects'] ?? null) ? $manny->taskPayload['effects'] : [];
        }

        $this->clearTask($manny, $result);
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
            [],
            $mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID ? [(int) ($snapshot['ownerPlayerId'] ?? $probe->playerId)] : [],
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
        ] + ($mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID
            ? ['artificialObjectDetected' => $this->hiddenDetachedContainerDetectionPayload($object->getId(), $targetObjectId)]
            : []));
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
        $this->missions?->handleReturnToSpaceProgramMaterialDrop(
            $probe,
            $sector,
            $targetObjectId,
            (int) ($snapshot['ownerPlayerId'] ?? $probe->playerId),
            $object->getId(),
            is_array($snapshot['resources'] ?? null) ? $snapshot['resources'] : [],
        );
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

    private function refreshInspectSectorObject(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector ?? $probe->currentSector);
        $objectId = (string) ($manny->taskPayload['objectId'] ?? '');
        $target = $this->findInspectableSectorObject($sector, $objectId, $probe->playerId);
        $detection = $target instanceof Asteroid
            ? $this->hiddenDetachedContainerDetection($sector, $objectId, $probe->playerId)
            : null;
        $result = [
            'lastTask' => Manny::TASK_INSPECTING_SECTOR_OBJECT,
            'result' => 'success',
            'objectId' => $objectId,
            'target' => $manny->taskPayload['target'] ?? null,
        ] + ($detection !== null ? ['artificialObjectDetected' => $detection] : []);
        $reportScheduledAt = is_string($manny->taskEndsAt) && trim($manny->taskEndsAt) !== ''
            ? $manny->taskEndsAt
            : null;
        if ($target === null) {
            $result['result'] = 'failed';
            $result['failureReason'] = 'target_unavailable';
        } elseif ($target instanceof SectorDetachedContainer) {
            $report = $this->detachedContainerInspectionReport($target);
            $result['containerReport'] = $report;
            $this->alerts?->createMannyReportAlert(
                $probe->id,
                $sector->getCoordinates(),
                $target->getId(),
                (string) ($target->getName() ?? $target->getId()),
                $report['message'],
                scheduledAt: $reportScheduledAt,
            );
        } elseif ($target instanceof DormantConstruct) {
            $report = $this->dormantConstructInspectionReport($probe, $sector, $target);
            $this->alerts?->createMannyReportAlert(
                $probe->id,
                $sector->getCoordinates(),
                $target->getId(),
                (string) ($target->getName() ?? $target->getId()),
                $report['message'],
                'dormant_construct',
                $reportScheduledAt,
            );
        }

        if (!$manny->isInSameSectorAs($probe)) {
            $this->clearTask($manny, $result);
            $manny->locationType = Manny::LOCATION_SECTOR;
            $this->registerMannyInSector($manny, SectorManny::STATE_FORGOTTEN);
            $this->mannies->save($manny);

            return $this->mannies->findById($manny->id) ?? $manny;
        }

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

    private function isInspectingSectorObjectTask(?string $task): bool
    {
        return $task === Manny::TASK_INSPECTING_SECTOR_OBJECT || $task === 'inspecting_asteroid';
    }

    private function refreshDeuteriumTankRefill(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $probe->deuteriumStock = $this->maxDeuteriumPercent($probe);
        $this->probes->save($probe);
        $this->clearTask($manny, [
            'lastTask' => Manny::TASK_REFILLING_DEUTERIUM_TANK,
            'result' => 'success',
            'resourceType' => ResourceComposition::DEUTERIUM,
        ]);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function currentSectorHasDeuteriumRefuelStation(NeumannProbe $probe): bool
    {
        foreach ($this->sectors->getOrCreateSector($probe->currentSector)->getObjects() as $object) {
            if ($object instanceof DeuteriumRefuelStation) {
                return true;
            }
        }

        return false;
    }

    private function currentSectorHasStar(NeumannProbe $probe): bool
    {
        return $this->sectors->getOrCreateSector($probe->currentSector)->hasStar();
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

    private function findInspectableSectorObject(SectorContent $sector, string $objectId, int $playerId): ?UniverseObject
    {
        $target = $sector->findObjectById($objectId);
        if ($target instanceof Asteroid || $target instanceof DormantConstruct) {
            return $target;
        }
        if ($target instanceof SectorDetachedContainer && $target->getMode() === SectorDetachedContainer::MODE_DRIFTING) {
            return $target;
        }

        $hidden = $sector->findHiddenDetachedContainerById($objectId);
        if ($hidden instanceof SectorDetachedContainer && $hidden->isDiscoveredByPlayer($playerId)) {
            return $hidden;
        }

        return null;
    }

    private function findScutRelayInCurrentSector(NeumannProbe $probe, string $objectId): ?ScutRelay
    {
        return $this->findScutRelayInSector(
            $this->sectors->getOrCreateSector($probe->currentSector),
            $objectId,
        );
    }

    private function findScutRelayInSector(SectorContent $sector, string $objectId): ?ScutRelay
    {
        if ($this->scut === null || !ctype_digit($objectId)) {
            return null;
        }

        $relay = $this->scut->relayById((int) $objectId);
        if ($relay === null || !$relay->sector->equals($sector->getCoordinates())) {
            return null;
        }

        return $relay;
    }

    private function isMineableObject(UniverseObject $object): bool
    {
        return $object instanceof Asteroid
            || ($object instanceof Planet && $object->getMass() <= $this->mineablePlanetMaxMass());
    }

    private function isSalvageableTarget(UniverseObject|ScutRelay $object): bool
    {
        return ($object instanceof SectorManny && $object->getState() === SectorManny::STATE_ABANDONED)
            || ($object instanceof SectorDriftingItem && $object->getQuantity() > 0 && $object->getContainerSpace() > 0.0)
            || ($object instanceof SectorDetachedContainer && $object->getMode() === SectorDetachedContainer::MODE_DRIFTING)
            || ($object instanceof ScutRelay && !$object->isOn());
    }

    private function ensureScutRelayNotAlreadyBeingSalvaged(NeumannProbe $probe, ScutRelay $target, int $actorMannyId): void
    {
        $targetId = (string) $target->id;
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if ($manny->id === $actorMannyId || $manny->currentTask !== Manny::TASK_SALVAGE) {
                continue;
            }

            $payloadTarget = is_array($manny->taskPayload['target'] ?? null) ? $manny->taskPayload['target'] : [];
            if (
                (string) ($manny->taskPayload['objectId'] ?? '') === $targetId
                && ($payloadTarget['type'] ?? null) === ProbeItem::TYPE_SCUT_RELAY
            ) {
                throw new MannyActionException(422, 'invalid_salvage_target', 'This SCUT relay is already being recovered.');
            }
        }
    }

    private function salvageTargetArray(UniverseObject|ScutRelay $object): array
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
    private function hiddenDetachedContainerDetection(SectorContent $sector, string $objectId, ?int $discoveringPlayerId = null): ?array
    {
        $hidden = $sector->hiddenDetachedContainersForObject($objectId);
        if ($hidden === []) {
            return null;
        }

        $container = $hidden[0];
        if ($discoveringPlayerId !== null && !$container->isDiscoveredByPlayer($discoveringPlayerId)) {
            $container = $container->withDiscoveredByPlayer($discoveringPlayerId);
            $sector->replaceDetachedContainer($container);
            $this->sectors->saveSector($sector);
        }

        return $this->hiddenDetachedContainerDetectionPayload($container->getId(), $objectId);
    }

    /**
     * @return array<string, mixed>
     */
    private function hiddenDetachedContainerDetectionPayload(string $objectId, ?string $targetObjectId): array
    {
        return [
            'type' => 'detached_storage_container',
            'detection' => SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID,
            'objectId' => $objectId,
            'targetObjectId' => $targetObjectId,
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
            'salvageable' => $container->getMode() === SectorDetachedContainer::MODE_DRIFTING,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detachedContainerInspectionReport(SectorDetachedContainer $container): array
    {
        $payload = $container->getPayload();
        $resources = [];
        foreach (is_array($payload['resources'] ?? null) ? $payload['resources'] : [] as $type => $amount) {
            if (!is_numeric($amount) || (float) $amount <= 0.0) {
                continue;
            }
            $resources[(string) $type] = round((float) $amount, 4);
        }

        $items = [];
        foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            $name = (string) ($item['name'] ?? $this->itemDisplayName($type));
            $key = $type . '|' . $name;
            $items[$key] ??= [
                'type' => $type,
                'name' => $name,
                'quantity' => 0,
                'containerSpace' => 0.0,
            ];
            $items[$key]['quantity']++;
            $items[$key]['containerSpace'] = round(
                (float) $items[$key]['containerSpace'] + max(0.0, (float) ($item['containerSpace'] ?? 0.0)),
                4,
            );
        }
        $items = array_values($items);

        $label = (string) ($container->getName() ?? $container->getId());
        $messageParts = [];
        if ($resources !== []) {
            $messageParts[] = 'resources: ' . implode(', ', array_map(
                fn(string $type, float $amount): string => $this->resourceReportLabel($type) . ' ' . $this->amountReportLabel($amount),
                array_keys($resources),
                array_values($resources),
            ));
        }
        if ($items !== []) {
            $messageParts[] = 'items: ' . implode(', ', array_map(
                static fn(array $item): string => (string) ($item['name'] ?? $item['type'] ?? 'item') . ' x' . (int) ($item['quantity'] ?? 0),
                $items,
            ));
        }
        if ($messageParts === []) {
            $messageParts[] = 'no contents detected';
        }

        $message = 'Manny report: contents of ' . $label
            . ":\n- " . implode("\n- ", $messageParts);

        return [
            'objectId' => $container->getId(),
            'mode' => $container->getMode(),
            'targetObjectId' => $container->getTargetObjectId(),
            'resources' => $resources,
            'items' => $items,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function dormantConstructInspectionReport(NeumannProbe $probe, SectorContent $sector, DormantConstruct $construct): array
    {
        $scenario = $construct->getInspectionScenario();
        if ($scenario === null) {
            $scenarios = DormantConstruct::inspectionScenarios();
            $scenario = $scenarios[random_int(0, count($scenarios) - 1)];
            $construct = $construct->withInspectionScenario($scenario);
            if ($sector->replaceObject($construct)) {
                $this->sectors->saveSector($sector);
            }
        }

        $this->improvements?->markAvailable($probe->id, $scenario);

        return [
            'scenario' => $scenario,
            'message' => $this->dormantConstructReportMessage($scenario),
        ];
    }

    private function dormantConstructReportMessage(string $scenario): string
    {
        return match ($scenario) {
            ProbeImprovementCatalog::DEUTERIUM_COMPRESSION => "Manny report\n\n"
                . "The structure is artificial. Its geometry faintly echoes our own probe, but the underlying architecture follows a completely different lineage.\n\n"
                . "Several compartments show the marks of methodical dismantling rather than impact damage. A deuterium storage vessel contains density gradients that should not remain stable under ordinary tank geometry.\n\n"
                . "Recovered data: deuterium compression principles.\n\n"
                . "Probe improvement unlocked: Deuterium compression.",
            ProbeImprovementCatalog::REINFORCED_CONTAINER_COUPLINGS => "Manny report\n\n"
                . "The structure is artificial. Its internal layout resembles a modular warehouse more than a vessel: linked storage bays, load-bearing rails, and repeated coupling sockets.\n\n"
                . "Manny analysis focused on the module fasteners. The recovered pattern describes a more tolerant coupling geometry for external containers under movement stress.\n\n"
                . "Recovered data: reinforced container coupling design.\n\n"
                . "Probe improvement unlocked: Reinforced container couplings.",
            default => "Manny report\n\nThe structure is artificial, but the recovered data could not be classified.",
        };
    }

    private function resourceReportLabel(string $type): string
    {
        return match ($type) {
            ResourceComposition::DEUTERIUM => 'deutérium',
            ResourceComposition::METALS => 'métaux',
            ResourceComposition::ICE => 'glace',
            ResourceComposition::CARBON_COMPOUNDS => 'composés carbonés',
            default => $type,
        };
    }

    private function amountReportLabel(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.') . ' ECE';
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
     * @return array<string, mixed>
     */
    private function completeSalvageTarget(NeumannProbe $probe, SectorContent $sector, UniverseObject|ScutRelay $target): array
    {
        if ($target instanceof ScutRelay) {
            return $this->completeScutRelaySalvageTarget($probe, $sector, $target);
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
     * @return array<string, mixed>
     */
    private function completeScutRelaySalvageTarget(NeumannProbe $probe, SectorContent $sector, ScutRelay $target): array
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

    /**
     * @return list<array<string, mixed>>
     */
    private function dropWaitingMannyCargo(Manny $manny): array
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
    private function availableAsteroidResourceAmountsForOrders(NeumannProbe $probe, Asteroid $asteroid, SectorCoordinates $sector): array
    {
        $availableAmounts = $asteroid->getResourceAmounts();
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if ($manny->currentTask !== Manny::TASK_MINING || ($manny->taskPayload['objectId'] ?? null) !== $asteroid->getId()) {
                continue;
            }
            if ($manny->sector === null || !$manny->sector->equals($sector)) {
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

    /**
     * @return array{container:SectorDetachedContainer, sameAsteroid:bool}
     */
    private function miningTargetContainer(SectorContent $sector, string $containerId, string $objectId): array
    {
        $container = $sector->findDetachedContainerById($containerId)
            ?? throw new MannyActionException(404, 'detached_container_not_found', 'Detached storage container not found.');
        if (!in_array($container->getMode(), [SectorDetachedContainer::MODE_DRIFTING, SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID], true)) {
            throw new MannyActionException(422, 'invalid_storage_container', 'This detached container cannot receive mined resources.');
        }

        return [
            'container' => $container,
            'sameAsteroid' => $container->getMode() === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID
                && $container->getTargetObjectId() === $objectId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function miningTargetContainerPayload(SectorDetachedContainer $container, bool $sameAsteroid): array
    {
        return [
            'id' => $container->getId(),
            'type' => $container->getType()->value,
            'name' => $container->getName(),
            'mode' => $container->getMode(),
            'targetObjectId' => $container->getTargetObjectId(),
            'capacity' => $container->getCapacity(),
            'capacityUnit' => $container->getCapacityUnit(),
            'travelDeducted' => $sameAsteroid,
        ];
    }

    private function detachedContainerFreeCapacity(SectorDetachedContainer $container): float
    {
        return round(max(0.0, $container->getCapacity() - $this->detachedContainerUsedCapacity($container)), 4);
    }

    private function detachedContainerUsedCapacity(SectorDetachedContainer $container): float
    {
        $payload = $container->getPayload();
        $used = 0.0;
        $resources = is_array($payload['resources'] ?? null) ? $payload['resources'] : [];
        foreach ($resources as $amount) {
            if (is_numeric($amount)) {
                $used += max(0.0, (float) $amount);
            }
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        foreach ($items as $item) {
            if (is_array($item) && is_numeric($item['containerSpace'] ?? null)) {
                $used += max(0.0, (float) $item['containerSpace']);
            }
        }

        return round($used, 4);
    }

    private function miningDurationSeconds(float $targetAmount, ?int $travelSeconds = null): int
    {
        $remaining = round($targetAmount, 4);
        $duration = 0;
        $travelSeconds ??= $this->miningTravelSeconds();
        while ($remaining > 0.0001) {
            $tripAmount = min($this->mannyCargoCapacity(), $remaining);
            $duration += $travelSeconds;
            $duration += (int) ceil($tripAmount / $this->miningAmountPerTick()) * $this->miningTickSeconds();
            $duration += $travelSeconds;
            $remaining = round($remaining - $tripAmount, 4);
        }

        return $duration;
    }

    private function recallReturnDurationSeconds(Manny $manny, \DateTimeImmutable $now): int
    {
        $defaultDuration = $this->miningTravelSeconds();
        if ($manny->taskStartedAt === null) {
            return $defaultDuration;
        }

        $elapsedSeconds = max(0, $now->getTimestamp() - (new \DateTimeImmutable($manny->taskStartedAt))->getTimestamp());

        return $elapsedSeconds < $defaultDuration ? $elapsedSeconds : $defaultDuration;
    }

    private function miningProgress(float $targetAmount, int $elapsedSeconds, ?int $travelSeconds = null): array
    {
        $remaining = round($targetAmount, 4);
        $cursor = 0;
        $delivered = 0.0;
        $tripIndex = 1;
        $travelSeconds ??= $this->miningTravelSeconds();
        while ($remaining > 0.0001) {
            $tripAmount = min($this->mannyCargoCapacity(), $remaining);
            $outboundEnd = $cursor + $travelSeconds;
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

            $returnEnd = $miningEnd + $travelSeconds;
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

    /**
     * @param array<string, float> $profile
     */
    private function transferMiningResourcesToDetachedContainer(Manny $manny, array $profile, float $amount): float
    {
        $amount = round(max(0.0, $amount), 4);
        $targetContainerId = $this->miningTaskTargetContainerId($manny);
        if ($amount <= 0.0 || $targetContainerId === null || $manny->sector === null) {
            return 0.0;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        $container = $sector->findDetachedContainerById($targetContainerId);
        if ($container === null) {
            $manny->taskPayload['targetContainerMissing'] = true;
            return 0.0;
        }

        $accepted = round(min($amount, $this->detachedContainerFreeCapacity($container)), 4);
        if ($accepted <= 0.0) {
            $manny->taskPayload['targetContainerFull'] = true;
            return 0.0;
        }

        $payload = $container->getPayload();
        $resources = is_array($payload['resources'] ?? null) ? $payload['resources'] : [];
        foreach ($this->resourceAmountsForTotal($accepted, $profile) as $type => $resourceAmount) {
            if ($resourceAmount <= 0.0) {
                continue;
            }
            $resources[$type] = round(max(0.0, (float) ($resources[$type] ?? 0.0)) + $resourceAmount, 4);
        }
        $payload['resources'] = $resources;

        if ($sector->replaceDetachedContainer($container->withPayload($payload))) {
            $this->sectors->saveSector($sector);
        }

        return $accepted;
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

    private function miningTaskTravelSeconds(Manny $manny): int
    {
        if (isset($manny->taskPayload['miningTravelSeconds']) && is_numeric($manny->taskPayload['miningTravelSeconds'])) {
            return max(0, (int) $manny->taskPayload['miningTravelSeconds']);
        }

        return $this->miningTravelSeconds();
    }

    private function miningTaskTargetContainerId(Manny $manny): ?string
    {
        $targetContainer = $manny->taskPayload['targetContainer'] ?? null;
        if (!is_array($targetContainer) || !isset($targetContainer['id']) || !is_string($targetContainer['id'])) {
            return null;
        }

        $id = trim($targetContainer['id']);

        return $id !== '' ? $id : null;
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
            ProbeItem::TYPE_SOLAR_PANEL,
            ProbeItem::TYPE_SCUT_RELAY,
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
            ProbeItem::TYPE_SOLAR_PANEL => ProbeItem::SOLAR_PANEL_NAME,
            ProbeItem::TYPE_SCUT_RELAY => ProbeItem::SCUT_RELAY_NAME,
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

    private function scutRelayTurnOnSeconds(): int
    {
        return max(1, Config::int($this->config, 'manny.actions.scutRelayTurnOnSeconds', self::SCUT_RELAY_TURN_ON_SECONDS));
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

    private function maxDeuteriumPercent(?NeumannProbe $probe = null): float
    {
        $max = max(0.0001, Config::float($this->config, 'probe.maxDeuteriumPercent', 100.0));
        if (
            $probe !== null
            && $this->improvements !== null
            && $this->improvements->isDone($probe->id, ProbeImprovementCatalog::DEUTERIUM_COMPRESSION)
        ) {
            $definition = ProbeImprovementCatalog::find(ProbeImprovementCatalog::DEUTERIUM_COMPRESSION, $this->probeImprovementConfig());
            $effects = is_array($definition['effects'] ?? null) ? $definition['effects'] : [];
            $max = max($max, (float) ($effects['maxDeuteriumPercent'] ?? ProbeImprovementCatalog::DEUTERIUM_COMPRESSION_MAX_DEUTERIUM_PERCENT));
        }

        return $max;
    }

    /**
     * @return array<string, mixed>
     */
    private function probeImprovementConfig(): array
    {
        return is_array($this->config['probeImprovements'] ?? null) ? $this->config['probeImprovements'] : [];
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
