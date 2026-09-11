<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGerminationDepot;
use VonNeumannGame\Service\OthersActionException;

final class GerminationDepotRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(string $publicId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM germination_depots WHERE public_id=?');
        $query->execute([$publicId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<SectorGerminationDepot> */
    public function projections(SectorCoordinates $sector): array
    {
        $query = $this->pdo->prepare('SELECT public_id FROM germination_depots WHERE sector_x=? AND sector_y=? AND sector_z=? ORDER BY id');
        $query->execute([$sector->getX(), $sector->getY(), $sector->getZ()]);
        return array_map(static fn(string $id): SectorGerminationDepot => new SectorGerminationDepot($id), $query->fetchAll(PDO::FETCH_COLUMN));
    }

    public function pendingConsumedObjects(SectorCoordinates $sector): array
    {
        $query=$this->pdo->prepare("SELECT object_id FROM sector_effects WHERE sector_x=? AND sector_y=? AND sector_z=? AND status='pending' AND effect_type='consume_object'");
        $query->execute([$sector->getX(),$sector->getY(),$sector->getZ()]);
        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    public function knowledgeInSector(int $probeId, SectorCoordinates $sector): array
    {
        $query = $this->pdo->prepare('SELECT d.public_id,d.state,k.inspected_at,k.access_discovered_at FROM germination_depots d LEFT JOIN germination_depot_probe_knowledge k ON k.depot_id=d.id AND k.probe_id=? WHERE d.sector_x=? AND d.sector_y=? AND d.sector_z=? ORDER BY d.id');
        $query->execute([$probeId, $sector->getX(), $sector->getY(), $sector->getZ()]);
        $result = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $result[$row['public_id']] = $row; }
        return $result;
    }

    public function create(int $actionId, SectorCoordinates $sector, string $now): array
    {
        $publicId = 'structure_' . bin2hex(random_bytes(12));
        $query = $this->pdo->prepare('INSERT INTO germination_depots(public_id,sector_x,sector_y,sector_z,construction_action_id,created_at) VALUES(?,?,?,?,?,?)');
        $query->execute([$publicId, $sector->getX(), $sector->getY(), $sector->getZ(), $actionId, $now]);
        return $this->find($publicId) ?? throw new \RuntimeException('Depot creation failed.');
    }

    public function hasLocalShip(int $playerId, array $depot): bool
    {
        $query = $this->pdo->prepare("SELECT EXISTS(SELECT 1 FROM others_ships s JOIN others_fleets f ON f.id=s.fleet_id WHERE f.player_id=? AND s.sector_x=? AND s.sector_y=? AND s.sector_z=? AND s.status NOT IN ('transit','destroyed','removed') AND s.destroyed_at IS NULL)");
        $query->execute([$playerId, $depot['sector_x'], $depot['sector_y'], $depot['sector_z']]);
        return (bool) $query->fetchColumn();
    }

    /** Must run under the depot lock, including when reading multiple pages. */
    public function inventory(array $depot, int $limit = 100, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 500) { throw new OthersActionException(400, 'bad_request', 'limit must be between 1 and 500.'); }
        $after = 0;
        if ($cursor !== null) {
            try { $decoded = json_decode(base64_decode($cursor, true) ?: '', true, 16, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new OthersActionException(400, 'bad_request', 'Invalid inventory cursor.'); }
            if (!is_array($decoded) || array_keys($decoded) !== ['storage', 'version', 'after'] || $decoded['storage'] !== $depot['public_id'] || !is_int($decoded['version']) || !is_int($decoded['after']) || $decoded['after'] < 0) {
                throw new OthersActionException(400, 'bad_request', 'Invalid inventory cursor.');
            }
            if ($decoded['version'] !== (int) $depot['version']) { throw new OthersActionException(409, 'inventory_changed', 'The inventory changed; restart pagination.'); }
            $after = $decoded['after'];
        }
        $resources = $this->pdo->prepare('SELECT resource_type,amount,reserved_amount FROM germination_depot_resources WHERE depot_id=? ORDER BY resource_type');
        $resources->execute([$depot['id']]);
        $query = $this->pdo->prepare('SELECT id,public_id,type,name,container_space,metadata_json,reserved_transfer_id FROM germination_depot_items WHERE depot_id=? AND id>? ORDER BY id LIMIT ' . ($limit + 1));
        $query->execute([$depot['id'], $after]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        if ($more) { array_pop($rows); }
        return ['depotId' => $depot['public_id'], 'resources' => array_map(static fn(array $row): array => [
            'type' => $row['resource_type'], 'amount' => (float) $row['amount'], 'reservedAmount' => (float) $row['reserved_amount'],
            'availableAmount' => round((float) $row['amount'] - (float) $row['reserved_amount'], 4),
        ], $resources->fetchAll(PDO::FETCH_ASSOC)), 'items' => array_map(static fn(array $row): array => [
            'id' => $row['public_id'], 'type' => $row['type'], 'name' => $row['name'], 'containerSpace' => (float) $row['container_space'],
            'available' => $row['reserved_transfer_id'] === null, 'metadata' => json_decode($row['metadata_json'], true, 512, JSON_THROW_ON_ERROR) ?: new \stdClass(),
        ], $rows), 'nextCursor' => $more ? base64_encode(json_encode(['storage' => $depot['public_id'], 'version' => (int) $depot['version'], 'after' => (int) end($rows)['id']], JSON_THROW_ON_ERROR)) : null];
    }
}
