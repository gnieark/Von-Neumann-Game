<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class ProbeAssemblyTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_ASSEMBLING_PROBE];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshProbeAssembly';
    }
}
