<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeInventory
{
    public const CAPACITY_UNIT = 'earth_container_equivalent';

    /**
     * @param array<ProbeInventoryItem> $items
     * @param array<ProbeExternalTank> $externalTanks
     * @param array<array<string, mixed>> $resourceStocks
     * @param array<array<string, mixed>> $containers
     */
    public function __construct(
        public readonly float $capacity,
        public readonly array $items,
        public readonly array $externalTanks,
        public readonly array $resourceStocks = [],
        public readonly array $containers = [],
    ) {}

    public function usedCapacity(): float
    {
        return round(array_reduce(
            $this->items,
            static fn(float $total, ProbeInventoryItem $item): float => $total + $item->containerSpace,
            0.0,
        ) + array_reduce(
            $this->resourceStocks,
            static fn(float $total, array $stock): float => $total + (float) ($stock['containerSpace'] ?? 0),
            0.0,
        ), 4);
    }

    public function findItem(string $id): ?ProbeInventoryItem
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'capacity' => $this->capacity,
            'capacityUnit' => self::CAPACITY_UNIT,
            'usedCapacity' => $this->usedCapacity(),
            'freeCapacity' => round($this->capacity - $this->usedCapacity(), 4),
            'items' => array_map(
                static fn(ProbeInventoryItem $item): array => $item->toArray(),
                $this->items,
            ),
            'resourceStocks' => $this->resourceStocks,
            'containers' => $this->containers,
            'externalTanks' => array_map(
                static fn(ProbeExternalTank $tank): array => $tank->toArray(),
                $this->externalTanks,
            ),
        ];
    }
}
