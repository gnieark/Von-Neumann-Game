<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class WaitingForSpaceTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_WAITING_FOR_SPACE];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshWaitingForSpace';
    }
}
