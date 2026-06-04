<?php

declare(strict_types=1);

namespace VonNeumannGame\Config;

final class Config
{
    public static function value(array $config, string $path, mixed $default = null): mixed
    {
        $cursor = $config;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public static function int(array $config, string $path, int $default): int
    {
        $value = self::value($config, $path, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function float(array $config, string $path, float $default): float
    {
        $value = self::value($config, $path, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public static function bool(array $config, string $path, bool $default): bool
    {
        $value = self::value($config, $path, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return $default;
    }

    /**
     * @return array<mixed>
     */
    public static function getArray(array $config, string $path, array $default = []): array
    {
        $value = self::value($config, $path, $default);

        return is_array($value) ? $value : $default;
    }

    /**
     * @param array<mixed> $base
     * @param array<mixed> $override
     * @return array<mixed>
     */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && self::isAssociative($value)
                && self::isAssociative($base[$key])
            ) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private static function isAssociative(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
