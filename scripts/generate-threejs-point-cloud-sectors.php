<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = generateSectorPointCloudParseArguments($argv);
    if ($options['help']) {
        echo generateSectorPointCloudUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $appConfig = $factory->appConfig();
    $universePath = generateSectorPointCloudAbsolutePath(
        $root,
        $options['universePath'] ?? (string) ($appConfig['universePath'] ?? 'data/universe'),
    );

    $outputDir = generateSectorPointCloudAbsolutePath($root, $options['outputDir']);
    $outputs = [
        'manifest' => generateSectorPointCloudOutputPath($root, $outputDir, $options['manifestOutput'], 'sector-point-clouds.json'),
        'editorScene' => generateSectorPointCloudOutputPath($root, $outputDir, $options['editorSceneOutput'], 'sector-point-clouds-threejs-editor.json'),
        'generated' => generateSectorPointCloudOutputPath($root, $outputDir, $options['generatedOutput'], 'generated-sectors.json'),
        'visited' => generateSectorPointCloudOutputPath($root, $outputDir, $options['visitedOutput'], 'visited-sectors.json'),
        'probes' => generateSectorPointCloudOutputPath($root, $outputDir, $options['probesOutput'], 'probe-sectors.json'),
        'scut' => generateSectorPointCloudOutputPath($root, $outputDir, $options['scutOutput'], 'scut-covered-sectors.json'),
    ];

    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: true);
    $clouds = [
        'generated' => [
            'title' => 'Generated sectors',
            'color' => '#7f8fa6',
            'coordinates' => generateSectorPointCloudGeneratedCoordinates($universePath),
        ],
        'visited' => [
            'title' => 'Visited sectors',
            'color' => '#59d185',
            'coordinates' => generateSectorPointCloudQueryCoordinates(
                $pdo,
                'SELECT DISTINCT sector_x, sector_y, sector_z FROM visited_sectors ORDER BY sector_x ASC, sector_y ASC, sector_z ASC',
            ),
        ],
        'probes' => [
            'title' => 'Sectors containing a probe',
            'color' => '#f2c94c',
            'coordinates' => generateSectorPointCloudQueryCoordinates(
                $pdo,
                'SELECT DISTINCT sector_x, sector_y, sector_z FROM neumann_probes ORDER BY sector_x ASC, sector_y ASC, sector_z ASC',
            ),
        ],
        'scut' => [
            'title' => 'SCUT-covered sectors',
            'color' => '#56ccf2',
            'coordinates' => generateSectorPointCloudQueryCoordinates(
                $pdo,
                'SELECT sector_x, sector_y, sector_z
                 FROM scut_covered_sectors
                 WHERE scut_network_id IS NOT NULL
                 GROUP BY sector_x, sector_y, sector_z
                 ORDER BY sector_x ASC, sector_y ASC, sector_z ASC',
            ),
        ],
    ];

    foreach ($clouds as $key => $cloud) {
        generateSectorPointCloudWriteJson(
            $outputs[$key],
            generateSectorPointCloudPayload(
                $key,
                $cloud['title'],
                $cloud['color'],
                $cloud['coordinates'],
                $options['scale'],
                $options['pointSize'],
            ),
            $options['pretty'],
        );
    }

    generateSectorPointCloudWriteJson(
        $outputs['manifest'],
        generateSectorPointCloudManifest($outputs, $clouds, $options['scale'], $options['pointSize']),
        $options['pretty'],
    );
    generateSectorPointCloudWriteJson(
        $outputs['editorScene'],
        generateSectorPointCloudThreeJsEditorScene($clouds, $options['scale'], $options['pointSize']),
        $options['pretty'],
    );

    echo "Three.js sector point-cloud exports written.\n";
    echo '- manifest: ' . $outputs['manifest'] . "\n";
    echo '- Three.js editor scene: ' . $outputs['editorScene'] . "\n";
    foreach (['generated', 'visited', 'probes', 'scut'] as $key) {
        echo '- ' . $clouds[$key]['title'] . ': ' . count($clouds[$key]['coordinates']) . ' -> ' . $outputs[$key] . "\n";
    }
    exit(0);
} catch (InvalidArgumentException | JsonException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . generateSectorPointCloudUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to generate Three.js sector point-cloud exports: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{
 *     databaseConfig:?string,
 *     universePath:?string,
 *     outputDir:string,
 *     manifestOutput:?string,
 *     editorSceneOutput:?string,
 *     generatedOutput:?string,
 *     visitedOutput:?string,
 *     probesOutput:?string,
 *     scutOutput:?string,
 *     scale:float,
 *     pointSize:float,
 *     pretty:bool,
 *     help:bool
 * }
 */
function generateSectorPointCloudParseArguments(array $argv): array
{
    $options = [
        'databaseConfig' => null,
        'universePath' => null,
        'outputDir' => 'var/point-clouds',
        'manifestOutput' => null,
        'editorSceneOutput' => null,
        'generatedOutput' => null,
        'visitedOutput' => null,
        'probesOutput' => null,
        'scutOutput' => null,
        'scale' => 1.0,
        'pointSize' => 1.0,
        'pretty' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($argument === '--pretty') {
            $options['pretty'] = true;
            continue;
        }
        if (str_starts_with($argument, '--database-config=')) {
            $value = substr($argument, strlen('--database-config='));
            $options['databaseConfig'] = $value !== '' ? $value : null;
            continue;
        }
        if (str_starts_with($argument, '--universe-path=')) {
            $value = substr($argument, strlen('--universe-path='));
            $options['universePath'] = $value !== '' ? $value : null;
            continue;
        }
        if (str_starts_with($argument, '--output-dir=')) {
            $value = substr($argument, strlen('--output-dir='));
            if ($value === '') {
                throw new InvalidArgumentException('Output directory cannot be empty.');
            }
            $options['outputDir'] = $value;
            continue;
        }
        if (str_starts_with($argument, '--manifest-output=')) {
            $options['manifestOutput'] = generateSectorPointCloudNonEmptyPath(substr($argument, strlen('--manifest-output=')), 'manifest output');
            continue;
        }
        if (str_starts_with($argument, '--editor-scene-output=')) {
            $options['editorSceneOutput'] = generateSectorPointCloudNonEmptyPath(substr($argument, strlen('--editor-scene-output=')), 'Three.js editor scene output');
            continue;
        }
        if (str_starts_with($argument, '--generated-output=')) {
            $options['generatedOutput'] = generateSectorPointCloudNonEmptyPath(substr($argument, strlen('--generated-output=')), 'generated output');
            continue;
        }
        if (str_starts_with($argument, '--visited-output=')) {
            $options['visitedOutput'] = generateSectorPointCloudNonEmptyPath(substr($argument, strlen('--visited-output=')), 'visited output');
            continue;
        }
        if (str_starts_with($argument, '--probes-output=')) {
            $options['probesOutput'] = generateSectorPointCloudNonEmptyPath(substr($argument, strlen('--probes-output=')), 'probes output');
            continue;
        }
        if (str_starts_with($argument, '--scut-output=')) {
            $options['scutOutput'] = generateSectorPointCloudNonEmptyPath(substr($argument, strlen('--scut-output=')), 'SCUT output');
            continue;
        }
        if (str_starts_with($argument, '--scale=')) {
            $options['scale'] = generateSectorPointCloudPositiveFloat(substr($argument, strlen('--scale=')), 'scale');
            continue;
        }
        if (str_starts_with($argument, '--point-size=')) {
            $options['pointSize'] = generateSectorPointCloudPositiveFloat(substr($argument, strlen('--point-size=')), 'point size');
            continue;
        }

        throw new InvalidArgumentException("Unexpected argument: {$argument}");
    }

    return $options;
}

function generateSectorPointCloudUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/generate-threejs-point-cloud-sectors.php
  php scripts/generate-threejs-point-cloud-sectors.php --output-dir=public/point-clouds

Options:
  --database-config=<path>        Use another database config.
  --universe-path=<path>          Use another universe storage path.
  --output-dir=<path>             Output directory (default: var/point-clouds).
  --manifest-output=<path>        Output path for the point-cloud manifest.
  --editor-scene-output=<path>    Output path for the Three.js editor scene.
  --generated-output=<path>       Output path for all generated sectors.
  --visited-output=<path>         Output path for visited sectors.
  --probes-output=<path>          Output path for sectors containing a probe.
  --scut-output=<path>            Output path for sectors covered by SCUT.
  --scale=<number>                Multiplier applied to sector coordinates (default: 1).
  --point-size=<number>           Suggested THREE.PointsMaterial size (default: 1).
  --pretty                        Pretty-print JSON output.
  -h, --help                      Show this help.

Each cloud file contains a flat "positions" array for THREE.BufferGeometry:
[x0, y0, z0, x1, y1, z1, ...]. Coordinates are absolute sector coordinates
multiplied by --scale. The manifest lists the generated files and suggested
colors.

To import directly in https://threejs.org/editor/, use the generated
sector-point-clouds-threejs-editor.json file. If the imported points are hard to
see, regenerate with for example --scale=0.05 --point-size=4, then use the
editor's frame/focus command on the imported scene.

Minimal custom Three.js usage when the data files are served from
public/point-clouds:

  import * as THREE from 'three';

  const cloud = await fetch('/point-clouds/generated-sectors.json').then((r) => r.json());
  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.Float32BufferAttribute(cloud.positions, 3));
  geometry.computeBoundingSphere();

  const material = new THREE.PointsMaterial({
    color: cloud.color || '#7f8fa6',
    size: cloud.pointSize || 1,
    sizeAttenuation: true,
  });

  scene.add(new THREE.Points(geometry, material));

