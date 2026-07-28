<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use VonNeumannGame\Repository\DetachedStorageContainerRepository;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorDetachedContainer;

final class DetachedContainerJsonMigrationService
{
    private const COLLECTIONS = [
        'detachedContainers',
        'hiddenDetachedContainers',
        'planetDroppedContainers',
    ];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly DetachedStorageContainerRepository $containers,
    ) {}

    /**
     * @param null|callable(int, int):void $progress Receives processed and total file counts.
     * @return array{filesScanned:int,filesRewritten:int,containersMigrated:int,containerIdsChanged:int}
     */
    public function migrate(string $universePath, bool $dryRun = false, ?callable $progress = null): array
    {
        $sectorDirectory = rtrim($universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
        if (!is_dir($sectorDirectory)) {
            throw new \RuntimeException("Sector directory not found: {$sectorDirectory}");
        }
        $files = $this->sectorFiles($sectorDirectory);
        $occurrenceByOriginalId = [];
        $result = [
            'filesScanned' => 0,
            'filesRewritten' => 0,
            'containersMigrated' => 0,
            'containerIdsChanged' => 0,
        ];

        foreach ($files as $file) {
            $data = $this->decodeFile($file);
            $coordinates = $this->coordinates($data, $file);
            $containers = [];
            foreach (self::COLLECTIONS as $collection) {
                $entries = $data[$collection] ?? [];
                if (!is_array($entries)) {
                    throw new \RuntimeException("Invalid {$collection} collection in {$file}.");
                }
                foreach (array_values($entries) as $index => $entry) {
                    if (!is_array($entry)) {
                        throw new \RuntimeException("Invalid {$collection}[{$index}] entry in {$file}.");
                    }
                    $originalId = trim((string) ($entry['id'] ?? ''));
                    if ($originalId === '') {
                        throw new \RuntimeException("Missing container id in {$file}:{$collection}[{$index}].");
                    }
                    $occurrence = $occurrenceByOriginalId[$originalId] ?? 0;
                    $occurrenceByOriginalId[$originalId] = $occurrence + 1;
                    if (
                        $occurrence > 0
                        || (!$dryRun && $this->containers->objectIdExistsOutsideSector($originalId, $coordinates))
                    ) {
                        $entry['id'] = $this->collisionId($originalId, $file, $collection, $index);
                        $result['containerIdsChanged']++;
                    }
                    $containers[] = SectorDetachedContainer::fromArray($entry);
                }
            }
            foreach (array_values(is_array($data['objects'] ?? null) ? $data['objects'] : []) as $index => $object) {
                if (!is_array($object) || ($object['type'] ?? null) !== 'detached_container') {
                    continue;
                }
                $originalId = trim((string) ($object['id'] ?? ''));
                if ($originalId === '') {
                    throw new \RuntimeException("Missing legacy detached container id in {$file}:objects[{$index}].");
                }
                $occurrence = $occurrenceByOriginalId[$originalId] ?? 0;
                $occurrenceByOriginalId[$originalId] = $occurrence + 1;
                if (
                    $occurrence > 0
                    || (!$dryRun && $this->containers->objectIdExistsOutsideSector($originalId, $coordinates))
                ) {
                    $object['id'] = $this->collisionId($originalId, $file, 'objects', $index);
                    $result['containerIdsChanged']++;
                }
                $containers[] = SectorDetachedContainer::fromArray($object);
            }

            $cleanData = $data;
            foreach (self::COLLECTIONS as $collection) {
                unset($cleanData[$collection]);
            }
            if (is_array($cleanData['objects'] ?? null)) {
                $cleanData['objects'] = array_values(array_filter(
                    $cleanData['objects'],
                    static fn(mixed $object): bool => !is_array($object) || ($object['type'] ?? null) !== 'detached_container',
                ));
            }

            if (!$dryRun) {
                $ownsTransaction = !$this->pdo->inTransaction();
                if ($ownsTransaction) {
                    $this->pdo->beginTransaction();
                }
                try {
                    foreach ($containers as $container) {
                        $this->containers->save($coordinates, $container);
                    }
                    if ($ownsTransaction) {
                        $this->pdo->commit();
                    }
                    $this->rewriteFile($file, $cleanData);
                } catch (\Throwable $e) {
                    if ($ownsTransaction && $this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }
            }

            $result['filesScanned']++;
            $result['containersMigrated'] += count($containers);
            if ($cleanData !== $data) {
                $result['filesRewritten']++;
            }
            if ($progress !== null && ($result['filesScanned'] % 1000 === 0 || $result['filesScanned'] === count($files))) {
                $progress($result['filesScanned'], count($files));
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function sectorFiles(string $sectorDirectory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sectorDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'json') {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFile(string $file): array
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            throw new \RuntimeException("Unable to read sector file: {$file}");
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Invalid JSON in {$file}: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data)) {
            throw new \RuntimeException("Sector JSON root must be an object: {$file}");
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function coordinates(array $data, string $file): SectorCoordinates
    {
        $coordinates = $data['coordinates'] ?? null;
        if (!is_array($coordinates) || !isset($coordinates['x'], $coordinates['y'], $coordinates['z'])) {
            throw new \RuntimeException("Missing sector coordinates in {$file}.");
        }

        return new SectorCoordinates(
            (int) $coordinates['x'],
            (int) $coordinates['y'],
            (int) $coordinates['z'],
        );
    }

    private function collisionId(string $originalId, string $file, string $collection, int $index): string
    {
        $suffix = '-migrated-' . substr(hash('sha256', $file . ':' . $collection . ':' . $index), 0, 12);
        $maxOriginalLength = 255 - strlen($suffix);

        return substr($originalId, 0, $maxOriginalLength) . $suffix;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function rewriteFile(string $file, array $data): void
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $temporary = $file . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write temporary sector file: {$temporary}");
        }
        if (!rename($temporary, $file)) {
            @unlink($temporary);
            throw new \RuntimeException("Unable to replace sector file: {$file}");
        }
    }
}
