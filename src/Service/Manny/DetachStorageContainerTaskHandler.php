<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class DetachStorageContainerTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_DETACHING_STORAGE_CONTAINER];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshDetachStorageContainer';
    }
}