TEXT;
}

function generateSectorPointCloudNonEmptyPath(string $value, string $label): string
{
    if ($value === '') {
        throw new InvalidArgumentException("The {$label} path cannot be empty.");
    }

    return $value;
}

function generateSectorPointCloudPositiveFloat(string $value, string $label): float
{
    if (preg_match('/\A(?:[1-9]\d*|0?\.\d*[1-9]\d*|[1-9]\d*\.\d+)\z/', $value) !== 1) {
        throw new InvalidArgumentException("Invalid {$label}; expected a positive number.");
    }

    return (float) $value;
}

/**
 * @return array<SectorCoordinates>
 */
function generateSectorPointCloudGeneratedCoordinates(string $universePath): array
{
    $sectorDirectory = rtrim($universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
    if (!is_dir($sectorDirectory)) {
        return [];
    }

    $coordinates = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sectorDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'json') {
            continue;
        }

        $json = file_get_contents($file->getPathname());
        if ($json === false) {
            throw new RuntimeException('Unable to read sector file: ' . $file->getPathname());
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid sector JSON root: ' . $file->getPathname());
        }

        $sector = SectorContent::fromArray($data, 'loaded');
        $coordinates[$sector->getCoordinates()->toKey()] = $sector->getCoordinates();
    }

    return generateSectorPointCloudSortedCoordinates($coordinates);
}

