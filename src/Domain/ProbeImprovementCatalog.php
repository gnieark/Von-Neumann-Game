<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Config\Config;

final class ProbeImprovementCatalog
{
    public const DEUTERIUM_COMPRESSION = 'deuterium_compression';
    public const REINFORCED_CONTAINER_COUPLINGS = 'reinforced_container_couplings';

    public const DEUTERIUM_COMPRESSION_DURATION_SECONDS = 300;
    public const DEUTERIUM_COMPRESSION_MAX_DEUTERIUM_PERCENT = 200.0;
    public const REINFORCED_CONTAINER_COUPLINGS_CONTAINER_RISK_DISCOUNT = 5;

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(array $config = []): array
    {
        return [
            self::deuteriumCompression($config),
            self::reinforcedContainerCouplings($config),
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
            'ingredients' => self::deuteriumCompressionIngredients($config),
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
    private static function deuteriumCompressionIngredients(array $config): array
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

    /**
     * @return array<string, mixed>
     */
    private static function reinforcedContainerCouplings(array $config): array
    {
        return [
            'id' => self::REINFORCED_CONTAINER_COUPLINGS,
            'name' => (string) Config::value($config, self::REINFORCED_CONTAINER_COUPLINGS . '.name', 'Reinforced container couplings'),
            'description' => (string) Config::value(
                $config,
                self::REINFORCED_CONTAINER_COUPLINGS . '.description',
                'Reinforces external container couplings so five additional containers are ignored when movement break risk is calculated.',
            ),
            'durationSeconds' => Config::int(
                $config,
                self::REINFORCED_CONTAINER_COUPLINGS . '.durationSeconds',
                self::DEUTERIUM_COMPRESSION_DURATION_SECONDS,
            ),
            'ingredients' => self::reinforcedContainerCouplingsIngredients($config),
            'effects' => [
                'fragileContainerRiskAdditionalContainerDiscount' => Config::int(
                    $config,
                    self::REINFORCED_CONTAINER_COUPLINGS . '.fragileContainerRiskAdditionalContainerDiscount',
                    self::REINFORCED_CONTAINER_COUPLINGS_CONTAINER_RISK_DISCOUNT,
                ),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function reinforcedContainerCouplingsIngredients(array $config): array
    {
        $configured = Config::value($config, self::REINFORCED_CONTAINER_COUPLINGS . '.ingredients');
        if (is_array($configured)) {
            return array_values(array_filter($configured, 'is_array'));
        }

        return [
            [
                'type' => ProbeItem::TYPE_INTEGRATED_CIRCUIT,
                'quantity' => 1,
                'unit' => 'item',
                'kind' => 'item',
            ],
            [
                'type' => 'carbon_compounds',
                'quantity' => 0.4,
                'unit' => 'earth_container_equivalent',
                'kind' => 'resource',
            ],
        ];
    }
}
