<?php
declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Domain\StorageContainer;

/** Transaction-local working inventory, created only after actor and container locks. */
final class CraftingBatchInventory
{
    /** @var array<string, array<string, ProbeItem>> */
    public array $itemsByType = [];

    /**
     * @param array{containers:array<StorageContainer>, used:array<int,float>, incoming:array<int,float>} $capacity
     * @param array<int, array<string,float>> $availableResources
     * @param list<ProbeItem> $consumableItems In the repository's canonical consumption order.
     * @param array<string,array<int,float>> $incomingItems Internal move reservations released if a component is consumed.
     */
    public function __construct(
        public readonly int $probeId,
        public array $capacity,
        public array $availableResources,
        array $consumableItems,
        private array $incomingItems,
    ) {
        foreach ($consumableItems as $item) { $this->itemsByType[$item->type][$item->uid] = $item; }
    }

    public function assertProbe(NeumannProbe $probe): void
    {
        if ($probe->id !== $this->probeId) { throw new \LogicException('Crafting batch inventory belongs to another probe.'); }
    }

    public function resourceStock(string $type): float
    {
        $total = 0.0;
        foreach ($this->availableResources as $resources) { $total += $resources[$type] ?? 0.0; }
        return round($total, 4);
    }

    /** @return array<int,float> */
    public function resourceDebits(string $type, float $amount): array
    {
        $remaining = round($amount, 4);
        $debits = [];
        foreach ($this->capacity['containers'] as $container) {
            $taken = round(min($this->availableResources[$container->id][$type] ?? 0.0, $remaining), 4);
            if ($taken > 0.0) { $debits[$container->id] = $taken; }
            $remaining = round($remaining - $taken, 4);
            if ($remaining <= 0.00001) { break; }
        }
        return $debits;
    }

    /** Reflect only successfully persisted resource debits. */
    public function consumeResources(string $type, array $debits): void
    {
        foreach ($debits as $id => $amount) {
            $this->availableResources[$id][$type] = round($this->availableResources[$id][$type] - $amount, 4);
            $this->capacity['used'][$id] = round($this->capacity['used'][$id] - $amount, 4);
        }
    }

    public function consumeItem(ProbeItem $item): void
    {
        unset($this->itemsByType[$item->type][$item->uid]);
        foreach ($this->incomingItems[$item->uid] ?? [] as $containerId => $space) {
            $this->capacity['incoming'][$containerId] = round($this->capacity['incoming'][$containerId] - $space, 4);
        }
        unset($this->incomingItems[$item->uid]);
        if ($item->storageContainerId !== null) {
            $id = $item->storageContainerId;
            $this->capacity['used'][$id] = round(($this->capacity['used'][$id] ?? 0.0) - max(0.0, $item->containerSpace), 4);
        }
    }

    public function reserveOutput(int $containerId, float $space): void
    {
        $this->capacity['used'][$containerId] = round(($this->capacity['used'][$containerId] ?? 0.0) + $space, 4);
    }
}
