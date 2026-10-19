<?php

declare(strict_types=1);

namespace VonNeumannGame\Database\Migration;

use PDO;

/** Maintenance-only expansion of storage transfer actors; existing manifests remain unchanged. */
final class CollectiveDepotSchemaMigration
{
    private const TYPES = "'others_auxiliary','others_ship','manny'";

    public function __construct(private readonly PDO $pdo) {}

    public function run(bool $apply): array
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Schema migration requires its own maintenance connection.'); }
        $sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($sqlite) {
            $definition = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='sector_storage_transfers'")->fetchColumn();
            if (!is_string($definition)) { throw new \RuntimeException('Missing sector_storage_transfers table.'); }
            $needed = !str_contains($definition, "'others_ship'");
        } else {
            $checks = $this->checks();
            $needed = $checks === [] || count(array_filter($checks, static fn(array $row): bool => !str_contains($row['CHECK_CLAUSE'], 'others_ship'))) > 0;
        }
        if (!$apply || !$needed) { return ['apply' => $apply, 'changeRequired' => $needed]; }
        if ($sqlite) {
            $indexes = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type IN ('index','trigger') AND tbl_name='sector_storage_transfers' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $next = preg_replace('/CREATE TABLE (?:IF NOT EXISTS )?["`]?sector_storage_transfers["`]?/i', 'CREATE TABLE sector_storage_transfers_creation_next', $definition, 1);
            $next = preg_replace('/CHECK\s*\(\s*actor_kind\s+IN\s*\([^)]*\)\s*\)/i', 'CHECK(actor_kind IN (' . self::TYPES . '))', $next, 1, $count);
            if ($count !== 1) { throw new \RuntimeException('Unexpected storage transfer actor kind constraint.'); }
            $foreignKeys = (int) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn();
            $this->pdo->exec('PRAGMA foreign_keys=OFF');
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($next);
                $this->pdo->exec('INSERT INTO sector_storage_transfers_creation_next SELECT * FROM sector_storage_transfers');
                $this->pdo->exec('DROP TABLE sector_storage_transfers');
                $this->pdo->exec('ALTER TABLE sector_storage_transfers_creation_next RENAME TO sector_storage_transfers');
                foreach ($indexes as $index) { $this->pdo->exec($index); }
                if ($this->pdo->query('PRAGMA foreign_key_check')->fetch() !== false) { throw new \RuntimeException('Foreign key validation failed.'); }
                $this->pdo->commit();
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                throw $error;
            } finally {
                $this->pdo->exec('PRAGMA foreign_keys=' . $foreignKeys);
            }
        } else {
            // MariaDB inline checks are removed by redefining the column. Re-running
            // after an interrupted DDL also repairs an absent type constraint.
            $this->pdo->exec('ALTER TABLE sector_storage_transfers MODIFY COLUMN actor_kind VARCHAR(255) NOT NULL');
            foreach ($this->checks() as $check) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/D', $check['CONSTRAINT_NAME'])) { throw new \RuntimeException('Unexpected constraint identifier.'); }
                $this->pdo->exec('ALTER TABLE sector_storage_transfers DROP CONSTRAINT `' . $check['CONSTRAINT_NAME'] . '`');
            }
            $this->pdo->exec('ALTER TABLE sector_storage_transfers ADD CONSTRAINT chk_sector_actor_kind CHECK(actor_kind IN (' . self::TYPES . '))');
        }
        return ['apply' => true, 'changeRequired' => true];
    }

    private function checks(): array
    {
        $rows = $this->pdo->query("SELECT c.CONSTRAINT_NAME,c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME='sector_storage_transfers'")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, static fn(array $row): bool => str_contains($row['CHECK_CLAUSE'], 'actor_kind')));
    }
}
