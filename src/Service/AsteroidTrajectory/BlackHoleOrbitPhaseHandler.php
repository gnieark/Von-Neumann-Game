<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use VonNeumannGame\Domain\AsteroidTrajectory;
use VonNeumannGame\Repository\AsteroidTrajectoryRepository;
use VonNeumannGame\Sector\SectorService;

final class BlackHoleOrbitPhaseHandler implements PhaseHandlerInterface
{
    public function __construct(private readonly AsteroidTrajectoryRepository $trajectories, private readonly SectorService $sectors) {}
    public function supports(AsteroidTrajectory $trajectory): bool { return $trajectory->status === AsteroidTrajectory::STATUS_ORBITING_BLACK_HOLE; }
    public function handle(AsteroidTrajectory $trajectory, \DateTimeImmutable $now): AsteroidTrajectory
    {
        $sector = $this->sectors->getOrCreateSector($trajectory->currentSector);
        $sector->removeObjectById($trajectory->asteroidId);
        $sector->removeContainersForObject($trajectory->asteroidId);
        $this->sectors->saveSector($sector);
        return $this->trajectories->transition($trajectory->id, [AsteroidTrajectory::STATUS_ORBITING_BLACK_HOLE], [
            'status' => AsteroidTrajectory::STATUS_LOST, 'result' => 'consumed_by_black_hole',
        ]);
    }
}
