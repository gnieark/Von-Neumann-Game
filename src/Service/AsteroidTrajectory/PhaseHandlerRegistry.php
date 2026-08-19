<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use VonNeumannGame\Domain\AsteroidTrajectory;

final class PhaseHandlerRegistry
{
    /** @param list<PhaseHandlerInterface> $handlers */
    public function __construct(private readonly array $handlers) {}

    public function handle(AsteroidTrajectory $trajectory, \DateTimeImmutable $now): AsteroidTrajectory
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($trajectory)) {
                return $handler->handle($trajectory, $now);
            }
        }
        throw new \LogicException("No phase handler supports trajectory status '{$trajectory->status}'.");
    }
}
