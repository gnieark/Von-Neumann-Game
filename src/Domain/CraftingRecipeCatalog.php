<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class CraftingRecipeCatalog
{
    public const FABRICATOR_MANNY = 'manny';
    public const WAYPOINT_BOOKMARK_METALS_COST = 0.01;
    public const WAYPOINT_BOOKMARK_CONTAINER_SPACE = 0.01;
    public const WAYPOINT_BOOKMARK_CRAFTING_SECONDS = 600;

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::waypointBookmark(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        $id = self::normalizeId($id);
        foreach (self::all() as $recipe) {
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
    private static function waypointBookmark(): array
    {
        return [
            'id' => ProbeItem::TYPE_WAYPOINT_BOOKMARK,
            'name' => ProbeItem::WAYPOINT_BOOKMARK_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                [
                    'type' => ResourceComposition::METALS,
                    'quantity' => self::WAYPOINT_BOOKMARK_METALS_COST,
                    'unit' => ProbeInventory::CAPACITY_UNIT,
                ],
            ],
            'durationSeconds' => self::WAYPOINT_BOOKMARK_CRAFTING_SECONDS,
            'output' => [
                'type' => ProbeItem::TYPE_WAYPOINT_BOOKMARK,
                'name' => ProbeItem::WAYPOINT_BOOKMARK_NAME,
                'containerSpace' => self::WAYPOINT_BOOKMARK_CONTAINER_SPACE,
                'containerSpaceUnit' => ProbeInventory::CAPACITY_UNIT,
            ],
        ];
    }
}
