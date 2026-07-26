<?php

declare(strict_types=1);

namespace VonNeumannGame\RateLimit;

interface RedisScriptExecutor
{
    /**
     * @param list<string|int|float> $arguments
     */
    public function evaluate(string $script, array $arguments, int $keyCount): mixed;
}
