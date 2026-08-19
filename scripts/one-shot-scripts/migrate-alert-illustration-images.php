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
        echo "Usage: php scripts/one-shot-scripts/migrate-alert-illustration-images.php [--database-config=PATH]\n";
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
    if (!alertIllustrationTableExists($pdo, $driver)) {
        throw new RuntimeException('Table probe_damage_warnings does not exist; initialize the base schema before this migration.');
    }

    $existingAlerts = (int) $pdo->query('SELECT COUNT(*) FROM probe_damage_warnings')->fetchColumn();
    $added = false;
    if (!alertIllustrationColumnExists($pdo, $driver)) {
        $definition = $driver === 'mysql'
            ? 'VARCHAR(2048) NULL AFTER message'
            : 'TEXT NULL';
        $pdo->exec('ALTER TABLE probe_damage_warnings ADD COLUMN illustration_image_url ' . $definition);
        $added = true;
    }
    if (!alertIllustrationColumnExists($pdo, $driver)) {
        throw new RuntimeException('Column illustration_image_url is still missing after migration.');
    }

    printf(
        "Alert illustration schema ready: column_added=%s existing_alerts=%d\n",
        $added ? 'yes' : 'no',
        $existingAlerts,
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'Alert illustration migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}

function alertIllustrationTableExists(PDO $pdo, string $driver): bool
{
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table_name");
        $stmt->execute(['table_name' => 'probe_damage_warnings']);

        return (int) $stmt->fetchColumn() > 0;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => 'probe_damage_warnings']);

    return (int) $stmt->fetchColumn() > 0;
}

function alertIllustrationColumnExists(PDO $pdo, string $driver): bool
{
    if ($driver === 'sqlite') {
        $columns = $pdo->query('PRAGMA table_info(probe_damage_warnings)')->fetchAll(PDO::FETCH_ASSOC);

        return in_array('illustration_image_url', array_column($columns, 'name'), true);
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM probe_damage_warnings WHERE Field = 'illustration_image_url'");

    return $stmt !== false && is_array($stmt->fetch(PDO::FETCH_ASSOC));
}
