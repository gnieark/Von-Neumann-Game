<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class ReturningTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_RETURNING];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshReturning';
    }
}
