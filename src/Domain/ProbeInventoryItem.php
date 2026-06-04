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
        public readonly ?array $location = null,
        public readonly ?array $cargo = null,
        public readonly array $metadata = [],
        public readonly ?array $container = null,
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
        ] + ($this->location !== null ? ['location' => $this->location] : [])
            + ($this->cargo !== null ? ['cargo' => $this->cargo] : [])
            + ($this->container !== null ? ['container' => $this->container] : [])
            + ($this->metadata !== [] ? ['metadata' => $this->metadata] : []);
    }

    public function taskArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'currentTask' => $this->currentTask,
            'taskProgressPercent' => $this->taskProgressPercent,
        ] + ($this->location !== null ? ['location' => $this->location] : [])
            + ($this->cargo !== null ? ['cargo' => $this->cargo] : [])
            + ($this->container !== null ? ['container' => $this->container] : [])
            + ($this->metadata !== [] ? ['metadata' => $this->metadata] : []);
    }
}