/**
 * @return array<SectorCoordinates>
 */
function generateSectorPointCloudQueryCoordinates(PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);
    $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
    $coordinates = [];
    foreach ($rows as $row) {
        $coordinate = new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);
        $coordinates[$coordinate->toKey()] = $coordinate;
    }

    return generateSectorPointCloudSortedCoordinates($coordinates);
}

/**
 * @param array<string, SectorCoordinates> $coordinates
 * @return array<SectorCoordinates>
 */
function generateSectorPointCloudSortedCoordinates(array $coordinates): array
{
    uasort($coordinates, static function (SectorCoordinates $a, SectorCoordinates $b): int {
        return ($a->getX() <=> $b->getX())
            ?: ($a->getY() <=> $b->getY())
            ?: ($a->getZ() <=> $b->getZ());
    });

    return array_values($coordinates);
}

/**
 * @param array<SectorCoordinates> $coordinates
 * @return array<string, mixed>
 */
function generateSectorPointCloudPayload(string $key, string $title, string $color, array $coordinates, float $scale, float $pointSize): array
{
    $positions = [];
    foreach ($coordinates as $coordinate) {
        $positions[] = generateSectorPointCloudScaledNumber($coordinate->getX(), $scale);
        $positions[] = generateSectorPointCloudScaledNumber($coordinate->getY(), $scale);
        $positions[] = generateSectorPointCloudScaledNumber($coordinate->getZ(), $scale);
    }

    return [
        'format' => 'vng-sector-point-cloud',
        'version' => 1,
        'key' => $key,
        'title' => $title,
        'generatedAt' => gmdate('c'),
        'count' => count($coordinates),
        'coordinateSystem' => [
            'source' => 'absolute_sector_coordinates',
            'scale' => $scale,
            'positionUnits' => 'sector * scale',
        ],
        'pointSize' => $pointSize,
        'color' => $color,
        'bounds' => generateSectorPointCloudBounds($coordinates, $scale),
        'positions' => $positions,
    ];
}

/**
 * @param array<string, string> $outputs
 * @param array<string, array{title:string, color:string, coordinates:array<SectorCoordinates>}> $clouds
 * @return array<string, mixed>
 */
