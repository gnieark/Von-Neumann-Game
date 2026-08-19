<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use VonNeumannGame\Domain\AsteroidTrajectory;

interface PhaseHandlerInterface
{
    public function supports(AsteroidTrajectory $trajectory): bool;

    public function handle(AsteroidTrajectory $trajectory, \DateTimeImmutable $now): AsteroidTrajectory;
}
