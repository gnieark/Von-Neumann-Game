<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class PlayerAuthMethod
{
    public function __construct(
        public readonly int $id,
        public readonly int $playerId,
        public readonly string $provider,
        public readonly ?string $providerUserId,
        public readonly ?string $passwordHash,
        public readonly string $createdAt,
    ) {}
}
