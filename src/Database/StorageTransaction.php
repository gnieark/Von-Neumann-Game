<?php

declare(strict_types=1);

namespace VonNeumannGame\Database;

use PDO;
use PDOException;

/** The owner retries the entire unit of work; participants never commit or swallow errors. */
final class StorageTransaction
{
    public function __construct(private readonly PDO $pdo) {}

    public function run(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) { return $operation(); }
        for ($attempt = 0; ; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                // A write before decision reads upgrades SQLite's deferred transaction.
                if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $this->pdo->exec('UPDATE germination_depots SET version=version WHERE id=-1');
                }
                $result = $operation();
                $this->pdo->commit();
                return $result;
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                $code = $error instanceof PDOException ? (int) ($error->errorInfo[1] ?? 0) : 0;
                if (!in_array($code, [5, 6, 1205, 1213], true)) { throw $error; }
                if ($attempt >= 2) { throw new StorageBusyException('Storage transaction retries exhausted.', 0, $error); }
                usleep(10000 * ($attempt + 1));
            }
        }
    }

    /** Closed set of root locks shared by commands, workers and interruptions. */
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
