<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\ScheduledEvent;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Domain\ProbeDamageWarning;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\DormantConstruct;

final class OthersService
{
    private const RECIPES = [
        'standard_ship' => ['duration' => 604800, 'ingredients' => ['metals' => 6000.0, 'ice' => 1000.0, 'carbon_compounds' => 2000.0, 'deuterium' => 100.0], 'outputSpace' => 0.0],
        'others_auxiliary' => ['duration' => 3600, 'ingredients' => ['metals' => 5.0, 'ice' => 0.5, 'carbon_compounds' => 1.0, 'deuterium' => 0.05], 'outputSpace' => 0.0],
        'missile' => ['duration' => 1800, 'ingredients' => ['metals' => 20.0, 'ice' => 2.0, 'carbon_compounds' => 5.0, 'deuterium' => 1.0], 'outputSpace' => 2.0],
    ];
    private readonly SectorGrid $grid;
    private readonly MovementDurationCalculator $durations;
    private readonly array $movementConfig;

    public function __construct(
        private readonly OthersRepository $others,
        private readonly ScheduledEventRepository $events,
        array $gameplayConfig = [],
        ?SectorGrid $grid = null,
        ?MovementDurationCalculator $durations = null,
        private readonly ?SectorService $sectors = null,
        private readonly ?NeumannProbeRepository $probes = null,
        private readonly ?ProbeDamageWarningRepository $alerts = null,
        private readonly ?MannyRepository $mannies = null,
        private readonly ?ProbeItemRepository $items = null,
        private readonly ?ScutNetworkService $scut = null,
        private readonly ?PlayerRepository $players = null,
    ) {
        $this->grid = $grid ?? new SectorGrid();
        $this->movementConfig = Config::getArray($gameplayConfig, 'movement', $gameplayConfig);
        $this->durations = $durations ?? new MovementDurationCalculator($this->movementConfig);
    }

