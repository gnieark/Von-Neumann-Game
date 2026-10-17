<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

use VonNeumannGame\Domain\MineableResourceAmounts;

/** The persisted representation never owns stocks, including exhausted sources. */
final class MineableDescriptors
{
    public static function covers(array $object): bool
    {
        return ($object['type'] ?? null) === 'asteroid'
            || (($object['type'] ?? null) === 'dormant_construct'
                && ($object['subtype'] ?? null) === DormantConstruct::SUBTYPE_OTHERS_MOTHERSHIP_WRECK);
    }

    public static function descriptor(array $data): array
    {
        return self::map($data, static function (array $object): array {
            unset($object['resourceAmounts']);
            return $object;
        });
    }

    /** Decode only canonical descriptors. No JSON stock or mass reconstruction. */
    public static function decode(array $data): array
    {
        return self::map($data, static function (array $object): array {
            if (array_key_exists('resourceAmounts', $object)) {
                throw new SectorStorageException('Mineable JSON stocks require the explicit stock migration.');
            }
            $object['resourceAmounts'] = MineableResourceAmounts::zero();
            return $object;
        });
    }

    /** Traverse object trees, including solar systems and nested orbital bodies. */
    public static function map(array $data, callable $transform): array
    {
        if (self::covers($data)) { return $transform($data); }
        if (($data['type'] ?? null) === 'manny' && ($data['state'] ?? null) === 'forgotten') {
            throw new SectorStorageException('Forgotten Manny JSON presence requires explicit migration.');
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) { $data[$key] = self::map($value, $transform); }
        }
        return $data;
    }
}