function generateSectorPointCloudManifest(array $outputs, array $clouds, float $scale, float $pointSize): array
{
    $items = [];
    foreach (['generated', 'visited', 'probes', 'scut'] as $key) {
        $items[] = [
            'key' => $key,
            'title' => $clouds[$key]['title'],
            'file' => basename($outputs[$key]),
            'count' => count($clouds[$key]['coordinates']),
            'color' => $clouds[$key]['color'],
            'pointSize' => $pointSize,
        ];
    }

    return [
        'format' => 'vng-sector-point-cloud-manifest',
        'version' => 1,
        'generatedAt' => gmdate('c'),
        'coordinateSystem' => [
            'source' => 'absolute_sector_coordinates',
            'scale' => $scale,
            'positionUnits' => 'sector * scale',
        ],
        'clouds' => $items,
    ];
}

/**
 * @param array<string, array{title:string, color:string, coordinates:array<SectorCoordinates>}> $clouds
 * @return array<string, mixed>
 */
function generateSectorPointCloudThreeJsEditorScene(array $clouds, float $scale, float $pointSize): array
{
    $geometries = [];
    $materials = [];
    $children = [];
    foreach (['generated', 'visited', 'probes', 'scut'] as $key) {
        $geometryUuid = generateSectorPointCloudUuid('geometry|' . $key);
        $materialUuid = generateSectorPointCloudUuid('material|' . $key);
        $positions = generateSectorPointCloudPositions($clouds[$key]['coordinates'], $scale);
        $geometryData = [
            'attributes' => [
                'position' => [
                    'itemSize' => 3,
                    'type' => 'Float32Array',
                    'array' => $positions,
                    'normalized' => false,
                ],
            ],
        ];
        $boundingSphere = generateSectorPointCloudBoundingSphere($clouds[$key]['coordinates'], $scale);
        if ($boundingSphere !== null) {
            $geometryData['boundingSphere'] = $boundingSphere;
        }
        $geometries[] = [
            'uuid' => $geometryUuid,
            'type' => 'BufferGeometry',
            'name' => $clouds[$key]['title'] . ' geometry',
            'data' => $geometryData,
        ];
        $materials[] = [
            'uuid' => $materialUuid,
            'type' => 'PointsMaterial',
            'name' => $clouds[$key]['title'] . ' material',
            'color' => generateSectorPointCloudColorInteger($clouds[$key]['color']),
            'size' => $pointSize,
            'sizeAttenuation' => true,
        ];
        $children[] = [
            'uuid' => generateSectorPointCloudUuid('object|' . $key),
            'type' => 'Points',
            'name' => $clouds[$key]['title'],
            'geometry' => $geometryUuid,
            'material' => $materialUuid,
            'userData' => [
                'count' => count($clouds[$key]['coordinates']),
                'source' => $key,
            ],
        ];
    }

    return [
        'metadata' => [
            'version' => 4.7,
            'type' => 'Object',
            'generator' => 'scripts/generate-threejs-point-cloud-sectors.php',
        ],
        'geometries' => $geometries,
        'materials' => $materials,
        'object' => [
            'uuid' => generateSectorPointCloudUuid('scene|sector-point-clouds'),
            'type' => 'Scene',
            'name' => 'VNG sector point clouds',
            'userData' => [
                'format' => 'vng-sector-point-cloud-threejs-editor',
                'version' => 1,
                'generatedAt' => gmdate('c'),
                'coordinateSystem' => [
                    'source' => 'absolute_sector_coordinates',
                    'scale' => $scale,
                    'positionUnits' => 'sector * scale',
                ],
            ],
            'children' => $children,
        ],
    ];
}

/**
 * @param array<SectorCoordinates> $coordinates
 * @return array{min:array{float|int, float|int, float|int}, max:array{float|int, float|int, float|int}}|null
 */
