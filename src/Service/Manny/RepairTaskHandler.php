<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class RepairTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_REPAIR];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshRepair';
    }
}
