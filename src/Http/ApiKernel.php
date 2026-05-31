<?php

declare(strict_types=1);

namespace VonNeumannGame\Http;

use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeMovement;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Service\MannyActionException;
use VonNeumannGame\Service\MannyService;
use VonNeumannGame\Service\ObservationAccessException;
use VonNeumannGame\Service\ProbeMovementException;
use VonNeumannGame\Service\ProbeMovementService;
use VonNeumannGame\Service\SectorObservationService;
use VonNeumannGame\Sector\InvalidSectorCoordinatesException;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorGrid;

final class ApiKernel
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly NeumannProbeRepository $probes,
        private readonly SectorObservationService $observations,
        private readonly ProbeMovementService $movements,
        private readonly VisitedSectorRepository $visitedSectors,
        private readonly MannyService $mannies,
    ) {}

    public function handle(string $method, string $path, array $headers = [], ?string $body = null): ApiResponse
    {
        $uri = parse_url($path);
        $routePath = (string) ($uri['path'] ?? $path);
        $query = [];
        if (isset($uri['query'])) {
            parse_str((string) $uri['query'], $query);
        }

        try {
            if (preg_match('#^/api/probe/inventory/([^/]+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeInventoryItemResponse($player, rawurldecode($matches[1])));
            }
            if (preg_match('#^/api/probe/mannies/([^/]+)/(repair|mine|recall)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeMannyActionResponse($player, rawurldecode($matches[1]), $matches[2], $body));
            }
            if (preg_match('#^/api/probe/mannies/([^/]+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['PATCH'], $headers, fn(Player $player): ApiResponse => $this->probeMannyRenameResponse($player, rawurldecode($matches[1]), $body));
            }

            return match ($routePath) {
                '/api/session' => $this->routeSession($method, $body),
                '/api/me' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => new ApiResponse(200, ['player' => $player->publicArray()])),
                '/api/probe' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeResponse($player)),
                '/api/probe/sector' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeSectorResponse($player)),
                '/api/probe/move' => $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeMoveResponse($player, $body)),
                '/api/probe/mannies' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeManniesResponse($player)),
                '/api/sector' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->sectorResponse($player, $query)),
                default => ApiResponse::error(404, 'not_found', 'Endpoint not found'),
            };
        } catch (ProbeMovementException $e) {
            return ApiResponse::error($e->httpStatus, $e->errorCode, $e->getMessage());
        } catch (MannyActionException $e) {
            return ApiResponse::error($e->httpStatus, $e->errorCode, $e->getMessage());
        } catch (ObservationAccessException $e) {
            return ApiResponse::error($e->httpStatus, $e->errorCode, $e->getMessage());
        } catch (InvalidSectorCoordinatesException|\InvalidArgumentException $e) {
            return ApiResponse::error(400, 'bad_request', $e->getMessage());
        } catch (\Throwable) {
            return ApiResponse::error(500, 'internal_error', 'Internal server error');
        }
    }

    private function routeSession(string $method, ?string $body): ApiResponse
    {
        if ($method !== 'POST') {
            return ApiResponse::error(405, 'method_not_allowed', 'Method not allowed');
        }

        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['username'], $data['password'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain username and password');
        }

        $player = $this->auth->authenticateWithPassword((string) $data['username'], (string) $data['password']);
        if ($player === null) {
            return ApiResponse::error(401, 'unauthorized', 'Invalid credentials');
        }

        $session = $this->auth->createSessionForPlayer($player);

        return new ApiResponse(200, [
            'token' => $session['token'],
            'expiresAt' => $session['expiresAt'],
            'player' => $player->publicArray(),
        ]);
    }

    private function protectedRoute(string $method, array $allowedMethods, array $headers, callable $handler): ApiResponse
    {
        if (!in_array($method, $allowedMethods, true)) {
            return ApiResponse::error(405, 'method_not_allowed', 'Method not allowed');
        }

        $player = $this->auth->getPlayerFromBearerToken($this->authorizationHeader($headers));
        if ($player === null) {
            return ApiResponse::error(401, 'unauthorized', 'Missing or invalid bearer token');
        }

        return $handler($player);
    }

    private function probeResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        if ($probe->status === ProbeStatus::TrappedByBlackHole) {
            return new ApiResponse(200, [
                'probe' => [
                    'id' => $probe->id,
                    'name' => $probe->name,
                    'status' => 'trapped_by_black_hole',
                    'message' => 'The probe has crossed a black hole escape threshold. No signal or actuator response can be recovered.',
                    'fuel' => ['deuterium' => $probe->deuteriumStock],
                    'sensorMode' => 'blind',
                    'systems' => [
                        'integrityPercent' => $probe->integrityPercent,
                        'damagePercent' => $probe->damagePercent,
                    ],
                ],
            ]);
        }
        if ($probe->status === ProbeStatus::Dead) {
            return new ApiResponse(200, [
                'probe' => [
                    'id' => $probe->id,
                    'name' => $probe->name,
                    'status' => 'dead',
                    'message' => 'The probe is no longer operational. Its intelligence core is isolated from all sensors and actuators.',
                    'fuel' => ['deuterium' => $probe->deuteriumStock],
                    'sensorMode' => 'blind',
                    'systems' => [
                        'integrityPercent' => $probe->integrityPercent,
                        'damagePercent' => $probe->damagePercent,
                    ],
                ],
            ]);
        }

        $relative = PlayerReferenceFrame::atGlobalCoordinates(
            $player->homeSector->getX(),
            $player->homeSector->getY(),
            $player->homeSector->getZ(),
        )->globalToRelative($probe->currentSector);

        return new ApiResponse(200, ['probe' => $this->probeArray($player, $probe, $relative)]);
    }

    private function probeSectorResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $movement = $this->movements->activeMovementForProbe($probe);
        $sensorMode = $this->movements->sensorModeFor($movement, $probe->status);
        if ($sensorMode === 'blind') {
            return ApiResponse::error(400, 'sensors_unavailable', 'External sensors are unavailable at current relativistic velocity.');
        }

        $observableSector = $this->movements->observableSectorFor($probe, $movement) ?? $probe->currentSector;
        if ($movement === null && $observableSector->equals($probe->currentSector)) {
            $this->movements->refreshCurrentSectorHazards($probe);
        }
        if ($sensorMode === 'degraded') {
            $frame = new PlayerReferenceFrame($player->homeSector);

            return new ApiResponse(200, [
                'sector' => [
                    'relativeCoordinates' => $frame->globalToRelative($observableSector),
                    'distance' => 0,
                    'knowledgeLevel' => 'long_range_estimation',
                    'confidence' => 0.2,
                    'sensorMode' => 'degraded',
                    'dataFreshness' => 'degraded_live',
                    'message' => 'Sensors are degraded during intersector maneuvering.',
                    'scan' => [
                        'currentSectorResidenceSeconds' => 0,
                        'requiredResidenceSeconds' => 0,
                        'scanQuality' => 0.2,
                    ],
                ],
                'inventory' => $this->inventoryForProbe($probe)->toArray(),
            ]);
        }

        $observation = $this->observations->observe($player, $probe, $observableSector)->toArray();
        $observation['sensorMode'] = $sensorMode;
        $observation['dataFreshness'] = 'live';
        $observation = $this->withBlackHoleTrapCountdown($observation, $probe);

        return new ApiResponse(200, [
            'sector' => $observation,
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    private function probeInventoryItemResponse(Player $player, string $itemId): ApiResponse
    {
        $probe = $this->requiredProbe($player);
        $this->movements->ensureProbeOperational($probe);
        $item = $this->inventoryForProbe($probe)->findItem($itemId);

        if ($item === null) {
            return ApiResponse::error(404, 'not_found', 'Inventory item not found.');
        }

        return new ApiResponse(200, ['item' => $item->taskArray()]);
    }

    private function sectorResponse(Player $player, array $query): ApiResponse
    {
        foreach (['x', 'y', 'z'] as $field) {
            if (!isset($query[$field]) || !is_numeric($query[$field]) || (string) (int) $query[$field] !== (string) $query[$field]) {
                return ApiResponse::error(400, 'bad_request', 'Query parameters x, y and z must be integer relative coordinates.');
            }
        }
        if (!$this->validRelativeCoordinateParity((int) $query['x'], (int) $query['y'], (int) $query['z'])) {
            return $this->invalidRelativeCoordinateResponse();
        }

        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $target = $this->observations->relativeToAbsolute($player, (int) $query['x'], (int) $query['y'], (int) $query['z']);
        $movement = $this->movements->activeMovementForProbe($probe);
        $sensorMode = $this->movements->sensorModeFor($movement, $probe->status);
        if ($movement === null && $target->equals($probe->currentSector)) {
            $this->movements->refreshCurrentSectorHazards($probe);
        }

        if ($sensorMode === 'blind' && !$this->visitedSectors->hasVisited($player, $target)) {
            return ApiResponse::error(400, 'sensors_unavailable', 'External sensors are unavailable at current relativistic velocity.');
        }
        if ($sensorMode === 'degraded' && !$this->visitedSectors->hasVisited($player, $target) && !$target->equals($probe->currentSector)) {
            $observable = $this->movements->observableSectorFor($probe, $movement) ?? $probe->currentSector;
            $frame = new PlayerReferenceFrame($player->homeSector);

            return new ApiResponse(200, [
                'sector' => [
                    'relativeCoordinates' => $frame->globalToRelative($target),
                    'distance' => (new SectorGrid())->getDistance($observable, $target),
                    'knowledgeLevel' => 'long_range_estimation',
                    'confidence' => 0.12,
                    'sensorMode' => 'degraded',
                    'dataFreshness' => 'degraded_live',
                    'message' => 'Sensors are degraded during intersector maneuvering.',
                    'scan' => [
                        'currentSectorResidenceSeconds' => 0,
                        'requiredResidenceSeconds' => 0,
                        'scanQuality' => 0.12,
                    ],
                ],
            ]);
        }

        $observation = $this->observations->observe($player, $probe, $target)->toArray();
        $observation['sensorMode'] = $sensorMode;
        $observation['dataFreshness'] = $sensorMode === 'blind' ? 'historical' : ($sensorMode === 'degraded' ? 'degraded_live' : 'live');
        if ($movement === null && $target->equals($probe->currentSector)) {
            $observation = $this->withBlackHoleTrapCountdown($observation, $probe);
        }

        return new ApiResponse(200, ['sector' => $observation]);
    }

    private function probeMoveResponse(Player $player, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['target']) || !is_array($data['target'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain target coordinates.');
        }
        foreach (['x', 'y', 'z'] as $field) {
            if (!isset($data['target'][$field]) || !is_int($data['target'][$field])) {
                return ApiResponse::error(400, 'bad_request', 'Target coordinates x, y and z must be integers.');
            }
        }
        if (!$this->validRelativeCoordinateParity($data['target']['x'], $data['target']['y'], $data['target']['z'])) {
            return $this->invalidRelativeCoordinateResponse();
        }

        $target = $this->observations->relativeToAbsolute($player, $data['target']['x'], $data['target']['y'], $data['target']['z']);
        $movement = $this->movements->startMovement($probe, $target);

        return new ApiResponse(202, ['movement' => $this->movementArray($player, $movement)]);
    }

    private function probeManniesResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $mannies = $this->mannies->manniesForProbe($probe);

        return new ApiResponse(200, [
            'mannies' => array_map(fn($manny): array => $this->mannyArray($player, $probe, $manny), $mannies),
        ]);
    }

    private function probeMannyRenameResponse(Player $player, string $uid, ?string $body): ApiResponse
    {
        $probe = $this->requiredProbe($player);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a Manny name.');
        }

        $manny = $this->mannies->renameManny($probe, $uid, $data['name']);

        return new ApiResponse(200, ['manny' => $this->mannyArray($player, $probe, $manny)]);
    }

    private function probeMannyActionResponse(Player $player, string $uid, string $action, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $data = $this->decodeJsonBody($body) ?? [];

        if ($action === 'repair') {
            if (!isset($data['percent']) || !is_numeric($data['percent'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain repair percent.');
            }

            $manny = $this->mannies->startRepair($probe, $uid, (float) $data['percent']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'mine') {
            if (!isset($data['objectId'], $data['resource'], $data['targetAmount']) || !is_string($data['objectId']) || !is_string($data['resource']) || !is_numeric($data['targetAmount'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId, resource and targetAmount.');
            }

            $manny = $this->mannies->startMining($probe, $uid, $data['objectId'], $data['resource'], (float) $data['targetAmount']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        $manny = $this->mannies->recallManny($probe, $uid);

        return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
    }

    private function requiredProbe(Player $player): NeumannProbe
    {
        return $this->probes->findByPlayerId($player->id) ?? throw new \RuntimeException('Probe not found.');
    }

    private function probeArray(Player $player, NeumannProbe $probe, array $relative): array
    {
        $movement = $this->movements->activeMovementForProbe($probe);
        $latest = $movement ?? $this->movements->latestMovementForProbe($probe);
        $sensorMode = $this->movements->sensorModeFor($movement, $probe->status);

        return [
            'id' => $probe->id,
            'name' => $probe->name,
            'status' => $probe->status->value,
            'fuel' => ['deuterium' => $probe->deuteriumStock],
            'sensorMode' => $sensorMode,
            'sector' => $movement === null ? ['relative' => $relative] : null,
            'navigation' => [
                'velocityC' => $probe->velocityC,
                'accelerationCPerDay' => $probe->accelerationCPerDay,
                'direction' => $probe->direction->toArray(),
            ],
            'movement' => $latest !== null ? $this->movementArray($player, $latest, $movement !== null) : null,
            'systems' => [
                'integrityPercent' => $probe->integrityPercent,
                'damagePercent' => $probe->damagePercent,
                'energyStored' => $probe->energyStored,
                'internalClockRate' => $probe->internalClockRate,
                'currentTask' => $probe->currentTask,
            ],
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ];
    }

    private function inventoryForProbe(NeumannProbe $probe): ProbeInventory
    {
        return ProbeInventory::defaultForProbe($probe, $this->mannies->manniesForProbe($probe));
    }

    private function mannyArray(Player $player, NeumannProbe $probe, Manny $manny): array
    {
        $relativeSector = $manny->sector === null
            ? null
            : (new PlayerReferenceFrame($player->homeSector))->globalToRelative($manny->sector);

        return $this->mannies->publicArray($probe, $manny, $relativeSector);
    }

    private function movementArray(Player $player, ProbeMovement $movement, bool $includeLive = true): array
    {
        $frame = new PlayerReferenceFrame($player->homeSector);

        return [
            'status' => $movement->status,
            'origin' => $frame->globalToRelative($movement->origin),
            'target' => $frame->globalToRelative($movement->target),
            'distance' => $movement->distance,
            'fuelCostDeuterium' => $movement->fuelCostDeuterium,
            'startedAt' => $movement->startedAt,
            'arrivalAt' => $movement->arrivalAt,
        ] + ($includeLive ? [
            'phase' => $this->movements->phaseFor($movement),
            'secondsRemaining' => $this->movements->secondsRemaining($movement),
            'sensorMode' => $this->movements->sensorModeFor($movement, ProbeStatus::from($movement->status === 'destroyed' ? 'dead' : ($movement->status === 'arrived' ? 'idle' : $movement->status))),
            'estimatedVelocityC' => $this->movements->estimatedVelocityC($movement),
        ] : []);
    }

    private function withBlackHoleTrapCountdown(array $observation, NeumannProbe $probe): array
    {
        $trap = $this->movements->pendingBlackHoleTrapForProbe($probe);
        if ($trap === null || !isset($observation['objects']) || !is_array($observation['objects'])) {
            return $observation;
        }

        foreach ($observation['objects'] as &$object) {
            if (($object['type'] ?? null) === 'black_hole') {
                $object['noReturnCountdown'] = $trap;
            }
        }
        unset($object);

        return $observation;
    }

    private function decodeJsonBody(?string $body): ?array
    {
        try {
            $decoded = json_decode($body ?? '', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function validRelativeCoordinateParity(int $x, int $y, int $z): bool
    {
        return ($x + $y + $z) % 2 === 0;
    }

    private function invalidRelativeCoordinateResponse(): ApiResponse
    {
        return ApiResponse::error(400, 'bad_request', 'Relative coordinates are invalid: x + y + z must be even.');
    }

    private function authorizationHeader(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        return null;
    }
}
