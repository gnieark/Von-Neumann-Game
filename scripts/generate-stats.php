<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Service\UniverseStatsService;

require_once __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$outputPath = $projectRoot . '/var/stats.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $outputPath = (string) substr($argument, strlen('--output='));
    }
}

$factory = new AppFactory($projectRoot);
$appConfig = $factory->appConfig();
$universePath = absolutePath($projectRoot, (string) ($appConfig['universePath'] ?? 'data/universe'));
$stats = (new UniverseStatsService($factory->pdo(initializeSchema: true), $universePath))->collect();

$directory = dirname($outputPath);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    fwrite(STDERR, "Unable to create stats directory: {$directory}\n");
    exit(1);
}

$json = json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$temporaryPath = $outputPath . '.tmp.' . bin2hex(random_bytes(6));
if (file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write temporary stats file: {$temporaryPath}\n");
    exit(1);
}
if (!rename($temporaryPath, $outputPath)) {
    @unlink($temporaryPath);
    fwrite(STDERR, "Unable to publish stats file: {$outputPath}\n");
    exit(1);
}

echo sprintf("[%s] stats written to %s\n", gmdate('c'), $outputPath);

/**
 * @param string $projectRoot
 * @param string $path
 */
function absolutePath(string $projectRoot, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}
