<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class ProbeTransferTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_TRANSFERRING_TO_PROBE];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshMannyProbeTransfer';
    }
}
