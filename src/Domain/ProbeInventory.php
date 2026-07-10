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

    /**
     * @param array<Manny>|null $mannies
     * @param array<ProbeItem>|null $probeItems
     */
    public static function defaultForProbe(NeumannProbe $probe, ?array $mannies = null, ?array $probeItems = null): self
    {
        $items = [
            new ProbeInventoryItem(
                'probe-' . $probe->id . '-atomic-3d-printer',
                'atomic_3d_printer',
                'Atomic printer',
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
                    Manny::CONTAINER_SPACE,
                    null,
                    0,
                    ['type' => Manny::LOCATION_PROBE],
                    ['capacity' => Manny::CARGO_CAPACITY, 'deuterium' => 0.0, 'metals' => 0.0, 'ice' => 0.0, 'organicCompounds' => 0.0, 'capacityUnit' => self::CAPACITY_UNIT],
                );
            }
        } else {
            foreach ($mannies as $manny) {
                $items[] = new ProbeInventoryItem(
                    $manny->uid,
                    'manny',
                    $manny->name,
                    $manny->isOnProbe() ? Manny::CONTAINER_SPACE : 0.0,
                    $manny->currentTask,
                    $manny->taskProgressPercent(),
                    ['type' => $manny->locationType],
                    $manny->cargoArray(),
                );
            }
        }

        foreach ($probeItems ?? [] as $probeItem) {
            $items[] = $probeItem->inventoryItem();
        }

        $capacity = round(1.0 + array_reduce(
            $items,
            static fn(float $total, ProbeInventoryItem $item): float => $total + self::capacityBonusForItem($item),
            0.0,
        ), 4);

        $externalTanks = [
            new ProbeExternalTank(
                'probe-' . $probe->id . '-deuterium-tank',
                'deuterium',
                'External deuterium tank',
                $probe->deuteriumStock,
            ),
        ];

        $resourceStocks = [
            [
                'id' => 'probe-' . $probe->id . '-stock-metals',
                'type' => 'metals',
                'name' => 'Metals',
                'amount' => $probe->metalsStock,
                'containerSpace' => $probe->metalsStock,
                'capacityUnit' => self::CAPACITY_UNIT,
            ],
            [
                'id' => 'probe-' . $probe->id . '-stock-ice',
                'type' => 'ice',
                'name' => 'Ice',
                'amount' => $probe->iceStock,
                'containerSpace' => $probe->iceStock,
                'capacityUnit' => self::CAPACITY_UNIT,
            ],
            [
                'id' => 'probe-' . $probe->id . '-stock-organic-compounds',
                'type' => 'carbon_compounds',
                'name' => 'Carbon compounds',
                'amount' => $probe->organicCompoundsStock,
                'containerSpace' => $probe->organicCompoundsStock,
                'capacityUnit' => self::CAPACITY_UNIT,
            ],
        ];

        return new self($capacity, $items, $externalTanks, $resourceStocks);
    }

    private static function capacityBonusForItem(ProbeInventoryItem $item): float
    {
        $capacityBonus = $item->metadata['capacityBonus'] ?? 0.0;
        if (!is_numeric($capacityBonus)) {
            return 0.0;
        }

        return round(max(0.0, (float) $capacityBonus), 4);
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
            'containers' => $this->containers,
            'externalTanks' => array_map(
                static fn(ProbeExternalTank $tank): array => $tank->toArray(),
                $this->externalTanks,
            ),
        ];
    }
}
