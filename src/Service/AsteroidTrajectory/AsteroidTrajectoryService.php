<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use PDOException;
use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\AsteroidTrajectory;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Repository\AsteroidTrajectoryRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\SolarSystem;
use VonNeumannGame\Sector\Star;
use VonNeumannGame\Service\MannyService;

final class AsteroidTrajectoryService
{
    private readonly array $trajectoryConfig;
    private readonly TrajectoryKinematicsCalculator $kinematics;
    private readonly RevolutionCalculator $revolutions;

    public function __construct(
        private readonly AsteroidTrajectoryRepository $trajectories,
        private readonly NeumannProbeRepository $probes,
        private readonly ScheduledEventRepository $events,
        private readonly SectorService $sectors,
        array $gameplayConfig,
        array $universeConfig,
        private readonly ?ProbeDamageWarningRepository $alerts = null,
        private readonly ?OthersRepository $others = null,
        private readonly ?MannyService $mannyService = null,
    ) {
        $this->trajectoryConfig = Config::getArray($gameplayConfig, 'asteroidTrajectories');
        $massRange = Config::getArray($universeConfig, 'asteroids.massRange', [0.000001, 0.02]);
        $this->kinematics = new TrajectoryKinematicsCalculator(
            (float) ($massRange[0] ?? 0.000001),
            (float) ($massRange[1] ?? 0.02),
            Config::float($this->trajectoryConfig, 'maximumTargetSpeedC', 0.5),
            Config::int($this->trajectoryConfig, 'minimumAccelerationDurationSeconds', 1),
            Config::int($this->trajectoryConfig, 'maximumAccelerationDurationSeconds', 259200),
        );
        $this->revolutions = new RevolutionCalculator(
            Config::getArray($this->trajectoryConfig, 'revolutionDurations', [
                ['maximumStarMassSolar' => null, 'durationSeconds' => 43200],
            ]),
            Config::float($this->trajectoryConfig, 'occultationWindowFraction', 0.15),
        );
    }

