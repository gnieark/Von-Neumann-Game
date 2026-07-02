<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeImprovementCatalog;
use VonNeumannGame\Domain\ProbeDirection;
use VonNeumannGame\Domain\ProbeMovement;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\ProbeImprovementRepository;
use VonNeumannGame\Repository\ProbeMovementRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Sector\BlackHole;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\SolarSystem;
use VonNeumannGame\Sector\UniverseObject;

final class ProbeMovementService
{
    public const BLACK_HOLE_TRAP_MIN_DELAY_SECONDS = 5400;
    public const BLACK_HOLE_TRAP_MAX_DELAY_SECONDS = 10800;

    private readonly SectorGrid $grid;
    private readonly array $gameplayConfig;
    private readonly array $movementConfig;

    public function __construct(
        private readonly NeumannProbeRepository $probes,
        private readonly ProbeMovementRepository $movements,
        private readonly VisitedSectorRepository $visitedSectors,
        private readonly ?ScheduledEventRepository $scheduledEvents = null,
        private readonly ?SectorService $sectors = null,
        private readonly ?MannyRepository $mannies = null,
        private readonly ?ProbeStorageService $storage = null,
        private readonly ?ProbeDamageWarningRepository $damageWarnings = null,
        private readonly ?MissionService $missions = null,
        private readonly ?ProbeImprovementRepository $improvements = null,
        private readonly MovementDurationCalculator $durations = new MovementDurationCalculator(),
        private readonly DeterministicRiskRoll $riskRoll = new DeterministicRiskRoll(),
        private readonly string $worldSeed = 'default-world',
        ?SectorGrid $grid = null,
        array $gameplayConfig = [],
    ) {
        $this->grid = $grid ?? new SectorGrid();
        $this->gameplayConfig = $gameplayConfig;
        $this->movementConfig = Config::getArray($gameplayConfig, 'movement', $gameplayConfig);
    }

    public function startMovement(NeumannProbe $probe, SectorCoordinates $target, ?Player $player = null): ProbeMovement
    {
        $probe = $this->refreshProbeMovementState($probe);
        $this->ensureProbeOperational($probe);

        if ($this->movements->findActiveByProbeId($probe->id) !== null) {
            throw new ProbeMovementException(409, 'probe_already_moving', 'The probe is already moving between sectors.');
        }

        if ($probe->currentSector->equals($target)) {
            throw new ProbeMovementException(400, 'same_destination', 'Destination is identical to the current sector.');
        }

        $distance = $this->grid->getDistance($probe->currentSector, $target);
        if ($distance <= 0) {
            throw new ProbeMovementException(400, 'same_destination', 'Destination is identical to the current sector.');
        }

        if ($probe->deuteriumStock <= 0.0001) {
            throw new ProbeMovementException(422, 'insufficient_fuel', 'Insufficient deuterium for movement.');
        }

        $fuelCost = round($probe->deuteriumStock * $this->float('fuelCostRatioOfCurrentDeuterium', 0.02), 4);
        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $movement = $this->movements->create(
            $probe->id,
            $probe->currentSector,
            $target,
            $distance,
            $this->durations->timeline($startedAt, $distance),
            $fuelCost,
        );
        $this->registerForgottenMannies($probe);

        $probe->deuteriumStock = round($probe->deuteriumStock - $fuelCost, 4);
        $probe->status = ProbeStatus::Preparing;
        $probe->velocityC = 0.0;
        $probe->accelerationCPerDay = 0.0;
        $probe->direction = $this->directionBetween($movement->origin, $movement->target);
        $probe->currentTask = 'intersector_movement';
        $this->probes->save($probe);
        $this->cancelBlackHoleTrap($probe);
        $this->scheduleMovementEvents($movement);
        $this->scheduleFragileContainerLossIfNeeded($probe, $movement, $player);

        return $movement;
    }

