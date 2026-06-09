<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;

final class UniverseStatsService
{
    public const RESOURCE_TYPES = ResourceComposition::TYPES;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $universePath,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $probeRows = $this->probeRows();
        $sectorStats = $this->sectorStats();
        $probeDistances = $this->probeDistances($probeRows);

        return [
            'generatedAt' => gmdate('c'),
            'metrics' => [
                'probesInUniverse' => count($probeRows),
                'generatedSectors' => $sectorStats['generatedSectors'],
                'visitedSectors' => $this->scalarInt(
                    'SELECT COUNT(*) FROM (SELECT sector_x, sector_y, sector_z FROM visited_sectors GROUP BY sector_x, sector_y, sector_z) visited'
                ),
                'blackHoles' => $sectorStats['blackHoles'],
                'asteroidsByResource' => $sectorStats['asteroidsByResource'],
                'lostMannies' => $sectorStats['lostMannies'],
                'forgottenMannies' => $sectorStats['forgottenMannies'],
                'driftingContainers' => $sectorStats['driftingContainers'],
                'hiddenContainers' => $sectorStats['hiddenContainers'],
                'furthestProbeDistance' => $probeDistances['furthest'],
                'closestProbeDistance' => $probeDistances['closest'],
                'intelligentLifeWorlds' => 0,
                'successfulMissions' => 0,
                'failedMissions' => 0,
            ],
        ];
    }

    /**
     * @return array<int, array{x: int, y: int, z: int}>
     */
    private function probeRows(): array
    {
        $stmt = $this->pdo->query('SELECT sector_x, sector_y, sector_z FROM neumann_probes ORDER BY id ASC');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => [
            'x' => (int) $row['sector_x'],
            'y' => (int) $row['sector_y'],
            'z' => (int) $row['sector_z'],
        ], $rows);
    }

    private function scalarInt(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return 0;
        }

        return max(0, (int) $stmt->fetchColumn());
    }

    /**
     * @param array<int, array{x: int, y: int, z: int}> $probeRows
     * @return array{furthest: int, closest: int}
     */
    private function probeDistances(array $probeRows): array
    {
        if (count($probeRows) < 2) {
            return ['furthest' => 0, 'closest' => 0];
        }

        $grid = new SectorGrid();
        $furthest = 0;
        $closest = null;
        $count = count($probeRows);
        for ($i = 0; $i < $count - 1; $i++) {
            $a = new SectorCoordinates($probeRows[$i]['x'], $probeRows[$i]['y'], $probeRows[$i]['z']);
            for ($j = $i + 1; $j < $count; $j++) {
                $b = new SectorCoordinates($probeRows[$j]['x'], $probeRows[$j]['y'], $probeRows[$j]['z']);
                $distance = $grid->getDistance($a, $b);
                $furthest = max($furthest, $distance);
                $closest = $closest === null ? $distance : min($closest, $distance);
            }
        }

        return ['furthest' => $furthest, 'closest' => $closest ?? 0];
    }

    /**
     * @return array{
     *     generatedSectors: int,
     *     blackHoles: int,
     *     asteroidsByResource: array<string, int>,
     *     lostMannies: int,
     *     forgottenMannies: int,
     *     driftingContainers: int,
     *     hiddenContainers: int
     * }
     */
    private function sectorStats(): array
    {
        $stats = [
            'generatedSectors' => 0,
            'blackHoles' => 0,
            'asteroidsByResource' => array_fill_keys(self::RESOURCE_TYPES, 0),
            'lostMannies' => 0,
            'forgottenMannies' => 0,
            'driftingContainers' => 0,
            'hiddenContainers' => 0,
        ];

        $sectorDirectory = rtrim($this->universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
        if (!is_dir($sectorDirectory)) {
            return $stats;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sectorDirectory));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'json') {
                continue;
            }

            $data = $this->jsonFile((string) $file->getPathname());
            if ($data === null) {
                continue;
            }

            $stats['generatedSectors']++;
            $this->addObjectStats($data['objects'] ?? [], $stats);
            $this->addDetachedContainerStats($data['detachedContainers'] ?? [], $stats);
            $this->addHiddenDetachedContainerStats($data['hiddenDetachedContainers'] ?? [], $stats);
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonFile(string $path): ?array
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param mixed $objects
     * @param array<string, mixed> $stats
     */
    private function addObjectStats(mixed $objects, array &$stats): void
    {
        if (!is_array($objects)) {
            return;
        }

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $type = (string) ($object['type'] ?? '');
            if ($type === 'black_hole') {
                $stats['blackHoles']++;
            } elseif ($type === 'asteroid') {
                foreach ($this->asteroidResourceTypes($object) as $resourceType) {
                    $stats['asteroidsByResource'][$resourceType]++;
                }
            } elseif ($type === 'manny') {
                $state = (string) ($object['state'] ?? '');
                if ($state === 'forgotten') {
                    $stats['forgottenMannies']++;
                } elseif ($state === 'abandoned') {
                    $stats['lostMannies']++;
                }
            } elseif ($type === 'solar_system') {
                $this->addSolarSystemStats($object, $stats);
            } elseif ($type === 'detached_container') {
                $this->addDetachedContainerStats([$object], $stats);
            }
        }
    }

    /**
     * @param array<string, mixed> $solarSystem
     * @param array<string, mixed> $stats
     */
    private function addSolarSystemStats(array $solarSystem, array &$stats): void
    {
        foreach ($solarSystem['orbitalBodies'] ?? [] as $body) {
            if (!is_array($body) || !is_array($body['object'] ?? null)) {
                continue;
            }
            $this->addObjectStats([$body['object']], $stats);
        }
    }

    /**
     * @param array<string, mixed> $asteroid
     * @return array<string>
     */
    private function asteroidResourceTypes(array $asteroid): array
    {
        if (is_array($asteroid['resourceAmounts'] ?? null)) {
            return array_values(array_filter(
                self::RESOURCE_TYPES,
                static fn(string $type): bool => (float) ($asteroid['resourceAmounts'][$type] ?? 0.0) > 0.0,
            ));
        }

        $composition = ResourceComposition::fromHints(
            is_array($asteroid['estimatedResources'] ?? null) ? $asteroid['estimatedResources'] : [],
        );

        return ResourceComposition::availableTypes($composition);
    }

    /**
     * @param mixed $containers
     * @param array<string, mixed> $stats
     */
    private function addDetachedContainerStats(mixed $containers, array &$stats): void
    {
        if (!is_array($containers)) {
            return;
        }

        foreach ($containers as $container) {
            if (!is_array($container)) {
                continue;
            }
            if ((string) ($container['mode'] ?? 'drifting') === 'hidden_on_asteroid') {
                $stats['hiddenContainers']++;
            } else {
                $stats['driftingContainers']++;
            }
        }
    }

    /**
     * @param mixed $containers
     * @param array<string, mixed> $stats
     */
    private function addHiddenDetachedContainerStats(mixed $containers, array &$stats): void
    {
        if (is_array($containers)) {
            $stats['hiddenContainers'] += count(array_filter($containers, 'is_array'));
        }
    }
}
