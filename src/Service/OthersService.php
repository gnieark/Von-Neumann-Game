<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Service\Manny\RepairTaskHandler;
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
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\SectorDriftingItem;

final class OthersService
{
    private const RECIPES = [
        'standard_ship' => ['duration' => 604800, 'ingredients' => ['metals' => 6000.0, 'ice' => 1000.0, 'carbon_compounds' => 2000.0, 'deuterium' => 100.0], 'outputSpace' => 0.0],
        'others_auxiliary' => ['duration' => 3600, 'ingredients' => ['metals' => 5.0, 'ice' => 0.5, 'carbon_compounds' => 1.0, 'deuterium' => 0.05], 'outputSpace' => 0.0],
        'missile' => ['duration' => 1800, 'ingredients' => ['metals' => 20.0, 'ice' => 2.0, 'carbon_compounds' => 5.0, 'deuterium' => 1.0], 'outputSpace' => 2.0],
    ];
    private readonly ?OthersSectorService $sectorChanges;
    private readonly SectorGrid $grid;
    private readonly MovementDurationCalculator $durations;
    private readonly array $movementConfig;
    private readonly array $gameplayConfig;

    public function __construct(
        private readonly OthersRepository $others,
        private readonly ScheduledEventRepository $events,
        private readonly ProbeReinstantiationService $reinstantiation,
        private readonly \VonNeumannGame\Repository\Others\OthersPersistence $persistence,
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
        private readonly ?GerminationDepotService $germinationDepots = null,
        private readonly ?SectorStorageTransferService $storageTransfers = null,
        private readonly ?MannyStorageTransferService $mannyStorageTransfers = null,
    ) {
        $this->sectorChanges = $sectors === null ? null : new OthersSectorService($persistence->effects, new SectorEffectService($persistence->effects, $events, $sectors), $sectors);
        $this->grid = $grid ?? new SectorGrid();
        $this->gameplayConfig = $gameplayConfig;
        $this->movementConfig = Config::getArray($gameplayConfig, 'movement', $gameplayConfig);
        $this->durations = $durations ?? new MovementDurationCalculator($this->movementConfig);
    }

    /** @return array{missile:array<string,mixed>,action:array<string,mixed>} */
    public function launchOthersMissile(array $ship, array $payload): array
    {
        $transaction = $this->persistence->transaction;
        $locks = $this->persistence->locks;
        return $transaction->run(function () use ($locks, $ship, $payload): array {
            $locks->lock('ship', (int) $ship['id']);
            $current = $this->others->findShipByPublicId($ship['public_id']) ?? throw new OthersActionException(404, 'others_ship_not_found', 'Ship not found.');
            return $this->launchOthersMissileLocked($current, $payload);
        });
    }

    private function launchOthersMissileLocked(array $ship, array $payload): array
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
        if ($target === null || $target['kind'] === 'dormant_construct') {
            throw new OthersActionException(404, 'target_not_found', 'An admissible missile target was not found in this sector.');
        }

