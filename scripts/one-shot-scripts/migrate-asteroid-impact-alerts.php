<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
        continue;
    }
    if ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-asteroid-impact-alerts.php [--database-config=PATH]\n";
        exit(0);
    }

    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
}

try {
    $factory = new AppFactory(dirname(__DIR__, 2));
    $pdo = $factory->pdo($databaseConfig, initializeSchema: false);
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['sqlite', 'mysql'], true)) {
        throw new RuntimeException("Unsupported database driver: {$driver}");
    }
    if (!asteroidTrajectoriesTableExists($pdo, $driver)) {
        throw new RuntimeException('Table asteroid_trajectories does not exist; run the asteroid trajectory migration first.');
    }

    $added = false;
    if (!asteroidLauncherColumnExists($pdo, $driver)) {
        $definition = $driver === 'mysql' ? 'INTEGER NULL AFTER direction_z' : 'INTEGER NULL';
        $pdo->exec('ALTER TABLE asteroid_trajectories ADD COLUMN launcher_probe_id ' . $definition);
        $added = true;
    }
    if (!asteroidLauncherColumnExists($pdo, $driver)) {
        throw new RuntimeException('Column launcher_probe_id is still missing after migration.');
    }

    $existing = (int) $pdo->query('SELECT COUNT(*) FROM asteroid_trajectories WHERE launcher_probe_id IS NULL')->fetchColumn();
    printf(
        "Asteroid impact alert schema ready: column_added=%s existing_trajectories_without_launcher=%d\n",
        $added ? 'yes' : 'no',
        $existing,
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'Asteroid impact alert migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}

function asteroidTrajectoriesTableExists(PDO $pdo, string $driver): bool
{
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table_name");
        $stmt->execute(['table_name' => 'asteroid_trajectories']);

        return (int) $stmt->fetchColumn() > 0;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name');
    $stmt->execute(['table_name' => 'asteroid_trajectories']);

    return (int) $stmt->fetchColumn() > 0;
}

function asteroidLauncherColumnExists(PDO $pdo, string $driver): bool
{
    if ($driver === 'sqlite') {
        $columns = $pdo->query('PRAGMA table_info(asteroid_trajectories)')->fetchAll(PDO::FETCH_ASSOC);

        return in_array('launcher_probe_id', array_column($columns, 'name'), true);
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM asteroid_trajectories WHERE Field = 'launcher_probe_id'");

    return $stmt !== false && is_array($stmt->fetch(PDO::FETCH_ASSOC));
}
