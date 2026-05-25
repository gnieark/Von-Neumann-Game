<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeInventoryItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $name,
        public readonly float $containerSpace,
        public readonly ?string $currentTask,
        public readonly float $taskProgressPercent,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'containerSpace' => $this->containerSpace,
            'currentTask' => $this->currentTask,
            'taskProgressPercent' => $this->taskProgressPercent,
        ];
    }

    public function taskArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'currentTask' => $this->currentTask,
            'taskProgressPercent' => $this->taskProgressPercent,
        ];
    }
}
