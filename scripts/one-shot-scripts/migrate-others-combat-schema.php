<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-others-combat-schema.php [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$factory = new AppFactory(dirname(__DIR__, 2));
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

$columns = static function (PDO $pdo, string $table) use ($driver): array {
    if ($driver === 'sqlite') {
        return array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
    return array_column($pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_ASSOC), 'Field');
};

$pdo->beginTransaction();
try {
    if (!in_array('missile_hits', $columns($pdo, 'asteroid_trajectories'), true)) {
        $pdo->exec('ALTER TABLE asteroid_trajectories ADD COLUMN missile_hits INTEGER NOT NULL DEFAULT 0');
    }

    $projectileColumns = $columns($pdo, 'others_projectiles');
    if ($projectileColumns !== [] && !in_array('launch_id', $projectileColumns, true)) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM others_projectiles')->fetchColumn();
        if ($count !== 0) {
            throw new RuntimeException('Legacy Others projectiles exist. Resolve or archive them explicitly before this canonical combat migration.');
        }
        if ($driver === 'sqlite') {
            $pdo->exec('DROP TABLE others_projectiles');
        } else {
            $pdo->exec('ALTER TABLE others_projectiles ADD COLUMN launch_id INTEGER NULL AFTER public_id');
            $pdo->exec('ALTER TABLE others_projectiles MODIFY action_id INTEGER NULL');
            $pdo->exec('ALTER TABLE others_projectiles MODIFY launch_id INTEGER NOT NULL');
            $pdo->exec('CREATE UNIQUE INDEX idx_others_projectiles_launch ON others_projectiles(launch_id)');
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$factory->databaseFactory($databaseConfig)->initializeSchema($pdo);
echo "Others combat and shared missile schema is ready.\n";
