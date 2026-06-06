<?php

declare(strict_types=1);

namespace VonNeumannGame\Http;

use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeMessage;
use VonNeumannGame\Domain\ProbeMovement;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Domain\VisitedSector;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\ProbeMessageRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Service\MannyActionException;
use VonNeumannGame\Service\MannyService;
use VonNeumannGame\Service\ObservationAccessException;
use VonNeumannGame\Service\ProbeMovementException;
use VonNeumannGame\Service\ProbeMovementService;
use VonNeumannGame\Service\ProbeStorageService;
use VonNeumannGame\Service\SectorObservationService;
use VonNeumannGame\Sector\InvalidSectorCoordinatesException;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;

final class ApiKernel
{
    /** Bump when the public API contract changes. */
    public const API_VERSION = 19;

    public function __construct(
        private readonly AuthService $auth,
        private readonly NeumannProbeRepository $probes,
        private readonly SectorObservationService $observations,
        private readonly ProbeMovementService $movements,
        private readonly VisitedSectorRepository $visitedSectors,
        private readonly MannyService $mannies,
        private readonly ProbeItemRepository $items,
        private readonly ProbeStorageService $storage,
        private readonly ProbeMessageRepository $messages,
        private readonly array $gameplayConfig = [],
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
            if (preg_match('#^/api/probe/inventory/([^/]+)/jettison$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeInventoryJettisonResponse($player, rawurldecode($matches[1]), $body));
            }
            if (preg_match('#^/api/probe/inventory/([^/]+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeInventoryItemResponse($player, rawurldecode($matches[1])));
            }
            if (preg_match('#^/api/probe/storage-containers/([^/]+)/rules$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['PATCH'], $headers, fn(Player $player): ApiResponse => $this->probeStorageContainerRulesResponse($player, rawurldecode($matches[1]), $body));
            }
            if (preg_match('#^/api/probe/storage-containers/([^/]+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeStorageContainerResponse($player, rawurldecode($matches[1])));
            }
            if (preg_match('#^/api/probe/mannies/([^/]+)/(repair|mine|craft|salvage|install-bookmark|recall)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeMannyActionResponse($player, rawurldecode($matches[1]), $matches[2], $body));
            }
            if (preg_match('#^/api/probe/mannies/([^/]+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['PATCH'], $headers, fn(Player $player): ApiResponse => $this->probeMannyRenameResponse($player, rawurldecode($matches[1]), $body));
            }
            if (preg_match('#^/api/probe/messages/(\d+)/read$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['PATCH'], $headers, fn(Player $player): ApiResponse => $this->probeMessageReadResponse($player, (int) $matches[1]));
            }

            return match ($routePath) {
                '/api/version' => $this->routeApiVersion($method),
                '/api/session' => $this->routeSession($method, $body),
                '/api/me' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => new ApiResponse(200, ['player' => $player->publicArray()])),
                '/api/me/api-key' => $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->apiKeyResponse($player)),
                '/api/crafting-recipes' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $_player): ApiResponse => $this->craftingRecipesResponse()),
                '/api/probe' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeResponse($player)),
                '/api/probe/storage-containers' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeStorageContainersResponse($player)),
                '/api/probe/storage-moves' => $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeStorageMoveResponse($player, $body)),
                '/api/probe/messages/sent' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeSentMessagesResponse($player, $query)),
                '/api/probe/messages' => $this->protectedRoute($method, ['GET', 'POST'], $headers, fn(Player $player): ApiResponse => $method === 'POST' ? $this->probeMessageSendResponse($player, $body) : $this->probeMessagesResponse($player, $query)),
                '/api/probe/visited-sectors' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeVisitedSectorsResponse($player)),
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

