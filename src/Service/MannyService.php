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
use VonNeumannGame\Domain\ScheduledEvent;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\ProbeImprovementRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Service\Manny\DetachStorageContainerTaskHandler;
use VonNeumannGame\Service\Manny\DeuteriumTankRefillTaskHandler;
use VonNeumannGame\Service\Manny\DeuteriumTransferTaskHandler;
use VonNeumannGame\Service\Manny\DropStorageContainerTaskHandler;
use VonNeumannGame\Service\Manny\InspectSectorObjectTaskHandler;
use VonNeumannGame\Service\Manny\MannyPublicPresenter;
use VonNeumannGame\Service\Manny\MannyTaskRefresher;
use VonNeumannGame\Service\Manny\MannyTaskRuntime;
use VonNeumannGame\Service\Manny\MiningTaskHandler;
use VonNeumannGame\Service\Manny\ProbeAssemblyTaskHandler;
use VonNeumannGame\Service\Manny\ProbeImprovementTaskHandler;
use VonNeumannGame\Service\Manny\RepairTaskHandler;
use VonNeumannGame\Service\Manny\ReturningTaskHandler;
use VonNeumannGame\Service\Manny\SalvageTaskHandler;
use VonNeumannGame\Service\Manny\ScutRelayTurnOnTaskHandler;
use VonNeumannGame\Service\Manny\StorageMoveTaskHandler;
use VonNeumannGame\Service\Manny\TaskHandlerRegistry;
use VonNeumannGame\Service\Manny\WaypointBookmarkInstallationTaskHandler;
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

