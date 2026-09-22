<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Storage;

use PDO;

/** Business root locks. Callers acquire families in actor/action/inventory order and sort IDs. */
final class StorageLockRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function lock(string $kind, int|string $id): ?array
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Storage locks require a transaction.'); }
        [$table, $key] = match ($kind) {
            'ship' => ['others_ships', 'id'], 'auxiliary' => ['others_auxiliaries', 'id'],
            'probe' => ['neumann_probes', 'id'], 'manny' => ['mannies', 'id'],
            'action' => ['others_actions', 'id'], 'transfer' => ['sector_storage_transfers', 'id'],
            'depot' => ['germination_depots', 'id'], 'container' => ['storage_containers', 'id'],
            'detached' => ['detached_storage_containers', 'object_id'],
            'broadcast' => ['anomaly_broadcasts', 'id'],
            default => throw new \InvalidArgumentException('Unknown storage lock root.'),
        };
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if (!$mysql) {
            $stmt = $this->pdo->prepare("UPDATE $table SET $key=$key WHERE $key=?");
            $stmt->execute([$id]);
        }
        $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE $key=?" . ($mysql ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
