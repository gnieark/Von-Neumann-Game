<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = generateSectorOpenScadParseArguments($argv);
    if ($options['help']) {
        echo generateSectorOpenScadUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $appConfig = $factory->appConfig();
    $universePath = generateSectorOpenScadAbsolutePath(
        $root,
        $options['universePath'] ?? (string) ($appConfig['universePath'] ?? 'data/universe'),
    );

    $outputDir = generateSectorOpenScadAbsolutePath($root, $options['outputDir']);
    $outputs = [
        'generated' => generateSectorOpenScadOutputPath($root, $outputDir, $options['generatedOutput'], 'generated-sectors.scad'),
        'visited' => generateSectorOpenScadOutputPath($root, $outputDir, $options['visitedOutput'], 'visited-sectors.scad'),
        'probes' => generateSectorOpenScadOutputPath($root, $outputDir, $options['probesOutput'], 'probe-sectors.scad'),
    ];

    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: true);
    $generated = generateSectorOpenScadGeneratedCoordinates($universePath);
    $visited = generateSectorOpenScadQueryCoordinates(
        $pdo,
        'SELECT DISTINCT sector_x, sector_y, sector_z FROM visited_sectors ORDER BY sector_x ASC, sector_y ASC, sector_z ASC',
    );
    $probes = generateSectorOpenScadQueryCoordinates(
        $pdo,
        'SELECT DISTINCT sector_x, sector_y, sector_z FROM neumann_probes ORDER BY sector_x ASC, sector_y ASC, sector_z ASC',
    );

    generateSectorOpenScadWriteFile($outputs['generated'], generateSectorOpenScadContent('Generated sectors', $generated, $options['sphereDiameterMm']));
    generateSectorOpenScadWriteFile($outputs['visited'], generateSectorOpenScadContent('Visited sectors', $visited, $options['sphereDiameterMm']));
    generateSectorOpenScadWriteFile($outputs['probes'], generateSectorOpenScadContent('Sectors containing a probe', $probes, $options['sphereDiameterMm']));

    echo "OpenSCAD sector exports written.\n";
    echo '- generated sectors: ' . count($generated) . ' -> ' . $outputs['generated'] . "\n";
    echo '- visited sectors: ' . count($visited) . ' -> ' . $outputs['visited'] . "\n";
    echo '- probe sectors: ' . count($probes) . ' -> ' . $outputs['probes'] . "\n";
    exit(0);
} catch (InvalidArgumentException | JsonException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . generateSectorOpenScadUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to generate OpenSCAD sector exports: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{
 *     databaseConfig:?string,
 *     universePath:?string,
 *     outputDir:string,
 *     generatedOutput:?string,
 *     visitedOutput:?string,
 *     probesOutput:?string,
 *     sphereDiameterMm:float,
 *     help:bool
 * }
 */
function generateSectorOpenScadParseArguments(array $argv): array
{
    $options = [
        'databaseConfig' => null,
        'universePath' => null,
        'outputDir' => 'var/openscad',
        'generatedOutput' => null,
        'visitedOutput' => null,
        'probesOutput' => null,
        'sphereDiameterMm' => 12.0,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
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
        if (str_starts_with($argument, '--generated-output=')) {
            $options['generatedOutput'] = generateSectorOpenScadNonEmptyPath(substr($argument, strlen('--generated-output=')), 'generated output');
            continue;
        }
        if (str_starts_with($argument, '--visited-output=')) {
            $options['visitedOutput'] = generateSectorOpenScadNonEmptyPath(substr($argument, strlen('--visited-output=')), 'visited output');
            continue;
        }
        if (str_starts_with($argument, '--probes-output=')) {
            $options['probesOutput'] = generateSectorOpenScadNonEmptyPath(substr($argument, strlen('--probes-output=')), 'probes output');
            continue;
        }
        if (str_starts_with($argument, '--sphere-diameter-mm=')) {
            $options['sphereDiameterMm'] = generateSectorOpenScadPositiveFloat(
                substr($argument, strlen('--sphere-diameter-mm=')),
                'sphere diameter',
            );
            continue;
        }

        throw new InvalidArgumentException("Unexpected argument: {$argument}");
    }

    return $options;
}

function generateSectorOpenScadUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/generate-sector-openscad.php

Options:
  --database-config=<path>        Use another database config.
  --universe-path=<path>          Use another universe storage path.
  --output-dir=<path>             Output directory (default: var/openscad).
  --generated-output=<path>       Output path for all generated sectors.
  --visited-output=<path>         Output path for visited sectors.
  --probes-output=<path>          Output path for sectors containing a probe.
  --sphere-diameter-mm=<number>   Sphere diameter in millimeters (default: 12).
  -h, --help                      Show this help.

The generated OpenSCAD files use the sector coordinates directly as millimeters:
sector 10:0:-2 becomes translate([10, 0, -2]) sphere(d = 12).

TEXT;
}

