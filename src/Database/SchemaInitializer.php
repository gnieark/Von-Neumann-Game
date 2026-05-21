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
                internal_clock_rate $decimal NOT NULL DEFAULT 1,
                current_task $nullableText,
                created_at $text NOT NULL,
                updated_at $text NOT NULL,
                FOREIGN KEY(player_id) REFERENCES players(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_neumann_probes_player_id ON neumann_probes(player_id)",
            "CREATE INDEX IF NOT EXISTS idx_neumann_probes_sector ON neumann_probes(sector_x, sector_y, sector_z)",
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
        ];
    }
}
