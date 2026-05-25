<?php

declare(strict_types=1);

namespace VonNeumannGame\Http;

use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Service\ObservationAccessException;
use VonNeumannGame\Service\SectorObservationService;
use VonNeumannGame\Sector\InvalidSectorCoordinatesException;
use VonNeumannGame\Sector\PlayerReferenceFrame;

final class ApiKernel
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly NeumannProbeRepository $probes,
        private readonly SectorObservationService $observations,
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

            return match ($routePath) {
                '/api/session' => $this->routeSession($method, $body),
                '/api/me' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => new ApiResponse(200, ['player' => $player->publicArray()])),
                '/api/probe' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeResponse($player)),
                '/api/probe/sector' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeSectorResponse($player)),
                '/api/sector' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->sectorResponse($player, $query)),
                default => ApiResponse::error(404, 'not_found', 'Endpoint not found'),
            };
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
        $probe = $this->requiredProbe($player);
        $relative = PlayerReferenceFrame::atGlobalCoordinates(
            $player->homeSector->getX(),
            $player->homeSector->getY(),
            $player->homeSector->getZ(),
        )->globalToRelative($probe->currentSector);

        return new ApiResponse(200, ['probe' => $this->probeArray($probe, $relative)]);
    }

    private function probeSectorResponse(Player $player): ApiResponse
    {
        $probe = $this->requiredProbe($player);
        $observation = $this->observations->observe($player, $probe, $probe->currentSector);

        return new ApiResponse(200, [
            'sector' => $observation->toArray(),
            'inventory' => ProbeInventory::defaultForProbe($probe)->toArray(),
        ]);
    }

    private function probeInventoryItemResponse(Player $player, string $itemId): ApiResponse
    {
        $probe = $this->requiredProbe($player);
        $item = ProbeInventory::defaultForProbe($probe)->findItem($itemId);

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

        $probe = $this->requiredProbe($player);
        $target = $this->observations->relativeToAbsolute($player, (int) $query['x'], (int) $query['y'], (int) $query['z']);
        $observation = $this->observations->observe($player, $probe, $target);

        return new ApiResponse(200, ['sector' => $observation->toArray()]);
    }

    private function requiredProbe(Player $player): NeumannProbe
    {
        return $this->probes->findByPlayerId($player->id) ?? throw new \RuntimeException('Probe not found.');
    }

    private function probeArray(NeumannProbe $probe, array $relative): array
    {
        return [
            'id' => $probe->id,
            'name' => $probe->name,
            'status' => $probe->status->value,
            'sector' => ['relative' => $relative],
            'movement' => [
                'velocityC' => $probe->velocityC,
                'accelerationCPerDay' => $probe->accelerationCPerDay,
                'direction' => $probe->direction->toArray(),
            ],
            'systems' => [
                'integrityPercent' => $probe->integrityPercent,
                'energyStored' => $probe->energyStored,
                'internalClockRate' => $probe->internalClockRate,
                'currentTask' => $probe->currentTask,
            ],
            'inventory' => ProbeInventory::defaultForProbe($probe)->toArray(),
        ];
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
