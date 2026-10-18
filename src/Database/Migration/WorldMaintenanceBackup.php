<?php
declare(strict_types=1);
namespace VonNeumannGame\Database\Migration;

use PDO;

/** Full offline SQL+sector backup. No passwords, shell command or server-global mutation. */
final class WorldMaintenanceBackup
{
    public function __construct(private readonly PDO $pdo, private readonly string $universe) {}

    public function fingerprint(): string
    {
        $identity = $this->mysql()
            ? $this->pdo->query('SELECT DATABASE(), @@hostname, @@port')->fetch(PDO::FETCH_NUM)
            : $this->pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
        return hash('sha256', json_encode([$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), $identity, realpath($this->universe)], JSON_THROW_ON_ERROR));
    }

    public function create(string $directory): array
    {
        if (is_file($directory . '/backup.json')) {
            $backup = MaintenanceFiles::read($directory . '/backup.json');
            $this->check($directory, $backup);
            return $backup;
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) { throw new \RuntimeException('Cannot create backup directory.'); }
        // Refuse a partial backup of an extended installation.
        $unsupported = $this->mysql()
            ? "SELECT (SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()) + (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()) + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()) + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE())"
            : "SELECT COUNT(*) FROM sqlite_master WHERE type IN ('view','trigger')";
        if ((int) $this->pdo->query($unsupported)->fetchColumn() !== 0) { throw new \RuntimeException('Extended SQL objects require an explicit backup/restore procedure before migration.'); }
        $backup = ['format' => 'world-maintenance-v1', 'fingerprint' => $this->fingerprint(), 'createdAt' => gmdate('c'), 'tables' => [], 'files' => []];
        $this->pdo->beginTransaction();
        try {
            $backup['sequences'] = !$this->mysql() && (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='sqlite_sequence'")->fetchColumn() > 0
                ? $this->pdo->query('SELECT name,seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($this->tables() as $table) {
                $quoted = $this->quoteName($table);
                if ($this->mysql()) {
                    $ddl = $this->pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM)[1];
                    if (!str_contains($ddl, 'ENGINE=InnoDB')) { throw new \RuntimeException('Maintenance backup requires transactional InnoDB tables: ' . $table); }
                    $columns = array_column(array_filter($this->pdo->query('SHOW COLUMNS FROM ' . $quoted)->fetchAll(PDO::FETCH_ASSOC), static fn($r) => !preg_match('/(?:VIRTUAL|STORED) GENERATED/', $r['Extra'])), 'Field');
                    $indexes = [];
                } else {
                    $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?"); $stmt->execute([$table]); $ddl = $stmt->fetchColumn();
                    $columns = array_column(array_filter($this->pdo->query('PRAGMA table_xinfo(' . $quoted . ')')->fetchAll(PDO::FETCH_ASSOC), static fn($r) => (int) $r['hidden'] === 0), 'name');
                    $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name=? AND sql IS NOT NULL"); $stmt->execute([$table]); $indexes = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
                $name = 'sql/' . hash('sha256', $table) . '.rows';
                if (!is_dir($directory . '/sql')) { mkdir($directory . '/sql', 0700); }
                $handle = fopen($directory . '/' . $name, 'wb');
                if ($handle === false) { throw new \RuntimeException('Cannot open SQL backup.'); }
                $rows = 0;
                try {
                    $query = $this->pdo->query('SELECT ' . implode(',', array_map($this->quoteName(...), $columns)) . ' FROM ' . $quoted);
                    while ($row = $query->fetch(PDO::FETCH_NUM)) {
                        $line = base64_encode(serialize($row)) . "\n";
                        if (fwrite($handle, $line) !== strlen($line)) { throw new \RuntimeException('Incomplete SQL backup.'); }
                        $rows++;
                    }
                    if (!fflush($handle) || !fsync($handle)) { throw new \RuntimeException('Cannot persist SQL backup.'); }
                } finally { fclose($handle); }
                chmod($directory . '/' . $name, 0600);
                $backup['tables'][$table] = compact('ddl', 'columns', 'indexes', 'name', 'rows') + ['hash' => hash_file('sha256', $directory . '/' . $name)];
            }
            // One durable stream avoids hundreds of thousands of fsyncs and directory entries.
            $archive = fopen($directory . '/sectors.rows', 'wb');
            if ($archive === false) { throw new \RuntimeException('Cannot create sector backup archive.'); }
            chmod($directory . '/sectors.rows', 0600);
            try {
                foreach (MaintenanceFiles::sectors($this->universe) as $path) {
                    $raw = file_get_contents($this->universe . '/' . $path);
                    if ($raw === false) { throw new \RuntimeException('Cannot read sector for backup.'); }
                    $line = base64_encode(serialize([$path, $raw])) . "\n";
                    if (fwrite($archive, $line) !== strlen($line)) { throw new \RuntimeException('Incomplete sector backup.'); }
                    $backup['files'][$path] = hash('sha256', $raw);
                }
                if (!fflush($archive) || !fsync($archive)) { throw new \RuntimeException('Cannot persist sector backup.'); }
            } finally { fclose($archive); }
            $backup['archiveHash'] = hash_file('sha256', $directory . '/sectors.rows');
            $this->pdo->rollBack();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
        foreach ($backup['files'] as $path => $hash) {
            if (hash_file('sha256', $this->universe . '/' . $path) !== $hash) { throw new \RuntimeException('Universe changed during backup.'); }
        }
        MaintenanceFiles::json($directory . '/backup.json', $backup);
        return $backup;
    }

    public function check(string $directory, array $backup): void
    {
        if (($backup['format'] ?? '') !== 'world-maintenance-v1' || $backup['fingerprint'] !== $this->fingerprint()) { throw new \RuntimeException('Backup does not belong to this SQL/universe pair.'); }
        foreach ($backup['tables'] as $table) {
            if (@hash_file('sha256', $directory . '/' . $table['name']) !== $table['hash']) { throw new \RuntimeException('SQL backup checksum mismatch.'); }
        }
        if (@hash_file('sha256', $directory . '/sectors.rows') !== $backup['archiveHash']) { throw new \RuntimeException('Sector backup checksum mismatch.'); }
    }

    /** Destructive, explicit maintenance command; restore SQL AND files before starting old code. */
    public function restore(string $directory): void
    {
        $lock = fopen(dirname($directory) . '/lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('A migration or restoration is already running.'); }
        try { $this->restoreLocked($directory); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function restoreLocked(string $directory): void
    {
        $backup = MaintenanceFiles::read($directory . '/backup.json');
        $this->check($directory, $backup); // Complete validation before the first destructive operation.
        if (in_array('storage_migrations', $this->tables(), true)) {
            $this->pdo->exec("UPDATE storage_migrations SET state='blocked' WHERE name='mineable-stocks'");
        }
        $this->pdo->exec($this->mysql() ? 'SET FOREIGN_KEY_CHECKS=0' : 'PRAGMA foreign_keys=OFF');
        try {
            foreach ($this->tables() as $name) { $this->pdo->exec('DROP TABLE ' . $this->quoteName($name)); }
            foreach ($backup['tables'] as $table) { $this->pdo->exec($table['ddl']); }
            $this->pdo->beginTransaction();
            foreach ($backup['tables'] as $name => $table) {
                $prefix = 'INSERT INTO ' . $this->quoteName($name) . '(' . implode(',', array_map($this->quoteName(...), $table['columns'])) . ') VALUES';
                $tuple = '(' . implode(',', array_fill(0, count($table['columns']), '?')) . ')';
                $limit = min(200, intdiv($this->mysql() ? 60000 : 900, count($table['columns'])));
                $batch = []; $bytes = 0;
                $flush = function () use (&$batch, &$bytes, $prefix, $tuple): void {
                    if ($batch === []) { return; }
                    $this->pdo->prepare($prefix . implode(',', array_fill(0, count($batch), $tuple)))->execute(array_merge(...$batch));
                    $batch = []; $bytes = 0;
                };
                $handle = fopen($directory . '/' . $table['name'], 'rb');
                try {
                    while (($line = fgets($handle)) !== false) {
                        $row = unserialize(base64_decode(trim($line), true), ['allowed_classes' => false]);
                        if (!is_array($row)) { throw new \RuntimeException('Invalid SQL backup row.'); }
                        if (count($batch) >= $limit || $bytes + strlen($line) > 1048576) { $flush(); }
                        // PDO's implicit float-to-string conversion can round to PHP's display
                        // precision. JSON uses round-trip precision, preserving the SQL DOUBLE.
                        $batch[] = array_map(static fn($value) => is_float($value) ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : $value, $row);
                        $bytes += strlen($line);
                    }
                    $flush();
                } finally { fclose($handle); }
            }
            if (!$this->mysql() && $backup['sequences'] !== []) {
                $this->pdo->exec('DELETE FROM sqlite_sequence');
                $insert = $this->pdo->prepare('INSERT INTO sqlite_sequence(name,seq) VALUES(?,?)');
                foreach ($backup['sequences'] as $row) { $insert->execute([$row['name'], $row['seq']]); }
            }
            $this->pdo->commit();
            foreach ($backup['tables'] as $table) { foreach ($table['indexes'] as $ddl) { $this->pdo->exec($ddl); } }
            foreach (MaintenanceFiles::sectors($this->universe) as $path) { if (!isset($backup['files'][$path])) { unlink($this->universe . '/' . $path); } }
            $archive = fopen($directory . '/sectors.rows', 'rb');
            try {
                while (($line = fgets($archive)) !== false) {
                    [$path, $raw] = unserialize(base64_decode(trim($line), true), ['allowed_classes' => false]);
                    if (!isset($backup['files'][$path]) || hash('sha256', $raw) !== $backup['files'][$path]) { throw new \RuntimeException('Sector archive entry mismatch.'); }
                    // Unchanged files already have their durable original bytes.
                    if (@hash_file('sha256', $this->universe . '/' . $path) !== $backup['files'][$path]) { MaintenanceFiles::write($this->universe . '/' . $path, $raw); }
                }
            } finally { fclose($archive); }
            foreach ($backup['tables'] as $name => $table) {
                if ((int) $this->pdo->query('SELECT COUNT(*) FROM ' . $this->quoteName($name))->fetchColumn() !== $table['rows']) { throw new \RuntimeException('Restored SQL row count mismatch: ' . $name); }
            }
            foreach ($backup['files'] as $path => $hash) {
                if (hash_file('sha256', $this->universe . '/' . $path) !== $hash) { throw new \RuntimeException('Restored file checksum mismatch: ' . $path); }
            }
        } finally {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            $this->pdo->exec($this->mysql() ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys=ON');
        }
    }

    private function tables(): array
    {
        return $this->pdo->query($this->mysql() ? "SHOW FULL TABLES WHERE Table_type='BASE TABLE'" : "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }
    private function quoteName(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
    private function mysql(): bool { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
}
