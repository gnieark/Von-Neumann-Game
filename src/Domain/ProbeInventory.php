<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeInventory
{
    public const CAPACITY_UNIT = 'earth_container_equivalent';

    /**
     * @param array<ProbeInventoryItem> $items
     * @param array<ProbeExternalTank> $externalTanks
     */
    public function __construct(
        public readonly float $capacity,
        public readonly array $items,
        public readonly array $externalTanks,
    ) {}

    public static function defaultForProbe(NeumannProbe $probe): self
    {
        $items = [
            new ProbeInventoryItem(
                'probe-' . $probe->id . '-atomic-3d-printer',
                'atomic_3d_printer',
                'Imprimante 3D atomique',
                0.3,
                null,
                0,
            ),
        ];

        for ($i = 1; $i <= 4; $i++) {
            $items[] = new ProbeInventoryItem(
                'probe-' . $probe->id . '-manny-' . $i,
                'manny',
                'Manny ' . $i,
                0.05,
                null,
                0,
            );
        }

        $externalTanks = [
            new ProbeExternalTank(
                'probe-' . $probe->id . '-deuterium-tank',
                'deuterium',
                'Cuve externe de deutérium',
                100,
            ),
        ];

        return new self(1, $items, $externalTanks);
    }

    public function usedCapacity(): float
    {
        return round(array_reduce(
            $this->items,
            static fn(float $total, ProbeInventoryItem $item): float => $total + $item->containerSpace,
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
            'externalTanks' => array_map(
                static fn(ProbeExternalTank $tank): array => $tank->toArray(),
                $this->externalTanks,
            ),
        ];
    }
}
