<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Domain\ProbeItem;

final class DeuteriumEngineContainerSpaceMigration
{
    /**
     * @return array{filesScanned:int,filesChanged:int,driftingItemsChanged:int}
     */
    public function migrateSectorFiles(string $universePath, bool $dryRun = false): array
    {
        $report = [
            'filesScanned' => 0,
            'filesChanged' => 0,
            'driftingItemsChanged' => 0,
        ];
        $sectorDirectory = rtrim($universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
        if (!is_dir($sectorDirectory)) {
            return $report;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sectorDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || !preg_match('/^sector_.+\.json$/', $file->getFilename())) {
                continue;
            }

            $report['filesScanned']++;
            $path = $file->getPathname();
            $json = file_get_contents($path);
            if ($json === false) {
                throw new RuntimeException("Unable to read sector file '{$path}'.");
            }
            try {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new RuntimeException("Invalid JSON in sector file '{$path}': {$error->getMessage()}", 0, $error);
            }
            if (!is_array($data)) {
                throw new RuntimeException("Sector file '{$path}' must contain a JSON object.");
            }

            $changed = 0;
            if (array_key_exists('objects', $data)) {
                $changed = $this->normalizeSectorObjects($data['objects']);
            }
            if ($changed === 0) {
                continue;
            }
            $report['filesChanged']++;
            $report['driftingItemsChanged'] += $changed;
            if ($dryRun) {
                continue;
            }

            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));
            if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false) {
                throw new RuntimeException("Unable to write temporary sector file '{$temporaryPath}'.");
            }
            if (!rename($temporaryPath, $path)) {
                @unlink($temporaryPath);
                throw new RuntimeException("Unable to replace sector file '{$path}'.");
            }
        }

        return $report;
    }

    public function normalizeJsonPayload(mixed &$value): int
    {
        if (!is_array($value)) {
            return 0;
        }

        $changed = 0;
        $isDeuteriumEngine = ($value['type'] ?? null) === ProbeItem::TYPE_DEUTERIUM_ENGINE
            || ($value['itemType'] ?? null) === ProbeItem::TYPE_DEUTERIUM_ENGINE;
        if (
            $isDeuteriumEngine
            && array_key_exists('containerSpace', $value)
            && abs((float) $value['containerSpace'] - CraftingRecipeCatalog::DEUTERIUM_ENGINE_CONTAINER_SPACE) > 0.00001
        ) {
            $value['containerSpace'] = CraftingRecipeCatalog::DEUTERIUM_ENGINE_CONTAINER_SPACE;
            $changed++;
        }
        if (
            ($value['reservedCargoType'] ?? null) === ProbeItem::TYPE_DEUTERIUM_ENGINE
            && array_key_exists('reservedCargoSpace', $value)
            && abs((float) $value['reservedCargoSpace'] - CraftingRecipeCatalog::DEUTERIUM_ENGINE_CONTAINER_SPACE) > 0.00001
        ) {
            $value['reservedCargoSpace'] = CraftingRecipeCatalog::DEUTERIUM_ENGINE_CONTAINER_SPACE;
            $changed++;
        }

        foreach ($value as &$child) {
            $changed += $this->normalizeJsonPayload($child);
        }
        unset($child);

        return $changed;
    }

    private function normalizeSectorObjects(mixed &$objects): int
    {
        if (!is_array($objects)) {
            return 0;
        }

        $changed = 0;
        foreach ($objects as &$object) {
            if (
                is_array($object)
                && ($object['type'] ?? null) === 'drifting_item'
                && ($object['itemType'] ?? null) === ProbeItem::TYPE_DEUTERIUM_ENGINE
                && abs((float) ($object['containerSpace'] ?? 0.0) - CraftingRecipeCatalog::DEUTERIUM_ENGINE_CONTAINER_SPACE) > 0.00001
            ) {
                $object['containerSpace'] = CraftingRecipeCatalog::DEUTERIUM_ENGINE_CONTAINER_SPACE;
                $changed++;
            }
        }
        unset($object);

        return $changed;
    }
}
