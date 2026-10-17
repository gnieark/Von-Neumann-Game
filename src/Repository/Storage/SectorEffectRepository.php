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
        $coordinates = [$sector->getX(), $sector->getY(), $sector->getZ()];
        // INSERT IGNORE takes a shared duplicate-key lock in InnoDB. Two callers
        // then upgrading it with FOR UPDATE can deadlock on an existing sector.
        $insert = $mysql
            ? 'INSERT INTO sector_effect_locks(sector_x,sector_y,sector_z) VALUES(?,?,?) ON DUPLICATE KEY UPDATE sector_x=sector_x'
            : 'INSERT OR IGNORE INTO sector_effect_locks(sector_x,sector_y,sector_z) VALUES(?,?,?)';
        $this->pdo->prepare($insert)->execute($coordinates);
        $statement = $this->pdo->prepare('SELECT sector_x FROM sector_effect_locks WHERE sector_x=? AND sector_y=? AND sector_z=?' . ($mysql ? ' FOR UPDATE' : ''));
        $statement->execute($coordinates);
        $statement->fetchColumn();
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function pendingInSector(SectorCoordinates $sector, ?int $throughId = null, bool $currentRead = false): \Generator
    {
        $cursor = 0;
        do {
            $sql = "SELECT * FROM sector_effects WHERE sector_x=? AND sector_y=? AND sector_z=? AND status='pending' AND id>?";
            $params = [$sector->getX(), $sector->getY(), $sector->getZ(), $cursor];
            if ($throughId !== null) { $sql .= ' AND id<=?'; $params[] = $throughId; }
            $suffix = $currentRead && $this->inTransaction() && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->pdo->prepare($sql . ' ORDER BY id LIMIT 100' . $suffix);
            $statement->execute($params);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) { $cursor = (int) $row['id']; yield $row; }
        } while (count($rows) === 100);
    }

    public function project(\VonNeumannGame\Sector\SectorContent $sector, bool $includeMineables = true): void
    {
        foreach ($this->pendingInSector($sector->getCoordinates(), currentRead: $includeMineables) as $effect) {
            if ($effect['effect_type'] === 'create_sector') { continue; }
            $payload = json_decode($effect['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!$includeMineables) {
                $covered = false;
                \VonNeumannGame\Sector\MineableDescriptors::map($payload, static function (array $object) use (&$covered): array { $covered = true; return $object; });
                $object = $sector->findObjectById($effect['object_id']);
                if ($covered || ($object !== null && \VonNeumannGame\Sector\MineableDescriptors::covers($object->toArray()))) { continue; }
            }
            \VonNeumannGame\Sector\SectorEffect::apply($sector, $effect['operation_id'], $effect['effect_type'], $effect['object_id'], $payload);
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

    public function findByOperation(string $operation): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sector_effects WHERE operation_id=?');
        $stmt->execute([$operation]);
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
