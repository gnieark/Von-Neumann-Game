<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Storage;

use PDO;
use VonNeumannGame\Service\Storage\InventoryTransferPort;

final class InventoryTransferRepositoryFactory
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(string $kind, int|string $id, ?float $availableCapacity = null, ?int $probeId = null): InventoryTransferPort
    {
        return new SqlInventoryTransferRepository($this->pdo, $kind, $id, $availableCapacity, $probeId);
    }
}
