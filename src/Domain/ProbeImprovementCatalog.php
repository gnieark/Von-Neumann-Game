<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Config\Config;

final class ProbeImprovementCatalog
{
    public const DEUTERIUM_COMPRESSION = 'deuterium_compression';

    public const DEUTERIUM_COMPRESSION_DURATION_SECONDS = 300;
    public const DEUTERIUM_COMPRESSION_MAX_DEUTERIUM_PERCENT = 200.0;

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(array $config = []): array
    {
        return [
            self::deuteriumCompression($config),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id, array $config = []): ?array
    {
        $id = self::normalizeId($id);
        foreach (self::all($config) as $improvement) {
            if ($improvement['id'] === $id) {
                return $improvement;
            }
        }

        return null;
    }

    public static function normalizeId(string $id): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($id)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function deuteriumCompression(array $config): array
    {
        return [
            'id' => self::DEUTERIUM_COMPRESSION,
            'name' => (string) Config::value($config, self::DEUTERIUM_COMPRESSION . '.name', 'Deuterium compression'),
            'description' => (string) Config::value(
                $config,
                self::DEUTERIUM_COMPRESSION . '.description',
                'Compresses the external deuterium tank reserve so it can hold up to 200% fuel.',
            ),
            'durationSeconds' => Config::int(
                $config,
                self::DEUTERIUM_COMPRESSION . '.durationSeconds',
                self::DEUTERIUM_COMPRESSION_DURATION_SECONDS,
            ),
            'ingredients' => self::ingredients($config),
            'effects' => [
                'maxDeuteriumPercent' => Config::float(
                    $config,
                    self::DEUTERIUM_COMPRESSION . '.maxDeuteriumPercent',
                    self::DEUTERIUM_COMPRESSION_MAX_DEUTERIUM_PERCENT,
                ),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function ingredients(array $config): array
    {
        $configured = Config::value($config, self::DEUTERIUM_COMPRESSION . '.ingredients');
        if (is_array($configured)) {
            return array_values(array_filter($configured, 'is_array'));
        }

        return [
            [
                'type' => ProbeItem::TYPE_ELECTRIC_MOTOR,
                'quantity' => 1,
                'unit' => 'item',
                'kind' => 'item',
            ],
            [
                'type' => ProbeItem::TYPE_STEEL_BAR,
                'quantity' => 2,
                'unit' => 'item',
                'kind' => 'item',
            ],
        ];
    }
}