    public function refreshProbeMovementState(NeumannProbe $probe): NeumannProbe
    {
        $movement = $this->movements->findActiveByProbeId($probe->id);
        if ($movement === null) {
            return $probe;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($this->isAtOrAfter($now, $movement->arrivalAt)) {
            $movement->status = 'arrived';
            $this->movements->save($movement);

            $probe->currentSector = $movement->target;
            $probe->status = ProbeStatus::Idle;
            $probe->velocityC = 0.0;
            $probe->accelerationCPerDay = 0.0;
            $probe->direction = new ProbeDirection(0.0, 0.0, 0.0);
            $probe->currentTask = null;
            $probe->enteredCurrentSectorAt = $now->format('c');
            $this->applyIntersectorIntegrityLoss($probe, $movement);
            $this->probes->save($probe);
            $alreadyVisited = $this->visitedSectors->getVisitedSectorByPlayerId($probe->playerId, $movement->target) !== null;
            $this->visitedSectors->markVisitedByPlayerId($probe->playerId, $movement->target);
            $this->createIntelligentLifeAlerts($probe, $movement);
            $this->createDormantConstructAlerts($probe, $movement);
            if (!$alreadyVisited) {
                $this->startIntelligentLifeScenarios($probe, $movement);
            }
            $this->scheduleBlackHoleTrapIfNeeded($probe);

            return $this->probes->findById($probe->id) ?? $probe;
        }

        $phase = $this->phaseAt($movement, $now);
        $this->checkDestructionAtCruiseStart($probe, $movement, $phase, $now);
        if ($movement->status === 'destroyed') {
            return $this->probes->findById($probe->id) ?? $probe;
        }

        $movement->status = $phase;
        $this->movements->save($movement);

        $probe->status = ProbeStatus::from($phase);
        $probe->velocityC = $this->estimatedVelocityC($movement, $now);
        $accelerationCPerDay = $this->float('accelerationCPerDay', 0.36);
        $probe->accelerationCPerDay = $phase === 'accelerating' ? $accelerationCPerDay : ($phase === 'decelerating' ? -$accelerationCPerDay : 0.0);
        $probe->direction = $this->directionBetween($movement->origin, $movement->target);
        $this->probes->save($probe);

        return $this->probes->findById($probe->id) ?? $probe;
    }

    public function activeMovementForProbe(NeumannProbe $probe): ?ProbeMovement
    {
        return $this->movements->findActiveByProbeId($probe->id);
    }

    public function refreshCurrentSectorHazards(NeumannProbe $probe): void
    {
        $this->scheduleBlackHoleTrapIfNeeded($probe);
    }

    public function ensureCurrentSectorIntelligentLifeScenarios(NeumannProbe $probe): void
    {
        if ($this->sectors === null || $this->missions === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($probe->currentSector);
        foreach ($this->intelligentLifePlanets($sector->getObjects()) as $planet) {
            $this->missions->startIntelligentLifeScenario($probe, $probe->currentSector, $planet, null);
        }
    }

    public function latestMovementForProbe(NeumannProbe $probe): ?ProbeMovement
    {
        return $this->movements->findLatestByProbeId($probe->id);
    }

    public function pendingBlackHoleTrapForProbe(NeumannProbe $probe): ?array
    {
        $event = $this->scheduledEvents?->findPendingByTypeAndEntity(SchedulerService::PROBE_BLACK_HOLE_TRAP, 'probe', $probe->id);
        if ($event === null) {
            return null;
        }

        $trapAt = new \DateTimeImmutable($event->runAt);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'trapAt' => $event->runAt,
            'secondsRemaining' => max(0, $trapAt->getTimestamp() - $now->getTimestamp()),
            'delaySeconds' => (int) ($event->payload['delaySeconds'] ?? 0),
        ];
    }

    public function phaseFor(?ProbeMovement $movement): string
    {
        if ($movement === null) {
            return 'idle';
        }
        if (in_array($movement->status, ['arrived', 'failed', 'destroyed'], true)) {
            return $movement->status;
        }

        return $this->phaseAt($movement, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    public function sensorModeFor(?ProbeMovement $movement, ProbeStatus $probeStatus): string
    {
        if ($probeStatus === ProbeStatus::Dead) {
            return 'blind';
        }

        return match ($this->phaseFor($movement)) {
            'accelerating', 'decelerating' => 'degraded',
            'cruising', 'destroyed' => 'blind',
            default => 'normal',
        };
    }

    public function observableSectorFor(NeumannProbe $probe, ?ProbeMovement $movement): ?SectorCoordinates
    {
        if ($probe->status === ProbeStatus::Dead) {
            return null;
        }

        return match ($this->phaseFor($movement)) {
            'preparing', 'accelerating' => $movement?->origin,
            'cruising', 'destroyed' => null,
            'decelerating' => $movement?->target,
            default => $probe->currentSector,
        };
    }

    public function estimatedVelocityC(?ProbeMovement $movement, ?\DateTimeImmutable $now = null): float
    {
        if ($movement === null || in_array($movement->status, ['arrived', 'failed', 'destroyed'], true)) {
            return 0.0;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $phase = $this->phaseAt($movement, $now);
        $max = min(
            $this->float('velocityMaxC', 0.95),
            $this->float('velocityBaseC', 0.42) + ($movement->distance * $this->float('velocityPerDistanceC', 0.1)),
        );

        if ($phase === 'preparing') {
            return 0.0;
        }
        if ($phase === 'cruising') {
            return round($max, 2);
        }
        if ($phase === 'accelerating') {
            return round($max * $this->progressBetween($now, $movement->preparationEndsAt, $movement->accelerationEndsAt), 2);
        }
        if ($phase === 'decelerating') {
            return round($max * (1 - $this->progressBetween($now, $movement->cruiseEndsAt, $movement->decelerationEndsAt)), 2);
        }

        return 0.0;
    }

    public function secondsRemaining(?ProbeMovement $movement): int
    {
        if ($movement === null || in_array($movement->status, ['arrived', 'failed', 'destroyed'], true)) {
            return 0;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $arrival = new \DateTimeImmutable($movement->arrivalAt);

        return max(0, $arrival->getTimestamp() - $now->getTimestamp());
    }

    public function ensureProbeOperational(NeumannProbe $probe): void
    {
        if ($probe->status === ProbeStatus::Dead) {
            throw new ProbeMovementException(409, 'probe_dead', 'The probe is no longer operational.');
        }
        if ($probe->status === ProbeStatus::TrappedByBlackHole) {
            throw new ProbeMovementException(409, 'probe_trapped_by_black_hole', 'The probe is trapped beyond a black hole escape threshold.');
        }
    }

    private function phaseAt(ProbeMovement $movement, \DateTimeImmutable $now): string
    {
        if ($this->isBefore($now, $movement->preparationEndsAt)) {
            return 'preparing';
        }
        if ($this->isBefore($now, $movement->accelerationEndsAt)) {
            return 'accelerating';
        }
        if ($this->isBefore($now, $movement->cruiseEndsAt)) {
            return 'cruising';
        }
        if ($this->isBefore($now, $movement->decelerationEndsAt)) {
            return 'decelerating';
        }

        return 'arrived';
    }

    private function checkDestructionAtCruiseStart(NeumannProbe $probe, ProbeMovement $movement, string $phase, \DateTimeImmutable $now): void
    {
        if ($phase !== 'cruising' || $movement->destructionCheckedAt !== null) {
            return;
        }

        $movement->destructionCheckedAt = $now->format('c');
        $risk = $this->destructionRiskForDistance($movement->distance);

        if ($risk > 0 && $this->riskRoll->roll($this->worldSeed, $movement) < $risk) {
            $movement->status = 'destroyed';
            $movement->destroyedAt = $now->format('c');
            $movement->destructionReason = 'High velocity collision with undetected celestial object';
            $this->movements->save($movement);

            $probe->status = ProbeStatus::Dead;
            $probe->velocityC = 0.0;
            $probe->accelerationCPerDay = 0.0;
            $probe->integrityPercent = 0.0;
            $probe->currentTask = null;
            $this->probes->save($probe);
            return;
        }

        $this->movements->save($movement);
    }

    private function registerForgottenMannies(NeumannProbe $probe): void
    {
        if ($this->mannies === null || $this->sectors === null) {
            return;
        }

        $sector = null;
        $changed = false;
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if ($manny->isOnProbe() || $manny->sector === null || !$manny->sector->equals($probe->currentSector)) {
                continue;
            }

            $sector ??= $this->sectors->getOrCreateSector($probe->currentSector);
            $object = new SectorManny(
                SectorManny::objectIdForUid($manny->uid),
                $manny->name,
                $manny->uid,
                SectorManny::STATE_FORGOTTEN,
                $this->mannyCargoArray($manny),
                'Manny left behind by its probe.',
            );
            if (!$sector->replaceObject($object)) {
                $sector->addObject($object);
            }
            $changed = true;
        }

        if ($changed && $sector !== null) {
            $this->sectors->saveSector($sector);
        }
    }

    private function createIntelligentLifeAlerts(NeumannProbe $probe, ProbeMovement $movement): void
    {
        if ($this->sectors === null || $this->damageWarnings === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($movement->target);
        foreach ($this->intelligentLifePlanets($sector->getObjects()) as $planet) {
            $planetName = $this->publicPlanetName($planet, $movement->target);
            $message = 'Intelligent life detected: technological signatures confirmed on '
                . $planetName
                . ' in the arrival sector'
                . '.';
            $this->damageWarnings->createIntelligentLifeAlert(
                $probe->id,
                $movement->id,
                $movement->target,
                $planet->getId(),
                $planetName,
                $message,
            );
        }
    }

    private function startIntelligentLifeScenarios(NeumannProbe $probe, ProbeMovement $movement): void
    {
        if ($this->sectors === null || $this->missions === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($movement->target);
        foreach ($this->intelligentLifePlanets($sector->getObjects()) as $planet) {
            $this->missions->startIntelligentLifeScenario($probe, $movement->target, $planet, $movement->id);
        }
    }

    private function createDormantConstructAlerts(NeumannProbe $probe, ProbeMovement $movement): void
    {
        if ($this->sectors === null || $this->damageWarnings === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($movement->target);
        foreach ($this->dormantConstructs($sector->getObjects()) as $construct) {
            $this->damageWarnings->createSectorObjectDetectedAlert(
                $probe->id,
                $movement->id,
                $movement->target,
                $construct->getId(),
                $construct->getType()->value,
                $construct->getName() ?? 'Dormant construct',
                'A dormant construct has been detected in this sector. Its origin and purpose are unknown; dispatching a Manny to inspect it is recommended.',
            );
        }
    }

    /**
     * @param array<UniverseObject> $objects
     * @return array<Planet>
     */
    private function intelligentLifePlanets(array $objects): array
    {
        $planets = [];
        foreach ($objects as $object) {
            if ($object instanceof Planet && $object->hasIntelligentLife()) {
                $planets[] = $object;
                continue;
            }

            if ($object instanceof SolarSystem) {
                foreach ($object->getOrbitalBodies() as $body) {
                    $bodyObject = $body->getObject();
                    if ($bodyObject instanceof Planet && $bodyObject->hasIntelligentLife()) {
                        $planets[] = $bodyObject;
                    }
                }
            }
        }

        return $planets;
    }

    /**
     * @param array<UniverseObject> $objects
     * @return array<DormantConstruct>
     */
    private function dormantConstructs(array $objects): array
    {
        $constructs = [];
        foreach ($objects as $object) {
            if ($object instanceof DormantConstruct) {
                $constructs[] = $object;
            }
        }

        return $constructs;
    }

    private function directionBetween(SectorCoordinates $origin, SectorCoordinates $target): ProbeDirection
    {
        $dx = $target->getX() - $origin->getX();
        $dy = $target->getY() - $origin->getY();
        $dz = $target->getZ() - $origin->getZ();
        $length = sqrt(($dx * $dx) + ($dy * $dy) + ($dz * $dz));
        if ($length <= 0.0) {
            return new ProbeDirection(0.0, 0.0, 0.0);
        }

        return new ProbeDirection(round($dx / $length, 4), round($dy / $length, 4), round($dz / $length, 4));
    }

    private function publicPlanetName(Planet $planet, SectorCoordinates $sector): string
    {
        $name = $planet->getName();
        if ($name !== null && !$this->nameContainsSectorCoordinates($name, $sector)) {
            return $name;
        }

        return 'Monde habite';
    }

    private function nameContainsSectorCoordinates(string $name, SectorCoordinates $sector): bool
    {
        $absoluteKey = $sector->toKey();

        return str_contains($name, $absoluteKey)
            || str_contains($name, str_replace(':', '-', $absoluteKey))
            || str_contains($name, str_replace(':', ' ', $absoluteKey));
    }

    private function progressBetween(\DateTimeImmutable $now, string $start, string $end): float
    {
        $startTime = (new \DateTimeImmutable($start))->getTimestamp();
        $endTime = (new \DateTimeImmutable($end))->getTimestamp();
        $duration = max(1, $endTime - $startTime);

        return max(0.0, min(1.0, ($now->getTimestamp() - $startTime) / $duration));
    }

    private function isBefore(\DateTimeImmutable $now, string $date): bool
    {
        return $now->getTimestamp() < (new \DateTimeImmutable($date))->getTimestamp();
    }

    private function isAtOrAfter(\DateTimeImmutable $now, string $date): bool
    {
        return $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }

    private function scheduleMovementEvents(ProbeMovement $movement): void
    {
        if ($this->scheduledEvents === null) {
            return;
        }

        foreach ([
            'accelerating' => $movement->preparationEndsAt,
            'cruising' => $movement->accelerationEndsAt,
            'decelerating' => $movement->cruiseEndsAt,
            'arrived' => $movement->arrivalAt,
        ] as $phase => $runAt) {
            $this->scheduledEvents->schedule(
                SchedulerService::PROBE_MOVEMENT_PHASE,
                'probe_movement',
                $movement->id,
                $runAt,
                [
                    'probeId' => $movement->probeId,
                    'phase' => $phase,
                ],
            );
        }
    }

    private function scheduleFragileContainerLossIfNeeded(NeumannProbe $probe, ProbeMovement $movement, ?Player $player = null): void
    {
        if ($this->scheduledEvents === null || $this->storage === null || $this->damageWarnings === null) {
            return;
        }

        $containers = $this->storage->additionalContainerCandidates($probe);
        $count = count($containers);
        $risk = $this->fragileContainerLossRisk($probe, $count);
        if ($risk <= 0.0 || $containers === []) {
            return;
        }
        if ($this->deterministicFloat('fragile-container-loss-risk', $movement) >= $risk) {
            return;
        }

        $containerIndex = min(
            count($containers) - 1,
            (int) floor($this->deterministicFloat('fragile-container-loss-container', $movement) * count($containers)),
        );
        $container = $containers[$containerIndex];
        $atOrigin = $this->deterministicFloat('fragile-container-loss-sector', $movement) < 0.5;
        $sector = $atOrigin ? $movement->origin : $movement->target;
        $phase = $atOrigin ? 'acceleration_end' : 'deceleration_start';
        $runAt = $atOrigin ? $movement->accelerationEndsAt : $movement->cruiseEndsAt;
        $objectId = SectorDetachedContainer::objectIdForContainer((string) $container['id']);
        $riskPercent = round($risk * 100, 2);
        $startsAtAdditionalContainers = 5 + $this->fragileContainerRiskDiscount($probe);
        $sectorLabel = $this->publicMovementSectorLabel($sector, $player, $atOrigin ? 'movement origin sector' : 'movement target sector');
        $message = 'Fragile external storage warning: from ' . $startsAtAdditionalContainers . ' additional containers onward, movement can break a container link. '
            . 'This jump is expected to lose ' . (string) $container['label']
            . ' near ' . $sectorLabel
            . ' with a ' . $riskPercent . '% break risk.';

        $warning = $this->damageWarnings->createStorageContainerBreakWarning(
            $probe->id,
            $movement->id,
            $phase,
            $runAt,
            $sector,
            (string) $container['id'],
            (string) $container['label'],
            $objectId,
            $riskPercent,
            $count,
            $message,
        );

        $this->scheduledEvents->schedule(
            SchedulerService::PROBE_STORAGE_CONTAINER_BREAK,
            'probe_damage_warning',
            $warning->id,
            $runAt,
            [
                'warningId' => $warning->id,
                'probeId' => $probe->id,
                'playerId' => $probe->playerId,
                'movementId' => $movement->id,
                'containerId' => (string) $container['id'],
                'objectId' => $objectId,
                'phase' => $phase,
                'sector' => $sector->toArray(),
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function breakStorageContainerFromScheduledWarning(array $payload): void
    {
        if ($this->storage === null || $this->sectors === null) {
            return;
        }

        $probeId = (int) ($payload['probeId'] ?? 0);
        $containerId = (string) ($payload['containerId'] ?? '');
        $sector = $this->sectorFromPayload($payload['sector'] ?? null);
        if ($probeId <= 0 || $containerId === '' || $sector === null) {
            return;
        }

        $probe = $this->probes->findById($probeId);
        if ($probe === null) {
            return;
        }

        try {
            $snapshot = $this->storage->detachAdditionalContainerSnapshot($probe, $containerId, (int) ($payload['playerId'] ?? $probe->playerId));
        } catch (MannyActionException) {
            return;
        }

        $containerData = is_array($snapshot['container'] ?? null) ? $snapshot['container'] : [];
        $object = new SectorDetachedContainer(
            (string) ($payload['objectId'] ?? SectorDetachedContainer::objectIdForContainer((string) ($snapshot['sourceContainerId'] ?? $containerId))),
            (string) ($containerData['label'] ?? 'Detached storage container'),
            SectorDetachedContainer::MODE_DRIFTING,
            (int) ($snapshot['ownerProbeId'] ?? $probe->id),
            (int) ($snapshot['ownerPlayerId'] ?? $probe->playerId),
            null,
            null,
            (float) ($containerData['capacity'] ?? 0.0),
            ProbeInventory::CAPACITY_UNIT,
            gmdate('c'),
            $snapshot + [
                'mode' => SectorDetachedContainer::MODE_DRIFTING,
                'sector' => $sector->toArray(),
                'movementDamageWarningId' => (int) ($payload['warningId'] ?? 0),
                'movementId' => (int) ($payload['movementId'] ?? 0),
                'phase' => (string) ($payload['phase'] ?? ''),
            ],
            'Detached storage container drifting after a movement stress break.',
        );

        $sectorContent = $this->sectors->getOrCreateSector($sector);
        if (!$sectorContent->replaceObject($object)) {
            $sectorContent->addObject($object);
        }
        $this->sectors->saveSector($sectorContent);
        if ($this->damageWarnings !== null && (int) ($payload['warningId'] ?? 0) > 0) {
            $this->damageWarnings->markResolved((int) $payload['warningId']);
        }
    }

    private function fragileContainerLossRisk(NeumannProbe $probe, int $additionalContainerCount): float
    {
        $effectiveContainerCount = max(0, $additionalContainerCount - $this->fragileContainerRiskDiscount($probe));

        return min(1.0, max(0.0, ($effectiveContainerCount - 4) * 0.10));
    }

    private function fragileContainerRiskDiscount(NeumannProbe $probe): int
    {
        if (
            $this->improvements === null
            || !$this->improvements->isDone($probe->id, ProbeImprovementCatalog::REINFORCED_CONTAINER_COUPLINGS)
        ) {
            return 0;
        }

        $definition = ProbeImprovementCatalog::find(
            ProbeImprovementCatalog::REINFORCED_CONTAINER_COUPLINGS,
            Config::getArray($this->gameplayConfig, 'probeImprovements'),
        );
        $effects = is_array($definition['effects'] ?? null) ? $definition['effects'] : [];

        return max(0, (int) ($effects['fragileContainerRiskAdditionalContainerDiscount'] ?? ProbeImprovementCatalog::REINFORCED_CONTAINER_COUPLINGS_CONTAINER_RISK_DISCOUNT));
    }

    private function publicMovementSectorLabel(SectorCoordinates $sector, ?Player $player, string $fallback): string
    {
        if ($player === null) {
            return $fallback;
        }

        $relative = (new PlayerReferenceFrame($player->homeSector))->globalToRelative($sector);

        return 'relative sector ' . $this->coordinateLabel($relative);
    }

    /**
     * @param array{x: int, y: int, z: int} $coordinates
     */
    private function coordinateLabel(array $coordinates): string
    {
        return (string) ($coordinates['x'] ?? 0)
            . ':' . (string) ($coordinates['y'] ?? 0)
            . ':' . (string) ($coordinates['z'] ?? 0);
    }

    private function deterministicFloat(string $purpose, ProbeMovement $movement): float
    {
        $payload = implode('|', [
            $this->worldSeed,
            $purpose,
            $movement->probeId,
            $movement->id,
            $movement->origin->toKey(),
            $movement->target->toKey(),
            $movement->startedAt,
        ]);

        return hexdec(substr(hash('sha256', $payload), 0, 8)) / hexdec('ffffffff');
    }

    private function sectorFromPayload(mixed $value): ?SectorCoordinates
    {
        if (!is_array($value)) {
            return null;
        }
        if (!isset($value['x'], $value['y'], $value['z'])) {
            return null;
        }

        return new SectorCoordinates((int) $value['x'], (int) $value['y'], (int) $value['z']);
    }

    public function trapProbeByBlackHole(NeumannProbe $probe): void
    {
        if ($this->movements->findActiveByProbeId($probe->id) !== null) {
            return;
        }
        if ($this->sectors === null || !$this->sectors->getOrCreateSector($probe->currentSector)->hasBlackHole()) {
            return;
        }
        if ($probe->status === ProbeStatus::Dead || $probe->status === ProbeStatus::TrappedByBlackHole) {
            return;
        }

        $probe->status = ProbeStatus::TrappedByBlackHole;
        $probe->velocityC = 0.0;
        $probe->accelerationCPerDay = 0.0;
        $probe->direction = new ProbeDirection(0.0, 0.0, 0.0);
        $probe->currentTask = null;
        $probe->integrityPercent = 0.0;
        $this->probes->save($probe);
    }

    private function applyIntersectorIntegrityLoss(NeumannProbe $probe, ProbeMovement $movement): void
    {
        $integrityLoss = 0.0;
        for ($sectorIndex = 1; $sectorIndex <= $movement->distance; $sectorIndex++) {
            $payload = implode('|', [
                $this->worldSeed,
                'intersector-dust-damage',
                $movement->probeId,
                $movement->id,
                $movement->origin->toKey(),
                $movement->target->toKey(),
                $movement->startedAt,
                $sectorIndex,
            ]);
            $roll = hexdec(substr(hash('sha256', $payload), 0, 8)) / hexdec('ffffffff');
            $integrityLoss += round($roll * $this->float('intersectorIntegrityLossMaxPercentPerDistance', 3.0), 2);
        }

        $probe->integrityPercent = round(max(0.0, $probe->integrityPercent - $integrityLoss), 2);
    }

    private function scheduleBlackHoleTrapIfNeeded(NeumannProbe $probe): void
    {
        if ($this->scheduledEvents === null || $this->sectors === null) {
            return;
        }
        $delaySeconds = $this->blackHoleTrapDelaySecondsForCurrentSector($probe);
        if ($delaySeconds === null) {
            return;
        }
        if ($this->scheduledEvents->findPendingByTypeAndEntity(SchedulerService::PROBE_BLACK_HOLE_TRAP, 'probe', $probe->id) !== null) {
            return;
        }

        $this->scheduledEvents->schedule(
            SchedulerService::PROBE_BLACK_HOLE_TRAP,
            'probe',
            $probe->id,
            gmdate('c', time() + $delaySeconds),
            ['delaySeconds' => $delaySeconds],
        );
    }

    private function cancelBlackHoleTrap(NeumannProbe $probe): void
    {
        $this->scheduledEvents?->cancelPending(SchedulerService::PROBE_BLACK_HOLE_TRAP, 'probe', $probe->id);
    }

    private function blackHoleTrapDelaySecondsForCurrentSector(NeumannProbe $probe): ?int
    {
        if ($this->sectors === null) {
            return null;
        }

        $masses = [];
        foreach ($this->sectors->getOrCreateSector($probe->currentSector)->getObjects() as $object) {
            if ($object instanceof BlackHole) {
                $masses[] = $object->getMass();
            }
        }
        if ($masses === []) {
            return null;
        }

        $mass = max($masses);
        $minMass = $this->float('blackHoleTrap.minMass', 3.0);
        $maxMass = $this->float('blackHoleTrap.maxMass', 30.0);
        $factor = max(0.0, min(1.0, ($mass - $minMass) / max(0.0001, $maxMass - $minMass)));
        $minDelay = $this->int('blackHoleTrap.minDelaySeconds', self::BLACK_HOLE_TRAP_MIN_DELAY_SECONDS);
        $maxDelay = $this->int('blackHoleTrap.maxDelaySeconds', self::BLACK_HOLE_TRAP_MAX_DELAY_SECONDS);

        return (int) round($maxDelay - ($factor * ($maxDelay - $minDelay)));
    }

    private function destructionRiskForDistance(int $distance): float
    {
        if ($distance <= $this->int('destructionSafeDistance', 2)) {
            return 0.0;
        }

        $risks = Config::getArray($this->movementConfig, 'destructionRiskByDistance', [
            '3' => 0.05,
            '4' => 0.12,
            '5' => 0.25,
            'default' => 0.40,
        ]);
        $key = (string) $distance;
        $risk = is_numeric($risks[$key] ?? null) ? (float) $risks[$key] : (float) ($risks['default'] ?? 0.40);

        return max(0.0, min(1.0, $risk));
    }

    private function int(string $path, int $default): int
    {
        return Config::int($this->movementConfig, $path, $default);
    }

    private function float(string $path, float $default): float
    {
        return Config::float($this->movementConfig, $path, $default);
    }

    private function mannyCargoArray(\VonNeumannGame\Domain\Manny $manny): array
    {
        return array_replace($manny->cargoArray(), [
            'capacity' => max(0.0001, Config::float($this->gameplayConfig, 'manny.cargoCapacity', \VonNeumannGame\Domain\Manny::CARGO_CAPACITY)),
        ]);
    }
}
