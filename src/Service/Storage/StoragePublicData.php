<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Storage;

/** Preserve JSON objects for empty maps, including after idempotency replay. */
final class StoragePublicData
{
    public static function normalize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (!is_array($value)) { continue; }
            $data[$key] = $value === [] && in_array($key, ['resources', 'metadata'], true)
                ? new \stdClass()
                : self::normalize($value);
        }
        return $data;
    }
}
