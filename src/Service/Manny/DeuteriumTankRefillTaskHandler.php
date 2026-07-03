<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class DeuteriumTankRefillTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_REFILLING_DEUTERIUM_TANK];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshDeuteriumTankRefill';
    }
}
