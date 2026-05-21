<?php

declare(strict_types=1);

namespace VonNeumannGame\Http;

use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\BlackHole;
use VonNeumannGame\Sector\DustCloud;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\SolarSystem;
use VonNeumannGame\Sector\Star;
use VonNeumannGame\Sector\UniverseObject;

final class ApiKernel
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly NeumannProbeRepository $probes,
        private readonly SectorService $sectors,
    ) {}

    public function handle(string $method, string $path, array $headers = [], ?string $body = null): ApiResponse
    {
        try {
            return match ($path) {
                '/api/session' => $this->routeSession($method, $body),
                '/api/me' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => new ApiResponse(200, ['player' => $player->publicArray()])),
                '/api/probe' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeResponse($player)),
                '/api/probe/sector' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeSectorResponse($player)),
                default => ApiResponse::error(404, 'not_found', 'Endpoint not found'),
            };
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
        $content = $this->sectors->getOrCreateSector($probe->currentSector);
        $relative = PlayerReferenceFrame::atGlobalCoordinates(
            $player->homeSector->getX(),
            $player->homeSector->getY(),
            $player->homeSector->getZ(),
        )->globalToRelative($probe->currentSector);

        return new ApiResponse(200, [
            'sector' => [
                'coordinates' => ['relative' => $relative],
                'objects' => array_map(fn(UniverseObject $object): array => $this->objectSummary($object), $content->getObjects()),
            ],
        ]);
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
        ];
    }

    private function objectSummary(UniverseObject $object): array
    {
        $summary = match (true) {
            $object instanceof SolarSystem => sprintf(
                'Stellar system with %d star(s) and %d orbital body(ies).',
                count($object->getStars()),
                count($object->getOrbitalBodies()),
            ),
            $object instanceof Star => 'Isolated star or stellar remnant.',
            $object instanceof Planet => 'Planetary body detected.',
            $object instanceof Asteroid => 'Wandering asteroid body.',
            $object instanceof DustCloud => 'Diffuse dust cloud with sensor interference.',
            $object instanceof BlackHole => 'Dangerous compact object detected.',
            default => 'Unknown astronomical object.',
        };

        return [
            'type' => $object->getType()->value,
            'name' => $object->getName(),
            'summary' => $summary,
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
