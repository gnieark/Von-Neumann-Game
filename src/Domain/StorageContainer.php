<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class StorageContainer
{
    public const KIND_PROBE = 'probe';
    public const KIND_CONTAINER = 'container';
    public const CORE_UID = 'probe-core';

    /**
     * @param array<string> $priorityFilter
     * @param array<string> $exclusionFilter
     * @param array<string> $strictExclusionFilter
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly int $probeId,
        public readonly string $kind,
        public string $label,
        public readonly int $sortOrder,
        public float $capacity,
        public array $priorityFilter,
        public array $exclusionFilter,
        public array $strictExclusionFilter,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}

    public function toArray(float $usedCapacity = 0.0): array
    {
        return [
            'id' => $this->uid,
            'kind' => $this->kind,
            'label' => $this->label,
            'sortOrder' => $this->sortOrder,
            'capacity' => $this->capacity,
            'usedCapacity' => round($usedCapacity, 4),
            'freeCapacity' => round(max(0.0, $this->capacity - $usedCapacity), 4),
            'capacityUnit' => ProbeInventory::CAPACITY_UNIT,
            'rules' => [
                'priority' => $this->priorityFilter,
                'exclusion' => $this->exclusionFilter,
                'strictExclusion' => $this->strictExclusionFilter,
            ],
        ];
    }
}
