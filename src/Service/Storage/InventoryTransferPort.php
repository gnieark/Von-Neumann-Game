<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Storage;

interface InventoryTransferPort
{
    public function items(array $ids): array;
    public function reserve(int $transferId, int $actionId, array $resources, array $items, string $now): void;
    public function reserveCapacity(int $transferId, float $space, string $now): void;
    public function debit(int $transferId, int $actionId, array $resources, array $itemIds, string $now): void;
    public function credit(array $resources, array $items, string $now): void;
    public function release(int $transferId, int $actionId, string $now): void;
}
