<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ApiKey
{
    public function __construct(
        public readonly int $id,
        public readonly int $playerId,
        public readonly string $tokenHash,
        public readonly string $label,
        public readonly string $lastFour,
        public readonly string $createdAt,
        public ?string $lastUsedAt,
        public ?string $revokedAt,
    ) {}
}
