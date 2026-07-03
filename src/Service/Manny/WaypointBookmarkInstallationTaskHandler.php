<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class WaypointBookmarkInstallationTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_INSTALLING_WAYPOINT_BOOKMARK];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshWaypointBookmarkInstallation';
    }
}
