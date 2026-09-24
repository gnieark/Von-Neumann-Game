<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Storage;

use PDO;
use VonNeumannGame\Sector\SectorCoordinates;

final class SectorEffectRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function afterCommit(callable $callback): void
    {
        \VonNeumannGame\Database\StorageTransaction::afterCommit($this->pdo, $callback);
    }

    public function inTransaction(): bool { return $this->pdo->inTransaction(); }

    public function withSectorLock(SectorCoordinates $sector, callable $operation): mixed
    {
        return (new \VonNeumannGame\Database\StorageTransaction($this->pdo, maxAttempts: 1))->run(function () use ($sector, $operation): mixed {
            $this->lockSector($sector);
            return $operation();
        });
    }

    public function lockSector(SectorCoordinates $sector): void
    {
        if (!$this->inTransaction()) { throw new \LogicException('Sector locks require a transaction.'); }
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $insert = $mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $coordinates = [$sector->getX(), $sector->getY(), $sector->getZ()];
        $this->pdo->prepare($insert . ' INTO sector_effect_locks(sector_x,sector_y,sector_z) VALUES(?,?,?)')->execute($coordinates);
        $statement = $this->pdo->prepare('SELECT sector_x FROM sector_effect_locks WHERE sector_x=? AND sector_y=? AND sector_z=?' . ($mysql ? ' FOR UPDATE' : ''));
        $statement->execute($coordinates);
        $statement->fetchColumn();
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function pendingInSector(SectorCoordinates $sector, ?int $throughId = null): \Generator
    {
        $cursor = 0;
        do {
            $sql = "SELECT * FROM sector_effects WHERE sector_x=? AND sector_y=? AND sector_z=? AND status='pending' AND id>?";
            $params = [$sector->getX(), $sector->getY(), $sector->getZ(), $cursor];
            if ($throughId !== null) { $sql .= ' AND id<=?'; $params[] = $throughId; }
            $statement = $this->pdo->prepare($sql . ' ORDER BY id LIMIT 100');
            $statement->execute($params);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) { $cursor = (int) $row['id']; yield $row; }
        } while (count($rows) === 100);
    }

    public function project(\VonNeumannGame\Sector\SectorContent $sector): void
    {
        foreach ($this->pendingInSector($sector->getCoordinates()) as $effect) {
            \VonNeumannGame\Sector\SectorEffect::apply($sector, $effect['operation_id'], $effect['effect_type'], $effect['object_id'], json_decode($effect['payload_json'], true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function create(string $operation, SectorCoordinates $sector, string $type, string $objectId, array $payload, string $now): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sector_effects(operation_id,sector_x,sector_y,sector_z,effect_type,object_id,payload_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$operation, $sector->getX(), $sector->getY(), $sector->getZ(), $type, $objectId, json_encode($payload, JSON_THROW_ON_ERROR), $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sector_effects WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function recordAttempt(int $id): void
    {
        $this->pdo->prepare("UPDATE sector_effects SET attempts=attempts+1 WHERE id=? AND status='pending'")->execute([$id]);
    }

    public function markApplied(int $id, string $now): void
    {
        $this->pdo->prepare("UPDATE sector_effects SET status='applied',last_error=NULL,updated_at=? WHERE id=? AND status='pending'")->execute([$now, $id]);
    }

    public function recordFailure(int $id, string $message, string $now): void
    {
        $this->pdo->prepare("UPDATE sector_effects SET last_error=?,updated_at=? WHERE id=? AND status='pending'")->execute([$message, $now, $id]);
    }
}
