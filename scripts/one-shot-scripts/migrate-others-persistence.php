<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

$config = null;
$apply = false;
$root = dirname(__DIR__, 2);
$app = json_decode(file_get_contents($root . '/config/app.json'), true, 512, JSON_THROW_ON_ERROR);
$universe = $app['universePath'] ?? 'data/universe';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') { $apply = true; }
    elseif ($argument === '--dry-run') { $apply = false; }
    elseif (str_starts_with($argument, '--database-config=')) { $config = substr($argument, 18); }
    elseif (str_starts_with($argument, '--universe-path=')) { $universe = substr($argument, 16); }
    else { fwrite(STDERR, "Usage: migrate-others-persistence.php [--database-config=PATH] [--universe-path=PATH] [--dry-run|--apply]\n"); exit(2); }
}
try {
    $pdo = (new \VonNeumannGame\AppFactory($root))->pdo($config, initializeSchema: false);
    $sectors = new \VonNeumannGame\Sector\SectorService(new \VonNeumannGame\Sector\SectorFileRepository(str_starts_with($universe, '/') ? $universe : $root . '/' . $universe), new \VonNeumannGame\Sector\SectorContentGenerator(), 'migration');
    $result = (new \VonNeumannGame\Database\Migration\OthersPersistenceMigration($pdo, $sectors))->run($apply);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