    private function routeApiVersion(string $method): ApiResponse
    {
        if ($method !== 'GET') {
            return ApiResponse::error(405, 'method_not_allowed', 'Method not allowed');
        }

        return new ApiResponse(200, ['apiVersion' => self::API_VERSION]);
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

    private function apiKeyResponse(Player $player): ApiResponse
    {
        return new ApiResponse(201, ['apiKey' => $this->auth->createApiKeyForPlayer($player)]);
    }

    private function craftingRecipesResponse(): ApiResponse
    {
        return new ApiResponse(200, ['recipes' => CraftingRecipeCatalog::all($this->gameplayConfig['crafting'] ?? [])]);
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

    private function probeVisitedSectorsResponse(Player $player): ApiResponse
    {
        $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $frame = new PlayerReferenceFrame($player->homeSector);

        return new ApiResponse(200, [
            'visitedSectors' => array_map(
                fn(VisitedSector $sector): array => [
                    'relativeCoordinates' => $frame->globalToRelative($sector->coordinates),
                    'firstVisitedAt' => $sector->firstVisitedAt,
                    'lastVisitedAt' => $sector->lastVisitedAt,
                    'visitCount' => $sector->visitCount,
                ],
                $this->visitedSectors->listVisited($player),
            ),
        ]);
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
            $observation = [
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
            ];

            return new ApiResponse(200, [
                'sector' => $this->withObservedProbePresence($observation, $probe, $observableSector),
                'inventory' => $this->inventoryForProbe($probe)->toArray(),
            ]);
        }

        $observation = $this->observations->observe($player, $probe, $observableSector)->toArray();
        $observation['sensorMode'] = $sensorMode;
        $observation['dataFreshness'] = 'live';
        $observation = $this->withBlackHoleTrapCountdown($observation, $probe);
        $observation = $this->withObservedProbePresence($observation, $probe, $observableSector);

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

    private function probeStorageContainersResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);

        return new ApiResponse(200, ['containers' => $this->storage->containersForProbe($probe)]);
    }

