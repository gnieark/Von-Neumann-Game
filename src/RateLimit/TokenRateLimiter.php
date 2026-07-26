<?php

declare(strict_types=1);

namespace VonNeumannGame\RateLimit;

interface TokenRateLimiter
{
    public function check(string $token): RateLimitDecision;
}
