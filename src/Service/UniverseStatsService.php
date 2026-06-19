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
    private const HABITABLE_PLANET_THRESHOLD = 0.35;

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
        $visitedSectorKeys = $this->visitedSectorKeys();
        $sectorStats = $this->sectorStats($visitedSectorKeys);
        $probeDistances = $this->probeDistances($probeRows);
        $waypointStats = $this->waypointStats();
        $intelligentLifeStats = $this->intelligentLifeDiscoveryStats();

        return [
            'generatedAt' => gmdate('c'),
            'metrics' => [
                'probesInUniverse' => count($probeRows),
                'generatedSectors' => $sectorStats['generatedSectors'],
                'visitedSectors' => count($visitedSectorKeys),
                'habitablePlanetsInGeneratedSectors' => $sectorStats['habitablePlanetsInGeneratedSectors'],
                'habitablePlanetsInVisitedSectors' => $sectorStats['habitablePlanetsInVisitedSectors'],
                'blackHoles' => $sectorStats['blackHoles'],
                'asteroidsByResource' => $sectorStats['asteroidsByResource'],
                'lostMannies' => $sectorStats['lostMannies'],
                'forgottenMannies' => $sectorStats['forgottenMannies'],
                'driftingContainers' => $sectorStats['driftingContainers'],
                'hiddenContainers' => $sectorStats['hiddenContainers'],
                'furthestProbeDistance' => $probeDistances['furthest'],
                'closestProbeDistance' => $probeDistances['closest'],
                'waypointBookmarksInstalled' => $waypointStats['installed'],
                'intelligentLifeWorlds' => $intelligentLifeStats['worlds'],
                'successfulMissions' => 0,
                'failedMissions' => 0,
                'topVisitedProbes' => $this->topVisitedProbes(),
                'topWaypointPlayers' => $waypointStats['topPlayers'],
                'topIntelligentLifeDiscoverers' => $intelligentLifeStats['topDiscoverers'],
            ],
        ];
    }

    /**
     * @return array<int, array{x: int, y: int, z: int}>
     */
    private function probeRows(): array
    {
        $stmt = $this->pdo->query('SELECT sector_x, sector_y, sector_z FROM neumann_probes WHERE exclude_from_stats = 0 ORDER BY id ASC');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => [
            'x' => (int) $row['sector_x'],
            'y' => (int) $row['sector_y'],
            'z' => (int) $row['sector_z'],
        ], $rows);
    }

    /**
     * @return array<string, true>
     */
    private function visitedSectorKeys(): array
    {
        $stmt = $this->pdo->query(
            'SELECT visited_sectors.sector_x, visited_sectors.sector_y, visited_sectors.sector_z
             FROM visited_sectors
             INNER JOIN neumann_probes ON neumann_probes.player_id = visited_sectors.player_id
             WHERE neumann_probes.exclude_from_stats = 0
             GROUP BY visited_sectors.sector_x, visited_sectors.sector_y, visited_sectors.sector_z'
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $keys = [];
        foreach ($rows as $row) {
            $keys[$this->sectorKey((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z'])] = true;
        }

        return $keys;
    }

    /**
     * @return array<int, array{rank: int, probeName: string, visitedSectors: int}>
     */
    private function topVisitedProbes(): array
    {
        $stmt = $this->pdo->query(
            'SELECT neumann_probes.name AS probe_name, COUNT(visited_sectors.id) AS visited_count
             FROM neumann_probes
             LEFT JOIN visited_sectors ON visited_sectors.player_id = neumann_probes.player_id
             WHERE neumann_probes.exclude_from_stats = 0
             GROUP BY neumann_probes.id, neumann_probes.name
             ORDER BY visited_count DESC, neumann_probes.name ASC, neumann_probes.id ASC
             LIMIT 3'
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row, int $index): array => [
            'rank' => $index + 1,
            'probeName' => (string) $row['probe_name'],
            'visitedSectors' => max(0, (int) $row['visited_count']),
        ], $rows, array_keys($rows));
    }

    /**
     * @return array{installed: int, topPlayers: array<int, array{rank: int, playerName: string, waypointBookmarks: int}>}
     */
    private function waypointStats(): array
    {
        $stats = [
            'installed' => 0,
            'players' => [],
        ];
        $excludedPlayerIds = $this->excludedStatsPlayerIds();
        $sectorDirectory = rtrim($this->universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
        if (!is_dir($sectorDirectory)) {
            return ['installed' => 0, 'topPlayers' => []];
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

            $this->addWaypointObjectStats($data['objects'] ?? [], $stats, $excludedPlayerIds);
            $this->addWaypointObjectStats($data['detachedContainers'] ?? [], $stats, $excludedPlayerIds);
            $this->addWaypointObjectStats($data['hiddenDetachedContainers'] ?? [], $stats, $excludedPlayerIds);
        }

        uasort($stats['players'], static fn(array $a, array $b): int => (
            ($b['waypointBookmarks'] <=> $a['waypointBookmarks'])
            ?: strcasecmp($a['playerName'], $b['playerName'])
        ));

        $rank = 1;
        $topPlayers = [];
        foreach (array_slice($stats['players'], 0, 3) as $player) {
            $topPlayers[] = [
                'rank' => $rank++,
                'playerName' => $player['playerName'],
                'waypointBookmarks' => $player['waypointBookmarks'],
            ];
        }

        return ['installed' => $stats['installed'], 'topPlayers' => $topPlayers];
    }

    /**
     * @return array{worlds: int, topDiscoverers: array<int, array{rank: int, playerName: string, intelligentLifeWorlds: int}>}
     */
    private function intelligentLifeDiscoveryStats(): array
    {
        $stats = [
            'worlds' => 0,
            'players' => [],
        ];
        $discoverersBySector = $this->firstDiscoverersBySector();
        $sectorDirectory = rtrim($this->universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
        if (!is_dir($sectorDirectory)) {
            return ['worlds' => 0, 'topDiscoverers' => []];
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

            $sectorKey = $this->sectorKeyFromData($data);
            if ($sectorKey === null || !isset($discoverersBySector[$sectorKey])) {
                continue;
            }

            $worlds = $this->intelligentLifeWorldCount($data['objects'] ?? []);
            if ($worlds <= 0) {
                continue;
            }

            $discoverer = $discoverersBySector[$sectorKey];
            $playerKey = 'id:' . $discoverer['playerId'];
            if (!isset($stats['players'][$playerKey])) {
                $stats['players'][$playerKey] = [
                    'playerName' => $discoverer['playerName'],
                    'intelligentLifeWorlds' => 0,
                ];
            }
            $stats['worlds'] += $worlds;
            $stats['players'][$playerKey]['intelligentLifeWorlds'] += $worlds;
        }

        uasort($stats['players'], static fn(array $a, array $b): int => (
            ($b['intelligentLifeWorlds'] <=> $a['intelligentLifeWorlds'])
            ?: strcasecmp($a['playerName'], $b['playerName'])
        ));

        $rank = 1;
        $topDiscoverers = [];
        foreach (array_slice($stats['players'], 0, 3) as $player) {
            $topDiscoverers[] = [
                'rank' => $rank++,
                'playerName' => $player['playerName'],
                'intelligentLifeWorlds' => $player['intelligentLifeWorlds'],
            ];
        }

        return ['worlds' => $stats['worlds'], 'topDiscoverers' => $topDiscoverers];
    }

    /**
     * @return array<string, array{playerId: int, playerName: string}>
     */
    private function firstDiscoverersBySector(): array
    {
        $excludedPlayerIds = $this->excludedStatsPlayerIds();
        $stmt = $this->pdo->query(
            "SELECT visited_sectors.sector_x, visited_sectors.sector_y, visited_sectors.sector_z,
                    visited_sectors.player_id, players.username, players.display_name
             FROM visited_sectors
             INNER JOIN players ON players.id = visited_sectors.player_id
             ORDER BY visited_sectors.sector_x ASC, visited_sectors.sector_y ASC, visited_sectors.sector_z ASC,
                      visited_sectors.first_visited_at ASC, visited_sectors.id ASC"
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $discoverers = [];
        foreach ($rows as $row) {
            $playerId = (int) $row['player_id'];
            if (isset($excludedPlayerIds[$playerId])) {
                continue;
            }

            $sectorKey = $this->sectorKey((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);
            if (isset($discoverers[$sectorKey])) {
                continue;
            }

            $displayName = trim((string) ($row['display_name'] ?? ''));
            $username = trim((string) ($row['username'] ?? ''));
            $discoverers[$sectorKey] = [
                'playerId' => $playerId,
                'playerName' => $displayName !== '' ? $displayName : ($username !== '' ? $username : 'Unknown player'),
            ];
        }

        return $discoverers;
    }

    /**
     * @return array<int, true>
     */
    private function excludedStatsPlayerIds(): array
    {
        $stmt = $this->pdo->query('SELECT player_id FROM neumann_probes WHERE exclude_from_stats = 1');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_COLUMN);
        $excluded = [];
        foreach ($rows as $playerId) {
            $excluded[(int) $playerId] = true;
        }

        return $excluded;
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
     * @param array<string, true> $visitedSectorKeys
     * @return array{
     *     generatedSectors: int,
     *     habitablePlanetsInGeneratedSectors: int,
     *     habitablePlanetsInVisitedSectors: int,
     *     blackHoles: int,
     *     asteroidsByResource: array<string, int>,
     *     lostMannies: int,
     *     forgottenMannies: int,
     *     driftingContainers: int,
     *     hiddenContainers: int
     * }
     */
    private function sectorStats(array $visitedSectorKeys): array
    {
        $stats = [
            'generatedSectors' => 0,
            'habitablePlanetsInGeneratedSectors' => 0,
            'habitablePlanetsInVisitedSectors' => 0,
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
            $habitablePlanets = $this->habitablePlanetCount($data['objects'] ?? []);
            $stats['habitablePlanetsInGeneratedSectors'] += $habitablePlanets;
            $sectorKey = $this->sectorKeyFromData($data);
            if ($sectorKey !== null && isset($visitedSectorKeys[$sectorKey])) {
                $stats['habitablePlanetsInVisitedSectors'] += $habitablePlanets;
            }
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
     * @param mixed $objects
     * @param array{installed: int, players: array<string, array{playerName: string, waypointBookmarks: int}>} $stats
     * @param array<int, true> $excludedPlayerIds
     */
    private function addWaypointObjectStats(mixed $objects, array &$stats, array $excludedPlayerIds): void
    {
        if (!is_array($objects)) {
            return;
        }

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $this->addWaypointBookmarks($object['waypointBookmarks'] ?? [], $stats, $excludedPlayerIds);
            if ((string) ($object['type'] ?? '') !== 'solar_system') {
                continue;
            }

            $this->addWaypointObjectStats([$object['primaryStar'] ?? null], $stats, $excludedPlayerIds);
            if (is_array($object['secondaryStar'] ?? null)) {
                $this->addWaypointObjectStats([$object['secondaryStar']], $stats, $excludedPlayerIds);
            }
            foreach ($object['orbitalBodies'] ?? [] as $body) {
                if (is_array($body) && is_array($body['object'] ?? null)) {
                    $this->addWaypointObjectStats([$body['object']], $stats, $excludedPlayerIds);
                }
            }
        }
    }

    /**
     * @param mixed $bookmarks
     * @param array{installed: int, players: array<string, array{playerName: string, waypointBookmarks: int}>} $stats
     * @param array<int, true> $excludedPlayerIds
     */
    private function addWaypointBookmarks(mixed $bookmarks, array &$stats, array $excludedPlayerIds): void
    {
        if (!is_array($bookmarks)) {
            return;
        }

        foreach ($bookmarks as $bookmark) {
            if (!is_array($bookmark)) {
                continue;
            }
            $playerId = isset($bookmark['playerId']) ? (int) $bookmark['playerId'] : null;
            if ($playerId !== null && isset($excludedPlayerIds[$playerId])) {
                continue;
            }

            $playerName = trim((string) ($bookmark['playerName'] ?? ''));
            if ($playerName === '') {
                $playerName = 'Unknown player';
            }
            $key = $playerId !== null ? 'id:' . $playerId : 'name:' . strtolower($playerName);
            if (!isset($stats['players'][$key])) {
                $stats['players'][$key] = [
                    'playerName' => $playerName,
                    'waypointBookmarks' => 0,
                ];
            }

            $stats['installed']++;
            $stats['players'][$key]['waypointBookmarks']++;
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

    /**
     * @param mixed $objects
     */
    private function habitablePlanetCount(mixed $objects): int
    {
        if (!is_array($objects)) {
            return 0;
        }

        $count = 0;
        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $type = (string) ($object['type'] ?? '');
            if ($type === 'planet' && $this->isHabitablePlanet($object)) {
                $count++;
            } elseif ($type === 'solar_system') {
                foreach ($object['orbitalBodies'] ?? [] as $body) {
                    if (is_array($body) && is_array($body['object'] ?? null)) {
                        $count += $this->habitablePlanetCount([$body['object']]);
                    }
                }
            }
        }

        return $count;
    }

    /**
     * @param mixed $objects
     */
    private function intelligentLifeWorldCount(mixed $objects): int
    {
        if (!is_array($objects)) {
            return 0;
        }

        $count = 0;
        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $type = (string) ($object['type'] ?? '');
            if ($type === 'planet' && (bool) ($object['intelligentLife'] ?? false)) {
                $count++;
            } elseif ($type === 'solar_system') {
                foreach ($object['orbitalBodies'] ?? [] as $body) {
                    if (is_array($body) && is_array($body['object'] ?? null)) {
                        $count += $this->intelligentLifeWorldCount([$body['object']]);
                    }
                }
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $planet
     */
    private function isHabitablePlanet(array $planet): bool
    {
        return (float) ($planet['habitabilityScore'] ?? 0.0) >= self::HABITABLE_PLANET_THRESHOLD;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sectorKeyFromData(array $data): ?string
    {
        $coordinates = $data['coordinates'] ?? null;
        if (!is_array($coordinates)) {
            return null;
        }

        return $this->sectorKey(
            (int) ($coordinates['x'] ?? 0),
            (int) ($coordinates['y'] ?? 0),
            (int) ($coordinates['z'] ?? 0),
        );
    }

    private function sectorKey(int $x, int $y, int $z): string
    {
        return $x . ':' . $y . ':' . $z;
    }
}
