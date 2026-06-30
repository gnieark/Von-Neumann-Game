<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class SessionToken
{
    public function __construct(
        public readonly int $id,
        public readonly int $playerId,
        public readonly string $tokenHash,
        public readonly string $createdAt,
        public string $expiresAt,
        public string $lastUsedAt,
        public ?string $revokedAt,
        public readonly bool $rememberMe = false,
    ) {}
}
