<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class StorageMoveTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_MOVING_STORAGE];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshStorageMove';
    }
}