final class MannyService implements MannyTaskRuntime
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
    public const PROBE_ASSEMBLY_SECONDS = 10800;
    public const DEUTERIUM_TRANSFER_SECONDS = 300;
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
    private readonly MannyCraftingService $crafting;
    private readonly MannyCargoService $cargo;
    private readonly MannyPublicPresenter $presenter;
    private readonly MannyTaskRefresher $taskRefresher;
    private readonly RepairTaskHandler $repairTaskHandler;
    private readonly MiningTaskHandler $miningTaskHandler;
    private readonly DetachStorageContainerTaskHandler $detachStorageContainerTaskHandler;
    private readonly DropStorageContainerTaskHandler $dropStorageContainerTaskHandler;
    private readonly InspectSectorObjectTaskHandler $inspectSectorObjectTaskHandler;
    private readonly ProbeAssemblyTaskHandler $probeAssemblyTaskHandler;
    private readonly ProbeImprovementTaskHandler $probeImprovementTaskHandler;
    private readonly DeuteriumTankRefillTaskHandler $deuteriumTankRefillTaskHandler;
    private readonly DeuteriumTransferTaskHandler $deuteriumTransferTaskHandler;
    private readonly ReturningTaskHandler $returningTaskHandler;
    private readonly SalvageTaskHandler $salvageTaskHandler;
    private readonly ScutRelayTurnOnTaskHandler $scutRelayTurnOnTaskHandler;
    private readonly StorageMoveTaskHandler $storageMoveTaskHandler;
    private readonly WaypointBookmarkInstallationTaskHandler $waypointBookmarkInstallationTaskHandler;

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
        ?array $taskHandlers = null,
        private readonly ?ScheduledEventRepository $scheduledEvents = null,
        ?MannyCraftingService $crafting = null,
        ?MannyCargoService $cargo = null,
    ) {
        $this->bookmarks = $bookmarks ?? new WaypointBookmarkService($items, $sectors);
        $this->crafting = $crafting ?? new MannyCraftingService($mannies, $probes, $items, $storage, $config);
        $this->cargo = $cargo ?? new MannyCargoService($mannies, $sectors, $storage, $config, $scut);
        $this->presenter = new MannyPublicPresenter(
            $this->scut,
            fn(Manny $manny): array => $this->mannyCargoArray($manny),
        );
        $this->repairTaskHandler = new RepairTaskHandler(
            $this->mannies,
            $this->probes,
            $this->storage,
            $this->config,
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
        );
        $this->miningTaskHandler = new MiningTaskHandler(
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            function (NeumannProbe $probe, Manny $manny): void {
                $this->refreshOtherMannyStates($probe, $manny);
            },
            fn(Manny $manny, NeumannProbe $probe): bool => $this->canOrderRemoteMannyViaScut($probe, $manny),
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            fn(mixed $sectorCoordinates): SectorContent => $this->sectors->getOrCreateSector($sectorCoordinates),
            fn(UniverseObject $target): bool => $this->isMineableObject($target),
            fn(NeumannProbe $probe, Asteroid $asteroid, mixed $sectorCoordinates): array => $this->availableAsteroidResourceAmountsForOrders($probe, $asteroid, $sectorCoordinates),
            fn(UniverseObject $target): array => $this->resourceComposition($target),
            function (array $availableAmounts, array $resourceProfile, float $targetAmount): void {
                $this->ensureAsteroidHasResources($availableAmounts, $resourceProfile, $targetAmount);
            },
            fn(SectorContent $sector, string $objectId, ?int $discoveringPlayerId): ?array => $this->hiddenDetachedContainerDetection($sector, $objectId, $discoveringPlayerId),
            fn(SectorContent $sector, string $containerId, string $objectId): array => $this->miningTargetContainer($sector, $containerId, $objectId),
            fn(SectorDetachedContainer $container): float => $this->detachedContainerFreeCapacity($container),
            fn(float $amount, array $resourceProfile): array => $this->resourceAmountsForTotal($amount, $resourceProfile),
            fn(NeumannProbe $probe, array $incomingResources, Manny $manny): bool => $this->storage->canStoreIncoming($probe, $incomingResources, [['type' => 'manny', 'space' => $this->mannyContainerSpace()]], $manny->uid),
            fn(UniverseObject $target): array => $this->miningTargetArray($target),
            fn(SectorDetachedContainer $container, bool $sameAsteroid): array => $this->miningTargetContainerPayload($container, $sameAsteroid),
            fn(): int => $this->miningTravelSeconds(),
            fn(float $targetAmount, ?int $travelSeconds): int => $this->miningDurationSeconds($targetAmount, $travelSeconds),
            function (Manny $manny): void {
                $this->storage->releaseMannyFromStorage($manny);
            },
            function (Manny $manny): void {
                $this->removeMannyFromSector($manny);
            },
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            fn(float $targetAmount, int $elapsedSeconds, ?int $travelSeconds): array => $this->miningProgress($targetAmount, $elapsedSeconds, $travelSeconds),
            fn(Manny $manny): int => $this->miningTaskTravelSeconds($manny),
            fn(Manny $manny): array => $this->miningResourceProfile($manny),
            fn(Manny $manny): ?string => $this->miningTaskTargetContainerId($manny),
            fn(Manny $manny, array $resourceProfile, float $amount): float => $this->depleteMiningTarget($manny, $resourceProfile, $amount),
            fn(Manny $manny, array $resourceProfile, float $amount): float => $this->transferMiningResourcesToDetachedContainer($manny, $resourceProfile, $amount),
            fn(NeumannProbe $probe, array $resourceProfile, float $amount, bool $includeManny): bool => $this->canAcceptMiningDelivery($probe, $resourceProfile, $amount, $includeManny),
            function (Manny $manny, array $resourceProfile, float $amount): void {
                $this->setMannyCargoProfile($manny, $resourceProfile, $amount);
            },
            function (Manny $manny, array $payload): void {
                $this->cargo->waitForStorageSpace($manny, $payload);
            },
            function (NeumannProbe $probe, array $resourceProfile, float $amount): void {
                $this->transferMiningResourcesToProbe($probe, $resourceProfile, $amount);
            },
            function (Manny $manny): void {
                $this->cargo->clearMannyCargo($manny);
            },
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            function (Manny $manny, string $state): void {
                $this->registerMannyInSector($manny, $state);
            },
            fn(NeumannProbe $probe, Manny $manny): bool => $this->storage->placeMannyOnProbe($probe, $manny),
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->detachStorageContainerTaskHandler = new DetachStorageContainerTaskHandler(
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            function (NeumannProbe $probe, Manny $manny): void {
                $this->refreshOtherMannyStates($probe, $manny);
            },
            fn(NeumannProbe $probe, string $objectId): ?UniverseObject => $this->findObjectInCurrentSector($probe, $objectId),
            fn(NeumannProbe $probe, string $containerId, int $ownerPlayerId): array => $this->storage->detachAdditionalContainerSnapshot($probe, $containerId, $ownerPlayerId),
            fn(): int => $this->detachStorageContainerSeconds(),
            fn(mixed $target): array => $target instanceof UniverseObject ? $this->bookmarkTargetArray($target) : [],
            fn(int $probeId): ?NeumannProbe => $this->probes->findById($probeId),
            fn(NeumannProbe $probe): bool => $this->probeAcceptsMannyOrders($probe),
            function (NeumannProbe $probe, array $snapshot): void {
                $this->storage->restoreDetachedContainerSnapshot($probe, $snapshot);
            },
            fn(string $objectId, ?string $targetObjectId): array => $this->hiddenDetachedContainerDetectionPayload($objectId, $targetObjectId),
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            fn(mixed $sectorCoordinates): SectorContent => $this->sectors->getOrCreateSector($sectorCoordinates),
            function (SectorContent $sector): void {
                $this->sectors->saveSector($sector);
            },
            fn(SectorDetachedContainer $container): array => $this->detachedContainerPublicArray($container),
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->dropStorageContainerTaskHandler = new DropStorageContainerTaskHandler(
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            function (NeumannProbe $probe, Manny $manny): void {
                $this->refreshOtherMannyStates($probe, $manny);
            },
            fn(NeumannProbe $probe, string $objectId): ?UniverseObject => $this->findObjectInCurrentSector($probe, $objectId),
            fn(NeumannProbe $probe): ?ProbeItem => $this->firstItemOfType($probe, ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT),
            fn(ProbeItem $item): array => $this->crafting->consumedItemPayload($item),
            fn(NeumannProbe $probe, string $containerId, int $ownerPlayerId): array => $this->storage->detachAdditionalContainerSnapshot($probe, $containerId, $ownerPlayerId),
            function (ProbeItem $item): void {
                $this->items->delete($item);
            },
            fn(): int => $this->dropStorageContainerSeconds(),
            fn(mixed $target): array => $target instanceof UniverseObject ? $this->bookmarkTargetArray($target) : [],
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            fn(mixed $sectorCoordinates): SectorContent => $this->sectors->getOrCreateSector($sectorCoordinates),
            function (SectorContent $sector): void {
                $this->sectors->saveSector($sector);
            },
            function (NeumannProbe $probe, SectorContent $sector, string $planetId, int $playerId, string $containerObjectId, array $resources): void {
                $this->missions?->handleReturnToSpaceProgramMaterialDrop($probe, $sector, $planetId, $playerId, $containerObjectId, $resources);
            },
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->inspectSectorObjectTaskHandler = new InspectSectorObjectTaskHandler(
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            function (NeumannProbe $probe, Manny $manny): void {
                $this->refreshOtherMannyStates($probe, $manny);
            },
            fn(Manny $manny, NeumannProbe $probe): bool => $this->canOrderRemoteMannyViaScut($probe, $manny),
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            fn(mixed $sectorCoordinates): SectorContent => $this->sectors->getOrCreateSector($sectorCoordinates),
            fn(SectorContent $sector, string $objectId, int $playerId): ?UniverseObject => $this->findInspectableSectorObject($sector, $objectId, $playerId),
            fn(SectorContent $sector, string $objectId, ?int $discoveringPlayerId): ?array => $this->hiddenDetachedContainerDetection($sector, $objectId, $discoveringPlayerId),
            fn(): int => $this->miningTravelSeconds(),
            fn(UniverseObject $target): array => $this->bookmarkTargetArray($target),
            function (Manny $manny): void {
                $this->storage->releaseMannyFromStorage($manny);
            },
            function (Manny $manny): void {
                $this->removeMannyFromSector($manny);
            },
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            fn(SectorDetachedContainer $container): array => $this->detachedContainerInspectionReport($container),
            fn(NeumannProbe $probe, SectorContent $sector, DormantConstruct $construct): array => $this->dormantConstructInspectionReport($probe, $sector, $construct),
            function (int $probeId, SectorCoordinates $sectorCoordinates, string $objectId, string $objectLabel, string $message, string $objectType, ?string $scheduledAt): void {
                $this->alerts?->createMannyReportAlert($probeId, $sectorCoordinates, $objectId, $objectLabel, $message, $objectType, $scheduledAt);
            },
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            function (Manny $manny, string $state): void {
                $this->registerMannyInSector($manny, $state);
            },
            fn(NeumannProbe $probe, Manny $manny): bool => $this->storage->placeMannyOnProbe($probe, $manny),
            function (Manny $manny, array $payload): void {
                $this->cargo->waitForStorageSpace($manny, $payload);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->probeImprovementTaskHandler = new ProbeImprovementTaskHandler(
            function (): void {
                if ($this->improvements === null) {
                    throw new MannyActionException(500, 'internal_error', 'Probe improvement storage is unavailable.');
                }
            },
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            function (NeumannProbe $probe, Manny $manny): void {
                $this->refreshOtherMannyStates($probe, $manny);
            },
            fn(string $improvement): string => ProbeImprovementCatalog::normalizeId($improvement),
            fn(string $improvement): ?array => ProbeImprovementCatalog::find($improvement, $this->probeImprovementConfig()),
            fn(NeumannProbe $probe, string $improvement): ?\VonNeumannGame\Domain\ProbeImprovement => $this->improvements?->findForProbe($probe->id, $improvement),
            fn(NeumannProbe $probe, array $definition): array => $this->crafting->probeImprovementPlan($probe, $definition),
            function (NeumannProbe $probe, array $plan): void {
                $this->crafting->consumeProbeImprovementPlan($probe, $plan);
            },
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            function (NeumannProbe $probe, string $improvement): void {
                $this->improvements?->markDone($probe->id, $improvement);
            },
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->probeAssemblyTaskHandler = new ProbeAssemblyTaskHandler(
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            function (NeumannProbe $probe, Manny $manny): void {
                $this->refreshOtherMannyStates($probe, $manny);
            },
            fn(NeumannProbe $probe): array => $this->crafting->probeAssemblyPlan($probe),
            fn(NeumannProbe $probe, array $containerIds): array => $this->storage->consumeEmptyAdditionalContainers($probe, $containerIds),
            function (NeumannProbe $probe, array $plan): void {
                $this->crafting->consumeProbeAssemblyPlan($probe, $plan);
            },
            fn(): int => self::PROBE_ASSEMBLY_SECONDS,
            fn(): array => $this->crafting->probeAssemblyComponentRequirements(),
            function (Manny $manny): void {
                $this->storage->releaseMannyFromStorage($manny);
            },
            function (Manny $manny): void {
                $this->removeMannyFromSector($manny);
            },
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            fn(int $playerId): string => $this->nextDroneProbeName($playerId),
            fn(int $playerId, string $name, SectorCoordinates $sector): NeumannProbe => $this->probes->createForPlayer($playerId, $name, $sector),
            function (NeumannProbe $probe): void {
                $this->storage->ensureProbeStorage($probe);
            },
            fn(NeumannProbe $probe, Manny $manny): bool => $this->storage->placeMannyOnProbe($probe, $manny),
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->deuteriumTankRefillTaskHandler = new DeuteriumTankRefillTaskHandler(
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            function (NeumannProbe $probe): void {
                $this->missions?->completeReadyReturnToSpacePrograms($probe);
            },
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            fn(NeumannProbe $probe): bool => $this->currentSectorHasDeuteriumRefuelStation($probe),
            fn(?NeumannProbe $probe): float => $this->maxDeuteriumPercent($probe),
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            function (NeumannProbe $probe): void {
                $this->probes->save($probe);
            },
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->deuteriumTransferTaskHandler = new DeuteriumTransferTaskHandler(
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            fn(int $probeId): ?NeumannProbe => $this->probes->findById($probeId),
            function (NeumannProbe $probe): void {
                $this->probes->save($probe);
            },
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            fn(?NeumannProbe $probe): float => $this->maxDeuteriumPercent($probe),
            fn(NeumannProbe $probe): bool => $this->probeAcceptsMannyOrders($probe),
        );
        $this->returningTaskHandler = new ReturningTaskHandler(
            fn(NeumannProbe $probe, Manny $manny, array $payload): bool => $this->cargo->canAcceptMannyDocking($probe, $manny, $payload),
            function (Manny $manny, array $payload): void {
                $this->cargo->waitForStorageSpace($manny, $payload);
            },
            function (Manny $manny, NeumannProbe $probe): void {
                $this->cargo->transferMannyCargoToProbe($manny, $probe);
            },
            function (NeumannProbe $probe, Manny $manny, array $payload): void {
                $this->cargo->deliverReservedSalvageItems($probe, $manny, $payload);
            },
            function (NeumannProbe $probe, array $payload): void {
                $this->cargo->deliverReservedDetachedContainer($probe, $payload);
            },
            fn(Manny $manny): bool => $this->cargo->mannyCargoIsEmpty($manny),
            fn(Manny $manny): bool => $this->cargo->hasReservedDeliveryPayload($manny),
            fn(NeumannProbe $probe, Manny $manny): bool => $this->storage->placeMannyOnProbe($probe, $manny),
            function (Manny $manny): void {
                $this->removeMannyFromSector($manny);
            },
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->salvageTaskHandler = new SalvageTaskHandler(
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            function (NeumannProbe $probe, Manny $manny): void {
                $this->refreshOtherMannyStates($probe, $manny);
            },
            fn(NeumannProbe $probe, string $objectId): UniverseObject|ScutRelay|null => $this->findObjectInCurrentSector($probe, $objectId) ?? $this->findScutRelayInCurrentSector($probe, $objectId),
            fn(UniverseObject|ScutRelay $target): bool => $this->cargo->isSalvageableTarget($target),
            function (NeumannProbe $probe, ScutRelay $target, int $actorMannyId): void {
                $this->ensureScutRelayNotAlreadyBeingSalvaged($probe, $target, $actorMannyId);
            },
            fn(NeumannProbe $probe, SectorDriftingItem $target): array => $this->cargo->reserveDriftingItemForSalvage($probe, $target),
            fn(NeumannProbe $probe, SectorDetachedContainer $target): array => $this->cargo->reserveDetachedContainerForSalvage($probe, $target),
            fn(UniverseObject|ScutRelay $target): array => $this->cargo->salvageTargetArray($target),
            fn(): int => $this->salvageSeconds(),
            function (Manny $manny): void {
                $this->storage->releaseMannyFromStorage($manny);
            },
            function (Manny $manny): void {
                $this->removeMannyFromSector($manny);
            },
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            fn(mixed $sectorCoordinates): SectorContent => $this->sectors->getOrCreateSector($sectorCoordinates),
            fn(Manny $manny): ?array => $this->cargo->reservedSalvageItemPayload($manny),
            fn(Manny $manny): ?array => $this->cargo->reservedDetachedContainerPayload($manny),
            fn(SectorContent $sector, string $objectId): ?ScutRelay => $this->findScutRelayInSector($sector, $objectId),
            fn(NeumannProbe $probe, SectorContent $sector, UniverseObject|ScutRelay $target): array => $this->cargo->completeSalvageTarget($probe, $sector, $target),
            function (Manny $manny, NeumannProbe $probe, array $resultPayload): void {
                $this->cargo->finishSalvageActor($manny, $probe, $resultPayload);
            },
            function (NeumannProbe $probe): void {
                $this->probes->save($probe);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->scutRelayTurnOnTaskHandler = new ScutRelayTurnOnTaskHandler(
            function (): void {
                if ($this->scut === null) {
                    throw new MannyActionException(500, 'internal_error', 'SCUT relay service is unavailable.');
                }
            },
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            function (NeumannProbe $probe, Manny $manny): void {
                $this->refreshOtherMannyStates($probe, $manny);
            },
            fn(NeumannProbe $probe): bool => $this->currentSectorHasStar($probe),
            fn(int $relayId): ?ScutRelay => $this->scut?->relayById($relayId),
            fn(NeumannProbe $probe, string $type): ?ProbeItem => $this->firstItemOfType($probe, $type),
            fn(ProbeItem $item): array => $this->crafting->consumedItemPayload($item),
            function (ProbeItem $item): void {
                $this->items->delete($item);
            },
            fn(): int => $this->scutRelayTurnOnSeconds(),
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            function (int $relayId, ?string $networkName): ScutRelay {
                if ($this->scut === null) {
                    throw new MannyActionException(500, 'internal_error', 'SCUT relay service is unavailable.');
                }

                return $this->scut->turnOnRelay($relayId, $networkName);
            },
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->storageMoveTaskHandler = new StorageMoveTaskHandler(
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            fn(): int => $this->storageMoveSecondsPerUnit(),
            function (NeumannProbe $probe, string $resourceType, float $amount, string $fromContainerId, string $toContainerId): void {
                $this->storage->assertCanMoveResource($probe, $resourceType, $amount, $fromContainerId, $toContainerId);
            },
            function (NeumannProbe $probe, array $itemIds, string $toContainerId): void {
                $this->storage->assertCanMoveItems($probe, $itemIds, $toContainerId);
            },
            function (NeumannProbe $probe, array $mannyIds, string $toContainerId): void {
                $this->storage->assertCanMoveMannies($probe, $mannyIds, $toContainerId);
            },
            fn(string $kind, float $amount): int => $this->storage->storageMoveDurationSeconds($kind, $amount),
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            function (NeumannProbe $probe, string $resourceType, float $amount, string $fromContainerId, string $toContainerId, int $mannyId): void {
                $this->storage->moveResource($probe, $resourceType, $amount, $fromContainerId, $toContainerId, $mannyId);
            },
            function (NeumannProbe $probe, array $itemIds, string $toContainerId, int $mannyId): void {
                $this->storage->moveItems($probe, $itemIds, $toContainerId, $mannyId);
            },
            function (NeumannProbe $probe, string $itemId, string $toContainerId, int $mannyId): void {
                $this->storage->moveItem($probe, $itemId, $toContainerId, $mannyId);
            },
            function (NeumannProbe $probe, array $mannyIds, string $toContainerId, int $mannyId): void {
                $this->storage->moveStoredMannies($probe, $mannyIds, $toContainerId, $mannyId);
            },
            function (NeumannProbe $probe, string $targetMannyId, string $toContainerId, int $mannyId): void {
                $this->storage->moveStoredManny($probe, $targetMannyId, $toContainerId, $mannyId);
            },
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->waypointBookmarkInstallationTaskHandler = new WaypointBookmarkInstallationTaskHandler(
            function (NeumannProbe $probe): void {
                $this->ensureProbeAcceptsMannyOrders($probe);
            },
            fn(Manny $manny, NeumannProbe $probe): Manny => $this->refreshMannyState($manny, $probe),
            fn(NeumannProbe $probe, string $uid): Manny => $this->requiredManny($probe, $uid),
            function (Manny $manny, NeumannProbe $probe): void {
                $this->ensureMannyInRange($manny, $probe);
            },
            function (Manny $manny): void {
                $this->ensureMannyIdle($manny);
            },
            function (NeumannProbe $probe, Manny $manny): void {
                $this->refreshOtherMannyStates($probe, $manny);
            },
            fn(NeumannProbe $probe): ?ProbeItem => $this->firstWaypointBookmarkItem($probe),
            fn(NeumannProbe $probe, string $objectId): UniverseObject => $this->bookmarks->deployableTarget($probe, $objectId),
            fn(UniverseObject $object): array => $this->bookmarkTargetArray($object),
            function (ProbeItem $item): void {
                $this->items->delete($item);
            },
            fn(): int => $this->waypointBookmarkInstallSeconds(),
            fn(ProbeItem $item): array => $this->crafting->consumedItemPayload($item),
            function (Manny $manny): void {
                $this->mannies->save($manny);
            },
            fn(mixed $value): ?SectorCoordinates => $this->taskSectorCoordinates($value),
            fn(NeumannProbe $probe, int $playerId, string $playerName, string $objectId, string $name, ?SectorCoordinates $sectorCoordinates): UniverseObject => $this->bookmarks->deployForPlayer($probe, $playerId, $playerName, $objectId, $name, $sectorCoordinates),
            function (Manny $manny, array $payload): void {
                $this->clearTask($manny, $payload);
            },
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
        );
        $this->taskRefresher = new MannyTaskRefresher(
            $taskHandlers ?? TaskHandlerRegistry::defaultHandlers(
                $this->repairTaskHandler,
                $this->miningTaskHandler,
                $this->detachStorageContainerTaskHandler,
                $this->dropStorageContainerTaskHandler,
                $this->inspectSectorObjectTaskHandler,
                $this->probeImprovementTaskHandler,
                $this->probeAssemblyTaskHandler,
                $this->deuteriumTankRefillTaskHandler,
                $this->deuteriumTransferTaskHandler,
                $this->returningTaskHandler,
                $this->salvageTaskHandler,
                $this->scutRelayTurnOnTaskHandler,
                $this->storageMoveTaskHandler,
                $this->waypointBookmarkInstallationTaskHandler,
            ),
            $this,
            fn(NeumannProbe $probe, callable $callback): mixed => $this->withProbeLock($probe, $callback),
            fn(int $mannyId): ?Manny => $this->mannies->findById($mannyId),
            fn(NeumannProbe $probe, Manny $manny): bool => $this->canRefreshMannyTaskFromProbe($probe, $manny),
        );
    }

    /**
     * @template T
     * @param callable(NeumannProbe): T $callback
     * @return T
     */
    private function withProbeLock(NeumannProbe $probe, callable $callback): mixed
    {
        return $this->probes->withProbeLock($probe->id, function () use ($probe, $callback): mixed {
            return $callback($this->probes->findById($probe->id) ?? $probe);
        });
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
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startRepairLocked($lockedProbe, $uid, $integrityPercent),
        );
    }

    private function startRepairLocked(NeumannProbe $probe, string $uid, float $integrityPercent): Manny
    {
        return $this->repairTaskHandler->start($probe, $uid, $integrityPercent);
    }

    public function startMining(NeumannProbe $probe, string $uid, string $objectId, string|array $resourceTypes, float $targetAmount, ?string $targetContainerId = null): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startMiningLocked($lockedProbe, $uid, $objectId, $resourceTypes, $targetAmount, $targetContainerId),
        );
    }

    private function startMiningLocked(NeumannProbe $probe, string $uid, string $objectId, string|array $resourceTypes, float $targetAmount, ?string $targetContainerId = null): Manny
    {
        return $this->miningTaskHandler->start($probe, $uid, $objectId, $resourceTypes, $targetAmount, $targetContainerId);
    }

    public function startCrafting(NeumannProbe $probe, string $uid, string $recipe): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startCraftingLocked($lockedProbe, $uid, $recipe),
        );
    }

    private function startCraftingLocked(NeumannProbe $probe, string $uid, string $recipe): Manny
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
        if ($recipeDefinition === null || !$this->crafting->recipeCraftableBy($recipeDefinition, CraftingRecipeCatalog::FABRICATOR_MANNY)) {
            throw new MannyActionException(400, 'invalid_recipe', 'Unknown crafting recipe.');
        }
        $craftingPlan = $this->crafting->craftingPlan($probe, $recipeDefinition);
        $freeAfterConsumption = round(
            $this->freeCargoCapacity($probe)
            + $this->crafting->cargoSpaceFreedByResourceCosts($craftingPlan['resourceCosts'])
            + $this->crafting->cargoSpaceFreedByConsumedItems($craftingPlan['consumedItems']),
            4,
        );
        if ($freeAfterConsumption + 0.00001 < (float) ($craftingPlan['output']['containerSpace'] ?? 0.0)) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for the crafted item.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->crafting->consumeCraftingPlan($probe, $craftingPlan);

        $manny->currentTask = Manny::TASK_CRAFTING;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . (int) $craftingPlan['durationSeconds'] . ' seconds')->format('c');
        $manny->taskPayload = [
            'craftingRunId' => $this->crafting->newCraftingRunId(),
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
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startAtomicPrinterCraftingLocked($lockedProbe, $recipe),
        );
    }

    private function startAtomicPrinterCraftingLocked(NeumannProbe $probe, string $recipe): Manny
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
        if ($recipeDefinition === null || !$this->crafting->recipeCraftableBy($recipeDefinition, CraftingRecipeCatalog::FABRICATOR_ATOMIC_PRINTER)) {
            throw new MannyActionException(400, 'invalid_recipe', 'Unknown atomic-printer recipe.');
        }

        $craftingPlan = $this->crafting->craftingPlan($probe, $recipeDefinition);
        $freeAfterConsumption = round(
            $this->freeCargoCapacity($probe)
            + $this->crafting->cargoSpaceFreedByResourceCosts($craftingPlan['resourceCosts'])
            + $this->crafting->cargoSpaceFreedByConsumedItems($craftingPlan['consumedItems']),
            4,
        );
        if ($freeAfterConsumption + 0.00001 < (float) ($craftingPlan['output']['containerSpace'] ?? 0.0)) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for the crafted item.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->crafting->consumeCraftingPlan($probe, $craftingPlan);

        $manny->currentTask = Manny::TASK_ASSISTING_ATOMIC_PRINTER;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . (int) $craftingPlan['durationSeconds'] . ' seconds')->format('c');
        $manny->taskPayload = [
            'craftingRunId' => $this->crafting->newCraftingRunId(),
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
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startProbeImprovementLocked($lockedProbe, $uid, $improvement),
        );
    }

    private function startProbeImprovementLocked(NeumannProbe $probe, string $uid, string $improvement): Manny
    {
        return $this->probeImprovementTaskHandler->start($probe, $uid, $improvement);
    }

    /**
     * @param list<string> $containerIds
     */
    public function startProbeAssembly(NeumannProbe $probe, string $uid, array $containerIds): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startProbeAssemblyLocked($lockedProbe, $uid, $containerIds),
        );
    }

    /**
     * @param list<string> $containerIds
     */
    private function startProbeAssemblyLocked(NeumannProbe $probe, string $uid, array $containerIds): Manny
    {
        return $this->probeAssemblyTaskHandler->start($probe, $uid, $containerIds);
    }

    public function startSalvage(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startSalvageLocked($lockedProbe, $uid, $objectId),
        );
    }

    private function startSalvageLocked(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        return $this->salvageTaskHandler->start($probe, $uid, $objectId);
    }

    public function startDetachStorageContainer(NeumannProbe $probe, int $ownerPlayerId, string $uid, string $containerId, string $mode, ?string $objectId = null): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startDetachStorageContainerLocked($lockedProbe, $ownerPlayerId, $uid, $containerId, $mode, $objectId),
        );
    }

    private function startDetachStorageContainerLocked(NeumannProbe $probe, int $ownerPlayerId, string $uid, string $containerId, string $mode, ?string $objectId = null): Manny
    {
        return $this->detachStorageContainerTaskHandler->start($probe, $ownerPlayerId, $uid, $containerId, $mode, $objectId);
    }

    public function startDropStorageContainerOnPlanet(NeumannProbe $probe, int $ownerPlayerId, string $uid, string $containerId, string $planetId): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startDropStorageContainerOnPlanetLocked($lockedProbe, $ownerPlayerId, $uid, $containerId, $planetId),
        );
    }

    private function startDropStorageContainerOnPlanetLocked(NeumannProbe $probe, int $ownerPlayerId, string $uid, string $containerId, string $planetId): Manny
    {
        return $this->dropStorageContainerTaskHandler->start($probe, $ownerPlayerId, $uid, $containerId, $planetId);
    }

    public function startInspectSectorObject(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startInspectSectorObjectLocked($lockedProbe, $uid, $objectId),
        );
    }

    private function startInspectSectorObjectLocked(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        return $this->inspectSectorObjectTaskHandler->start($probe, $uid, $objectId);
    }

    public function startRecoverDetachedContainer(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startRecoverDetachedContainerLocked($lockedProbe, $uid, $objectId),
        );
    }

    private function startRecoverDetachedContainerLocked(NeumannProbe $probe, string $uid, string $objectId): Manny
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

        $reservedDetachedContainer = $this->cargo->reserveDetachedContainerForSalvage($probe, $target);
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
            'target' => $this->cargo->salvageTargetArray($target),
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
            $droppedCargo = $this->cargo->dropWaitingMannyCargo($manny);
            $this->cargo->clearMannyCargo($manny);
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
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startWaypointBookmarkInstallationLocked($lockedProbe, $player, $uid, $objectId, $name),
        );
    }

    private function startWaypointBookmarkInstallationLocked(NeumannProbe $probe, Player $player, string $uid, string $objectId, string $name): Manny
    {
        return $this->waypointBookmarkInstallationTaskHandler->start($probe, $player, $uid, $objectId, $name);
    }

    public function startDeuteriumTankRefill(NeumannProbe $probe, string $uid): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startDeuteriumTankRefillLocked($lockedProbe, $uid),
        );
    }

    private function startDeuteriumTankRefillLocked(NeumannProbe $probe, string $uid): Manny
    {
        return $this->deuteriumTankRefillTaskHandler->start($probe, $uid);
    }

    public function startDeuteriumTransferToProbe(NeumannProbe $probe, string $uid, int $targetProbeId, float $amount): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startDeuteriumTransferToProbeLocked($lockedProbe, $uid, $targetProbeId, $amount),
        );
    }

    private function startDeuteriumTransferToProbeLocked(NeumannProbe $probe, string $uid, int $targetProbeId, float $amount): Manny
    {
        return $this->deuteriumTransferTaskHandler->start($probe, $uid, $targetProbeId, $amount);
    }

    public function startScutRelayTurnOn(NeumannProbe $probe, string $uid, int $relayId, ?string $networkName = null): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startScutRelayTurnOnLocked($lockedProbe, $uid, $relayId, $networkName),
        );
    }

    private function startScutRelayTurnOnLocked(NeumannProbe $probe, string $uid, int $relayId, ?string $networkName = null): Manny
    {
        return $this->scutRelayTurnOnTaskHandler->start($probe, $uid, $relayId, $networkName);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function startStorageMove(NeumannProbe $probe, string $uid, array $payload): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->startStorageMoveLocked($lockedProbe, $uid, $payload),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function startStorageMoveLocked(NeumannProbe $probe, string $uid, array $payload): Manny
    {
        return $this->storageMoveTaskHandler->start($probe, $uid, $payload);
    }

    public function recallManny(NeumannProbe $probe, string $uid): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->recallMannyLocked($lockedProbe, $uid),
        );
    }

    private function recallMannyLocked(NeumannProbe $probe, string $uid): Manny
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
            $this->crafting->refundCraftingCommitment($probe, $manny);
            $this->clearTask($manny);
            $this->mannies->save($manny);

            return $this->requiredManny($probe, $uid);
        }
        if ($manny->currentTask === Manny::TASK_IMPROVING_PROBE) {
            $this->crafting->refundProbeImprovementCommitment($probe, $manny);
            $this->clearTask($manny);
            $this->mannies->save($manny);

            return $this->requiredManny($probe, $uid);
        }
        if ($manny->currentTask === Manny::TASK_TURNING_ON_SCUT_RELAY) {
            $consumedItem = is_array($manny->taskPayload['consumedItem'] ?? null) ? $manny->taskPayload['consumedItem'] : null;
            if ($consumedItem !== null) {
                $this->crafting->restoreConsumedItem($probe, $consumedItem);
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
        if ($manny->currentTask === Manny::TASK_WAITING_FOR_SPACE && $this->cargo->hasReservedDeliveryPayload($manny)) {
            return $manny;
        }
        if ($manny->currentTask === Manny::TASK_SALVAGE) {
            $this->cargo->restoreReservedSalvageItem($manny);
            $this->cargo->restoreReservedDetachedContainer($manny);
        }
        $droppedAssemblyIngredients = [];
        if ($manny->currentTask === Manny::TASK_ASSEMBLING_PROBE) {
            $droppedAssemblyIngredients = $this->cargo->restoreProbeAssemblyIngredientsAsDrifting($manny);
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
        if ($droppedAssemblyIngredients !== []) {
            $manny->taskPayload['droppedIngredients'] = $droppedAssemblyIngredients;
        }
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
            $this->cargo->restoreReservedSalvageItem($manny);
            $this->cargo->restoreReservedDetachedContainer($manny);
        }
        $droppedAssemblyIngredients = [];
        if ($manny->currentTask === Manny::TASK_ASSEMBLING_PROBE) {
            $droppedAssemblyIngredients = $this->cargo->restoreProbeAssemblyIngredientsAsDrifting($manny);
        }

        $payload = [
            'lastTask' => $lastTask,
            'result' => 'forgotten',
            'reason' => 'remote_scut_recall',
        ];
        if ($droppedAssemblyIngredients !== []) {
            $payload['droppedIngredients'] = $droppedAssemblyIngredients;
        }

        $this->clearTask($manny, $payload);
        $manny->locationType = Manny::LOCATION_SECTOR;
        $this->registerMannyInSector($manny, SectorManny::STATE_FORGOTTEN);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    public function dropMannyCargo(NeumannProbe $probe, string $uid): Manny
    {
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->dropMannyCargoLocked($lockedProbe, $uid),
        );
    }

    private function dropMannyCargoLocked(NeumannProbe $probe, string $uid): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->requiredManny($probe, $uid);
        $this->ensureMannyInRange($manny, $probe);
        if ($manny->currentTask !== Manny::TASK_WAITING_FOR_SPACE) {
            throw new MannyActionException(409, 'manny_not_waiting_for_space', 'The Manny is not waiting for storage space.');
        }

        $droppedCargo = $this->cargo->dropWaitingMannyCargo($manny);
        $resultPayload = [
            'lastTask' => 'drop_manny_cargo',
            'result' => 'success',
            'droppedCargo' => $droppedCargo,
        ];
        $this->cargo->clearMannyCargo($manny);
        $manny->taskPayload = $resultPayload;

        if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
            $this->cargo->waitForStorageSpace($manny, ['reason' => 'return_to_probe'] + $resultPayload);
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
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): Manny => $this->jettisonMannyFromProbeLocked($lockedProbe, $uid),
        );
    }

    private function jettisonMannyFromProbeLocked(NeumannProbe $probe, string $uid): Manny
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
        return $this->withProbeLock(
            $probe,
            fn(NeumannProbe $lockedProbe): array => $this->jettisonProbeItemFromProbeLocked($lockedProbe, $uid),
        );
    }

    private function jettisonProbeItemFromProbeLocked(NeumannProbe $probe, string $uid): array
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
        return $this->taskRefresher->refresh($manny, $probe, true);
    }

    public function refreshScheduledMannyTask(ScheduledEvent $event): ?Manny
    {
        if ($event->entityType !== 'manny') {
            throw new \RuntimeException('Invalid entity type for Manny task event: ' . $event->entityType);
        }

        $manny = $this->mannies->findById($event->entityId);
        if ($manny === null || $manny->probeId === null) {
            return $manny;
        }
        if ($manny->taskScheduledEventId !== $event->id || $manny->currentTask === null) {
            return $manny;
        }

        $probe = $this->probes->findById($manny->probeId);
        if ($probe === null) {
            return $manny;
        }

        return $this->taskRefresher->refreshAllowingOutOfRange($manny, $probe);
    }

    private function canRefreshMannyTaskFromProbe(NeumannProbe $probe, Manny $manny): bool
    {
        if ($this->taskRefresher->allowsOutOfRangeTasks()) {
            return true;
        }

        return $manny->isInSameSectorAs($probe)
            || $manny->currentTask === Manny::TASK_ASSEMBLING_PROBE
            || $this->canRefreshRemoteMiningViaScut($probe, $manny)
            || $this->canRefreshRemoteInspectViaScut($probe, $manny);
    }

    public function publicArray(NeumannProbe $probe, Manny $manny, ?array $relativeSector = null): array
    {
        return $this->presenter->present($probe, $manny, $relativeSector);
    }

    private function requiredManny(NeumannProbe $probe, string $uid): Manny
    {
        return $this->mannies->findByUidForProbe($probe->id, $uid)
            ?? throw new MannyActionException(404, 'manny_not_found', 'Manny not found.');
    }

    public function refreshCrafting(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        try {
            $this->crafting->createCraftingOutput($probe, $manny, $now);
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

    public function refreshWaitingForSpace(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->cargo->canAcceptMannyDocking($probe, $manny, $manny->taskPayload)) {
            return $manny;
        }

        $this->cargo->transferMannyCargoToProbe($manny, $probe);
        $this->cargo->deliverReservedSalvageItems($probe, $manny, $manny->taskPayload);
        $this->cargo->deliverReservedDetachedContainer($probe, $manny->taskPayload);
        if ($this->cargo->mannyCargoIsEmpty($manny)) {
            $finalPayload = $this->cargo->hasReservedDeliveryPayload($manny) ? $manny->taskPayload : [];
            if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
                $this->cargo->waitForStorageSpace($manny, ['reason' => 'return_to_probe']);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }
            $this->removeMannyFromSector($manny);
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
            $this->clearTask($manny, $finalPayload);
        } else {
            $this->cargo->waitForStorageSpace($manny, ['reason' => 'cargo_delivery']);
        }

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

    private function probeAcceptsMannyOrders(NeumannProbe $probe): bool
    {
        return !in_array($probe->status, [ProbeStatus::Dead, ProbeStatus::TrappedByBlackHole], true);
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

    /**
     * @param array<string, float> $profile
     */
    private function canAcceptMiningDelivery(NeumannProbe $probe, array $profile, float $amount, bool $includeManny): bool
    {
        $units = $includeManny ? [['type' => 'manny', 'space' => $this->mannyContainerSpace()]] : [];

        return $this->storage->canStoreIncoming($probe, $this->resourceAmountsForTotal($amount, $profile), $units);
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
        $this->cargo->clearMannyCargo($manny);
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
            ProbeItem::TYPE_ATOMIC_PRINTER_PART,
            ProbeItem::TYPE_DEUTERIUM_ENGINE,
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
            ProbeItem::TYPE_ATOMIC_PRINTER_PART => ProbeItem::ATOMIC_PRINTER_PART_NAME,
            ProbeItem::TYPE_DEUTERIUM_ENGINE => ProbeItem::DEUTERIUM_ENGINE_NAME,
            ProbeItem::TYPE_SOLAR_PANEL => ProbeItem::SOLAR_PANEL_NAME,
            ProbeItem::TYPE_SCUT_RELAY => ProbeItem::SCUT_RELAY_NAME,
            ProbeItem::TYPE_THERMAL_PROTECTION_SHELL => ProbeItem::THERMAL_PROTECTION_SHELL_NAME,
            ProbeItem::TYPE_PARACHUTE_PACK => ProbeItem::PARACHUTE_PACK_NAME,
            ProbeItem::TYPE_DESCENT_GUIDANCE_MODULE => ProbeItem::DESCENT_GUIDANCE_MODULE_NAME,
            ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT => ProbeItem::ATMOSPHERIC_DROP_KIT_NAME,
            default => $fallback !== null && trim($fallback) !== '' ? $fallback : $type,
        };
    }

    private function nextDroneProbeName(int $playerId): string
    {
        $existing = array_map(
            static fn(NeumannProbe $probe): string => strtolower($probe->name),
            $this->probes->findAllByPlayerId($playerId),
        );
        for ($index = 1; $index < 100000; $index++) {
            $candidate = 'drone-' . $index;
            if (!in_array($candidate, $existing, true)) {
                return $candidate;
            }
        }

        return 'drone-' . bin2hex(random_bytes(4));
    }

    private function craftingConfig(): array
    {
        return Config::getArray($this->config, 'crafting');
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
