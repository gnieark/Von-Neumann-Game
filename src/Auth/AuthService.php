<?php

declare(strict_types=1);

namespace VonNeumannGame\Auth;

use VonNeumannGame\Domain\AuthProvider;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Repository\ApiKeyRepository;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerAuthRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\SessionRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Sector\SectorCoordinates;

final class AuthService
{
    private const PSEUDONYM_PATTERN = '/\A[\p{L}\p{N}][\p{L}\p{N} ._-]{1,38}[\p{L}\p{N}]\z/u';

    public function __construct(
        private readonly PlayerRepository $players,
        private readonly PlayerAuthRepository $authMethods,
        private readonly NeumannProbeRepository $probes,
        private readonly SessionRepository $sessions,
        private readonly VisitedSectorRepository $visitedSectors,
        private readonly int $sessionTtlDays = 7,
        private readonly ?MannyRepository $mannies = null,
        private readonly ?ApiKeyRepository $apiKeys = null,
    ) {}

    public function registerPlayerWithPassword(string $username, string $password, ?string $displayName = null, ?string $probeName = null): Player
    {
        if ($this->players->existsByUsername($username)) {
            throw new \RuntimeException('Username already exists.');
        }

        $home = $this->randomHomeSector();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $player = $this->players->createPlayer($username, $displayName, null, $home);
        $this->authMethods->addPasswordAuth($player->id, $passwordHash);
        $probe = $this->probes->createForPlayer($player->id, $probeName ?? 'Probe of ' . $username, $home);
        $this->mannies?->ensureDefaultsForProbe($probe);
        $this->visitedSectors->markVisited($player, $home);

        return $player;
    }

    public function registerPlayerWithExternalAuth(string $pseudonym, string $provider, string $providerUserId): Player
    {
        $provider = $this->normalizeExternalProvider($provider);
        $pseudonym = $this->normalizePseudonym($pseudonym);

        if ($this->players->existsByUsername($pseudonym)) {
            throw new \InvalidArgumentException('Pseudonym already exists.');
        }
        if ($this->authenticateWithExternal($provider, $providerUserId) !== null) {
            throw new \RuntimeException('External account already linked.');
        }

        $home = $this->randomHomeSector();
        $player = $this->players->createPlayer($pseudonym, $pseudonym, null, $home);
        $this->authMethods->addExternalAuth($player->id, $provider, $providerUserId);
        $probe = $this->probes->createForPlayer($player->id, 'Sonde de ' . $pseudonym, $home);
        $this->mannies?->ensureDefaultsForProbe($probe);
        $this->visitedSectors->markVisited($player, $home);

        return $player;
    }

    public function authenticateWithPassword(string $username, string $password): ?Player
    {
        return (new PasswordAuthProvider($this->players, $this->authMethods))->authenticate($username, $password);
    }

    public function authenticateWithExternal(string $provider, string $providerUserId): ?Player
    {
        $method = $this->authMethods->findExternalAuth($this->normalizeExternalProvider($provider), $providerUserId);

        return $method === null ? null : $this->players->findById($method->playerId);
    }

    /**
     * @return array{token: string, expiresAt: string}
     */
    public function createSessionForPlayer(Player $player): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $this->sessionTtlDays . ' days')
            ->format('c');
        $this->sessions->createSession($player->id, $token, $expiresAt);

        return ['token' => $token, 'expiresAt' => $expiresAt];
    }

    public function getPlayerFromBearerToken(?string $authorizationHeader): ?Player
    {
        $token = $this->extractBearerToken($authorizationHeader);
        if ($token === null) {
            return null;
        }

        $session = $this->sessions->findValidSessionByToken($token);
        if ($session !== null) {
            $this->sessions->touchSession($session);

            return $this->players->findById($session->playerId);
        }

        if ($this->apiKeys !== null) {
            $apiKey = $this->apiKeys->findValidByToken($token);
            if ($apiKey !== null) {
                $this->apiKeys->touch($apiKey);

                return $this->players->findById($apiKey->playerId);
            }
        }

        return null;
    }

    /**
     * @return array{id:int, token:string, label:string, lastFour:string, createdAt:string}
     */
    public function createApiKeyForPlayer(Player $player): array
    {
        if ($this->apiKeys === null) {
            throw new \RuntimeException('API key storage is not configured.');
        }

        $token = 'vng_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $apiKey = $this->apiKeys->createKey($player->id, $token, 'default');

        return [
            'id' => $apiKey->id,
            'token' => $token,
            'label' => $apiKey->label,
            'lastFour' => $apiKey->lastFour,
            'createdAt' => $apiKey->createdAt,
        ];
    }

    private function extractBearerToken(?string $authorizationHeader): ?string
    {
        if ($authorizationHeader === null || !preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    private function normalizeExternalProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, AuthProvider::externalValues(), true)) {
            throw new \InvalidArgumentException('Unsupported external auth provider.');
        }

        return $provider;
    }

    private function normalizePseudonym(string $pseudonym): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($pseudonym));
        if (!is_string($normalized) || preg_match(self::PSEUDONYM_PATTERN, $normalized) !== 1) {
            throw new \InvalidArgumentException('Invalid pseudonym.');
        }

        return $normalized;
    }

    private function randomHomeSector(): SectorCoordinates
    {
        do {
            $x = random_int(-1000, 1000);
            $y = random_int(-1000, 1000);
            $z = random_int(-1000, 1000);
        } while (($x + $y + $z) % 2 !== 0);

        return new SectorCoordinates($x, $y, $z);
    }
}
