<?php

declare(strict_types=1);

namespace VonNeumannGame\Database\Migration;

use PDO;

/** Offline conversion: SQL is authoritative, the manifest precedes every mutation. */
final class ForgottenMannyMigration
{
    public function __construct(private readonly PDO $pdo, private readonly string $universePath) {}

    public function ensureSchema(): bool
    {
        $sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $columns = $sqlite
            ? $this->pdo->query('PRAGMA table_info(mannies)')->fetchAll(PDO::FETCH_ASSOC)
            : $this->pdo->query('SHOW COLUMNS FROM mannies')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $column) {
            if (($column[$sqlite ? 'name' : 'Field']) === 'is_forgotten') { return false; }
        }
        $this->pdo->exec('ALTER TABLE mannies ADD COLUMN is_forgotten ' . ($sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'BOOLEAN NOT NULL DEFAULT FALSE'));
        return true;
    }

    /** Resolutions are explicit per UID: {"acceptSqlCargo":true,"reason":"..."}. */
    public function plan(array $resolutions = []): array
    {
        $this->assertNoPendingPresence();
        $root = realpath($this->universePath);
        if ($root === false || !is_dir($root . '/sectors')) { throw new \RuntimeException('Missing sector directory.'); }
        $files = []; $entries = []; $presenceCounts = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/sectors', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'json') { continue; }
            $raw = file_get_contents($file->getPathname());
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $this->countMannyPresences($data, $presenceCounts);
            $forgotten = $this->presences($data);
            if ($forgotten === []) { continue; }
            $coordinates = $data['coordinates'] ?? null;
            if (!is_array($coordinates) || !isset($coordinates['x'], $coordinates['y'], $coordinates['z'])) { throw new \RuntimeException('Missing sector coordinates.'); }
            foreach ($forgotten as $object) {
                $uid = $object['mannyUid'] ?? '';
                if ($uid === '' || isset($entries[$uid]) || ($object['id'] ?? '') !== 'manny-' . $uid) { throw new \RuntimeException('Duplicate or malformed forgotten Manny: ' . $uid); }
                $entries[$uid] = ['object' => $object, 'sector' => $coordinates];
            }
            $files[] = ['path' => $file->getPathname(), 'beforeHash' => hash('sha256', $raw), 'before' => $raw, 'after' => $data];
        }
        foreach ($entries as $uid => $entry) {
            if (($presenceCounts[$uid] ?? 0) !== 1) { throw new \RuntimeException('Multiple Manny descriptors for UID: ' . $uid); }
        }
        $rows = [];
        foreach (array_chunk(array_keys($entries), 400) as $uids) {
            $query = $this->pdo->prepare('SELECT m.*, p.status AS owner_status, pl.id AS owner_player FROM mannies m LEFT JOIN neumann_probes p ON p.id=m.probe_id LEFT JOIN players pl ON pl.id=p.player_id WHERE m.uid IN (' . implode(',', array_fill(0, count($uids), '?')) . ')');
            $query->execute($uids);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $rows[$row['uid']] = $row; }
        }
        $abandoned = [];
        foreach ($entries as $uid => &$entry) {
            $row = $rows[$uid] ?? null;
            $entry['ownerBefore'] = $row === null ? null : ['status' => $row['owner_status'], 'player' => $row['owner_player']];
            $resolution = $resolutions[$uid] ?? null;
            $orphan = $row === null || $row['owner_player'] === null || in_array($row['owner_status'], ['dead', 'trapped_by_black_hole'], true);
            if ($orphan && (($resolution['action'] ?? null) !== 'abandon' || trim($resolution['reason'] ?? '') === '')) {
                throw new \RuntimeException('Forgotten Manny requires explicit orphan resolution before conversion: ' . $uid);
            }
            if ($orphan && $row !== null && ($row['current_task'] !== null || (float) $row['reserved_cargo_space'] !== 0.0 || $row['reserved_storage_container_id'] !== null)) {
                throw new \RuntimeException('Cancel orphan tasks and reservations with the old business code before conversion: ' . $uid);
            }
            $entry['abandon'] = $orphan;
            if ($row === null) {
                foreach (['deuterium', 'metals', 'ice', 'organicCompounds'] as $type) {
                    $amount = $entry['object']['cargo'][$type] ?? null;
                    if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) { throw new \RuntimeException('Orphan cargo is invalid: ' . $uid); }
                }
                $entry['sqlBefore'] = null;
                $entry['cargoDifferences'] = [];
                $entry['resolution'] = $resolution;
                $abandoned[$uid] = $entry['object'];
                continue;
            }
            if ($row['location_type'] !== 'sector') { throw new \RuntimeException('Forgotten Manny is no longer deployed: ' . $uid); }
            foreach (['x', 'y', 'z'] as $axis) {
                if ($row['sector_' . $axis] === null || (int) $row['sector_' . $axis] !== (int) $entry['sector'][$axis]) { throw new \RuntimeException('Forgotten Manny sector mismatch: ' . $uid); }
            }
            $differences = [];
            foreach (['deuterium', 'metals', 'ice', 'organicCompounds'] as $type) {
                $value = $entry['object']['cargo'][$type] ?? null;
                $column = 'cargo_' . ($type === 'organicCompounds' ? 'organic_compounds' : $type);
                if (!is_numeric($row[$column]) || !is_finite((float) $row[$column]) || (float) $row[$column] < 0) { throw new \RuntimeException('Invalid SQL cargo: ' . $uid); }
                if (!is_numeric($value) || round((float) $value, 4) !== round((float) $row[$column], 4)) { $differences[] = $type; }
            }
            $resolution = $resolutions[$uid] ?? null;
            if ($differences !== [] && (!(bool) ($resolution['acceptSqlCargo'] ?? false) || trim($resolution['reason'] ?? '') === '')) {
                throw new \RuntimeException('Cargo divergence needs a documented SQL-authority resolution: ' . $uid);
            }
            if ($orphan) {
                $abandoned[$uid] = $entry['object'];
                foreach (['deuterium', 'metals', 'ice', 'organicCompounds'] as $type) {
                    $abandoned[$uid]['cargo'][$type] = (float) $row['cargo_' . ($type === 'organicCompounds' ? 'organic_compounds' : $type)];
                }
            }
            unset($row['owner_status'], $row['owner_player'], $row['is_forgotten']);
            $entry['sqlBefore'] = $row;
            $entry['cargoDifferences'] = $differences;
            $entry['resolution'] = $resolution;
        }
        unset($entry);
        foreach ($files as &$file) {
            $file['after'] = json_encode($this->withoutForgotten($file['after'], $abandoned), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $file['afterHash'] = hash('sha256', $file['after']);
        }
        return ['format' => 'forgotten-mannies-sql-v1', 'universe' => $root, 'entries' => $entries, 'files' => $files, 'complete' => false];
    }

    /** API and workers must stay stopped, including on resume. No live migration. */
    public function apply(string $manifestPath, array $resolutions = []): array
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Migration requires its own maintenance connection.'); }
        if (is_file($manifestPath)) {
            $plan = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (($plan['format'] ?? null) !== 'forgotten-mannies-sql-v1' || ($plan['universe'] ?? null) !== realpath($this->universePath)) { throw new \RuntimeException('Manifest does not match this universe.'); }
        } else {
            $plan = $this->plan($resolutions);
            $this->writeManifest($manifestPath, $plan);
        }
        $this->assertNoPendingPresence();
        // Verify all files before SQL, including already published files at resume.
        foreach ($plan['files'] as $file) {
            if (!in_array(hash_file('sha256', $file['path']), [$file['beforeHash'], $file['afterHash']], true)) { throw new \RuntimeException('Sector changed since migration planning.'); }
        }
        $this->ensureSchema();
        $this->pdo->beginTransaction();
        try {
            foreach ($plan['entries'] as $uid => $entry) {
                $query = $this->pdo->prepare('SELECT * FROM mannies WHERE uid=?' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                $query->execute([$uid]);
                $row = $query->fetch(PDO::FETCH_ASSOC);
                if ($entry['sqlBefore'] === null && !$row) { continue; }
                if (!$row || $entry['sqlBefore'] === null) { throw new \RuntimeException('Manny existence changed since planning: ' . $uid); }
                if (!$entry['abandon']) {
                    $owner = $this->pdo->prepare('SELECT p.status, pl.id AS player FROM neumann_probes p LEFT JOIN players pl ON pl.id=p.player_id WHERE p.id=?');
                    $owner->execute([$row['probe_id']]);
                    if ($owner->fetch(PDO::FETCH_ASSOC) != $entry['ownerBefore']) { throw new \RuntimeException('Manny owner changed since planning: ' . $uid); }
                }
                unset($row['is_forgotten']);
                $expectedAfter = $entry['sqlBefore'];
                if ($entry['abandon']) { $expectedAfter['probe_id'] = null; $expectedAfter['storage_container_id'] = null; }
                if ($row != $entry['sqlBefore'] && $row != $expectedAfter) { throw new \RuntimeException('SQL state changed since migration planning: ' . $uid); }
                if ($entry['abandon']) {
                    $this->pdo->prepare('UPDATE mannies SET is_forgotten=0, probe_id=NULL, storage_container_id=NULL WHERE uid=?')->execute([$uid]);
                } else {
                    $this->pdo->prepare('UPDATE mannies SET is_forgotten=1 WHERE uid=?')->execute([$uid]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
        foreach ($plan['files'] as $file) {
            if (hash_file('sha256', $file['path']) === $file['afterHash']) { continue; }
            if (hash_file('sha256', $file['path']) !== $file['beforeHash']) { throw new \RuntimeException('Sector changed during maintenance.'); }
            $this->atomicWrite($file['path'], $file['after']);
        }
        $plan['complete'] = true;
        $this->writeManifest($manifestPath, $plan);
        return ['complete' => true, 'mannies' => count($plan['entries']), 'files' => count($plan['files'])];
    }

    private function assertNoPendingPresence(): void
    {
        $query = $this->pdo->query("SELECT payload_json FROM sector_effects WHERE status='pending'");
        while (($payload = $query->fetchColumn()) !== false) {
            if ($this->presences(json_decode($payload, true, 512, JSON_THROW_ON_ERROR)) !== []) { throw new \RuntimeException('Drain forgotten Manny sector effects with the previous code before migration.'); }
        }
    }

    private function countMannyPresences(array $data, array &$counts): void
    {
        if (($data['type'] ?? null) === 'manny') {
            $uid = (string) ($data['mannyUid'] ?? '');
            $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            return;
        }
        foreach ($data as $value) { if (is_array($value)) { $this->countMannyPresences($value, $counts); } }
    }

    private function presences(array $data): array
    {
        if (($data['type'] ?? null) === 'manny' && ($data['state'] ?? null) === 'forgotten') { return [$data]; }
        $result = [];
        foreach ($data as $value) { if (is_array($value)) { array_push($result, ...$this->presences($value)); } }
        return $result;
    }

    private function withoutForgotten(array $data, array $abandoned): array
    {
        foreach ($data as $key => $value) {
            if (!is_array($value)) { continue; }
            if (($value['type'] ?? null) === 'manny' && ($value['state'] ?? null) === 'forgotten') {
                if (isset($abandoned[$value['mannyUid']])) {
                    $data[$key] = array_replace($abandoned[$value['mannyUid']], ['state' => 'abandoned', 'description' => 'Manny abandoned after its owner disappeared.']);
                } else { unset($data[$key]); }
            } else { $data[$key] = $this->withoutForgotten($value, $abandoned); }
        }
        // Object arrays must remain arrays even after removing a middle element.
        if (isset($data['objects'])) { $data['objects'] = array_values($data['objects']); }
        return $data;
    }

    private function writeManifest(string $path, array $plan): void
    {
        $this->atomicWrite($path, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    private function atomicWrite(string $path, string $content): void
    {
        $temporary = $path . '.migration-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) { throw new \RuntimeException('Incomplete migration file write.'); }
        chmod($temporary, is_file($path) ? fileperms($path) & 0777 : 0600);
        if (!rename($temporary, $path)) { throw new \RuntimeException('Cannot publish migration file.'); }
    }
}
