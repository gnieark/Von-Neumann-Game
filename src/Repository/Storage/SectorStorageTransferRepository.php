<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Storage;

use PDO;

final class SectorStorageTransferRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function createOthers(string $publicId, int $playerId, array $actor, array $ship, array $action, int $depotId, string $direction, array $plan, array $resources, array $items, string $startedAt, string $endsAt): int
    {
        $query = $this->pdo->prepare("INSERT INTO sector_storage_transfers(public_id,player_id,actor_kind,actor_public_id,others_ship_id,others_action_id,external_storage_kind,external_storage_id,direction,status,manifest_json,resources_json,items_json,started_at,ends_at,updated_at) VALUES(?,?,'others_auxiliary',?,?,?,'depot',?,?,'queued',?,?,?,?,?,?)");
        $query->execute([$publicId, $playerId, $actor['public_id'], $ship['id'], $action['id'], (string) $depotId, $direction,
            json_encode($plan, JSON_THROW_ON_ERROR), json_encode($resources, JSON_THROW_ON_ERROR), json_encode($items, JSON_THROW_ON_ERROR), $startedAt, $endsAt, $startedAt]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createManny(string $publicId, int $playerId, string $mannyUid, int $probeId, int $mannyId, string $externalKind, int|string $externalId, string $objectId, ?int $containerId, ?string $containerUid, string $direction, array $plan, array $resources, array $items, string $startedAt, string $endsAt): int
    {
        $query = $this->pdo->prepare("INSERT INTO sector_storage_transfers(public_id,player_id,actor_kind,actor_public_id,probe_id,manny_id,external_storage_kind,external_storage_id,object_public_id,container_id,container_public_id,direction,status,manifest_json,resources_json,items_json,started_at,ends_at,updated_at) VALUES(?,?,'manny',?,?,?,?,?,?,?,?,?,'queued',?,?,?,?,?,?)");
        $query->execute([$publicId, $playerId, $mannyUid, $probeId, $mannyId, $externalKind, (string) $externalId, $objectId, $containerId, $containerUid, $direction,
            json_encode($plan, JSON_THROW_ON_ERROR), json_encode($resources, JSON_THROW_ON_ERROR), json_encode($items, JSON_THROW_ON_ERROR), $startedAt, $endsAt, $startedAt]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findByActionId(int $actionId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM sector_storage_transfers WHERE others_action_id=?');
        $query->execute([$actionId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByPublicId(string $publicId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM sector_storage_transfers WHERE public_id=?');
        $query->execute([$publicId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findMannyForProbe(string $publicId, int $probeId, int $playerId): ?array
    {
        $query = $this->pdo->prepare("SELECT * FROM sector_storage_transfers WHERE public_id=? AND probe_id=? AND player_id=? AND actor_kind='manny'");
        $query->execute([$publicId, $probeId, $playerId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function actorId(string $publicId): ?int
    {
        $query = $this->pdo->prepare('SELECT id FROM others_auxiliaries WHERE public_id=?');
        $query->execute([$publicId]);
        $id = $query->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function finish(int $id, string $status, array $result, string $now): void
    {
        $query = $this->pdo->prepare("UPDATE sector_storage_transfers SET status=?,version=version+1,result_json=?,updated_at=? WHERE id=? AND status='queued'");
        $query->execute([$status, json_encode($result, JSON_THROW_ON_ERROR), $now, $id]);
        if ($query->rowCount() !== 1) { throw new \RuntimeException('Transfer terminal transition invariant violated.'); }
    }

    public function activeMannyPublicIds(string $root, int|string $id): array
    {
        $column = match ($root) {
            'probe' => 'probe_id', 'manny' => 'manny_id', 'object' => 'object_public_id',
            default => throw new \InvalidArgumentException('Unknown transfer root.'),
        };
        $query = $this->pdo->prepare("SELECT public_id FROM sector_storage_transfers WHERE $column=? AND actor_kind='manny' AND status='queued' ORDER BY id");
        $query->execute([$id]);
        return $query->fetchAll(PDO::FETCH_COLUMN);
    }
}