function generateSectorPointCloudBounds(array $coordinates, float $scale): ?array
{
    if ($coordinates === []) {
        return null;
    }

    $minX = $maxX = $coordinates[0]->getX();
    $minY = $maxY = $coordinates[0]->getY();
    $minZ = $maxZ = $coordinates[0]->getZ();

    foreach ($coordinates as $coordinate) {
        $minX = min($minX, $coordinate->getX());
        $maxX = max($maxX, $coordinate->getX());
        $minY = min($minY, $coordinate->getY());
        $maxY = max($maxY, $coordinate->getY());
        $minZ = min($minZ, $coordinate->getZ());
        $maxZ = max($maxZ, $coordinate->getZ());
    }

    return [
        'min' => [
            generateSectorPointCloudScaledNumber($minX, $scale),
            generateSectorPointCloudScaledNumber($minY, $scale),
            generateSectorPointCloudScaledNumber($minZ, $scale),
        ],
        'max' => [
            generateSectorPointCloudScaledNumber($maxX, $scale),
            generateSectorPointCloudScaledNumber($maxY, $scale),
            generateSectorPointCloudScaledNumber($maxZ, $scale),
        ],
    ];
}

function generateSectorPointCloudScaledNumber(int $value, float $scale): float|int
{
    $scaled = $value * $scale;

    return floor($scaled) === $scaled ? (int) $scaled : $scaled;
}

/**
 * @param array<SectorCoordinates> $coordinates
 * @return array<int, float|int>
 */
function generateSectorPointCloudPositions(array $coordinates, float $scale): array
{
    $positions = [];
    foreach ($coordinates as $coordinate) {
        $positions[] = generateSectorPointCloudScaledNumber($coordinate->getX(), $scale);
        $positions[] = generateSectorPointCloudScaledNumber($coordinate->getY(), $scale);
        $positions[] = generateSectorPointCloudScaledNumber($coordinate->getZ(), $scale);
    }

    return $positions;
}

/**
 * @param array<SectorCoordinates> $coordinates
 * @return array{center:array{float|int, float|int, float|int}, radius:float}|null
 */
function generateSectorPointCloudBoundingSphere(array $coordinates, float $scale): ?array
{
    $bounds = generateSectorPointCloudBounds($coordinates, $scale);
    if ($bounds === null) {
        return null;
    }

    $center = [
        ($bounds['min'][0] + $bounds['max'][0]) / 2,
        ($bounds['min'][1] + $bounds['max'][1]) / 2,
        ($bounds['min'][2] + $bounds['max'][2]) / 2,
    ];
    $radius = 0.0;
    foreach ($coordinates as $coordinate) {
        $x = generateSectorPointCloudScaledNumber($coordinate->getX(), $scale);
        $y = generateSectorPointCloudScaledNumber($coordinate->getY(), $scale);
        $z = generateSectorPointCloudScaledNumber($coordinate->getZ(), $scale);
        $radius = max($radius, sqrt(($x - $center[0]) ** 2 + ($y - $center[1]) ** 2 + ($z - $center[2]) ** 2));
    }

    return [
        'center' => [
            generateSectorPointCloudWholeNumberAsInt($center[0]),
            generateSectorPointCloudWholeNumberAsInt($center[1]),
            generateSectorPointCloudWholeNumberAsInt($center[2]),
        ],
        'radius' => $radius,
    ];
}

function generateSectorPointCloudWholeNumberAsInt(float|int $value): float|int
{
    return floor($value) === (float) $value ? (int) $value : $value;
}

function generateSectorPointCloudColorInteger(string $hexColor): int
{
    if (preg_match('/\A#[0-9a-fA-F]{6}\z/', $hexColor) !== 1) {
        throw new RuntimeException('Invalid Three.js material color: ' . $hexColor);
    }

    return hexdec(substr($hexColor, 1));
}

function generateSectorPointCloudUuid(string $seed): string
{
    $hash = sha1('vng-sector-point-cloud|' . $seed);

    return sprintf(
        '%s-%s-4%s-%s%s-%s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        substr($hash, 13, 3),
        dechex((hexdec($hash[16]) & 0x3) | 0x8),
        substr($hash, 17, 3),
        substr($hash, 20, 12),
    );
}

/**
 * @param array<string, mixed> $payload
 */
function generateSectorPointCloudWriteJson(string $path, array $payload, bool $pretty): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create output directory: ' . $directory);
    }

    $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    $json = json_encode($payload, $flags) . "\n";

    $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));
    if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary output file: ' . $temporaryPath);
    }
    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Unable to publish output file: ' . $path);
    }
}

function generateSectorPointCloudOutputPath(string $root, string $outputDir, ?string $configuredPath, string $defaultFilename): string
{
    if ($configuredPath !== null) {
        return generateSectorPointCloudAbsolutePath($root, $configuredPath);
    }

    return rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $defaultFilename;
}

function generateSectorPointCloudAbsolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}
