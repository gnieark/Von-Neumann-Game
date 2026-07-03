<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class InspectSectorObjectTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_INSPECTING_SECTOR_OBJECT, 'inspecting_asteroid'];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshInspectSectorObject';
    }
}
