<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Storage;

use PDO;

final class StorageReservationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function reserveProbeTank(int $transferId, int $probeId, float $amount): void
    {
        $this->pdo->prepare("INSERT INTO sector_storage_capacity_reservations(transfer_id,inventory_kind,inventory_id,amount) VALUES (?,'probe_tank',?,?)")
            ->execute([$transferId, (string) $probeId, $amount]);
    }

    public function reservedProbeTank(int $probeId, int $ignoredTransferId = 0): float
    {
        $query = $this->pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sector_storage_capacity_reservations WHERE inventory_kind='probe_tank' AND inventory_id=? AND transfer_id<>?");
        $query->execute([(string) $probeId, $ignoredTransferId]);
        return (float) $query->fetchColumn();
    }

    public function releaseProbeTank(int $transferId): void
    {
        $this->pdo->prepare("DELETE FROM sector_storage_capacity_reservations WHERE transfer_id=? AND inventory_kind='probe_tank'")->execute([$transferId]);
    }
}
