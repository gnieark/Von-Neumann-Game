<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

final class ItemMetadataColumns
{
    private const COLUMN_KEYS = [
        'recipe' => 'recipe',
        'craftingRunId' => 'crafting_run_id',
        'craftedByMannyId' => 'crafted_by_manny_id',
        'craftedByMannyName' => 'crafted_by_manny_name',
        'craftedAt' => 'crafted_at',
        'fabricator' => 'fabricator',
        'capacityBonus' => 'capacity_bonus',
        'restoredDetachedContainerSourceUid' => 'restored_detached_container_source_uid',
    ];

    /** @return array<string, mixed> */
    public static function parameters(array $metadata): array
    {
        $audit = $metadata;
        $parameters = [];
        foreach (self::COLUMN_KEYS as $key => $column) {
            $parameters[$column] = $metadata[$key] ?? ($key === 'capacityBonus' ? 0.0 : null);
            unset($audit[$key]);
        }
        unset($audit['capacityBonusUnit']);
        $parameters['audit_metadata_json'] = $audit === []
            ? '{}'
            : json_encode($audit, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $parameters;
    }

    /** @return array<string, mixed> */
    public static function metadata(array $row): array
    {
        $auditJson = (string) ($row['audit_metadata_json'] ?? '{}');
        $metadata = $auditJson === '{}' ? [] : json_decode($auditJson, true);
        $metadata = is_array($metadata) ? $metadata : [];
        foreach (self::COLUMN_KEYS as $key => $column) {
            $value = $row[$column] ?? null;
            if ($key === 'capacityBonus') {
                $value = (float) ($value ?? 0.0);
                if ($value <= 0.0) continue;
                $metadata['capacityBonusUnit'] = 'earth_container_equivalent';
            } elseif ($value === null || $value === '') {
                continue;
            }
            $metadata[$key] = $value;
        }
        return $metadata;
    }
}
