<?php

declare(strict_types=1);

namespace VonNeumannGame\Auth;

use VonNeumannGame\Domain\Player;

interface AuthProviderInterface
{
    public function authenticate(string $identifier, string $secret): ?Player;
}
