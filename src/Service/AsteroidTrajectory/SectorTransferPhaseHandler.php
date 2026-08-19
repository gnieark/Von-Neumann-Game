<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use VonNeumannGame\Domain\AsteroidTrajectory;
use VonNeumannGame\Repository\AsteroidTrajectoryRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\BlackHole;
use VonNeumannGame\Sector\OrbitDescriptor;
use VonNeumannGame\Sector\OrbitingBody;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\SolarSystem;
use VonNeumannGame\Sector\Star;

final class SectorTransferPhaseHandler implements PhaseHandlerInterface
{
    public function __construct(
        private readonly AsteroidTrajectoryRepository $trajectories,
        private readonly ScheduledEventRepository $events,
        private readonly SectorService $sectors,
        private readonly CaptureCalculator $capture,
        private readonly int $crossingDurationSeconds = 86400,
        private readonly int $blackHoleTrapMinimumSeconds = 5400,
        private readonly int $blackHoleTrapMaximumSeconds = 10800,
        private readonly float $blackHoleMinimumMass = 3.0,
        private readonly float $blackHoleMaximumMass = 30.0,
    ) {}

    public function supports(AsteroidTrajectory $trajectory): bool
    {
        return $trajectory->mode === AsteroidTrajectory::MODE_SECTOR_TRANSFER
            && $trajectory->status === AsteroidTrajectory::STATUS_CROSSING_SECTOR;
    }

    public function handle(AsteroidTrajectory $trajectory, \DateTimeImmutable $now): AsteroidTrajectory
    {
        $direction = $trajectory->direction ?? throw new \LogicException('Transfer trajectory has no direction.');
        $nextSectorCoordinates = $trajectory->currentSector->add($direction['x'], $direction['y'], $direction['z']);
        $sector = $this->sectors->getOrCreateSector($nextSectorCoordinates);
        $crossing = $trajectory->sectorsCrossed + 1;
        $candidates = $this->candidates($sector);
        $capturedBy = $this->capture->resolve($trajectory->uid, $crossing, $nextSectorCoordinates->toKey(), $trajectory->capturePenaltySteps, $candidates);
        if ($capturedBy !== null) {
            return $this->captureAsteroid($trajectory, $sector, $capturedBy, $crossing, $now);
        }
        if ($crossing >= $trajectory->maximumSectorCrossings) {
            return $this->trajectories->transition($trajectory->id, [AsteroidTrajectory::STATUS_CROSSING_SECTOR], [
                'status' => AsteroidTrajectory::STATUS_LOST,
                'current_sector_x' => $nextSectorCoordinates->getX(),
                'current_sector_y' => $nextSectorCoordinates->getY(),
                'current_sector_z' => $nextSectorCoordinates->getZ(),
                'sectors_crossed' => $crossing,
                'capture_penalty_steps' => $trajectory->capturePenaltySteps + 1,
                'result' => 'crossing_limit_reached',
            ]);
        }

        $next = $now->modify('+' . max(1, $this->crossingDurationSeconds) . ' seconds')->format('c');
        $updated = $this->trajectories->transition($trajectory->id, [AsteroidTrajectory::STATUS_CROSSING_SECTOR], [
            'current_sector_x' => $nextSectorCoordinates->getX(),
            'current_sector_y' => $nextSectorCoordinates->getY(),
            'current_sector_z' => $nextSectorCoordinates->getZ(),
            'sectors_crossed' => $crossing,
            'capture_penalty_steps' => $trajectory->capturePenaltySteps + 1,
            'next_transition_at' => $next,
        ]);
        $this->events->schedule('asteroid.trajectory.phase', 'asteroid_trajectory', $updated->id, $next, [
            'trajectoryId' => $updated->id,
            'expectedStatus' => AsteroidTrajectory::STATUS_CROSSING_SECTOR,
            'expectedSectorsCrossed' => $updated->sectorsCrossed,
        ]);
        return $updated;
    }

    /** @return list<array{id:string,type:string,mass:float}> */
    private function candidates(SectorContent $sector): array
    {
        $candidates = [];
        foreach ($sector->getObjects() as $object) {
            if ($object instanceof BlackHole) {
                $candidates[] = ['id' => $object->getId(), 'type' => 'black_hole', 'mass' => $object->getMass() * 332946.0];
            } elseif ($object instanceof Star) {
                $candidates[] = ['id' => $object->getId(), 'type' => 'star', 'mass' => $object->getMass() * 332946.0];
            } elseif ($object instanceof Planet) {
                $candidates[] = ['id' => $object->getId(), 'type' => 'planet', 'mass' => $object->getMass()];
            } elseif ($object instanceof SolarSystem) {
                foreach ($object->getStars() as $star) {
                    $candidates[] = ['id' => $star->getId(), 'type' => 'star', 'mass' => $star->getMass() * 332946.0];
                }
                foreach ($object->getOrbitalBodies() as $body) {
                    if ($body->getObject() instanceof Planet) {
                        $candidates[] = ['id' => $body->getObject()->getId(), 'type' => 'planet', 'mass' => $body->getObject()->getMass()];
                    }
                }
            }
        }
        return $candidates;
    }

