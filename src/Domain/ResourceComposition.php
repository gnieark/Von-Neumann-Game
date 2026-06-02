<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ResourceComposition
{
    public const DEUTERIUM = 'deuterium';
    public const METALS = 'metals';
    public const ICE = 'ice';
    public const CARBON_COMPOUNDS = 'carbon_compounds';
    public const TYPES = [self::DEUTERIUM, self::METALS, self::ICE, self::CARBON_COMPOUNDS];
    private const LEGACY_OTHER = 'other';

    public static function typeForHint(string $hint): string
    {
        $hint = strtolower($hint);

        if (str_contains($hint, 'deuterium') || str_contains($hint, 'hydrogen')) {
            return self::DEUTERIUM;
        }

        if (
            str_contains($hint, 'iron')
            || str_contains($hint, 'nickel')
            || str_contains($hint, 'metal')
            || str_contains($hint, 'platinum')
            || str_contains($hint, 'magnesium')
            || str_contains($hint, 'silicate')
        ) {
            return self::METALS;
        }

        if (
            str_contains($hint, 'water')
            || str_contains($hint, 'ice')
            || str_contains($hint, 'volatile')
            || str_contains($hint, 'ammonia')
        ) {
            return self::ICE;
        }

        if (
            str_contains($hint, 'carbon')
            || str_contains($hint, 'organic')
            || str_contains($hint, 'hydrocarbon')
        ) {
            return self::CARBON_COMPOUNDS;
        }

        return self::CARBON_COMPOUNDS;
    }

    /**
     * @param array<mixed> $hints
     * @return array<string, float>
     */
    public static function fromHints(array $hints): array
    {
        $counts = array_fill_keys(self::TYPES, 0);
        foreach ($hints as $hint) {
            $counts[self::typeForHint((string) $hint)]++;
        }

        if (array_sum($counts) === 0) {
            $counts[self::CARBON_COMPOUNDS] = 1;
        }

        $total = (float) array_sum($counts);
        $composition = [];
        foreach (self::TYPES as $type) {
            $composition[$type] = round($counts[$type] / $total, 4);
        }

        return $composition;
    }

    /**
     * @param array<string, float|int> $amounts
     * @return array<string, float>
     */
    public static function fromAmounts(array $amounts): array
    {
        if (isset($amounts[self::LEGACY_OTHER])) {
            $amounts[self::CARBON_COMPOUNDS] = (float) ($amounts[self::CARBON_COMPOUNDS] ?? 0.0)
                + (float) $amounts[self::LEGACY_OTHER];
        }

        $normalized = [];
        $total = 0.0;
        foreach (self::TYPES as $type) {
            $amount = max(0.0, (float) ($amounts[$type] ?? 0.0));
            $normalized[$type] = $amount;
            $total += $amount;
        }

        if ($total <= 0.0) {
            return array_fill_keys(self::TYPES, 0.0);
        }

        $composition = [];
        $remaining = 1.0;
        $lastIndex = count(self::TYPES) - 1;
        foreach (self::TYPES as $index => $type) {
            if ($index === $lastIndex) {
                $composition[$type] = round(max(0.0, $remaining), 4);
                break;
            }

            $share = round($normalized[$type] / $total, 4);
            $composition[$type] = $share;
            $remaining = round($remaining - $share, 4);
        }

        return $composition;
    }

    /**
     * @param array<string, float|int> $composition
     * @return array<string>
     */
    public static function availableTypes(array $composition): array
    {
        return array_values(array_filter(
            self::TYPES,
            static fn(string $type): bool => (float) ($composition[$type] ?? 0.0) > 0.0,
        ));
    }

    /**
     * @param string|array<mixed> $types
     * @return array<string>
     */
    public static function normalizeSelection(string|array $types): array
    {
        $types = is_string($types) ? [$types] : $types;
        $normalized = [];
        foreach ($types as $type) {
            $normalizedType = self::normalizeType((string) $type);
            if ($normalizedType === null) {
                continue;
            }
            if (!in_array($normalizedType, self::TYPES, true)) {
                throw new \InvalidArgumentException('Mining resource must be deuterium, metals, ice or carbon_compounds.');
            }
            if (!in_array($normalizedType, $normalized, true)) {
                $normalized[] = $normalizedType;
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one mining resource must be selected.');
        }

        return $normalized;
    }

    private static function normalizeType(string $type): ?string
    {
        $type = strtolower(trim($type));
        $type = str_replace(['-', ' '], '_', $type);
        if ($type === '') {
            return null;
        }

        return match ($type) {
            self::DEUTERIUM => self::DEUTERIUM,
            self::METALS, 'metal' => self::METALS,
            self::ICE, 'water', 'water_ice', 'volatile', 'volatiles' => self::ICE,
            self::CARBON_COMPOUNDS, 'carbon', 'organic', 'organics', 'organic_compounds', 'organiccompounds', self::LEGACY_OTHER => self::CARBON_COMPOUNDS,
            default => $type,
        };
    }

    /**
     * @param array<string, float|int> $composition
     * @param array<string> $selection
     * @return array<string, float>
     */
    public static function profileForSelection(array $composition, array $selection): array
    {
        $total = 0.0;
        foreach ($selection as $type) {
            $total += max(0.0, (float) ($composition[$type] ?? 0.0));
        }
        if ($total <= 0.0) {
            throw new \InvalidArgumentException('Selected resources are not present on this object.');
        }

        $profile = array_fill_keys(self::TYPES, 0.0);
        $remaining = 1.0;
        $lastIndex = count($selection) - 1;
        foreach ($selection as $index => $type) {
            if ($index === $lastIndex) {
                $profile[$type] = round(max(0.0, $remaining), 4);
                break;
            }

            $share = round(max(0.0, (float) ($composition[$type] ?? 0.0)) / $total, 4);
            $profile[$type] = $share;
            $remaining = round($remaining - $share, 4);
        }

        return $profile;
    }
}
