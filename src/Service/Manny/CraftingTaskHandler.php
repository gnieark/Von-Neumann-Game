<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;

final class CraftingTaskHandler extends DelegatingTaskHandler
{
    protected function taskNames(): array
    {
        return [Manny::TASK_CRAFTING, Manny::TASK_ASSISTING_ATOMIC_PRINTER];
    }

    protected function runtimeMethod(): string
    {
        return 'refreshCrafting';
    }
}
