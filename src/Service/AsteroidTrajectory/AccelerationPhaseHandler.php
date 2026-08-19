<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use VonNeumannGame\Domain\AsteroidTrajectory;
use VonNeumannGame\Repository\AsteroidTrajectoryRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;

final class AccelerationPhaseHandler implements PhaseHandlerInterface
{
    public function __construct(
        private readonly AsteroidTrajectoryRepository $trajectories,
        private readonly ScheduledEventRepository $events,
        private readonly int $coastingDurationSeconds = 600,
    ) {}

    public function supports(AsteroidTrajectory $trajectory): bool
    {
        return $trajectory->status === AsteroidTrajectory::STATUS_ACCELERATING;
    }

    public function handle(AsteroidTrajectory $trajectory, \DateTimeImmutable $now): AsteroidTrajectory
    {
        $next = $now->modify('+' . max(1, $this->coastingDurationSeconds) . ' seconds')->format('c');
        $updated = $this->trajectories->transition($trajectory->id, [AsteroidTrajectory::STATUS_ACCELERATING], [
            'status' => AsteroidTrajectory::STATUS_COASTING,
            'next_transition_at' => $next,
        ]);
        $this->events->schedule(
            'asteroid.trajectory.phase',
            'asteroid_trajectory',
            $updated->id,
            $next,
            ['trajectoryId' => $updated->id, 'expectedStatus' => AsteroidTrajectory::STATUS_COASTING],
        );

        return $updated;
    }
}
