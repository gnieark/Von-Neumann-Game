<?php
declare(strict_types=1);
namespace VonNeumannGame\Database\Migration;

use PDO;
use VonNeumannGame\Domain\AsteroidTrajectory;
use VonNeumannGame\Repository\MineableObjectStockRepository;
use VonNeumannGame\Sector\MineableDescriptors;

/** Immutable occurrence-based plan, written before the first identity/stock mutation. */
final class MineableStockMigrationPlan
{
    private array $sources = [];
    private array $byId = [];
    private array $resolutions = [];
    private array $decisions = [];

    public function __construct(private readonly PDO $pdo, private readonly string $universe) {}

    public function build(string $migrationId, ?string $staging = null, array $resolutions = []): array
    {
        $this->resolutions = $resolutions;
        $this->decisions = [];
        $this->pdo->beginTransaction();
        try { $audit = (new MineableObjectStockAudit(1000000, 5000000, true))->inspect($this->pdo, $this->universe); }
        finally { $this->pdo->rollBack(); }
        $tolerated = ['duplicate_identity', 'ambiguous_reference', 'stock_needs_rounding', 'forgotten_cargo_requires_review', 'forgotten_manny_owner_lost', 'forgotten_manny_missing_sql'];
        foreach ($audit['anomalies'] as $issue) {
            if ($issue['severity'] === 'error' && !in_array($issue['code'], $tolerated, true)) {
                throw new \RuntimeException('Migration blocked: ' . $issue['code'] . ' at ' . $issue['location']);
            }
        }
        // Reuse the explicit, strict SQL-authority decisions of lot 2 bis before any import.
        (new ForgottenMannyMigration($this->pdo, $this->universe))->plan($resolutions['forgottenMannies'] ?? []);
        $this->sources = $audit['sources'];
        usort($this->sources, static fn($a, $b) => strcmp($a['location'], $b['location']));
        $this->byId = []; $reserved = [];
        foreach ([...$audit['sources'], ...$audit['snapshots']] as $source) { $reserved[$source['objectId']] = true; }
        foreach ($audit['references'] as $reference) { $reserved[$reference['objectId']] = true; }
        // Terminal scheduler payloads can still identify consumed objects. Reserve their IDs,
        // without treating those snapshots as current stock or rewriting historical events.
        $cursor = 0;
        do {
            $stmt = $this->pdo->prepare("SELECT id,payload_json FROM scheduled_events WHERE status IN ('done','cancelled') AND id>? ORDER BY id LIMIT 400");
            $stmt->execute([$cursor]); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                self::reserveReferences(json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR), $reserved);
            }
        } while (count($rows) === 400);
        if ($this->hasIdentityTable()) {
            foreach ($this->pdo->query('SELECT object_id FROM mineable_object_identities')->fetchAll(PDO::FETCH_COLUMN) as $id) { $reserved[$id] = true; }
        }
        foreach ($reserved as $id => $_) { MineableObjectStockRepository::validateId((string) $id); }
        foreach ($this->sources as $i => &$source) {
            if ($source['amounts'] === null) { throw new \RuntimeException('Source stock is not defined: ' . $source['location']); }
            $id = $source['objectId']; $source['newId'] = $id;
            if (isset($this->byId[$id])) {
                $n = 0;
                do {
                    $hash = hash('sha256', $migrationId . ':' . $source['location'] . ':' . $n++);
                    $new = preg_match('/^(.*?)([a-f0-9]{16,64})$/D', $id, $prefix) ? $prefix[1] . substr($hash, 0, strlen($prefix[2])) : 'ast_migration_' . substr($hash, 0, 32);
                } while (isset($reserved[$new]));
                $source['newId'] = $new; $reserved[$new] = true;
            }
            $this->byId[$id][] = $i;
        }
        unset($source);
        $fileChanges = []; $sqlChanges = [];
        foreach ($this->sources as $source) {
            if (!str_starts_with($source['location'], 'sql:')) {
                [$path, $pointer] = explode('#', $source['location'], 2);
                $fileChanges[$path][$pointer . '/id'] = $source['newId'];
            }
        }
        foreach ($audit['references'] as $reference) {
            if (!$reference['active']) { continue; }
            $new = $this->resolve($reference['objectId'], $reference['location'], $reference['sector']);
            if ($new === $reference['objectId']) { continue; }
            if (!str_starts_with($reference['location'], 'sql:')) {
                [$path, $pointer] = explode('#', $reference['location'], 2); $fileChanges[$path][$pointer] = $new;
            } else {
                [$table, $id, $column, $pointer] = $this->sqlLocation($reference['location']);
                $key = $table === 'detached_storage_containers' ? 'object_id' : 'id';
                $rowKey = $table . '/' . $id;
                if (!isset($sqlChanges[$rowKey])) {
                    $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE $key=?"); $stmt->execute([$id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $sqlChanges[$rowKey] = ['table' => $table, 'key' => $key, 'id' => $id, 'before' => [], 'after' => [], 'associatedEvent' => $row['scheduled_event_id'] ?? null, 'row' => $row];
                }
                $change =& $sqlChanges[$rowKey];
                $change['before'][$column] = $change['row'][$column];
                if ($pointer === '') { $change['after'][$column] = $new; }
                else {
                    $data = json_decode($change['after'][$column] ?? $change['row'][$column], true, 512, JSON_THROW_ON_ERROR);
                    MaintenanceFiles::put($data, $pointer, $new);
                    $change['after'][$column] = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                }
                unset($change);
            }
        }
        // Current file owns non-transit stocks; crossing snapshots alone own transit stocks.
        foreach ($this->pdo->query('SELECT * FROM asteroid_trajectories ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array($row['status'], AsteroidTrajectory::ACTIVE_STATUSES, true)) { continue; }
            $location = 'sql:asteroid_trajectories/' . $row['id'];
            $sector = ['x' => (int) $row['current_sector_x'], 'y' => (int) $row['current_sector_y'], 'z' => (int) $row['current_sector_z']];
            $new = $this->resolve($row['asteroid_id'], $location . '/asteroid_id', $sector);
            $snapshot = json_decode($row['asteroid_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            $snapshot['id'] = $new;
            $snapshot = self::strip($snapshot);
            $change = $sqlChanges['asteroid_trajectories/' . $row['id']] ?? ['table' => 'asteroid_trajectories', 'key' => 'id', 'id' => $row['id'], 'before' => [], 'after' => []];
            $change['before']['asteroid_snapshot_json'] = $row['asteroid_snapshot_json'];
            $change['after']['asteroid_snapshot_json'] = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $sqlChanges['asteroid_trajectories/' . $row['id']] = $change;
        }
        // Operational nested snapshots must also become descriptors, even without an ID collision.
        foreach (['scheduled_events' => "status IN ('pending','running','failed')", 'others_actions' => "status NOT IN ('completed','cancelled','succeeded')", 'asteroid_trajectories' => "status IN ('accelerating','coasting','crossing_sector','orbiting_black_hole')"] as $table => $where) {
            foreach ($this->pdo->query("SELECT * FROM $table WHERE $where ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                foreach (['payload_json', 'attachments_snapshot_json'] as $column) {
                    if (!is_string($row[$column] ?? null)) { continue; }
                    $key = $table . '/' . $row['id'];
                    $before = $sqlChanges[$key]['after'][$column] ?? $row[$column];
                    $data = json_decode($before, true, 512, JSON_THROW_ON_ERROR); $after = self::strip($data);
                    if ($data === $after) { continue; }
                    $sqlChanges[$key] ??= ['table' => $table, 'key' => 'id', 'id' => $row['id'], 'before' => [], 'after' => []];
                    $sqlChanges[$key]['before'][$column] = $row[$column];
                    $sqlChanges[$key]['after'][$column] = json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                }
            }
        }
        foreach ($sqlChanges as &$change) { unset($change['row']); } unset($change);
        $files = [];
        foreach ($fileChanges as $path => $patches) {
            $absolute = $this->universe . '/sectors/' . $path;
            $raw = file_get_contents($absolute); $beforeHash = hash('sha256', $raw);
            if ($beforeHash !== $audit['files'][$path]) { throw new \RuntimeException('File changed during planning: ' . $path); }
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            foreach ($patches as $pointer => $value) { MaintenanceFiles::put($data, $pointer, $value); }
            $output = json_encode(self::strip($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
            $afterHash = hash('sha256', $output);
            if ($staging !== null) { MaintenanceFiles::write($staging . '/converted/' . $path, $output); }
            $files[$path] = compact('beforeHash', 'afterHash');
        }
        foreach ($reserved as $id => $_) { MineableObjectStockRepository::validateId((string) $id); }
        return ['format' => 'mineable-stocks-plan-v1', 'migrationId' => $migrationId, 'universe' => realpath($this->universe),
            'sources' => $this->sources, 'reservedIds' => array_map('strval', array_keys($reserved)), 'files' => $files, 'allFiles' => $audit['files'],
            'counts' => $audit['counts'], 'subtotals' => $audit['subtotals'],
            'referencesWithoutLocalSource' => array_values(array_filter($audit['references'], fn($r) => $r['active'] && $r['sourceOccurrences'] === [])),
            'sqlChanges' => array_values($sqlChanges), 'normalizations' => [...$audit['normalizations'], ...array_values(array_filter($audit['anomalies'], fn($a) => $a['code'] === 'stock_needs_rounding'))], 'totals' => $audit['totals'],
            'decisions' => $this->decisions, 'resolutions' => $resolutions,
            'failedEvents' => $this->pdo->query("SELECT * FROM scheduled_events WHERE type='manny.task' AND status='failed' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function hasIdentityTable(): bool
    {
        $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mineable_object_identities'"
            : "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mineable_object_identities'";
        return (int) $this->pdo->query($sql)->fetchColumn() === 1;
    }

    private static function reserveReferences(array $data, array &$ids): void
    {
        if (MineableDescriptors::covers($data) && is_string($data['id'] ?? null)) { $ids[$data['id']] = true; }
        $keys = ['objectId', 'targetObjectId', 'asteroidId', 'capturedByObjectId', 'object_id', 'target_object_id', 'asteroid_id', 'object_public_id'];
        foreach ($data as $key => $value) {
            if (is_array($value)) { self::reserveReferences($value, $ids); }
            elseif (in_array($key, $keys, true) && is_string($value) && $value !== '') { $ids[$value] = true; }
        }
    }

    private function resolve(string $id, string $location, ?array $sector): string
    {
        $candidates = $this->byId[$id] ?? [];
        if (count($candidates) <= 1) { return $candidates === [] ? $id : $this->sources[$candidates[0]]['newId']; }
        foreach ($candidates as $i) {
            if (str_starts_with($location, $this->sources[$i]['location'] . '/')) { return $this->sources[$i]['newId']; }
        }
        if ($sector === null && preg_match('~^sql:scheduled_events/(\d+)/~', $location, $match)) {
            $stmt = $this->pdo->prepare("SELECT COALESCE(m.sector_x,p.sector_x) AS x, COALESCE(m.sector_y,p.sector_y) AS y, COALESCE(m.sector_z,p.sector_z) AS z
                FROM scheduled_events e JOIN mannies m ON e.entity_type='manny' AND m.id=e.entity_id LEFT JOIN neumann_probes p ON p.id=m.probe_id WHERE e.id=?");
            $stmt->execute([$match[1]]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['x'] !== null) { $sector = array_map('intval', $row); }
        }
        $local = $sector === null ? $candidates : array_values(array_filter($candidates, fn($i) => $this->sources[$i]['sector'] === $sector));
        if (count($local) === 1) { return $this->sources[$local[0]]['newId']; }
        $resolution = $this->resolutions['references'][$location] ?? null;
        if (is_array($resolution) && trim($resolution['reason'] ?? '') !== '') {
            foreach ($candidates as $i) {
                if ($this->sources[$i]['location'] === ($resolution['occurrence'] ?? null)) {
                    $this->decisions[$location] = $resolution;
                    return $this->sources[$i]['newId'];
                }
            }
        }
        throw new \RuntimeException('Ambiguous reference requires occurrence and reason: ' . $location);
    }

    private function sqlLocation(string $location): array
    {
        if (!preg_match('~^sql:(manny_tasks|scheduled_events|others_actions|others_auxiliaries|sector_storage_transfers|detached_storage_containers|probe_damage_warnings|asteroid_trajectories|sector_effects)/([^/]+)/([a-z_]+)(/.*)?$~D', $location, $match)) { throw new \RuntimeException('Unsupported SQL reference: ' . $location); }
        return [$match[1], $match[2], $match[3], $match[4] ?? ''];
    }

    /** Migration only. Unlike runtime decoding, forgotten presences are left for their own phase. */
    public static function strip(array $data): array
    {
        if (MineableDescriptors::covers($data)) { unset($data['resourceAmounts']); }
        foreach ($data as &$value) { if (is_array($value)) { $value = self::strip($value); } } unset($value);
        return $data;
    }
}