    /** @return array{missile:array<string,mixed>,action:array<string,mixed>} */
    public function launchOthersMissile(array $ship, array $payload): array
    {
        $itemId = $payload['missileItemId'] ?? null;
        $targetId = $payload['targetId'] ?? null;
        if (!is_string($itemId) || $itemId === '' || !is_string($targetId) || $targetId === '') {
            throw new OthersActionException(400, 'bad_request', 'missileItemId and targetId are required.');
        }
        if ($ship['destroyed_at'] !== null || $ship['status'] === 'transit') {
            throw new OthersActionException(409, 'others_ship_busy', 'The firing ship is unavailable.');
        }
        $target = $this->resolveMissileTarget((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z'], $targetId);
        if ($target === null) {
            throw new OthersActionException(404, 'target_not_found', 'An admissible missile target was not found in this sector.');
        }

        return $this->others->transaction(function () use ($ship, $itemId, $target): array {
            $pdo = $this->others->pdo();
            $stmt = $pdo->prepare("SELECT * FROM others_inventory_items WHERE ship_id=:ship_id AND public_id=:public_id AND type='missile' AND reserved_action_id IS NULL");
            $stmt->execute(['ship_id' => (int) $ship['id'], 'public_id' => $itemId]);
            $item = $stmt->fetch();
            if (!$item) {
                throw new OthersActionException(404, 'missile_item_not_found', 'An available missile item was not found in this ship inventory.');
            }
            $now = gmdate('c');
            $action = $this->others->createAction($ship, 'missile_launch', 'others_ship', (string) $ship['public_id'], ['targetId' => $target['id'], 'targetKind' => $target['kind']]);
            $missileId = OthersRepository::publicId('missile');
            $pdo->prepare("INSERT INTO missile_launches (public_id,launcher_kind,launcher_public_id,player_id,probe_id,manny_id,probe_item_id,others_action_id,others_item_id,target_public_id,target_kind,sector_x,sector_y,sector_z,status,projectile_public_id,launch_at,impact_at,result,scheduled_event_id,created_at,updated_at) VALUES (:public_id,'others_ship',:launcher,:player_id,NULL,NULL,NULL,:action_id,:item_id,:target,:kind,:x,:y,:z,'queued',NULL,:launch_at,NULL,NULL,NULL,:created_at,:updated_at)")->execute([
                'public_id' => $missileId, 'launcher' => (string) $ship['public_id'], 'player_id' => (int) $ship['player_id'],
                'action_id' => (int) $action['id'], 'item_id' => (int) $item['id'], 'target' => $target['id'], 'kind' => $target['kind'],
                'x' => (int) $ship['sector_x'], 'y' => (int) $ship['sector_y'], 'z' => (int) $ship['sector_z'], 'launch_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $launchId = (int) $pdo->lastInsertId();
            $reserved = $pdo->prepare('UPDATE others_inventory_items SET reserved_action_id=:action_id,updated_at=:now WHERE id=:id AND reserved_action_id IS NULL');
            $reserved->execute(['action_id' => (int) $action['id'], 'now' => $now, 'id' => (int) $item['id']]);
            if ($reserved->rowCount() !== 1) { throw new OthersActionException(409, 'action_conflict', 'The missile item was reserved concurrently.'); }
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $now, ['expectedStatus' => 'queued']);
            $pdo->prepare('UPDATE others_actions SET scheduled_event_id=:event_id WHERE id=:id')->execute(['event_id' => $event->id, 'id' => (int) $action['id']]);
            $pdo->prepare('UPDATE missile_launches SET scheduled_event_id=:event_id WHERE id=:id')->execute(['event_id' => $event->id, 'id' => $launchId]);
            return ['missile' => $this->findMissileForPlayer($missileId, (int) $ship['player_id']) ?? [], 'action' => $this->others->findActionByPublicId((string) $action['public_id']) ?? $action];
        });
    }

    /** @return array<string,mixed> */
    public function prepareProbeMissile(NeumannProbe $probe, int $playerId, array $payload): array
    {
        $mannyId = $payload['actorMannyId'] ?? null; $itemId = $payload['missileItemId'] ?? null; $targetId = $payload['targetId'] ?? null;
        if (!is_string($mannyId) || $mannyId === '' || !is_string($itemId) || $itemId === '' || !is_string($targetId) || $targetId === '') {
            throw new OthersActionException(400, 'bad_request', 'actorMannyId, missileItemId and targetId are required.');
        }
        return $this->igniteProbeMissile($probe, $playerId, $mannyId, ['missileItemId' => $itemId, 'targetId' => $targetId]);
    }

    /** @return array<string,mixed> */
    public function igniteProbeMissile(NeumannProbe $probe, int $playerId, string $mannyId, array $payload): array
    {
        $itemIdProvided = array_key_exists('missileItemId', $payload);
        $itemId = $itemIdProvided ? $payload['missileItemId'] : null; $targetId = $payload['targetId'] ?? null;
        if (($itemIdProvided && (!is_string($itemId) || $itemId === '')) || !is_string($targetId) || $targetId === '') {
            throw new OthersActionException(400, 'bad_request', 'targetId is required and missileItemId must be a non-empty string when provided.');
        }
        if ($this->mannies === null || $this->items === null) { throw new OthersActionException(503, 'missile_service_unavailable', 'Missile preparation is unavailable.'); }
        $manny = $this->mannies->findByUidForProbe($probe->id, $mannyId);
        if ($manny === null || !$manny->isOnProbe()) { throw new OthersActionException(404, 'manny_not_found', 'An embarked Manny was not found.'); }
        if ($manny->currentTask !== null) { throw new OthersActionException(409, 'manny_busy', 'The Manny is already busy.'); }
        $target = $this->resolveMissileTarget($probe->currentSector->getX(), $probe->currentSector->getY(), $probe->currentSector->getZ(), $targetId);
        if ($target === null) { throw new OthersActionException(404, 'target_not_found', 'An admissible missile target was not found in this sector.'); }
        return $this->others->transaction(function () use ($probe, $playerId, $manny, $itemId, $target): array {
            $pdo = $this->others->pdo(); $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $launchAt = $now->modify('+1 minute');
            $lockSql = "SELECT pi.* FROM probe_items pi WHERE pi.probe_id=:probe_id AND pi.type='missile'";
            $parameters = ['probe_id' => $probe->id];
            if ($itemId !== null) {
                $lockSql .= ' AND pi.uid=:item_uid';
                $parameters['item_uid'] = $itemId;
            } else {
                $lockSql .= " AND NOT EXISTS (SELECT 1 FROM missile_launches ml WHERE ml.probe_item_id=pi.id AND ml.status IN ('preparing','queued')) ORDER BY pi.created_at ASC, pi.id ASC LIMIT 1";
            }
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') { $lockSql .= ' FOR UPDATE'; }
            $lock = $pdo->prepare($lockSql); $lock->execute($parameters); $item = $lock->fetch();
            if (!$item) { throw new OthersActionException(404, 'missile_item_not_found', 'An available missile item was not found in this probe inventory.'); }
            $check = $pdo->prepare("SELECT 1 FROM missile_launches WHERE probe_item_id=:item_id AND status IN ('preparing','queued') LIMIT 1"); $check->execute(['item_id' => (int) $item['id']]);
            if ($check->fetchColumn() !== false) { throw new OthersActionException(409, 'action_conflict', 'The missile item is already reserved.'); }
            $missileId = OthersRepository::publicId('missile');
            $pdo->prepare("INSERT INTO missile_launches (public_id,launcher_kind,launcher_public_id,player_id,probe_id,manny_id,probe_item_id,others_action_id,others_item_id,target_public_id,target_kind,sector_x,sector_y,sector_z,status,projectile_public_id,launch_at,impact_at,result,scheduled_event_id,created_at,updated_at) VALUES (:public_id,'probe',:launcher,:player_id,:probe_id,:manny_id,:item_id,NULL,NULL,:target,:kind,:x,:y,:z,'preparing',NULL,:launch_at,NULL,NULL,NULL,:created_at,:updated_at)")->execute([
                'public_id' => $missileId, 'launcher' => (string) $probe->id, 'player_id' => $playerId, 'probe_id' => $probe->id, 'manny_id' => $manny->id, 'item_id' => (int) $item['id'],
                'target' => $target['id'], 'kind' => $target['kind'], 'x' => $probe->currentSector->getX(), 'y' => $probe->currentSector->getY(), 'z' => $probe->currentSector->getZ(),
                'launch_at' => $launchAt->format('c'), 'created_at' => $now->format('c'), 'updated_at' => $now->format('c'),
            ]);
            $manny->currentTask = Manny::TASK_PREPARING_MISSILE; $manny->taskStartedAt = $now->format('c'); $manny->taskEndsAt = $launchAt->format('c');
            $manny->taskPayload = ['missileLaunchId' => $missileId, 'targetObjectId' => $target['id']];
            $this->mannies->save($manny);
            $pdo->prepare('UPDATE missile_launches SET scheduled_event_id=:event_id WHERE public_id=:public_id')->execute(['event_id' => $manny->taskScheduledEventId, 'public_id' => $missileId]);
            return $this->findMissileForPlayer($missileId, $playerId) ?? [];
        });
    }

    public function findMissileForPlayer(string $publicId, int $playerId): ?array
    {
        $stmt = $this->others->pdo()->prepare('SELECT l.*,a.public_id AS action_public_id,p.status AS projectile_status,p.launched_at,p.impact_at AS projectile_impact_at,h.result AS history_result,h.details_json,h.resolved_at FROM missile_launches l LEFT JOIN others_actions a ON a.id=l.others_action_id LEFT JOIN others_projectiles p ON p.launch_id=l.id LEFT JOIN others_projectile_history h ON h.projectile_public_id=l.public_id WHERE l.public_id=:public_id AND l.player_id=:player_id');
        $stmt->execute(['public_id' => $publicId, 'player_id' => $playerId]); return $stmt->fetch() ?: null;
    }

    public function moveShip(array $ship, array $payload, SectorCoordinates $homeSector): array
    {
        $target = $this->homeRelativeTarget($homeSector, $payload);
        return $this->moveShipToTarget($ship, $target, $payload, $homeSector);
    }

    private function moveShipToTarget(array $ship, SectorCoordinates $target, array $payload, SectorCoordinates $homeSector): array
    {
        $origin = new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']);
        $distance = $this->grid->getDistance($origin, $target);
        if ($distance === 0) {
            throw new OthersActionException(409, 'same_destination', 'The ship is already in the target sector.');
        }
        if ($distance > 10) {
            throw new OthersActionException(422, 'target_out_of_range', 'An Others ship cannot move more than ten sectors.');
        }
        if ($ship['current_action_id'] !== null || in_array((string) $ship['status'], ['transit', 'destroyed', 'removed'], true)) {
            throw new OthersActionException(409, 'others_ship_busy', 'The Others ship is busy.');
        }
        $fuelCost = round(Config::float($this->movementConfig, 'fuelCostPoints', 0.02) * 100, 4);
        if ((float) $ship['deuterium_stock'] < $fuelCost) {
            throw new OthersActionException(422, 'insufficient_resources', 'The Others ship does not have enough deuterium.');
        }
        $leaveBehind = $payload['leaveAuxiliariesBehind'] ?? false;
        if (!is_bool($leaveBehind)) {
            throw new OthersActionException(400, 'bad_request', 'leaveAuxiliariesBehind must be a boolean.');
        }

        return $this->others->transaction(function () use ($ship, $target, $origin, $distance, $fuelCost, $leaveBehind, $homeSector): array {
            $pdo = $this->others->pdo();
            $locked = $this->others->findShipByPublicId((string) $ship['public_id']);
            if ($locked === null || $locked['current_action_id'] !== null || (float) $locked['deuterium_stock'] < $fuelCost) {
                throw new OthersActionException(409, 'action_conflict', 'The ship state changed while accepting the command.');
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $cancelableUntil = $now->modify('+15 minutes');
            $timeline = $this->durations->timeline($cancelableUntil, $distance);
            $action = $this->others->createAction(
                $locked, 'ship_move', 'others_ship', (string) $locked['public_id'],
                ['target' => $target->subtract($homeSector), 'leaveAuxiliariesBehind' => $leaveBehind],
                $timeline['arrivalAt']->format('c'), $cancelableUntil->format('c'),
            );
            $stmt = $pdo->prepare(
                "INSERT INTO others_movements (action_id, ship_id, source_x, source_y, source_z, target_x, target_y, target_z, fuel_cost, leave_auxiliaries_behind, phase, depart_at, arrive_at, created_at, updated_at)
                 VALUES (:action_id, :ship_id, :source_x, :source_y, :source_z, :target_x, :target_y, :target_z, :fuel_cost, :leave_behind, 'waiting_to_depart', :depart_at, :arrive_at, :created_at, :updated_at)"
            );
            $stmt->execute([
                'action_id' => (int) $action['id'], 'ship_id' => (int) $locked['id'],
                'source_x' => $origin->getX(), 'source_y' => $origin->getY(), 'source_z' => $origin->getZ(),
                'target_x' => $target->getX(), 'target_y' => $target->getY(), 'target_z' => $target->getZ(),
                'fuel_cost' => $fuelCost, 'leave_behind' => $leaveBehind ? 1 : 0,
                'depart_at' => $cancelableUntil->format('c'), 'arrive_at' => $timeline['arrivalAt']->format('c'),
                'created_at' => $now->format('c'), 'updated_at' => $now->format('c'),
            ]);
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $cancelableUntil->format('c'), ['expectedStatus' => 'queued']);
            $update = $pdo->prepare("UPDATE others_ships SET deuterium_stock = deuterium_stock - :fuel, status = 'preparing', current_action_id = :action_id, departure_engaged = 1, updated_at = :updated_at WHERE id = :ship_id AND current_action_id IS NULL AND deuterium_stock >= :fuel");
            $update->execute(['fuel' => $fuelCost, 'action_id' => (int) $action['id'], 'updated_at' => $now->format('c'), 'ship_id' => (int) $locked['id']]);
            if ($update->rowCount() !== 1) {
                throw new OthersActionException(409, 'action_conflict', 'The ship state changed while accepting the command.');
            }
            $pdo->prepare("UPDATE others_actions SET status = 'canceled', completed_at = :now, updated_at = :now, error_json = :error WHERE auxiliary_id IN (SELECT id FROM others_auxiliaries WHERE ship_id = :ship_id AND location_type = 'deployed') AND status IN ('queued','running')")->execute(['now' => $now->format('c'), 'error' => json_encode(['code' => 'carrier_departure', 'message' => 'The carrier departure terminated the auxiliary task.'], JSON_THROW_ON_ERROR), 'ship_id' => (int) $locked['id']]);
            $pdo->prepare("UPDATE scheduled_events SET status = 'cancelled', processed_at = :now, updated_at = :now WHERE entity_type = 'others_action' AND entity_id IN (SELECT id FROM others_actions WHERE auxiliary_id IN (SELECT id FROM others_auxiliaries WHERE ship_id = :ship_id AND location_type = 'deployed')) AND status = 'pending'")->execute(['now' => $now->format('c'), 'ship_id' => (int) $locked['id']]);
            if (!$leaveBehind) {
                $pdo->prepare("UPDATE others_auxiliaries SET status = 'returning', spatial_state = 'returning_to_carrier', current_action_id = NULL, updated_at = :now WHERE ship_id = :ship_id AND location_type = 'deployed'")->execute(['now' => $now->format('c'), 'ship_id' => (int) $locked['id']]);
            }
            $pdo->prepare('UPDATE others_actions SET scheduled_event_id = :event_id WHERE id = :id')->execute(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    public function cancelMove(array $ship): array
    {
        if ($ship['current_action_id'] === null) {
            throw new OthersActionException(404, 'active_movement_not_found', 'No active movement was found for this ship.');
        }
        return $this->others->transaction(function () use ($ship): array {
            $pdo = $this->others->pdo();
            $stmt = $pdo->prepare('SELECT a.*, m.phase FROM others_actions a JOIN others_movements m ON m.action_id = a.id WHERE a.id = :id AND a.ship_id = :ship_id');
            $stmt->execute(['id' => (int) $ship['current_action_id'], 'ship_id' => (int) $ship['id']]);
            $action = $stmt->fetch();
            if (!$action || $action['status'] !== 'queued' || $action['phase'] !== 'waiting_to_depart') {
                throw new OthersActionException(409, 'movement_cancellation_window_closed', 'The movement can no longer be canceled.');
            }
            if ((string) $action['cancelable_until'] < gmdate('c')) {
                throw new OthersActionException(409, 'movement_cancellation_window_closed', 'The movement can no longer be canceled.');
            }
            $now = gmdate('c');
            $pdo->prepare("UPDATE others_actions SET status = 'cancel_requested', ends_at = :ends_at, updated_at = :updated_at WHERE id = :id AND status = 'queued'")->execute(['ends_at' => $now, 'updated_at' => $now, 'id' => (int) $action['id']]);
            if ($action['scheduled_event_id'] !== null) {
                $pdo->prepare("UPDATE scheduled_events SET run_at = :run_at, payload_json = :payload, updated_at = :updated_at WHERE id = :id AND status = 'pending'")->execute(['run_at' => $now, 'payload' => json_encode(['expectedStatus' => 'cancel_requested'], JSON_THROW_ON_ERROR), 'updated_at' => $now, 'id' => (int) $action['scheduled_event_id']]);
            }
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    public function moveFleet(array $fleet, array $payload, SectorCoordinates $homeSector): array
    {
        $ships = $this->others->findShipsByFleetId((int) $fleet['id']);
        $mothership = null;
        foreach ($ships as $ship) { if ($ship['type'] === 'mothership') { $mothership = $ship; break; } }
        if ($mothership === null) { throw new OthersActionException(409, 'others_mothership_required', 'The fleet has no active mothership.'); }
        $relative = $payload['target'] ?? null;
        if (!is_array($relative)) { throw new OthersActionException(400, 'bad_request', 'JSON body must contain target.'); }
        $absolute = $this->homeRelativeTarget($homeSector, $payload);
        $created = []; $ignored = []; $blocked = [];
        foreach ($ships as $ship) {
            try {
                $created[] = ['shipId' => (string) $ship['public_id'], 'action' => $this->moveShipToTarget($ship, $absolute, $payload, $homeSector)];
            } catch (OthersActionException $error) {
                if ($error->errorCode === 'same_destination') { $ignored[] = ['shipId' => (string) $ship['public_id'], 'reason' => 'already_at_destination']; }
                else { $blocked[] = ['shipId' => (string) $ship['public_id'], 'reason' => $error->errorCode]; }
            }
        }
        return ['created' => $created, 'ignored' => $ignored, 'blocked' => $blocked];
    }

    public function createInventoryTransfer(array $source, array $payload): array
    {
        foreach (['actorAuxiliaryId', 'targetShipId', 'kind'] as $field) {
            if (!is_string($payload[$field] ?? null) || $payload[$field] === '') { throw new OthersActionException(400, 'bad_request', $field . ' is required.'); }
        }
        $target = $this->others->findShipByPublicId($payload['targetShipId']);
        $auxiliary = $this->others->findAuxiliaryForShip($payload['actorAuxiliaryId'], (int) $source['id']);
        if ($target === null || (int) $target['player_id'] !== (int) $source['player_id']) { throw new OthersActionException(404, 'others_ship_not_found', 'Target Others ship not found.'); }
        if ($auxiliary === null) { throw new OthersActionException(404, 'others_auxiliary_not_found', 'Actor auxiliary not found.'); }
        if ($auxiliary['current_action_id'] !== null || !in_array((string) $auxiliary['status'], ['inactive', 'available'], true) || $auxiliary['location_type'] !== 'embarked') { throw new OthersActionException(409, 'others_auxiliary_busy', 'The actor auxiliary is not available and embarked.'); }
        if (!$this->sameSector($source, $target)) { throw new OthersActionException(422, 'target_out_of_range', 'Both ships must be in the same sector.'); }
        $kind = (string) $payload['kind']; $resourceType = null; $amount = null; $items = []; $space = 0.0; $durationUnits = 0;
        if ($kind === 'resource') {
            $resourceType = $payload['resourceType'] ?? null; $amount = $payload['amount'] ?? null;
            if (!is_string($resourceType) || !in_array($resourceType, OthersRepository::RESOURCE_TYPES, true) || !is_numeric($amount) || (float) $amount <= 0.0) { throw new OthersActionException(400, 'bad_request', 'A canonical resourceType and a positive amount are required.'); }
            $amount = round((float) $amount, 4); $space = $amount; $durationUnits = (int) ceil($amount / 0.05);
        } elseif ($kind === 'item') {
            $itemIds = $payload['itemIds'] ?? null;
            if (!is_array($itemIds) || $itemIds === [] || count(array_unique($itemIds)) !== count($itemIds) || array_filter($itemIds, 'is_string') !== $itemIds) { throw new OthersActionException(400, 'bad_request', 'itemIds must be a non-empty list of unique item identifiers.'); }
            $items = $this->others->inventoryItemsByPublicIds((int) $source['id'], $itemIds);
            if (count($items) !== count($itemIds)) { throw new OthersActionException(422, 'insufficient_resources', 'One or more inventory items are unavailable.'); }
            foreach ($items as $item) { if ($item['reserved_action_id'] !== null || (float) $item['container_space'] > 2.0) { throw new OthersActionException(422, 'insufficient_resources', 'Every item must be available and fit in the auxiliary hold.'); } $space += (float) $item['container_space']; }
            $durationUnits = count($items);
        } else { throw new OthersActionException(400, 'bad_request', 'kind must be resource or item.'); }
        if ($this->others->inventoryUsage((int) $target['id']) + (float) $target['inventory_reserved'] + $space > (float) $target['inventory_capacity'] + 0.00001) { throw new OthersActionException(422, 'insufficient_resources', 'The target inventory has insufficient capacity.'); }

        return $this->others->transaction(function () use ($source, $target, $auxiliary, $kind, $resourceType, $amount, $items, $space, $durationUnits): array {
            $pdo = $this->others->pdo(); $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $endsAt = $now->modify('+' . max(10, $durationUnits * 10) . ' seconds');
            if ($kind === 'resource') {
                $reserve = $pdo->prepare('UPDATE others_inventory_resources SET reserved_amount = reserved_amount + CAST(:amount AS REAL), updated_at = :now WHERE ship_id = :ship_id AND resource_type = :resource_type AND amount - reserved_amount >= CAST(:amount AS REAL)');
                $reserve->execute(['amount' => $amount, 'now' => $now->format('c'), 'ship_id' => (int) $source['id'], 'resource_type' => $resourceType]);
                if ($reserve->rowCount() !== 1) { throw new OthersActionException(422, 'insufficient_resources', 'The source inventory amount is unavailable.'); }
            }
            $action = $this->others->createAction($source, 'inventory_transfer', 'others_auxiliary', (string) $auxiliary['public_id'], ['targetShipId' => $target['public_id'], 'kind' => $kind, 'resourceType' => $resourceType, 'amount' => $amount, 'itemIds' => array_column($items, 'public_id')], $endsAt->format('c'), auxiliaryId: (int) $auxiliary['id']);
            if ($kind === 'item') {
                foreach ($items as $item) { $pdo->prepare('UPDATE others_inventory_items SET reserved_action_id = :action_id, updated_at = :now WHERE id = :id AND reserved_action_id IS NULL')->execute(['action_id' => (int) $action['id'], 'now' => $now->format('c'), 'id' => (int) $item['id']]); }
            }
            $publicId = OthersRepository::publicId('transfer');
            $pdo->prepare("INSERT INTO others_inventory_transfers (public_id, action_id, source_ship_id, target_ship_id, auxiliary_id, kind, resource_type, amount, item_ids_json, status, created_at, updated_at) VALUES (:public_id,:action_id,:source,:target,:aux,:kind,:resource_type,:amount,:items,'queued',:now,:now)")->execute(['public_id' => $publicId, 'action_id' => (int) $action['id'], 'source' => (int) $source['id'], 'target' => (int) $target['id'], 'aux' => (int) $auxiliary['id'], 'kind' => $kind, 'resource_type' => $resourceType, 'amount' => $amount, 'items' => json_encode(array_column($items, 'public_id'), JSON_THROW_ON_ERROR), 'now' => $now->format('c')]);
            $pdo->prepare('UPDATE others_ships SET inventory_reserved = inventory_reserved + :space, updated_at = :now WHERE id = :id')->execute(['space' => $space, 'now' => $now->format('c'), 'id' => (int) $target['id']]);
            $pdo->prepare("UPDATE others_auxiliaries SET status = 'busy', current_action_id = :action_id, updated_at = :now WHERE id = :id AND current_action_id IS NULL")->execute(['action_id' => (int) $action['id'], 'now' => $now->format('c'), 'id' => (int) $auxiliary['id']]);
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $endsAt->format('c'), ['expectedStatus' => 'queued']);
            $pdo->prepare('UPDATE others_actions SET scheduled_event_id = :event_id WHERE id = :id')->execute(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return ['transfer' => $this->others->findInventoryTransferForPlayer($publicId, (int) $source['player_id']), 'action' => $this->others->findActionByPublicId((string) $action['public_id'])];
        });
    }

    public function transferDeuterium(array $source, array $auxiliary, array $payload): array
    {
        $targetId = $payload['targetShipId'] ?? null; $amount = $payload['amount'] ?? null;
        if (!is_string($targetId) || !is_numeric($amount) || (float) $amount <= 0.0) { throw new OthersActionException(400, 'bad_request', 'targetShipId and a positive amount are required.'); }
        $target = $this->others->findShipByPublicId($targetId); $amount = round((float) $amount, 4);
        if ($target === null || (int) $target['player_id'] !== (int) $source['player_id']) { throw new OthersActionException(404, 'others_ship_not_found', 'Target Others ship not found.'); }
        if (!$this->sameSector($source, $target)) { throw new OthersActionException(422, 'target_out_of_range', 'Both ships must be in the same sector.'); }
        if ($auxiliary['current_action_id'] !== null || $auxiliary['location_type'] !== 'embarked') { throw new OthersActionException(409, 'others_auxiliary_busy', 'The auxiliary is busy.'); }
        if ((float) $source['deuterium_stock'] - (float) $source['deuterium_reserved'] < $amount || (float) $target['deuterium_stock'] + (float) $target['deuterium_reserved'] + $amount > (float) $target['deuterium_capacity']) { throw new OthersActionException(422, 'insufficient_resources', 'Source stock or target tank capacity is insufficient.'); }
        return $this->others->transaction(function () use ($source, $target, $auxiliary, $amount): array {
            $pdo = $this->others->pdo(); $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $ends = $now->modify('+5 minutes');
            $action = $this->others->createAction($source, 'deuterium_transfer', 'others_auxiliary', (string) $auxiliary['public_id'], ['targetShipId' => $target['public_id'], 'amount' => $amount], $ends->format('c'), auxiliaryId: (int) $auxiliary['id']);
            $pdo->prepare('UPDATE others_ships SET deuterium_reserved = deuterium_reserved + :amount, updated_at = :now WHERE id IN (:source, :target)')->execute(['amount' => $amount, 'now' => $now->format('c'), 'source' => (int) $source['id'], 'target' => (int) $target['id']]);
            $pdo->prepare("UPDATE others_auxiliaries SET status = 'busy', current_action_id = :action_id, updated_at = :now WHERE id = :id AND current_action_id IS NULL")->execute(['action_id' => (int) $action['id'], 'now' => $now->format('c'), 'id' => (int) $auxiliary['id']]);
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $ends->format('c'), ['expectedStatus' => 'queued']);
            $pdo->prepare('UPDATE others_actions SET scheduled_event_id = :event_id WHERE id = :id')->execute(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    public function startAuxiliaryTask(array $ship, array $auxiliary, string $task, array $payload): array
    {
        return match ($task) {
            'transfer-deuterium' => $this->transferDeuterium($ship, $auxiliary, $payload),
            'mine' => $this->startAuxiliaryMining($ship, $auxiliary, $payload),
            'recall' => $this->startAuxiliaryRecall($ship, $auxiliary, $payload),
            'recover-dormant-auxiliary' => $this->startDormantRecovery($ship, $auxiliary, $payload),
            default => throw new OthersActionException(400, 'bad_request', 'Unsupported Others auxiliary task.'),
        };
    }

    public function startHarvest(array $ship, array $payload): array
    {
        if ($this->sectors === null) { throw new OthersActionException(503, 'others_sector_unavailable', 'Sector storage is unavailable.'); }
        $targetId = $payload['targetObjectId'] ?? null; $count = $payload['auxiliaryCount'] ?? null;
        if (!is_string($targetId) || !is_int($count) || $count <= 0) { throw new OthersActionException(400, 'bad_request', 'targetObjectId and a positive integer auxiliaryCount are required.'); }
        if ($ship['current_action_id'] !== null || in_array((string) $ship['status'], ['transit', 'destroyed', 'removed'], true)) { throw new OthersActionException(409, 'others_ship_busy', 'The ship is busy.'); }
        $coordinates = new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']);
        $planet = $this->sectors->getOrCreateSector($coordinates)->findObjectById($targetId);
        if (!$planet instanceof Planet) { throw new OthersActionException(404, 'target_not_found', 'Harvest target planet not found.'); }
        $capacity = 2.0 * $count;
        if ($this->others->inventoryUsage((int) $ship['id']) + (float) $ship['inventory_reserved'] + $capacity > (float) $ship['inventory_capacity'] + 0.00001) { throw new OthersActionException(422, 'insufficient_resources', 'The coordinator inventory has insufficient capacity.'); }
        return $this->others->transaction(function () use ($ship, $planet, $count, $capacity): array {
            $pdo = $this->others->pdo(); $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $lifePhase = $planet->hasIntelligentLife();
            $duration = $lifePhase ? (int) ceil(86400 * 100 / $count) : 600;
            $ends = $now->modify('+' . $duration . ' seconds');
            $action = $this->others->createAction($ship, 'planet_harvest', 'others_ship', (string) $ship['public_id'], ['targetObjectId' => $planet->getId(), 'auxiliaryCount' => $count], $ends->format('c'));
            $auxiliaries = $this->others->claimAvailableAuxiliaries((int) $ship['id'], $count, (int) $action['id']);
            if ($auxiliaries === []) { throw new OthersActionException(422, 'insufficient_resources', 'Not enough available auxiliaries for this swarm.'); }
            $participantValues = [];
            $participantParameters = [];
            foreach ($auxiliaries as $index => $auxiliary) {
                $participantValues[] = "(:action_$index,:auxiliary_$index,:created_$index)";
                $participantParameters["action_$index"] = (int) $action['id'];
                $participantParameters["auxiliary_$index"] = (int) $auxiliary['id'];
                $participantParameters["created_$index"] = $now->format('c');
            }
            $pdo->prepare('INSERT INTO others_swarm_participants (action_id, auxiliary_id, created_at) VALUES ' . implode(',', $participantValues))
                ->execute($participantParameters);
            $ids = array_map(static fn(array $auxiliary): int => (int) $auxiliary['id'], $auxiliaries); $in = implode(',', $ids);
            $updated = $pdo->exec("UPDATE others_auxiliaries SET location_type='deployed', spatial_state='moving_to_sector_object', sector_x=" . (int) $ship['sector_x'] . ', sector_y=' . (int) $ship['sector_y'] . ', sector_z=' . (int) $ship['sector_z'] . ", object_id=" . $pdo->quote($planet->getId()) . ", updated_at=" . $pdo->quote($now->format('c')) . " WHERE id IN ($in) AND current_action_id=" . (int) $action['id']);
            if ($updated !== $count) { throw new OthersActionException(409, 'action_conflict', 'The swarm reservation collided with another command.'); }
            $biologicalCarbon = $planet->hasIntelligentLife() ? $planet->getHabitabilityScore() * $planet->getRadius() * 179.9592830250242 : 0.0;
            $pdo->prepare("INSERT INTO others_harvests (action_id,ship_id,target_object_id,phase,phase_started_at,auxiliary_count,reserved_capacity,biological_carbon,pending_output_json,created_at,updated_at) VALUES (:action_id,:ship_id,:target,:phase,:started,:count,:capacity,:biomass,NULL,:created,:updated)")->execute(['action_id' => (int) $action['id'], 'ship_id' => (int) $ship['id'], 'target' => $planet->getId(), 'phase' => $lifePhase ? 'destroying_life' : 'mining', 'started' => $now->format('c'), 'count' => $count, 'capacity' => $capacity, 'biomass' => $biologicalCarbon, 'created' => $now->format('c'), 'updated' => $now->format('c')]);
            $pdo->prepare("UPDATE others_ships SET status='low_orbit', current_action_id=:action_id, inventory_reserved=inventory_reserved+:capacity, updated_at=:now WHERE id=:id AND current_action_id IS NULL")->execute(['action_id' => (int) $action['id'], 'capacity' => $capacity, 'now' => $now->format('c'), 'id' => (int) $ship['id']]);
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $ends->format('c'), ['expectedStatus' => 'queued']);
            $pdo->prepare('UPDATE others_actions SET scheduled_event_id=:event_id WHERE id=:id')->execute(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    public function cancelHarvest(array $ship): array
    {
        if ($ship['current_action_id'] === null) { throw new OthersActionException(404, 'active_harvest_not_found', 'No active harvest was found.'); }
        return $this->others->transaction(function () use ($ship): array {
            $pdo = $this->others->pdo(); $stmt = $pdo->prepare("SELECT a.* FROM others_actions a JOIN others_harvests h ON h.action_id=a.id WHERE a.id=:id AND a.status IN ('queued','running')"); $stmt->execute(['id' => (int) $ship['current_action_id']]); $action = $stmt->fetch();
            if (!$action) { throw new OthersActionException(404, 'active_harvest_not_found', 'No active harvest was found.'); }
            $now = gmdate('c'); $pdo->prepare("UPDATE others_actions SET status='cancel_requested',updated_at=:now WHERE id=:id")->execute(['now' => $now, 'id' => (int) $action['id']]);
            if ($action['scheduled_event_id'] !== null) { $pdo->prepare("UPDATE scheduled_events SET run_at=:now,payload_json=:payload,updated_at=:now WHERE id=:id AND status='pending'")->execute(['now' => $now, 'payload' => json_encode(['expectedStatus' => 'cancel_requested'], JSON_THROW_ON_ERROR), 'id' => (int) $action['scheduled_event_id']]); }
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    public function startCraft(array $ship, array $payload): array
    {
        if ($ship['type'] !== 'mothership') { throw new OthersActionException(422, 'others_mothership_required', 'Only an Others mothership has workshops.'); }
        $recipeId = $payload['recipeId'] ?? null; $assistantId = $payload['assistantAuxiliaryId'] ?? null;
        if (!is_string($recipeId) || !isset(self::RECIPES[$recipeId]) || !is_string($assistantId)) { throw new OthersActionException(400, 'bad_request', 'A canonical recipeId and assistantAuxiliaryId are required.'); }
        $assistant = $this->others->findAuxiliaryForShip($assistantId, (int) $ship['id']);
        if ($assistant === null) { throw new OthersActionException(404, 'others_auxiliary_not_found', 'Assistant auxiliary not found.'); }
        if ($assistant['current_action_id'] !== null || $assistant['location_type'] !== 'embarked' || !in_array((string) $assistant['status'], ['inactive','available'], true)) { throw new OthersActionException(409, 'others_auxiliary_busy', 'The assistant auxiliary is busy.'); }
        $recipe = self::RECIPES[$recipeId];
        if ((float) $recipe['outputSpace'] > 0.0 && $this->others->inventoryUsage((int) $ship['id']) + (float) $ship['inventory_reserved'] + (float) $recipe['outputSpace'] > (float) $ship['inventory_capacity'] + 0.00001) { throw new OthersActionException(422, 'insufficient_resources', 'The workshop output has no reserved inventory capacity.'); }
        return $this->others->transaction(function () use ($ship, $assistant, $recipeId, $recipe): array {
            $pdo = $this->others->pdo(); $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $ends = $now->modify('+' . (int) $recipe['duration'] . ' seconds');
            foreach ($recipe['ingredients'] as $type => $amount) {
                $consume = $pdo->prepare('UPDATE others_inventory_resources SET amount=amount-CAST(:amount AS REAL),updated_at=:now WHERE ship_id=:ship_id AND resource_type=:type AND amount-reserved_amount>=CAST(:amount AS REAL)');
                $consume->execute(['amount' => $amount, 'now' => $now->format('c'), 'ship_id' => (int) $ship['id'], 'type' => $type]);
                if ($consume->rowCount() !== 1) { throw new OthersActionException(422, 'insufficient_resources', 'The mothership inventory lacks recipe ingredients.'); }
            }
            $action = $this->others->createAction($ship, 'others_craft', 'others_ship', (string) $ship['public_id'], ['recipeId' => $recipeId, 'assistantAuxiliaryId' => $assistant['public_id']], $ends->format('c'), auxiliaryId: (int) $assistant['id']);
            $craftId = OthersRepository::publicId('craft');
            $pdo->prepare("INSERT INTO others_crafts (public_id,action_id,ship_id,assistant_auxiliary_id,recipe_id,ingredients_json,output_space,status,created_at,updated_at) VALUES (:public_id,:action_id,:ship_id,:assistant,:recipe,:ingredients,:space,'queued',:now,:now)")->execute(['public_id' => $craftId, 'action_id' => (int) $action['id'], 'ship_id' => (int) $ship['id'], 'assistant' => (int) $assistant['id'], 'recipe' => $recipeId, 'ingredients' => json_encode($recipe['ingredients'], JSON_THROW_ON_ERROR), 'space' => (float) $recipe['outputSpace'], 'now' => $now->format('c')]);
            $pdo->prepare("UPDATE others_auxiliaries SET status='busy',current_action_id=:action_id,updated_at=:now WHERE id=:id AND current_action_id IS NULL")->execute(['action_id' => (int) $action['id'], 'now' => $now->format('c'), 'id' => (int) $assistant['id']]);
            if ((float) $recipe['outputSpace'] > 0.0) { $pdo->prepare('UPDATE others_ships SET inventory_reserved=inventory_reserved+:space,updated_at=:now WHERE id=:id')->execute(['space' => (float) $recipe['outputSpace'], 'now' => $now->format('c'), 'id' => (int) $ship['id']]); }
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $ends->format('c'), ['expectedStatus' => 'queued']);
            $pdo->prepare('UPDATE others_actions SET scheduled_event_id=:event_id WHERE id=:id')->execute(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return ['craft' => $this->others->findCraftForPlayer($craftId, (int) $ship['player_id']), 'action' => $this->others->findActionByPublicId((string) $action['public_id'])];
        });
    }

    public function startLaser(array $ship, array $payload): array
    {
        $targetId = $payload['targetId'] ?? null;
        if (!is_string($targetId) || $targetId === '') { throw new OthersActionException(400, 'bad_request', 'targetId is required.'); }
        if ($ship['status'] === 'transit' || $ship['destroyed_at'] !== null) { throw new OthersActionException(409, 'others_ship_busy', 'The firing ship is unavailable.'); }
        if ((float) $ship['deuterium_stock'] <= 0.0) { throw new OthersActionException(422, 'insufficient_resources', 'The laser requires a positive deuterium stock.'); }
        if ($ship['laser_next_target_at'] !== null && (string) $ship['laser_next_target_at'] > gmdate('c')) { throw new OthersActionException(409, 'action_conflict', 'The laser target-change cooldown is active.'); }
        $active = $this->others->pdo()->prepare("SELECT COUNT(*) FROM others_laser_locks WHERE ship_id=:ship_id AND status IN ('queued','active')"); $active->execute(['ship_id' => (int) $ship['id']]);
        if ((int) $active->fetchColumn() > 0) { throw new OthersActionException(409, 'action_conflict', 'This ship already maintains a laser lock.'); }
        $target = $this->resolveLocalTarget($ship, $targetId, laserOnly: true);
        if ($target === null) { throw new OthersActionException(404, 'target_not_found', 'Admissible local laser target not found.'); }
        return $this->others->transaction(function () use ($ship, $target): array {
            $pdo = $this->others->pdo(); $now = gmdate('c');
            $action = $this->others->createAction($ship, 'laser_lock', 'others_ship', (string) $ship['public_id'], ['targetId' => $target['id'], 'targetKind' => $target['kind']]);
            $pdo->prepare("INSERT INTO others_laser_locks (action_id,ship_id,target_kind,target_public_id,sector_x,sector_y,sector_z,status,started_at,accounted_until,next_damage_at,exhausts_at,created_at,updated_at) VALUES (:action_id,:ship_id,:kind,:target,:x,:y,:z,'queued',NULL,NULL,NULL,NULL,:now,:now)")->execute(['action_id' => (int) $action['id'], 'ship_id' => (int) $ship['id'], 'kind' => $target['kind'], 'target' => $target['id'], 'x' => (int) $ship['sector_x'], 'y' => (int) $ship['sector_y'], 'z' => (int) $ship['sector_z'], 'now' => $now]);
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $now, ['expectedStatus' => 'queued']);
            $pdo->prepare('UPDATE others_actions SET scheduled_event_id=:event_id WHERE id=:id')->execute(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    private function startAuxiliaryMining(array $ship, array $auxiliary, array $payload): array
    {
        if ($this->sectors === null) { throw new OthersActionException(503, 'others_sector_unavailable', 'Sector storage is unavailable.'); }
        $objectId = $payload['objectId'] ?? null; $resources = $payload['resources'] ?? null; $targetAmount = $payload['targetAmount'] ?? null;
        if (!is_string($objectId) || (!is_string($resources) && !is_array($resources)) || !is_numeric($targetAmount) || (float) $targetAmount <= 0.0 || (float) $targetAmount > 2.0) { throw new OthersActionException(400, 'bad_request', 'Mining requires objectId, resources and targetAmount between 0 and 2 ECE.'); }
        if ($auxiliary['current_action_id'] !== null || !in_array((string) $auxiliary['status'], ['inactive', 'available'], true) || $auxiliary['location_type'] !== 'embarked') { throw new OthersActionException(409, 'others_auxiliary_busy', 'The auxiliary is not available and embarked.'); }
        $selection = ResourceComposition::normalizeSelection($resources);
        $sectorCoordinates = new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']);
        $sector = $this->sectors->getOrCreateSector($sectorCoordinates); $object = $sector->findObjectById($objectId);
        if (!$object instanceof Planet && !$object instanceof Asteroid && !($object instanceof DormantConstruct && $object->getSubtype() !== null)) { throw new OthersActionException(404, 'target_not_found', 'Mineable sector object not found.'); }
        $amounts = method_exists($object, 'getResourceAmounts') ? $object->getResourceAmounts() : [];
        $profile = ResourceComposition::profileForSelection($amounts, $selection);
        $available = 0.0; foreach ($selection as $type) { $available += (float) ($amounts[$type] ?? 0.0); }
        if ($available <= 0.0) { throw new OthersActionException(422, 'insufficient_resources', 'The selected resources are exhausted.'); }
        $amount = round(min(2.0, (float) $targetAmount, $available), 4);
        $endsAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+10 minutes');
        return $this->reserveAuxiliaryAction($ship, $auxiliary, 'auxiliary_mine', ['objectId' => $objectId, 'amount' => $amount, 'profile' => $profile], $endsAt, deployed: true, objectId: $objectId);
    }

    private function startAuxiliaryRecall(array $ship, array $auxiliary, array $payload): array
    {
        if ($payload !== []) { throw new OthersActionException(400, 'bad_request', 'Recall accepts an empty JSON object.'); }
        if ($auxiliary['current_action_id'] !== null || $auxiliary['location_type'] !== 'deployed') { throw new OthersActionException(409, 'others_auxiliary_busy', 'Only an available deployed auxiliary can be recalled.'); }
        $endsAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 minutes');
        return $this->reserveAuxiliaryAction($ship, $auxiliary, 'auxiliary_recall', [], $endsAt, deployed: true, objectId: $auxiliary['object_id']);
    }

    private function startDormantRecovery(array $ship, array $auxiliary, array $payload): array
    {
        if ($this->sectors === null) { throw new OthersActionException(503, 'others_sector_unavailable', 'Sector storage is unavailable.'); }
        $objectId = $payload['objectId'] ?? null;
        if (!is_string($objectId) || $objectId === '') { throw new OthersActionException(400, 'bad_request', 'objectId is required.'); }
        if ($auxiliary['current_action_id'] !== null || $auxiliary['location_type'] !== 'embarked') { throw new OthersActionException(409, 'others_auxiliary_busy', 'The recovery auxiliary is not available and embarked.'); }
        $sector = $this->sectors->getOrCreateSector(new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']));
        $object = $sector->findObjectById($objectId);
        if (!$object instanceof DormantConstruct || $object->getSubtype() !== 'others_auxiliary' || (float)($object->getResourceAmounts()['metals'] ?? 0.0) < 5.0 - 0.00001) { throw new OthersActionException(422, 'target_not_found', 'An intact dormant Others auxiliary is required.'); }
        $endsAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 minutes');
        return $this->reserveAuxiliaryAction($ship, $auxiliary, 'dormant_auxiliary_recovery', ['objectId' => $objectId, 'originalAuxiliaryId' => $object->getOriginalAuxiliaryId()], $endsAt);
    }

    private function reserveAuxiliaryAction(array $ship, array $auxiliary, string $type, array $payload, \DateTimeImmutable $endsAt, bool $deployed = false, ?string $objectId = null): array
    {
        return $this->others->transaction(function () use ($ship, $auxiliary, $type, $payload, $endsAt, $deployed, $objectId): array {
            $pdo = $this->others->pdo(); $now = gmdate('c');
            $action = $this->others->createAction($ship, $type, 'others_auxiliary', (string) $auxiliary['public_id'], $payload, $endsAt->format('c'), auxiliaryId: (int) $auxiliary['id']);
            $sql = "UPDATE others_auxiliaries SET status = 'busy', current_action_id = :action_id, updated_at = :now";
            $params = ['action_id' => (int) $action['id'], 'now' => $now, 'id' => (int) $auxiliary['id']];
            if ($deployed) { $sql .= ", location_type = 'deployed', spatial_state = :spatial_state, sector_x = :x, sector_y = :y, sector_z = :z, object_id = :object_id"; $params += ['spatial_state' => $type === 'auxiliary_recall' ? 'returning_to_carrier' : 'moving_to_sector_object', 'x' => (int) $ship['sector_x'], 'y' => (int) $ship['sector_y'], 'z' => (int) $ship['sector_z'], 'object_id' => $objectId]; }
            $sql .= ' WHERE id = :id AND current_action_id IS NULL';
            $update = $pdo->prepare($sql); $update->execute($params);
            if ($update->rowCount() !== 1) { throw new OthersActionException(409, 'action_conflict', 'The auxiliary state changed while accepting the task.'); }
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $endsAt->format('c'), ['expectedStatus' => 'queued']);
            $pdo->prepare('UPDATE others_actions SET scheduled_event_id = :event_id WHERE id = :id')->execute(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    public function processScheduledAction(ScheduledEvent $event): void
    {
        $this->others->transaction(function () use ($event): void {
            $pdo = $this->others->pdo();
            $stmt = $pdo->prepare('SELECT a.*, m.id AS movement_id, m.ship_id AS movement_ship_id, m.target_x, m.target_y, m.target_z, m.fuel_cost, m.phase, m.arrive_at, m.leave_auxiliaries_behind FROM others_actions a LEFT JOIN others_movements m ON m.action_id = a.id WHERE a.id = :id');
            $stmt->execute(['id' => $event->entityId]);
            $action = $stmt->fetch();
            if (!$action || in_array((string) $action['status'], ['succeeded', 'failed', 'canceled'], true)) { return; }
            $now = gmdate('c');
            if ($action['type'] === 'inventory_transfer') {
                $this->completeInventoryTransfer($action, $now);
                return;
            }
            if ($action['type'] === 'deuterium_transfer') {
                $this->completeDeuteriumTransfer($action, $now);
                return;
            }
            if ($action['type'] === 'auxiliary_mine') {
                $this->completeAuxiliaryMining($action, $now);
                return;
            }
            if ($action['type'] === 'auxiliary_recall') {
                $this->completeAuxiliaryRecall($action, $now);
                return;
            }
            if ($action['type'] === 'dormant_auxiliary_recovery') {
                $this->completeDormantRecovery($action, $now);
                return;
            }
            if ($action['type'] === 'planet_harvest') {
                $this->processHarvest($action, $now);
                return;
            }
            if ($action['type'] === 'others_craft') {
                $this->completeCraft($action, $now);
                return;
            }
            if ($action['type'] === 'laser_lock') {
                $this->processLaser($action, $now);
                return;
            }
            if ($action['type'] === 'missile_launch') {
                $this->launchOthersProjectile($action, $now);
                return;
            }
            if ($action['type'] !== 'ship_move') {
                throw new \RuntimeException('Unsupported Others action type: ' . $action['type']);
            }
            if ($action['status'] === 'cancel_requested') {
                $pdo->prepare("UPDATE others_actions SET status = 'canceled', completed_at = :now, updated_at = :now WHERE id = :id AND status = 'cancel_requested'")->execute(['now' => $now, 'id' => (int) $action['id']]);
                $pdo->prepare("UPDATE others_movements SET phase = 'canceled', updated_at = :now WHERE action_id = :id")->execute(['now' => $now, 'id' => (int) $action['id']]);
                $pdo->prepare("UPDATE others_ships SET status = 'inactive', current_action_id = NULL, departure_engaged = 0, deuterium_stock = deuterium_stock + :fuel, updated_at = :now WHERE id = :ship_id AND current_action_id = :action_id")->execute(['fuel' => (float) $action['fuel_cost'], 'now' => $now, 'ship_id' => (int) $action['movement_ship_id'], 'action_id' => (int) $action['id']]);
                $pdo->prepare("UPDATE others_auxiliaries SET status = 'inactive', location_type = 'embarked', spatial_state = 'drifting', sector_x = NULL, sector_y = NULL, sector_z = NULL, object_id = NULL, updated_at = :now WHERE ship_id = :ship_id AND status = 'returning'")->execute(['now' => $now, 'ship_id' => (int) $action['movement_ship_id']]);
                return;
            }
            if ($action['status'] === 'queued' && $action['phase'] === 'waiting_to_depart') {
                if ((int) $action['leave_auxiliaries_behind'] === 1) { $this->turnDeployedAuxiliariesDormant((int) $action['movement_ship_id'], $now); }
                else { $pdo->prepare("UPDATE others_auxiliaries SET status = 'inactive', location_type = 'embarked', spatial_state = 'drifting', sector_x = NULL, sector_y = NULL, sector_z = NULL, object_id = NULL, updated_at = :now WHERE ship_id = :ship_id AND status = 'returning'")->execute(['now' => $now, 'ship_id' => (int) $action['movement_ship_id']]); }
                $pdo->prepare("UPDATE others_actions SET status = 'running', updated_at = :now WHERE id = :id AND status = 'queued'")->execute(['now' => $now, 'id' => (int) $action['id']]);
                $pdo->prepare("UPDATE others_movements SET phase = 'transit', updated_at = :now WHERE action_id = :id")->execute(['now' => $now, 'id' => (int) $action['id']]);
                $pdo->prepare("UPDATE others_ships SET status = 'transit', updated_at = :now WHERE id = :ship_id AND current_action_id = :action_id")->execute(['now' => $now, 'ship_id' => (int) $action['movement_ship_id'], 'action_id' => (int) $action['id']]);
                $next = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], (string) $action['arrive_at'], ['expectedStatus' => 'running']);
                $pdo->prepare('UPDATE others_actions SET scheduled_event_id = :event_id WHERE id = :id')->execute(['event_id' => $next->id, 'id' => (int) $action['id']]);
                return;
            }
            if ($action['status'] === 'running' && $action['phase'] === 'transit') {
                $result = ['outcome' => 'arrived'];
                $pdo->prepare("UPDATE others_actions SET status = 'succeeded', result_json = :result, completed_at = :now, updated_at = :now WHERE id = :id AND status = 'running'")->execute(['result' => json_encode($result, JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
                $pdo->prepare("UPDATE others_movements SET phase = 'arrived', updated_at = :now WHERE action_id = :id")->execute(['now' => $now, 'id' => (int) $action['id']]);
                $target = new SectorCoordinates((int) $action['target_x'], (int) $action['target_y'], (int) $action['target_z']);
                $pdo->prepare("UPDATE others_ships SET status = 'inactive', sector_x = :x, sector_y = :y, sector_z = :z, current_action_id = NULL, departure_engaged = 0, entered_sector_at = :now, updated_at = :now WHERE id = :ship_id AND current_action_id = :action_id")->execute(['x' => $target->getX(), 'y' => $target->getY(), 'z' => $target->getZ(), 'now' => $now, 'ship_id' => (int) $action['movement_ship_id'], 'action_id' => (int) $action['id']]);
                $this->others->markFleetSectorVisited((int) $action['fleet_id'], $target, $now);
                $this->createOthersArrivalAlerts($target, (string) $action['public_id']);
                return;
            }
        });
    }

    private function completeInventoryTransfer(array $action, string $now): void
    {
        $pdo = $this->others->pdo();
        $stmt = $pdo->prepare('SELECT * FROM others_inventory_transfers WHERE action_id = :action_id');
        $stmt->execute(['action_id' => (int) $action['id']]);
        $transfer = $stmt->fetch();
        if (!$transfer || $transfer['status'] !== 'queued') { return; }
        $targetStmt = $pdo->prepare("SELECT * FROM others_ships WHERE id = :id AND destroyed_at IS NULL AND status <> 'removed'");
        $targetStmt->execute(['id' => (int) $transfer['target_ship_id']]);
        $target = $targetStmt->fetch();
        $items = json_decode((string) $transfer['item_ids_json'], true, 512, JSON_THROW_ON_ERROR);
        $space = $transfer['kind'] === 'resource' ? (float) $transfer['amount'] : 0.0;
        if ($transfer['kind'] === 'item') {
            $reservedItems = $this->others->inventoryItemsByPublicIds((int) $transfer['source_ship_id'], $items);
            foreach ($reservedItems as $item) { if ((int) ($item['reserved_action_id'] ?? 0) === (int) $action['id']) { $space += (float) $item['container_space']; } }
        }
        if (!$target) {
            if ($transfer['kind'] === 'resource') {
                $amount = (float) $transfer['amount'];
                $pdo->prepare('UPDATE others_inventory_resources SET reserved_amount = CASE WHEN reserved_amount > :reserved_floor THEN reserved_amount - :reserved_decrease ELSE 0 END, updated_at = :now WHERE ship_id = :ship_id AND resource_type = :resource_type')->execute(['reserved_floor' => $amount, 'reserved_decrease' => $amount, 'now' => $now, 'ship_id' => (int) $transfer['source_ship_id'], 'resource_type' => $transfer['resource_type']]);
            } else {
                $pdo->prepare('UPDATE others_inventory_items SET reserved_action_id = NULL, updated_at = :now WHERE reserved_action_id = :action_id')->execute(['now' => $now, 'action_id' => (int) $action['id']]);
            }
            $this->finishTransfer($transfer, $action, $space, $now, false, 'target_unavailable');
            return;
        }
        if ($transfer['kind'] === 'resource') {
            $source = $pdo->prepare('UPDATE others_inventory_resources SET amount = amount - CAST(:amount AS REAL), reserved_amount = reserved_amount - CAST(:amount AS REAL), updated_at = :now WHERE ship_id = :ship_id AND resource_type = :resource_type AND amount >= CAST(:amount AS REAL) AND reserved_amount >= CAST(:amount AS REAL)');
            $source->execute(['amount' => (float) $transfer['amount'], 'now' => $now, 'ship_id' => (int) $transfer['source_ship_id'], 'resource_type' => $transfer['resource_type']]);
            if ($source->rowCount() !== 1) { throw new \RuntimeException('Reserved Others inventory resource is inconsistent.'); }
            $pdo->prepare('UPDATE others_inventory_resources SET amount = amount + :amount, updated_at = :now WHERE ship_id = :ship_id AND resource_type = :resource_type')->execute(['amount' => (float) $transfer['amount'], 'now' => $now, 'ship_id' => (int) $transfer['target_ship_id'], 'resource_type' => $transfer['resource_type']]);
        } else {
            $move = $pdo->prepare('UPDATE others_inventory_items SET ship_id = :target_ship_id, reserved_action_id = NULL, updated_at = :now WHERE reserved_action_id = :action_id AND ship_id = :source_ship_id');
            $move->execute(['target_ship_id' => (int) $transfer['target_ship_id'], 'now' => $now, 'action_id' => (int) $action['id'], 'source_ship_id' => (int) $transfer['source_ship_id']]);
            if ($move->rowCount() !== count($items)) { throw new \RuntimeException('Reserved Others inventory items are inconsistent.'); }
        }
        $this->finishTransfer($transfer, $action, $space, $now, true, null);
    }

    private function finishTransfer(array $transfer, array $action, float $space, string $now, bool $success, ?string $reason): void
    {
        $pdo = $this->others->pdo();
        $status = $success ? 'succeeded' : 'failed';
        $result = $success ? ['outcome' => 'transferred'] : null;
        $error = $success ? null : ['code' => $reason, 'message' => 'The inventory transfer could not be completed.'];
        $pdo->prepare('UPDATE others_inventory_transfers SET status = :status, updated_at = :now WHERE id = :id AND status = :expected')->execute(['status' => $status, 'now' => $now, 'id' => (int) $transfer['id'], 'expected' => 'queued']);
        $pdo->prepare('UPDATE others_actions SET status = :status, result_json = :result, error_json = :error, completed_at = :now, updated_at = :now WHERE id = :id AND status = :expected')->execute(['status' => $status, 'result' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR), 'error' => $error === null ? null : json_encode($error, JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id'], 'expected' => 'queued']);
        $pdo->prepare('UPDATE others_ships SET inventory_reserved = CASE WHEN inventory_reserved > :reserved_floor THEN inventory_reserved - :reserved_decrease ELSE 0 END, updated_at = :now WHERE id = :id')->execute(['reserved_floor' => $space, 'reserved_decrease' => $space, 'now' => $now, 'id' => (int) $transfer['target_ship_id']]);
        $pdo->prepare("UPDATE others_auxiliaries SET status = 'inactive', current_action_id = NULL, updated_at = :now WHERE id = :id AND current_action_id = :action_id")->execute(['now' => $now, 'id' => (int) $transfer['auxiliary_id'], 'action_id' => (int) $action['id']]);
    }

    private function completeDeuteriumTransfer(array $action, string $now): void
    {
        $pdo = $this->others->pdo(); $payload = json_decode((string) $action['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $target = $this->others->findShipByPublicId((string) $payload['targetShipId']); $amount = (float) $payload['amount'];
        if ($target === null || $target['destroyed_at'] !== null || $target['status'] === 'removed') {
            $pdo->prepare('UPDATE others_ships SET deuterium_reserved = CASE WHEN deuterium_reserved > :reserved_floor THEN deuterium_reserved - :reserved_decrease ELSE 0 END, updated_at = :now WHERE id = :id')->execute(['reserved_floor' => $amount, 'reserved_decrease' => $amount, 'now' => $now, 'id' => (int) $action['ship_id']]);
            $pdo->prepare("UPDATE others_actions SET status = 'failed', error_json = :error, completed_at = :now, updated_at = :now WHERE id = :id AND status = 'queued'")->execute(['error' => json_encode(['code' => 'target_unavailable', 'message' => 'The target ship became unavailable.'], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
        } else {
            $sourceUpdate = $pdo->prepare('UPDATE others_ships SET deuterium_stock = deuterium_stock - :amount, deuterium_reserved = deuterium_reserved - :amount, updated_at = :now WHERE id = :id AND deuterium_stock >= :amount AND deuterium_reserved >= :amount');
            $sourceUpdate->execute(['amount' => $amount, 'now' => $now, 'id' => (int) $action['ship_id']]);
            if ($sourceUpdate->rowCount() !== 1) { throw new \RuntimeException('Reserved Others deuterium is inconsistent.'); }
            $pdo->prepare('UPDATE others_ships SET deuterium_stock = deuterium_stock + :stock_increase, deuterium_reserved = CASE WHEN deuterium_reserved > :reserved_floor THEN deuterium_reserved - :reserved_decrease ELSE 0 END, updated_at = :now WHERE id = :id')->execute(['stock_increase' => $amount, 'reserved_floor' => $amount, 'reserved_decrease' => $amount, 'now' => $now, 'id' => (int) $target['id']]);
            $pdo->prepare("UPDATE others_actions SET status = 'succeeded', result_json = :result, completed_at = :now, updated_at = :now WHERE id = :id AND status = 'queued'")->execute(['result' => json_encode(['outcome' => 'transferred', 'amount' => $amount], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
        }
        if ($action['auxiliary_id'] !== null) { $pdo->prepare("UPDATE others_auxiliaries SET status = 'inactive', current_action_id = NULL, updated_at = :now WHERE id = :id AND current_action_id = :action_id")->execute(['now' => $now, 'id' => (int) $action['auxiliary_id'], 'action_id' => (int) $action['id']]); }
    }

    private function completeAuxiliaryMining(array $action, string $now): void
    {
        if ($this->sectors === null || $action['auxiliary_id'] === null) { throw new \RuntimeException('Others mining sector storage is unavailable.'); }
        $ship = $this->others->findShipByPublicId((string) $action['actor_public_id']);
        $shipStmt = $this->others->pdo()->prepare('SELECT * FROM others_ships WHERE id = :id'); $shipStmt->execute(['id' => (int) $action['ship_id']]); $ship = $shipStmt->fetch();
        if (!$ship) { $this->failAuxiliaryAction($action, $now, 'carrier_unavailable'); return; }
        $payload = json_decode((string) $action['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $coordinates = new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']);
        $sector = $this->sectors->getOrCreateSector($coordinates); $object = $sector->findObjectById((string) $payload['objectId']);
        if (!$object instanceof Planet && !$object instanceof Asteroid && !$object instanceof DormantConstruct) { $this->failAuxiliaryAction($action, $now, 'target_unavailable'); return; }
        $remaining = $object->getResourceAmounts(); $profile = $payload['profile']; $requested = (float) $payload['amount']; $extracted = []; $total = 0.0;
        foreach (ResourceComposition::TYPES as $type) {
            $take = round(min((float) ($remaining[$type] ?? 0.0), $requested * (float) ($profile[$type] ?? 0.0)), 4);
            $extracted[$type] = $take; $remaining[$type] = round(max(0.0, (float) ($remaining[$type] ?? 0.0) - $take), 4); $total += $take;
        }
        if ($total <= 0.0) { $this->failAuxiliaryAction($action, $now, 'resources_exhausted'); return; }
        $replacement = $object instanceof Planet ? $object->withResourceAmounts($remaining) : $object->withResourceAmounts($remaining);
        $sector->replaceObject($replacement); $this->sectors->saveSector($sector);
        $pdo = $this->others->pdo();
        $pdo->prepare("UPDATE others_auxiliaries SET cargo_deuterium = cargo_deuterium + :deuterium, cargo_metals = cargo_metals + :metals, cargo_ice = cargo_ice + :ice, cargo_carbon_compounds = cargo_carbon_compounds + :carbon, status = 'inactive', current_action_id = NULL, spatial_state = 'landed_on_sector_object', updated_at = :now WHERE id = :id AND current_action_id = :action_id")->execute(['deuterium' => $extracted['deuterium'], 'metals' => $extracted['metals'], 'ice' => $extracted['ice'], 'carbon' => $extracted['carbon_compounds'], 'now' => $now, 'id' => (int) $action['auxiliary_id'], 'action_id' => (int) $action['id']]);
        $pdo->prepare("UPDATE others_actions SET status = 'succeeded', result_json = :result, completed_at = :now, updated_at = :now WHERE id = :id AND status = 'queued'")->execute(['result' => json_encode(['outcome' => 'mined', 'amounts' => $extracted], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    private function completeAuxiliaryRecall(array $action, string $now): void
    {
        if ($action['auxiliary_id'] === null) { return; }
        $pdo = $this->others->pdo();
        $pdo->prepare("UPDATE others_auxiliaries SET status = 'inactive', location_type = 'embarked', spatial_state = 'drifting', sector_x = NULL, sector_y = NULL, sector_z = NULL, object_id = NULL, current_action_id = NULL, updated_at = :now WHERE id = :id AND current_action_id = :action_id")->execute(['now' => $now, 'id' => (int) $action['auxiliary_id'], 'action_id' => (int) $action['id']]);
        $pdo->prepare("UPDATE others_actions SET status = 'succeeded', result_json = :result, completed_at = :now, updated_at = :now WHERE id = :id AND status = 'queued'")->execute(['result' => json_encode(['outcome' => 'embarked'], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    private function completeDormantRecovery(array $action, string $now): void
    {
        if ($this->sectors === null || $action['auxiliary_id'] === null) { throw new \RuntimeException('Dormant recovery storage is unavailable.'); }
        $shipStmt = $this->others->pdo()->prepare('SELECT * FROM others_ships WHERE id = :id'); $shipStmt->execute(['id' => (int) $action['ship_id']]); $ship = $shipStmt->fetch();
        if (!$ship) { $this->failAuxiliaryAction($action, $now, 'carrier_unavailable'); return; }
        $payload = json_decode((string) $action['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $sector = $this->sectors->getOrCreateSector(new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']));
        $object = $sector->findObjectById((string) $payload['objectId']);
        if (!$object instanceof DormantConstruct || $object->getSubtype() !== 'others_auxiliary' || array_sum($object->getResourceAmounts()) < 5.01 - 0.00001) { $this->failAuxiliaryAction($action, $now, 'target_unavailable'); return; }
        $sector->removeObjectById($object->getId()); $this->sectors->saveSector($sector);
        $recoveredId = is_string($payload['originalAuxiliaryId'] ?? null) && $payload['originalAuxiliaryId'] !== '' ? $payload['originalAuxiliaryId'] : OthersRepository::publicId('aux');
        $this->others->reviveAuxiliary((int) $ship['id'], $recoveredId);
        $pdo = $this->others->pdo();
        $pdo->prepare("UPDATE others_auxiliaries SET status = 'inactive', current_action_id = NULL, updated_at = :now WHERE id = :id AND current_action_id = :action_id")->execute(['now' => $now, 'id' => (int) $action['auxiliary_id'], 'action_id' => (int) $action['id']]);
        $pdo->prepare("UPDATE others_actions SET status = 'succeeded', result_json = :result, completed_at = :now, updated_at = :now WHERE id = :id AND status = 'queued'")->execute(['result' => json_encode(['outcome' => 'recovered', 'auxiliaryId' => $recoveredId], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    private function failAuxiliaryAction(array $action, string $now, string $reason): void
    {
        $pdo = $this->others->pdo();
        if ($action['auxiliary_id'] !== null) { $pdo->prepare("UPDATE others_auxiliaries SET status = 'inactive', current_action_id = NULL, updated_at = :now WHERE id = :id AND current_action_id = :action_id")->execute(['now' => $now, 'id' => (int) $action['auxiliary_id'], 'action_id' => (int) $action['id']]); }
        $pdo->prepare("UPDATE others_actions SET status = 'failed', error_json = :error, completed_at = :now, updated_at = :now WHERE id = :id AND status = 'queued'")->execute(['error' => json_encode(['code' => $reason, 'message' => 'The Others auxiliary task could not be completed.'], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    private function processHarvest(array $action, string $now): void
    {
        if ($this->sectors === null) { throw new \RuntimeException('Harvest sector storage is unavailable.'); }
        $pdo = $this->others->pdo(); $stmt = $pdo->prepare('SELECT * FROM others_harvests WHERE action_id=:action_id'); $stmt->execute(['action_id' => (int) $action['id']]); $harvest = $stmt->fetch();
        if (!$harvest) { return; }
        $canceling = $action['status'] === 'cancel_requested';
        if ($canceling && in_array((string) $harvest['phase'], ['destroying_life', 'mining'], true)) {
            $output = [];
            if ($harvest['phase'] === 'mining') {
                $elapsed = max(0, (new \DateTimeImmutable($now))->getTimestamp() - (new \DateTimeImmutable((string) $harvest['phase_started_at']))->getTimestamp());
                $fraction = max(0.0, min(1.0, ($elapsed - 300) / 300));
                if ($fraction > 0.0) { $output = $this->extractHarvestResources($harvest, round((float) $harvest['reserved_capacity'] * $fraction, 4)); }
            }
            $pdo->prepare("UPDATE others_harvests SET phase='recalling',phase_started_at=:now,pending_output_json=:output,updated_at=:now WHERE id=:id")->execute(['now' => $now, 'output' => json_encode($output, JSON_THROW_ON_ERROR), 'id' => (int) $harvest['id']]);
            $this->scheduleExistingAction($action, (new \DateTimeImmutable($now))->modify('+5 minutes')->format('c'), 'cancel_requested');
            return;
        }
        if ($harvest['phase'] === 'recalling') {
            $pdo->prepare("UPDATE others_harvests SET phase='orbit_exit',phase_started_at=:now,updated_at=:now WHERE id=:id")->execute(['now' => $now, 'id' => (int) $harvest['id']]);
            $this->scheduleExistingAction($action, (new \DateTimeImmutable($now))->modify('+10 minutes')->format('c'), 'cancel_requested');
            return;
        }
        if ($harvest['phase'] === 'orbit_exit') {
            $output = is_string($harvest['pending_output_json']) ? json_decode($harvest['pending_output_json'], true) : [];
            $this->finishHarvest($action, $harvest, $output, $now, canceled: true);
            return;
        }
        if ($harvest['phase'] === 'destroying_life') {
            $shipStmt = $pdo->prepare('SELECT * FROM others_ships WHERE id=:id'); $shipStmt->execute(['id' => (int) $harvest['ship_id']]); $ship = $shipStmt->fetch();
            if (!$ship) { $this->finishHarvest($action, $harvest, [], $now, canceled: false, failure: 'carrier_unavailable'); return; }
            $sector = $this->sectors->getOrCreateSector(new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z'])); $planet = $sector->findObjectById((string) $harvest['target_object_id']);
            if (!$planet instanceof Planet) { $this->finishHarvest($action, $harvest, [], $now, canceled: false, failure: 'target_unavailable'); return; }
            $amounts = $planet->getResourceAmounts(); $amounts['carbon_compounds'] = round($amounts['carbon_compounds'] + (float) $harvest['biological_carbon'], 4);
            $sector->replaceObject($planet->withResourceAmounts($amounts, intelligentLife: false)); $this->sectors->saveSector($sector);
            $pdo->prepare("UPDATE others_harvests SET phase='mining',phase_started_at=:now,updated_at=:now WHERE id=:id")->execute(['now' => $now, 'id' => (int) $harvest['id']]);
            $pdo->prepare("UPDATE others_actions SET status='running',ends_at=:ends,updated_at=:now WHERE id=:id")->execute(['ends' => (new \DateTimeImmutable($now))->modify('+10 minutes')->format('c'), 'now' => $now, 'id' => (int) $action['id']]);
            $this->scheduleExistingAction($action, (new \DateTimeImmutable($now))->modify('+10 minutes')->format('c'), 'running');
            return;
        }
        if ($harvest['phase'] === 'mining') {
            $output = $this->extractHarvestResources($harvest, (float) $harvest['reserved_capacity']);
            $this->finishHarvest($action, $harvest, $output, $now, canceled: false);
        }
    }

    private function extractHarvestResources(array $harvest, float $grossCapacity): array
    {
        $pdo = $this->others->pdo(); $shipStmt = $pdo->prepare('SELECT * FROM others_ships WHERE id=:id'); $shipStmt->execute(['id' => (int) $harvest['ship_id']]); $ship = $shipStmt->fetch();
        if (!$ship || $grossCapacity <= 0.0) { return []; }
        $sector = $this->sectors?->getOrCreateSector(new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z'])); $planet = $sector?->findObjectById((string) $harvest['target_object_id']);
        if (!$planet instanceof Planet) { return []; }
        $amounts = $planet->getResourceAmounts(); $total = array_sum($amounts); $grossTotal = round(min($grossCapacity, $total), 4);
        if ($grossTotal <= 0.0) { return []; }
        $gross = []; $stored = []; $consumed = []; $remainingGross = $grossTotal;
        foreach (ResourceComposition::TYPES as $index => $type) {
            $take = $index === count(ResourceComposition::TYPES) - 1 ? min((float) $amounts[$type], $remainingGross) : min((float) $amounts[$type], round($grossTotal * (float) $amounts[$type] / $total, 4));
            $take = round(max(0.0, $take), 4); $gross[$type] = $take; $remainingGross = round(max(0.0, $remainingGross - $take), 4);
            $consumed[$type] = round($take * 0.10, 4); $stored[$type] = round($take - $consumed[$type], 4); $amounts[$type] = round($amounts[$type] - $take, 4);
        }
        $sector->replaceObject($planet->withResourceAmounts($amounts, true, false)); $this->sectors?->saveSector($sector);
        return ['gross' => $gross, 'consumed' => $consumed, 'stored' => $stored];
    }

    private function finishHarvest(array $action, array $harvest, array $output, string $now, bool $canceled, ?string $failure = null): void
    {
        $pdo = $this->others->pdo(); $stored = is_array($output['stored'] ?? null) ? $output['stored'] : [];
        foreach (ResourceComposition::TYPES as $type) {
            $amount = (float) ($stored[$type] ?? 0.0);
            if ($amount > 0.0) { $pdo->prepare('UPDATE others_inventory_resources SET amount=amount+:amount,updated_at=:now WHERE ship_id=:ship_id AND resource_type=:type')->execute(['amount' => $amount, 'now' => $now, 'ship_id' => (int) $harvest['ship_id'], 'type' => $type]); }
        }
        $pdo->prepare("UPDATE others_auxiliaries SET status='inactive',location_type='embarked',spatial_state='drifting',sector_x=NULL,sector_y=NULL,sector_z=NULL,object_id=NULL,current_action_id=NULL,updated_at=:now WHERE current_action_id=:action_id")->execute(['now' => $now, 'action_id' => (int) $action['id']]);
        $reservedCapacity = (float) $harvest['reserved_capacity'];
        $pdo->prepare("UPDATE others_ships SET status='inactive',current_action_id=NULL,inventory_reserved=CASE WHEN inventory_reserved > :reserved_floor THEN inventory_reserved - :reserved_decrease ELSE 0 END,updated_at=:now WHERE id=:id AND current_action_id=:action_id")->execute(['reserved_floor' => $reservedCapacity, 'reserved_decrease' => $reservedCapacity, 'now' => $now, 'id' => (int) $harvest['ship_id'], 'action_id' => (int) $action['id']]);
        $status = $failure !== null ? 'failed' : ($canceled ? 'canceled' : 'succeeded');
        $result = $failure === null ? ['outcome' => $canceled ? 'interrupted' : 'harvested', 'resources' => $output] : null;
        $error = $failure !== null ? ['code' => $failure, 'message' => 'The harvest could not be completed.'] : null;
        $pdo->prepare("UPDATE others_harvests SET phase=:phase,pending_output_json=:output,updated_at=:now WHERE id=:id")->execute(['phase' => $status, 'output' => json_encode($output, JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $harvest['id']]);
        $pdo->prepare("UPDATE others_actions SET status=:status,result_json=:result,error_json=:error,completed_at=:now,updated_at=:now WHERE id=:id AND status IN ('queued','running','cancel_requested')")->execute(['status' => $status, 'result' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR), 'error' => $error === null ? null : json_encode($error, JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    private function scheduleExistingAction(array $action, string $runAt, string $expectedStatus): void
    {
        $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $runAt, ['expectedStatus' => $expectedStatus]);
        $this->others->pdo()->prepare('UPDATE others_actions SET scheduled_event_id=:event_id,ends_at=:ends_at,updated_at=:now WHERE id=:id')->execute(['event_id' => $event->id, 'ends_at' => $runAt, 'now' => gmdate('c'), 'id' => (int) $action['id']]);
    }

    private function completeCraft(array $action, string $now): void
    {
        $pdo = $this->others->pdo(); $stmt = $pdo->prepare('SELECT * FROM others_crafts WHERE action_id=:action_id'); $stmt->execute(['action_id' => (int) $action['id']]); $craft = $stmt->fetch();
        if (!$craft || $craft['status'] !== 'queued') { return; }
        $shipStmt = $pdo->prepare('SELECT s.*,f.player_id,f.public_id AS fleet_public_id FROM others_ships s JOIN others_fleets f ON f.id=s.fleet_id WHERE s.id=:id AND s.destroyed_at IS NULL'); $shipStmt->execute(['id' => (int) $craft['ship_id']]); $ship = $shipStmt->fetch();
        if (!$ship) {
            $pdo->prepare("UPDATE others_crafts SET status='failed',updated_at=:now WHERE id=:id")->execute(['now' => $now, 'id' => (int) $craft['id']]);
            $pdo->prepare("UPDATE others_actions SET status='failed',error_json=:error,completed_at=:now,updated_at=:now WHERE id=:id AND status='queued'")->execute(['error' => json_encode(['code' => 'carrier_unavailable', 'message' => 'The crafting mothership is unavailable.'], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
            return;
        }
        $output = match ($craft['recipe_id']) {
            'standard_ship' => ['kind' => 'standard_ship', 'id' => ($created = $this->others->createStandardShip($ship))['public_id']],
            'others_auxiliary' => ['kind' => 'others_auxiliary', 'id' => ($created = $this->others->createAuxiliary((int) $ship['id']))['public_id']],
            'missile' => $this->createCraftedMissileItem((int) $ship['id'], $now),
            default => throw new \RuntimeException('Unsupported frozen Others craft recipe.'),
        };
        $pdo->prepare("UPDATE others_crafts SET status='succeeded',updated_at=:now WHERE id=:id AND status='queued'")->execute(['now' => $now, 'id' => (int) $craft['id']]);
        $pdo->prepare("UPDATE others_actions SET status='succeeded',result_json=:result,completed_at=:now,updated_at=:now WHERE id=:id AND status='queued'")->execute(['result' => json_encode(['output' => $output], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
        $pdo->prepare("UPDATE others_auxiliaries SET status='inactive',current_action_id=NULL,updated_at=:now WHERE id=:id AND current_action_id=:action_id")->execute(['now' => $now, 'id' => (int) $craft['assistant_auxiliary_id'], 'action_id' => (int) $action['id']]);
        $outputSpace = (float) $craft['output_space'];
        if ($outputSpace > 0.0) { $pdo->prepare('UPDATE others_ships SET inventory_reserved=CASE WHEN inventory_reserved > :reserved_floor THEN inventory_reserved - :reserved_decrease ELSE 0 END,updated_at=:now WHERE id=:id')->execute(['reserved_floor' => $outputSpace, 'reserved_decrease' => $outputSpace, 'now' => $now, 'id' => (int) $ship['id']]); }
    }

    private function createCraftedMissileItem(int $shipId, string $now): array
    {
        $publicId = OthersRepository::publicId('item');
        $this->others->pdo()->prepare("INSERT INTO others_inventory_items (public_id,ship_id,type,container_space,reserved_action_id,created_at,updated_at) VALUES (:public_id,:ship_id,'missile',2,NULL,:now,:now)")->execute(['public_id' => $publicId, 'ship_id' => $shipId, 'now' => $now]);
        return ['kind' => 'missile', 'id' => $publicId, 'containerSpaceEce' => 2.0];
    }

    private function processLaser(array $action, string $now): void
    {
        $pdo = $this->others->pdo(); $stmt = $pdo->prepare('SELECT l.*,s.public_id AS ship_public_id,s.status AS ship_status,s.destroyed_at,s.deuterium_stock FROM others_laser_locks l JOIN others_ships s ON s.id=l.ship_id WHERE l.action_id=:action_id'); $stmt->execute(['action_id' => (int) $action['id']]); $lock = $stmt->fetch();
        if (!$lock || in_array((string) $lock['status'], ['stopped','failed'], true)) { return; }
        if ($lock['status'] === 'queued') {
            $target = $this->resolveLocalTarget(['sector_x' => $lock['sector_x'], 'sector_y' => $lock['sector_y'], 'sector_z' => $lock['sector_z'], 'status' => $lock['ship_status'], 'destroyed_at' => $lock['destroyed_at']], (string) $lock['target_public_id'], laserOnly: true);
            if ($target === null) { $this->stopLaser($action, $lock, $now, 'target_unavailable'); return; }
            $start = new \DateTimeImmutable($now); $exhausts = $start->modify('+' . max(1, (int) round((float) $lock['deuterium_stock'] * 60)) . ' seconds'); $damage = $start->modify('+10 minutes'); $next = $damage < $exhausts ? $damage : $exhausts;
            $pdo->prepare("UPDATE others_laser_locks SET status='active',started_at=:now,accounted_until=:now,next_damage_at=:damage,exhausts_at=:exhausts,updated_at=:now WHERE id=:id AND status='queued'")->execute(['now' => $now, 'damage' => $damage->format('c'), 'exhausts' => $exhausts->format('c'), 'id' => (int) $lock['id']]);
            $pdo->prepare("UPDATE others_actions SET status='running',ends_at=:ends,updated_at=:now WHERE id=:id AND status='queued'")->execute(['ends' => $next->format('c'), 'now' => $now, 'id' => (int) $action['id']]);
            $lockSector = new SectorCoordinates((int) $lock['sector_x'], (int) $lock['sector_y'], (int) $lock['sector_z']);
            $message = $target['kind'] === 'manny'
                ? 'Laser lock: the exposed Manny ' . $target['name'] . ' will be destroyed in ten minutes unless it is embarked.'
                : 'Laser lock detected: a probe target may lose 5 integrity points every ten minutes.';
            $this->createWeaponAlerts($lockSector, (string) $action['public_id'], $message, $damage->format('c'));
            if ($target['kind'] === 'manny') {
                $this->createRemoteMannyLaserAlert($lockSector, (string) $target['id'], (string) $action['public_id'], $damage->format('c'));
            }
            $this->scheduleExistingAction($action, $next->format('c'), 'running'); return;
        }
        $ship = $this->others->findShipByPublicId((string) $lock['ship_public_id']);
        if ($ship === null || $ship['status'] === 'transit' || !$this->sameCoordinates($ship, $lock)) { $this->stopLaser($action, $lock, $now, 'emitter_unavailable'); return; }
        $target = $this->resolveLocalTarget($ship, (string) $lock['target_public_id'], laserOnly: true);
        if ($target === null || $target['kind'] !== $lock['target_kind']) { $this->stopLaser($action, $lock, $now, 'target_lost'); return; }
        $accounted = new \DateTimeImmutable((string) $lock['accounted_until']); $current = new \DateTimeImmutable($now); $elapsed = max(0, $current->getTimestamp() - $accounted->getTimestamp()); $cost = round($elapsed / 60, 4); $available = (float) $ship['deuterium_stock']; $charged = min($available, $cost);
        if ($charged > 0.0) { $pdo->prepare('UPDATE others_ships SET deuterium_stock=CASE WHEN deuterium_stock > :stock_floor THEN deuterium_stock - :stock_decrease ELSE 0 END,updated_at=:now WHERE id=:id')->execute(['stock_floor' => $charged, 'stock_decrease' => $charged, 'now' => $now, 'id' => (int) $ship['id']]); }
        $pdo->prepare('UPDATE others_laser_locks SET accounted_until=:now,updated_at=:now WHERE id=:id')->execute(['now' => $now, 'id' => (int) $lock['id']]);
        if ($available <= $cost + 0.00001 || $current >= new \DateTimeImmutable((string) $lock['exhausts_at'])) { $this->stopLaser($action, $lock, $now, 'deuterium_exhausted'); return; }
        if ($current >= new \DateTimeImmutable((string) $lock['next_damage_at'])) {
            $damageKey = 'laser:' . $action['public_id'] . ':' . $lock['next_damage_at'];
            try {
                $pdo->prepare('INSERT INTO others_damage_events (event_key,target_kind,target_public_id,damage,created_at) VALUES (:key,:kind,:target,:damage,:now)')->execute(['key' => $damageKey, 'kind' => $target['kind'], 'target' => $target['id'], 'damage' => $target['kind'] === 'probe' ? 5 : ($target['kind'] === 'manny' ? 1 : 0), 'now' => $now]);
                if ($target['kind'] === 'probe' && $this->probes !== null) { $probe = $this->probes->findById((int) $target['id']); if ($probe !== null) { $probe->subtractIntegrityPercent(5.0); $this->probes->save($probe); } }
                elseif ($target['kind'] === 'manny') { $pdo->prepare('DELETE FROM mannies WHERE uid=:uid AND location_type=\'sector\'')->execute(['uid' => $target['id']]); if ($this->sectors !== null) { $sector = $this->sectors->getOrCreateSector(new SectorCoordinates((int) $lock['sector_x'], (int) $lock['sector_y'], (int) $lock['sector_z'])); if ($sector->removeObjectById('manny-' . $target['id'])) { $this->sectors->saveSector($sector); } } $this->stopLaser($action, $lock, $now, 'target_destroyed'); return; }
            } catch (\PDOException $error) { if (!str_contains(strtolower($error->getMessage()), 'unique')) { throw $error; } }
            $nextDamage = (new \DateTimeImmutable((string) $lock['next_damage_at']))->modify('+10 minutes');
            $pdo->prepare('UPDATE others_laser_locks SET next_damage_at=:next,updated_at=:now WHERE id=:id')->execute(['next' => $nextDamage->format('c'), 'now' => $now, 'id' => (int) $lock['id']]);
            $next = $nextDamage < new \DateTimeImmutable((string) $lock['exhausts_at']) ? $nextDamage : new \DateTimeImmutable((string) $lock['exhausts_at']);
            $this->scheduleExistingAction($action, $next->format('c'), 'running');
        }
    }

    private function stopLaser(array $action, array $lock, string $now, string $reason): void
    {
        $nextTarget = (new \DateTimeImmutable($now))->modify('+1 minute')->format('c'); $pdo = $this->others->pdo();
        $pdo->prepare("UPDATE others_laser_locks SET status='stopped',updated_at=:now WHERE id=:id AND status IN ('queued','active')")->execute(['now' => $now, 'id' => (int) $lock['id']]);
        $pdo->prepare('UPDATE others_ships SET laser_next_target_at=:next,updated_at=:now WHERE id=:id')->execute(['next' => $nextTarget, 'now' => $now, 'id' => (int) $lock['ship_id']]);
        $pdo->prepare("UPDATE others_actions SET status='succeeded',result_json=:result,completed_at=:now,updated_at=:now WHERE id=:id AND status IN ('queued','running')")->execute(['result' => json_encode(['outcome' => 'stopped', 'reason' => $reason, 'nextTargetAt' => $nextTarget], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    public function processScheduledMannyMissile(ScheduledEvent $event): bool
    {
        if ($event->entityType !== 'manny' || $this->mannies === null) { return false; }
        $manny = $this->mannies->findById($event->entityId);
        if ($manny === null || $manny->currentTask !== Manny::TASK_PREPARING_MISSILE || $manny->taskScheduledEventId !== $event->id) { return false; }
        $missileId = $manny->taskPayload['missileLaunchId'] ?? null;
        if (!is_string($missileId) || $missileId === '') { throw new \RuntimeException('Missile preparation has no launch identity.'); }
        $this->others->transaction(function () use ($manny, $missileId): void {
            $pdo = $this->others->pdo(); $stmt = $pdo->prepare("SELECT * FROM missile_launches WHERE public_id=:public_id AND status='preparing'"); $stmt->execute(['public_id' => $missileId]); $launch = $stmt->fetch();
            if (!$launch) { $this->clearMissileMannyTask($manny); return; }
            $probe = $this->probes?->findById((int) $launch['probe_id']);
            $validCarrier = $probe !== null && $manny->isOnProbe() && $manny->probeId === $probe->id
                && [$probe->currentSector->getX(),$probe->currentSector->getY(),$probe->currentSector->getZ()] === [(int)$launch['sector_x'],(int)$launch['sector_y'],(int)$launch['sector_z']];
            $target = $validCarrier ? $this->resolveMissileTarget((int)$launch['sector_x'],(int)$launch['sector_y'],(int)$launch['sector_z'],(string)$launch['target_public_id']) : null;
            if (!$validCarrier || $target === null || $target['kind'] !== $launch['target_kind']) {
                $pdo->prepare("UPDATE missile_launches SET status='failed',result='launch_preconditions_lost',updated_at=:now WHERE id=:id AND status='preparing'")->execute(['now' => gmdate('c'), 'id' => (int)$launch['id']]);
                $this->clearMissileMannyTask($manny); return;
            }
            $itemStmt = $pdo->prepare("SELECT * FROM probe_items WHERE id=:id AND probe_id=:probe_id AND type='missile'"); $itemStmt->execute(['id' => (int)$launch['probe_item_id'], 'probe_id' => (int)$launch['probe_id']]);
            if (!$itemStmt->fetch()) {
                $pdo->prepare("UPDATE missile_launches SET status='failed',result='missile_item_lost',updated_at=:now WHERE id=:id")->execute(['now' => gmdate('c'), 'id' => (int)$launch['id']]);
                $this->clearMissileMannyTask($manny); return;
            }
            $now = gmdate('c'); $pdo->prepare('UPDATE missile_launches SET probe_item_id=NULL,updated_at=:now WHERE id=:id')->execute(['now' => $now, 'id' => (int)$launch['id']]);
            $pdo->prepare('DELETE FROM probe_items WHERE id=:id')->execute(['id' => (int)$launch['probe_item_id']]);
            $this->createProjectile($launch, null, $target, $now);
            $this->clearMissileMannyTask($manny);
        });
        return true;
    }

    private function clearMissileMannyTask(Manny $manny): void
    {
        if ($this->mannies === null) { return; }
        $manny->currentTask = null; $manny->taskStartedAt = null; $manny->taskEndsAt = null;
        $manny->taskPayload = ['lastTask' => Manny::TASK_PREPARING_MISSILE, 'result' => 'completed'];
        $this->mannies->save($manny);
    }

    private function launchOthersProjectile(array $action, string $now): void
    {
        $pdo = $this->others->pdo(); $stmt = $pdo->prepare("SELECT * FROM missile_launches WHERE others_action_id=:action_id AND status='queued'"); $stmt->execute(['action_id' => (int)$action['id']]); $launch = $stmt->fetch();
        if (!$launch) { return; }
        $ship = $this->others->findShipByPublicId((string)$launch['launcher_public_id']);
        $target = $this->resolveMissileTarget((int)$launch['sector_x'],(int)$launch['sector_y'],(int)$launch['sector_z'],(string)$launch['target_public_id']);
        if ($ship === null || $ship['destroyed_at'] !== null || $ship['status'] === 'transit' || !$this->sameCoordinates($ship, $launch) || $target === null || $target['kind'] !== $launch['target_kind']) {
            $pdo->prepare('UPDATE others_inventory_items SET reserved_action_id=NULL,updated_at=:now WHERE id=:id AND reserved_action_id=:action_id')->execute(['now'=>$now,'id'=>(int)$launch['others_item_id'],'action_id'=>(int)$action['id']]);
            $pdo->prepare("UPDATE missile_launches SET status='failed',result='launch_preconditions_lost',updated_at=:now WHERE id=:id")->execute(['now'=>$now,'id'=>(int)$launch['id']]);
            $pdo->prepare("UPDATE others_actions SET status='failed',error_json=:error,completed_at=:now,updated_at=:now WHERE id=:id AND status='queued'")->execute(['error'=>json_encode(['code'=>'target_not_found','message'=>'The missile target is no longer admissible.'],JSON_THROW_ON_ERROR),'now'=>$now,'id'=>(int)$action['id']]); return;
        }
        $pdo->prepare('UPDATE missile_launches SET others_item_id=NULL,updated_at=:now WHERE id=:id')->execute(['now'=>$now,'id'=>(int)$launch['id']]);
        $deleted = $pdo->prepare("DELETE FROM others_inventory_items WHERE id=:id AND reserved_action_id=:action_id AND type='missile'"); $deleted->execute(['id'=>(int)$launch['others_item_id'],'action_id'=>(int)$action['id']]);
        if ($deleted->rowCount() !== 1) { throw new \RuntimeException('Reserved Others missile item is inconsistent.'); }
        $this->createProjectile($launch, $action, $target, $now);
    }

    private function createProjectile(array $launch, ?array $action, array $target, string $now): void
    {
        $pdo = $this->others->pdo(); $interception = $target['kind'] === 'missile'; $impactAt = (new \DateTimeImmutable($now))->modify($interception ? '+15 minutes' : '+30 minutes')->format('c');
        $pdo->prepare("INSERT INTO others_projectiles (public_id,launch_id,action_id,launcher_kind,launcher_public_id,target_public_id,target_kind,sector_x,sector_y,sector_z,status,launched_at,impact_at,created_at,updated_at) VALUES (:public_id,:launch_id,:action_id,:launcher_kind,:launcher_public_id,:target_public_id,:target_kind,:x,:y,:z,'moving',:launched_at,:impact_at,:created_at,:updated_at)")->execute([
            'public_id'=>(string)$launch['public_id'],'launch_id'=>(int)$launch['id'],'action_id'=>$action !== null ? (int)$action['id'] : null,'launcher_kind'=>(string)$launch['launcher_kind'],'launcher_public_id'=>(string)$launch['launcher_public_id'],'target_public_id'=>(string)$launch['target_public_id'],'target_kind'=>(string)$launch['target_kind'],'x'=>(int)$launch['sector_x'],'y'=>(int)$launch['sector_y'],'z'=>(int)$launch['sector_z'],'launched_at'=>$now,'impact_at'=>$impactAt,'created_at'=>$now,'updated_at'=>$now,
        ]);
        $projectileId = (int)$pdo->lastInsertId(); $event = $this->events->schedule(SchedulerService::MISSILE_PROJECTILE,'missile_projectile',$projectileId,$impactAt,['projectileId'=>(string)$launch['public_id']]);
        $pdo->prepare("UPDATE missile_launches SET status='launched',projectile_public_id=:projectile,impact_at=:impact_at,scheduled_event_id=:event_id,updated_at=:now WHERE id=:id")->execute(['projectile'=>(string)$launch['public_id'],'impact_at'=>$impactAt,'event_id'=>$event->id,'now'=>$now,'id'=>(int)$launch['id']]);
        if ($action !== null) { $pdo->prepare("UPDATE others_actions SET status='running',ends_at=:impact_at,scheduled_event_id=:event_id,updated_at=:now WHERE id=:id AND status='queued'")->execute(['impact_at'=>$impactAt,'event_id'=>$event->id,'now'=>$now,'id'=>(int)$action['id']]); }
        $this->createWeaponAlerts(
            new SectorCoordinates((int) $launch['sector_x'], (int) $launch['sector_y'], (int) $launch['sector_z']),
            (string) $launch['public_id'],
            'Kinetic weapon launch detected; suspected target: ' . $this->missileTargetLabel($target) . '; estimated resolution in ' . ($interception ? 'fifteen' : 'thirty') . ' minutes.',
            $impactAt,
            $target,
        );
    }

    public function processScheduledProjectile(ScheduledEvent $event): void
    {
        $this->others->transaction(function () use ($event): void {
            $pdo=$this->others->pdo(); $stmt=$pdo->prepare("SELECT p.*,l.player_id,l.id AS launch_sql_id,a.public_id AS action_public_id FROM others_projectiles p JOIN missile_launches l ON l.id=p.launch_id LEFT JOIN others_actions a ON a.id=p.action_id WHERE p.id=:id AND p.status='moving'"); $stmt->execute(['id'=>$event->entityId]); $projectile=$stmt->fetch(); if(!$projectile){return;}
            $target=$this->resolveMissileTarget((int)$projectile['sector_x'],(int)$projectile['sector_y'],(int)$projectile['sector_z'],(string)$projectile['target_public_id']);
            $targetIdentity = $target ?? ['kind' => (string) $projectile['target_kind'], 'id' => (string) $projectile['target_public_id']];
            if($target===null || $target['kind']!==$projectile['target_kind']) { $this->finishProjectile($projectile,'lost',['reason'=>'target_lost'],$targetIdentity); return; }
            if($target['kind']==='missile') { $this->interceptProjectile($target,$projectile); $this->finishProjectile($projectile,'intercepted',['targetId'=>$target['id']],$target); return; }
            $probability=$this->missileHitProbability($target,$projectile); $roll=$this->stableFraction((string)$projectile['public_id'].'|'.$target['id'].'|'.$projectile['impact_at'].'|hit');
            if($roll >= $probability) { $this->finishProjectile($projectile,'missed',['probability'=>$probability],$target); return; }
            $details=$this->applyMissileImpact($projectile,$target); $this->finishProjectile($projectile,'impacted',$details+['probability'=>$probability],$target);
        });
    }

    /** @param array<string, mixed> $target */
    private function finishProjectile(array $projectile,string $result,array $details,array $target): void
    {
        $pdo=$this->others->pdo(); $now=gmdate('c'); $actionPublicId=(string)($projectile['action_public_id']??'');
        $pdo->prepare('INSERT INTO others_projectile_history (projectile_public_id,action_public_id,result,details_json,resolved_at) VALUES (:projectile,:action,:result,:details,:resolved_at)')->execute(['projectile'=>(string)$projectile['public_id'],'action'=>$actionPublicId,'result'=>$result,'details'=>json_encode($details,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'resolved_at'=>$now]);
        $pdo->prepare("UPDATE missile_launches SET status='resolved',result=:result,updated_at=:now WHERE id=:id")->execute(['result'=>$result,'now'=>$now,'id'=>(int)$projectile['launch_sql_id']]);
        if($projectile['action_id']!==null){$pdo->prepare("UPDATE others_actions SET status='succeeded',result_json=:details,completed_at=:now,updated_at=:now WHERE id=:id AND status='running'")->execute(['details'=>json_encode(['outcome'=>$result]+$details,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'now'=>$now,'id'=>(int)$projectile['action_id']]);}
        $this->createProjectileResolutionAlerts($projectile, $target, $result, $details, $now);
        $pdo->prepare('DELETE FROM others_projectiles WHERE id=:id')->execute(['id'=>(int)$projectile['id']]);
    }

    private function interceptProjectile(array $target,array $interceptor): void
    {
        $pdo=$this->others->pdo(); $stmt=$pdo->prepare("SELECT p.*,l.player_id,l.id AS launch_sql_id,a.public_id AS action_public_id FROM others_projectiles p JOIN missile_launches l ON l.id=p.launch_id LEFT JOIN others_actions a ON a.id=p.action_id WHERE p.public_id=:id AND p.status='moving'"); $stmt->execute(['id'=>$target['id']]); $projectile=$stmt->fetch();
        if($projectile){$this->finishProjectile($projectile,'intercepted',['interceptorId'=>$interceptor['public_id']],['kind'=>(string)$projectile['target_kind'],'id'=>(string)$projectile['target_public_id']]);}
    }

    /** @return array<string,mixed> */
    private function applyMissileImpact(array $projectile,array $target): array
    {
        $pdo=$this->others->pdo(); $key='missile:'.$projectile['public_id'].':'.$target['kind'].':'.$target['id']; $damage=match($target['kind']){'probe'=>(12+(int)floor($this->stableFraction($key.'|damage')*7)),'others_ship'=>10,default=>1};
        if($target['kind']==='others_ship'){$before=$this->others->findShipByPublicId((string)$target['id']);$ship=$this->damageShip((string)$target['id'],$damage,$key);$applied=max(0,(int)($before['integrity']??0)-(int)($ship['integrity']??0));$maximum=max(1,(int)($before['max_integrity']??1));return ['damage'=>$applied,'damagePercent'=>round(100*$applied/$maximum,2),'destroyed'=>$ship===null||$ship['destroyed_at']!==null];}
        try{$pdo->prepare('INSERT INTO others_damage_events (event_key,target_kind,target_public_id,damage,created_at) VALUES (:key,:kind,:target,:damage,:now)')->execute(['key'=>$key,'kind'=>$target['kind'],'target'=>$target['id'],'damage'=>$damage,'now'=>gmdate('c')]);}catch(\PDOException $e){if(str_contains(strtolower($e->getMessage()),'unique')){return ['damage'=>0,'replayed'=>true];}throw $e;}
        if($target['kind']==='manny'){$pdo->prepare("DELETE FROM mannies WHERE uid=:uid AND location_type='sector'")->execute(['uid'=>$target['id']]);return ['damage'=>1,'destroyed'=>true];}
        if($target['kind']==='others_auxiliary'){$this->destroyAuxiliary($target);return ['damage'=>1,'destroyed'=>true];}
        if($target['kind']==='probe' && $this->probes!==null){$probe=$this->probes->findById((int)$target['id']);$applied=0.0;if($probe!==null){$applied=$probe->subtractIntegrityPercent($damage);$this->probes->save($probe);}return ['damage'=>$applied,'damagePercent'=>$applied,'destroyed'=>$probe?->status===ProbeStatus::Dead];}
        if ($target['kind'] === 'motorized_asteroid') {
            $trajectoryId = (int) $target['trajectory_id'];
            $now = gmdate('c');
            $stmt = $pdo->prepare("UPDATE asteroid_trajectories SET missile_hits=missile_hits+1,updated_at=:now WHERE id=:id AND status IN ('accelerating','coasting','crossing_sector','orbiting_black_hole')");
            $stmt->execute(['now' => $now, 'id' => $trajectoryId]);
            $hitsStmt = $pdo->prepare('SELECT missile_hits FROM asteroid_trajectories WHERE id=:id');
            $hitsStmt->execute(['id' => $trajectoryId]);
            $hits = (int) $hitsStmt->fetchColumn();
            if ($hits >= 3) {
                $pdo->prepare("UPDATE asteroid_trajectories SET status='destroyed',result='destroyed_by_missiles',updated_at=:now WHERE id=:id")
                    ->execute(['now' => $now, 'id' => $trajectoryId]);
                $this->events->cancelPending(SchedulerService::ASTEROID_TRAJECTORY_PHASE, 'asteroid_trajectory', $trajectoryId);
                if ($this->sectors !== null) {
                    $sector = $this->sectors->getOrCreateSector(new SectorCoordinates((int) $projectile['sector_x'], (int) $projectile['sector_y'], (int) $projectile['sector_z']));
                    $changed = $sector->removeObjectById((string) $target['id']);
                    $hadContainers = $sector->containersForObject((string) $target['id']) !== [];
                    $sector->removeContainersForObject((string) $target['id']);
                    $changed = $hadContainers || $changed;
                    if ($changed) {
                        $this->sectors->saveSector($sector);
                    }
                }
            }
            return ['damage' => 1, 'hits' => $hits, 'destroyed' => $hits >= 3];
        }
        return ['damage'=>$damage];
    }

    private function destroyAuxiliary(array $target): void
    {
        $pdo=$this->others->pdo();$now=gmdate('c');$pdo->prepare("UPDATE others_actions SET auxiliary_id=NULL,status=CASE WHEN status IN ('queued','running') THEN 'failed' ELSE status END,completed_at=CASE WHEN status IN ('queued','running') THEN :now ELSE completed_at END,updated_at=:now WHERE auxiliary_id=:id")->execute(['now'=>$now,'id'=>(int)$target['sql_id']]);
        $pdo->prepare("UPDATE others_auxiliaries SET status='destroyed',current_action_id=NULL,destroyed_at=:now,updated_at=:now WHERE id=:id AND destroyed_at IS NULL")->execute(['now'=>$now,'id'=>(int)$target['sql_id']]);
        if($this->sectors!==null){$sector=$this->sectors->getOrCreateSector(new SectorCoordinates((int)$target['sector_x'],(int)$target['sector_y'],(int)$target['sector_z']));$wreck=DormantConstruct::fromOthersAuxiliary((string)$target['id'],true);if($sector->findObjectById($wreck->getId())===null){$sector->addObject($wreck);$this->sectors->saveSector($sector);}}
    }

    /** Applies one idempotent damage event and returns the remaining ship row when it still exists. */
    public function damageShip(string $shipPublicId,int $damage,string $eventKey,bool $relativistic=false): ?array
    {
        return $this->others->transaction(function()use($shipPublicId,$damage,$eventKey,$relativistic):?array{$pdo=$this->others->pdo();$ship=$this->others->findShipByPublicId($shipPublicId);if($ship===null||$ship['destroyed_at']!==null){return $ship;}
            $exists=$pdo->prepare('SELECT 1 FROM others_damage_events WHERE event_key=:key');$exists->execute(['key'=>$eventKey]);if($exists->fetchColumn()!==false){return $ship;}
            $applied=$relativistic?(int)$ship['integrity']:min(max(0,$damage),(int)$ship['integrity']);$pdo->prepare('INSERT INTO others_damage_events (event_key,target_kind,target_public_id,damage,created_at) VALUES (:key,\'others_ship\',:target,:damage,:now)')->execute(['key'=>$eventKey,'target'=>$shipPublicId,'damage'=>$applied,'now'=>gmdate('c')]);
            $remaining=$relativistic?0:max(0,(int)$ship['integrity']-$applied);$pdo->prepare('UPDATE others_ships SET integrity=:integrity,updated_at=:now WHERE id=:id')->execute(['integrity'=>$remaining,'now'=>gmdate('c'),'id'=>(int)$ship['id']]);if($remaining===0){$this->destroyShip($ship,$eventKey);return $this->others->findShipByPublicId($shipPublicId);}return $this->others->findShipByPublicId($shipPublicId);});
    }

    private function destroyShip(array $ship, string $eventKey): void
    {
        $pdo = $this->others->pdo();
        $now = gmdate('c');
        $ships = $ship['type'] === 'mothership'
            ? $this->others->findActiveShipsByFleetId((int) $ship['fleet_id'])
            : [$ship];
        foreach ($ships as $victim) {
            $this->turnDeployedAuxiliariesDormant((int) $victim['id'], $now);
            $pdo->prepare('UPDATE others_actions SET auxiliary_id=NULL WHERE ship_id=:ship_id')->execute(['ship_id' => (int) $victim['id']]);
            $pdo->prepare("UPDATE missile_launches SET status='failed',result='carrier_destroyed',others_item_id=NULL,updated_at=:now WHERE others_action_id IN (SELECT id FROM others_actions WHERE ship_id=:ship_id) AND status='queued'")
                ->execute(['now' => $now, 'ship_id' => (int) $victim['id']]);
            $pdo->prepare("UPDATE others_actions SET status='failed',error_json=:error,completed_at=:now,updated_at=:now WHERE ship_id=:ship_id AND status IN ('queued','running','cancel_requested')")
                ->execute(['error' => json_encode(['code' => 'carrier_destroyed', 'message' => 'The carrier was destroyed.'], JSON_THROW_ON_ERROR), 'now' => $now, 'ship_id' => (int) $victim['id']]);
            $pdo->prepare('DELETE FROM others_auxiliaries WHERE ship_id=:ship_id')->execute(['ship_id' => (int) $victim['id']]);
            $pdo->prepare('DELETE FROM others_inventory_items WHERE ship_id=:ship_id')->execute(['ship_id' => (int) $victim['id']]);
            $pdo->prepare('DELETE FROM others_inventory_resources WHERE ship_id=:ship_id')->execute(['ship_id' => (int) $victim['id']]);
            $status = (int) $victim['id'] === (int) $ship['id'] ? 'destroyed' : 'removed';
            $pdo->prepare('UPDATE others_ships SET integrity=0,status=:status,current_action_id=NULL,destroyed_at=:now,updated_at=:now WHERE id=:id AND destroyed_at IS NULL')
                ->execute(['status' => $status, 'now' => $now, 'id' => (int) $victim['id']]);
        }
        if ($ship['type'] === 'mothership') {
            $pdo->prepare("UPDATE others_fleets SET status='dissolved',dissolved_at=:now,updated_at=:now WHERE id=:id AND status='active'")
                ->execute(['now' => $now, 'id' => (int) $ship['fleet_id']]);
        }
    }

    private function missileHitProbability(array $target,array $projectile): float
    {
        if($target['kind']==='motorized_asteroid'){return (float)$target['hit_probability'];}
        if($target['kind']==='others_ship' && !empty($target['departure_engaged'])){return 0.5;}
        if($target['kind']==='probe' && !empty($target['departure_engaged'])){return 0.0;}
        return 0.95;
    }

    private function stableFraction(string $seed): float { return hexdec(substr(hash('sha256',$seed),0,8))/4294967296; }

    /** @return array<string,mixed>|null */
    private function resolveMissileTarget(int $x,int $y,int $z,string $targetId): ?array
    {
        $pdo=$this->others->pdo();$key="$x:$y:$z";
        $stmt=$pdo->prepare("SELECT id,public_id,departure_engaged FROM others_ships WHERE public_id=:id AND sector_x=:x AND sector_y=:y AND sector_z=:z AND destroyed_at IS NULL AND status<>'transit' AND status<>'removed'");$stmt->execute(['id'=>$targetId,'x'=>$x,'y'=>$y,'z'=>$z]);if($row=$stmt->fetch()){return ['kind'=>'others_ship','id'=>(string)$row['public_id'],'sql_id'=>(int)$row['id'],'departure_engaged'=>(bool)$row['departure_engaged']];}
        $stmt=$pdo->prepare("SELECT id,public_id,sector_x,sector_y,sector_z FROM others_auxiliaries WHERE public_id=:id AND sector_x=:x AND sector_y=:y AND sector_z=:z AND location_type='deployed' AND destroyed_at IS NULL AND status<>'dormant'");$stmt->execute(['id'=>$targetId,'x'=>$x,'y'=>$y,'z'=>$z]);if($row=$stmt->fetch()){return ['kind'=>'others_auxiliary','id'=>(string)$row['public_id'],'sql_id'=>(int)$row['id'],'sector_x'=>$x,'sector_y'=>$y,'sector_z'=>$z];}
        if(ctype_digit($targetId)&&$this->probes!==null){$probe=$this->probes->findById((int)$targetId);if($probe!==null&&$probe->currentSector->toKey()===$key&&!in_array($probe->status,[ProbeStatus::Dead,ProbeStatus::Accelerating,ProbeStatus::Cruising,ProbeStatus::Decelerating],true)){return ['kind'=>'probe','id'=>$targetId,'departure_engaged'=>$probe->status===ProbeStatus::Preparing];}}
        if($this->mannies!==null){$manny=$this->mannies->findByUid($targetId);if($manny!==null&&$manny->locationType===Manny::LOCATION_SECTOR&&$manny->sector?->toKey()===$key){return ['kind'=>'manny','id'=>$targetId];}}
        $stmt=$pdo->prepare("SELECT public_id FROM others_projectiles WHERE public_id=:id AND sector_x=:x AND sector_y=:y AND sector_z=:z AND status='moving'");$stmt->execute(['id'=>$targetId,'x'=>$x,'y'=>$y,'z'=>$z]);if($stmt->fetch()){return ['kind'=>'missile','id'=>$targetId];}
        $stmt=$pdo->prepare("SELECT * FROM asteroid_trajectories WHERE (asteroid_id=:id OR uid=:id) AND current_sector_x=:x AND current_sector_y=:y AND current_sector_z=:z AND status IN ('accelerating','coasting','crossing_sector','orbiting_black_hole') ORDER BY id DESC LIMIT 1");$stmt->execute(['id'=>$targetId,'x'=>$x,'y'=>$y,'z'=>$z]);if($row=$stmt->fetch()){$ratio=0.0;if((float)$row['target_speed_c']>0&&is_string($row['acceleration_started_at'])&&is_string($row['acceleration_ends_at'])){$start=strtotime($row['acceleration_started_at']);$end=strtotime($row['acceleration_ends_at']);$ratio=$end>$start?max(0.0,min(1.0,(time()-$start)/($end-$start))):1.0;}return ['kind'=>'motorized_asteroid','id'=>(string)$row['asteroid_id'],'trajectory_id'=>(int)$row['id'],'hit_probability'=>1.0-(2.0/3.0)*$ratio];}
        return null;
    }

    private function resolveLocalTarget(array $ship, string $targetId, bool $laserOnly = false): ?array
    {
        $x = (int) $ship['sector_x']; $y = (int) $ship['sector_y']; $z = (int) $ship['sector_z'];
        if (ctype_digit($targetId) && $this->probes !== null) { $probe = $this->probes->findById((int) $targetId); if ($probe !== null && $probe->currentSector->toKey() === "$x:$y:$z" && !in_array($probe->status->value, ['dead','accelerating','cruising','decelerating'], true)) { return ['kind' => 'probe', 'id' => $targetId]; } }
        if ($this->mannies !== null) { $manny = $this->mannies->findByUid($targetId); if ($manny !== null && $manny->locationType === 'sector' && $manny->sector?->toKey() === "$x:$y:$z") { return ['kind' => 'manny', 'id' => $targetId, 'name' => $manny->name]; } }
        if ($this->sectors !== null) { $object = $this->sectors->getOrCreateSector(new SectorCoordinates($x, $y, $z))->findObjectById($targetId); if ($object instanceof Planet) { return ['kind' => 'planet', 'id' => $targetId]; } if ($object instanceof Asteroid) { return ['kind' => 'asteroid', 'id' => $targetId]; } }
        return null;
    }

    private function sameCoordinates(array $a, array $b): bool { return [(int) $a['sector_x'],(int) $a['sector_y'],(int) $a['sector_z']] === [(int) $b['sector_x'],(int) $b['sector_y'],(int) $b['sector_z']]; }

    /**
     * @param array<string, mixed> $projectile
     * @param array<string, mixed> $target
     * @param array<string, mixed> $details
     */
    private function createProjectileResolutionAlerts(array $projectile, array $target, string $result, array $details, string $resolvedAt): void
    {
        $sector = new SectorCoordinates((int) $projectile['sector_x'], (int) $projectile['sector_y'], (int) $projectile['sector_z']);
        $targetLabel = $this->missileTargetLabel($target);
        $resultMessage = $this->missileResultMessage((string) $projectile['public_id'], $targetLabel, $result, $details);
        $resultEventKey = 'weapon-result-' . (string) $projectile['public_id'];

        if (($projectile['launcher_kind'] ?? null) === 'probe' && $this->probes !== null && $this->alerts !== null) {
            $launcher = $this->probes->findById((int) $projectile['launcher_public_id']);
            if ($launcher !== null && $this->probeIsPresentInSector($launcher, $sector)) {
                $this->alerts->createOthersAlert($launcher->id, null, ProbeDamageWarning::TYPE_OTHERS_WEAPON, $resultEventKey, $sector, $resultMessage, ProbeDamageWarning::PHASE_WEAPON_RESULT, $resolvedAt);
            }
        } elseif (($projectile['launcher_kind'] ?? null) === 'others_ship') {
            $launcher = $this->others->findShipByPublicId((string) $projectile['launcher_public_id']);
            if ($launcher !== null && $this->othersShipIsPresentInSector($launcher, $sector)) {
                $this->others->createAlert((int) $launcher['player_id'], (string) $launcher['public_id'], 'missile_resolution', ProbeDamageWarning::PHASE_WEAPON_RESULT, $resultEventKey, $resultMessage);
            }
        }

        if ($result !== 'impacted') {
            return;
        }
        $victimMessage = $this->missileVictimMessage((string) $projectile['public_id'], $targetLabel, $details);
        $victimEventKey = 'weapon-damage-' . (string) $projectile['public_id'];
        if (($target['kind'] ?? null) === 'probe' && $this->probes !== null && $this->alerts !== null) {
            $victim = $this->probes->findById((int) $target['id']);
            if ($victim !== null) {
                $this->alerts->createOthersAlert($victim->id, null, ProbeDamageWarning::TYPE_OTHERS_WEAPON, $victimEventKey, $sector, $victimMessage, ProbeDamageWarning::PHASE_WEAPON_DAMAGE, $resolvedAt);
            }
        } elseif (($target['kind'] ?? null) === 'others_ship') {
            $victim = $this->others->findShipByPublicId((string) $target['id']);
            if ($victim !== null) {
                $this->others->createAlert((int) $victim['player_id'], (string) $victim['public_id'], 'missile_damage', ProbeDamageWarning::PHASE_WEAPON_DAMAGE, $victimEventKey, $victimMessage);
            }
        }
    }

    private function probeIsPresentInSector(NeumannProbe $probe, SectorCoordinates $sector): bool
    {
        return $probe->currentSector->toKey() === $sector->toKey()
            && !in_array($probe->status, [ProbeStatus::Dead, ProbeStatus::Accelerating, ProbeStatus::Cruising, ProbeStatus::Decelerating], true);
    }

    /** @param array<string, mixed> $ship */
    private function othersShipIsPresentInSector(array $ship, SectorCoordinates $sector): bool
    {
        return $ship['destroyed_at'] === null
            && !in_array((string) $ship['status'], ['transit', 'destroyed', 'removed'], true)
            && [(int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']] === [$sector->getX(), $sector->getY(), $sector->getZ()];
    }

    /** @param array<string, mixed> $details */
    private function missileResultMessage(string $missileId, string $targetLabel, string $result, array $details): string
    {
        return match ($result) {
            'impacted' => !empty($details['destroyed'])
                ? 'Missile ' . $missileId . ' impacted target ' . $targetLabel . '; target destroyed.'
                : 'Missile ' . $missileId . ' impacted target ' . $targetLabel . $this->missileDamageSuffix($details),
            'missed' => 'Missile ' . $missileId . ' missed target ' . $targetLabel . '.',
            'intercepted' => 'Missile ' . $missileId . ' was intercepted before reaching target ' . $targetLabel . '.',
            'lost' => 'Missile ' . $missileId . ' lost target ' . $targetLabel . '.',
            default => 'Missile ' . $missileId . ' resolved with outcome ' . $result . ' against target ' . $targetLabel . '.',
        };
    }

    /** @param array<string, mixed> $details */
    private function missileVictimMessage(string $missileId, string $targetLabel, array $details): string
    {
        return !empty($details['destroyed'])
            ? 'Missile ' . $missileId . ' impacted ' . $targetLabel . '; target destroyed.'
            : 'Missile ' . $missileId . ' impacted ' . $targetLabel . $this->missileDamageSuffix($details);
    }

    /** @param array<string, mixed> $details */
    private function missileDamageSuffix(array $details): string
    {
        if (!isset($details['damagePercent']) || !is_numeric($details['damagePercent'])) {
            return '; impact confirmed.';
        }

        return '; damage: ' . rtrim(rtrim(number_format((float) $details['damagePercent'], 2, '.', ''), '0'), '.') . '% of total integrity.';
    }

    /** @param array<string, mixed>|null $target */
    private function createWeaponAlerts(SectorCoordinates $sector, string $eventKey, string $message, string $scheduledAt, ?array $target = null): void
    {
        if ($this->probes === null || $this->alerts === null) { return; }
        foreach ($this->probes->findBySector($sector) as $probe) {
            $phase = $target !== null
                && ($target['kind'] ?? null) === 'probe'
                && (string) $probe->id === (string) ($target['id'] ?? '')
                    ? ProbeDamageWarning::PHASE_WEAPON_TARGETED
                    : ProbeDamageWarning::PHASE_WEAPON;
            $this->alerts->createOthersAlert($probe->id, null, ProbeDamageWarning::TYPE_OTHERS_WEAPON, 'weapon-' . $eventKey, $sector, $message, $phase, $scheduledAt);
        }
    }

    private function createRemoteMannyLaserAlert(SectorCoordinates $sector, string $mannyId, string $eventKey, string $scheduledAt): void
    {
        if ($this->mannies === null || $this->probes === null || $this->alerts === null || $this->scut === null || $this->players === null) {
            return;
        }
        $manny = $this->mannies->findByUid($mannyId);
        if ($manny === null || $manny->probeId === null || $manny->sector === null || !$manny->sector->equals($sector)) {
            return;
        }
        $ownerProbe = $this->probes->findById($manny->probeId);
        if ($ownerProbe === null || $ownerProbe->currentSector->equals($sector) || !$this->scut->canSectorsCommunicate($ownerProbe->currentSector, $sector)) {
            return;
        }
        $owner = $this->players->findById($ownerProbe->playerId);
        if ($owner === null) {
            return;
        }
        $relative = $sector->subtract($owner->homeSector);
        $message = 'Laser lock detected on Manny ' . $manny->name
            . ' in relative sector (' . $relative['x'] . ', ' . $relative['y'] . ', ' . $relative['z'] . '). '
            . 'Unless the laser ceases, this Manny will be destroyed in ten minutes.';
        $this->alerts->createOthersAlert(
            $ownerProbe->id,
            null,
            ProbeDamageWarning::TYPE_OTHERS_WEAPON,
            'weapon-' . $eventKey,
            $sector,
            $message,
            ProbeDamageWarning::PHASE_WEAPON_TARGETED,
            $scheduledAt,
        );
    }

    /** @param array<string, mixed> $target */
    private function missileTargetLabel(array $target): string
    {
        $id = (string) ($target['id'] ?? 'unknown');
        if (($target['kind'] ?? null) === 'probe' && $this->probes !== null) {
            $probe = $this->probes->findById((int) $id);
            return $probe === null ? 'probe #' . $id : 'probe ' . $probe->name . ' (#' . $id . ')';
        }
        if (($target['kind'] ?? null) === 'manny' && $this->mannies !== null) {
            $manny = $this->mannies->findByUid($id);
            return $manny === null ? 'Manny ' . $id : 'Manny ' . $manny->name . ' (' . $id . ')';
        }

        return match ($target['kind'] ?? null) {
            'others_ship' => 'Others ship ' . $id,
            'others_auxiliary' => 'Others auxiliary ' . $id,
            'missile' => 'missile ' . $id,
            'motorized_asteroid' => 'motorized asteroid ' . $id,
            default => 'object ' . $id,
        };
    }

    private function sameSector(array $a, array $b): bool
    {
        return [(int) $a['sector_x'], (int) $a['sector_y'], (int) $a['sector_z']] === [(int) $b['sector_x'], (int) $b['sector_y'], (int) $b['sector_z']]
            && $a['status'] !== 'transit' && $b['status'] !== 'transit';
    }

    private function turnDeployedAuxiliariesDormant(int $shipId, string $now): void
    {
        if ($this->sectors === null) { throw new \RuntimeException('Sector storage is unavailable for dormant auxiliaries.'); }
        $pdo = $this->others->pdo();
        $shipStmt = $pdo->prepare('SELECT * FROM others_ships WHERE id = :id'); $shipStmt->execute(['id' => $shipId]); $ship = $shipStmt->fetch();
        if (!$ship) { return; }
        $auxStmt = $pdo->prepare("SELECT * FROM others_auxiliaries WHERE ship_id = :ship_id AND location_type = 'deployed' AND destroyed_at IS NULL ORDER BY id"); $auxStmt->execute(['ship_id' => $shipId]); $auxiliaries = $auxStmt->fetchAll();
        if ($auxiliaries === []) { return; }
        $operationId = OthersRepository::publicId('xstore');
        $pdo->prepare("INSERT INTO others_cross_store_operations (public_id, action_id, operation_type, payload_json, sql_applied, sector_applied, status, created_at, updated_at) VALUES (:public_id,NULL,'dormant_auxiliaries',:payload,0,0,'pending',:now,:now)")->execute(['public_id' => $operationId, 'payload' => json_encode(['shipId' => $ship['public_id'], 'auxiliaryIds' => array_column($auxiliaries, 'public_id')], JSON_THROW_ON_ERROR), 'now' => $now]);
        $sector = $this->sectors->getOrCreateSector(new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']));
        foreach ($auxiliaries as $auxiliary) { if ($sector->findObjectById('dormant-others-auxiliary-' . $auxiliary['public_id']) === null) { $sector->addObject(DormantConstruct::fromOthersAuxiliary((string) $auxiliary['public_id'])); } }
        $this->sectors->saveSector($sector);
        $pdo->prepare('UPDATE others_cross_store_operations SET sector_applied = 1, updated_at = :now WHERE public_id = :id')->execute(['now' => $now, 'id' => $operationId]);
        $pdo->prepare("UPDATE others_actions SET auxiliary_id = NULL WHERE auxiliary_id IN (SELECT id FROM others_auxiliaries WHERE ship_id = :ship_id AND location_type = 'deployed')")->execute(['ship_id' => $shipId]);
        $pdo->prepare("DELETE FROM others_auxiliaries WHERE ship_id = :ship_id AND location_type = 'deployed'")->execute(['ship_id' => $shipId]);
        $pdo->prepare("UPDATE others_cross_store_operations SET sql_applied = 1, status = 'succeeded', updated_at = :now WHERE public_id = :id")->execute(['now' => $now, 'id' => $operationId]);
    }

    private function createOthersArrivalAlerts(SectorCoordinates $sector, string $eventKey): void
    {
        if ($this->probes === null || $this->alerts === null) { return; }
        $entities = $this->others->observableEntitiesBySector($sector->getX(), $sector->getY(), $sector->getZ());
        $shipStates = [];
        foreach ($entities['ships'] as $ship) { $state = (string) $ship['status']; $shipStates[$state] = ($shipStates[$state] ?? 0) + 1; }
        $deployed = count($this->others->deployedAuxiliariesBySector($sector->getX(), $sector->getY(), $sector->getZ()));
        $message = 'Others presence detected: ' . count($entities['ships']) . ' ship(s), states ' . json_encode($shipStates, JSON_UNESCAPED_SLASHES) . ', ' . $deployed . ' deployed auxiliary unit(s). Do not deploy Mannys: their carrier transmissions make them immediately detectable.';
        foreach ($this->probes->findBySector($sector) as $probe) {
            $this->alerts->createOthersAlert($probe->id, null, ProbeDamageWarning::TYPE_OTHERS_PRESENCE, 'others-arrival-' . $eventKey, $sector, $message, 'arrival');
        }
    }

    private function homeRelativeTarget(SectorCoordinates $homeSector, array $payload): SectorCoordinates
    {
        $target = $payload['target'] ?? null;
        if (!is_array($target) || array_keys($target) !== ['x', 'y', 'z'] || !is_int($target['x']) || !is_int($target['y']) || !is_int($target['z'])) {
            throw new OthersActionException(400, 'bad_request', 'target must contain integer x, y and z relative coordinates.');
        }
        try {
            return $homeSector->add($target['x'], $target['y'], $target['z']);
        } catch (\Throwable) {
            throw new OthersActionException(422, 'invalid_destination', 'The relative destination is invalid.');
        }
    }
}
