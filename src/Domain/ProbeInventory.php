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
     */
    public function __construct(
        public readonly float $capacity,
        public readonly array $items,
        public readonly array $externalTanks,
        public readonly array $resourceStocks = [],
    ) {}

    /**
     * @param array<Manny>|null $mannies
     */
    public static function defaultForProbe(NeumannProbe $probe, ?array $mannies = null): self
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

        if ($mannies === null) {
            for ($i = 1; $i <= 4; $i++) {
                $items[] = new ProbeInventoryItem(
                    'probe-' . $probe->id . '-manny-' . $i,
                    'manny',
                    'manny-' . $i,
                    0.05,
                    null,
                    0,
                    ['type' => Manny::LOCATION_PROBE],
                    ['capacity' => 0.3, 'deuterium' => 0.0, 'metals' => 0.0, 'other' => 0.0, 'capacityUnit' => self::CAPACITY_UNIT],
                );
            }
        } else {
            foreach ($mannies as $manny) {
                $items[] = new ProbeInventoryItem(
                    $manny->uid,
                    'manny',
                    $manny->name,
                    $manny->isOnProbe() ? 0.05 : 0.0,
                    $manny->currentTask,
                    $manny->taskProgressPercent(),
                    ['type' => $manny->locationType],
                    $manny->cargoArray(),
                );
            }
        }

        $externalTanks = [
            new ProbeExternalTank(
                'probe-' . $probe->id . '-deuterium-tank',
                'deuterium',
                'Cuve externe de deutérium',
                $probe->deuteriumStock,
            ),
        ];

        $resourceStocks = [
            [
                'id' => 'probe-' . $probe->id . '-stock-metals',
                'type' => 'metals',
                'name' => 'Métaux',
                'amount' => $probe->metalsStock,
                'containerSpace' => $probe->metalsStock,
                'capacityUnit' => self::CAPACITY_UNIT,
            ],
            [
                'id' => 'probe-' . $probe->id . '-stock-other',
                'type' => 'other',
                'name' => 'Matériaux non métalliques',
                'amount' => $probe->otherStock,
                'containerSpace' => $probe->otherStock,
                'capacityUnit' => self::CAPACITY_UNIT,
            ],
        ];

        return new self(1, $items, $externalTanks, $resourceStocks);
    }

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
            'externalTanks' => array_map(
                static fn(ProbeExternalTank $tank): array => $tank->toArray(),
                $this->externalTanks,
            ),
        ];
    }
}
