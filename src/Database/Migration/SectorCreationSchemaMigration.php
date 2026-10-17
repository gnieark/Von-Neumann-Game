<?php

declare(strict_types=1);

namespace VonNeumannGame\Database\Migration;

use PDO;

/** Maintenance-only expansion of the existing effect queue, without touching its payloads. */
final class SectorCreationSchemaMigration
{
    private const TYPES = "'add_object','consume_object','patch_objects','create_sector'";

    public function __construct(private readonly PDO $pdo) {}

    public function run(bool $apply): array
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Schema migration requires its own maintenance connection.'); }
        $sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($sqlite) {
            $definition = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='sector_effects'")->fetchColumn();
            if (!is_string($definition)) { throw new \RuntimeException('Missing sector_effects table.'); }
            $needed = !str_contains($definition, "'create_sector'");
        } else {
            $checks = $this->checks();
            $needed = $checks === [] || count(array_filter($checks, static fn(array $row): bool => !str_contains($row['CHECK_CLAUSE'], 'create_sector'))) > 0;
        }
        if (!$apply || !$needed) { return ['apply' => $apply, 'changeRequired' => $needed]; }
        if ($sqlite) {
            $indexes = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='sector_effects' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $next = preg_replace('/CREATE TABLE (?:IF NOT EXISTS )?["`]?sector_effects["`]?/i', 'CREATE TABLE sector_effects_creation_next', $definition, 1);
            $next = preg_replace('/CHECK\s*\(\s*effect_type\s+IN\s*\([^)]*\)\s*\)/i', 'CHECK(effect_type IN (' . self::TYPES . '))', $next, 1, $count);
            if ($count !== 1) { throw new \RuntimeException('Unexpected sector effect type constraint.'); }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($next);
                $this->pdo->exec('INSERT INTO sector_effects_creation_next SELECT * FROM sector_effects');
                $this->pdo->exec('DROP TABLE sector_effects');
                $this->pdo->exec('ALTER TABLE sector_effects_creation_next RENAME TO sector_effects');
                foreach ($indexes as $index) { $this->pdo->exec($index); }
                $this->pdo->commit();
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                throw $error;
            }
        } else {
            // MariaDB inline checks are removed by redefining the column. Re-running
            // after an interrupted DDL also repairs an absent type constraint.
            $this->pdo->exec('ALTER TABLE sector_effects MODIFY COLUMN effect_type VARCHAR(255) NOT NULL');
            foreach ($this->checks() as $check) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/D', $check['CONSTRAINT_NAME'])) { throw new \RuntimeException('Unexpected constraint identifier.'); }
                $this->pdo->exec('ALTER TABLE sector_effects DROP CONSTRAINT `' . $check['CONSTRAINT_NAME'] . '`');
            }
            $this->pdo->exec('ALTER TABLE sector_effects ADD CONSTRAINT chk_sector_effect_type CHECK(effect_type IN (' . self::TYPES . '))');
        }
        return ['apply' => true, 'changeRequired' => true];
    }

    private function checks(): array
    {
        $rows = $this->pdo->query("SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='sector_effects'")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, static fn(array $row): bool => str_contains($row['CHECK_CLAUSE'], 'effect_type')));
    }
}
