<?php

declare(strict_types=1);

namespace VonNeumannGame\RateLimit;

final class RateLimitDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $retryAfterSeconds,
        public readonly int $resetAt,
        public readonly bool $available = true,
    ) {}

    public static function unavailable(): self
    {
        return new self(true, 0, 0, 0, 0, false);
    }
}
