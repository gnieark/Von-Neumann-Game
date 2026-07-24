<?php

declare(strict_types=1);

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(migrateVisitedSectorsToProbes($argv));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 */
function migrateVisitedSectorsToProbes(array $argv): int
{
    $configPath = 'config/database.json';
    $dryRun = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') {
            $dryRun = true;
        } elseif (str_starts_with($argument, '--database-config=')) {
            $configPath = substr($argument, strlen('--database-config='));
        } elseif ($argument === '--help' || $argument === '-h') {
            echo migrateVisitedSectorsToProbesUsage();

            return 0;
        } else {
            throw new InvalidArgumentException('Unknown option: ' . $argument . "\n\n" . migrateVisitedSectorsToProbesUsage());
        }
    }

    $root = dirname(__DIR__);
    $absoluteConfigPath = str_starts_with($configPath, '/')
        ? $configPath
        : $root . DIRECTORY_SEPARATOR . $configPath;
    $config = DatabaseConfig::fromFile($absoluteConfigPath);
    $pdo = (new DatabaseConnectionFactory($config, $root))->create();

    if (migrateVisitedSectorsColumnExists($pdo, $config->driver, 'probe_id')) {
        echo "visited_sectors already contains probe_id; no migration needed.\n";

        return 0;
    }

    $eventSql = <<<'SQL'
SELECT probes.player_id, events.probe_id, events.sector_x, events.sector_y, events.sector_z,
       MIN(events.visited_at) AS first_visited_at,
       MAX(events.visited_at) AS last_visited_at,
       COUNT(*) AS visit_count
FROM (
    SELECT first_movement.probe_id,
           first_movement.origin_x AS sector_x,
           first_movement.origin_y AS sector_y,
           first_movement.origin_z AS sector_z,
           first_movement.started_at AS visited_at
    FROM probe_movements first_movement
    WHERE first_movement.id = (
        SELECT MIN(candidate.id)
        FROM probe_movements candidate
        WHERE candidate.probe_id = first_movement.probe_id
    )

    UNION ALL

    SELECT arrived.probe_id, arrived.target_x, arrived.target_y, arrived.target_z, arrived.arrival_at
    FROM probe_movements arrived
    WHERE arrived.status = 'arrived'

    UNION ALL

    SELECT stationary.id, stationary.sector_x, stationary.sector_y, stationary.sector_z,
           stationary.entered_current_sector_at
    FROM neumann_probes stationary
    WHERE NOT EXISTS (
        SELECT 1 FROM probe_movements movement WHERE movement.probe_id = stationary.id
    )
) events
INNER JOIN neumann_probes probes ON probes.id = events.probe_id
GROUP BY probes.player_id, events.probe_id, events.sector_x, events.sector_y, events.sector_z
ORDER BY events.probe_id, first_visited_at
SQL;

    $rows = $pdo->query($eventSql)?->fetchAll(PDO::FETCH_ASSOC) ?? [];
    $probeCount = count(array_unique(array_column($rows, 'probe_id')));
    if ($dryRun) {
        echo sprintf(
            "Dry-run: %d probe(s), %d probe-sector visit row(s) would be reconstructed.\n",
            $probeCount,
            count($rows),
        );

        return 0;
    }

    $sqlite = $config->driver === 'sqlite';
    if ($sqlite) {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
    }
    try {
        migrateVisitedSectorsCreateReplacement($pdo, $config->driver);
        $insert = $pdo->prepare(
            'INSERT INTO visited_sectors_by_probe
             (player_id, probe_id, sector_x, sector_y, sector_z, first_visited_at, last_visited_at, visit_count)
             VALUES (:player_id, :probe_id, :sector_x, :sector_y, :sector_z, :first_visited_at, :last_visited_at, :visit_count)'
        );
        foreach ($rows as $row) {
            $insert->execute($row);
        }

        if ($sqlite) {
            $pdo->exec('DROP TABLE visited_sectors');
            $pdo->exec('ALTER TABLE visited_sectors_by_probe RENAME TO visited_sectors');
        } else {
            $pdo->exec(
                'RENAME TABLE visited_sectors TO visited_sectors_player_legacy,
                              visited_sectors_by_probe TO visited_sectors'
            );
        }
        $pdo->exec('CREATE INDEX idx_visited_sectors_player_id ON visited_sectors(player_id)');
        $pdo->exec('CREATE INDEX idx_visited_sectors_player_coords ON visited_sectors(player_id, sector_x, sector_y, sector_z)');
        $pdo->exec('CREATE INDEX idx_visited_sectors_probe_id ON visited_sectors(probe_id)');
        if ($sqlite) {
            $pdo->commit();
        } else {
            $pdo->exec('DROP TABLE visited_sectors_player_legacy');
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } finally {
        if ($sqlite) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    echo sprintf(
        "visited_sectors migrated: %d probe(s), %d probe-sector visit row(s).\n",
        $probeCount,
        count($rows),
    );

    return 0;
}

function migrateVisitedSectorsToProbesUsage(): string
{
    return <<<'TXT'
Usage:
  php scripts/migrate-visited-sectors-to-probes.php [--database-config=config/database.json] [--dry-run]

Replaces the player-wide visited_sectors table with probe-owned visit history.
History is rebuilt from the first movement origin and all arrived movement targets.
Probes without movements are initialized from their current sector.

TXT;
}

function migrateVisitedSectorsColumnExists(PDO $pdo, string $driver, string $column): bool
{
    if ($driver === 'sqlite') {
        foreach ($pdo->query('PRAGMA table_info(visited_sectors)')?->fetchAll(PDO::FETCH_ASSOC) ?? [] as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute(['table_name' => 'visited_sectors', 'column_name' => $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrateVisitedSectorsCreateReplacement(PDO $pdo, string $driver): void
{
    $id = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
    $pdo->exec(
        "CREATE TABLE visited_sectors_by_probe (
            id {$id},
            player_id INTEGER NOT NULL,
            probe_id INTEGER NOT NULL,
            sector_x INTEGER NOT NULL,
            sector_y INTEGER NOT NULL,
            sector_z INTEGER NOT NULL,
            first_visited_at {$text} NOT NULL,
            last_visited_at {$text} NOT NULL,
            visit_count INTEGER NOT NULL DEFAULT 1,
            UNIQUE(probe_id, sector_x, sector_y, sector_z),
            FOREIGN KEY(player_id) REFERENCES players(id),
            FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
        )"
    );
}