    private function probeStorageContainerResponse(Player $player, string $containerId): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);

        return new ApiResponse(200, $this->storage->containerInventory($probe, $containerId));
    }

    private function probeStorageContainerRulesResponse(Player $player, string $containerId, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain storage rules.');
        }
        foreach (['priority', 'exclusion', 'strictExclusion'] as $field) {
            if (isset($data[$field]) && !is_array($data[$field])) {
                return ApiResponse::error(400, 'bad_request', 'Storage rule filters must be arrays.');
            }
        }

        return new ApiResponse(200, [
            'container' => $this->storage->updateContainerRules(
                $probe,
                $containerId,
                $data['priority'] ?? [],
                $data['exclusion'] ?? [],
                $data['strictExclusion'] ?? [],
            ),
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    private function probeStorageMoveResponse(Player $player, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a storage move order.');
        }
        $mannyId = $data['actorMannyId'] ?? $data['mannyId'] ?? null;
        if (!is_string($mannyId) || $mannyId === '') {
            return ApiResponse::error(400, 'bad_request', 'Storage move order requires actorMannyId.');
        }

        $manny = $this->mannies->startStorageMove($probe, $mannyId, $data);

        return new ApiResponse(202, [
            'manny' => $this->mannyArray($player, $probe, $manny),
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    private function probeMessageSendResponse(Player $player, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a probe message.');
        }

        $recipientProbeId = $this->messageRecipientProbeId($data['recipientProbeId'] ?? null);
        if ($recipientProbeId === null) {
            return ApiResponse::error(400, 'bad_request', 'Message requires recipientProbeId.');
        }
        if ($recipientProbeId === $probe->id) {
            return ApiResponse::error(422, 'invalid_message_recipient', 'A probe cannot send a message to itself.');
        }

        $messageBody = isset($data['body']) && is_string($data['body']) ? trim($data['body']) : '';
        if ($messageBody === '' || strlen($messageBody) > 2000) {
            return ApiResponse::error(400, 'bad_request', 'Message body must contain 1 to 2000 characters.');
        }

        $recipient = $this->probes->findById($recipientProbeId);
        if ($recipient === null) {
            return ApiResponse::error(404, 'not_found', 'Recipient probe not found.');
        }
        $recipient = $this->movements->refreshProbeMovementState($recipient);
        if (!$recipient->currentSector->equals($probe->currentSector)) {
            return ApiResponse::error(422, 'probe_not_in_same_sector', 'Recipient probe must be in the same sector.');
        }

        $message = $this->messages->create($probe->id, $recipient->id, $probe->currentSector, $messageBody);

        return new ApiResponse(201, ['message' => $this->probeMessageArray($player, $message)]);
    }

    private function probeMessagesResponse(Player $player, array $query): ApiResponse
    {
        return $this->probeMessageListResponse($player, $query, false);
    }

    private function probeSentMessagesResponse(Player $player, array $query): ApiResponse
    {
        return $this->probeMessageListResponse($player, $query, true);
    }

    private function probeMessageListResponse(Player $player, array $query, bool $sent): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $limit = $this->messagePaginationParameter($query, 'limit', 50, 1, 200);
        if ($limit instanceof ApiResponse) {
            return $limit;
        }
        $offset = $this->messagePaginationParameter($query, 'offset', 0, 0);
        if ($offset instanceof ApiResponse) {
            return $offset;
        }

        $messages = $sent
            ? $this->messages->sentByProbe($probe->id, $limit, $offset)
            : $this->messages->receivedByProbe($probe->id, $limit, $offset);
        $total = $sent
            ? $this->messages->countSentByProbe($probe->id)
            : $this->messages->countReceivedByProbe($probe->id);

        return new ApiResponse(200, [
            'messages' => array_map(
                fn(ProbeMessage $message): array => $this->probeMessageArray($player, $message, includeReadState: !$sent),
                $messages,
            ),
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($messages),
                'total' => $total,
                'hasMore' => $offset + count($messages) < $total,
            ],
        ]);
    }

    private function probeMessageReadResponse(Player $player, int $messageId): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $message = $this->messages->findById($messageId);
        if ($message === null || $message->recipientProbeId !== $probe->id) {
            return ApiResponse::error(404, 'not_found', 'Message not found.');
        }

        return new ApiResponse(200, ['message' => $this->probeMessageArray($player, $this->messages->markRead($message))]);
    }

    private function messageRecipientProbeId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function messagePaginationParameter(array $query, string $name, int $default, int $min, ?int $max = null): int|ApiResponse
    {
        if (!array_key_exists($name, $query)) {
            return $default;
        }

        $value = $query[$name];
        if (is_array($value) || !is_string($value) || !ctype_digit($value)) {
            return ApiResponse::error(400, 'bad_request', $this->messagePaginationError($name, $min, $max));
        }

        $integer = (int) $value;
        if ($integer < $min || ($max !== null && $integer > $max)) {
            return ApiResponse::error(400, 'bad_request', $this->messagePaginationError($name, $min, $max));
        }

        return $integer;
    }

    private function messagePaginationError(string $name, int $min, ?int $max): string
    {
        if ($max === null) {
            return sprintf('Query parameter %s must be an integer greater than or equal to %d.', $name, $min);
        }

        return sprintf('Query parameter %s must be an integer between %d and %d.', $name, $min, $max);
    }

    private function probeInventoryJettisonResponse(Player $player, string $itemId, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body) ?? [];
        if (isset($data['amount']) && !is_numeric($data['amount'])) {
            return ApiResponse::error(400, 'bad_request', 'Jettison amount must be numeric.');
        }

        $amount = isset($data['amount']) ? round((float) $data['amount'], 4) : null;
        if ($amount !== null && $amount <= 0.0) {
            return ApiResponse::error(400, 'bad_request', 'Jettison amount must be greater than zero.');
        }
        $containerId = isset($data['containerId']) && is_string($data['containerId']) && $data['containerId'] !== ''
            ? $data['containerId']
            : null;

        $inventory = $this->inventoryForProbe($probe);
        $item = $inventory->findItem($itemId);
        if ($item !== null) {
            if ($item->type === 'atomic_3d_printer') {
                return ApiResponse::error(422, 'item_not_jettisonable', 'This inventory item cannot be jettisoned.');
            }

            if ($item->type === 'manny') {
                $manny = $this->mannies->jettisonMannyFromProbe($probe, $itemId);
                $this->mannies->manniesForProbe($probe);
                $probe = $this->requiredProbe($player);

                return new ApiResponse(200, [
                    'inventory' => $this->inventoryForProbe($probe)->toArray(),
                    'manny' => $this->mannyArray($player, $probe, $manny),
                ]);
            }

            $jettisoned = $this->mannies->jettisonProbeItemFromProbe($probe, $itemId);
            $this->mannies->manniesForProbe($probe);
            $probe = $this->requiredProbe($player);
            return new ApiResponse(200, [
                'inventory' => $this->inventoryForProbe($probe)->toArray(),
                'jettisoned' => $jettisoned,
            ]);
        }

        $resourceType = $this->inventoryResourceType($probe, $itemId);
        if ($resourceType !== null) {
            $available = $containerId !== null
                ? $this->storage->resourceStockInContainer($probe, $resourceType, $containerId)
                : $this->storage->resourceStock($probe, $resourceType);
            $discarded = $this->jettisonAmount($amount, $available);
            if ($discarded instanceof ApiResponse) {
                return $discarded;
            }

            if ($containerId !== null) {
                $this->storage->consumeResourceFromContainer($probe, $resourceType, $discarded, $containerId);
            } else {
                $this->storage->consumeResource($probe, $resourceType, $discarded);
            }
            $this->mannies->manniesForProbe($probe);
            $probe = $this->requiredProbe($player);

            return new ApiResponse(200, [
                'inventory' => $this->inventoryForProbe($probe)->toArray(),
                'jettisoned' => ['type' => $resourceType, 'amount' => $discarded],
            ]);
        }

        if ($itemId === 'probe-' . $probe->id . '-deuterium-tank' || $itemId === 'deuterium') {
            return ApiResponse::error(422, 'item_not_jettisonable', 'The external deuterium tank cannot be jettisoned.');
        }

        return ApiResponse::error(404, 'not_found', 'Inventory item not found.');
    }

    private function inventoryResourceType(NeumannProbe $probe, string $itemId): ?string
    {
        return match ($itemId) {
            'metals', 'probe-' . $probe->id . '-stock-metals' => 'metals',
            'ice', 'probe-' . $probe->id . '-stock-ice' => 'ice',
            'carbon_compounds', 'organic_compounds', 'organicCompounds', 'probe-' . $probe->id . '-stock-organic-compounds' => 'carbon_compounds',
            default => null,
        };
    }

    private function jettisonAmount(?float $requested, float $available): float|ApiResponse
    {
        $available = round(max(0.0, $available), 4);
        $amount = $requested === null ? $available : round($requested, 4);
        if ($amount <= 0.0 || $available <= 0.0) {
            return ApiResponse::error(422, 'nothing_to_jettison', 'There is nothing to jettison for this inventory entry.');
        }
        if ($amount > $available + 0.00001) {
            return ApiResponse::error(422, 'insufficient_inventory_amount', 'The requested jettison amount is not available.');
        }

        return min($amount, $available);
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
            $repairPercent = $data['integrityPercent'] ?? $data['percent'] ?? null;
            if (!is_numeric($repairPercent)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain repair percent.');
            }

            $manny = $this->mannies->startRepair($probe, $uid, (float) $repairPercent);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'mine') {
            if (!isset($data['objectId'], $data['targetAmount']) || !is_string($data['objectId']) || !is_numeric($data['targetAmount'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId, resources and targetAmount.');
            }

            $resources = $data['resources'] ?? $data['resource'] ?? null;
            if (is_array($resources)) {
                foreach ($resources as $resource) {
                    if (!is_string($resource)) {
                        return ApiResponse::error(400, 'bad_request', 'Mining resources must be strings.');
                    }
                }
            } elseif (!is_string($resources)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain resources or resource.');
            }

            $manny = $this->mannies->startMining($probe, $uid, $data['objectId'], $resources, (float) $data['targetAmount']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'craft') {
            if (!isset($data['recipe']) || !is_string($data['recipe'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain recipe.');
            }

            $manny = $this->mannies->startCrafting($probe, $uid, $data['recipe']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'salvage') {
            if (!isset($data['objectId']) || !is_string($data['objectId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId.');
            }

            $manny = $this->mannies->startSalvage($probe, $uid, $data['objectId']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'install-bookmark') {
            $this->movements->ensureProbeOperational($probe);
            if ($this->movements->activeMovementForProbe($probe) !== null) {
                return ApiResponse::error(409, 'probe_already_moving', 'The probe is already moving between sectors.');
            }
            if (!isset($data['objectId'], $data['name']) || !is_string($data['objectId']) || !is_string($data['name'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId and name.');
            }

            $manny = $this->mannies->startWaypointBookmarkInstallation($probe, $player, $uid, $data['objectId'], $data['name']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        $manny = $this->mannies->recallManny($probe, $uid);

        return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
    }

    private function requiredProbe(Player $player): NeumannProbe
    {
        return $this->probes->findByPlayerId($player->id) ?? throw new \RuntimeException('Probe not found.');
    }

    private function withObservedProbePresence(array $observation, NeumannProbe $probe, SectorCoordinates $observableSector): array
    {
        if (!$observableSector->equals($probe->currentSector)) {
            return $observation;
        }

        $observedProbes = $this->observedProbePresence($probe, $observableSector);
        if ($observedProbes !== []) {
            $observation['probes'] = $observedProbes;
        }

        return $observation;
    }

    /**
     * @return array<array{id:int, name:string, moving:bool}>
     */
    private function observedProbePresence(NeumannProbe $probe, SectorCoordinates $sector): array
    {
        $observed = [];
        foreach ($this->probes->findBySector($sector, $probe->id) as $otherProbe) {
            $otherProbe = $this->movements->refreshProbeMovementState($otherProbe);
            if (!$otherProbe->currentSector->equals($sector)) {
                continue;
            }

            $observed[] = [
                'id' => $otherProbe->id,
                'name' => $otherProbe->name,
                'moving' => $this->movements->activeMovementForProbe($otherProbe) !== null,
            ];
        }

        return $observed;
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
                'energyStored' => $probe->energyStored,
                'internalClockRate' => $probe->internalClockRate,
                'currentTask' => $probe->currentTask,
            ],
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ];
    }

    private function inventoryForProbe(NeumannProbe $probe): ProbeInventory
    {
        return $this->storage->inventoryForProbe(
            $probe,
            $this->mannies->manniesForProbe($probe),
            $this->items->findByProbeId($probe->id),
        );
    }

    private function mannyArray(Player $player, NeumannProbe $probe, Manny $manny): array
    {
        $relativeSector = $manny->sector === null
            ? null
            : (new PlayerReferenceFrame($player->homeSector))->globalToRelative($manny->sector);

        return $this->mannies->publicArray($probe, $manny, $relativeSector);
    }

    private function probeMessageArray(Player $player, ProbeMessage $message, bool $includeReadState = true): array
    {
        $sender = $this->probes->findById($message->senderProbeId);
        $recipient = $this->probes->findById($message->recipientProbeId);

        $payload = [
            'id' => $message->id,
            'sender' => [
                'probeId' => $message->senderProbeId,
                'name' => $sender?->name ?? 'Probe #' . $message->senderProbeId,
            ],
            'recipient' => [
                'probeId' => $message->recipientProbeId,
                'name' => $recipient?->name ?? 'Probe #' . $message->recipientProbeId,
            ],
            'sector' => [
                'relative' => (new PlayerReferenceFrame($player->homeSector))->globalToRelative($message->sector),
            ],
            'body' => $message->body,
            'createdAt' => $message->createdAt,
        ];

        if ($includeReadState) {
            $payload['status'] = $message->status;
            $payload['readAt'] = $message->readAt;
            $payload['updatedAt'] = $message->updatedAt;
        }

        return $payload;
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