    /** @param array<string, mixed> $request */
    public function create(Player $player, NeumannProbe $selectedProbe, string $asteroidId, array $request): AsteroidTrajectory
    {
        return $this->probes->withProbeLock($selectedProbe->id, function () use ($player, $selectedProbe, $asteroidId, $request): AsteroidTrajectory {
            $probe = $this->probes->findById($selectedProbe->id) ?? $selectedProbe;
            $sector = $this->sectors->getOrCreateSector($probe->currentSector);
            $activeTrajectory = $this->trajectories->findActiveByAsteroidId($asteroidId);
            if ($activeTrajectory !== null && $activeTrajectory->currentSector->equals($probe->currentSector)) {
                throw new AsteroidTrajectoryException(409, 'asteroid_trajectory_already_active', 'This asteroid already has an active trajectory.');
            }
            $asteroid = $sector->findObjectById($asteroidId);
            if (!$asteroid instanceof Asteroid) {
                throw new AsteroidTrajectoryException(404, 'asteroid_not_found', 'The asteroid is not present in the selected probe sector.');
            }
            if (!$asteroid->isMotorized()) {
                throw new AsteroidTrajectoryException(409, 'asteroid_not_motorized', 'This asteroid is not motorized.');
            }
            if ($activeTrajectory !== null) {
                throw new AsteroidTrajectoryException(409, 'asteroid_trajectory_already_active', 'This asteroid already has an active trajectory.');
            }
            if ($asteroid->getMotorFuelStatus() !== Asteroid::MOTOR_FUEL_FULL) {
                throw new AsteroidTrajectoryException(409, 'asteroid_motor_empty', 'This asteroid motor is empty.');
            }

            $mode = $request['mode'] ?? null;
            if (!is_string($mode) || !in_array($mode, [AsteroidTrajectory::MODE_SYSTEM_IMPACT, AsteroidTrajectory::MODE_SECTOR_TRANSFER], true)) {
                throw new AsteroidTrajectoryException(422, 'invalid_asteroid_trajectory_mode', 'Unknown asteroid trajectory mode.');
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $values = $mode === AsteroidTrajectory::MODE_SYSTEM_IMPACT
                ? $this->systemImpactValues($probe, $sector, $asteroid, $request, $now)
                : $this->sectorTransferValues($player, $probe, $asteroid, $request, $now);
            $values['launcherProbeId'] = $probe->id;
            $values['asteroidSnapshot'] = $asteroid->withMotorFuelStatus(Asteroid::MOTOR_FUEL_EMPTY)->toArray();
            $values['attachmentsSnapshot'] = array_map(
                static fn(\VonNeumannGame\Sector\SectorDetachedContainer $container): array => $container->toArray(),
                $sector->containersForObject($asteroidId),
            );

            try {
                $trajectory = $this->trajectories->create($values);
            } catch (PDOException $error) {
                if ($this->trajectories->findActiveByAsteroidId($asteroidId) !== null) {
                    throw new AsteroidTrajectoryException(409, 'asteroid_trajectory_already_active', 'This asteroid already has an active trajectory.');
                }
                throw $error;
            }
            $this->mannyService?->recallMiningManniesTargetingDetachedContainers(
                array_values(array_filter(array_map(
                    static fn(array $container): string => is_string($container['id'] ?? null) ? trim($container['id']) : '',
                    $values['attachmentsSnapshot'],
                ))),
                'target_container_departed_with_asteroid',
            );
            // A recall removes the Manny's sector object from a freshly loaded
            // aggregate. Reload before saving the launch state so that this
            // method cannot overwrite that removal with its older snapshot.
            $sector = $this->sectors->getOrCreateSector($probe->currentSector);
            if ($mode === AsteroidTrajectory::MODE_SECTOR_TRANSFER) {
                if (!$sector->removeObjectById($asteroidId)) {
                    throw new \RuntimeException('Unable to remove the departing asteroid from its origin.');
                }
                $sector->removeContainersForObject($asteroidId);
            } else {
                $emptyAsteroid = $asteroid->withMotorFuelStatus(Asteroid::MOTOR_FUEL_EMPTY);
                if (!$sector->replaceObject($emptyAsteroid)) {
                    throw new \RuntimeException('Unable to persist asteroid fuel consumption.');
                }
            }
            $this->sectors->saveSector($sector);
            $this->events->schedule(
                'asteroid.trajectory.phase',
                'asteroid_trajectory',
                $trajectory->id,
                $trajectory->nextTransitionAt,
                [
                    'trajectoryId' => $trajectory->id,
                    'expectedStatus' => $trajectory->status,
                    'expectedSectorsCrossed' => $trajectory->mode === AsteroidTrajectory::MODE_SECTOR_TRANSFER
                        ? $trajectory->sectorsCrossed
                        : null,
                ],
            );
            $ignitionAlertMessage = $this->ignitionAlertMessage($trajectory, $sector);
            foreach ($this->probes->findBySector($probe->currentSector) as $localProbe) {
                $this->alerts?->createAsteroidTrajectoryAlert(
                    $localProbe->id,
                    $probe->currentSector,
                    $trajectory->uid,
                    $asteroidId,
                    $ignitionAlertMessage,
                );
            }

            return $trajectory;
        });
    }

    private function ignitionAlertMessage(AsteroidTrajectory $trajectory, SectorContent $sector): string
    {
        $message = 'Motorized asteroid ignition detected. Trajectory: ' . $trajectory->mode . '.';
        if ($trajectory->mode !== AsteroidTrajectory::MODE_SYSTEM_IMPACT || $trajectory->targetObjectId === null) {
            return $message;
        }

        $targetType = $trajectory->targetProbeId !== null
            ? 'probe'
            : ($sector->findObjectById($trajectory->targetObjectId)?->getType()->value
                ?? ($this->others?->findShipByPublicId($trajectory->targetObjectId) !== null ? 'ship' : 'unknown'));

        return $message . ' Target: ' . $trajectory->targetObjectId . ' (' . $targetType . ').';
    }

    public function getForLocalProbe(NeumannProbe $probe, string $uid, ?\DateTimeImmutable $now = null): array
    {
        $trajectory = $this->trajectories->findByUid($uid);
        if ($trajectory === null || !$probe->currentSector->equals($trajectory->currentSector)) {
            throw new AsteroidTrajectoryException(404, 'asteroid_trajectory_not_found', 'No locally detectable asteroid trajectory has this id.');
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (
            $trajectory->mode === AsteroidTrajectory::MODE_SYSTEM_IMPACT
            && $trajectory->status === AsteroidTrajectory::STATUS_ACCELERATING
            && $trajectory->accelerationStartedAt !== null
            && $trajectory->revolutionDurationSeconds !== null
            && $this->revolutions->isOcculted(
                $trajectory->uid,
                max(0, $now->getTimestamp() - (new \DateTimeImmutable($trajectory->accelerationStartedAt))->getTimestamp()),
                $trajectory->revolutionDurationSeconds,
            )
        ) {
            throw new AsteroidTrajectoryException(409, 'asteroid_temporarily_occluded', 'Local asteroid telemetry is temporarily unavailable.');
        }

        return $this->publicArray($trajectory, $now);
    }

    public function publicArray(AsteroidTrajectory $trajectory, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $data = [
            'id' => $trajectory->uid,
            'asteroidId' => $trajectory->asteroidId,
            'mode' => $trajectory->mode,
            'status' => $trajectory->status,
            'startedAt' => $trajectory->createdAt,
            'nextTransitionAt' => $trajectory->nextTransitionAt,
        ];
        if ($trajectory->mode === AsteroidTrajectory::MODE_SYSTEM_IMPACT) {
            $elapsed = $trajectory->accelerationStartedAt === null
                ? 0
                : max(0, $now->getTimestamp() - (new \DateTimeImmutable($trajectory->accelerationStartedAt))->getTimestamp());
            $duration = $trajectory->accelerationStartedAt === null || $trajectory->accelerationEndsAt === null
                ? 1
                : max(1, (new \DateTimeImmutable($trajectory->accelerationEndsAt))->getTimestamp() - (new \DateTimeImmutable($trajectory->accelerationStartedAt))->getTimestamp());
            $data += [
                'targetObjectId' => $trajectory->targetObjectId,
                'targetSpeedC' => $trajectory->targetSpeedC,
                'currentSpeedC' => $trajectory->status === AsteroidTrajectory::STATUS_ACCELERATING
                    ? $this->kinematics->currentSpeedC((float) $trajectory->targetSpeedC, $elapsed, $duration)
                    : $trajectory->targetSpeedC,
                'plannedRevolutions' => $trajectory->plannedRevolutions,
                'completedRevolutions' => $this->revolutions->completedRevolutions(
                    $elapsed,
                    max(1, (int) $trajectory->revolutionDurationSeconds),
                    max(1, (int) $trajectory->plannedRevolutions),
                ),
                'estimatedCompletionAt' => $trajectory->accelerationEndsAt === null
                    ? null
                    : (new \DateTimeImmutable($trajectory->accelerationEndsAt))
                        ->modify('+' . Config::int($this->trajectoryConfig, 'coastingDurationSeconds', 600) . ' seconds')->format('c'),
            ];
        } else {
            $data += [
                'speed' => ['sectors' => 1, 'perSeconds' => Config::int($this->trajectoryConfig, 'sectorCrossingDurationSeconds', 86400)],
                'direction' => $trajectory->direction,
                'sectorsCrossed' => $trajectory->sectorsCrossed,
                'maximumSectorCrossings' => $trajectory->maximumSectorCrossings,
            ];
        }
        if ($trajectory->result !== null) {
            $data['result'] = $trajectory->result;
        }
        if ($trajectory->failureReason !== null) {
            $data['failureReason'] = $trajectory->failureReason;
        }

        return $data;
    }

    public function isOcculted(AsteroidTrajectory $trajectory, ?\DateTimeImmutable $now = null): bool
    {
        if ($trajectory->mode !== AsteroidTrajectory::MODE_SYSTEM_IMPACT || $trajectory->status !== AsteroidTrajectory::STATUS_ACCELERATING || $trajectory->accelerationStartedAt === null || $trajectory->revolutionDurationSeconds === null) {
            return false;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this->revolutions->isOcculted(
            $trajectory->uid,
            max(0, $now->getTimestamp() - (new \DateTimeImmutable($trajectory->accelerationStartedAt))->getTimestamp()),
            $trajectory->revolutionDurationSeconds,
        );
    }

    /** @return array<string, mixed> */
    private function systemImpactValues(NeumannProbe $probe, SectorContent $sector, Asteroid $asteroid, array $request, \DateTimeImmutable $now): array
    {
        if ($sector->hasBlackHole()) {
            throw new AsteroidTrajectoryException(422, 'system_impact_black_hole_forbidden', 'System impacts are forbidden in a sector containing a black hole.');
        }
        $targetSpeed = $request['targetSpeedC'] ?? null;
        if (!is_int($targetSpeed) && !is_float($targetSpeed)) {
            throw new AsteroidTrajectoryException(422, 'invalid_asteroid_target_speed', 'targetSpeedC must be a number.');
        }
        try {
            $duration = $this->kinematics->accelerationDurationSeconds($asteroid->getMass(), (float) $targetSpeed);
        } catch (\InvalidArgumentException $error) {
            throw new AsteroidTrajectoryException(422, 'invalid_asteroid_target_speed', $error->getMessage());
        }
        $targetId = $request['targetObjectId'] ?? null;
        if (!is_string($targetId) || $targetId === '' || $targetId === $asteroid->getId()) {
            throw new AsteroidTrajectoryException(422, 'invalid_asteroid_impact_target', 'A distinct targetObjectId is required.');
        }
        $target = $sector->findObjectById($targetId);
        $targetProbeId = null;
        if (!$target instanceof Asteroid && !$target instanceof Planet && !$target instanceof Star) {
            $numericTargetId = filter_var($targetId, FILTER_VALIDATE_INT);
            $targetProbe = $numericTargetId !== false ? $this->probes->findById((int) $numericTargetId) : null;
            if ($targetProbe === null || !$targetProbe->currentSector->equals($probe->currentSector)) {
                $othersShip = $this->others?->findShipByPublicId($targetId);
                if ($othersShip === null || $othersShip['destroyed_at'] !== null || in_array((string)$othersShip['status'], ['transit','removed','destroyed'], true)
                    || [(int)$othersShip['sector_x'],(int)$othersShip['sector_y'],(int)$othersShip['sector_z']] !== [$probe->currentSector->getX(),$probe->currentSector->getY(),$probe->currentSector->getZ()]) {
                    throw new AsteroidTrajectoryException(422, 'invalid_asteroid_impact_target', 'The impact target must be a local probe, Others ship, asteroid, planet or star.');
                }
            } else {
                $targetProbeId = $targetProbe->id;
            }
        }
        [$starMass] = $this->referenceStar($sector);
        $revolutionDuration = $this->revolutions->durationSeconds($starMass);
        $accelerationEnds = $now->modify('+' . $duration . ' seconds');

        return [
            'asteroidId' => $asteroid->getId(), 'mode' => AsteroidTrajectory::MODE_SYSTEM_IMPACT,
            'status' => AsteroidTrajectory::STATUS_ACCELERATING, 'originSector' => $probe->currentSector,
            'targetObjectId' => $targetId, 'targetProbeId' => $targetProbeId, 'targetSpeedC' => (float) $targetSpeed,
            'asteroidMassEarth' => $asteroid->getMass(), 'starMassSolar' => $starMass,
            'accelerationStartedAt' => $now->format('c'), 'accelerationEndsAt' => $accelerationEnds->format('c'),
            'revolutionDurationSeconds' => $revolutionDuration,
            'plannedRevolutions' => $this->revolutions->plannedRevolutions($duration, $revolutionDuration),
            'nextTransitionAt' => $accelerationEnds->format('c'),
            'maximumSectorCrossings' => Config::int($this->trajectoryConfig, 'maximumSectorCrossings', 10),
        ];
    }

    /** @return array<string, mixed> */
    private function sectorTransferValues(Player $player, NeumannProbe $probe, Asteroid $asteroid, array $request, \DateTimeImmutable $now): array
    {
        $target = $request['target'] ?? null;
        if (!is_array($target) || !$this->integerCoordinates($target)) {
            throw new AsteroidTrajectoryException(422, 'invalid_neighbor_sector', 'target must contain integer relative x, y and z coordinates.');
        }
        try {
            $absoluteTarget = (new PlayerReferenceFrame($player->homeSector))->relativeToGlobal((int) $target['x'], (int) $target['y'], (int) $target['z']);
        } catch (\Throwable $error) {
            throw new AsteroidTrajectoryException(422, 'invalid_neighbor_sector', $error->getMessage());
        }
        if ((new SectorGrid())->getDistance($probe->currentSector, $absoluteTarget) !== 1) {
            throw new AsteroidTrajectoryException(422, 'invalid_neighbor_sector', 'The initial transfer target must be a direct FCC-grid neighbor.');
        }
        $direction = $absoluteTarget->subtract($probe->currentSector);
        $next = $now->modify('+' . Config::int($this->trajectoryConfig, 'sectorCrossingDurationSeconds', 86400) . ' seconds');

        return [
            'asteroidId' => $asteroid->getId(), 'mode' => AsteroidTrajectory::MODE_SECTOR_TRANSFER,
            'status' => AsteroidTrajectory::STATUS_CROSSING_SECTOR, 'originSector' => $probe->currentSector,
            'direction' => $direction, 'asteroidMassEarth' => $asteroid->getMass(),
            'nextTransitionAt' => $next->format('c'),
            'maximumSectorCrossings' => Config::int($this->trajectoryConfig, 'maximumSectorCrossings', 10),
        ];
    }

    /** @return array{float, string} */
    private function referenceStar(SectorContent $sector): array
    {
        $candidates = [];
        foreach ($sector->getObjects() as $object) {
            $star = $object instanceof SolarSystem ? $object->getPrimaryStar() : ($object instanceof Star ? $object : null);
            if ($star !== null) {
                $candidates[] = [$star->getMass(), $star->getId()];
            }
        }
        if ($candidates === []) {
            throw new AsteroidTrajectoryException(422, 'invalid_asteroid_impact_target', 'A system impact requires a stellar system in the sector.');
        }
        usort($candidates, static fn(array $left, array $right): int => ($right[0] <=> $left[0]) ?: ($left[1] <=> $right[1]));
        return $candidates[0];
    }

    private function integerCoordinates(array $target): bool
    {
        foreach (['x', 'y', 'z'] as $axis) {
            if (!array_key_exists($axis, $target) || filter_var($target[$axis], FILTER_VALIDATE_INT) === false) {
                return false;
            }
        }
        return true;
    }
}
