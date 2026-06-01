<?php

declare(strict_types=1);

namespace VonNeumannGame\Database;

use PDO;

final class SchemaInitializer
{
    public function __construct(
        private readonly string $driver,
    ) {}

    public function initialize(PDO $pdo): void
    {
        foreach ($this->statements() as $statement) {
            $pdo->exec($statement);
        }
        $this->applyLightweightMigrations($pdo);
    }

    /**
     * @return array<string>
     */
    private function statements(): array
    {
        $id = $this->driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $this->driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
        $nullableText = $this->driver === 'mysql' ? 'VARCHAR(255) NULL' : 'TEXT NULL';
        $decimal = $this->driver === 'mysql' ? 'DOUBLE' : 'REAL';

        return [
            "CREATE TABLE IF NOT EXISTS players (
                id $id,
                username $text NOT NULL UNIQUE,
                display_name $nullableText,
                password_hash $nullableText,
                home_sector_x INTEGER NOT NULL DEFAULT 0,
                home_sector_y INTEGER NOT NULL DEFAULT 0,
                home_sector_z INTEGER NOT NULL DEFAULT 0,
                created_at $text NOT NULL,
                updated_at $text NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_players_username ON players(username)",
            "CREATE TABLE IF NOT EXISTS player_auth_methods (
                id $id,
                player_id INTEGER NOT NULL,
                provider $text NOT NULL,
                provider_user_id $nullableText,
                password_hash $nullableText,
                created_at $text NOT NULL,
                UNIQUE(provider, provider_user_id),
                FOREIGN KEY(player_id) REFERENCES players(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_player_auth_player_id ON player_auth_methods(player_id)",
            "CREATE INDEX IF NOT EXISTS idx_player_auth_provider ON player_auth_methods(provider, provider_user_id)",
            "CREATE TABLE IF NOT EXISTS neumann_probes (
                id $id,
                player_id INTEGER NOT NULL UNIQUE,
                name $text NOT NULL,
                sector_x INTEGER NOT NULL,
                sector_y INTEGER NOT NULL,
                sector_z INTEGER NOT NULL,
                velocity_c $decimal NOT NULL DEFAULT 0,
                acceleration_c_per_day $decimal NOT NULL DEFAULT 0,
                direction_x $decimal NOT NULL DEFAULT 0,
                direction_y $decimal NOT NULL DEFAULT 0,
                direction_z $decimal NOT NULL DEFAULT 0,
                status $text NOT NULL,
                integrity_percent $decimal NOT NULL DEFAULT 100,
                energy_stored $decimal NOT NULL DEFAULT 0,
                deuterium_stock $decimal NOT NULL DEFAULT 100,
                metals_stock $decimal NOT NULL DEFAULT 0,
                other_stock $decimal NOT NULL DEFAULT 0,
                internal_clock_rate $decimal NOT NULL DEFAULT 1,
                current_task $nullableText,
                entered_current_sector_at $text NOT NULL,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                FOREIGN KEY(player_id) REFERENCES players(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_neumann_probes_player_id ON neumann_probes(player_id)",
            "CREATE INDEX IF NOT EXISTS idx_neumann_probes_sector ON neumann_probes(sector_x, sector_y, sector_z)",
            "CREATE TABLE IF NOT EXISTS mannies (
                id $id,
                uid $text NOT NULL UNIQUE,
                probe_id INTEGER NULL,
                name $text NOT NULL,
                location_type $text NOT NULL,
                sector_x INTEGER NULL,
                sector_y INTEGER NULL,
                sector_z INTEGER NULL,
                current_task $nullableText,
                task_started_at $nullableText,
                task_ends_at $nullableText,
                task_payload_json TEXT NOT NULL,
                cargo_deuterium $decimal NOT NULL DEFAULT 0,
                cargo_metals $decimal NOT NULL DEFAULT 0,
                cargo_other $decimal NOT NULL DEFAULT 0,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                UNIQUE(probe_id, name),
                FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_mannies_probe_id ON mannies(probe_id)",
            "CREATE INDEX IF NOT EXISTS idx_mannies_uid ON mannies(uid)",
            "CREATE INDEX IF NOT EXISTS idx_mannies_sector ON mannies(sector_x, sector_y, sector_z)",
            "CREATE TABLE IF NOT EXISTS probe_movements (
                id $id,
                probe_id INTEGER NOT NULL,
                origin_x INTEGER NOT NULL,
                origin_y INTEGER NOT NULL,
                origin_z INTEGER NOT NULL,
                target_x INTEGER NOT NULL,
                target_y INTEGER NOT NULL,
                target_z INTEGER NOT NULL,
                distance INTEGER NOT NULL,
                status $text NOT NULL,
                started_at $text NOT NULL,
                preparation_ends_at $text NOT NULL,
                acceleration_ends_at $text NOT NULL,
                cruise_ends_at $text NOT NULL,
                deceleration_ends_at $text NOT NULL,
                arrival_at $text NOT NULL,
                fuel_cost_deuterium $decimal NOT NULL,
                destruction_checked_at $nullableText,
                destroyed_at $nullableText,
                destruction_reason $nullableText,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_probe_movements_probe_status ON probe_movements(probe_id, status)",
            "CREATE TABLE IF NOT EXISTS scheduled_events (
                id $id,
                type $text NOT NULL,
                entity_type $text NOT NULL,
                entity_id INTEGER NOT NULL,
                run_at $text NOT NULL,
                status $text NOT NULL,
                payload_json TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                locked_at $nullableText,
                processed_at $nullableText,
                last_error TEXT NULL,
                created_at $text NOT NULL,
                updated_at $text NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_scheduled_events_due ON scheduled_events(status, run_at)",
            "CREATE INDEX IF NOT EXISTS idx_scheduled_events_entity ON scheduled_events(entity_type, entity_id)",
            "CREATE TABLE IF NOT EXISTS visited_sectors (
                id $id,
                player_id INTEGER NOT NULL,
                sector_x INTEGER NOT NULL,
                sector_y INTEGER NOT NULL,
                sector_z INTEGER NOT NULL,
                first_visited_at $text NOT NULL,
                last_visited_at $text NOT NULL,
                visit_count INTEGER NOT NULL DEFAULT 1,
                UNIQUE(player_id, sector_x, sector_y, sector_z),
                FOREIGN KEY(player_id) REFERENCES players(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_visited_sectors_player_id ON visited_sectors(player_id)",
            "CREATE INDEX IF NOT EXISTS idx_visited_sectors_player_coords ON visited_sectors(player_id, sector_x, sector_y, sector_z)",
            "CREATE TABLE IF NOT EXISTS sessions (
                id $id,
                player_id INTEGER NOT NULL,
                token_hash $text NOT NULL UNIQUE,
                created_at $text NOT NULL,
                expires_at $text NOT NULL,
                last_used_at $text NOT NULL,
                revoked_at $nullableText,
                FOREIGN KEY(player_id) REFERENCES players(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_sessions_player_id ON sessions(player_id)",
            "CREATE INDEX IF NOT EXISTS idx_sessions_token_hash ON sessions(token_hash)",
            "CREATE TABLE IF NOT EXISTS api_keys (
                id $id,
                player_id INTEGER NOT NULL,
                token_hash $text NOT NULL UNIQUE,
                label $text NOT NULL,
                last_four $text NOT NULL,
                created_at $text NOT NULL,
                last_used_at $nullableText,
                revoked_at $nullableText,
                FOREIGN KEY(player_id) REFERENCES players(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_api_keys_player_id ON api_keys(player_id)",
            "CREATE INDEX IF NOT EXISTS idx_api_keys_token_hash ON api_keys(token_hash)",
        ];
    }

    private function applyLightweightMigrations(PDO $pdo): void
    {
        if ($this->driver === 'sqlite') {
            $this->ensureSqliteMannyProbeIdNullable($pdo);
            $this->migrateRepairTaskPayloads($pdo);
            $columns = $pdo->query('PRAGMA table_info(neumann_probes)')->fetchAll(PDO::FETCH_ASSOC);
            $names = array_map(static fn(array $row): string => (string) $row['name'], $columns);
            if (!in_array('entered_current_sector_at', $names, true)) {
                $now = gmdate('c');
                $pdo->exec("ALTER TABLE neumann_probes ADD COLUMN entered_current_sector_at TEXT NOT NULL DEFAULT '$now'");
            }
            if (!in_array('deuterium_stock', $names, true)) {
                $pdo->exec('ALTER TABLE neumann_probes ADD COLUMN deuterium_stock REAL NOT NULL DEFAULT 100');
            }
            if (!in_array('metals_stock', $names, true)) {
                $pdo->exec('ALTER TABLE neumann_probes ADD COLUMN metals_stock REAL NOT NULL DEFAULT 0');
            }
            if (!in_array('other_stock', $names, true)) {
                $pdo->exec('ALTER TABLE neumann_probes ADD COLUMN other_stock REAL NOT NULL DEFAULT 0');
            }
            if (in_array('damage_percent', $names, true)) {
                $pdo->exec('UPDATE neumann_probes SET integrity_percent = min(100.0, max(0.0, 100.0 - damage_percent))');
                $pdo->exec('ALTER TABLE neumann_probes DROP COLUMN damage_percent');
            }
        } elseif ($this->driver === 'mysql') {
            $this->ensureMysqlMannyProbeIdNullable($pdo);
            $this->migrateRepairTaskPayloads($pdo);
            $columns = $pdo->query('SHOW COLUMNS FROM neumann_probes')->fetchAll(PDO::FETCH_ASSOC);
            $names = array_map(static fn(array $row): string => (string) $row['Field'], $columns);
            if (!in_array('deuterium_stock', $names, true)) {
                $pdo->exec('ALTER TABLE neumann_probes ADD COLUMN deuterium_stock DOUBLE NOT NULL DEFAULT 100 AFTER energy_stored');
            }
            if (!in_array('metals_stock', $names, true)) {
                $pdo->exec('ALTER TABLE neumann_probes ADD COLUMN metals_stock DOUBLE NOT NULL DEFAULT 0 AFTER deuterium_stock');
            }
            if (!in_array('other_stock', $names, true)) {
                $pdo->exec('ALTER TABLE neumann_probes ADD COLUMN other_stock DOUBLE NOT NULL DEFAULT 0 AFTER metals_stock');
            }
            if (in_array('damage_percent', $names, true)) {
                $pdo->exec('UPDATE neumann_probes SET integrity_percent = LEAST(100.0, GREATEST(0.0, 100.0 - damage_percent))');
                $pdo->exec('ALTER TABLE neumann_probes DROP COLUMN damage_percent');
            }
        }
    }

    private function ensureSqliteMannyProbeIdNullable(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(mannies)')->fetchAll(PDO::FETCH_ASSOC);
        $probeId = null;
        foreach ($columns as $column) {
            if (($column['name'] ?? null) === 'probe_id') {
                $probeId = $column;
                break;
            }
        }

        if ($probeId === null || (int) ($probeId['notnull'] ?? 0) === 0) {
            return;
        }

        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->beginTransaction();
        try {
            $pdo->exec('ALTER TABLE mannies RENAME TO mannies_nullable_probe_backup');
            $pdo->exec(
                "CREATE TABLE mannies (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    uid TEXT NOT NULL UNIQUE,
                    probe_id INTEGER NULL,
                    name TEXT NOT NULL,
                    location_type TEXT NOT NULL,
                    sector_x INTEGER NULL,
                    sector_y INTEGER NULL,
                    sector_z INTEGER NULL,
                    current_task TEXT NULL,
                    task_started_at TEXT NULL,
                    task_ends_at TEXT NULL,
                    task_payload_json TEXT NOT NULL,
                    cargo_deuterium REAL NOT NULL DEFAULT 0,
                    cargo_metals REAL NOT NULL DEFAULT 0,
                    cargo_other REAL NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    UNIQUE(probe_id, name),
                    FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
                )"
            );
            $pdo->exec(
                'INSERT INTO mannies
                 (id, uid, probe_id, name, location_type, sector_x, sector_y, sector_z, current_task, task_started_at, task_ends_at, task_payload_json, cargo_deuterium, cargo_metals, cargo_other, created_at, updated_at)
                 SELECT id, uid, probe_id, name, location_type, sector_x, sector_y, sector_z, current_task, task_started_at, task_ends_at, task_payload_json, cargo_deuterium, cargo_metals, cargo_other, created_at, updated_at
                 FROM mannies_nullable_probe_backup'
            );
            $pdo->exec('DROP TABLE mannies_nullable_probe_backup');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $pdo->exec('PRAGMA foreign_keys=ON');
            throw $e;
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mannies_probe_id ON mannies(probe_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mannies_uid ON mannies(uid)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mannies_sector ON mannies(sector_x, sector_y, sector_z)');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }

    private function ensureMysqlMannyProbeIdNullable(PDO $pdo): void
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM mannies WHERE Field = 'probe_id'");
        $column = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($column) || strtoupper((string) ($column['Null'] ?? 'YES')) === 'YES') {
            return;
        }

        $pdo->exec('ALTER TABLE mannies MODIFY probe_id INTEGER NULL');
    }

    private function migrateRepairTaskPayloads(PDO $pdo): void
    {
        $stmt = $pdo->query("SELECT id, task_payload_json FROM mannies WHERE current_task = 'repair'");
        if ($stmt === false) {
            return;
        }

        $update = $pdo->prepare('UPDATE mannies SET task_payload_json = :payload WHERE id = :id');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string) $row['task_payload_json'], true);
            if (!is_array($payload) || !array_key_exists('damagePercent', $payload) || array_key_exists('integrityPercent', $payload)) {
                continue;
            }

            $payload['integrityPercent'] = $payload['damagePercent'];
            unset($payload['damagePercent']);
            $update->execute([
                'id' => (int) $row['id'],
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        }
    }
}