    private function captureAsteroid(AsteroidTrajectory $trajectory, SectorContent $sector, array $candidate, int $crossing, \DateTimeImmutable $now): AsteroidTrajectory
    {
        $asteroid = Asteroid::fromArray($trajectory->asteroidSnapshot);
        if ($sector->findObjectById($asteroid->getId()) !== null) {
            $asteroid = $asteroid->withId('mtr_' . bin2hex(random_bytes(12)));
        }
        $status = AsteroidTrajectory::STATUS_CAPTURED;
        $result = 'captured_by_' . $candidate['type'];
        $nextTransition = $trajectory->nextTransitionAt;
        if ($candidate['type'] === 'star') {
            $inserted = false;
            foreach ($sector->getObjects() as $object) {
                if (!$object instanceof SolarSystem) {
                    continue;
                }
                if (!in_array($candidate['id'], array_map(static fn(Star $star): string => $star->getId(), $object->getStars()), true)) {
                    continue;
                }
                $sector->replaceObject($object->withOrbitalBody(new OrbitingBody(
                    $asteroid,
                    new OrbitDescriptor(1.0, 0.05, 0.0, 365.25 / sqrt(max(0.05, $object->getPrimaryStar()->getMass())), 0.0),
                )));
                $inserted = true;
                break;
            }
            if (!$inserted) {
                $star = $sector->findObjectById($candidate['id']);
                if (!$star instanceof Star) {
                    throw new \LogicException('Selected stellar capture candidate is no longer present.');
                }
                $sector->removeObjectById($star->getId());
                $sector->addObject(new SolarSystem(
                    'sys_capture_' . substr(hash('sha256', $trajectory->uid . ':' . $star->getId()), 0, 20),
                    $star->getName(),
                    $star,
                    null,
                    [new OrbitingBody(
                        $asteroid,
                        new OrbitDescriptor(1.0, 0.05, 0.0, 365.25 / sqrt(max(0.05, $star->getMass())), 0.0),
                    )],
                    $star->getMass(),
                    1.0,
                    'Stellar system formed by asteroid capture.',
                ));
            }
        } elseif ($candidate['type'] === 'planet') {
            $asteroid = $asteroid->withCapturedByObjectId($candidate['id']);
            $sector->addObject($asteroid);
        } else {
            $sector->addObject($asteroid);
            $status = AsteroidTrajectory::STATUS_ORBITING_BLACK_HOLE;
            $hole = $sector->findObjectById($candidate['id']);
            $delay = $this->blackHoleDisappearanceDelay($hole instanceof BlackHole ? $hole->getMass() : $this->blackHoleMinimumMass);
            $nextTransition = $now->modify('+' . $delay . ' seconds')->format('c');
        }
        $this->restoreAttachments($sector, $trajectory->attachmentsSnapshot, $asteroid->getId());
        $this->sectors->saveSector($sector);
        $updated = $this->trajectories->transition($trajectory->id, [AsteroidTrajectory::STATUS_CROSSING_SECTOR], [
            'asteroid_id' => $asteroid->getId(),
            'asteroid_snapshot_json' => json_encode($asteroid->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'status' => $status,
            'current_sector_x' => $sector->getCoordinates()->getX(), 'current_sector_y' => $sector->getCoordinates()->getY(), 'current_sector_z' => $sector->getCoordinates()->getZ(),
            'sectors_crossed' => $crossing, 'next_transition_at' => $nextTransition, 'result' => $result,
        ]);
        if ($status === AsteroidTrajectory::STATUS_ORBITING_BLACK_HOLE) {
            $this->events->schedule('asteroid.trajectory.phase', 'asteroid_trajectory', $updated->id, $nextTransition, [
                'trajectoryId' => $updated->id, 'expectedStatus' => AsteroidTrajectory::STATUS_ORBITING_BLACK_HOLE,
            ]);
        }
        return $updated;
    }

    private function restoreAttachments(SectorContent $sector, array $snapshots, string $asteroidId): void
    {
        foreach ($snapshots as $snapshot) {
            if (!is_array($snapshot)) continue;
            $container = SectorDetachedContainer::fromArray($snapshot)->withTargetObjectId($asteroidId);
            match ($container->getMode()) {
                SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID => $sector->addHiddenDetachedContainer($container),
                SectorDetachedContainer::MODE_DROPPED_ON_PLANET => $sector->addPlanetDroppedContainer($container),
                default => $sector->addObject($container),
            };
        }
    }

    private function blackHoleDisappearanceDelay(float $mass): int
    {
        $ratio = max(0.0, min(1.0, ($mass - $this->blackHoleMinimumMass) / max(0.0001, $this->blackHoleMaximumMass - $this->blackHoleMinimumMass)));
        $trap = $this->blackHoleTrapMaximumSeconds - (($this->blackHoleTrapMaximumSeconds - $this->blackHoleTrapMinimumSeconds) * $ratio);
        return max(1, (int) round(2 * $trap));
    }
}