        return $this->persistence->transaction->run(function () use ($ship, $itemId, $target): array {
            $stmt = $this->persistence->combat->findAvailableMissile(['ship_id' => (int) $ship['id'], 'public_id' => $itemId]);
            $item = $stmt;
            if (!$item) {
                throw new OthersActionException(404, 'missile_item_not_found', 'An available missile item was not found in this ship inventory.');
            }
            $metadata=json_decode($item['metadata_json'],true,512,JSON_THROW_ON_ERROR);
            if (($metadata['technology'] ?? null) !== 'others') { throw new OthersActionException(422,'item_not_usable','This technology is not supported by the launcher.'); }
            $now = gmdate('c');
            $action = $this->others->createAction($ship, 'missile_launch', 'others_ship', (string) $ship['public_id'], ['targetId' => $target['id'], 'targetKind' => $target['kind']]);
            $missileId = OthersRepository::publicId('missile');
            $insertedLaunchId = $this->persistence->combat->createOthersLaunch([
                'public_id' => $missileId, 'launcher' => (string) $ship['public_id'], 'player_id' => (int) $ship['player_id'],
                'action_id' => (int) $action['id'], 'item_id' => (int) $item['id'], 'target' => $target['id'], 'kind' => $target['kind'],
                'x' => (int) $ship['sector_x'], 'y' => (int) $ship['sector_y'], 'z' => (int) $ship['sector_z'], 'launch_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $launchId = (int) $insertedLaunchId;
            $reserved = $this->persistence->combat->reserveItem(['action_id' => (int) $action['id'], 'now' => $now, 'id' => (int) $item['id']]);
            if ($reserved !== 1) { throw new OthersActionException(409, 'action_conflict', 'The missile item was reserved concurrently.'); }
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $now, ['expectedStatus' => 'queued']);
            $this->persistence->action->attachActionEvent(['event_id' => $event->id, 'id' => (int) $action['id']]);
            $this->persistence->combat->attachLaunchEvent(['event_id' => $event->id, 'id' => $launchId]);
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
        if ($this->probes === null || $this->mannies === null) { throw new OthersActionException(503, 'missile_service_unavailable', 'Missile service unavailable.'); }
        return $this->probes->withProbeLock($probe->id, function () use ($probe, $playerId, $mannyId, $payload): array {
            $actor = $this->mannies->findByUidForProbe($probe->id, $mannyId);
            if ($actor === null) { throw new OthersActionException(404, 'manny_not_found', 'Manny not found.'); }
            return $this->mannies->withMannyLock($actor->id, fn(): array => $this->igniteProbeMissileLocked(
                $this->probes->findById($probe->id) ?? throw new \RuntimeException('Probe disappeared under lock.'), $playerId, $mannyId, $payload,
            ));
        });
    }

    private function igniteProbeMissileLocked(NeumannProbe $probe, int $playerId, string $mannyId, array $payload): array
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
        return $this->persistence->transaction->run(function () use ($probe, $playerId, $manny, $itemId, $target): array {
            if ($target['kind'] === 'dormant_construct' && !$this->depotService()->canTarget($probe->id, $target['id'], $probe->currentSector)) {
                throw new OthersActionException(422, 'invalid_missile_target', 'This target cannot be engaged.');
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $launchAt = $now->modify('+1 minute');
            $item = $this->persistence->combat->findProbeMissileForUpdate($probe->id, $itemId);
            if (!$item) { throw new OthersActionException(404, 'missile_item_not_found', 'An available missile item was not found in this probe inventory.'); }
            $check = $this->persistence->combat->probeMissileReserved(['item_id' => (int) $item['id']]);
            if ($check !== false) { throw new OthersActionException(409, 'action_conflict', 'The missile item is already reserved.'); }
            $missileId = OthersRepository::publicId('missile');
            $this->persistence->combat->createProbeLaunch([
                'public_id' => $missileId, 'launcher' => (string) $probe->id, 'player_id' => $playerId, 'probe_id' => $probe->id, 'manny_id' => $manny->id, 'item_id' => (int) $item['id'],
                'target' => $target['id'], 'kind' => $target['kind'], 'x' => $probe->currentSector->getX(), 'y' => $probe->currentSector->getY(), 'z' => $probe->currentSector->getZ(),
                'launch_at' => $launchAt->format('c'), 'created_at' => $now->format('c'), 'updated_at' => $now->format('c'),
            ]);
            $manny->currentTask = Manny::TASK_PREPARING_MISSILE; $manny->taskStartedAt = $now->format('c'); $manny->taskEndsAt = $launchAt->format('c');
            $manny->taskPayload = ['missileLaunchId' => $missileId, 'targetObjectId' => $target['id']];
            $this->mannies->save($manny);
            $this->persistence->combat->attachProbeLaunchEvent(['event_id' => $manny->taskScheduledEventId, 'public_id' => $missileId]);
            $missile = $this->findMissileForPlayer($missileId, $playerId) ?? [];
            $missile['missileItemId'] = (string) $item['uid'];
            $missile['targetId'] = (string) $target['id'];
            return $missile;
        });
    }

    public function findMissileForPlayer(string $publicId, int $playerId): ?array
    {
        $stmt = $this->persistence->combat->findMissileForPlayer(['public_id' => $publicId, 'player_id' => $playerId]); return $stmt ?: null;
    }

    public function moveShip(array $ship, array $payload, SectorCoordinates $homeSector): array
    {
        $target = $this->homeRelativeTarget($homeSector, $payload);
        return $this->moveShipToTarget($ship, $target, $payload, $homeSector);
    }

    private function moveShipToTarget(array $ship, SectorCoordinates $target, array $payload, SectorCoordinates $homeSector): array
    {
        return $this->persistence->transaction->run(function () use ($ship, $target, $payload, $homeSector): array {
            $fresh = $this->persistence->locks->lock('ship', (int) $ship['id']);
            if ($fresh === null || $fresh['destroyed_at'] !== null || $fresh['status'] === 'removed') { throw new OthersActionException(409, 'others_ship_unavailable', 'The carrier is unavailable.'); }
            $ship = $fresh + $ship;
            return $this->moveShipToTargetLocked($ship, $target, $payload, $homeSector);
        });
    }

    private function moveShipToTargetLocked(array $ship, SectorCoordinates $target, array $payload, SectorCoordinates $homeSector): array
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

        return $this->persistence->transaction->run(function () use ($ship, $target, $origin, $distance, $fuelCost, $leaveBehind, $homeSector): array {
            $locked = $ship;
            if ($locked === null || $locked['current_action_id'] !== null || (float) $locked['deuterium_stock'] < $fuelCost) {
                throw new OthersActionException(409, 'action_conflict', 'The ship state changed while accepting the command.');
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $this->interruptDepotConstructions((int) $locked['id'], $now->format('c'), 'carrier_departure');
            $this->interruptInventoryTransfers($locked, $now->format('c'), 'carrier_departure');
            $cancelableUntil = $now->modify('+15 minutes');
            $timeline = $this->durations->timeline($cancelableUntil, $distance);
            $action = $this->others->createAction(
                $locked, 'ship_move', 'others_ship', (string) $locked['public_id'],
                ['target' => $target->subtract($homeSector), 'leaveAuxiliariesBehind' => $leaveBehind],
                $timeline['arrivalAt']->format('c'), $cancelableUntil->format('c'),
            );
            $stmt = $this->persistence->movement->createMovement([
                'action_id' => (int) $action['id'], 'ship_id' => (int) $locked['id'],
                'source_x' => $origin->getX(), 'source_y' => $origin->getY(), 'source_z' => $origin->getZ(),
                'target_x' => $target->getX(), 'target_y' => $target->getY(), 'target_z' => $target->getZ(),
                'fuel_cost' => $fuelCost, 'leave_behind' => $leaveBehind ? 1 : 0,
                'depart_at' => $cancelableUntil->format('c'), 'arrive_at' => $timeline['arrivalAt']->format('c'),
                'created_at' => $now->format('c'), 'updated_at' => $now->format('c'),
            ]);
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $cancelableUntil->format('c'), ['expectedStatus' => 'queued']);
            $update = $this->persistence->movement->engageDeparture(['fuel' => $fuelCost, 'action_id' => (int) $action['id'], 'updated_at' => $now->format('c'), 'ship_id' => (int) $locked['id']]);
            if ($update !== 1) {
                throw new OthersActionException(409, 'action_conflict', 'The ship state changed while accepting the command.');
            }
            $this->persistence->action->cancelDeployedActions(['now' => $now->format('c'), 'error' => json_encode(['code' => 'carrier_departure', 'message' => 'The carrier departure terminated the auxiliary task.'], JSON_THROW_ON_ERROR), 'ship_id' => (int) $locked['id']]);
            $this->persistence->action->cancelDeployedEvents(['now' => $now->format('c'), 'ship_id' => (int) $locked['id']]);
            if (!$leaveBehind) {
                $this->persistence->movement->recallDeployedAuxiliaries(['now' => $now->format('c'), 'ship_id' => (int) $locked['id']]);
            }
            $this->persistence->action->attachActionEvent(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    public function cancelMove(array $ship): array
    {
        return $this->persistence->transaction->run(function () use ($ship): array {
            $fresh = $this->persistence->locks->lock('ship', (int) $ship['id']);
            if ($fresh === null || $fresh['destroyed_at'] !== null || $fresh['status'] === 'removed') { throw new OthersActionException(409, 'others_ship_unavailable', 'The carrier is unavailable.'); }
            $ship = $fresh + $ship;
            return $this->cancelMoveLocked($ship);
        });
    }

    private function cancelMoveLocked(array $ship): array
    {
        if ($ship['current_action_id'] === null) {
            throw new OthersActionException(404, 'active_movement_not_found', 'No active movement was found for this ship.');
        }
        return $this->persistence->transaction->run(function () use ($ship): array {
            $stmt = $this->persistence->movement->findCancelableMovement(['id' => (int) $ship['current_action_id'], 'ship_id' => (int) $ship['id']]);
            $action = $stmt;
            if (!$action || $action['status'] !== 'queued' || $action['phase'] !== 'waiting_to_depart') {
                throw new OthersActionException(409, 'movement_cancellation_window_closed', 'The movement can no longer be canceled.');
            }
            if ((string) $action['cancelable_until'] < gmdate('c')) {
                throw new OthersActionException(409, 'movement_cancellation_window_closed', 'The movement can no longer be canceled.');
            }
            $now = gmdate('c');
            $this->persistence->action->requestMovementCancellation(['ends_at' => $now, 'updated_at' => $now, 'id' => (int) $action['id']]);
            if ($action['scheduled_event_id'] !== null) {
                $this->persistence->action->reschedulePendingEvent(['run_at' => $now, 'payload' => json_encode(['expectedStatus' => 'cancel_requested'], JSON_THROW_ON_ERROR), 'updated_at' => $now, 'id' => (int) $action['scheduled_event_id']]);
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
                $created[] = ['shipId' => (string) $ship['public_id'], 'action' => $this->persistence->transaction->isolated(fn(): array => $this->moveShipToTarget($ship, $absolute, $payload, $homeSector))];
            } catch (OthersActionException $error) {
                if ($error->errorCode === 'same_destination') { $ignored[] = ['shipId' => (string) $ship['public_id'], 'reason' => 'already_at_destination']; }
                else { $blocked[] = ['shipId' => (string) $ship['public_id'], 'reason' => $error->errorCode]; }
            }
        }
        return ['created' => $created, 'ignored' => $ignored, 'blocked' => $blocked];
    }

    public function createInventoryTransfer(array $source, array $payload): array
    {
        $transaction = $this->persistence->transaction;
        $locks = $this->persistence->locks;
        return $transaction->run(function () use ($locks, $source, $payload): array {
            $target = is_string($payload['targetShipId'] ?? null) ? $this->others->findShipByPublicId($payload['targetShipId']) : null;
            $ids = array_unique([(int) $source['id'], (int) ($target['id'] ?? $source['id'])]);
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) { $locks->lock('ship', $id); }
            $source = $this->others->findShipByPublicId($source['public_id']) ?? throw new OthersActionException(404, 'others_ship_not_found', 'Ship not found.');
            return $this->createInventoryTransferLocked($source, $payload);
        });
    }

    /** @return array<string, mixed> */
    public function jettisonInventory(array $ship, array $payload): array
    {
        $kind = $payload['kind'] ?? null;
        $allowed = match ($kind) {
            'resource' => ['kind', 'resourceType', 'amount'],
            'item' => ['kind', 'itemId'],
            default => throw new OthersActionException(400, 'bad_request', 'kind must be resource or item.'),
        };
        if (array_diff(array_keys($payload), $allowed) !== [] || count($payload) !== count($allowed)) {
            throw new OthersActionException(400, 'bad_request', 'The jettison request must contain exactly the fields for its kind.');
        }
        $resourceType = null;
        $amount = null;
        $itemId = null;
        if ($kind === 'resource') {
            $resourceType = $payload['resourceType'];
            $rawAmount = $payload['amount'];
            if (!is_string($resourceType) || !in_array($resourceType, OthersRepository::RESOURCE_TYPES, true)
                || (!is_int($rawAmount) && !is_float($rawAmount)) || !is_finite((float) $rawAmount)
                || (float) $rawAmount <= 0 || round((float) $rawAmount, 4) < 0.0001
                || (float) $rawAmount !== round((float) $rawAmount, 4)) {
                throw new OthersActionException(400, 'bad_request', 'A canonical resourceType and a positive amount with at most four decimal places are required.');
            }
            $amount = round((float) $rawAmount, 4);
        } else {
            $itemId = $payload['itemId'];
            if (!is_string($itemId) || $itemId === '') {
                throw new OthersActionException(400, 'bad_request', 'A non-empty itemId is required.');
            }
        }

        $transaction = $this->persistence->transaction;
        $locks = $this->persistence->locks;
        return $transaction->run(function () use ($locks, $ship, $kind, $resourceType, $amount, $itemId): array {
            $locks->lock('ship', (int) $ship['id']);
            $ship = $this->others->findShipByPublicId((string) $ship['public_id'])
                ?? throw new OthersActionException(404, 'others_ship_not_found', 'Others ship not found.');
            if ($ship['destroyed_at'] !== null || $ship['status'] === 'transit') {
                throw new OthersActionException(409, 'others_ship_busy', 'The ship is not in a sector.');
            }
            if ($kind === 'resource') {
                $update = $this->persistence->inventory->jettisonResource(['amount' => $amount, 'now' => gmdate('c'), 'ship_id' => (int) $ship['id'], 'resource_type' => $resourceType, 'available' => $amount]);
                if ($update !== 1) {
                    throw new OthersActionException(422, 'insufficient_resources', 'The unreserved inventory amount is unavailable.');
                }
                return ['kind' => 'resource', 'resourceType' => $resourceType, 'amount' => $amount];
            }

            $items = $this->others->inventoryItemsByPublicIds((int) $ship['id'], [$itemId]);
            $item = $items[0] ?? null;
            if ($item === null) { throw new OthersActionException(404, 'others_inventory_item_not_found', 'Inventory item not found on this ship.'); }
            if ($item['reserved_action_id'] !== null) { throw new OthersActionException(409, 'inventory_changed', 'The inventory item is reserved.'); }
            if ($item['type'] !== 'missile') { throw new OthersActionException(422, 'item_not_jettisonable', 'This inventory item cannot be jettisoned.'); }
            if ($this->sectors === null) { throw new \RuntimeException('Sector storage is unavailable for inventory jettison.'); }
            $delete = $this->persistence->inventory->deleteAvailableItem(['id' => (int) $item['id'], 'ship_id' => (int) $ship['id']]);
            if ($delete !== 1) { throw new OthersActionException(409, 'inventory_changed', 'The inventory item changed concurrently.'); }
            $drifting = $this->sectorChanges->addDriftingItem(
                new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']),
                'others-inventory-jettison-' . $itemId,
                ProbeItem::TYPE_MISSILE,
                ProbeItem::MISSILE_NAME,
                Config::float($this->gameplayConfig, 'crafting.missile.containerSpace', CraftingRecipeCatalog::MISSILE_CONTAINER_SPACE),
            );
            return ['kind' => 'item', 'itemId' => $itemId, 'type' => 'missile', 'objectId' => $drifting->getId(), 'driftingQuantity' => $drifting->getQuantity(), 'containerSpaceEce' => $drifting->getContainerSpace()];
        });
    }

    private function createInventoryTransferLocked(array $source, array $payload): array
    {
        foreach (['actorAuxiliaryId', 'targetShipId', 'kind'] as $field) {
            if (!is_string($payload[$field] ?? null) || $payload[$field] === '') { throw new OthersActionException(400, 'bad_request', $field . ' is required.'); }
        }
        $target = $this->others->findShipByPublicId($payload['targetShipId']);
        $auxiliary = $this->others->findAuxiliaryForShip($payload['actorAuxiliaryId'], (int) $source['id']);
        if ($target === null || (int) $target['player_id'] !== (int) $source['player_id']) { throw new OthersActionException(404, 'others_ship_not_found', 'Target Others ship not found.'); }
        if ($auxiliary === null) { throw new OthersActionException(404, 'others_auxiliary_not_found', 'Actor auxiliary not found.'); }
        $auxiliary = $this->persistence->locks->lock('auxiliary', (int) $auxiliary['id']) ?? throw new OthersActionException(409, 'others_auxiliary_busy', 'The auxiliary became unavailable.');
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

        return $this->persistence->transaction->run(function () use ($source, $target, $auxiliary, $kind, $resourceType, $amount, $items, $space, $durationUnits): array {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $endsAt = $now->modify('+' . max(10, $durationUnits * 10) . ' seconds');
            if ($kind === 'resource') {
                $reserve = $this->persistence->inventory->reserveResource(['amount' => $amount, 'now' => $now->format('c'), 'ship_id' => (int) $source['id'], 'resource_type' => $resourceType]);
                if ($reserve !== 1) { throw new OthersActionException(422, 'insufficient_resources', 'The source inventory amount is unavailable.'); }
            }
            $action = $this->others->createAction($source, 'inventory_transfer', 'others_auxiliary', (string) $auxiliary['public_id'], ['targetShipId' => $target['public_id'], 'kind' => $kind, 'resourceType' => $resourceType, 'amount' => $amount, 'itemIds' => array_column($items, 'public_id')], $endsAt->format('c'), auxiliaryId: (int) $auxiliary['id']);
            if ($kind === 'item') {
                $this->persistence->inventory->reserveItems((int) $action['id'], array_column($items, 'id'), $now->format('c'));
            }
            $publicId = OthersRepository::publicId('transfer');
            $this->persistence->inventory->createTransfer(['public_id' => $publicId, 'action_id' => (int) $action['id'], 'source' => (int) $source['id'], 'target' => (int) $target['id'], 'aux' => (int) $auxiliary['id'], 'kind' => $kind, 'resource_type' => $resourceType, 'amount' => $amount, 'items' => json_encode(array_column($items, 'public_id'), JSON_THROW_ON_ERROR), 'now' => $now->format('c')]);
            $this->persistence->inventory->reserveCapacity(['space' => $space, 'now' => $now->format('c'), 'id' => (int) $target['id']]);
            $this->persistence->inventory->claimAuxiliary(['action_id' => (int) $action['id'], 'now' => $now->format('c'), 'id' => (int) $auxiliary['id']]);
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $endsAt->format('c'), ['expectedStatus' => 'queued']);
            $this->persistence->action->attachActionEvent(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return ['transfer' => $this->others->findInventoryTransferForPlayer($publicId, (int) $source['player_id']), 'action' => $this->others->findActionByPublicId((string) $action['public_id'])];
        });
    }

    public function transferDeuterium(array $source, array $auxiliary, array $payload): array
    {
        return $this->persistence->transaction->run(function () use ($source, $auxiliary, $payload): array {
            $target = is_string($payload['targetShipId'] ?? null) ? $this->others->findShipByPublicId($payload['targetShipId']) : null;
            $ids = [(int) $source['id']];
            if ($target !== null) { $ids[] = (int) $target['id']; }
            sort($ids, SORT_NUMERIC);
            foreach (array_unique($ids) as $id) { $this->persistence->locks->lock('ship', $id); }
            $source = ($this->persistence->actor->findShipById(['id' => (int) $source['id']]) ?: []) + $source;
            $auxiliary = ($this->persistence->locks->lock('auxiliary', (int) $auxiliary['id']) ?? []) + $auxiliary;
            return $this->transferDeuteriumLocked($source, $auxiliary, $payload);
        });
    }

    private function transferDeuteriumLocked(array $source, array $auxiliary, array $payload): array
    {
        $targetId = $payload['targetShipId'] ?? null; $amount = $payload['amount'] ?? null;
        if (!is_string($targetId) || !is_numeric($amount) || (float) $amount <= 0.0) { throw new OthersActionException(400, 'bad_request', 'targetShipId and a positive amount are required.'); }
        $target = $this->others->findShipByPublicId($targetId); $amount = round((float) $amount, 4);
        if ($target === null || (int) $target['player_id'] !== (int) $source['player_id']) { throw new OthersActionException(404, 'others_ship_not_found', 'Target Others ship not found.'); }
        if ((int) $source['id'] === (int) $target['id']) { throw new OthersActionException(422, 'bad_request', 'A fuel transfer requires distinct ships.'); }
        if ($source['destroyed_at'] !== null || $target['destroyed_at'] !== null || $source['departure_engaged'] || $target['departure_engaged']) { throw new OthersActionException(409, 'others_ship_busy', 'Both carriers must be available.'); }
        if (!$this->sameSector($source, $target)) { throw new OthersActionException(422, 'target_out_of_range', 'Both ships must be in the same sector.'); }
        if ($auxiliary['current_action_id'] !== null || $auxiliary['location_type'] !== 'embarked') { throw new OthersActionException(409, 'others_auxiliary_busy', 'The auxiliary is busy.'); }
        if ((float) $source['deuterium_stock'] - (float) $source['deuterium_reserved'] < $amount || (float) $target['deuterium_stock'] + (float) $target['deuterium_reserved'] + $amount > (float) $target['deuterium_capacity']) { throw new OthersActionException(422, 'insufficient_resources', 'Source stock or target tank capacity is insufficient.'); }
        return $this->persistence->transaction->run(function () use ($source, $target, $auxiliary, $amount): array {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $ends = $now->modify('+5 minutes');
            $action = $this->others->createAction($source, 'deuterium_transfer', 'others_auxiliary', (string) $auxiliary['public_id'], ['targetShipId' => $target['public_id'], 'amount' => $amount], $ends->format('c'), auxiliaryId: (int) $auxiliary['id']);
            $this->persistence->inventory->reserveFuelTanks(['amount' => $amount, 'now' => $now->format('c'), 'source' => (int) $source['id'], 'target' => (int) $target['id']]);
            $this->persistence->inventory->claimAuxiliary(['action_id' => (int) $action['id'], 'now' => $now->format('c'), 'id' => (int) $auxiliary['id']]);
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $ends->format('c'), ['expectedStatus' => 'queued']);
            $this->persistence->action->attachActionEvent(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    public function startAuxiliaryTask(array $ship, array $auxiliary, string $task, array $payload): array
    {
        if ($task === 'transfer-deuterium') { return $this->transferDeuterium($ship, $auxiliary, $payload); }
        return $this->persistence->transaction->run(function () use ($ship, $auxiliary, $task, $payload): array {
            $fresh = $this->persistence->locks->lock('ship', (int) $ship['id']);
            $actor = $this->persistence->locks->lock('auxiliary', (int) $auxiliary['id']);
            if ($fresh === null || $fresh['destroyed_at'] !== null || $fresh['status'] === 'removed' || $actor === null || (int) $actor['ship_id'] !== (int) $ship['id']) { throw new OthersActionException(409, 'others_ship_unavailable', 'The carrier or auxiliary is unavailable.'); }
            return $this->startAuxiliaryTaskLocked($fresh + $ship, $actor + $auxiliary, $task, $payload);
        });
    }

    private function startAuxiliaryTaskLocked(array $ship, array $auxiliary, string $task, array $payload): array
    {
        return match ($task) {
            'repair' => $this->startAuxiliaryRepair($ship, $auxiliary, $payload),
            'depot-deposits' => $this->storageTransferService()->startOthers($ship, $auxiliary, 'to_storage', $payload),
            'depot-withdrawals' => $this->storageTransferService()->startOthers($ship, $auxiliary, 'from_storage', $payload),
            'build-germination-depot' => $this->depotService()->build($ship, $auxiliary, $payload),
            'transfer-deuterium' => $this->transferDeuterium($ship, $auxiliary, $payload),
            'mine' => $this->startAuxiliaryMining($ship, $auxiliary, $payload),
            'recall' => $this->startAuxiliaryRecall($ship, $auxiliary, $payload),
            'recover-dormant-auxiliary' => $this->startDormantRecovery($ship, $auxiliary, $payload),
            default => throw new OthersActionException(400, 'bad_request', 'Unsupported Others auxiliary task.'),
        };
    }

    public function startHarvest(array $ship, array $payload): array
    {
        $transaction = $this->persistence->transaction;
        $locks = $this->persistence->locks;
        return $transaction->run(function () use ($locks, $ship, $payload): array {
            $locks->lock('ship', (int) $ship['id']);
            $current = $this->others->findShipByPublicId($ship['public_id']) ?? throw new OthersActionException(404, 'others_ship_not_found', 'Ship not found.');
            return $this->startHarvestLocked($current, $payload);
        });
    }

    private function startHarvestLocked(array $ship, array $payload): array
    {
        if ($this->sectors === null) { throw new OthersActionException(503, 'others_sector_unavailable', 'Sector storage is unavailable.'); }
        $targetId = $payload['targetObjectId'] ?? null; $count = $payload['auxiliaryCount'] ?? null;
        if (!is_string($targetId) || !is_int($count) || $count <= 0) { throw new OthersActionException(400, 'bad_request', 'targetObjectId and a positive integer auxiliaryCount are required.'); }
        if ($ship['current_action_id'] !== null || in_array((string) $ship['status'], ['transit', 'destroyed', 'removed'], true)) { throw new OthersActionException(409, 'others_ship_busy', 'The ship is busy.'); }
        $coordinates = new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']);
        $planet = $this->sectorChanges->getOrCreateSector($coordinates)->findObjectById($targetId);
        if (!$planet instanceof Planet) { throw new OthersActionException(404, 'target_not_found', 'Harvest target planet not found.'); }
        $capacity = 2.0 * $count;
        if ($this->others->inventoryUsage((int) $ship['id']) + (float) $ship['inventory_reserved'] + $capacity > (float) $ship['inventory_capacity'] + 0.00001) { throw new OthersActionException(422, 'insufficient_resources', 'The coordinator inventory has insufficient capacity.'); }
        return $this->persistence->transaction->run(function () use ($ship, $planet, $count, $capacity): array {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $lifePhase = $planet->hasIntelligentLife();
            $duration = $lifePhase ? (int) ceil(86400 * 100 / $count) : 600;
            $ends = $now->modify('+' . $duration . ' seconds');
            $action = $this->others->createAction($ship, 'planet_harvest', 'others_ship', (string) $ship['public_id'], ['targetObjectId' => $planet->getId(), 'auxiliaryCount' => $count], $ends->format('c'));
            $auxiliaries = $this->others->claimAvailableAuxiliaries((int) $ship['id'], $count, (int) $action['id']);
            if ($auxiliaries === []) { throw new OthersActionException(422, 'insufficient_resources', 'Not enough available auxiliaries for this swarm.'); }
            $ids = array_map('intval', array_column($auxiliaries, 'id'));
            $this->persistence->production->recordSwarmParticipants((int) $action['id'], $ids, $now->format('c'));
            $updated = $this->persistence->production->deploySwarm((int) $action['id'], $ids, $ship, $planet->getId(), $now->format('c'));
            if ($updated !== $count) { throw new OthersActionException(409, 'action_conflict', 'The swarm reservation collided with another command.'); }
            $biologicalCarbon = $planet->hasIntelligentLife() ? $planet->getHabitabilityScore() * $planet->getRadius() * 179.9592830250242 : 0.0;
            $this->persistence->production->createHarvest(['action_id' => (int) $action['id'], 'ship_id' => (int) $ship['id'], 'target' => $planet->getId(), 'phase' => $lifePhase ? 'destroying_life' : 'mining', 'started' => $now->format('c'), 'count' => $count, 'capacity' => $capacity, 'biomass' => $biologicalCarbon, 'created' => $now->format('c'), 'updated' => $now->format('c')]);
            $this->persistence->production->engageHarvest(['action_id' => (int) $action['id'], 'capacity' => $capacity, 'now' => $now->format('c'), 'id' => (int) $ship['id']]);
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $ends->format('c'), ['expectedStatus' => 'queued']);
            $this->persistence->action->attachActionEvent(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    public function cancelHarvest(array $ship): array
    {
        return $this->persistence->transaction->run(function () use ($ship): array {
            $fresh = $this->persistence->locks->lock('ship', (int) $ship['id']);
            if ($fresh === null || $fresh['destroyed_at'] !== null || $fresh['status'] === 'removed') { throw new OthersActionException(409, 'others_ship_unavailable', 'The carrier is unavailable.'); }
            $ship = $fresh + $ship;
            return $this->cancelHarvestLocked($ship);
        });
    }

    private function cancelHarvestLocked(array $ship): array
    {
        if ($ship['current_action_id'] === null) { throw new OthersActionException(404, 'active_harvest_not_found', 'No active harvest was found.'); }
        return $this->persistence->transaction->run(function () use ($ship): array {
            $stmt = $this->persistence->action->findCancelableHarvest(['id' => (int) $ship['current_action_id']]); $action = $stmt;
            if (!$action) { throw new OthersActionException(404, 'active_harvest_not_found', 'No active harvest was found.'); }
            $now = gmdate('c'); $this->persistence->action->requestHarvestCancellation(['now' => $now, 'id' => (int) $action['id']]);
            if ($action['scheduled_event_id'] !== null) { $this->persistence->action->rescheduleHarvestCancellation(['now' => $now, 'payload' => json_encode(['expectedStatus' => 'cancel_requested'], JSON_THROW_ON_ERROR), 'id' => (int) $action['scheduled_event_id']]); }
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    public function startCraft(array $ship, array $payload): array
    {
        $transaction = $this->persistence->transaction;
        $locks = $this->persistence->locks;
        return $transaction->run(function () use ($locks, $ship, $payload): array {
            $locks->lock('ship', (int) $ship['id']);
            $current = $this->others->findShipByPublicId($ship['public_id']) ?? throw new OthersActionException(404, 'others_ship_not_found', 'Ship not found.');
            return $this->startCraftLocked($current, $payload);
        });
    }

    private function startCraftLocked(array $ship, array $payload): array
    {
        if ($ship['type'] !== 'mothership') { throw new OthersActionException(422, 'others_mothership_required', 'Only an Others mothership has workshops.'); }
        $recipeId = $payload['recipeId'] ?? null; $assistantId = $payload['assistantAuxiliaryId'] ?? null;
        if (!is_string($recipeId) || !isset(self::RECIPES[$recipeId]) || !is_string($assistantId)) { throw new OthersActionException(400, 'bad_request', 'A canonical recipeId and assistantAuxiliaryId are required.'); }
        $assistant = $this->others->findAuxiliaryForShip($assistantId, (int) $ship['id']);
        if ($assistant === null) { throw new OthersActionException(404, 'others_auxiliary_not_found', 'Assistant auxiliary not found.'); }
        $assistant = $this->persistence->locks->lock('auxiliary', (int) $assistant['id']) ?? throw new OthersActionException(409, 'others_auxiliary_busy', 'The assistant became unavailable.');
        if ($assistant['current_action_id'] !== null || $assistant['location_type'] !== 'embarked' || !in_array((string) $assistant['status'], ['inactive','available'], true)) { throw new OthersActionException(409, 'others_auxiliary_busy', 'The assistant auxiliary is busy.'); }
        $recipe = self::RECIPES[$recipeId];
        if ((float) $recipe['outputSpace'] > 0.0 && $this->others->inventoryUsage((int) $ship['id']) + (float) $ship['inventory_reserved'] + (float) $recipe['outputSpace'] > (float) $ship['inventory_capacity'] + 0.00001) { throw new OthersActionException(422, 'insufficient_resources', 'The workshop output has no reserved inventory capacity.'); }
        return $this->persistence->transaction->run(function () use ($ship, $assistant, $recipeId, $recipe): array {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $ends = $now->modify('+' . (int) $recipe['duration'] . ' seconds');
            foreach ($recipe['ingredients'] as $type => $amount) {
                $consume = $this->persistence->production->consumeCraftIngredient(['amount' => $amount, 'now' => $now->format('c'), 'ship_id' => (int) $ship['id'], 'type' => $type]);
                if ($consume !== 1) { throw new OthersActionException(422, 'insufficient_resources', 'The mothership inventory lacks recipe ingredients.'); }
            }
            $action = $this->others->createAction($ship, 'others_craft', 'others_ship', (string) $ship['public_id'], ['recipeId' => $recipeId, 'assistantAuxiliaryId' => $assistant['public_id']], $ends->format('c'), auxiliaryId: (int) $assistant['id']);
            $craftId = OthersRepository::publicId('craft');
            $this->persistence->production->createCraft(['public_id' => $craftId, 'action_id' => (int) $action['id'], 'ship_id' => (int) $ship['id'], 'assistant' => (int) $assistant['id'], 'recipe' => $recipeId, 'ingredients' => json_encode($recipe['ingredients'], JSON_THROW_ON_ERROR), 'space' => (float) $recipe['outputSpace'], 'now' => $now->format('c')]);
            $this->persistence->production->claimCraftAssistant(['action_id' => (int) $action['id'], 'now' => $now->format('c'), 'id' => (int) $assistant['id']]);
            if ((float) $recipe['outputSpace'] > 0.0) { $this->persistence->production->reserveCraftCapacity(['space' => (float) $recipe['outputSpace'], 'now' => $now->format('c'), 'id' => (int) $ship['id']]); }
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $ends->format('c'), ['expectedStatus' => 'queued']);
            $this->persistence->action->attachActionEvent(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return ['craft' => $this->others->findCraftForPlayer($craftId, (int) $ship['player_id']), 'action' => $this->others->findActionByPublicId((string) $action['public_id'])];
        });
    }

    public function startLaser(array $ship, array $payload): array
    {
        return $this->persistence->transaction->run(function () use ($ship, $payload): array {
            $fresh = $this->persistence->locks->lock('ship', (int) $ship['id']);
            if ($fresh === null || $fresh['destroyed_at'] !== null || $fresh['status'] === 'removed') { throw new OthersActionException(409, 'others_ship_unavailable', 'The carrier is unavailable.'); }
            $ship = $fresh + $ship;
            return $this->startLaserLocked($ship, $payload);
        });
    }

    private function startLaserLocked(array $ship, array $payload): array
    {
        $targetId = $payload['targetId'] ?? null;
        if (!is_string($targetId) || $targetId === '') { throw new OthersActionException(400, 'bad_request', 'targetId is required.'); }
        if ($ship['status'] === 'transit' || $ship['destroyed_at'] !== null) { throw new OthersActionException(409, 'others_ship_busy', 'The firing ship is unavailable.'); }
        if ((float) $ship['deuterium_stock'] <= 0.0) { throw new OthersActionException(422, 'insufficient_resources', 'The laser requires a positive deuterium stock.'); }
        if ($ship['laser_next_target_at'] !== null && (string) $ship['laser_next_target_at'] > gmdate('c')) { throw new OthersActionException(409, 'action_conflict', 'The laser target-change cooldown is active.'); }
        $active = $this->persistence->combat->countActiveLasers(['ship_id' => (int) $ship['id']]);
        if ((int) $active > 0) { throw new OthersActionException(409, 'action_conflict', 'This ship already maintains a laser lock.'); }
        $target = $this->resolveLocalTarget($ship, $targetId, laserOnly: true);
        if ($target === null) { throw new OthersActionException(404, 'target_not_found', 'Admissible local laser target not found.'); }
        return $this->persistence->transaction->run(function () use ($ship, $target): array {
            $now = gmdate('c');
            $action = $this->others->createAction($ship, 'laser_lock', 'others_ship', (string) $ship['public_id'], ['targetId' => $target['id'], 'targetKind' => $target['kind']]);
            $this->persistence->combat->createLaser(['action_id' => (int) $action['id'], 'ship_id' => (int) $ship['id'], 'kind' => $target['kind'], 'target' => $target['id'], 'x' => (int) $ship['sector_x'], 'y' => (int) $ship['sector_y'], 'z' => (int) $ship['sector_z'], 'now' => $now]);
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $now, ['expectedStatus' => 'queued']);
            $this->persistence->action->attachActionEvent(['event_id' => $event->id, 'id' => (int) $action['id']]);
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
        $sector = $this->sectorChanges->getOrCreateSector($sectorCoordinates); $object = $sector->findObjectById($objectId);
        if (!$object instanceof Planet && !$object instanceof Asteroid && !($object instanceof DormantConstruct && $object->getSubtype() !== null)) { throw new OthersActionException(404, 'target_not_found', 'Mineable sector object not found.'); }
        $amounts = method_exists($object, 'getResourceAmounts') ? $object->getResourceAmounts() : [];
        $profile = ResourceComposition::profileForSelection($amounts, $selection);
        $available = 0.0; foreach ($selection as $type) { $available += (float) ($amounts[$type] ?? 0.0); }
        if ($available <= 0.0) { throw new OthersActionException(422, 'insufficient_resources', 'The selected resources are exhausted.'); }
        $amount = round(min(2.0, (float) $targetAmount, $available), 4);
        $endsAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+10 minutes');
        return $this->reserveAuxiliaryAction($ship, $auxiliary, 'auxiliary_mine', ['objectId' => $objectId, 'amount' => $amount, 'profile' => $profile], $endsAt, deployed: true, objectId: $objectId);
    }

    private function startAuxiliaryRepair(array $ship, array $auxiliary, array $payload): array
    {
        $percent = $payload['integrityPercent'] ?? null;
        if (array_diff(array_keys($payload), ['integrityPercent']) !== [] || !is_numeric($percent)
            || !is_finite((float) $percent) || (float) $percent <= 0 || floor((float) $percent) !== (float) $percent) {
            throw new OthersActionException(400, 'bad_request', 'integrityPercent must be a positive whole number of integrity points.');
        }
        $transaction = $this->persistence->transaction;
        $locks = $this->persistence->locks;
        return $transaction->run(function () use ($locks, $ship, $auxiliary, $percent): array {
            $current = $locks->lock('ship', (int) $ship['id']);
            if ($current === null || $current['destroyed_at'] !== null || $current['status'] === 'removed') {
                throw new OthersActionException(404, 'others_ship_not_found', 'Others ship not found.');
            }
            $actor = $locks->lock('auxiliary', (int) $auxiliary['id']);
            if ($actor === null || $actor['destroyed_at'] !== null || (int) $actor['ship_id'] !== (int) $current['id']) {
                throw new OthersActionException(404, 'others_auxiliary_not_found', 'Others auxiliary not found.');
            }
            if ($actor['current_action_id'] !== null || !in_array($actor['status'], ['inactive', 'available'], true)) {
                throw new OthersActionException(409, 'others_auxiliary_busy', 'The auxiliary is already executing an order.');
            }
            if ($actor['location_type'] !== 'embarked') {
                throw new OthersActionException(409, 'others_auxiliary_not_embarked', 'The auxiliary must be embarked to repair its ship.');
            }
            $missing = max(0, (int) $current['max_integrity'] - (int) $current['integrity']);
            if ($missing === 0) {
                throw new OthersActionException(409, 'others_ship_integrity_full', 'The ship integrity is already full.');
            }
            $points = (int) min((float) $percent, $missing);
            $seconds = max(1, Config::int($this->gameplayConfig, 'manny.actions.repairSecondsPerIntegrityPercent', RepairTaskHandler::REPAIR_SECONDS_PER_INTEGRITY_PERCENT));
            $metalsCost = round($points * max(0.0, Config::float($this->gameplayConfig, 'manny.actions.repairMetalsPerIntegrityPercent', RepairTaskHandler::REPAIR_METALS_PER_INTEGRITY_PERCENT)), 4);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($metalsCost > 0) {
                $consume = $this->persistence->production->consumeRepairMetals(['cost' => $metalsCost, 'now' => $now->format('c'), 'ship_id' => (int) $current['id']]);
                if ($consume !== 1) {
                    throw new OthersActionException(422, 'insufficient_metals', 'Insufficient available metals in ship inventory for this repair.');
                }
            }
            return $this->reserveAuxiliaryAction($ship, $actor, 'auxiliary_repair', ['integrityPercent' => $points, 'metalsCost' => $metalsCost], $now->modify('+' . ($points * $seconds) . ' seconds'));
        });
    }

    private function completeAuxiliaryRepair(int $actionId, string $runAt): void
    {
        $transaction = $this->persistence->transaction;
        $locks = $this->persistence->locks;
        $transaction->run(function () use ($locks, $actionId, $runAt): void {
            $query = $this->persistence->action->findActionActors([$actionId]);
            $ids = $query;
            if (!$ids) { return; }
            $ship = $locks->lock('ship', (int) $ids['ship_id']);
            $actor = $ids['auxiliary_id'] === null ? null : $locks->lock('auxiliary', (int) $ids['auxiliary_id']);
            $action = $locks->lock('action', $actionId);
            if ($action === null || $action['status'] !== 'queued' || new \DateTimeImmutable($runAt) < new \DateTimeImmutable($action['ends_at'])) { return; }
            if ($ship === null || $ship['destroyed_at'] !== null || $ship['status'] === 'removed'
                || $actor === null || $actor['destroyed_at'] !== null || (int) $actor['current_action_id'] !== $actionId) {
                $this->failAuxiliaryAction($action, gmdate('c'), 'repair_unavailable');
                return;
            }
            $payload = json_decode($action['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $restored = min((int) $payload['integrityPercent'], max(0, (int) $ship['max_integrity'] - (int) $ship['integrity']));
            $now = gmdate('c');
            $this->persistence->production->addIntegrity(['restored' => $restored, 'now' => $now, 'id' => (int) $ship['id']]);
            $this->persistence->production->releaseRepairActor(['now' => $now, 'id' => (int) $actor['id'], 'action_id' => $actionId]);
            $this->persistence->action->finishRepair(['result' => json_encode(['outcome' => 'repaired', 'integrityPercent' => $restored, 'integrity' => (int) $ship['integrity'] + $restored], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => $actionId]);
        });
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
        $sector = $this->sectorChanges->getOrCreateSector(new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']));
        $object = $sector->findObjectById($objectId);
        if (!$object instanceof DormantConstruct || $object->getSubtype() !== 'others_auxiliary' || (float)($object->getResourceAmounts()['metals'] ?? 0.0) < 5.0 - 0.00001) { throw new OthersActionException(422, 'target_not_found', 'An intact dormant Others auxiliary is required.'); }
        $endsAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 minutes');
        return $this->reserveAuxiliaryAction($ship, $auxiliary, 'dormant_auxiliary_recovery', ['objectId' => $objectId, 'originalAuxiliaryId' => $object->getOriginalAuxiliaryId()], $endsAt);
    }

    private function reserveAuxiliaryAction(array $ship, array $auxiliary, string $type, array $payload, \DateTimeImmutable $endsAt, bool $deployed = false, ?string $objectId = null): array
    {
        return $this->persistence->transaction->run(function () use ($ship, $auxiliary, $type, $payload, $endsAt, $deployed, $objectId): array {
            $now = gmdate('c');
            $action = $this->others->createAction($ship, $type, 'others_auxiliary', (string) $auxiliary['public_id'], $payload, $endsAt->format('c'), auxiliaryId: (int) $auxiliary['id']);
            $params = ['action_id' => (int) $action['id'], 'now' => $now, 'id' => (int) $auxiliary['id']];
            if ($deployed) { $params += ['spatial_state' => $type === 'auxiliary_recall' ? 'returning_to_carrier' : 'moving_to_sector_object', 'x' => (int) $ship['sector_x'], 'y' => (int) $ship['sector_y'], 'z' => (int) $ship['sector_z'], 'object_id' => $objectId]; }
            $update = $this->persistence->production->claimAuxiliaryTask($params, $deployed);
            if ($update !== 1) { throw new OthersActionException(409, 'action_conflict', 'The auxiliary state changed while accepting the task.'); }
            $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $endsAt->format('c'), ['expectedStatus' => 'queued']);
            $this->persistence->action->attachActionEvent(['event_id' => $event->id, 'id' => (int) $action['id']]);
            return $this->others->findActionByPublicId((string) $action['public_id']) ?? $action;
        });
    }

    public function processScheduledAction(ScheduledEvent $event): void
    {
        $check = $this->persistence->action->findActionType([$event->entityId]);
        $storageActionType = $check;
        if ($storageActionType === 'auxiliary_repair') {
            $this->completeAuxiliaryRepair($event->entityId, $event->runAt);
            return;
        }
        if (in_array($storageActionType, ['depot_deposit', 'depot_withdrawal'], true)) {
            $this->storageTransferService()->completeOthers($event->entityId, $event->runAt);
            return;
        }
        if ($storageActionType === 'build_germination_depot') {
            $this->depotService()->completeConstruction($event->entityId, $event->runAt);
            return;
        }
        $this->persistence->transaction->run(function () use ($event): void {
            $identity = $this->persistence->locks->actionIdentity($event->entityId);
            if ($identity === null) { return; }
            foreach ($this->persistence->locks->actionShipIds($identity) as $shipId) { $this->persistence->locks->lock('ship', $shipId); }
            if ($identity['auxiliary_id'] !== null) { $this->persistence->locks->lock('auxiliary', (int) $identity['auxiliary_id']); }
            $fresh = $this->persistence->locks->lock('action', $event->entityId);
            if ($fresh === null || (int) ($fresh['scheduled_event_id'] ?? 0) !== $event->id) { return; }
            $stmt = $this->persistence->movement->findActionWithMovement(['id' => $event->entityId]);
            $action = $stmt;
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
                $this->persistence->action->cancelAction(['now' => $now, 'id' => (int) $action['id']]);
                $this->persistence->movement->cancelMovement(['now' => $now, 'id' => (int) $action['id']]);
                $this->persistence->movement->refundDeparture(['fuel' => (float) $action['fuel_cost'], 'now' => $now, 'ship_id' => (int) $action['movement_ship_id'], 'action_id' => (int) $action['id']]);
                $this->persistence->movement->embarkReturningAuxiliaries(['now' => $now, 'ship_id' => (int) $action['movement_ship_id']]);
                return;
            }
            if ($action['status'] === 'queued' && $action['phase'] === 'waiting_to_depart') {
                if ((int) $action['leave_auxiliaries_behind'] === 1) { $this->turnDeployedAuxiliariesDormant((int) $action['movement_ship_id'], $now); }
                else { $this->persistence->movement->embarkReturningAuxiliaries(['now' => $now, 'ship_id' => (int) $action['movement_ship_id']]); }
                $this->persistence->action->startAction(['now' => $now, 'id' => (int) $action['id']]);
                $this->persistence->movement->startMovement(['now' => $now, 'id' => (int) $action['id']]);
                $this->persistence->movement->departShip(['now' => $now, 'ship_id' => (int) $action['movement_ship_id'], 'action_id' => (int) $action['id']]);
                $next = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], (string) $action['arrive_at'], ['expectedStatus' => 'running']);
                $this->persistence->action->attachActionEvent(['event_id' => $next->id, 'id' => (int) $action['id']]);
                return;
            }
            if ($action['status'] === 'running' && $action['phase'] === 'transit') {
                $result = ['outcome' => 'arrived'];
                $this->persistence->action->finishMovementAction(['result' => json_encode($result, JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
                $this->persistence->movement->arriveMovement(['now' => $now, 'id' => (int) $action['id']]);
                $target = new SectorCoordinates((int) $action['target_x'], (int) $action['target_y'], (int) $action['target_z']);
                $this->persistence->movement->arriveShip(['x' => $target->getX(), 'y' => $target->getY(), 'z' => $target->getZ(), 'now' => $now, 'ship_id' => (int) $action['movement_ship_id'], 'action_id' => (int) $action['id']]);
                $this->others->markFleetSectorVisited((int) $action['fleet_id'], $target, $now);
                $this->others->discoverFleetDepotsInSector((int) $action['fleet_id'], $target);
                $this->createOthersArrivalAlerts($target, (string) $action['public_id']);
                return;
            }
        });
    }

    private function completeInventoryTransfer(array $action, string $now): void
    {
        $stmt = $this->persistence->inventory->findTransfer(['action_id' => (int) $action['id']]);
        $transfer = $stmt;
        if (!$transfer || $transfer['status'] !== 'queued') { return; }
        $targetStmt = $this->persistence->inventory->findActiveTargetShip(['id' => (int) $transfer['target_ship_id']]);
        $target = $targetStmt;
        $items = json_decode((string) $transfer['item_ids_json'], true, 512, JSON_THROW_ON_ERROR);
        $space = $transfer['kind'] === 'resource' ? (float) $transfer['amount'] : 0.0;
        if ($transfer['kind'] === 'item') {
            $reservedItems = $this->others->inventoryItemsByPublicIds((int) $transfer['source_ship_id'], $items);
            foreach ($reservedItems as $item) { if ((int) ($item['reserved_action_id'] ?? 0) === (int) $action['id']) { $space += (float) $item['container_space']; } }
        }
        if (!$target) {
            if ($transfer['kind'] === 'resource') {
                $amount = (float) $transfer['amount'];
                $this->persistence->inventory->releaseResourceReservation(['reserved_floor' => $amount, 'reserved_decrease' => $amount, 'now' => $now, 'ship_id' => (int) $transfer['source_ship_id'], 'resource_type' => $transfer['resource_type']]);
            } else {
                $this->persistence->inventory->releaseItemReservations(['now' => $now, 'action_id' => (int) $action['id']]);
            }
            $this->finishTransfer($transfer, $action, $space, $now, false, 'target_unavailable');
            return;
        }
        if ($transfer['kind'] === 'resource') {
            $source = $this->persistence->inventory->debitReservedResource(['amount' => (float) $transfer['amount'], 'now' => $now, 'ship_id' => (int) $transfer['source_ship_id'], 'resource_type' => $transfer['resource_type']]);
            if ($source !== 1) { throw new \RuntimeException('Reserved Others inventory resource is inconsistent.'); }
            $this->persistence->inventory->creditTransferredResource(['amount' => (float) $transfer['amount'], 'now' => $now, 'ship_id' => (int) $transfer['target_ship_id'], 'resource_type' => $transfer['resource_type']]);
        } else {
            $move = $this->persistence->inventory->moveReservedItems(['target_ship_id' => (int) $transfer['target_ship_id'], 'now' => $now, 'action_id' => (int) $action['id'], 'source_ship_id' => (int) $transfer['source_ship_id']]);
            if ($move !== count($items)) { throw new \RuntimeException('Reserved Others inventory items are inconsistent.'); }
        }
        $this->finishTransfer($transfer, $action, $space, $now, true, null);
    }

    private function finishTransfer(array $transfer, array $action, float $space, string $now, bool $success, ?string $reason): void
    {
        $status = $success ? 'succeeded' : 'failed';
        $result = $success ? ['outcome' => 'transferred'] : null;
        $error = $success ? null : ['code' => $reason, 'message' => 'The inventory transfer could not be completed.'];
        $this->persistence->inventory->finishTransfer(['status' => $status, 'now' => $now, 'id' => (int) $transfer['id'], 'expected' => 'queued']);
        $this->persistence->action->finishTransferAction(['status' => $status, 'result' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR), 'error' => $error === null ? null : json_encode($error, JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id'], 'expected' => 'queued']);
        $this->persistence->inventory->releaseCapacity(['reserved_floor' => $space, 'reserved_decrease' => $space, 'now' => $now, 'id' => (int) $transfer['target_ship_id']]);
        $this->persistence->inventory->releaseTransferActor(['now' => $now, 'id' => (int) $transfer['auxiliary_id'], 'action_id' => (int) $action['id']]);
    }

    private function completeDeuteriumTransfer(array $action, string $now): void
    {
        $payload = json_decode((string) $action['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $target = $this->others->findShipByPublicId((string) $payload['targetShipId']); $amount = (float) $payload['amount'];
        if ($target === null || $target['destroyed_at'] !== null || $target['status'] === 'removed') {
            $this->persistence->inventory->releaseFuelReservation(['reserved_floor' => $amount, 'reserved_decrease' => $amount, 'now' => $now, 'id' => (int) $action['ship_id']]);
            if ($target !== null) { $this->persistence->inventory->releaseFuelReservation(['reserved_floor' => $amount, 'reserved_decrease' => $amount, 'now' => $now, 'id' => (int) $target['id']]); }
            $this->persistence->action->failFuelTransfer(['error' => json_encode(['code' => 'target_unavailable', 'message' => 'The target ship became unavailable.'], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
        } else {
            $sourceUpdate = $this->persistence->inventory->debitReservedFuel(['amount' => $amount, 'now' => $now, 'id' => (int) $action['ship_id']]);
            if ($sourceUpdate !== 1) { throw new \RuntimeException('Reserved Others deuterium is inconsistent.'); }
            $this->persistence->inventory->creditReservedFuel(['stock_increase' => $amount, 'reserved_floor' => $amount, 'reserved_decrease' => $amount, 'now' => $now, 'id' => (int) $target['id']]);
            $this->persistence->action->finishFuelTransfer(['result' => json_encode(['outcome' => 'transferred', 'amount' => $amount], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
        }
        if ($action['auxiliary_id'] !== null) { $this->persistence->inventory->releaseTransferActor(['now' => $now, 'id' => (int) $action['auxiliary_id'], 'action_id' => (int) $action['id']]); }
    }

    private function completeAuxiliaryMining(array $action, string $now): void
    {
        if ($this->sectors === null || $action['auxiliary_id'] === null) { throw new \RuntimeException('Others mining sector storage is unavailable.'); }
        $ship = $this->others->findShipByPublicId((string) $action['actor_public_id']);
        $shipStmt = $this->persistence->actor->findShipById(['id' => (int) $action['ship_id']]); $ship = $shipStmt;
        if (!$ship) { $this->failAuxiliaryAction($action, $now, 'carrier_unavailable'); return; }
        $payload = json_decode((string) $action['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $coordinates = new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']);
        $sector = $this->sectorChanges->getOrCreateSector($coordinates); $object = $sector->findObjectById((string) $payload['objectId']);
        if (!$object instanceof Planet && !$object instanceof Asteroid && !$object instanceof DormantConstruct) { $this->failAuxiliaryAction($action, $now, 'target_unavailable'); return; }
        $remaining = $object->getResourceAmounts(); $profile = $payload['profile']; $requested = (float) $payload['amount']; $extracted = []; $total = 0.0;
        foreach (ResourceComposition::TYPES as $type) {
            $take = round(min((float) ($remaining[$type] ?? 0.0), $requested * (float) ($profile[$type] ?? 0.0)), 4);
            $extracted[$type] = $take; $remaining[$type] = round(max(0.0, (float) ($remaining[$type] ?? 0.0) - $take), 4); $total += $take;
        }
        if ($total <= 0.0) { $this->failAuxiliaryAction($action, $now, 'resources_exhausted'); return; }
        $replacement = $object instanceof Planet ? $object->withResourceAmounts($remaining) : $object->withResourceAmounts($remaining);
        $sector->replaceObject($replacement); $this->sectorChanges->saveSector($sector);
        $this->persistence->production->finishAuxiliaryMining(['deuterium' => $extracted['deuterium'], 'metals' => $extracted['metals'], 'ice' => $extracted['ice'], 'carbon' => $extracted['carbon_compounds'], 'now' => $now, 'id' => (int) $action['auxiliary_id'], 'action_id' => (int) $action['id']]);
        $this->persistence->action->finishFuelTransfer(['result' => json_encode(['outcome' => 'mined', 'amounts' => $extracted], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    private function completeAuxiliaryRecall(array $action, string $now): void
    {
        if ($action['auxiliary_id'] === null) { return; }
        $this->persistence->production->finishRecall(['now' => $now, 'id' => (int) $action['auxiliary_id'], 'action_id' => (int) $action['id']]);
        $this->persistence->action->finishFuelTransfer(['result' => json_encode(['outcome' => 'embarked'], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    private function completeDormantRecovery(array $action, string $now): void
    {
        if ($this->sectors === null || $action['auxiliary_id'] === null) { throw new \RuntimeException('Dormant recovery storage is unavailable.'); }
        $shipStmt = $this->persistence->actor->findShipById(['id' => (int) $action['ship_id']]); $ship = $shipStmt;
        if (!$ship) { $this->failAuxiliaryAction($action, $now, 'carrier_unavailable'); return; }
        $payload = json_decode((string) $action['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $sector = $this->sectorChanges->getOrCreateSector(new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']));
        $object = $sector->findObjectById((string) $payload['objectId']);
        if (!$object instanceof DormantConstruct || $object->getSubtype() !== 'others_auxiliary' || array_sum($object->getResourceAmounts()) < 5.01 - 0.00001) { $this->failAuxiliaryAction($action, $now, 'target_unavailable'); return; }
        $sector->removeObjectById($object->getId()); $this->sectorChanges->saveSector($sector);
        $recoveredId = is_string($payload['originalAuxiliaryId'] ?? null) && $payload['originalAuxiliaryId'] !== '' ? $payload['originalAuxiliaryId'] : OthersRepository::publicId('aux');
        $this->others->reviveAuxiliary((int) $ship['id'], $recoveredId);
        $this->persistence->production->releaseRepairActor(['now' => $now, 'id' => (int) $action['auxiliary_id'], 'action_id' => (int) $action['id']]);
        $this->persistence->action->finishFuelTransfer(['result' => json_encode(['outcome' => 'recovered', 'auxiliaryId' => $recoveredId], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    private function failAuxiliaryAction(array $action, string $now, string $reason): void
    {
        if ($action['auxiliary_id'] !== null) { $this->persistence->production->releaseRepairActor(['now' => $now, 'id' => (int) $action['auxiliary_id'], 'action_id' => (int) $action['id']]); }
        $this->persistence->action->failFuelTransfer(['error' => json_encode(['code' => $reason, 'message' => 'The Others auxiliary task could not be completed.'], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    private function processHarvest(array $action, string $now): void
    {
        if ($this->sectors === null) { throw new \RuntimeException('Harvest sector storage is unavailable.'); }
        $stmt = $this->persistence->production->findHarvest(['action_id' => (int) $action['id']]); $harvest = $stmt;
        if (!$harvest) { return; }
        $canceling = $action['status'] === 'cancel_requested';
        if ($canceling && in_array((string) $harvest['phase'], ['destroying_life', 'mining'], true)) {
            $output = [];
            if ($harvest['phase'] === 'mining') {
                $elapsed = max(0, (new \DateTimeImmutable($now))->getTimestamp() - (new \DateTimeImmutable((string) $harvest['phase_started_at']))->getTimestamp());
                $fraction = max(0.0, min(1.0, ($elapsed - 300) / 300));
                if ($fraction > 0.0) { $output = $this->extractHarvestResources($harvest, round((float) $harvest['reserved_capacity'] * $fraction, 4)); }
            }
            $this->persistence->production->beginHarvestRecall(['now' => $now, 'output' => json_encode($output, JSON_THROW_ON_ERROR), 'id' => (int) $harvest['id']]);
            $this->scheduleExistingAction($action, (new \DateTimeImmutable($now))->modify('+5 minutes')->format('c'), 'cancel_requested');
            return;
        }
        if ($harvest['phase'] === 'recalling') {
            $this->persistence->production->beginOrbitExit(['now' => $now, 'id' => (int) $harvest['id']]);
            $this->scheduleExistingAction($action, (new \DateTimeImmutable($now))->modify('+10 minutes')->format('c'), 'cancel_requested');
            return;
        }
        if ($harvest['phase'] === 'orbit_exit') {
            $output = is_string($harvest['pending_output_json']) ? json_decode($harvest['pending_output_json'], true) : [];
            $this->finishHarvest($action, $harvest, $output, $now, canceled: true);
            return;
        }
        if ($harvest['phase'] === 'destroying_life') {
            $shipStmt = $this->persistence->actor->findShipById(['id' => (int) $harvest['ship_id']]); $ship = $shipStmt;
            if (!$ship) { $this->finishHarvest($action, $harvest, [], $now, canceled: false, failure: 'carrier_unavailable'); return; }
            $sector = $this->sectorChanges->getOrCreateSector(new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z'])); $planet = $sector->findObjectById((string) $harvest['target_object_id']);
            if (!$planet instanceof Planet) { $this->finishHarvest($action, $harvest, [], $now, canceled: false, failure: 'target_unavailable'); return; }
            $amounts = $planet->getResourceAmounts(); $amounts['carbon_compounds'] = round($amounts['carbon_compounds'] + (float) $harvest['biological_carbon'], 4);
            $sector->replaceObject($planet->withResourceAmounts($amounts, intelligentLife: false)); $this->sectorChanges->saveSector($sector);
            $this->persistence->production->beginHarvestMining(['now' => $now, 'id' => (int) $harvest['id']]);
            $this->persistence->action->updateHarvestDeadline(['ends' => (new \DateTimeImmutable($now))->modify('+10 minutes')->format('c'), 'now' => $now, 'id' => (int) $action['id']]);
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
        $shipStmt = $this->persistence->actor->findShipById(['id' => (int) $harvest['ship_id']]); $ship = $shipStmt;
        if (!$ship || $grossCapacity <= 0.0) { return []; }
        $sector = $this->sectorChanges?->getOrCreateSector(new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z'])); $planet = $sector?->findObjectById((string) $harvest['target_object_id']);
        if (!$planet instanceof Planet) { return []; }
        $amounts = $planet->getResourceAmounts(); $total = array_sum($amounts); $grossTotal = round(min($grossCapacity, $total), 4);
        if ($grossTotal <= 0.0) { return []; }
        $gross = []; $stored = []; $consumed = []; $remainingGross = $grossTotal;
        foreach (ResourceComposition::TYPES as $index => $type) {
            $take = $index === count(ResourceComposition::TYPES) - 1 ? min((float) $amounts[$type], $remainingGross) : min((float) $amounts[$type], round($grossTotal * (float) $amounts[$type] / $total, 4));
            $take = round(max(0.0, $take), 4); $gross[$type] = $take; $remainingGross = round(max(0.0, $remainingGross - $take), 4);
            $consumed[$type] = round($take * 0.10, 4); $stored[$type] = round($take - $consumed[$type], 4); $amounts[$type] = round($amounts[$type] - $take, 4);
        }
        $sector->replaceObject($planet->withResourceAmounts($amounts, true, false)); $this->sectorChanges?->saveSector($sector);
        return ['gross' => $gross, 'consumed' => $consumed, 'stored' => $stored];
    }

    private function finishHarvest(array $action, array $harvest, array $output, string $now, bool $canceled, ?string $failure = null): void
    {
        $stored = is_array($output['stored'] ?? null) ? $output['stored'] : [];
        $inventoryStored = $stored;
        if ($failure === null) {
            $deuteriumAllocation = $this->allocateHarvestedDeuterium(
                (int) $harvest['ship_id'],
                (float) ($stored[ResourceComposition::DEUTERIUM] ?? 0.0),
                $now,
            );
            $inventoryStored[ResourceComposition::DEUTERIUM] = $deuteriumAllocation['inventoryEce'];
            $output['deuteriumAllocation'] = $deuteriumAllocation;
        }
        foreach (ResourceComposition::TYPES as $type) {
            $amount = (float) ($inventoryStored[$type] ?? 0.0);
            if ($amount > 0.0) { $this->persistence->production->creditHarvestResource(['amount' => $amount, 'now' => $now, 'ship_id' => (int) $harvest['ship_id'], 'type' => $type]); }
        }
        $this->persistence->production->embarkHarvestActors(['now' => $now, 'action_id' => (int) $action['id']]);
        $reservedCapacity = (float) $harvest['reserved_capacity'];
        $this->persistence->production->finishHarvestShip(['reserved_floor' => $reservedCapacity, 'reserved_decrease' => $reservedCapacity, 'now' => $now, 'id' => (int) $harvest['ship_id'], 'action_id' => (int) $action['id']]);
        $status = $failure !== null ? 'failed' : ($canceled ? 'canceled' : 'succeeded');
        $result = $failure === null ? ['outcome' => $canceled ? 'interrupted' : 'harvested', 'resources' => $output] : null;
        $error = $failure !== null ? ['code' => $failure, 'message' => 'The harvest could not be completed.'] : null;
        $this->persistence->production->finishHarvest(['phase' => $status, 'output' => json_encode($output, JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $harvest['id']]);
        $this->persistence->action->finishHarvestAction(['status' => $status, 'result' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR), 'error' => $error === null ? null : json_encode($error, JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    /** @return array{tankPoints:float,tankEquivalentEce:float,inventoryEce:float} */
    private function allocateHarvestedDeuterium(int $shipId, float $storedEce, string $now): array
    {
        $storedEce = round(max(0.0, $storedEce), 4);
        $ship = $this->persistence->actor->findTankForUpdate($shipId);
        if (!is_array($ship)) {
            throw new \RuntimeException('Others harvest carrier disappeared before deuterium allocation.');
        }

        $freeTankPoints = max(
            0.0,
            (float) $ship['deuterium_capacity']
                - (float) $ship['deuterium_stock']
                - (float) $ship['deuterium_reserved'],
        );
        // Harvested resources are canonical to 0.0001 ECE, or 0.01 tank point.
        $freeTankEce = floor(($freeTankPoints * 100) + 0.00001) / 10000;
        $tankEquivalentEce = round(min($storedEce, $freeTankEce), 4);
        $tankPoints = round($tankEquivalentEce * ResourceComposition::DEUTERIUM_TANK_POINTS_PER_ECE, 4);
        $inventoryEce = round($storedEce - $tankEquivalentEce, 4);

        if ($tankPoints > 0.0) {
            $this->persistence->production->creditHarvestFuel(['tank_points' => $tankPoints, 'now' => $now, 'id' => $shipId]);
        }

        return [
            'tankPoints' => $tankPoints,
            'tankEquivalentEce' => $tankEquivalentEce,
            'inventoryEce' => $inventoryEce,
        ];
    }

    private function scheduleExistingAction(array $action, string $runAt, string $expectedStatus): void
    {
        $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $runAt, ['expectedStatus' => $expectedStatus]);
        $this->persistence->action->rescheduleAction(['event_id' => $event->id, 'ends_at' => $runAt, 'now' => gmdate('c'), 'id' => (int) $action['id']]);
    }

    private function completeCraft(array $action, string $now): void
    {
        $stmt = $this->persistence->production->findCraft(['action_id' => (int) $action['id']]); $craft = $stmt;
        if (!$craft || $craft['status'] !== 'queued') { return; }
        $shipStmt = $this->persistence->production->findCraftCarrier(['id' => (int) $craft['ship_id']]); $ship = $shipStmt;
        if (!$ship) {
            $this->persistence->production->failCraft(['now' => $now, 'id' => (int) $craft['id']]);
            $this->persistence->action->failFuelTransfer(['error' => json_encode(['code' => 'carrier_unavailable', 'message' => 'The crafting mothership is unavailable.'], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
            return;
        }
        $output = match ($craft['recipe_id']) {
            'standard_ship' => ['kind' => 'standard_ship', 'id' => ($created = $this->others->createStandardShip($ship))['public_id']],
            'others_auxiliary' => ['kind' => 'others_auxiliary', 'id' => ($created = $this->others->createAuxiliary((int) $ship['id']))['public_id']],
            'missile' => $this->createCraftedMissileItem((int) $ship['id'], $now),
            default => throw new \RuntimeException('Unsupported frozen Others craft recipe.'),
        };
        $this->persistence->production->finishCraft(['now' => $now, 'id' => (int) $craft['id']]);
        $this->persistence->action->finishFuelTransfer(['result' => json_encode(['output' => $output], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
        $this->persistence->production->releaseRepairActor(['now' => $now, 'id' => (int) $craft['assistant_auxiliary_id'], 'action_id' => (int) $action['id']]);
        $outputSpace = (float) $craft['output_space'];
        if ($outputSpace > 0.0) { $this->persistence->production->releaseCraftCapacity(['reserved_floor' => $outputSpace, 'reserved_decrease' => $outputSpace, 'now' => $now, 'id' => (int) $ship['id']]); }
    }

    private function createCraftedMissileItem(int $shipId, string $now): array
    {
        $publicId = OthersRepository::publicId('item');
        $this->persistence->combat->createCraftedMissile(['public_id' => $publicId, 'ship_id' => $shipId, 'metadata' => json_encode(['technology' => 'others', 'recipe' => 'missile', 'fabricator' => 'others', 'craftedAt' => $now], JSON_THROW_ON_ERROR), 'now' => $now]);
        return ['kind' => 'missile', 'id' => $publicId, 'containerSpaceEce' => 2.0];
    }

    private function processLaser(array $action, string $now): void
    {
        $stmt = $this->persistence->combat->findLaser(['action_id' => (int) $action['id']]); $lock = $stmt;
        if (!$lock || in_array((string) $lock['status'], ['stopped','failed'], true)) { return; }
        if ($lock['status'] === 'queued') {
            $target = $this->resolveLocalTarget(['sector_x' => $lock['sector_x'], 'sector_y' => $lock['sector_y'], 'sector_z' => $lock['sector_z'], 'status' => $lock['ship_status'], 'destroyed_at' => $lock['destroyed_at']], (string) $lock['target_public_id'], laserOnly: true);
            if ($target === null) { $this->stopLaser($action, $lock, $now, 'target_unavailable'); return; }
            $start = new \DateTimeImmutable($now); $exhausts = $start->modify('+' . max(1, (int) round((float) $lock['deuterium_stock'] * 60)) . ' seconds'); $damage = $start->modify('+10 minutes'); $next = $damage < $exhausts ? $damage : $exhausts;
            $this->persistence->combat->startLaser(['now' => $now, 'damage' => $damage->format('c'), 'exhausts' => $exhausts->format('c'), 'id' => (int) $lock['id']]);
            $this->persistence->action->startLaserAction(['ends' => $next->format('c'), 'now' => $now, 'id' => (int) $action['id']]);
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
        if ($charged > 0.0) { $this->persistence->combat->debitLaserFuel(['stock_floor' => $charged, 'stock_decrease' => $charged, 'now' => $now, 'id' => (int) $ship['id']]); }
        $this->persistence->combat->accountLaserDamage(['now' => $now, 'id' => (int) $lock['id']]);
        if ($available <= $cost + 0.00001 || $current >= new \DateTimeImmutable((string) $lock['exhausts_at'])) { $this->stopLaser($action, $lock, $now, 'deuterium_exhausted'); return; }
        if ($current >= new \DateTimeImmutable((string) $lock['next_damage_at'])) {
            $damageKey = 'laser:' . $action['public_id'] . ':' . $lock['next_damage_at'];
            if ($this->persistence->combat->recordDamage(['key' => $damageKey, 'kind' => $target['kind'], 'target' => $target['id'], 'damage' => $target['kind'] === 'probe' ? 5 : ($target['kind'] === 'manny' ? 1 : 0), 'now' => $now])) {
                if ($target['kind'] === 'probe' && $this->probes !== null) {
                    $probe = $this->probes->findById((int) $target['id']);
                    if ($probe !== null) {
                        $probe->subtractIntegrityPercent(5.0);
                        if ($probe->status === ProbeStatus::Dead) {
                            $this->interruptProbeStorageTransfers($probe->id, (string) $lock['next_damage_at']);
                        }
                        $this->probes->save($probe);
                        if ($probe->status === ProbeStatus::Dead) {
                            $this->reinstantiation->handleTerminalProbeLoss($probe, ProbeReinstantiationService::TERMINAL_REASON_LASER);
                            $this->stopLaser($action, $lock, $now, 'target_destroyed');
                            return;
                        }
                    }
                }
                elseif ($target['kind'] === 'manny') { $victim=$this->mannies?->findByUid($target['id']); if($victim!==null){$this->mannyStorageTransfers?->interruptManny($victim->id,(string)$lock['next_damage_at']);} $this->persistence->combat->deleteSectorManny(['uid' => $target['id']]); if ($this->sectors !== null) { $sector = $this->sectorChanges->getOrCreateSector(new SectorCoordinates((int) $lock['sector_x'], (int) $lock['sector_y'], (int) $lock['sector_z'])); if ($sector->removeObjectById('manny-' . $target['id'])) { $this->sectorChanges->saveSector($sector); } } $this->stopLaser($action, $lock, $now, 'target_destroyed'); return; }
            }
            $nextDamage = (new \DateTimeImmutable((string) $lock['next_damage_at']))->modify('+10 minutes');
            $this->persistence->combat->scheduleLaserDamage(['next' => $nextDamage->format('c'), 'now' => $now, 'id' => (int) $lock['id']]);
            $next = $nextDamage < new \DateTimeImmutable((string) $lock['exhausts_at']) ? $nextDamage : new \DateTimeImmutable((string) $lock['exhausts_at']);
            $this->scheduleExistingAction($action, $next->format('c'), 'running');
        }
    }

    private function stopLaser(array $action, array $lock, string $now, string $reason): void
    {
        $nextTarget = (new \DateTimeImmutable($now))->modify('+1 minute')->format('c'); $this->persistence->combat->stopLaser(['now' => $now, 'id' => (int) $lock['id']]);
        $this->persistence->combat->setLaserCooldown(['next' => $nextTarget, 'now' => $now, 'id' => (int) $lock['ship_id']]);
        $this->persistence->action->finishLaserAction(['result' => json_encode(['outcome' => 'stopped', 'reason' => $reason, 'nextTargetAt' => $nextTarget], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
    }

    public function processScheduledMannyMissile(ScheduledEvent $event): bool
    {
        if ($event->entityType !== 'manny' || $this->mannies === null) { return false; }
        $manny = $this->mannies->findById($event->entityId);
        if ($manny === null || $manny->currentTask !== Manny::TASK_PREPARING_MISSILE || $manny->taskScheduledEventId !== $event->id) { return false; }
        $missileId = $manny->taskPayload['missileLaunchId'] ?? null;
        if (!is_string($missileId) || $missileId === '') { throw new \RuntimeException('Missile preparation has no launch identity.'); }
        $this->persistence->transaction->run(function () use ($manny, $missileId, $event): void {
            if ($manny->probeId !== null) { $this->persistence->locks->lock('probe', $manny->probeId); }
            $this->persistence->locks->lock('manny', $manny->id);
            $manny = $this->mannies?->findById($manny->id);
            if ($manny === null || $manny->currentTask !== Manny::TASK_PREPARING_MISSILE || $manny->taskScheduledEventId !== $event->id) { return; }
            $stmt = $this->persistence->combat->findPreparingProbeLaunch(['public_id' => $missileId]); $launch = $stmt;
            if (!$launch) { $this->clearMissileMannyTask($manny); return; }
            $probe = $this->probes?->findById((int) $launch['probe_id']);
            $validCarrier = $probe !== null && !in_array($probe->status, [ProbeStatus::Dead, ProbeStatus::TrappedByBlackHole], true) && $manny->isOnProbe() && $manny->probeId === $probe->id
                && [$probe->currentSector->getX(),$probe->currentSector->getY(),$probe->currentSector->getZ()] === [(int)$launch['sector_x'],(int)$launch['sector_y'],(int)$launch['sector_z']];
            $target = $validCarrier ? $this->resolveMissileTarget((int)$launch['sector_x'],(int)$launch['sector_y'],(int)$launch['sector_z'],(string)$launch['target_public_id']) : null;
            if (!$validCarrier || $target === null || $target['kind'] !== $launch['target_kind']) {
                $this->persistence->combat->failPreparingProbeLaunch(['now' => gmdate('c'), 'id' => (int)$launch['id']]);
                $this->clearMissileMannyTask($manny); return;
            }
            $itemStmt = $this->persistence->combat->findProbeMissile(['id' => (int)$launch['probe_item_id'], 'probe_id' => (int)$launch['probe_id']]);
            if (!$itemStmt) {
                $this->persistence->combat->failMissingMissile(['now' => gmdate('c'), 'id' => (int)$launch['id']]);
                $this->clearMissileMannyTask($manny); return;
            }
            $now = gmdate('c'); $this->persistence->combat->detachProbeMissile(['now' => $now, 'id' => (int)$launch['id']]);
            $this->persistence->combat->deleteProbeMissile(['id' => (int)$launch['probe_item_id']]);
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
        $stmt = $this->persistence->combat->findQueuedOthersLaunch(['action_id' => (int)$action['id']]); $launch = $stmt;
        if (!$launch) { return; }
        $ship = $this->others->findShipByPublicId((string)$launch['launcher_public_id']);
        $target = $this->resolveMissileTarget((int)$launch['sector_x'],(int)$launch['sector_y'],(int)$launch['sector_z'],(string)$launch['target_public_id']);
        if ($ship === null || $ship['destroyed_at'] !== null || $ship['status'] === 'transit' || !$this->sameCoordinates($ship, $launch) || $target === null || $target['kind'] !== $launch['target_kind']) {
            $this->persistence->combat->releaseLaunchMissile(['now'=>$now,'id'=>(int)$launch['others_item_id'],'action_id'=>(int)$action['id']]);
            $this->persistence->combat->failOthersLaunch(['now'=>$now,'id'=>(int)$launch['id']]);
            $this->persistence->action->failFuelTransfer(['error'=>json_encode(['code'=>'target_not_found','message'=>'The missile target is no longer admissible.'],JSON_THROW_ON_ERROR),'now'=>$now,'id'=>(int)$action['id']]); return;
        }
        $this->persistence->combat->detachOthersMissile(['now'=>$now,'id'=>(int)$launch['id']]);
        $deleted = $this->persistence->combat->consumeLaunchMissile(['id'=>(int)$launch['others_item_id'],'action_id'=>(int)$action['id']]);
        if ($deleted !== 1) { throw new \RuntimeException('Reserved Others missile item is inconsistent.'); }
        $this->createProjectile($launch, $action, $target, $now);
    }

    private function createProjectile(array $launch, ?array $action, array $target, string $now): void
    {
        $interception = $target['kind'] === 'missile'; $impactAt = (new \DateTimeImmutable($now))->modify($interception ? '+15 minutes' : '+30 minutes')->format('c');
        $insertedProjectileId = $this->persistence->combat->createProjectile([
            'public_id'=>(string)$launch['public_id'],'launch_id'=>(int)$launch['id'],'action_id'=>$action !== null ? (int)$action['id'] : null,'launcher_kind'=>(string)$launch['launcher_kind'],'launcher_public_id'=>(string)$launch['launcher_public_id'],'target_public_id'=>(string)$launch['target_public_id'],'target_kind'=>(string)$launch['target_kind'],'x'=>(int)$launch['sector_x'],'y'=>(int)$launch['sector_y'],'z'=>(int)$launch['sector_z'],'launched_at'=>$now,'impact_at'=>$impactAt,'created_at'=>$now,'updated_at'=>$now,
        ]);
        $projectileId = (int)$insertedProjectileId; $event = $this->events->schedule(SchedulerService::MISSILE_PROJECTILE,'missile_projectile',$projectileId,$impactAt,['projectileId'=>(string)$launch['public_id']]);
        $this->persistence->combat->launchMissile(['projectile'=>(string)$launch['public_id'],'impact_at'=>$impactAt,'event_id'=>$event->id,'now'=>$now,'id'=>(int)$launch['id']]);
        if ($action !== null) { $this->persistence->action->startMissileAction(['impact_at'=>$impactAt,'event_id'=>$event->id,'now'=>$now,'id'=>(int)$action['id']]); }
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
        $this->persistence->transaction->run(function () use ($event): void {
            $this->persistence->locks->lock('projectile', $event->entityId);
            $stmt = $this->persistence->combat->findMovingProjectile(['id'=>$event->entityId]); $projectile=$stmt; if(!$projectile){return;}
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
        $now=gmdate('c'); $actionPublicId=(string)($projectile['action_public_id']??'');
        $this->persistence->combat->recordProjectileHistory(['projectile'=>(string)$projectile['public_id'],'action'=>$actionPublicId,'result'=>$result,'details'=>json_encode($details,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'resolved_at'=>$now]);
        $this->persistence->combat->resolveLaunch(['result'=>$result,'now'=>$now,'id'=>(int)$projectile['launch_sql_id']]);
        if($projectile['action_id']!==null){$this->persistence->action->finishProjectileAction(['details'=>json_encode(['outcome'=>$result]+$details,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'now'=>$now,'id'=>(int)$projectile['action_id']]);}
        $this->createProjectileResolutionAlerts($projectile, $target, $result, $details, $now);
        $this->persistence->combat->deleteProjectile(['id'=>(int)$projectile['id']]);
        if ($target['kind'] === 'probe' && ($details['destroyed'] ?? false)) {
            $probe = $this->probes?->findById((int) $target['id']);
            if ($probe !== null) {
                $this->reinstantiation->handleTerminalProbeLoss($probe, ProbeReinstantiationService::TERMINAL_REASON_MISSILE);
            }
        }
    }

    private function interceptProjectile(array $target,array $interceptor): void
    {
        $stmt = $this->persistence->combat->findProjectileByPublicId(['id'=>$target['id']]); $projectile=$stmt;
        if($projectile){$this->finishProjectile($projectile,'intercepted',['interceptorId'=>$interceptor['public_id']],['kind'=>(string)$projectile['target_kind'],'id'=>(string)$projectile['target_public_id']]);}
    }

    /** @return array<string,mixed> */
    private function applyMissileImpact(array $projectile,array $target): array
    {
        if ($target['kind'] === 'dormant_construct') {
            $this->depotService()->impact($target['id']);
            return ['damage'=>0,'destroyed'=>false,'message'=>'La structure a résisté. Vous pouvez envoyer une Manny pour une nouvelle inspection.'];
        }

        $key='missile:'.$projectile['public_id'].':'.$target['kind'].':'.$target['id']; $damage=match($target['kind']){'probe'=>(12+(int)floor($this->stableFraction($key.'|damage')*7)),'others_ship'=>10,default=>1};
        if($target['kind']==='others_ship'){$before=$this->others->findShipByPublicId((string)$target['id']);$responsiblePlayerId=($projectile['launcher_kind']??null)==='probe'?(int)$projectile['player_id']:null;$ship=$this->damageShip((string)$target['id'],$damage,$key,['type'=>'missile','missileId'=>(string)$projectile['public_id'],'occurredAt'=>$projectile['impact_at']],responsiblePlayerId:$responsiblePlayerId);$applied=max(0,(int)($before['integrity']??0)-(int)($ship['integrity']??0));$maximum=max(1,(int)($before['max_integrity']??1));return ['damage'=>$applied,'damagePercent'=>round(100*$applied/$maximum,2),'destroyed'=>$ship===null||$ship['destroyed_at']!==null];}
        if (!$this->persistence->combat->recordDamage(['key'=>$key,'kind'=>$target['kind'],'target'=>$target['id'],'damage'=>$damage,'now'=>gmdate('c')])) { return ['damage'=>0,'replayed'=>true]; }
        if($target['kind']==='manny'){ $victim=$this->mannies?->findByUid($target['id']); if($victim!==null){$this->mannyStorageTransfers?->interruptManny($victim->id,$projectile['impact_at']);} $this->persistence->combat->deleteImpactedManny(['uid'=>$target['id']]);return ['damage'=>1,'destroyed'=>true];}
        if($target['kind']==='others_auxiliary'){$this->destroyAuxiliary($target,$projectile['impact_at']);return ['damage'=>1,'destroyed'=>true];}
        if($target['kind']==='probe' && $this->probes!==null){$probe=$this->probes->findById((int)$target['id']);$applied=0.0;if($probe!==null){$applied=$probe->subtractIntegrityPercent($damage);if($probe->status===ProbeStatus::Dead){$this->interruptProbeStorageTransfers($probe->id,$projectile['impact_at']);}$this->probes->save($probe);}return ['damage'=>$applied,'damagePercent'=>$applied,'destroyed'=>$probe?->status===ProbeStatus::Dead];}
        if ($target['kind'] === 'motorized_asteroid') {
            $trajectoryId = (int) $target['trajectory_id'];
            $now = gmdate('c');
            $stmt = $this->persistence->combat->incrementAsteroidHits(['now' => $now, 'id' => $trajectoryId]);
            $hitsStmt = $this->persistence->combat->findAsteroidHits(['id' => $trajectoryId]);
            $hits = (int) $hitsStmt;
            if ($hits >= 3) {
                $this->persistence->combat->destroyAsteroid(['now' => $now, 'id' => $trajectoryId]);
                $this->events->cancelPending(SchedulerService::ASTEROID_TRAJECTORY_PHASE, 'asteroid_trajectory', $trajectoryId);
                if ($this->sectors !== null) {
                    $sector = $this->sectorChanges->getOrCreateSector(new SectorCoordinates((int) $projectile['sector_x'], (int) $projectile['sector_y'], (int) $projectile['sector_z']));
                    $changed = $sector->removeObjectById((string) $target['id']);
                    $hadContainers = $sector->containersForObject((string) $target['id']) !== [];
                    $sector->removeContainersForObject((string) $target['id']);
                    $changed = $hadContainers || $changed;
                    if ($changed) {
                        $this->sectorChanges->saveSector($sector);
                    }
                }
            }
            return ['damage' => 1, 'hits' => $hits, 'destroyed' => $hits >= 3];
        }
        return ['damage'=>$damage];
    }

    private function destroyAuxiliary(array $target,string $causalTime): void
    {
        $query = $this->persistence->destruction->findAuxiliaryStorageActions([(int) $target['sql_id']]);
        foreach ($query as $action) { $this->settleStorageAction($action, $causalTime, 'auxiliary_destroyed'); }
        $now=gmdate('c');$this->persistence->destruction->detachAuxiliaryActions(['now'=>$now,'id'=>(int)$target['sql_id']]);
        $this->persistence->destruction->destroyAuxiliary(['now'=>$now,'id'=>(int)$target['sql_id']]);
        if($this->sectors!==null){$sector=$this->sectorChanges->getOrCreateSector(new SectorCoordinates((int)$target['sector_x'],(int)$target['sector_y'],(int)$target['sector_z']));$wreck=DormantConstruct::fromOthersAuxiliary((string)$target['id'],true);if($sector->findObjectById($wreck->getId())===null){$sector->addObject($wreck);$this->sectorChanges->saveSector($sector);}}
    }

    /** Applies one idempotent damage event and returns the remaining ship row when it still exists. */
    public function interruptProbeStorageTransfers(int $probeId, string $now): void
    {
        $this->mannyStorageTransfers?->interruptProbe($probeId,$now,'carrier_destroyed');
    }

    public function damageShip(string $shipPublicId,int $damage,string $eventKey,array $cause,bool $relativistic=false,?int $responsiblePlayerId=null): ?array
    {
        return $this->persistence->transaction->run(function()use($shipPublicId,$damage,$eventKey,$cause,$relativistic,$responsiblePlayerId):?array{$ship=$this->others->findShipByPublicId($shipPublicId);
            if ($ship === null) { return null; }
            $roots = $ship['type'] === 'mothership' ? $this->others->findActiveShipsByFleetId((int) $ship['fleet_id']) : [$ship];
            usort($roots, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
            foreach ($roots as $root) { $this->persistence->locks->lock('ship', (int) $root['id']); }
            $ship = $this->others->findShipByPublicId($shipPublicId);
            if ($ship === null || $ship['destroyed_at'] !== null || $ship['status'] === 'removed') { return $ship; }
            $exists = $this->persistence->destruction->damageAlreadyRecorded(['key'=>$eventKey]);if($exists!==false){return $ship;}
            $applied=$relativistic?(int)$ship['integrity']:min(max(0,$damage),(int)$ship['integrity']);$this->persistence->destruction->recordShipDamage(['key'=>$eventKey,'target'=>$shipPublicId,'damage'=>$applied,'now'=>gmdate('c')]);
            $remaining=$relativistic?0:max(0,(int)$ship['integrity']-$applied);$this->persistence->destruction->updateShipIntegrity(['integrity'=>$remaining,'now'=>gmdate('c'),'id'=>(int)$ship['id']]);if($remaining===0){$this->destroyShip($ship,$responsiblePlayerId,$cause);return $this->others->findShipByPublicId($shipPublicId);}return $this->others->findShipByPublicId($shipPublicId);});
    }

    private function interruptInventoryTransfers(array $ship, string $now, string $reason): void
    {
        foreach ($this->persistence->destruction->activeTransfersTouchingShip((int) $ship['id'], $ship['public_id']) as $identity) {
            foreach ($this->persistence->locks->actionShipIds($identity) as $id) { $this->persistence->locks->lock('ship', $id); }
            if ($identity['auxiliary_id'] !== null) { $this->persistence->locks->lock('auxiliary', (int) $identity['auxiliary_id']); }
            $action = $this->persistence->locks->lock('action', (int) $identity['id']);
            if ($action === null || !in_array($action['status'], ['queued','running','cancel_requested'], true)) { continue; }
            if ($action['type'] === 'inventory_transfer') {
                $transfer = $this->persistence->inventory->findTransfer(['action_id' => (int) $action['id']]);
                if (!$transfer || $transfer['status'] !== 'queued') { continue; }
                $space = 0.0;
                if ($transfer['kind'] === 'resource') {
                    $space = (float) $transfer['amount'];
                    $this->persistence->inventory->releaseResourceReservation(['reserved_floor' => $space, 'reserved_decrease' => $space, 'now' => $now, 'ship_id' => (int) $transfer['source_ship_id'], 'resource_type' => $transfer['resource_type']]);
                } else {
                    foreach ($this->others->inventoryItemsByPublicIds((int) $transfer['source_ship_id'], json_decode($transfer['item_ids_json'], true, 512, JSON_THROW_ON_ERROR)) as $item) { $space += (float) $item['container_space']; }
                    $this->persistence->inventory->releaseItemReservations(['now' => $now, 'action_id' => (int) $action['id']]);
                }
                $this->finishTransfer($transfer, $action, $space, $now, false, $reason);
            } else {
                $payload = json_decode($action['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                $amount = (float) $payload['amount'];
                foreach ($this->persistence->locks->actionShipIds($action) as $id) {
                    $this->persistence->inventory->releaseFuelReservation(['reserved_floor' => $amount, 'reserved_decrease' => $amount, 'now' => $now, 'id' => $id]);
                }
                $this->persistence->action->failFuelTransfer(['error' => json_encode(['code' => $reason, 'message' => 'The carrier became unavailable.'], JSON_THROW_ON_ERROR), 'now' => $now, 'id' => (int) $action['id']]);
                if ($action['auxiliary_id'] !== null) { $this->persistence->inventory->releaseTransferActor(['now' => $now, 'id' => (int) $action['auxiliary_id'], 'action_id' => (int) $action['id']]); }
            }
            $this->events->cancelPending(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id']);
        }
    }

    private function destroyShip(array $ship, ?int $responsiblePlayerId, array $cause): void
    {
        $now = $cause['occurredAt'] ?? gmdate('c');
        $constructionCarriers = $ship['type'] === 'mothership' ? $this->others->findActiveShipsByFleetId((int) $ship['fleet_id']) : [$ship];
        foreach ($constructionCarriers as $carrier) {
            $this->interruptDepotConstructions((int) $carrier['id'], $now, 'carrier_destroyed');
            $this->interruptInventoryTransfers($carrier, $now, 'carrier_destroyed');
            $this->persistence->destruction->terminateCarrierWork((int) $carrier['id'], $now);
        }
        if ($ship['type'] === 'mothership') { $this->createMothershipWreck($ship, $cause, $now); }
        $ships = $ship['type'] === 'mothership'
            ? $this->others->findActiveShipsByFleetId((int) $ship['fleet_id'])
            : [$ship];
        $destroyedTarget = false;
        foreach ($ships as $victim) {
            $this->turnDeployedAuxiliariesDormant((int) $victim['id'], $now);
            $this->persistence->destruction->detachShipActions(['ship_id' => (int) $victim['id']]);
            $this->persistence->destruction->failShipLaunches(['now' => $now, 'ship_id' => (int) $victim['id']]);
            $this->persistence->destruction->failShipActions(['error' => json_encode(['code' => 'carrier_destroyed', 'message' => 'The carrier was destroyed.'], JSON_THROW_ON_ERROR), 'now' => $now, 'ship_id' => (int) $victim['id']]);
            // Completed harvests still reference their participants; keep the actions, not links to deleted auxiliaries.
            $this->persistence->destruction->deleteShipParticipants(['ship_id' => (int) $victim['id']]);
            $this->persistence->destruction->deleteShipAuxiliaries(['ship_id' => (int) $victim['id']]);
            $this->persistence->destruction->deleteShipItems(['ship_id' => (int) $victim['id']]);
            $this->persistence->destruction->deleteShipResources(['ship_id' => (int) $victim['id']]);
            $status = (int) $victim['id'] === (int) $ship['id'] ? 'destroyed' : 'removed';
            $destroy = $this->persistence->destruction->destroyShip(['status' => $status, 'now' => $now, 'id' => (int) $victim['id']]);
            if ((int) $victim['id'] === (int) $ship['id'] && $destroy === 1) {
                $destroyedTarget = true;
            }
        }
        if ($ship['type'] === 'mothership') {
            $this->persistence->destruction->dissolveFleet(['now' => $now, 'id' => (int) $ship['fleet_id']]);
        }
        if ($destroyedTarget && $responsiblePlayerId !== null && $this->players !== null) {
            $this->players->recordOthersShipDestroyed($responsiblePlayerId, $ship['type'] === 'mothership');
        }

    }

    /** @param array<string, mixed> $ship @param array<string, mixed> $cause */
    private function createMothershipWreck(array $ship, array $cause, string $now): void
    {
        if ($this->sectors === null) {
            throw new \RuntimeException('Sector storage is unavailable for an Others mothership wreck.');
        }

        $causeType = (string) ($cause['type'] ?? '');
        match ($causeType) {
            'missile' => isset($cause['missileId']) && (string) $cause['missileId'] !== ''
                ? true
                : throw new \InvalidArgumentException('A missile destruction cause requires missileId.'),
            'motorized_asteroid' => isset($cause['asteroidId'], $cause['trajectoryUid'])
                && (string) $cause['asteroidId'] !== '' && (string) $cause['trajectoryUid'] !== ''
                    ? true
                    : throw new \InvalidArgumentException('A motorized asteroid destruction cause requires asteroidId and trajectoryUid.'),
            default => throw new \InvalidArgumentException('Unknown Others mothership destruction cause.'),
        };

        $resourceAmounts = [
            ResourceComposition::DEUTERIUM => 0.0,
            ResourceComposition::METALS => 0.0,
            ResourceComposition::ICE => 0.0,
            ResourceComposition::CARBON_COMPOUNDS => 0.0,
        ];
        $resourceStmt = $this->persistence->destruction->wreckResources(['ship_id' => (int) $ship['id']]);
        foreach ($resourceStmt as $resource) {
            $type = (string) $resource['resource_type'];
            if (array_key_exists($type, $resourceAmounts)) {
                $resourceAmounts[$type] = round((float) $resource['amount'], 4);
            }
        }

        $itemStmt = $this->persistence->destruction->wreckItems(['ship_id' => (int) $ship['id']]);
        $driftingItems = [];
        foreach ($itemStmt as $item) {
            switch ((string) $item['type']) {
                case 'missile':
                    $driftingItems[] = [
                        'type' => ProbeItem::TYPE_MISSILE,
                        'quantity' => (int) $item['quantity'],
                        'containerSpace' => Config::float(
                            $this->gameplayConfig,
                            'crafting.missile.containerSpace',
                            CraftingRecipeCatalog::MISSILE_CONTAINER_SPACE,
                        ),
                    ];
                    break;
            }
        }

        $wreck = DormantConstruct::fromOthersMothership(
            (string) $ship['public_id'],
            $resourceAmounts,
            Config::float($this->gameplayConfig, 'others.mothershipWreck.massKg', DormantConstruct::OTHERS_MOTHERSHIP_WRECK_MASS_KG),
            Config::float($this->gameplayConfig, 'others.mothershipWreck.radiusMeters', DormantConstruct::OTHERS_MOTHERSHIP_WRECK_RADIUS_METERS),
        );
        $sectorCoordinates = new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']);
        $sector = $this->sectorChanges->getOrCreateSector($sectorCoordinates);
        if ($sector->findObjectById($wreck->getId()) === null) {
            $sector->addObject($wreck);
            foreach ($driftingItems as $item) {
                if (!is_array($item) || ($item['type'] ?? null) !== ProbeItem::TYPE_MISSILE || (int) ($item['quantity'] ?? 0) <= 0) {
                    continue;
                }
                $objectId = SectorDriftingItem::objectIdForItemType(ProbeItem::TYPE_MISSILE);
                $existing = $sector->findObjectById($objectId);
                if ($existing instanceof SectorDriftingItem) {
                    $sector->replaceObject($existing->withQuantity($existing->getQuantity() + (int) $item['quantity']));
                } else {
                    $sector->addObject(new SectorDriftingItem(
                        $objectId,
                        ProbeItem::MISSILE_NAME,
                        ProbeItem::TYPE_MISSILE,
                        (int) $item['quantity'],
                        (float) $item['containerSpace'],
                    ));
                }
            }
            $this->sectorChanges->saveSector($sector);
        }

        $this->createMothershipWreckAlerts($sectorCoordinates, $wreck, $cause);
    }

    /** @param array<string, mixed> $cause */
    private function createMothershipWreckAlerts(SectorCoordinates $sector, DormantConstruct $wreck, array $cause): void
    {
        if ($this->probes === null || $this->alerts === null) {
            return;
        }
        $message = match ((string) ($cause['type'] ?? '')) {
            'missile' => 'An Others mothership was destroyed by missile ' . (string) $cause['missileId'] . '. Its remains have been detected as a dormant construct. A close inspection by a Manny may be worthwhile.',
            'motorized_asteroid' => 'An Others mothership was destroyed by the impact of motorized asteroid ' . (string) $cause['asteroidId'] . '. Its remains have been detected as a dormant construct. A close inspection by a Manny may be worthwhile.',
        };
        foreach ($this->probes->findBySector($sector) as $probe) {
            if (!$this->probeIsPresentInSector($probe, $sector)) {
                continue;
            }
            $this->alerts->createSectorObjectDetectedAlert(
                $probe->id,
                null,
                $sector,
                $wreck->getId(),
                $wreck->getType()->value,
                DormantConstruct::OTHERS_MOTHERSHIP_WRECK_NAME,
                $message,
            );
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
    private function lockCombatTarget(array $target): void
    {
        switch ($target['kind']) {
            case 'probe': $this->persistence->locks->lock('probe', (int) $target['id']); break;
            case 'manny':
                $manny = $this->mannies?->findByUid($target['id']);
                if ($manny !== null) { $this->persistence->locks->lock('manny', $manny->id); }
                break;
            case 'others_ship':
                $ship = $this->others->findShipByPublicId($target['id']);
                $ships = $ship !== null && $ship['type'] === 'mothership' ? $this->others->findActiveShipsByFleetId((int) $ship['fleet_id']) : ($ship === null ? [] : [$ship]);
                usort($ships, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
                foreach ($ships as $ship) { $this->persistence->locks->lock('ship', (int) $ship['id']); }
                break;
            case 'others_auxiliary': $this->persistence->locks->lock('auxiliary', (int) $target['sql_id']); break;
            case 'motorized_asteroid': $this->persistence->locks->lock('trajectory', (int) $target['trajectory_id']); break;
            case 'missile':
                $projectile = $this->persistence->combat->findProjectileByPublicId(['id' => $target['id']]);
                if ($projectile) { $this->persistence->locks->lock('projectile', (int) $projectile['id']); }
                break;
        }
    }

    private function resolveMissileTarget(int $x,int $y,int $z,string $targetId): ?array
    {
        $target = $this->resolveMissileTargetSnapshot($x, $y, $z, $targetId);
        if ($target !== null && $this->persistence->transaction->isActive()) {
            $this->lockCombatTarget($target);
            return $this->resolveMissileTargetSnapshot($x, $y, $z, $targetId);
        }
        return $target;
    }

    private function resolveMissileTargetSnapshot(int $x,int $y,int $z,string $targetId): ?array
    {
        $depot = $this->persistence->depots->find($targetId);
        if ($depot !== null && [(int)$depot['sector_x'],(int)$depot['sector_y'],(int)$depot['sector_z']] === [$x,$y,$z]) {
            return ['kind'=>'dormant_construct','id'=>$targetId];
        }

        $key="$x:$y:$z";
        $stmt = $this->persistence->target->findShipTarget(['id'=>$targetId,'x'=>$x,'y'=>$y,'z'=>$z]);if($row=$stmt){return ['kind'=>'others_ship','id'=>(string)$row['public_id'],'sql_id'=>(int)$row['id'],'departure_engaged'=>(bool)$row['departure_engaged']];}
        $stmt = $this->persistence->target->findAuxiliaryTarget(['id'=>$targetId,'x'=>$x,'y'=>$y,'z'=>$z]);if($row=$stmt){return ['kind'=>'others_auxiliary','id'=>(string)$row['public_id'],'sql_id'=>(int)$row['id'],'sector_x'=>$x,'sector_y'=>$y,'sector_z'=>$z];}
        if(ctype_digit($targetId)&&$this->probes!==null){$probe=$this->probes->findById((int)$targetId);if($probe!==null&&$probe->currentSector->toKey()===$key&&!in_array($probe->status,[ProbeStatus::Dead,ProbeStatus::Accelerating,ProbeStatus::Cruising,ProbeStatus::Decelerating],true)){return ['kind'=>'probe','id'=>$targetId,'departure_engaged'=>$probe->status===ProbeStatus::Preparing];}}
        if($this->mannies!==null){$manny=$this->mannies->findByUid($targetId);if($manny!==null&&$manny->locationType===Manny::LOCATION_SECTOR&&$manny->sector?->toKey()===$key){return ['kind'=>'manny','id'=>$targetId];}}
        $stmt = $this->persistence->target->findProjectileTarget(['id'=>$targetId,'x'=>$x,'y'=>$y,'z'=>$z]);if($stmt){return ['kind'=>'missile','id'=>$targetId];}
        $stmt = $this->persistence->target->findAsteroidTarget(['id'=>$targetId,'x'=>$x,'y'=>$y,'z'=>$z]);if($row=$stmt){$ratio=0.0;if((float)$row['target_speed_c']>0&&is_string($row['acceleration_started_at'])&&is_string($row['acceleration_ends_at'])){$start=strtotime($row['acceleration_started_at']);$end=strtotime($row['acceleration_ends_at']);$ratio=$end>$start?max(0.0,min(1.0,(time()-$start)/($end-$start))):1.0;}return ['kind'=>'motorized_asteroid','id'=>(string)$row['asteroid_id'],'trajectory_id'=>(int)$row['id'],'hit_probability'=>1.0-(2.0/3.0)*$ratio];}
        return null;
    }

    private function resolveLocalTarget(array $ship, string $targetId, bool $laserOnly = false): ?array
    {
        $target = $this->resolveLocalTargetSnapshot($ship, $targetId, $laserOnly);
        if ($target !== null && $this->persistence->transaction->isActive()) {
            $this->lockCombatTarget($target);
            return $this->resolveLocalTargetSnapshot($ship, $targetId, $laserOnly);
        }
        return $target;
    }

    private function resolveLocalTargetSnapshot(array $ship, string $targetId, bool $laserOnly = false): ?array
    {
        $x = (int) $ship['sector_x']; $y = (int) $ship['sector_y']; $z = (int) $ship['sector_z'];
        if (ctype_digit($targetId) && $this->probes !== null) { $probe = $this->probes->findById((int) $targetId); if ($probe !== null && $probe->currentSector->toKey() === "$x:$y:$z" && !in_array($probe->status->value, ['dead','accelerating','cruising','decelerating'], true)) { return ['kind' => 'probe', 'id' => $targetId]; } }
        if ($this->mannies !== null) { $manny = $this->mannies->findByUid($targetId); if ($manny !== null && $manny->locationType === 'sector' && $manny->sector?->toKey() === "$x:$y:$z") { return ['kind' => 'manny', 'id' => $targetId, 'name' => $manny->name]; } }
        if ($this->sectors !== null) { $object = $this->sectorChanges->getOrCreateSector(new SectorCoordinates($x, $y, $z))->findObjectById($targetId); if ($object instanceof Planet) { return ['kind' => 'planet', 'id' => $targetId]; } if ($object instanceof Asteroid) { return ['kind' => 'asteroid', 'id' => $targetId]; } }
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

    public function depotService(): GerminationDepotService
    {
        return $this->germinationDepots ?? throw new \RuntimeException('Germination depot service is required.');
    }

    public function mannyStorageTransferService(): MannyStorageTransferService
    {
        return $this->mannyStorageTransfers ?? throw new \RuntimeException('Manny storage service required.');
    }

    public function storageTransferService(): SectorStorageTransferService
    {
        return $this->storageTransfers ?? throw new \RuntimeException('Sector storage transfer service is required.');
    }

    private function settleStorageAction(array $action, string $now, string $reason): void
    {
        if ($action['type'] === 'build_germination_depot') { $this->depotService()->completeConstruction((int) $action['id'], $now, $reason); }
        else { $this->storageTransferService()->completeOthers((int) $action['id'], $now, $reason); }
    }

    private function interruptDepotConstructions(int $shipId, string $now, string $reason): void
    {
        $query = $this->persistence->action->findShipStorageActions([$shipId]);
        foreach ($query as $action) { $this->settleStorageAction($action, $now, $reason); }
    }

    private function turnDeployedAuxiliariesDormant(int $shipId, string $now): void
    {
        if ($this->sectors === null) { throw new \RuntimeException('Sector storage is unavailable for dormant auxiliaries.'); }
        $shipStmt = $this->persistence->actor->findShipById(['id' => $shipId]); $ship = $shipStmt;
        if (!$ship) { return; }
        $auxStmt = $this->persistence->destruction->findDeployedAuxiliaries(['ship_id' => $shipId]); $auxiliaries = $auxStmt;
        if ($auxiliaries === []) { return; }
        $sector = $this->sectorChanges->getOrCreateSector(new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']));
        $existing = array_fill_keys(array_map(static fn($object): string => $object->getId(), $sector->getObjects()), true);
        foreach ($auxiliaries as $auxiliary) { if (!isset($existing['dormant-others-auxiliary-' . $auxiliary['public_id']])) { $sector->addObject(DormantConstruct::fromOthersAuxiliary((string) $auxiliary['public_id'])); } }
        $this->sectorChanges->saveSector($sector);
        $this->persistence->destruction->detachDeployedActions(['ship_id' => $shipId]);
        $this->persistence->destruction->deleteDeployedParticipants(['ship_id' => $shipId]);
        $this->persistence->destruction->deleteDeployedAuxiliaries(['ship_id' => $shipId]);
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
