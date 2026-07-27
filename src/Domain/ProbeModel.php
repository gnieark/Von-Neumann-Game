<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeModel
{
    public const GENERIC = 'generic';
    public const DEUTERIUM_TANKER = 'deuterium_tanker';
    public const ALL = [
        self::GENERIC,
        self::DEUTERIUM_TANKER,
    ];

    public static function isValid(string $model): bool
    {
        return in_array($model, self::ALL, true);
    }

    public static function baseMaxDeuteriumPercent(string $model, float $genericMaximum): float
    {
        return $model === self::DEUTERIUM_TANKER ? 400.0 : $genericMaximum;
    }

    public static function containerBreakThreshold(string $model, bool $reinforced): int
    {
        if ($model === self::DEUTERIUM_TANKER) {
            return $reinforced ? 4 : 2;
        }

        return $reinforced ? 10 : 5;
    }
}
