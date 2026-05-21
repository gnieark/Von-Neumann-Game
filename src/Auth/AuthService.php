<?php

declare(strict_types=1);

namespace VonNeumannGame\Auth;

use VonNeumannGame\Domain\Player;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerAuthRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\SessionRepository;
use VonNeumannGame\Sector\SectorCoordinates;

final class AuthService
{
    public function __construct(
        private readonly PlayerRepository $players,
        private readonly PlayerAuthRepository $authMethods,
        private readonly NeumannProbeRepository $probes,
        private readonly SessionRepository $sessions,
        private readonly int $sessionTtlDays = 7,
    ) {}

    public function registerPlayerWithPassword(string $username, string $password, ?string $displayName = null, ?string $probeName = null): Player
    {
        if ($this->players->existsByUsername($username)) {
            throw new \RuntimeException('Username already exists.');
        }

        $home = SectorCoordinates::origin();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $player = $this->players->createPlayer($username, $displayName, null, $home);
        $this->authMethods->addPasswordAuth($player->id, $passwordHash);
        $this->probes->createForPlayer($player->id, $probeName ?? 'Probe of ' . $username, $home);

        return $player;
    }

    public function authenticateWithPassword(string $username, string $password): ?Player
    {
        return (new PasswordAuthProvider($this->players, $this->authMethods))->authenticate($username, $password);
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
        if ($session === null) {
            return null;
        }

        $this->sessions->touchSession($session);

        return $this->players->findById($session->playerId);
    }

    private function extractBearerToken(?string $authorizationHeader): ?string
    {
        if ($authorizationHeader === null || !preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }
}
