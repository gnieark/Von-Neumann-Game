<?php

declare(strict_types=1);

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(migrateProbeImprovementsRun($argv));
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . migrateProbeImprovementsUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to migrate probe improvements: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 */
function migrateProbeImprovementsRun(array $argv): int
{
    $options = migrateProbeImprovementsParseArguments($argv);
    if ($options['help']) {
        echo migrateProbeImprovementsUsage();

        return 0;
    }

    $root = dirname(__DIR__);
    $configPath = migrateProbeImprovementsAbsolutePath($root, $options['databaseConfig'] ?? 'config/database.json');
    $config = DatabaseConfig::fromFile($configPath);
    $pdo = (new DatabaseConnectionFactory($config, $root))->create();

    migrateProbeImprovementsEnsureSchema($pdo, $config->driver);
    $hasLegacyTable = migrateProbeImprovementsTableExists($pdo, $config->driver, 'probe_improvements');
    if (!$hasLegacyTable) {
        echo "Probe improvements already use split tables; no legacy probe_improvements table found.\n";

        return 0;
    }

    $orphanCount = migrateProbeImprovementsCount(
        $pdo,
        'SELECT COUNT(*)
         FROM probe_improvements pi
         LEFT JOIN neumann_probes np ON np.id = pi.probe_id
         WHERE np.player_id IS NULL'
    );
    if ($orphanCount > 0) {
        throw new RuntimeException("Found {$orphanCount} legacy improvement row(s) whose probe has no player; aborting migration.");
    }

    $legacyRows = migrateProbeImprovementsCount($pdo, 'SELECT COUNT(*) FROM probe_improvements');
    $blueprintRows = migrateProbeImprovementsCount(
        $pdo,
        'SELECT COUNT(*)
         FROM (
             SELECT np.player_id, pi.improvement
             FROM probe_improvements pi
             INNER JOIN neumann_probes np ON np.id = pi.probe_id
             WHERE pi.available = 1 OR pi.done = 1
             GROUP BY np.player_id, pi.improvement
         ) migrated_rows'
    );
    $installationRows = migrateProbeImprovementsCount($pdo, 'SELECT COUNT(*) FROM probe_improvements WHERE done = 1');

    if ($options['dryRun']) {
        echo "Dry-run: {$legacyRows} legacy improvement row(s) can be migrated.\n";
        echo "- player blueprints: {$blueprintRows}\n";
        echo "- probe installations: {$installationRows}\n";

        return 0;
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        migrateProbeImprovementsCopyBlueprints($pdo, $config->driver);
        migrateProbeImprovementsCopyInstallations($pdo, $config->driver);
        if (!$options['keepLegacyTable']) {
            $pdo->exec('DROP TABLE probe_improvements');
        }
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    echo "Probe improvements migrated to split tables.\n";
    echo "- legacy rows scanned: {$legacyRows}\n";
    echo "- player blueprints: {$blueprintRows}\n";
    echo "- probe installations: {$installationRows}\n";
    echo '- legacy table: ' . ($options['keepLegacyTable'] ? 'kept' : 'dropped') . "\n";

    return 0;
}

/**
 * @param array<int, string> $argv
 * @return array{databaseConfig:?string,dryRun:bool,keepLegacyTable:bool,help:bool}
 */
function migrateProbeImprovementsParseArguments(array $argv): array
{
    $options = [
        'databaseConfig' => null,
        'dryRun' => false,
        'keepLegacyTable' => false,
        'help' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }
        if ($arg === '--keep-legacy-table') {
            $options['keepLegacyTable'] = true;
            continue;
        }
        if ($arg === '--database-config') {
            $options['databaseConfig'] = $argv[++$i] ?? throw new InvalidArgumentException('Missing value for --database-config.');
            continue;
        }
        if (str_starts_with($arg, '--database-config=')) {
            $options['databaseConfig'] = substr($arg, strlen('--database-config='));
            continue;
        }

        throw new InvalidArgumentException('Unknown argument: ' . $arg);
    }

    return $options;
}

function migrateProbeImprovementsUsage(): string
{
    return <<<TXT
Usage:
  php scripts/migrate-probe-improvements.php [--database-config=config/database.json] [--dry-run] [--keep-legacy-table]

Splits legacy probe_improvements rows into player-owned blueprints and
probe-owned installations. Run this once just after deploying the split
probe-improvement model.

TXT;
}

function migrateProbeImprovementsAbsolutePath(string $root, string $path): string
{
    if ($path !== '' && $path[0] === '/') {
        return $path;
    }

    return $root . DIRECTORY_SEPARATOR . $path;
}

function migrateProbeImprovementsEnsureSchema(PDO $pdo, string $driver): void
{
    $id = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS probe_improvement_blueprints (
            id $id,
            player_id INTEGER NOT NULL,
            improvement $text NOT NULL,
            created_at $text NOT NULL,
            updated_at $text NOT NULL,
            UNIQUE(player_id, improvement),
            FOREIGN KEY(player_id) REFERENCES players(id)
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_improvement_blueprints_player_id ON probe_improvement_blueprints(player_id)');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS probe_improvement_installations (
            id $id,
            probe_id INTEGER NOT NULL,
            improvement $text NOT NULL,
            created_at $text NOT NULL,
            updated_at $text NOT NULL,
            UNIQUE(probe_id, improvement),
            FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_improvement_installations_probe_id ON probe_improvement_installations(probe_id)');
}

function migrateProbeImprovementsCopyBlueprints(PDO $pdo, string $driver): void
{
    $insert = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    $pdo->exec(
        "{$insert} INTO probe_improvement_blueprints (player_id, improvement, created_at, updated_at)
         SELECT np.player_id,
                pi.improvement,
                MIN(pi.created_at) AS created_at,
                MAX(pi.updated_at) AS updated_at
         FROM probe_improvements pi
         INNER JOIN neumann_probes np ON np.id = pi.probe_id
         WHERE pi.available = 1 OR pi.done = 1
         GROUP BY np.player_id, pi.improvement"
    );
}

function migrateProbeImprovementsCopyInstallations(PDO $pdo, string $driver): void
{
    $insert = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    $pdo->exec(
        "{$insert} INTO probe_improvement_installations (probe_id, improvement, created_at, updated_at)
         SELECT pi.probe_id,
                pi.improvement,
                pi.created_at,
                pi.updated_at
         FROM probe_improvements pi
         INNER JOIN neumann_probes np ON np.id = pi.probe_id
         WHERE pi.done = 1"
    );
}

function migrateProbeImprovementsTableExists(PDO $pdo, string $driver, string $table): bool
{
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrateProbeImprovementsCount(PDO $pdo, string $sql): int
{
    $stmt = $pdo->query($sql);

    return $stmt === false ? 0 : (int) $stmt->fetchColumn();
}
