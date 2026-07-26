<?php

declare(strict_types=1);

namespace VonNeumannGame\RateLimit;

final class RedisTokenRateLimiter implements TokenRateLimiter
{
    private const SLIDING_WINDOW_SCRIPT = <<<'LUA'
local key = KEYS[1]
local limit = tonumber(ARGV[1])
local window_ms = tonumber(ARGV[2])
local nonce = ARGV[3]
local redis_time = redis.call('TIME')
local now_ms = (tonumber(redis_time[1]) * 1000) + math.floor(tonumber(redis_time[2]) / 1000)
local cutoff_ms = now_ms - window_ms

redis.call('ZREMRANGEBYSCORE', key, '-inf', cutoff_ms)
local count = redis.call('ZCARD', key)

if count >= limit then
    local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
    local reset_ms = tonumber(oldest[2]) + window_ms
    local retry_after = math.max(1, math.ceil((reset_ms - now_ms) / 1000))
    return {0, limit, 0, retry_after, math.ceil(reset_ms / 1000)}
end

redis.call('ZADD', key, now_ms, tostring(now_ms) .. ':' .. nonce)
redis.call('PEXPIRE', key, window_ms + 1000)
local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
local reset_ms = tonumber(oldest[2]) + window_ms

return {1, limit, limit - count - 1, 0, math.ceil(reset_ms / 1000)}
LUA;

    public function __construct(
        private readonly RedisScriptExecutor $redis,
        private readonly int $maxRequests = 60,
        private readonly int $windowSeconds = 60,
        private readonly string $keyPrefix = 'vng:',
    ) {
        if ($this->maxRequests < 1 || $this->windowSeconds < 1) {
            throw new \InvalidArgumentException('Rate limit and window must be positive integers.');
        }
    }

    public function check(string $token): RateLimitDecision
    {
        $key = $this->keyPrefix . 'rate-limit:api:token:' . hash('sha256', $token);

        try {
            $result = $this->redis->evaluate(
                self::SLIDING_WINDOW_SCRIPT,
                [$key, $this->maxRequests, $this->windowSeconds * 1000, bin2hex(random_bytes(8))],
                1,
            );
        } catch (\Throwable $exception) {
            error_log('Redis API rate limiter unavailable: ' . $exception->getMessage());

            return RateLimitDecision::unavailable();
        }

        if (!is_array($result) || count($result) < 5) {
            error_log('Redis API rate limiter returned an invalid response.');

            return RateLimitDecision::unavailable();
        }

        return new RateLimitDecision(
            (int) $result[0] === 1,
            (int) $result[1],
            max(0, (int) $result[2]),
            max(0, (int) $result[3]),
            max(0, (int) $result[4]),
        );
    }
}
