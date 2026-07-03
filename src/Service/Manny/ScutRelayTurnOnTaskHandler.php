<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class ScutRelayTurnOnTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_TURNING_ON_SCUT_RELAY];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshScutRelayTurnOn';
    }
}
