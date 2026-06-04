<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Config\Config;

final class CraftingRecipeCatalog
{
    public const FABRICATOR_MANNY = 'manny';
    public const WAYPOINT_BOOKMARK_METALS_COST = 0.01;
    public const WAYPOINT_BOOKMARK_CONTAINER_SPACE = 0.01;
    public const WAYPOINT_BOOKMARK_CRAFTING_SECONDS = 600;
    public const STEEL_BAR_METALS_COST = 0.02;
    public const STEEL_BAR_CONTAINER_SPACE = 0.01;
    public const STEEL_BAR_CRAFTING_SECONDS = 300;
    public const STEEL_PLATE_METALS_COST = 0.02;
    public const STEEL_PLATE_CONTAINER_SPACE = 0.01;
    public const STEEL_PLATE_CRAFTING_SECONDS = 300;
    public const ADDITIONAL_CONTAINER_STEEL_PLATES = 12;
    public const ADDITIONAL_CONTAINER_STEEL_BARS = 15;
    public const ADDITIONAL_CONTAINER_CRAFTING_SECONDS = 180;
    public const ADDITIONAL_CONTAINER_CAPACITY_BONUS = 1.0;
    public const ADDITIONAL_CONTAINER_CONTAINER_SPACE = 0.0;

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(array $config = []): array
    {
        return [
            self::waypointBookmark($config),
            self::steelBar($config),
            self::steelPlate($config),
            self::additionalContainer($config),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id, array $config = []): ?array
    {
        $id = self::normalizeId($id);
        foreach (self::all($config) as $recipe) {
            if ($recipe['id'] === $id) {
                return $recipe;
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
    private static function waypointBookmark(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_WAYPOINT_BOOKMARK,
            'name' => ProbeItem::WAYPOINT_BOOKMARK_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                [
                    'type' => ResourceComposition::METALS,
                    'quantity' => Config::float($config, 'waypoint_bookmark.metalsCost', self::WAYPOINT_BOOKMARK_METALS_COST),
                    'unit' => ProbeInventory::CAPACITY_UNIT,
                    'kind' => 'resource',
                ],
            ],
            'durationSeconds' => Config::int($config, 'waypoint_bookmark.durationSeconds', self::WAYPOINT_BOOKMARK_CRAFTING_SECONDS),
            'output' => [
                'type' => ProbeItem::TYPE_WAYPOINT_BOOKMARK,
                'name' => ProbeItem::WAYPOINT_BOOKMARK_NAME,
                'containerSpace' => Config::float($config, 'waypoint_bookmark.containerSpace', self::WAYPOINT_BOOKMARK_CONTAINER_SPACE),
                'containerSpaceUnit' => ProbeInventory::CAPACITY_UNIT,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function steelBar(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_STEEL_BAR,
            'name' => ProbeItem::STEEL_BAR_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                [
                    'type' => ResourceComposition::METALS,
                    'quantity' => Config::float($config, 'steel_bar.metalsCost', self::STEEL_BAR_METALS_COST),
                    'unit' => ProbeInventory::CAPACITY_UNIT,
                    'kind' => 'resource',
                ],
            ],
            'durationSeconds' => Config::int($config, 'steel_bar.durationSeconds', self::STEEL_BAR_CRAFTING_SECONDS),
            'output' => [
                'type' => ProbeItem::TYPE_STEEL_BAR,
                'name' => ProbeItem::STEEL_BAR_NAME,
                'containerSpace' => Config::float($config, 'steel_bar.containerSpace', self::STEEL_BAR_CONTAINER_SPACE),
                'containerSpaceUnit' => ProbeInventory::CAPACITY_UNIT,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function steelPlate(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_STEEL_PLATE,
            'name' => ProbeItem::STEEL_PLATE_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                [
                    'type' => ResourceComposition::METALS,
                    'quantity' => Config::float($config, 'steel_plate.metalsCost', self::STEEL_PLATE_METALS_COST),
                    'unit' => ProbeInventory::CAPACITY_UNIT,
                    'kind' => 'resource',
                ],
            ],
            'durationSeconds' => Config::int($config, 'steel_plate.durationSeconds', self::STEEL_PLATE_CRAFTING_SECONDS),
            'output' => [
                'type' => ProbeItem::TYPE_STEEL_PLATE,
                'name' => ProbeItem::STEEL_PLATE_NAME,
                'containerSpace' => Config::float($config, 'steel_plate.containerSpace', self::STEEL_PLATE_CONTAINER_SPACE),
                'containerSpaceUnit' => ProbeInventory::CAPACITY_UNIT,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function additionalContainer(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_ADDITIONAL_CONTAINER,
            'name' => ProbeItem::ADDITIONAL_CONTAINER_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                [
                    'type' => ProbeItem::TYPE_STEEL_PLATE,
                    'quantity' => Config::int($config, 'additional_container.steelPlateCount', self::ADDITIONAL_CONTAINER_STEEL_PLATES),
                    'unit' => 'item',
                    'kind' => 'item',
                ],
                [
                    'type' => ProbeItem::TYPE_STEEL_BAR,
                    'quantity' => Config::int($config, 'additional_container.steelBarCount', self::ADDITIONAL_CONTAINER_STEEL_BARS),
                    'unit' => 'item',
                    'kind' => 'item',
                ],
            ],
            'durationSeconds' => Config::int($config, 'additional_container.durationSeconds', self::ADDITIONAL_CONTAINER_CRAFTING_SECONDS),
            'output' => [
                'type' => ProbeItem::TYPE_ADDITIONAL_CONTAINER,
                'name' => ProbeItem::ADDITIONAL_CONTAINER_NAME,
                'containerSpace' => Config::float($config, 'additional_container.containerSpace', self::ADDITIONAL_CONTAINER_CONTAINER_SPACE),
                'containerSpaceUnit' => ProbeInventory::CAPACITY_UNIT,
                'capacityBonus' => Config::float($config, 'additional_container.capacityBonus', self::ADDITIONAL_CONTAINER_CAPACITY_BONUS),
                'capacityBonusUnit' => ProbeInventory::CAPACITY_UNIT,
            ],
        ];
    }
}
