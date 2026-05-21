<?php

declare(strict_types=1);

namespace VonNeumannGame\Auth;

use VonNeumannGame\Domain\Player;
use VonNeumannGame\Repository\PlayerAuthRepository;
use VonNeumannGame\Repository\PlayerRepository;

final class PasswordAuthProvider implements AuthProviderInterface
{
    public function __construct(
        private readonly PlayerRepository $players,
        private readonly PlayerAuthRepository $authMethods,
    ) {}

    public function authenticate(string $identifier, string $secret): ?Player
    {
        $method = $this->authMethods->findPasswordAuthByUsername($identifier);
        if ($method === null || $method->passwordHash === null || !password_verify($secret, $method->passwordHash)) {
            return null;
        }

        return $this->players->findById($method->playerId);
    }
}
