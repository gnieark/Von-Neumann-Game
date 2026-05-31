<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ResourceComposition
{
    public const DEUTERIUM = 'deuterium';
    public const METALS = 'metals';
    public const OTHER = 'other';
    public const TYPES = [self::DEUTERIUM, self::METALS, self::OTHER];

    public static function typeForHint(string $hint): string
    {
        $hint = strtolower($hint);

        if (
            str_contains($hint, 'water')
            || str_contains($hint, 'ice')
            || str_contains($hint, 'volatile')
            || str_contains($hint, 'hydrogen')
        ) {
            return self::DEUTERIUM;
        }

        if (
            str_contains($hint, 'iron')
            || str_contains($hint, 'nickel')
            || str_contains($hint, 'metal')
            || str_contains($hint, 'platinum')
            || str_contains($hint, 'magnesium')
        ) {
            return self::METALS;
        }

        return self::OTHER;
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
            $counts[self::OTHER] = 1;
        }

        $total = (float) array_sum($counts);
        $composition = [];
        foreach (self::TYPES as $type) {
            $composition[$type] = round($counts[$type] / $total, 4);
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
            $type = strtolower(trim((string) $type));
            if ($type === '') {
                continue;
            }
            if (!in_array($type, self::TYPES, true)) {
                throw new \InvalidArgumentException('Mining resource must be deuterium, metals or other.');
            }
            if (!in_array($type, $normalized, true)) {
                $normalized[] = $type;
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one mining resource must be selected.');
        }

        return $normalized;
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
