<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class ProbeImprovementTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_IMPROVING_PROBE];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshProbeImprovement';
    }
}
