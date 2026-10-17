<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\Migration\AsteroidSnapshotDescriptorMigration;

$usage = "Usage: php scripts/one-shot-scripts/migrate-asteroid-snapshot-descriptors.php --database-config=PATH [--apply --backup=NEW_FILE | --dry-run | --verify]\nMaintenance only, after stock import. Removes stocks from active trajectory snapshots; never imports or modifies resource quantities. Dry run by default.\n";
$config = $backup = null;
$mode = 'dry-run';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=') && $config === null) { $config = substr($argument, 18); }
    elseif (str_starts_with($argument, '--backup=') && $backup === null) { $backup = substr($argument, 9); }
    elseif (in_array($argument, ['--apply', '--dry-run', '--verify'], true)) { $mode = substr($argument, 2); }
    elseif ($argument === '--help') { echo $usage; exit(0); }
    else { fwrite(STDERR, $usage); exit(2); }
}
if (!$config) { fwrite(STDERR, $usage); exit(2); }
try {
    $databaseConfig = DatabaseConfig::fromFile($config);
    if ($databaseConfig->driver === 'sqlite') {
        $databasePath = $databaseConfig->path ?? '';
        $databasePath = str_starts_with($databasePath, '/') ? $databasePath : dirname(__DIR__, 2) . '/' . $databasePath;
        if (!is_file($databasePath)) { throw new RuntimeException('The existing SQLite database is required.'); }
    }
    $pdo = (new DatabaseConnectionFactory($databaseConfig, dirname(__DIR__, 2)))->create();
    $result = (new AsteroidSnapshotDescriptorMigration($pdo))->run($mode === 'apply', $backup);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    exit($mode === 'verify' && $result['snapshotsToConvert'] !== 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Snapshot conversion failed.\n" : $error->getMessage() . "\n");
    exit(1);
}
