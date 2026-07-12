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
        $caseSensitiveText = $this->driver === 'mysql' ? 'VARCHAR(255) COLLATE utf8mb4_bin' : 'TEXT';
        $decimal = $this->driver === 'mysql' ? 'DOUBLE' : 'REAL';
        $boolean = $this->driver === 'mysql' ? 'BOOLEAN NOT NULL DEFAULT FALSE' : 'INTEGER NOT NULL DEFAULT 0';
        $activeMovementColumn = $this->driver === 'mysql'
            ? ",
                active_probe_id INTEGER GENERATED ALWAYS AS (CASE WHEN status IN ('preparing','accelerating','cruising','decelerating') THEN probe_id ELSE NULL END) STORED"
            : '';

        $statements = [
            "CREATE TABLE IF NOT EXISTS players (
                id $id,
                username $caseSensitiveText NOT NULL UNIQUE,
                display_name $nullableText,
                password_hash $nullableText,
                default_probe_id INTEGER NULL,
                home_sector_x INTEGER NOT NULL DEFAULT 0,
                home_sector_y INTEGER NOT NULL DEFAULT 0,
                home_sector_z INTEGER NOT NULL DEFAULT 0,
                forum_admin $boolean,
                forum_moderator $boolean,
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
                player_id INTEGER NOT NULL,
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
                ice_stock $decimal NOT NULL DEFAULT 0,
                organic_compounds_stock $decimal NOT NULL DEFAULT 0,
                internal_clock_rate $decimal NOT NULL DEFAULT 1,
                current_task $nullableText,
                entered_current_sector_at $text NOT NULL,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                exclude_from_stats $boolean,
                FOREIGN KEY(player_id) REFERENCES players(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_neumann_probes_player_id ON neumann_probes(player_id)",
            "CREATE INDEX IF NOT EXISTS idx_neumann_probes_sector ON neumann_probes(sector_x, sector_y, sector_z)",
            "CREATE TABLE IF NOT EXISTS mannies (
                id $id,
                uid $text NOT NULL UNIQUE,
                probe_id INTEGER NULL,
                storage_container_id INTEGER NULL,
                name $text NOT NULL,
                location_type $text NOT NULL,
                sector_x INTEGER NULL,
                sector_y INTEGER NULL,
                sector_z INTEGER NULL,
                current_task $nullableText,
                task_started_at $nullableText,
                task_ends_at $nullableText,
                task_scheduled_event_id INTEGER NULL,
                task_payload_json TEXT NOT NULL,
                cargo_deuterium $decimal NOT NULL DEFAULT 0,
                cargo_metals $decimal NOT NULL DEFAULT 0,
                cargo_ice $decimal NOT NULL DEFAULT 0,
                cargo_organic_compounds $decimal NOT NULL DEFAULT 0,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                UNIQUE(probe_id, name),
                FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_mannies_probe_id ON mannies(probe_id)",
            "CREATE INDEX IF NOT EXISTS idx_mannies_uid ON mannies(uid)",
            "CREATE INDEX IF NOT EXISTS idx_mannies_sector ON mannies(sector_x, sector_y, sector_z)",
            "CREATE TABLE IF NOT EXISTS probe_items (
                id $id,
                uid $text NOT NULL UNIQUE,
                probe_id INTEGER NOT NULL,
                storage_container_id INTEGER NULL,
                type $text NOT NULL,
                name $text NOT NULL,
                container_space $decimal NOT NULL,
                metadata_json TEXT NOT NULL,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_probe_items_probe_id ON probe_items(probe_id)",
            "CREATE INDEX IF NOT EXISTS idx_probe_items_uid ON probe_items(uid)",
            "CREATE TABLE IF NOT EXISTS probe_improvement_blueprints (
                id $id,
                player_id INTEGER NOT NULL,
                improvement $text NOT NULL,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                UNIQUE(player_id, improvement),
                FOREIGN KEY(player_id) REFERENCES players(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_probe_improvement_blueprints_player_id ON probe_improvement_blueprints(player_id)",
            "CREATE TABLE IF NOT EXISTS probe_improvement_installations (
                id $id,
                probe_id INTEGER NOT NULL,
                improvement $text NOT NULL,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                UNIQUE(probe_id, improvement),
                FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_probe_improvement_installations_probe_id ON probe_improvement_installations(probe_id)",
            "CREATE TABLE IF NOT EXISTS storage_containers (
                id $id,
                uid $text NOT NULL,
                probe_id INTEGER NOT NULL,
                kind $text NOT NULL,
                label $text NOT NULL,
                sort_order INTEGER NOT NULL,
                capacity $decimal NOT NULL DEFAULT 1,
                priority_filter_json TEXT NOT NULL,
                exclusion_filter_json TEXT NOT NULL,
                strict_exclusion_filter_json TEXT NOT NULL,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                UNIQUE(probe_id, uid),
                FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_storage_containers_probe_id ON storage_containers(probe_id)",
            "CREATE TABLE IF NOT EXISTS storage_container_resources (
                id $id,
                container_id INTEGER NOT NULL,
                resource_type $text NOT NULL,
                amount $decimal NOT NULL DEFAULT 0,
                updated_at $text NOT NULL,
                UNIQUE(container_id, resource_type),
                FOREIGN KEY(container_id) REFERENCES storage_containers(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_storage_container_resources_container_id ON storage_container_resources(container_id)",
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
                updated_at $text NOT NULL$activeMovementColumn,
                FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_probe_movements_probe_status ON probe_movements(probe_id, status)",
            $this->driver === 'sqlite'
                ? "CREATE UNIQUE INDEX IF NOT EXISTS idx_probe_movements_one_active_per_probe ON probe_movements(probe_id) WHERE status IN ('preparing','accelerating','cruising','decelerating')"
                : '',
            "CREATE TABLE IF NOT EXISTS probe_messages (
                id $id,
                sender_type $text NOT NULL DEFAULT 'probe',
                sender_id $text NOT NULL,
                sender_name $nullableText,
                sender_probe_id INTEGER NULL,
                recipient_type $text NOT NULL DEFAULT 'probe',
                recipient_id $text NOT NULL,
                recipient_name $nullableText,
                recipient_probe_id INTEGER NULL,
                sector_x INTEGER NOT NULL,
                sector_y INTEGER NOT NULL,
                sector_z INTEGER NOT NULL,
                body TEXT NOT NULL,
                status $text NOT NULL,
                read_at $nullableText,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                FOREIGN KEY(sender_probe_id) REFERENCES neumann_probes(id),
                FOREIGN KEY(recipient_probe_id) REFERENCES neumann_probes(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_probe_messages_recipient ON probe_messages(recipient_probe_id, status, created_at)",
            "CREATE INDEX IF NOT EXISTS idx_probe_messages_sender ON probe_messages(sender_probe_id, created_at)",
            "CREATE TABLE IF NOT EXISTS probe_missions (
                id $id,
                uid $text NOT NULL UNIQUE,
                player_id INTEGER NOT NULL,
                type $text NOT NULL,
                title $text NOT NULL,
                description TEXT NULL,
                status $text NOT NULL,
                step_order $text NOT NULL,
                metadata_json TEXT NOT NULL,
                created_by_event_json TEXT NULL,
                started_at $text NOT NULL,
                completed_at $nullableText,
                failed_at $nullableText,
                abandoned_at $nullableText,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                FOREIGN KEY(player_id) REFERENCES players(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_probe_missions_player_status ON probe_missions(player_id, status, created_at)",
            "CREATE INDEX IF NOT EXISTS idx_probe_missions_uid ON probe_missions(uid)",
            "CREATE TABLE IF NOT EXISTS probe_mission_steps (
                id $id,
                uid $text NOT NULL UNIQUE,
                mission_id INTEGER NOT NULL,
                sort_order INTEGER NOT NULL,
                title $text NOT NULL,
                description TEXT NULL,
                status $text NOT NULL,
                metadata_json TEXT NOT NULL,
                completed_at $nullableText,
                failed_at $nullableText,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                FOREIGN KEY(mission_id) REFERENCES probe_missions(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_probe_mission_steps_mission_order ON probe_mission_steps(mission_id, sort_order, id)",
            "CREATE INDEX IF NOT EXISTS idx_probe_mission_steps_uid ON probe_mission_steps(uid)",
            "CREATE TABLE IF NOT EXISTS probe_damage_warnings (
                id $id,
                probe_id INTEGER NOT NULL,
                movement_id INTEGER NULL,
                type $text NOT NULL,
                status $text NOT NULL,
                phase $text NOT NULL,
                scheduled_at $text NOT NULL,
                sector_x INTEGER NOT NULL,
                sector_y INTEGER NOT NULL,
                sector_z INTEGER NOT NULL,
                container_id $text NOT NULL,
                container_label $text NOT NULL,
                object_id $text NOT NULL,
                risk_percent $decimal NOT NULL,
                additional_container_count INTEGER NOT NULL,
                message TEXT NOT NULL,
                read_at $nullableText,
                resolved_at $nullableText,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                FOREIGN KEY(probe_id) REFERENCES neumann_probes(id),
                FOREIGN KEY(movement_id) REFERENCES probe_movements(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_probe_damage_warnings_probe_status ON probe_damage_warnings(probe_id, status, created_at)",
            "CREATE INDEX IF NOT EXISTS idx_probe_damage_warnings_movement ON probe_damage_warnings(movement_id)",
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
            "CREATE TABLE IF NOT EXISTS scut_networks (
                id $id,
                name $text NOT NULL,
                created_at $text NOT NULL,
                updated_at $text NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS scut_relays (
                id $id,
                created_by_probe_id INTEGER NULL,
                sector_x INTEGER NOT NULL,
                sector_y INTEGER NOT NULL,
                sector_z INTEGER NOT NULL,
                status $text NOT NULL,
                network_id INTEGER NULL,
                created_at $text NOT NULL,
                activated_at $nullableText,
                updated_at $text NOT NULL,
                FOREIGN KEY(network_id) REFERENCES scut_networks(id)
            )",
            "CREATE TABLE IF NOT EXISTS scut_covered_sectors (
                id $id,
                scut_network_id INTEGER NULL,
                scut_relay_id INTEGER NOT NULL,
                sector_x INTEGER NOT NULL,
                sector_y INTEGER NOT NULL,
                sector_z INTEGER NOT NULL,
                FOREIGN KEY(scut_network_id) REFERENCES scut_networks(id) ON DELETE CASCADE,
                FOREIGN KEY(scut_relay_id) REFERENCES scut_relays(id) ON DELETE CASCADE
            )",
            "CREATE INDEX IF NOT EXISTS idx_scut_relays_sector ON scut_relays(sector_x, sector_y, sector_z)",
            "CREATE INDEX IF NOT EXISTS idx_scut_relays_status_sector ON scut_relays(status, sector_x, sector_y, sector_z)",
            "CREATE INDEX IF NOT EXISTS idx_scut_relays_network ON scut_relays(network_id)",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_scut_covered_sectors_relay_sector ON scut_covered_sectors(scut_relay_id, sector_x, sector_y, sector_z)",
            "CREATE INDEX IF NOT EXISTS idx_scut_covered_sectors_network_sector ON scut_covered_sectors(scut_network_id, sector_x, sector_y, sector_z)",
            "CREATE INDEX IF NOT EXISTS idx_scut_covered_sectors_sector ON scut_covered_sectors(sector_x, sector_y, sector_z)",
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
                remember_me $boolean,
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
            "CREATE TABLE IF NOT EXISTS forum_categories (
                id $id,
                name $text NOT NULL,
                description TEXT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at $text NOT NULL,
                updated_at $text NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_forum_categories_sort_order ON forum_categories(sort_order, id)",
            "CREATE TABLE IF NOT EXISTS forum_posts (
                id $id,
                category_id INTEGER NOT NULL,
                author_player_id INTEGER NOT NULL,
                title $text NOT NULL,
                pinned $boolean,
                first_message_id INTEGER NULL,
                message_count INTEGER NOT NULL DEFAULT 0,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                last_message_at $text NOT NULL,
                FOREIGN KEY(category_id) REFERENCES forum_categories(id),
                FOREIGN KEY(author_player_id) REFERENCES players(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_forum_posts_recent ON forum_posts(pinned, last_message_at, id)",
            "CREATE INDEX IF NOT EXISTS idx_forum_posts_category_recent ON forum_posts(category_id, pinned, last_message_at, id)",
            "CREATE TABLE IF NOT EXISTS forum_messages (
                id $id,
                post_id INTEGER NOT NULL,
                author_player_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                edited_at $nullableText,
                FOREIGN KEY(post_id) REFERENCES forum_posts(id),
                FOREIGN KEY(author_player_id) REFERENCES players(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_forum_messages_post_recent ON forum_messages(post_id, created_at, id)",
        ];

        $statements = array_values(array_filter($statements, static fn(string $statement): bool => $statement !== ''));

        if ($this->driver !== 'mysql') {
            return $statements;
        }

        return array_map(fn(string $statement): string => $this->withMysqlEngine($statement), $statements);
    }

    private function withMysqlEngine(string $statement): string
    {
        if (!str_starts_with(strtoupper(ltrim($statement)), 'CREATE TABLE')) {
            return $statement;
        }

        if (stripos($statement, 'ENGINE=') !== false) {
            return $statement;
        }

        return rtrim($statement) . ' ENGINE=InnoDB';
    }

    private function applyLightweightMigrations(PDO $pdo): void
    {
        if ($this->driver === 'sqlite') {
            $this->ensureSqliteColumn($pdo, 'players', 'forum_admin', 'INTEGER NOT NULL DEFAULT 0');
            $this->ensureSqliteColumn($pdo, 'players', 'forum_moderator', 'INTEGER NOT NULL DEFAULT 0');
            $this->ensurePlayerDefaultProbeSchema($pdo);
            $this->ensureSqliteColumn($pdo, 'sessions', 'remember_me', 'INTEGER NOT NULL DEFAULT 0');
            $this->ensureSqliteColumn($pdo, 'forum_posts', 'first_message_id', 'INTEGER NULL');
            $this->ensureSqliteColumn($pdo, 'forum_messages', 'edited_at', 'TEXT NULL');
            $this->syncForumFirstMessages($pdo);
            $this->ensureSqliteMannyCargoColumns($pdo);
            $this->ensureSqliteMannyProbeIdNullable($pdo);
            $this->ensureSqliteColumn($pdo, 'mannies', 'task_scheduled_event_id', 'INTEGER NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mannies_task_scheduled_event_id ON mannies(task_scheduled_event_id)');
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
            if (in_array('damage_percent', $names, true)) {
                $pdo->exec('UPDATE neumann_probes SET integrity_percent = min(100.0, max(0.0, 100.0 - damage_percent))');
                $pdo->exec('ALTER TABLE neumann_probes DROP COLUMN damage_percent');
            }
            $this->ensureSqliteProbeResourceStockColumns($pdo);
            $this->ensureProbePlayerOneToManySchema($pdo);
            $this->ensureSqliteColumn($pdo, 'neumann_probes', 'exclude_from_stats', 'INTEGER NOT NULL DEFAULT 0');
            $this->ensureStorageSchema($pdo);
            $this->ensureDamageWarningSchema($pdo);
            $this->ensureProbeMessageSchema($pdo);
            $this->ensureScutSchema($pdo);
            $this->ensureProbeImprovementSchema($pdo);
            $this->ensureProbeMovementActiveConstraint($pdo);
        } elseif ($this->driver === 'mysql') {
            $this->ensureMysqlColumn($pdo, 'players', 'forum_admin', 'BOOLEAN NOT NULL DEFAULT FALSE AFTER home_sector_z');
            $this->ensureMysqlColumn($pdo, 'players', 'forum_moderator', 'BOOLEAN NOT NULL DEFAULT FALSE AFTER forum_admin');
            $this->ensurePlayerDefaultProbeSchema($pdo);
            $this->ensureMysqlColumn($pdo, 'sessions', 'remember_me', 'BOOLEAN NOT NULL DEFAULT FALSE AFTER last_used_at');
            $this->ensureMysqlColumnCollation($pdo, 'players', 'username', 'utf8mb4_bin', 'VARCHAR(255) COLLATE utf8mb4_bin NOT NULL');
            $this->ensureMysqlColumn($pdo, 'forum_posts', 'first_message_id', 'INTEGER NULL AFTER pinned');
            $this->ensureMysqlColumn($pdo, 'forum_messages', 'edited_at', 'VARCHAR(255) NULL AFTER updated_at');
            $this->syncForumFirstMessages($pdo);
            $this->ensureMysqlMannyProbeIdNullable($pdo);
            $this->ensureMysqlMannyCargoColumns($pdo);
            $this->ensureMysqlColumn($pdo, 'mannies', 'task_scheduled_event_id', 'INTEGER NULL AFTER task_ends_at');
            if (!$this->mysqlIndexExists($pdo, 'mannies', 'idx_mannies_task_scheduled_event_id')) {
                $pdo->exec('CREATE INDEX idx_mannies_task_scheduled_event_id ON mannies(task_scheduled_event_id)');
            }
            $this->migrateRepairTaskPayloads($pdo);
            $columns = $pdo->query('SHOW COLUMNS FROM neumann_probes')->fetchAll(PDO::FETCH_ASSOC);
            $names = array_map(static fn(array $row): string => (string) $row['Field'], $columns);
            if (!in_array('deuterium_stock', $names, true)) {
                $pdo->exec('ALTER TABLE neumann_probes ADD COLUMN deuterium_stock DOUBLE NOT NULL DEFAULT 100 AFTER energy_stored');
            }
            if (!in_array('metals_stock', $names, true)) {
                $pdo->exec('ALTER TABLE neumann_probes ADD COLUMN metals_stock DOUBLE NOT NULL DEFAULT 0 AFTER deuterium_stock');
            }
            if (in_array('damage_percent', $names, true)) {
                $pdo->exec('UPDATE neumann_probes SET integrity_percent = LEAST(100.0, GREATEST(0.0, 100.0 - damage_percent))');
                $pdo->exec('ALTER TABLE neumann_probes DROP COLUMN damage_percent');
            }
            $this->ensureMysqlProbeResourceStockColumns($pdo);
            $this->ensureProbePlayerOneToManySchema($pdo);
            $this->ensureMysqlColumn($pdo, 'neumann_probes', 'exclude_from_stats', 'BOOLEAN NOT NULL DEFAULT FALSE AFTER updated_at');
            $this->ensureStorageSchema($pdo);
            $this->ensureDamageWarningSchema($pdo);
            $this->ensureMysqlProbeDamageWarningMovementNullable($pdo);
            $this->ensureProbeMessageSchema($pdo);
            $this->ensureScutSchema($pdo);
            $this->ensureProbeImprovementSchema($pdo);
            $this->ensureProbeMovementActiveConstraint($pdo);
        }
    }

    private function ensureProbeMovementActiveConstraint(PDO $pdo): void
    {
        $this->normalizeDuplicateActiveProbeMovements($pdo);

        if ($this->driver === 'sqlite') {
            $pdo->exec(
                "CREATE UNIQUE INDEX IF NOT EXISTS idx_probe_movements_one_active_per_probe
                 ON probe_movements(probe_id)
                 WHERE status IN ('preparing','accelerating','cruising','decelerating')"
            );
            return;
        }

        $this->ensureMysqlColumn(
            $pdo,
            'probe_movements',
            'active_probe_id',
            "INTEGER GENERATED ALWAYS AS (CASE WHEN status IN ('preparing','accelerating','cruising','decelerating') THEN probe_id ELSE NULL END) STORED AFTER probe_id",
        );
        if ($this->mysqlIndexExists($pdo, 'probe_movements', 'idx_probe_movements_one_active_per_probe')) {
            return;
        }

        $pdo->exec('CREATE UNIQUE INDEX idx_probe_movements_one_active_per_probe ON probe_movements(active_probe_id)');
    }

    private function normalizeDuplicateActiveProbeMovements(PDO $pdo): void
    {
        $now = $pdo->quote(gmdate('c'));
        if ($this->driver === 'sqlite') {
            $pdo->exec(
                "UPDATE probe_movements
                 SET status = 'failed', updated_at = $now
                 WHERE status IN ('preparing','accelerating','cruising','decelerating')
                   AND id NOT IN (
                       SELECT keep_id
                       FROM (
                           SELECT MAX(id) AS keep_id
                           FROM probe_movements
                           WHERE status IN ('preparing','accelerating','cruising','decelerating')
                           GROUP BY probe_id
                       )
                   )
                   AND probe_id IN (
                       SELECT duplicate_probe_id
                       FROM (
                           SELECT probe_id AS duplicate_probe_id
                           FROM probe_movements
                           WHERE status IN ('preparing','accelerating','cruising','decelerating')
                           GROUP BY probe_id
                           HAVING COUNT(*) > 1
                       )
                   )"
            );
            return;
        }

        $pdo->exec(
            "UPDATE probe_movements pm
             JOIN (
                 SELECT probe_id, MAX(id) AS keep_id
                 FROM probe_movements
                 WHERE status IN ('preparing','accelerating','cruising','decelerating')
                 GROUP BY probe_id
                 HAVING COUNT(*) > 1
             ) keepers ON keepers.probe_id = pm.probe_id
             SET pm.status = 'failed', pm.updated_at = $now
             WHERE pm.status IN ('preparing','accelerating','cruising','decelerating')
               AND pm.id <> keepers.keep_id"
        );
    }

    private function ensureProbeImprovementSchema(PDO $pdo): void
    {
        $id = $this->driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $this->driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';

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

    private function ensureScutSchema(PDO $pdo): void
    {
        $id = $this->driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $this->driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
        $nullableText = $this->driver === 'mysql' ? 'VARCHAR(255) NULL' : 'TEXT NULL';

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scut_networks (
                id $id,
                name $text NOT NULL,
                created_at $text NOT NULL,
                updated_at $text NOT NULL
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scut_relays (
                id $id,
                created_by_probe_id INTEGER NULL,
                sector_x INTEGER NOT NULL,
                sector_y INTEGER NOT NULL,
                sector_z INTEGER NOT NULL,
                status $text NOT NULL,
                network_id INTEGER NULL,
                created_at $text NOT NULL,
                activated_at $nullableText,
                updated_at $text NOT NULL,
                FOREIGN KEY(network_id) REFERENCES scut_networks(id)
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scut_covered_sectors (
                id $id,
                scut_network_id INTEGER NULL,
                scut_relay_id INTEGER NOT NULL,
                sector_x INTEGER NOT NULL,
                sector_y INTEGER NOT NULL,
                sector_z INTEGER NOT NULL,
                FOREIGN KEY(scut_network_id) REFERENCES scut_networks(id) ON DELETE CASCADE,
                FOREIGN KEY(scut_relay_id) REFERENCES scut_relays(id) ON DELETE CASCADE
            )"
        );
        $this->removeScutRelayCreatorForeignKey($pdo);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scut_relays_sector ON scut_relays(sector_x, sector_y, sector_z)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scut_relays_status_sector ON scut_relays(status, sector_x, sector_y, sector_z)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scut_relays_network ON scut_relays(network_id)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_scut_covered_sectors_relay_sector ON scut_covered_sectors(scut_relay_id, sector_x, sector_y, sector_z)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scut_covered_sectors_network_sector ON scut_covered_sectors(scut_network_id, sector_x, sector_y, sector_z)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scut_covered_sectors_sector ON scut_covered_sectors(sector_x, sector_y, sector_z)');
    }

    private function removeScutRelayCreatorForeignKey(PDO $pdo): void
    {
        if ($this->driver === 'sqlite') {
            $foreignKeys = $pdo->query('PRAGMA foreign_key_list(scut_relays)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($foreignKeys as $foreignKey) {
                if (($foreignKey['from'] ?? null) === 'created_by_probe_id' && ($foreignKey['table'] ?? null) === 'neumann_probes') {
                    $this->rebuildSqliteScutRelaysWithoutCreatorForeignKey($pdo);
                    return;
                }
            }

            return;
        }

        $stmt = $pdo->prepare(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'scut_relays'
               AND COLUMN_NAME = 'created_by_probe_id'
               AND REFERENCED_TABLE_NAME = 'neumann_probes'"
        );
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $constraintName) {
            $escaped = str_replace('`', '``', (string) $constraintName);
            $pdo->exec("ALTER TABLE scut_relays DROP FOREIGN KEY `$escaped`");
        }
    }

    private function rebuildSqliteScutRelaysWithoutCreatorForeignKey(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM scut_relays')->fetchAll(PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->beginTransaction();
        try {
            $pdo->exec('ALTER TABLE scut_relays RENAME TO scut_relays_creator_fk_backup');
            $pdo->exec(
                'CREATE TABLE scut_relays (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    created_by_probe_id INTEGER NULL,
                    sector_x INTEGER NOT NULL,
                    sector_y INTEGER NOT NULL,
                    sector_z INTEGER NOT NULL,
                    status TEXT NOT NULL,
                    network_id INTEGER NULL,
                    created_at TEXT NOT NULL,
                    activated_at TEXT NULL,
                    updated_at TEXT NOT NULL,
                    FOREIGN KEY(network_id) REFERENCES scut_networks(id)
                )'
            );

            $insert = $pdo->prepare(
                'INSERT INTO scut_relays
                 (id, created_by_probe_id, sector_x, sector_y, sector_z, status, network_id, created_at, activated_at, updated_at)
                 VALUES (:id, :created_by_probe_id, :sector_x, :sector_y, :sector_z, :status, :network_id, :created_at, :activated_at, :updated_at)'
            );
            foreach ($rows as $row) {
                $insert->execute([
                    'id' => (int) $row['id'],
                    'created_by_probe_id' => $row['created_by_probe_id'] !== null ? (int) $row['created_by_probe_id'] : null,
                    'sector_x' => (int) $row['sector_x'],
                    'sector_y' => (int) $row['sector_y'],
                    'sector_z' => (int) $row['sector_z'],
                    'status' => (string) $row['status'],
                    'network_id' => $row['network_id'] !== null ? (int) $row['network_id'] : null,
                    'created_at' => (string) $row['created_at'],
                    'activated_at' => $row['activated_at'] !== null ? (string) $row['activated_at'] : null,
                    'updated_at' => (string) $row['updated_at'],
                ]);
            }

            $pdo->exec('DROP TABLE scut_relays_creator_fk_backup');
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        } finally {
            $pdo->exec('PRAGMA foreign_keys=ON');
        }
    }

    private function ensureProbeMessageSchema(PDO $pdo): void
    {
        if ($this->driver === 'sqlite') {
            $this->ensureSqliteColumn($pdo, 'probe_messages', 'sender_type', "TEXT NOT NULL DEFAULT 'probe'");
            $this->ensureSqliteColumn($pdo, 'probe_messages', 'sender_id', 'TEXT NULL');
            $this->ensureSqliteColumn($pdo, 'probe_messages', 'sender_name', 'TEXT NULL');
            $this->ensureSqliteColumn($pdo, 'probe_messages', 'recipient_type', "TEXT NOT NULL DEFAULT 'probe'");
            $this->ensureSqliteColumn($pdo, 'probe_messages', 'recipient_id', 'TEXT NULL');
            $this->ensureSqliteColumn($pdo, 'probe_messages', 'recipient_name', 'TEXT NULL');
            $columns = $pdo->query('PRAGMA table_info(probe_messages)')->fetchAll(PDO::FETCH_ASSOC);
            $byName = [];
            foreach ($columns as $column) {
                $byName[(string) $column['name']] = $column;
            }
            if (((int) ($byName['sender_probe_id']['notnull'] ?? 0)) === 1 || ((int) ($byName['recipient_probe_id']['notnull'] ?? 0)) === 1) {
                $this->rebuildSqliteProbeMessages($pdo);
            } else {
                $this->backfillProbeMessageEndpoints($pdo);
            }
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_messages_recipient ON probe_messages(recipient_probe_id, status, created_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_messages_sender ON probe_messages(sender_probe_id, created_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_messages_recipient_endpoint ON probe_messages(recipient_type, recipient_id, status, created_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_messages_sender_endpoint ON probe_messages(sender_type, sender_id, created_at)');
            return;
        }

        $this->ensureMysqlColumn($pdo, 'probe_messages', 'sender_type', "VARCHAR(255) NOT NULL DEFAULT 'probe' AFTER id");
        $this->ensureMysqlColumn($pdo, 'probe_messages', 'sender_id', 'VARCHAR(255) NULL AFTER sender_type');
        $this->ensureMysqlColumn($pdo, 'probe_messages', 'sender_name', 'VARCHAR(255) NULL AFTER sender_id');
        $this->ensureMysqlColumn($pdo, 'probe_messages', 'recipient_type', "VARCHAR(255) NOT NULL DEFAULT 'probe' AFTER sender_probe_id");
        $this->ensureMysqlColumn($pdo, 'probe_messages', 'recipient_id', 'VARCHAR(255) NULL AFTER recipient_type');
        $this->ensureMysqlColumn($pdo, 'probe_messages', 'recipient_name', 'VARCHAR(255) NULL AFTER recipient_id');
        $pdo->exec('ALTER TABLE probe_messages MODIFY sender_probe_id INTEGER NULL');
        $pdo->exec('ALTER TABLE probe_messages MODIFY recipient_probe_id INTEGER NULL');
        $this->backfillProbeMessageEndpoints($pdo);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_messages_recipient_endpoint ON probe_messages(recipient_type(32), recipient_id(191), status(32), created_at(32))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_messages_sender_endpoint ON probe_messages(sender_type(32), sender_id(191), created_at(32))');
    }

    private function backfillProbeMessageEndpoints(PDO $pdo): void
    {
        $pdo->exec("UPDATE probe_messages SET sender_type = 'probe' WHERE sender_type IS NULL OR sender_type = ''");
        $pdo->exec("UPDATE probe_messages SET recipient_type = 'probe' WHERE recipient_type IS NULL OR recipient_type = ''");
        $castType = $this->driver === 'mysql' ? 'CHAR' : 'TEXT';
        $pdo->exec("UPDATE probe_messages SET sender_id = CAST(sender_probe_id AS $castType) WHERE (sender_id IS NULL OR sender_id = '') AND sender_probe_id IS NOT NULL");
        $pdo->exec("UPDATE probe_messages SET recipient_id = CAST(recipient_probe_id AS $castType) WHERE (recipient_id IS NULL OR recipient_id = '') AND recipient_probe_id IS NOT NULL");
    }

    private function rebuildSqliteProbeMessages(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM probe_messages')->fetchAll(PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->beginTransaction();
        try {
            $pdo->exec('ALTER TABLE probe_messages RENAME TO probe_messages_endpoint_backup');
            $pdo->exec(
                "CREATE TABLE probe_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sender_type TEXT NOT NULL DEFAULT 'probe',
                    sender_id TEXT NOT NULL,
                    sender_name TEXT NULL,
                    sender_probe_id INTEGER NULL,
                    recipient_type TEXT NOT NULL DEFAULT 'probe',
                    recipient_id TEXT NOT NULL,
                    recipient_name TEXT NULL,
                    recipient_probe_id INTEGER NULL,
                    sector_x INTEGER NOT NULL,
                    sector_y INTEGER NOT NULL,
                    sector_z INTEGER NOT NULL,
                    body TEXT NOT NULL,
                    status TEXT NOT NULL,
                    read_at TEXT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    FOREIGN KEY(sender_probe_id) REFERENCES neumann_probes(id),
                    FOREIGN KEY(recipient_probe_id) REFERENCES neumann_probes(id)
                )"
            );

            $insert = $pdo->prepare(
                'INSERT INTO probe_messages
                 (id, sender_type, sender_id, sender_name, sender_probe_id, recipient_type, recipient_id, recipient_name, recipient_probe_id, sector_x, sector_y, sector_z, body, status, read_at, created_at, updated_at)
                 VALUES (:id, :sender_type, :sender_id, :sender_name, :sender_probe_id, :recipient_type, :recipient_id, :recipient_name, :recipient_probe_id, :sector_x, :sector_y, :sector_z, :body, :status, :read_at, :created_at, :updated_at)'
            );
            foreach ($rows as $row) {
                $senderProbeId = $row['sender_probe_id'] !== null ? (int) $row['sender_probe_id'] : null;
                $recipientProbeId = $row['recipient_probe_id'] !== null ? (int) $row['recipient_probe_id'] : null;
                $insert->execute([
                    'id' => (int) $row['id'],
                    'sender_type' => (string) (($row['sender_type'] ?? null) ?: 'probe'),
                    'sender_id' => (string) (($row['sender_id'] ?? null) ?: ($senderProbeId !== null ? (string) $senderProbeId : '')),
                    'sender_name' => $row['sender_name'] ?? null,
                    'sender_probe_id' => $senderProbeId,
                    'recipient_type' => (string) (($row['recipient_type'] ?? null) ?: 'probe'),
                    'recipient_id' => (string) (($row['recipient_id'] ?? null) ?: ($recipientProbeId !== null ? (string) $recipientProbeId : '')),
                    'recipient_name' => $row['recipient_name'] ?? null,
                    'recipient_probe_id' => $recipientProbeId,
                    'sector_x' => (int) $row['sector_x'],
                    'sector_y' => (int) $row['sector_y'],
                    'sector_z' => (int) $row['sector_z'],
                    'body' => (string) $row['body'],
                    'status' => (string) $row['status'],
                    'read_at' => $row['read_at'] !== null ? (string) $row['read_at'] : null,
                    'created_at' => (string) $row['created_at'],
                    'updated_at' => (string) $row['updated_at'],
                ]);
            }

            $pdo->exec('DROP TABLE probe_messages_endpoint_backup');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $pdo->exec('PRAGMA foreign_keys=ON');
            throw $e;
        }
        $pdo->exec('PRAGMA foreign_keys=ON');
    }

    private function ensureDamageWarningSchema(PDO $pdo): void
    {
        $id = $this->driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $this->driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
        $nullableText = $this->driver === 'mysql' ? 'VARCHAR(255) NULL' : 'TEXT NULL';
        $decimal = $this->driver === 'mysql' ? 'DOUBLE' : 'REAL';

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS probe_damage_warnings (
                id $id,
                probe_id INTEGER NOT NULL,
                movement_id INTEGER NULL,
                type $text NOT NULL,
                status $text NOT NULL,
                phase $text NOT NULL,
                scheduled_at $text NOT NULL,
                sector_x INTEGER NOT NULL,
                sector_y INTEGER NOT NULL,
                sector_z INTEGER NOT NULL,
                container_id $text NOT NULL,
                container_label $text NOT NULL,
                object_id $text NOT NULL,
                risk_percent $decimal NOT NULL,
                additional_container_count INTEGER NOT NULL,
                message TEXT NOT NULL,
                read_at $nullableText,
                resolved_at $nullableText,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                FOREIGN KEY(probe_id) REFERENCES neumann_probes(id),
                FOREIGN KEY(movement_id) REFERENCES probe_movements(id)
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_damage_warnings_probe_status ON probe_damage_warnings(probe_id, status, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_damage_warnings_movement ON probe_damage_warnings(movement_id)');
    }

    private function ensureMysqlProbeDamageWarningMovementNullable(PDO $pdo): void
    {
        if ($this->driver !== 'mysql') {
            return;
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM probe_damage_warnings WHERE Field = 'movement_id'");
        $column = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($column) || strtoupper((string) ($column['Null'] ?? 'YES')) === 'YES') {
            return;
        }

        $pdo->exec('ALTER TABLE probe_damage_warnings MODIFY movement_id INTEGER NULL');
    }

    private function ensureStorageSchema(PDO $pdo): void
    {
        $id = $this->driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $this->driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
        $decimal = $this->driver === 'mysql' ? 'DOUBLE' : 'REAL';

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS storage_containers (
                id $id,
                uid $text NOT NULL,
                probe_id INTEGER NOT NULL,
                kind $text NOT NULL,
                label $text NOT NULL,
                sort_order INTEGER NOT NULL,
                capacity $decimal NOT NULL DEFAULT 1,
                priority_filter_json TEXT NOT NULL,
                exclusion_filter_json TEXT NOT NULL,
                strict_exclusion_filter_json TEXT NOT NULL,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                UNIQUE(probe_id, uid),
                FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_storage_containers_probe_id ON storage_containers(probe_id)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS storage_container_resources (
                id $id,
                container_id INTEGER NOT NULL,
                resource_type $text NOT NULL,
                amount $decimal NOT NULL DEFAULT 0,
                updated_at $text NOT NULL,
                UNIQUE(container_id, resource_type),
                FOREIGN KEY(container_id) REFERENCES storage_containers(id)
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_storage_container_resources_container_id ON storage_container_resources(container_id)');

        if ($this->driver === 'sqlite') {
            $this->ensureSqliteColumn($pdo, 'probe_items', 'storage_container_id', 'INTEGER NULL');
            $this->ensureSqliteColumn($pdo, 'mannies', 'storage_container_id', 'INTEGER NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_items_storage_container_id ON probe_items(storage_container_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mannies_storage_container_id ON mannies(storage_container_id)');
            return;
        }

        $this->ensureMysqlColumn($pdo, 'probe_items', 'storage_container_id', 'INTEGER NULL AFTER probe_id');
        $this->ensureMysqlColumn($pdo, 'mannies', 'storage_container_id', 'INTEGER NULL AFTER probe_id');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_items_storage_container_id ON probe_items(storage_container_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mannies_storage_container_id ON mannies(storage_container_id)');
    }

    private function ensureSqliteColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(static fn(array $row): string => (string) $row['name'], $columns);
        if (in_array($column, $names, true)) {
            return;
        }

        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    private function syncForumFirstMessages(PDO $pdo): void
    {
        $pdo->exec(
            'UPDATE forum_posts
             SET first_message_id = (
                 SELECT id
                 FROM forum_messages
                 WHERE post_id = forum_posts.id
                 ORDER BY created_at ASC, id ASC
                 LIMIT 1
             )
             WHERE first_message_id IS NULL'
        );
    }

    private function ensurePlayerDefaultProbeSchema(PDO $pdo): void
    {
        if ($this->driver === 'sqlite') {
            $this->ensureSqliteColumn($pdo, 'players', 'default_probe_id', 'INTEGER NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_players_default_probe_id ON players(default_probe_id)');
        } else {
            $this->ensureMysqlColumn($pdo, 'players', 'default_probe_id', 'INTEGER NULL AFTER password_hash');
            if (!$this->mysqlIndexExists($pdo, 'players', 'idx_players_default_probe_id')) {
                $pdo->exec('CREATE INDEX idx_players_default_probe_id ON players(default_probe_id)');
            }
        }

        $this->backfillDefaultProbeIds($pdo);
    }

    private function backfillDefaultProbeIds(PDO $pdo): void
    {
        $pdo->exec(
            'UPDATE players
             SET default_probe_id = (
                 SELECT MIN(neumann_probes.id)
                 FROM neumann_probes
                 WHERE neumann_probes.player_id = players.id
             )
             WHERE (
                   default_probe_id IS NULL
                   OR NOT EXISTS (
                       SELECT 1
                       FROM neumann_probes
                       WHERE neumann_probes.id = players.default_probe_id
                         AND neumann_probes.player_id = players.id
                   )
               )
               AND EXISTS (
                   SELECT 1
                   FROM neumann_probes
                   WHERE neumann_probes.player_id = players.id
               )'
        );
    }

    private function ensureProbePlayerOneToManySchema(PDO $pdo): void
    {
        if ($this->driver === 'sqlite') {
            if ($this->sqliteHasSingleColumnUniqueIndex($pdo, 'neumann_probes', 'player_id')) {
                $this->rebuildSqliteNeumannProbesWithoutUniquePlayerId($pdo);
            }
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_neumann_probes_player_id ON neumann_probes(player_id)');
            return;
        }

        $uniqueIndexes = $this->mysqlSingleColumnUniqueIndexes($pdo, 'neumann_probes', 'player_id');
        if ($uniqueIndexes !== [] && !$this->mysqlNonUniqueSingleColumnIndexExists($pdo, 'neumann_probes', 'player_id')) {
            if (!$this->mysqlIndexExists($pdo, 'neumann_probes', 'idx_neumann_probes_player_id_lookup')) {
                $pdo->exec('CREATE INDEX idx_neumann_probes_player_id_lookup ON neumann_probes(player_id)');
            }
        }
        foreach ($uniqueIndexes as $indexName) {
            $escaped = str_replace('`', '``', $indexName);
            $pdo->exec("ALTER TABLE neumann_probes DROP INDEX `$escaped`");
        }
        if (!$this->mysqlIndexExists($pdo, 'neumann_probes', 'idx_neumann_probes_player_id')) {
            $pdo->exec('CREATE INDEX idx_neumann_probes_player_id ON neumann_probes(player_id)');
        }
    }

    private function sqliteHasSingleColumnUniqueIndex(PDO $pdo, string $table, string $column): bool
    {
        $indexes = $pdo->query('PRAGMA index_list(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $index) {
            if ((int) ($index['unique'] ?? 0) !== 1) {
                continue;
            }
            $name = (string) ($index['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $columns = $pdo->query('PRAGMA index_info(' . $pdo->quote($name) . ')')->fetchAll(PDO::FETCH_ASSOC);
            $columnNames = array_map(static fn(array $row): string => (string) $row['name'], $columns);
            if ($columnNames === [$column]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string>
     */
    private function mysqlSingleColumnUniqueIndexes(PDO $pdo, string $table, string $column): array
    {
        $stmt = $pdo->prepare(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND NON_UNIQUE = 0
               AND INDEX_NAME <> :primary_index
             GROUP BY INDEX_NAME
             HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR \',\') = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'primary_index' => 'PRIMARY',
            'column_name' => $column,
        ]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function mysqlNonUniqueSingleColumnIndexExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND NON_UNIQUE = 1
             GROUP BY INDEX_NAME
             HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR \',\') = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function ensureMysqlColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM $table WHERE Field = '$column'");
        if ($stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            return;
        }

        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    private function mysqlIndexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND INDEX_NAME = :index_name
             LIMIT 1'
        );
        $stmt->execute([
            'table_name' => $table,
            'index_name' => $index,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function ensureMysqlColumnCollation(PDO $pdo, string $table, string $column, string $collation, string $definition): void
    {
        $stmt = $pdo->query("SHOW FULL COLUMNS FROM $table WHERE Field = '$column'");
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (is_array($row) && (string) ($row['Collation'] ?? '') === $collation) {
            return;
        }

        $pdo->exec('ALTER TABLE ' . $table . ' MODIFY ' . $column . ' ' . $definition);
    }

    private function ensureSqliteProbeResourceStockColumns(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(neumann_probes)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(static fn(array $row): string => (string) $row['name'], $columns);
        if (
            in_array('ice_stock', $names, true)
            && in_array('organic_compounds_stock', $names, true)
            && !in_array('other_stock', $names, true)
        ) {
            return;
        }

        $rows = $pdo->query('SELECT * FROM neumann_probes')->fetchAll(PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->beginTransaction();
        try {
            $pdo->exec('ALTER TABLE neumann_probes RENAME TO neumann_probes_resource_backup');
            $pdo->exec(
                "CREATE TABLE neumann_probes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    player_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    sector_x INTEGER NOT NULL,
                    sector_y INTEGER NOT NULL,
                    sector_z INTEGER NOT NULL,
                    velocity_c REAL NOT NULL DEFAULT 0,
                    acceleration_c_per_day REAL NOT NULL DEFAULT 0,
                    direction_x REAL NOT NULL DEFAULT 0,
                    direction_y REAL NOT NULL DEFAULT 0,
                    direction_z REAL NOT NULL DEFAULT 0,
                    status TEXT NOT NULL,
                    integrity_percent REAL NOT NULL DEFAULT 100,
                    energy_stored REAL NOT NULL DEFAULT 0,
                    deuterium_stock REAL NOT NULL DEFAULT 100,
                    metals_stock REAL NOT NULL DEFAULT 0,
                    ice_stock REAL NOT NULL DEFAULT 0,
                    organic_compounds_stock REAL NOT NULL DEFAULT 0,
                    internal_clock_rate REAL NOT NULL DEFAULT 1,
                    current_task TEXT NULL,
                    entered_current_sector_at TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    exclude_from_stats INTEGER NOT NULL DEFAULT 0,
                    FOREIGN KEY(player_id) REFERENCES players(id)
                )"
            );

            $insert = $pdo->prepare(
                'INSERT INTO neumann_probes
                 (id, player_id, name, sector_x, sector_y, sector_z, velocity_c, acceleration_c_per_day, direction_x, direction_y, direction_z, status, integrity_percent, energy_stored, deuterium_stock, metals_stock, ice_stock, organic_compounds_stock, internal_clock_rate, current_task, entered_current_sector_at, created_at, updated_at, exclude_from_stats)
                 VALUES (:id, :player_id, :name, :sector_x, :sector_y, :sector_z, :velocity_c, :acceleration_c_per_day, :direction_x, :direction_y, :direction_z, :status, :integrity_percent, :energy_stored, :deuterium_stock, :metals_stock, :ice_stock, :organic_compounds_stock, :internal_clock_rate, :current_task, :entered_current_sector_at, :created_at, :updated_at, :exclude_from_stats)'
            );
            foreach ($rows as $row) {
                $insert->execute([
                    'id' => (int) $row['id'],
                    'player_id' => (int) $row['player_id'],
                    'name' => (string) $row['name'],
                    'sector_x' => (int) $row['sector_x'],
                    'sector_y' => (int) $row['sector_y'],
                    'sector_z' => (int) $row['sector_z'],
                    'velocity_c' => (float) ($row['velocity_c'] ?? 0.0),
                    'acceleration_c_per_day' => (float) ($row['acceleration_c_per_day'] ?? 0.0),
                    'direction_x' => (float) ($row['direction_x'] ?? 0.0),
                    'direction_y' => (float) ($row['direction_y'] ?? 0.0),
                    'direction_z' => (float) ($row['direction_z'] ?? 0.0),
                    'status' => (string) $row['status'],
                    'integrity_percent' => max(0.0, min(100.0, (float) ($row['integrity_percent'] ?? 100.0))),
                    'energy_stored' => (float) ($row['energy_stored'] ?? 0.0),
                    'deuterium_stock' => (float) ($row['deuterium_stock'] ?? 100.0),
                    'metals_stock' => (float) ($row['metals_stock'] ?? 0.0),
                    'ice_stock' => round(max(0.0, (float) ($row['ice_stock'] ?? 0.0)), 4),
                    'organic_compounds_stock' => round(
                        max(0.0, (float) ($row['organic_compounds_stock'] ?? 0.0))
                        + max(0.0, (float) ($row['other_stock'] ?? 0.0)),
                        4,
                    ),
                    'internal_clock_rate' => (float) ($row['internal_clock_rate'] ?? 1.0),
                    'current_task' => $row['current_task'] !== null ? (string) $row['current_task'] : null,
                    'entered_current_sector_at' => (string) ($row['entered_current_sector_at'] ?? $row['created_at']),
                    'created_at' => (string) $row['created_at'],
                    'updated_at' => (string) $row['updated_at'],
                    'exclude_from_stats' => (int) ($row['exclude_from_stats'] ?? 0) === 1 ? 1 : 0,
                ]);
            }

            $pdo->exec('DROP TABLE neumann_probes_resource_backup');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $pdo->exec('PRAGMA foreign_keys=ON');
            throw $e;
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_neumann_probes_player_id ON neumann_probes(player_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_neumann_probes_sector ON neumann_probes(sector_x, sector_y, sector_z)');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }

    private function rebuildSqliteNeumannProbesWithoutUniquePlayerId(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM neumann_probes')->fetchAll(PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->beginTransaction();
        try {
            $pdo->exec('ALTER TABLE neumann_probes RENAME TO neumann_probes_player_unique_backup');
            $pdo->exec(
                "CREATE TABLE neumann_probes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    player_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    sector_x INTEGER NOT NULL,
                    sector_y INTEGER NOT NULL,
                    sector_z INTEGER NOT NULL,
                    velocity_c REAL NOT NULL DEFAULT 0,
                    acceleration_c_per_day REAL NOT NULL DEFAULT 0,
                    direction_x REAL NOT NULL DEFAULT 0,
                    direction_y REAL NOT NULL DEFAULT 0,
                    direction_z REAL NOT NULL DEFAULT 0,
                    status TEXT NOT NULL,
                    integrity_percent REAL NOT NULL DEFAULT 100,
                    energy_stored REAL NOT NULL DEFAULT 0,
                    deuterium_stock REAL NOT NULL DEFAULT 100,
                    metals_stock REAL NOT NULL DEFAULT 0,
                    ice_stock REAL NOT NULL DEFAULT 0,
                    organic_compounds_stock REAL NOT NULL DEFAULT 0,
                    internal_clock_rate REAL NOT NULL DEFAULT 1,
                    current_task TEXT NULL,
                    entered_current_sector_at TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    exclude_from_stats INTEGER NOT NULL DEFAULT 0,
                    FOREIGN KEY(player_id) REFERENCES players(id)
                )"
            );

            $insert = $pdo->prepare(
                'INSERT INTO neumann_probes
                 (id, player_id, name, sector_x, sector_y, sector_z, velocity_c, acceleration_c_per_day, direction_x, direction_y, direction_z, status, integrity_percent, energy_stored, deuterium_stock, metals_stock, ice_stock, organic_compounds_stock, internal_clock_rate, current_task, entered_current_sector_at, created_at, updated_at, exclude_from_stats)
                 VALUES (:id, :player_id, :name, :sector_x, :sector_y, :sector_z, :velocity_c, :acceleration_c_per_day, :direction_x, :direction_y, :direction_z, :status, :integrity_percent, :energy_stored, :deuterium_stock, :metals_stock, :ice_stock, :organic_compounds_stock, :internal_clock_rate, :current_task, :entered_current_sector_at, :created_at, :updated_at, :exclude_from_stats)'
            );
            foreach ($rows as $row) {
                $insert->execute([
                    'id' => (int) $row['id'],
                    'player_id' => (int) $row['player_id'],
                    'name' => (string) $row['name'],
                    'sector_x' => (int) $row['sector_x'],
                    'sector_y' => (int) $row['sector_y'],
                    'sector_z' => (int) $row['sector_z'],
                    'velocity_c' => (float) ($row['velocity_c'] ?? 0.0),
                    'acceleration_c_per_day' => (float) ($row['acceleration_c_per_day'] ?? 0.0),
                    'direction_x' => (float) ($row['direction_x'] ?? 0.0),
                    'direction_y' => (float) ($row['direction_y'] ?? 0.0),
                    'direction_z' => (float) ($row['direction_z'] ?? 0.0),
                    'status' => (string) $row['status'],
                    'integrity_percent' => max(0.0, min(100.0, (float) ($row['integrity_percent'] ?? 100.0))),
                    'energy_stored' => (float) ($row['energy_stored'] ?? 0.0),
                    'deuterium_stock' => (float) ($row['deuterium_stock'] ?? 100.0),
                    'metals_stock' => (float) ($row['metals_stock'] ?? 0.0),
                    'ice_stock' => (float) ($row['ice_stock'] ?? 0.0),
                    'organic_compounds_stock' => (float) ($row['organic_compounds_stock'] ?? 0.0),
                    'internal_clock_rate' => (float) ($row['internal_clock_rate'] ?? 1.0),
                    'current_task' => $row['current_task'] !== null ? (string) $row['current_task'] : null,
                    'entered_current_sector_at' => (string) ($row['entered_current_sector_at'] ?? $row['created_at']),
                    'created_at' => (string) $row['created_at'],
                    'updated_at' => (string) $row['updated_at'],
                    'exclude_from_stats' => (int) ($row['exclude_from_stats'] ?? 0) === 1 ? 1 : 0,
                ]);
            }

            $pdo->exec('DROP TABLE neumann_probes_player_unique_backup');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $pdo->exec('PRAGMA foreign_keys=ON');
            throw $e;
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_neumann_probes_player_id ON neumann_probes(player_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_neumann_probes_sector ON neumann_probes(sector_x, sector_y, sector_z)');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }

    private function ensureMysqlProbeResourceStockColumns(PDO $pdo): void
    {
        $columns = $pdo->query('SHOW COLUMNS FROM neumann_probes')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(static fn(array $row): string => (string) $row['Field'], $columns);
        if (!in_array('ice_stock', $names, true)) {
            $pdo->exec('ALTER TABLE neumann_probes ADD COLUMN ice_stock DOUBLE NOT NULL DEFAULT 0 AFTER metals_stock');
            $names[] = 'ice_stock';
        }
        if (!in_array('organic_compounds_stock', $names, true)) {
            $pdo->exec('ALTER TABLE neumann_probes ADD COLUMN organic_compounds_stock DOUBLE NOT NULL DEFAULT 0 AFTER ice_stock');
            $names[] = 'organic_compounds_stock';
        }
        if (!in_array('other_stock', $names, true)) {
            return;
        }

        $pdo->exec('UPDATE neumann_probes SET organic_compounds_stock = organic_compounds_stock + other_stock WHERE other_stock <> 0');
        $pdo->exec('ALTER TABLE neumann_probes DROP COLUMN other_stock');
    }

    private function ensureSqliteMannyCargoColumns(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(mannies)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(static fn(array $row): string => (string) $row['name'], $columns);
        if (
            in_array('cargo_ice', $names, true)
            && in_array('cargo_organic_compounds', $names, true)
            && !in_array('cargo_other', $names, true)
        ) {
            return;
        }

        $rows = $pdo->query('SELECT * FROM mannies')->fetchAll(PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->beginTransaction();
        try {
            $pdo->exec('ALTER TABLE mannies RENAME TO mannies_cargo_backup');
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
                    cargo_ice REAL NOT NULL DEFAULT 0,
                    cargo_organic_compounds REAL NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    UNIQUE(probe_id, name),
                    FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
                )"
            );

            $insert = $pdo->prepare(
                'INSERT INTO mannies
                 (id, uid, probe_id, name, location_type, sector_x, sector_y, sector_z, current_task, task_started_at, task_ends_at, task_payload_json, cargo_deuterium, cargo_metals, cargo_ice, cargo_organic_compounds, created_at, updated_at)
                 VALUES (:id, :uid, :probe_id, :name, :location_type, :sector_x, :sector_y, :sector_z, :current_task, :task_started_at, :task_ends_at, :task_payload_json, :cargo_deuterium, :cargo_metals, :cargo_ice, :cargo_organic_compounds, :created_at, :updated_at)'
            );
            foreach ($rows as $row) {
                $cargo = $this->migratedMannyCargoAmounts($row);
                $insert->execute([
                    'id' => (int) $row['id'],
                    'uid' => (string) $row['uid'],
                    'probe_id' => $row['probe_id'] !== null ? (int) $row['probe_id'] : null,
                    'name' => (string) $row['name'],
                    'location_type' => (string) $row['location_type'],
                    'sector_x' => $row['sector_x'] !== null ? (int) $row['sector_x'] : null,
                    'sector_y' => $row['sector_y'] !== null ? (int) $row['sector_y'] : null,
                    'sector_z' => $row['sector_z'] !== null ? (int) $row['sector_z'] : null,
                    'current_task' => $row['current_task'] !== null ? (string) $row['current_task'] : null,
                    'task_started_at' => $row['task_started_at'] !== null ? (string) $row['task_started_at'] : null,
                    'task_ends_at' => $row['task_ends_at'] !== null ? (string) $row['task_ends_at'] : null,
                    'task_payload_json' => (string) ($row['task_payload_json'] ?? '{}'),
                    'cargo_deuterium' => max(0.0, (float) ($row['cargo_deuterium'] ?? 0.0)),
                    'cargo_metals' => max(0.0, (float) ($row['cargo_metals'] ?? 0.0)),
                    'cargo_ice' => $cargo['ice'],
                    'cargo_organic_compounds' => $cargo['organicCompounds'],
                    'created_at' => (string) $row['created_at'],
                    'updated_at' => (string) $row['updated_at'],
                ]);
            }

            $pdo->exec('DROP TABLE mannies_cargo_backup');
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

    private function ensureMysqlMannyCargoColumns(PDO $pdo): void
    {
        $columns = $pdo->query('SHOW COLUMNS FROM mannies')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(static fn(array $row): string => (string) $row['Field'], $columns);
        if (!in_array('cargo_ice', $names, true)) {
            $pdo->exec('ALTER TABLE mannies ADD COLUMN cargo_ice DOUBLE NOT NULL DEFAULT 0 AFTER cargo_metals');
            $names[] = 'cargo_ice';
        }
        if (!in_array('cargo_organic_compounds', $names, true)) {
            $pdo->exec('ALTER TABLE mannies ADD COLUMN cargo_organic_compounds DOUBLE NOT NULL DEFAULT 0 AFTER cargo_ice');
            $names[] = 'cargo_organic_compounds';
        }
        if (!in_array('cargo_other', $names, true)) {
            return;
        }

        $rows = $pdo->query('SELECT id, task_payload_json, cargo_other, cargo_ice, cargo_organic_compounds FROM mannies')->fetchAll(PDO::FETCH_ASSOC);
        $update = $pdo->prepare('UPDATE mannies SET cargo_ice = :ice, cargo_organic_compounds = :organic WHERE id = :id');
        foreach ($rows as $row) {
            $cargo = $this->migratedMannyCargoAmounts($row);
            $update->execute([
                'id' => (int) $row['id'],
                'ice' => $cargo['ice'],
                'organic' => $cargo['organicCompounds'],
            ]);
        }

        $pdo->exec('ALTER TABLE mannies DROP COLUMN cargo_other');
    }

    /**
     * @return array{ice: float, organicCompounds: float}
     */
    private function migratedMannyCargoAmounts(array $row): array
    {
        $existingIce = round(max(0.0, (float) ($row['cargo_ice'] ?? 0.0)), 4);
        $existingOrganic = round(max(0.0, (float) ($row['cargo_organic_compounds'] ?? 0.0)), 4);
        $legacyOther = round(max(0.0, (float) ($row['cargo_other'] ?? 0.0)), 4);
        if ($existingIce + $existingOrganic > 0.0 || $legacyOther <= 0.0) {
            return ['ice' => $existingIce, 'organicCompounds' => $existingOrganic];
        }

        $payload = json_decode((string) ($row['task_payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        return $this->splitLegacyMannyOtherCargo($legacyOther, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ice: float, organicCompounds: float}
     */
    private function splitLegacyMannyOtherCargo(float $amount, array $payload): array
    {
        $extracted = is_array($payload['extractedResources'] ?? null) ? $payload['extractedResources'] : null;
        $deposited = is_array($payload['depositedResources'] ?? null) ? $payload['depositedResources'] : null;
        if ($extracted !== null || $deposited !== null) {
            $ice = $this->resourceDelta($extracted, $deposited, 'ice');
            $organic = $this->resourceDelta($extracted, $deposited, 'carbon_compounds')
                + $this->resourceDelta($extracted, $deposited, 'other');

            return $this->scaledLegacyMannyOtherCargo($amount, $ice, $organic);
        }

        $profile = is_array($payload['resourceProfile'] ?? null) ? $payload['resourceProfile'] : null;
        if ($profile !== null) {
            return $this->scaledLegacyMannyOtherCargo(
                $amount,
                max(0.0, (float) ($profile['ice'] ?? 0.0)),
                max(0.0, (float) ($profile['carbon_compounds'] ?? 0.0)) + max(0.0, (float) ($profile['other'] ?? 0.0)),
            );
        }

        $resourceType = strtolower(str_replace(['-', ' '], '_', (string) ($payload['resourceType'] ?? '')));
        if ($resourceType === 'ice' || $resourceType === 'water' || $resourceType === 'water_ice') {
            return ['ice' => $amount, 'organicCompounds' => 0.0];
        }

        return ['ice' => 0.0, 'organicCompounds' => $amount];
    }

    private function resourceDelta(?array $extracted, ?array $deposited, string $type): float
    {
        return max(
            0.0,
            (float) ($extracted[$type] ?? 0.0) - (float) ($deposited[$type] ?? 0.0),
        );
    }

    /**
     * @return array{ice: float, organicCompounds: float}
     */
    private function scaledLegacyMannyOtherCargo(float $amount, float $ice, float $organic): array
    {
        $total = max(0.0, $ice) + max(0.0, $organic);
        if ($total <= 0.0) {
            return ['ice' => 0.0, 'organicCompounds' => $amount];
        }

        $iceAmount = round($amount * (max(0.0, $ice) / $total), 4);

        return [
            'ice' => $iceAmount,
            'organicCompounds' => round(max(0.0, $amount - $iceAmount), 4),
        ];
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
                    cargo_ice REAL NOT NULL DEFAULT 0,
                    cargo_organic_compounds REAL NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    UNIQUE(probe_id, name),
                    FOREIGN KEY(probe_id) REFERENCES neumann_probes(id)
                )"
            );
            $pdo->exec(
                'INSERT INTO mannies
                 (id, uid, probe_id, name, location_type, sector_x, sector_y, sector_z, current_task, task_started_at, task_ends_at, task_payload_json, cargo_deuterium, cargo_metals, cargo_ice, cargo_organic_compounds, created_at, updated_at)
                 SELECT id, uid, probe_id, name, location_type, sector_x, sector_y, sector_z, current_task, task_started_at, task_ends_at, task_payload_json, cargo_deuterium, cargo_metals, cargo_ice, cargo_organic_compounds, created_at, updated_at
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
