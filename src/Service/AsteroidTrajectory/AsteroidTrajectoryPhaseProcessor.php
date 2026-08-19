<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use VonNeumannGame\Repository\AsteroidTrajectoryRepository;

final class AsteroidTrajectoryPhaseProcessor
{
    public function __construct(
        private readonly AsteroidTrajectoryRepository $trajectories,
        private readonly PhaseHandlerRegistry $handlers,
    ) {}

    public function process(
        int $trajectoryId,
        string $expectedStatus,
        ?int $expectedSectorsCrossed = null,
        ?\DateTimeImmutable $now = null,
    ): void
    {
        $this->trajectories->withLockedTrajectory($trajectoryId, function ($trajectory) use ($expectedStatus, $expectedSectorsCrossed, $now): void {
            if (
                $trajectory === null
                || $trajectory->status !== $expectedStatus
                || ($expectedSectorsCrossed !== null && $trajectory->sectorsCrossed !== $expectedSectorsCrossed)
            ) {
                return;
            }
            $this->handlers->handle($trajectory, $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        });
    }
}
