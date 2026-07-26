<?php

declare(strict_types=1);

namespace VonNeumannGame\RateLimit;

final class PhpRedisScriptExecutor implements RedisScriptExecutor
{
    private ?\Redis $redis = null;

    public function __construct(private readonly array $config) {}

    public function evaluate(string $script, array $arguments, int $keyCount): mixed
    {
        $redis = $this->connection();
        $scriptHash = sha1($script);

        try {
            $result = $redis->evalSha($scriptHash, $arguments, $keyCount);
        } catch (\RedisException $exception) {
            if (!str_contains(strtoupper($exception->getMessage()), 'NOSCRIPT')) {
                throw $exception;
            }
            $result = false;
        }

        if ($result !== false) {
            return $result;
        }

        $lastError = strtoupper((string) $redis->getLastError());
        if ($lastError !== '' && !str_contains($lastError, 'NOSCRIPT')) {
            throw new \RuntimeException('Redis script execution failed: ' . $lastError);
        }

        $redis->clearLastError();
        $loadedHash = $redis->script('load', $script);
        if (!is_string($loadedHash) || $loadedHash === '') {
            throw new \RuntimeException('Unable to load the Redis rate-limit script.');
        }

        return $redis->evalSha($loadedHash, $arguments, $keyCount);
    }

    private function connection(): \Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('The PHP Redis extension is not installed.');
        }

        $redis = new \Redis();
        $connected = $redis->connect(
            (string) ($this->config['host'] ?? '127.0.0.1'),
            (int) ($this->config['port'] ?? 6379),
            (float) ($this->config['connectTimeoutSeconds'] ?? 1.0),
        );
        if (!$connected) {
            throw new \RuntimeException('Unable to connect to Redis.');
        }

        $password = $this->config['password'] ?? null;
        if (is_string($password) && $password !== '' && !$redis->auth($password)) {
            throw new \RuntimeException('Redis authentication failed.');
        }
        if (!$redis->select((int) ($this->config['database'] ?? 0))) {
            throw new \RuntimeException('Unable to select the configured Redis database.');
        }

        return $this->redis = $redis;
    }
}
