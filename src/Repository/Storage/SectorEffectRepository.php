<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Storage;

use PDO;
use VonNeumannGame\Sector\SectorCoordinates;

final class SectorEffectRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function inTransaction(): bool { return $this->pdo->inTransaction(); }

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
