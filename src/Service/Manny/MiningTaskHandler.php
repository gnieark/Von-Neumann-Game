<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class MiningTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_MINING];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshMining';
    }
}