function generateSectorOpenScadNonEmptyPath(string $value, string $label): string
{
    if ($value === '') {
        throw new InvalidArgumentException("The {$label} path cannot be empty.");
    }

    return $value;
}

function generateSectorOpenScadPositiveFloat(string $value, string $label): float
{
    if (preg_match('/\A(?:[1-9]\d*|0?\.\d*[1-9]\d*|[1-9]\d*\.\d+)\z/', $value) !== 1) {
        throw new InvalidArgumentException("Invalid {$label}; expected a positive number.");
    }

    return (float) $value;
}

/**
 * @return array<SectorCoordinates>
 */
function generateSectorOpenScadGeneratedCoordinates(string $universePath): array
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

    return generateSectorOpenScadSortedCoordinates($coordinates);
}

/**
 * @return array<SectorCoordinates>
 */
function generateSectorOpenScadQueryCoordinates(PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);
    $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
    $coordinates = [];
    foreach ($rows as $row) {
        $coordinate = new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);
        $coordinates[$coordinate->toKey()] = $coordinate;
    }

    return generateSectorOpenScadSortedCoordinates($coordinates);
}

/**
 * @param array<string, SectorCoordinates> $coordinates
 * @return array<SectorCoordinates>
 */
function generateSectorOpenScadSortedCoordinates(array $coordinates): array
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
 */
function generateSectorOpenScadContent(string $title, array $coordinates, float $sphereDiameterMm): string
{
    $diameter = generateSectorOpenScadNumber($sphereDiameterMm);
    $lines = [
        '// ' . $title,
        '// Generated by scripts/generate-sector-openscad.php at ' . gmdate('c'),
        '// Units: millimeters. One sector coordinate unit is exported as 1 mm.',
        '',
        'sphere_diameter_mm = ' . $diameter . ';',
        '',
        'module sector_marker(x, y, z) {',
        '    translate([x, y, z]) sphere(d = sphere_diameter_mm);',
        '}',
        '',
        'union() {',
    ];

    foreach ($coordinates as $coordinate) {
        $lines[] = sprintf(
            '    sector_marker(%d, %d, %d);',
            $coordinate->getX(),
            $coordinate->getY(),
            $coordinate->getZ(),
        );
    }

    $lines[] = '}';
    $lines[] = '';

    return implode("\n", $lines);
}

function generateSectorOpenScadNumber(float $value): string
{
    $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

    return $formatted !== '' ? $formatted : '0';
}

function generateSectorOpenScadWriteFile(string $path, string $content): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create output directory: ' . $directory);
    }

    $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));
    if (file_put_contents($temporaryPath, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary output file: ' . $temporaryPath);
    }
    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Unable to publish output file: ' . $path);
    }
}

function generateSectorOpenScadOutputPath(string $root, string $outputDir, ?string $configuredPath, string $defaultFilename): string
{
    if ($configuredPath !== null) {
        return generateSectorOpenScadAbsolutePath($root, $configuredPath);
    }

    return rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $defaultFilename;
}

function generateSectorOpenScadAbsolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}
