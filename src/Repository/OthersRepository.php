<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use Throwable;
use VonNeumannGame\Sector\SectorCoordinates;

final class OthersRepository
{
    public const RESOURCE_TYPES = ['deuterium', 'metals', 'ice', 'carbon_compounds'];

    public function __construct(private readonly PDO $pdo) {}

    public function transaction(callable $operation): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function createAlert(int $playerId, string $shipPublicId, string $type, string $phase, string $eventKey, string $message): array
    {
        $existing = $this->findAlertByEventKey($playerId, $eventKey);
        if ($existing !== null) {
            return $existing;
        }
        $now = gmdate('c');
        $publicId = self::publicId('oalert');
        $stmt = $this->pdo->prepare(
            "INSERT INTO others_alerts (public_id,player_id,ship_public_id,type,status,phase,event_key,message,created_at,updated_at,read_at)
             VALUES (:public_id,:player_id,:ship_public_id,:type,'unread',:phase,:event_key,:message,:created_at,:updated_at,NULL)"
        );
        $stmt->execute([
            'public_id' => $publicId,
            'player_id' => $playerId,
            'ship_public_id' => $shipPublicId,
            'type' => $type,
            'phase' => $phase,
            'event_key' => $eventKey,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findAlertForPlayer($publicId, $playerId) ?? throw new \RuntimeException('Others alert creation failed.');
    }

    /** @return list<array<string, mixed>> */
    public function findAlertsForPlayer(int $playerId, bool $unreadOnly = false): array
    {
        $statusClause = $unreadOnly ? " AND status='unread'" : '';
        $stmt = $this->pdo->prepare('SELECT * FROM others_alerts WHERE player_id=:player_id' . $statusClause . ' ORDER BY created_at DESC,id DESC');
        $stmt->execute(['player_id' => $playerId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findAlertForPlayer(string $publicId, int $playerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM others_alerts WHERE public_id=:public_id AND player_id=:player_id');
        $stmt->execute(['public_id' => $publicId, 'player_id' => $playerId]);

        return $stmt->fetch() ?: null;
    }

    /** @param array<string, mixed> $alert
     *  @return array<string, mixed>
     */
    public function markAlertRead(array $alert): array
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare("UPDATE others_alerts SET status='read',read_at=COALESCE(read_at,:now),updated_at=:now WHERE id=:id");
        $stmt->execute(['now' => $now, 'id' => (int) $alert['id']]);

        return $this->findAlertForPlayer((string) $alert['public_id'], (int) $alert['player_id']) ?? throw new \RuntimeException('Others alert disappeared.');
    }

    /** @return array<string, mixed>|null */
    private function findAlertByEventKey(int $playerId, string $eventKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM others_alerts WHERE player_id=:player_id AND event_key=:event_key');
        $stmt->execute(['player_id' => $playerId, 'event_key' => $eventKey]);

        return $stmt->fetch() ?: null;
    }

    public function createFleet(int $playerId, int $x, int $y, int $z): array
    {
        return $this->transaction(function () use ($playerId, $x, $y, $z): array {
            $now = gmdate('c');
            $fleetId = self::publicId('fleet');
            $stmt = $this->pdo->prepare('INSERT INTO others_fleets (public_id, player_id, status, created_at, updated_at) VALUES (:public_id, :player_id, :status, :created_at, :updated_at)');
            $stmt->execute(['public_id' => $fleetId, 'player_id' => $playerId, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            $fleetSqlId = (int) $this->pdo->lastInsertId();
            $ship = $this->createShip($fleetSqlId, 'mothership', $x, $y, $z);
            $this->markFleetSectorVisited($fleetSqlId, new SectorCoordinates($x, $y, $z), $now);

            return ['id' => $fleetSqlId, 'public_id' => $fleetId, 'ship' => $ship];
        });
    }

    public function markFleetSectorVisited(int $fleetId, SectorCoordinates $coordinates, ?string $visitedAt = null): void
    {
        $visitedAt ??= gmdate('c');
        $params = [
            'fleet_id' => $fleetId,
            'x' => $coordinates->getX(),
            'y' => $coordinates->getY(),
            'z' => $coordinates->getZ(),
            'visited_at' => $visitedAt,
        ];
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO others_visited_sectors (fleet_id,sector_x,sector_y,sector_z,first_visited_at,last_visited_at,visit_count)
                 VALUES (:fleet_id,:x,:y,:z,:visited_at,:visited_at,1)
                 ON DUPLICATE KEY UPDATE
                    first_visited_at=LEAST(first_visited_at,VALUES(first_visited_at)),
                    last_visited_at=GREATEST(last_visited_at,VALUES(last_visited_at)),
                    visit_count=visit_count+1'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO others_visited_sectors (fleet_id,sector_x,sector_y,sector_z,first_visited_at,last_visited_at,visit_count)
                 VALUES (:fleet_id,:x,:y,:z,:visited_at,:visited_at,1)
                 ON CONFLICT(fleet_id,sector_x,sector_y,sector_z)
                 DO UPDATE SET
                    first_visited_at=MIN(others_visited_sectors.first_visited_at,excluded.first_visited_at),
                    last_visited_at=MAX(others_visited_sectors.last_visited_at,excluded.last_visited_at),
                    visit_count=others_visited_sectors.visit_count+1'
            );
        }
        $stmt->execute($params);
    }

    /** @return array{targetVisited:bool,visitedSectorCount:int} */
    public function fleetSectorKnowledge(int $fleetId, SectorCoordinates $coordinates): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                EXISTS(
                    SELECT 1 FROM others_visited_sectors
                    WHERE fleet_id=:target_fleet_id AND sector_x=:x AND sector_y=:y AND sector_z=:z
                ) AS target_visited,
                (SELECT COUNT(*) FROM others_visited_sectors WHERE fleet_id=:count_fleet_id) AS visited_sector_count'
        );
        $stmt->execute([
            'target_fleet_id' => $fleetId,
            'count_fleet_id' => $fleetId,
            'x' => $coordinates->getX(),
            'y' => $coordinates->getY(),
            'z' => $coordinates->getZ(),
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new \RuntimeException('Unable to read Others fleet sector knowledge.');
        }

        return [
            'targetVisited' => (bool) $row['target_visited'],
            'visitedSectorCount' => (int) $row['visited_sector_count'],
        ];
    }

    /**
     * @param array{lastVisitedAt:string,id:int}|null $cursor
     * @return array{rows:list<array<string,mixed>>,nextCursor:array{lastVisitedAt:string,id:int}|null}
     */
    public function findFleetVisitedSectorsPage(int $fleetId, ?array $cursor, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM others_visited_sectors WHERE fleet_id=:fleet_id';
        $params = ['fleet_id' => $fleetId];
        if ($cursor !== null) {
            $sql .= ' AND (last_visited_at < :cursor_time OR (last_visited_at = :cursor_time AND id < :cursor_id))';
            $params['cursor_time'] = $cursor['lastVisitedAt'];
            $params['cursor_id'] = $cursor['id'];
        }
        $sql .= ' ORDER BY last_visited_at DESC,id DESC LIMIT ' . ($limit + 1);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $last = $rows !== [] ? $rows[array_key_last($rows)] : null;

        return [
            'rows' => $rows,
            'nextCursor' => $hasMore && $last !== null
                ? ['lastVisitedAt' => (string) $last['last_visited_at'], 'id' => (int) $last['id']]
                : null,
        ];
    }

    public function createStandardShip(array $mothership): array
    {
        if (($mothership['type'] ?? null) !== 'mothership') {
            throw new \InvalidArgumentException('A mothership is required.');
        }
        return $this->transaction(fn(): array => $this->createShip(
            (int) $mothership['fleet_id'],
            'standard',
            (int) $mothership['sector_x'],
            (int) $mothership['sector_y'],
            (int) $mothership['sector_z'],
        ));
    }

    /** @return array<string, mixed> */
    public function fillShipDeuteriumTank(string $shipPublicId): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE others_ships
             SET deuterium_stock = deuterium_capacity, updated_at = :updated_at
             WHERE public_id = :public_id AND destroyed_at IS NULL'
        );
        $stmt->execute(['updated_at' => gmdate('c'), 'public_id' => $shipPublicId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Unable to fill Others ship deuterium tank.');
        }

        $ship = $this->findShipByPublicId($shipPublicId);
        if ($ship === null) {
            throw new \RuntimeException('Others ship disappeared after refueling.');
        }

        return $ship;
    }

    /**
     * @return array<string, int|string>
     */
    public function deleteFleetByMothershipPublicId(string $mothershipPublicId): array
    {
        return $this->transaction(function () use ($mothershipPublicId): array {
            $mothership = $this->findShipByPublicId($mothershipPublicId);
            if ($mothership === null || $mothership['type'] !== 'mothership') {
                throw new \InvalidArgumentException('Others mothership not found.');
            }

            $fleetId = (int) $mothership['fleet_id'];
            $shipRows = $this->fetchRowsByValues('others_ships', 'fleet_id', [$fleetId], 'id,public_id');
            $shipIds = array_map(static fn(array $row): int => (int) $row['id'], $shipRows);
            $shipPublicIds = array_map(static fn(array $row): string => (string) $row['public_id'], $shipRows);
            $auxiliaryRows = $this->fetchRowsByValues('others_auxiliaries', 'ship_id', $shipIds, 'id,public_id');
            $auxiliaryIds = array_map(static fn(array $row): int => (int) $row['id'], $auxiliaryRows);
            $auxiliaryPublicIds = array_map(static fn(array $row): string => (string) $row['public_id'], $auxiliaryRows);
            $actionRows = $this->fetchRowsByValues('others_actions', 'fleet_id', [$fleetId], 'id,public_id,scheduled_event_id');
            $actionIds = array_map(static fn(array $row): int => (int) $row['id'], $actionRows);
            $actionPublicIds = array_map(static fn(array $row): string => (string) $row['public_id'], $actionRows);

            $launchRowsById = [];
            foreach ($this->fetchRowsByValues('missile_launches', 'others_action_id', $actionIds, 'id,public_id,scheduled_event_id') as $row) {
                $launchRowsById[(int) $row['id']] = $row;
            }
            foreach ($this->fetchRowsByValues('missile_launches', 'launcher_public_id', $shipPublicIds, 'id,public_id,scheduled_event_id', "launcher_kind='others_ship'") as $row) {
                $launchRowsById[(int) $row['id']] = $row;
            }
            $launchRows = array_values($launchRowsById);
            $launchIds = array_map(static fn(array $row): int => (int) $row['id'], $launchRows);
            $launchPublicIds = array_map(static fn(array $row): string => (string) $row['public_id'], $launchRows);
            $projectileRows = $this->fetchRowsByValues('others_projectiles', 'launch_id', $launchIds, 'id,public_id');
            $projectileIds = array_map(static fn(array $row): int => (int) $row['id'], $projectileRows);

            $counts = [
                'scheduledEvents' => 0,
                'probeAlerts' => 0,
                'alerts' => 0,
                'idempotencyKeys' => 0,
                'damageEvents' => 0,
                'crossStoreOperations' => 0,
                'projectileHistory' => 0,
                'projectiles' => 0,
                'missileLaunches' => 0,
                'swarmParticipants' => 0,
                'movements' => 0,
                'inventoryTransfers' => 0,
                'crafts' => 0,
                'harvests' => 0,
                'laserLocks' => 0,
                'actions' => 0,
                'auxiliaries' => 0,
                'inventoryItems' => 0,
                'inventoryResources' => 0,
                'visitedSectors' => 0,
                'ships' => 0,
                'fleets' => 0,
            ];

            $scheduledEventIds = [];
            foreach ([...$actionRows, ...$launchRows] as $row) {
                if ($row['scheduled_event_id'] !== null) {
                    $scheduledEventIds[] = (int) $row['scheduled_event_id'];
                }
            }
            $counts['scheduledEvents'] += $this->deleteRowsByValues('scheduled_events', 'id', array_values(array_unique($scheduledEventIds)));
            $counts['scheduledEvents'] += $this->deleteScheduledEventsByEntity('others_action', $actionIds);
            $counts['scheduledEvents'] += $this->deleteScheduledEventsByEntity('missile_projectile', $projectileIds);

            $probeAlertKeys = [];
            foreach ($actionPublicIds as $actionPublicId) {
                $probeAlertKeys[] = 'others-arrival-' . $actionPublicId;
                $probeAlertKeys[] = 'weapon-' . $actionPublicId;
            }
            $othersAlertKeys = [];
            foreach ($launchPublicIds as $launchPublicId) {
                $probeAlertKeys[] = 'weapon-' . $launchPublicId;
                $probeAlertKeys[] = 'weapon-result-' . $launchPublicId;
                $probeAlertKeys[] = 'weapon-damage-' . $launchPublicId;
                $othersAlertKeys[] = 'weapon-result-' . $launchPublicId;
                $othersAlertKeys[] = 'weapon-damage-' . $launchPublicId;
            }
            $counts['probeAlerts'] += $this->deleteRowsByValues('probe_damage_warnings', 'object_id', array_values(array_unique($probeAlertKeys)));
            $counts['alerts'] += $this->deleteRowsByValues('others_alerts', 'ship_public_id', $shipPublicIds);
            $counts['alerts'] += $this->deleteRowsByValues('others_alerts', 'event_key', array_values(array_unique($othersAlertKeys)));
            $idempotencyKeyIds = $this->idempotencyKeyIdsForFleet(
                (int) $mothership['player_id'],
                (string) $mothership['fleet_public_id'],
                $shipPublicIds,
                $actionPublicIds,
            );
            $counts['idempotencyKeys'] += $this->deleteRowsByValues('others_idempotency_keys', 'id', $idempotencyKeyIds);

            $counts['damageEvents'] += $this->deleteRowsByValues('others_damage_events', 'target_public_id', [...$shipPublicIds, ...$auxiliaryPublicIds]);
            $damageEventIds = [];
            $actionPublicIdSet = array_fill_keys($actionPublicIds, true);
            $launchPublicIdSet = array_fill_keys($launchPublicIds, true);
            $damageCandidates = $this->pdo->query("SELECT id,event_key FROM others_damage_events WHERE event_key LIKE 'laser:%' OR event_key LIKE 'missile:%'")->fetchAll();
            foreach ($damageCandidates as $candidate) {
                $parts = explode(':', (string) $candidate['event_key']);
                if (($parts[0] ?? null) === 'laser' && isset($actionPublicIdSet[$parts[1] ?? ''])) {
                    $damageEventIds[] = (int) $candidate['id'];
                }
                if (($parts[0] ?? null) === 'missile' && isset($launchPublicIdSet[$parts[1] ?? ''])) {
                    $damageEventIds[] = (int) $candidate['id'];
                }
            }
            $counts['damageEvents'] += $this->deleteRowsByValues('others_damage_events', 'id', array_values(array_unique($damageEventIds)));

            $crossStoreOperationIds = $this->crossStoreOperationIdsForShips($shipPublicIds);
            $counts['crossStoreOperations'] += $this->deleteRowsByValues('others_cross_store_operations', 'action_id', $actionIds);
            $counts['crossStoreOperations'] += $this->deleteRowsByValues('others_cross_store_operations', 'id', $crossStoreOperationIds);
            $counts['projectileHistory'] += $this->deleteRowsByValues('others_projectile_history', 'projectile_public_id', $launchPublicIds);
            $counts['projectileHistory'] += $this->deleteRowsByValues('others_projectile_history', 'action_public_id', $actionPublicIds);
            $counts['projectiles'] += $this->deleteRowsByValues('others_projectiles', 'id', $projectileIds);
            $counts['missileLaunches'] += $this->deleteRowsByValues('missile_launches', 'id', $launchIds);
            $counts['swarmParticipants'] += $this->deleteRowsByValues('others_swarm_participants', 'action_id', $actionIds);
            $counts['movements'] += $this->deleteRowsByValues('others_movements', 'action_id', $actionIds);
            $counts['inventoryTransfers'] += $this->deleteRowsByValues('others_inventory_transfers', 'action_id', $actionIds);
            $counts['crafts'] += $this->deleteRowsByValues('others_crafts', 'action_id', $actionIds);
            $counts['harvests'] += $this->deleteRowsByValues('others_harvests', 'action_id', $actionIds);
            $counts['laserLocks'] += $this->deleteRowsByValues('others_laser_locks', 'action_id', $actionIds);
            $counts['actions'] += $this->deleteRowsByValues('others_actions', 'id', $actionIds);
            $counts['auxiliaries'] += $this->deleteRowsByValues('others_auxiliaries', 'id', $auxiliaryIds);
            $counts['inventoryItems'] += $this->deleteRowsByValues('others_inventory_items', 'ship_id', $shipIds);
            $counts['inventoryResources'] += $this->deleteRowsByValues('others_inventory_resources', 'ship_id', $shipIds);
            $counts['visitedSectors'] += $this->deleteRowsByValues('others_visited_sectors', 'fleet_id', [$fleetId]);
            $counts['ships'] += $this->deleteRowsByValues('others_ships', 'id', $shipIds);
            $counts['fleets'] += $this->deleteRowsByValues('others_fleets', 'id', [$fleetId]);

            return [
                'fleetId' => (string) $mothership['fleet_public_id'],
                'mothershipId' => $mothershipPublicId,
                'playerId' => (int) $mothership['player_id'],
            ] + $counts;
        });
    }

    /** @return list<array<string, mixed>> */
    private function fetchRowsByValues(string $table, string $column, array $values, string $selection = '*', string $extraWhere = ''): array
    {
        if ($values === []) {
            return [];
        }
        $rows = [];
        foreach (array_chunk(array_values(array_unique($values, SORT_REGULAR)), 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT {$selection} FROM {$table} WHERE {$column} IN ({$placeholders})";
            if ($extraWhere !== '') {
                $sql .= ' AND ' . $extraWhere;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($chunk);
            array_push($rows, ...$stmt->fetchAll());
        }
        return $rows;
    }

    private function deleteRowsByValues(string $table, string $column, array $values): int
    {
        if ($values === []) {
            return 0;
        }
        $deleted = 0;
        foreach (array_chunk(array_values(array_unique($values, SORT_REGULAR)), 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE {$column} IN ({$placeholders})");
            $stmt->execute($chunk);
            $deleted += $stmt->rowCount();
        }
        return $deleted;
    }

    private function deleteScheduledEventsByEntity(string $entityType, array $entityIds): int
    {
        if ($entityIds === []) {
            return 0;
        }
        $deleted = 0;
        foreach (array_chunk(array_values(array_unique($entityIds, SORT_REGULAR)), 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("DELETE FROM scheduled_events WHERE entity_type = ? AND entity_id IN ({$placeholders})");
            $stmt->execute([$entityType, ...$chunk]);
            $deleted += $stmt->rowCount();
        }
        return $deleted;
    }

    /** @return list<int> */
    private function crossStoreOperationIdsForShips(array $shipPublicIds): array
    {
        if ($shipPublicIds === []) {
            return [];
        }
        $shipPublicIdSet = array_fill_keys($shipPublicIds, true);
        $ids = [];
        $stmt = $this->pdo->query("SELECT id,payload_json FROM others_cross_store_operations WHERE operation_type IN ('dormant_auxiliaries','mothership_wreck')");
        foreach ($stmt->fetchAll() as $row) {
            $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \RuntimeException('Invalid Others cross-store operation payload.');
            }
            if (isset($shipPublicIdSet[(string) ($payload['shipId'] ?? '')])) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    /** @return list<int> */
    private function idempotencyKeyIdsForFleet(int $playerId, string $fleetPublicId, array $shipPublicIds, array $actionPublicIds): array
    {
        $entityPublicIdSet = array_fill_keys([$fleetPublicId, ...$shipPublicIds], true);
        $actionPublicIdSet = array_fill_keys($actionPublicIds, true);
        $ids = [];
        $stmt = $this->pdo->prepare('SELECT id,request_path,action_public_id FROM others_idempotency_keys WHERE player_id=:player_id');
        $stmt->execute(['player_id' => $playerId]);
        foreach ($stmt->fetchAll() as $row) {
            if (isset($actionPublicIdSet[(string) ($row['action_public_id'] ?? '')])) {
                $ids[] = (int) $row['id'];
                continue;
            }
            foreach (explode('/', trim((string) $row['request_path'], '/')) as $pathSegment) {
                if (isset($entityPublicIdSet[rawurldecode($pathSegment)])) {
                    $ids[] = (int) $row['id'];
                    break;
                }
            }
        }
        return $ids;
    }

    public function createAuxiliary(int $shipId): array
    {
        $now = gmdate('c');
        $publicId = self::publicId('aux');
        $stmt = $this->pdo->prepare(
            "INSERT INTO others_auxiliaries
             (public_id, ship_id, status, location_type, spatial_state, sector_x, sector_y, sector_z, object_id, current_action_id,
              cargo_deuterium, cargo_metals, cargo_ice, cargo_carbon_compounds, created_at, updated_at, destroyed_at)
             VALUES (:public_id, :ship_id, 'inactive', 'embarked', 'drifting', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, :created_at, :updated_at, NULL)"
        );
        $stmt->execute(['public_id' => $publicId, 'ship_id' => $shipId, 'created_at' => $now, 'updated_at' => $now]);

        return $this->findAuxiliaryByPublicId($publicId) ?? throw new \RuntimeException('Others auxiliary creation failed.');
    }

    public function reviveAuxiliary(int $shipId, string $publicId): array
    {
        $existing = $this->findAuxiliaryByPublicId($publicId);
        $now = gmdate('c');
        if ($existing !== null) {
            $stmt = $this->pdo->prepare("UPDATE others_auxiliaries SET ship_id = :ship_id, status = 'inactive', location_type = 'embarked', spatial_state = 'drifting', sector_x = NULL, sector_y = NULL, sector_z = NULL, object_id = NULL, current_action_id = NULL, destroyed_at = NULL, updated_at = :now WHERE id = :id");
            $stmt->execute(['ship_id' => $shipId, 'now' => $now, 'id' => (int) $existing['id']]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO others_auxiliaries (public_id,ship_id,status,location_type,spatial_state,sector_x,sector_y,sector_z,object_id,current_action_id,cargo_deuterium,cargo_metals,cargo_ice,cargo_carbon_compounds,created_at,updated_at,destroyed_at) VALUES (:public_id,:ship_id,'inactive','embarked','drifting',NULL,NULL,NULL,NULL,NULL,0,0,0,0,:now,:now,NULL)");
            $stmt->execute(['public_id' => $publicId, 'ship_id' => $shipId, 'now' => $now]);
        }
        return $this->findAuxiliaryByPublicId($publicId) ?? throw new \RuntimeException('Unable to revive Others auxiliary.');
    }

    public function findFleetForPlayer(string $publicId, int $playerId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM others_fleets WHERE public_id = :public_id AND player_id = :player_id AND status = 'active'");
        $stmt->execute(['public_id' => $publicId, 'player_id' => $playerId]);
        return $stmt->fetch() ?: null;
    }

    public function findFleetByPublicId(string $publicId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM others_fleets WHERE public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);
        return $stmt->fetch() ?: null;
    }

    public function findShipForPlayer(string $publicId, int $playerId, bool $activeOnly = true): ?array
    {
        $sql = 'SELECT s.*, f.public_id AS fleet_public_id, f.player_id,
                       m.phase AS movement_phase,
                       m.target_x AS movement_target_x,
                       m.target_y AS movement_target_y,
                       m.target_z AS movement_target_z,
                       m.arrive_at AS movement_arrive_at
                FROM others_ships s
                JOIN others_fleets f ON f.id = s.fleet_id
                LEFT JOIN others_movements m ON m.action_id = s.current_action_id
                WHERE s.public_id = :public_id AND f.player_id = :player_id';
        if ($activeOnly) {
            $sql .= " AND f.status = 'active' AND s.destroyed_at IS NULL AND s.status <> 'removed'";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['public_id' => $publicId, 'player_id' => $playerId]);
        return $stmt->fetch() ?: null;
    }

    public function findShipByPublicId(string $publicId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT s.*, f.public_id AS fleet_public_id, f.player_id FROM others_ships s JOIN others_fleets f ON f.id = s.fleet_id WHERE s.public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);
        return $stmt->fetch() ?: null;
    }

    public function findAuxiliaryForShip(string $publicId, int $shipId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM others_auxiliaries WHERE public_id = :public_id AND ship_id = :ship_id AND destroyed_at IS NULL');
        $stmt->execute(['public_id' => $publicId, 'ship_id' => $shipId]);
        return $stmt->fetch() ?: null;
    }

    public function findAuxiliaryByPublicId(string $publicId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM others_auxiliaries WHERE public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);
        return $stmt->fetch() ?: null;
    }

    public function findFleetSummariesByPlayerId(int $playerId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT f.*,
                    COALESCE(sc.ship_count, 0) AS ship_count,
                    COALESCE(sc.standard_ship_count, 0) AS standard_ship_count,
                    COALESCE(ax.auxiliary_count, 0) AS auxiliary_count,
                    COALESCE(ax.deployed_auxiliary_count, 0) AS deployed_auxiliary_count,
                    COALESCE(aa.active_action_count, 0) AS active_action_count
             FROM others_fleets f
             LEFT JOIN (
                 SELECT fleet_id, COUNT(*) AS ship_count,
                        SUM(CASE WHEN type = 'standard' THEN 1 ELSE 0 END) AS standard_ship_count
                 FROM others_ships WHERE destroyed_at IS NULL GROUP BY fleet_id
             ) sc ON sc.fleet_id = f.id
             LEFT JOIN (
                 SELECT s.fleet_id, COUNT(*) AS auxiliary_count,
                        SUM(CASE WHEN a.location_type <> 'embarked' THEN 1 ELSE 0 END) AS deployed_auxiliary_count
                 FROM others_auxiliaries a
                 JOIN others_ships s ON s.id = a.ship_id
                 WHERE a.destroyed_at IS NULL AND s.destroyed_at IS NULL
                 GROUP BY s.fleet_id
             ) ax ON ax.fleet_id = f.id
             LEFT JOIN (
                 SELECT fleet_id, COUNT(*) AS active_action_count
                 FROM others_actions WHERE status IN ('queued','running','cancel_requested') GROUP BY fleet_id
             ) aa ON aa.fleet_id = f.id
             WHERE f.player_id = :player_id AND f.status = 'active'
             ORDER BY f.public_id"
        );
        $stmt->execute(['player_id' => $playerId]);
        return $stmt->fetchAll();
    }

    public function findShipsByFleetId(int $fleetId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.*, MAX(f.public_id) AS fleet_public_id, COUNT(a.id) AS auxiliary_count,
                    SUM(CASE WHEN a.location_type <> 'embarked' AND a.destroyed_at IS NULL THEN 1 ELSE 0 END) AS deployed_auxiliary_count,
                    MAX(m.phase) AS movement_phase,
                    MAX(m.target_x) AS movement_target_x,
                    MAX(m.target_y) AS movement_target_y,
                    MAX(m.target_z) AS movement_target_z,
                    MAX(m.arrive_at) AS movement_arrive_at
             FROM others_ships s
             JOIN others_fleets f ON f.id = s.fleet_id
             LEFT JOIN others_auxiliaries a ON a.ship_id = s.id AND a.destroyed_at IS NULL
             LEFT JOIN others_movements m ON m.action_id = s.current_action_id
             WHERE s.fleet_id = :fleet_id AND s.destroyed_at IS NULL
             GROUP BY s.id ORDER BY CASE s.type WHEN 'mothership' THEN 0 ELSE 1 END, s.public_id"
        );
        $stmt->execute(['fleet_id' => $fleetId]);
        return $stmt->fetchAll();
    }

    public function findActiveShipsByFleetId(int $fleetId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM others_ships WHERE fleet_id = :fleet_id AND destroyed_at IS NULL AND status <> 'removed' ORDER BY public_id");
        $stmt->execute(['fleet_id' => $fleetId]);
        return $stmt->fetchAll();
    }

    public function observableEntitiesBySector(int $x, int $y, int $z): array
    {
        $ships = $this->pdo->prepare(
            "SELECT s.public_id, s.type, s.status,
                    m.target_x - m.source_x AS movement_direction_x,
                    m.target_y - m.source_y AS movement_direction_y,
                    m.target_z - m.source_z AS movement_direction_z
             FROM others_ships s
             LEFT JOIN others_movements m ON m.action_id = s.current_action_id
             WHERE s.sector_x = :x AND s.sector_y = :y AND s.sector_z = :z
               AND s.destroyed_at IS NULL AND s.status <> 'removed' AND s.status <> 'transit'
             ORDER BY s.public_id"
        );
        $ships->execute(['x' => $x, 'y' => $y, 'z' => $z]);
        return ['ships' => $ships->fetchAll(), 'projectiles' => $this->movingProjectilesBySector($x, $y, $z)];
    }

    /** @return list<array<string, mixed>> */
    public function movingProjectilesBySector(int $x, int $y, int $z): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT public_id, launcher_kind, target_public_id, target_kind, status, launched_at, impact_at
             FROM others_projectiles
             WHERE sector_x = :x AND sector_y = :y AND sector_z = :z AND status = 'moving'
             ORDER BY public_id"
        );
        $stmt->execute(['x' => $x, 'y' => $y, 'z' => $z]);

        return $stmt->fetchAll();
    }

    public function deployedAuxiliariesBySector(int $x, int $y, int $z): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.public_id, a.spatial_state, s.public_id AS carrier_public_id
             FROM others_auxiliaries a JOIN others_ships s ON s.id = a.ship_id
             WHERE a.sector_x = :x AND a.sector_y = :y AND a.sector_z = :z
               AND a.location_type = 'deployed' AND a.destroyed_at IS NULL AND a.status <> 'dormant'
             ORDER BY a.public_id"
        );
        $stmt->execute(['x' => $x, 'y' => $y, 'z' => $z]);
        return $stmt->fetchAll();
    }

    public function findAuxiliariesPageByShipId(int $shipId, ?string $cursor, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT a.*, ac.public_id AS action_public_id, ac.type AS action_type, ac.status AS action_status, ac.ends_at AS action_ends_at
                FROM others_auxiliaries a LEFT JOIN others_actions ac ON ac.id = a.current_action_id
                WHERE a.ship_id = :ship_id AND a.destroyed_at IS NULL';
        $params = ['ship_id' => $shipId];
        if ($cursor !== null) {
            $sql .= ' AND a.public_id > :cursor';
            $params['cursor'] = $cursor;
        }
        $sql .= ' ORDER BY a.public_id LIMIT ' . ($limit + 1);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $next = count($rows) > $limit ? (string) $rows[$limit - 1]['public_id'] : null;
        if ($next !== null) {
            array_pop($rows);
        }
        return ['rows' => $rows, 'nextCursor' => $next];
    }

    public function findActiveAuxiliaryTasksByFleetId(int $fleetId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ac.*, a.public_id AS auxiliary_public_id FROM others_actions ac
             JOIN others_auxiliaries a ON a.id = ac.auxiliary_id JOIN others_ships s ON s.id = a.ship_id
             WHERE s.fleet_id = :fleet_id AND ac.status IN ('queued','running') ORDER BY ac.id"
        );
        $stmt->execute(['fleet_id' => $fleetId]);
        return $stmt->fetchAll();
    }

    /**
     * Atomically selects and reserves exactly $count available auxiliaries.
     * An empty result means that the full requested swarm was unavailable.
     *
     * @return list<array<string,mixed>>
     */
    public function claimAvailableAuxiliaries(int $shipId, int $count, int $actionId): array
    {
        if ($count <= 0) {
            throw new \InvalidArgumentException('The auxiliary claim count must be positive.');
        }

        return $this->transaction(function () use ($shipId, $count, $actionId): array {
            $sql = "SELECT * FROM others_auxiliaries
                    WHERE ship_id = :ship_id AND current_action_id IS NULL
                      AND location_type = 'embarked' AND status IN ('inactive','available')
                      AND destroyed_at IS NULL
                    ORDER BY public_id LIMIT " . $count;
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $sql .= ' FOR UPDATE';
            }
            $select = $this->pdo->prepare($sql);
            $select->execute(['ship_id' => $shipId]);
            $auxiliaries = $select->fetchAll();
            if (count($auxiliaries) !== $count) {
                return [];
            }

            $parameters = ['action_id' => $actionId, 'now' => gmdate('c')];
            $placeholders = [];
            foreach ($auxiliaries as $index => $auxiliary) {
                $name = 'auxiliary_' . $index;
                $placeholders[] = ':' . $name;
                $parameters[$name] = (int) $auxiliary['id'];
            }
            $reserve = $this->pdo->prepare(
                "UPDATE others_auxiliaries SET status='busy',current_action_id=:action_id,updated_at=:now
                 WHERE current_action_id IS NULL AND id IN (" . implode(',', $placeholders) . ')'
            );
            $reserve->execute($parameters);
            if ($reserve->rowCount() !== $count) {
                throw new \RuntimeException('The auxiliary swarm reservation changed concurrently.');
            }

            return $auxiliaries;
        });
    }

    public function inventory(int $shipId): array
    {
        $resources = [];
        $stmt = $this->pdo->prepare('SELECT resource_type, amount, reserved_amount FROM others_inventory_resources WHERE ship_id = :ship_id ORDER BY resource_type');
        $stmt->execute(['ship_id' => $shipId]);
        foreach ($stmt->fetchAll() as $row) {
            $resources[(string) $row['resource_type']] = ['amount' => (float) $row['amount'], 'reserved' => (float) $row['reserved_amount']];
        }
        foreach (self::RESOURCE_TYPES as $type) {
            $resources[$type] ??= ['amount' => 0.0, 'reserved' => 0.0];
        }
        $stmt = $this->pdo->prepare('SELECT public_id, type, container_space FROM others_inventory_items WHERE ship_id = :ship_id AND reserved_action_id IS NULL ORDER BY public_id');
        $stmt->execute(['ship_id' => $shipId]);
        return ['resources' => $resources, 'items' => $stmt->fetchAll()];
    }

    public function inventoryUsage(int $shipId): float
    {
        $resources = $this->pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM others_inventory_resources WHERE ship_id = :ship_id');
        $resources->execute(['ship_id' => $shipId]);
        $items = $this->pdo->prepare('SELECT COALESCE(SUM(container_space), 0) FROM others_inventory_items WHERE ship_id = :ship_id');
        $items->execute(['ship_id' => $shipId]);
        return round((float) $resources->fetchColumn() + (float) $items->fetchColumn(), 4);
    }

    public function inventoryItemsByPublicIds(int $shipId, array $publicIds): array
    {
        if ($publicIds === []) { return []; }
        $placeholders = implode(',', array_fill(0, count($publicIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM others_inventory_items WHERE ship_id = ? AND public_id IN ($placeholders) ORDER BY public_id");
        $stmt->execute(array_merge([$shipId], array_values($publicIds)));
        return $stmt->fetchAll();
    }

    public function createAction(array $ship, string $type, string $actorKind, string $actorPublicId, array $payload, ?string $endsAt = null, ?string $cancelableUntil = null, ?int $auxiliaryId = null): array
    {
        $now = gmdate('c');
        $publicId = self::publicId('action');
        $stmt = $this->pdo->prepare(
            "INSERT INTO others_actions (public_id, fleet_id, ship_id, auxiliary_id, type, status, actor_kind, actor_public_id, payload_json, result_json, error_json, ends_at, cancelable_until, completed_at, scheduled_event_id, created_at, updated_at)
             VALUES (:public_id, :fleet_id, :ship_id, :auxiliary_id, :type, 'queued', :actor_kind, :actor_public_id, :payload_json, NULL, NULL, :ends_at, :cancelable_until, NULL, NULL, :created_at, :updated_at)"
        );
        $stmt->execute([
            'public_id' => $publicId, 'fleet_id' => (int) $ship['fleet_id'], 'ship_id' => (int) $ship['id'],
            'auxiliary_id' => $auxiliaryId, 'type' => $type, 'actor_kind' => $actorKind, 'actor_public_id' => $actorPublicId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'ends_at' => $endsAt,
            'cancelable_until' => $cancelableUntil, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $this->findActionByPublicId($publicId) ?? throw new \RuntimeException('Others action creation failed.');
    }

    public function findActionForPlayer(string $publicId, int $playerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT a.* FROM others_actions a JOIN others_fleets f ON f.id = a.fleet_id WHERE a.public_id = :public_id AND f.player_id = :player_id');
        $stmt->execute(['public_id' => $publicId, 'player_id' => $playerId]);
        return $stmt->fetch() ?: null;
    }

    public function findActionByPublicId(string $publicId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM others_actions WHERE public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);
        return $stmt->fetch() ?: null;
    }

    public function findInventoryTransferForPlayer(string $publicId, int $playerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT t.*, a.public_id AS action_public_id, a.status AS action_status, a.ends_at, a.result_json, a.error_json FROM others_inventory_transfers t JOIN others_actions a ON a.id = t.action_id JOIN others_fleets f ON f.id = a.fleet_id WHERE t.public_id = :public_id AND f.player_id = :player_id');
        $stmt->execute(['public_id' => $publicId, 'player_id' => $playerId]);
        return $stmt->fetch() ?: null;
    }

    public function findCraftForPlayer(string $publicId, int $playerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.*, a.public_id AS action_public_id, a.status AS action_status, a.ends_at, a.result_json, a.error_json FROM others_crafts c JOIN others_actions a ON a.id=c.action_id JOIN others_fleets f ON f.id=a.fleet_id WHERE c.public_id=:public_id AND f.player_id=:player_id');
        $stmt->execute(['public_id' => $publicId, 'player_id' => $playerId]); return $stmt->fetch() ?: null;
    }

    public function findCraftsByShipForPlayer(int $shipId, int $playerId): array
    {
        $stmt = $this->pdo->prepare('SELECT c.*, a.public_id AS action_public_id, a.status AS action_status, a.ends_at, a.result_json, a.error_json FROM others_crafts c JOIN others_actions a ON a.id=c.action_id JOIN others_fleets f ON f.id=a.fleet_id WHERE c.ship_id=:ship_id AND f.player_id=:player_id ORDER BY c.public_id');
        $stmt->execute(['ship_id' => $shipId, 'player_id' => $playerId]); return $stmt->fetchAll();
    }

    public function pdo(): PDO { return $this->pdo; }

    public static function publicId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(10));
    }

    private function createShip(int $fleetId, string $type, int $x, int $y, int $z): array
    {
        if (!in_array($type, ['mothership', 'standard'], true)) {
            throw new \InvalidArgumentException('Invalid Others ship type.');
        }
        $now = gmdate('c');
        $publicId = self::publicId($type === 'mothership' ? 'mother' : 'ship');
        $maxIntegrity = $type === 'mothership' ? 100 : 20;
        $fuelCapacity = $type === 'mothership' ? 1000.0 : 50.0;
        $inventoryCapacity = $type === 'mothership' ? 100000.0 : 10000.0;
        $stmt = $this->pdo->prepare(
            "INSERT INTO others_ships (public_id, fleet_id, type, status, sector_x, sector_y, sector_z, integrity, max_integrity, deuterium_stock, deuterium_capacity, inventory_capacity, inventory_reserved, current_action_id, departure_engaged, laser_next_target_at, entered_sector_at, created_at, updated_at, destroyed_at)
             VALUES (:public_id, :fleet_id, :type, 'inactive', :x, :y, :z, :integrity, :max_integrity, 0, :fuel_capacity, :inventory_capacity, 0, NULL, 0, NULL, :entered_sector_at, :created_at, :updated_at, NULL)"
        );
        $stmt->execute(['public_id' => $publicId, 'fleet_id' => $fleetId, 'type' => $type, 'x' => $x, 'y' => $y, 'z' => $z, 'integrity' => $maxIntegrity, 'max_integrity' => $maxIntegrity, 'fuel_capacity' => $fuelCapacity, 'inventory_capacity' => $inventoryCapacity, 'entered_sector_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $shipId = (int) $this->pdo->lastInsertId();
        foreach (self::RESOURCE_TYPES as $resourceType) {
            $resource = $this->pdo->prepare('INSERT INTO others_inventory_resources (ship_id, resource_type, amount, reserved_amount, updated_at) VALUES (:ship_id, :resource_type, 0, 0, :updated_at)');
            $resource->execute(['ship_id' => $shipId, 'resource_type' => $resourceType, 'updated_at' => $now]);
        }
        $this->createAuxiliary($shipId);
        return $this->findShipByPublicId($publicId) ?? throw new \RuntimeException('Others ship creation failed.');
    }
}
