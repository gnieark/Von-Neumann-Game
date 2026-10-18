<?php
declare(strict_types=1);
namespace VonNeumannGame\Database\Migration;

use PDO;
use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Repository\MineableObjectStockRepository;
use VonNeumannGame\Repository\StorageMigrationRepository;
use VonNeumannGame\Sector\MineableDescriptors;

/** Maintenance orchestration. Each cross-store phase is verifiable and replayable. */
final class MineableStockMigration
{
    public function __construct(private readonly PDO $pdo, private readonly string $universe, private readonly ?\Closure $checkpoint = null, private readonly ?\Closure $progress = null) {}

    public function apply(string $directory, array $resolutions, \Closure $recall): array
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Migration owns its maintenance connection.'); }
        $directory = rtrim($directory, '/');
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) { throw new \RuntimeException('Cannot create manifest directory.'); }
        $lock = fopen($directory . '/lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('This migration is already running.'); }
        try {
            $backup = new WorldMaintenanceBackup($this->pdo, $this->universe);
            $metaPath = $directory . '/manifest.json';
            if (is_file($metaPath)) {
                $meta = MaintenanceFiles::read($metaPath);
                if (($meta['format'] ?? '') !== 'mineable-stocks-migration-v1' || $meta['fingerprint'] !== $backup->fingerprint()) { throw new \RuntimeException('Manifest does not match this SQL/universe pair.'); }
                if ($resolutions !== [] && $resolutions != $meta['resolutions']) { throw new \RuntimeException('Use the original resolutions when resuming.'); }
            } else {
                if (str_starts_with(realpath($directory) . '/', realpath($this->universe) . '/')) { throw new \RuntimeException('Keep the manifest and backup outside the universe.'); }
                $meta = ['format' => 'mineable-stocks-migration-v1', 'migrationId' => bin2hex(random_bytes(24)), 'fingerprint' => $backup->fingerprint(),
                    'codeRevision' => trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')), 'resolutions' => $resolutions, 'phases' => [], 'createdAt' => gmdate('c')];
                MaintenanceFiles::json($metaPath, $meta);
            }
            $code = $this->codeFingerprint();
            if (!isset($meta['executionCode'][$code])) {
                $meta['executionCode'][$code] = gmdate('c');
                MaintenanceFiles::json($metaPath, $meta);
            }
            $phase = function (string $name, callable $operation) use (&$meta, $metaPath): void {
                if (isset($meta['phases'][$name])) { return; }
                $this->report('Starting ' . $name);
                $started = microtime(true);
                $operation();
                $this->hit($name . ':committed');
                $meta['phases'][$name] = ['finishedAt' => gmdate('c'), 'seconds' => round(microtime(true) - $started, 3)];
                MaintenanceFiles::json($metaPath, $meta);
                $this->report('Completed ' . $name . ' in ' . $meta['phases'][$name]['seconds'] . 's');
            };
            $phase('backup', fn() => $backup->create($directory . '/backup'));
            $backup->check($directory . '/backup', MaintenanceFiles::read($directory . '/backup/backup.json'));
            $phase('schema', function () use ($meta): void {
                $this->schema($meta['migrationId']);
            });
            $gate = new StorageMigrationRepository($this->pdo);
            if (($gate->state()['migration_id'] ?? null) !== $meta['migrationId']) { throw new \RuntimeException('Database migration identity changed; do not reuse a manifest after restoration.'); }
            $phase('effects', fn() => (new LegacySectorEffectDrain($this->pdo, $this->universe))->apply($directory));
            if (!is_file($directory . '/plan.json')) {
                $this->report('Building occurrence plan');
                $plan = (new MineableStockMigrationPlan($this->pdo, $this->universe))->build($meta['migrationId'], $directory, $meta['resolutions']);
                MaintenanceFiles::json($directory . '/plan.json', $plan);
                $this->report('Occurrence plan persisted');
            }
            $plan = MaintenanceFiles::read($directory . '/plan.json');
            $gate->bindPlan($meta['migrationId'], hash_file('sha256', $directory . '/plan.json'));
            $stocks = new MineableObjectStockRepository($this->pdo);
            $phase('stocks', function () use ($plan, $stocks): void {
                foreach (array_chunk($plan['reservedIds'], 400) as $ids) {
                    (new StorageTransaction($this->pdo))->run(function () use ($stocks, $ids): void { foreach ($ids as $id) { $stocks->reserveHistoricalIdentity($id); } });
                }
                foreach (array_chunk($plan['sources'], 200) as $batch) {
                    (new StorageTransaction($this->pdo))->run(function () use ($stocks, $batch, $plan): void {
                        foreach ($batch as $source) { $stocks->importExisting($plan['migrationId'] . ':' . $source['location'], $source['newId'], $source['amounts']); }
                    });
                    $this->hit('stocks:batch');
                }
            });
            $this->verifyStocks($plan);
            $phase('references', function () use ($plan): void {
                foreach ($plan['sqlChanges'] as $change) {
                    (new StorageTransaction($this->pdo))->run(function () use ($change): void {
                        $row = $this->changedRow($change);
                        foreach ($change['after'] as $column => $value) {
                            if ($row[$column] === $value) { continue; }
                            if ($row[$column] !== $change['before'][$column]) { throw new \RuntimeException('Operational SQL reference changed during migration.'); }
                            $this->pdo->prepare('UPDATE ' . $change['table'] . ' SET ' . $column . '=? WHERE ' . $change['key'] . '=?')->execute([$value, $change['id']]);
                        }
                    });
                    $this->hit('references:row');
                }
            });
            $phase('descriptors', function () use ($plan, $directory): void {
                foreach ($plan['allFiles'] as $path => $hash) {
                    $current = @hash_file('sha256', $this->universe . '/sectors/' . $path);
                    $expected = $plan['files'][$path] ?? ['beforeHash' => $hash, 'afterHash' => $hash];
                    if (!in_array($current, [$expected['beforeHash'], $expected['afterHash']], true)) { throw new \RuntimeException('Sector changed since planning: ' . $path); }
                }
                foreach ($plan['files'] as $path => $hashes) {
                    $output = $directory . '/converted/' . $path;
                    if (hash_file('sha256', $output) !== $hashes['afterHash']) { throw new \RuntimeException('Staged descriptor checksum mismatch.'); }
                    $target = $this->universe . '/sectors/' . $path;
                    if (hash_file('sha256', $target) !== $hashes['afterHash']) { MaintenanceFiles::write($target, file_get_contents($output)); }
                    $this->hit('descriptors:file');
                }
            });
            $phase('forgotten', fn() => (new ForgottenMannyMigration($this->pdo, $this->universe))->apply($directory . '/forgotten.json', $plan['resolutions']['forgottenMannies'] ?? []));
            $forgotten = MaintenanceFiles::read($directory . '/forgotten.json');
            (new MaintenanceRecallSectors($this->pdo, $this->universe))->prepare($directory, $plan['failedEvents'], $this->expectedFiles($plan, $forgotten));
            $phase('recalls', function () use ($plan, $recall, $directory): void {
                MaintenanceFiles::json($directory . '/recalls.json', $recall($plan['migrationId'], $plan['failedEvents']));
            });
            $this->report('Verifying stocks, references, forgotten Mannies and sector hashes');
            $result = $this->verify($directory, true);
            $this->hit('verified:before-open');
            $gate->verified($meta['migrationId']);
            $meta['complete'] = true; $meta['verification'] = $result;
            MaintenanceFiles::json($metaPath, $meta);
            return $meta;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function schema(?string $migrationId = null): void
    {
        $initializer = new SchemaInitializer($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        foreach ($initializer->storageMigrationStatements() as $sql) { $this->pdo->exec($sql); }
        if ($migrationId !== null) { (new StorageMigrationRepository($this->pdo))->begin($migrationId); }
        foreach ([...$initializer->mineableObjectStockStatements(), ...$initializer->probeOperationStatements(), ...$initializer->miningOrderLockStatements()] as $sql) { $this->pdo->exec($sql); }
        (new SectorCreationSchemaMigration($this->pdo))->run(true);
        (new ForgottenMannyMigration($this->pdo, $this->universe))->ensureSchema();
        $columns = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? array_column($this->pdo->query('PRAGMA table_info(scheduled_events)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : array_column($this->pdo->query('SHOW COLUMNS FROM scheduled_events')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        foreach ($initializer->schedulerConflictColumns() as $name => $definition) {
            if (!in_array($name, $columns, true)) { $this->pdo->exec("ALTER TABLE scheduled_events ADD COLUMN $name $definition"); }
        }
        (new StorageTransaction($this->pdo))->run(function (): void {
            $operations = new \VonNeumannGame\Repository\ProbeOperationRepository($this->pdo);
            $ids = $this->pdo->query('SELECT p.id FROM neumann_probes p LEFT JOIN probe_operation_gates g ON g.probe_id=p.id WHERE g.probe_id IS NULL')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) { $operations->create((int) $id); }
            $losses = new \VonNeumannGame\Repository\ProbeReinstantiationRepository($this->pdo);
            foreach ($this->pdo->query("SELECT id,updated_at FROM neumann_probes WHERE status IN ('dead','trapped_by_black_hole')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $losses->requestLoss((int) $row['id'], 'migration_terminal', static function (): void {}, $row['updated_at']);
                if (!$operations->close((int) $row['id'])) { throw new \RuntimeException('A production writer is still active.'); }
            }
        });
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM probe_operations')->fetchColumn() !== 0) { throw new \RuntimeException('Outstanding probe admissions must be resolved before maintenance.'); }
    }

    public function verify(string $directory, bool $recordFiles = false): array
    {
        $plan = MaintenanceFiles::read($directory . '/plan.json');
        $meta = MaintenanceFiles::read($directory . '/manifest.json');
        if ($meta['fingerprint'] !== (new WorldMaintenanceBackup($this->pdo, $this->universe))->fingerprint()) { throw new \RuntimeException('Wrong database/universe pair.'); }
        $gate = new StorageMigrationRepository($this->pdo);
        if (($gate->state()['migration_id'] ?? null) !== $plan['migrationId'] || ($gate->state()['plan_hash'] ?? null) !== hash_file('sha256', $directory . '/plan.json')) { throw new \RuntimeException('Plan differs from its SQL proof.'); }
        $this->verifyStocks($plan);
        $forgotten = MaintenanceFiles::read($directory . '/forgotten.json');
        $expectedFiles = (new MaintenanceRecallSectors($this->pdo, $this->universe))->verify($directory, $this->expectedFiles($plan, $forgotten), $recordFiles);
        $recalledMannies = [];
        foreach ($plan['failedEvents'] as $event) {
            $stmt = $this->pdo->prepare('SELECT result_json FROM storage_migration_recalls WHERE event_id=? AND migration_id=?');
            $stmt->execute([$event['id'], $plan['migrationId']]);
            $raw = $stmt->fetchColumn();
            if ($raw === false) { throw new \RuntimeException('Missing failed-event resolution receipt.'); }
            $receipt = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $stmt = $this->pdo->prepare('SELECT status FROM scheduled_events WHERE id=?'); $stmt->execute([$event['id']]);
            if ($stmt->fetchColumn() !== 'cancelled') { throw new \RuntimeException('Failed event was not cancelled.'); }
            // Several obsolete failures can refer to one Manny; the latest receipt owns its final state.
            $recalledMannies[$receipt['mannyId']] = $receipt;
        }
        foreach ($recalledMannies as $id => $receipt) {
            $stmt = $this->pdo->prepare('SELECT * FROM mannies WHERE id=?'); $stmt->execute([$id]);
            if (($stmt->fetch(PDO::FETCH_ASSOC) ?: null) != $receipt['mannyAfter']) { throw new \RuntimeException('Manny changed after maintenance recall: ' . $id); }
            $stmt = $this->pdo->prepare('SELECT * FROM manny_tasks WHERE manny_id=?'); $stmt->execute([$id]);
            if (($stmt->fetch(PDO::FETCH_ASSOC) ?: null) != $receipt['taskProjectionAfter']) { throw new \RuntimeException('Manny task projection changed after recall: ' . $id); }
            $stmt = $this->pdo->prepare('SELECT * FROM scheduled_events WHERE id=?'); $stmt->execute([$receipt['mannyAfter']['task_scheduled_event_id'] ?? null]);
            if (($stmt->fetch(PDO::FETCH_ASSOC) ?: null) != $receipt['eventAfter']) { throw new \RuntimeException('Manny return event changed after recall: ' . $id); }
        }
        foreach ($forgotten['entries'] as $uid => $entry) {
            if ($entry['sqlBefore'] !== null && array_key_exists((int) $entry['sqlBefore']['id'], $recalledMannies)) { continue; }
            $stmt = $this->pdo->prepare('SELECT * FROM mannies WHERE uid=?'); $stmt->execute([$uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $expected = $entry['sqlBefore'];
            if ($expected !== null) {
                $expected['is_forgotten'] = $entry['abandon'] ? 0 : 1;
                if ($entry['abandon']) { $expected['probe_id'] = null; $expected['storage_container_id'] = null; }
            }
            if ($row != $expected) { throw new \RuntimeException('Forgotten Manny changed after conversion: ' . $uid); }
        }
        $found = []; $files = [];
        $walk = function (array $data, string $where) use (&$walk, &$found): void {
            if (($data['type'] ?? null) === 'manny' && ($data['state'] ?? null) === 'forgotten') { throw new \RuntimeException('Forgotten presence remains: ' . $where); }
            if (MineableDescriptors::covers($data)) {
                if (array_key_exists('resourceAmounts', $data) || isset($found[$data['id']])) { throw new \RuntimeException('Noncanonical/duplicate source remains: ' . $where); }
                $found[$data['id']] = true;
            }
            foreach ($data as $key => $value) { if (is_array($value)) { $walk($value, $where . '/' . $key); } }
        };
        foreach (MaintenanceFiles::sectors($this->universe) as $path) {
            $walk(MaintenanceFiles::read($this->universe . '/' . $path), $path);
            $files[$path] = hash_file('sha256', $this->universe . '/' . $path);
            if (($expectedFiles[$path] ?? null) !== $files[$path]) { throw new \RuntimeException('Sector differs from the documented transformations: ' . $path); }
        }
        if (count($files) !== count($expectedFiles)) { throw new \RuntimeException('Sector file inventory changed.'); }
        foreach ($plan['sources'] as $source) {
            if (!str_starts_with($source['location'], 'sql:') && !isset($found[$source['newId']])) { throw new \RuntimeException('A migrated source disappeared: ' . $source['newId']); }
        }
        foreach ($plan['sqlChanges'] as $change) {
            // Recalls deliberately replace only failed events/tasks, and are proven by their receipts.
            if ($change['table'] === 'manny_tasks' && ($change['associatedEvent'] ?? null) !== null) {
                $receipt = $this->pdo->prepare('SELECT result_json FROM storage_migration_recalls WHERE event_id=? AND migration_id=?');
                $receipt->execute([$change['associatedEvent'], $plan['migrationId']]);
                $result = $receipt->fetchColumn();
                if ($result !== false && (json_decode($result, true, 512, JSON_THROW_ON_ERROR)['result'] ?? null) === 'recalled') { continue; }
            }
            $row = $this->changedRow($change);
            foreach ($change['after'] as $column => $expected) { if ($row[$column] !== $expected) { throw new \RuntimeException('SQL reference verification failed.'); } }
        }
        if ((int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_events WHERE type='manny.task' AND status='failed'")->fetchColumn() !== 0) { throw new \RuntimeException('Failed Manny events remain unresolved.'); }
        if ((int) $this->pdo->query("SELECT COUNT(*) FROM sector_effects WHERE status='pending'")->fetchColumn() !== 0) { throw new \RuntimeException('Unpublished sector intentions remain; publish them explicitly before verification.'); }
        if (is_file($directory . '/verified-files.json')) {
            if (MaintenanceFiles::read($directory . '/verified-files.json') !== $files) { throw new \RuntimeException('Files changed after verification.'); }
        } elseif ($recordFiles) { MaintenanceFiles::json($directory . '/verified-files.json', $files); }
        else { throw new \RuntimeException('Migration has not completed file verification.'); }
        return ['verified' => true, 'sources' => count($plan['sources']), 'files' => count($files), 'renamed' => count(array_filter($plan['sources'], fn($s) => $s['objectId'] !== $s['newId'])), 'totals' => $plan['totals']];
    }

    private function verifyStocks(array $plan): void
    {
        $stocks = new MineableObjectStockRepository($this->pdo);
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM mineable_object_stocks')->fetchColumn() !== count($plan['sources'])) { throw new \RuntimeException('SQL stock inventory differs from authoritative sources.'); }
        foreach (array_chunk($plan['sources'], 400) as $batch) {
            $rows = $stocks->findMany(array_column($batch, 'newId'));
            foreach ($batch as $source) {
                if ($rows[$source['newId']]['version'] !== 1 || $rows[$source['newId']]['amounts'] !== array_map('floatval', $source['amounts'])) { throw new \RuntimeException('Imported stock missing or changed: ' . $source['newId']); }
            }
        }
        foreach (array_chunk($plan['reservedIds'], 400) as $ids) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mineable_object_identities WHERE object_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'); $stmt->execute($ids);
            if ((int) $stmt->fetchColumn() !== count($ids)) { throw new \RuntimeException('Historical identity reservation missing.'); }
        }
    }

    private function changedRow(array $change): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $change['table'] . ' WHERE ' . $change['key'] . '=?'); $stmt->execute([$change['id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new \RuntimeException('Operational SQL row disappeared.');
    }
    private function hit(string $point): void { if ($this->checkpoint !== null) { ($this->checkpoint)($point); } }
    private function report(string $message): void { if ($this->progress !== null) { ($this->progress)($message); } }

    private function codeFingerprint(): string
    {
        $root = dirname(__DIR__, 3); $files = [];
        foreach (['src', 'scripts/one-shot-scripts'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') { $files[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname()); }
            }
        }
        ksort($files, SORT_STRING);
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    private function expectedFiles(array $plan, array $forgotten): array
    {
        $files = [];
        foreach ($plan['allFiles'] as $path => $hash) { $files['sectors/' . $path] = $plan['files'][$path]['afterHash'] ?? $hash; }
        foreach ($forgotten['files'] as $file) { $files[substr($file['path'], strlen(realpath($this->universe)) + 1)] = $file['afterHash']; }
        return $files;
    }
}
