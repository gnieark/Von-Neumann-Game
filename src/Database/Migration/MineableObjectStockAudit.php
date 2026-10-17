<?php

declare(strict_types=1);

namespace VonNeumannGame\Database\Migration;

use PDO;
use VonNeumannGame\Domain\AsteroidTrajectory;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Sector\DormantConstruct;

/** Read raw persisted data: domain constructors would conceal missing or invalid stocks. */
final class MineableObjectStockAudit
{
    private array $report;
    private array $sources = [];
    private array $forgotten = [];

    public function __construct(private readonly int $maxFiles = 100000, private readonly int $maxRows = 1000000, private readonly bool $missingResourcesAreExhausted = false)
    {
        if ($maxFiles < 1 || $maxRows < 1) { throw new \InvalidArgumentException('Audit limits must be positive.'); }
    }

    /** The caller owns a read-only SQL snapshot and supplies an offline copy of the sector files. */
    public function inspect(PDO $pdo, string $universePath): array
    {
        if (!$pdo->inTransaction()) { throw new \LogicException('The audit requires a caller-owned read-only snapshot.'); }
        $directory = realpath($universePath . '/sectors');
        if ($directory === false || !is_dir($directory)) { throw new \InvalidArgumentException('The universe must contain an existing sectors directory.'); }
        $this->sources = $this->forgotten = [];
        $this->report = [
            'formatVersion' => 1,
            'startedAt' => gmdate('c'),
            'scope' => ['asteroid', 'dormant_construct:' . DormantConstruct::SUBTYPE_OTHERS_MOTHERSHIP_WRECK],
            'limits' => ['files' => $this->maxFiles, 'rowsPerTable' => $this->maxRows],
            'files' => [], 'sqlRows' => [], 'counts' => [], 'sources' => [], 'snapshots' => [],
            'references' => [], 'forgottenMannies' => [], 'anomalies' => [],
            'totals' => array_fill_keys(ResourceComposition::TYPES, 0.0),
            'maximumAmounts' => array_fill_keys(ResourceComposition::TYPES, 0.0),
            'maximumIdBytes' => 0, 'subtotals' => [], 'normalizations' => [],
            'missingResourcePolicy' => $this->missingResourcesAreExhausted ? 'explicit_exhausted_zero' : 'reject',
            'coverageNotes' => [
                'Scheduled events: pending, running and failed only; terminal event history is not scanned.',
                'Trajectory and sector-effect history is scanned within the explicit row bounds.',
                'File hashes and a SQL snapshot do not establish a common backup timestamp.',
                'Inventory validity does not authorize deployment or replace migration verification.',
            ],
        ];
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) { $this->issue('symlink', $file->getFilename()); continue; }
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) { continue; }
            if (count($paths) >= $this->maxFiles) { $this->issue('file_limit', 'sectors'); break; }
            $paths[] = $file->getPathname();
        }
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $location = substr($path, strlen($directory) + 1);
            $json = file_get_contents($path);
            if ($json === false) { $this->issue('unreadable_file', $location); continue; }
            $this->report['files'][$location] = hash('sha256', $json);
            $data = $this->decode($json, $location);
            if ($data === null) { continue; }
            $sector = $this->coordinates($data['coordinates'] ?? [], '', $location);
            if (!isset($data['objects']) || !is_array($data['objects']) || !array_is_list($data['objects'])) {
                $this->issue('invalid_objects', $location); continue;
            }
            foreach ($data['objects'] as $index => $object) {
                if (!is_array($object)) { $this->issue('invalid_object', $location . '#/objects/' . $index); continue; }
                $this->walkObjects($object, $location . '#/objects/' . $index, $sector, true);
            }
            $this->references($data, $location . '#', $sector, true);
        }
        $this->trajectories($pdo);
        $this->effects($pdo);
        $this->operationalReferences($pdo);
        $this->forgottenMannies($pdo);
        foreach ($this->report['files'] as $path => $checksum) {
            if (@hash_file('sha256', $directory . '/' . $path) !== $checksum) { $this->issue('file_changed_during_audit', $path); }
        }
        foreach ($this->sources as $id => $occurrences) {
            if (count($occurrences) > 1) { $this->issue('duplicate_identity', (string) $id, ['occurrences' => $occurrences]); }
        }
        foreach ($this->report['references'] as &$reference) {
            $candidates = $this->sources[$reference['objectId']] ?? [];
            if ($reference['sector'] !== null) {
                $candidates = array_values(array_filter($candidates, fn(int $index): bool => $this->report['sources'][$index]['sector'] === $reference['sector']));
            }
            $reference['sourceOccurrences'] = $candidates;
            if ($reference['active'] && count($candidates) > 1) {
                $this->issue('ambiguous_reference', $reference['location'], ['objectId' => $reference['objectId'], 'occurrences' => $candidates]);
            }
        }
        unset($reference);
        $this->report['uniqueSourceIds'] = count($this->sources);
        $this->report['validStockSources'] = count(array_filter($this->report['sources'], static fn(array $source): bool => $source['amounts'] !== null));
        $this->report['blockingAnomalies'] = count(array_filter($this->report['anomalies'], static fn(array $issue): bool => $issue['severity'] === 'error'));
        $this->report['inventoryValid'] = $this->report['blockingAnomalies'] === 0;
        $this->report['completedAt'] = gmdate('c');
        return $this->report;
    }

    private function walkObjects(array $object, string $location, ?array $sector, bool $authoritative): void
    {
        $type = $object['type'] ?? null;
        $covered = $type === 'asteroid' || ($type === 'dormant_construct' && ($object['subtype'] ?? null) === DormantConstruct::SUBTYPE_OTHERS_MOTHERSHIP_WRECK);
        if ($covered) {
            $raw = $object['resourceAmounts'] ?? null;
            // Explicit migration decision for exhausted asteroid fields only, never runtime loading.
            if ($this->missingResourcesAreExhausted && $type === 'asteroid') {
                if (!array_key_exists('resourceAmounts', $object)) {
                    $this->report['normalizations'][] = ['location' => $location, 'objectId' => $object['id'] ?? null, 'resources' => ResourceComposition::TYPES, 'reason' => 'exhausted_missing_stock'];
                    $raw = array_fill_keys(ResourceComposition::TYPES, 0.0);
                } elseif (is_array($raw) && array_diff(array_keys($raw), ResourceComposition::TYPES) === []) {
                    $missing = array_values(array_diff(ResourceComposition::TYPES, array_keys($raw)));
                    if ($missing !== []) {
                        $this->report['normalizations'][] = ['location' => $location, 'objectId' => $object['id'] ?? null, 'resources' => $missing, 'reason' => 'exhausted_missing_resources'];
                        $raw += array_fill_keys($missing, 0.0);
                    }
                }
            }
            $amounts = $this->amounts($raw, $location, $authoritative);
            $id = $object['id'] ?? null;
            if (!is_string($id) || $id === '' || trim($id) !== $id) {
                $this->issue('invalid_identity', $location); return;
            }
            $this->report['maximumIdBytes'] = max($this->report['maximumIdBytes'], strlen($id));
            if (strlen($id) > 255) { $this->issue('identity_too_long', $location, ['objectId' => $id]); }
            $entry = ['objectId' => $id, 'type' => $type, 'subtype' => $object['subtype'] ?? null,
                'location' => $location, 'sector' => $sector, 'amounts' => $amounts,
                'checksum' => hash('sha256', json_encode([$id, $type, $object['subtype'] ?? null, $amounts], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))];
            if ($authoritative) {
                $this->sources[$id][] = count($this->report['sources']);
                $this->report['sources'][] = $entry;
                $category = $type === 'asteroid' ? 'asteroids' : 'mothershipWrecks';
                $this->count($category);
                if ($amounts !== null) {
                    if (array_sum($amounts) === 0.0) { $this->count($category . 'Exhausted'); }
                    else { $this->count($category . 'NonExhausted'); }
                    foreach ($amounts as $resource => $amount) {
                        $sum = $this->report['totals'][$resource] + $amount;
                        if (!is_finite($sum)) { $this->issue('total_overflow', $resource); }
                        else { $this->report['totals'][$resource] = round($sum, 4); }
                        $this->report['maximumAmounts'][$resource] = max($this->report['maximumAmounts'][$resource], $amount);
                        $subtotal = ($this->report['subtotals'][$category][$resource] ?? 0.0) + $amount;
                        if (is_finite($subtotal)) { $this->report['subtotals'][$category][$resource] = round($subtotal, 4); }
                    }
                }
            } else { $this->report['snapshots'][] = $entry; }
        } elseif ($type === 'dormant_construct' && $authoritative) {
            $this->count('excludedConstructs:' . ($object['subtype'] ?? 'none'));
        } elseif ($type === 'manny' && ($object['state'] ?? null) === 'forgotten') {
            if ($authoritative) { $this->forgotten[] = ['object' => $object, 'location' => $location, 'sector' => $sector]; }
            else { $this->issue('forgotten_manny_in_effect', $location); }
        } elseif ($type === 'manny' && $authoritative) { $this->count('abandonedMannies'); }
        if ($type === 'solar_system') {
            foreach ($object['orbitalBodies'] ?? [] as $index => $body) {
                if (!is_array($body) || !is_array($body['object'] ?? null)) { $this->issue('invalid_orbit', $location . '/orbitalBodies/' . $index); continue; }
                $this->walkObjects($body['object'], $location . '/orbitalBodies/' . $index . '/object', $sector, $authoritative);
            }
        }
    }

    private function amounts(mixed $raw, string $location, bool $authoritative): ?array
    {
        $severity = $authoritative ? 'error' : 'warning';
        if (!is_array($raw) || array_diff(array_keys($raw), ResourceComposition::TYPES) !== [] || array_diff(ResourceComposition::TYPES, array_keys($raw)) !== []) {
            $this->issue('invalid_stock_shape', $location, [], $severity); return null;
        }
        $amounts = [];
        foreach (ResourceComposition::TYPES as $type) {
            $value = $raw[$type];
            if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value) || $value < 0) {
                $this->issue('invalid_stock_value', $location, ['resource' => $type], $severity); return null;
            }
            $amounts[$type] = round((float) $value, 4);
            if ((float) $value !== $amounts[$type]) { $this->issue('stock_needs_rounding', $location, ['resource' => $type], $severity); }
            // At 2^39 the ULP exceeds 0.0001. Do not hide precision loss behind a tolerance.
            if ($amounts[$type] >= 549755813888.0) { $this->issue('four_decimal_resolution_lost', $location, ['resource' => $type], $severity); }
        }
        return $amounts;
    }

    private function trajectories(PDO $pdo): void
    {
        foreach ($this->rows($pdo, 'asteroid_trajectories') as $row) {
            $location = 'sql:asteroid_trajectories/' . $row['id'];
            $active = in_array($row['status'], AsteroidTrajectory::ACTIVE_STATUSES, true);
            $sector = $this->coordinates($row, 'current_sector_', $location);
            $this->count('trajectories:' . $row['status']);
            $this->references(['asteroidId' => $row['asteroid_id'], 'targetObjectId' => $row['target_object_id']], $location, $sector, $active);
            $snapshot = $this->decode($row['asteroid_snapshot_json'], $location);
            if ($snapshot === null) { continue; }
            if (($snapshot['id'] ?? null) !== $row['asteroid_id'] || ($snapshot['type'] ?? null) !== 'asteroid') {
                $this->issue('trajectory_identity_mismatch', $location); continue;
            }
            $present = $this->sources[$row['asteroid_id']] ?? [];
            $transit = $row['status'] === AsteroidTrajectory::STATUS_CROSSING_SECTOR;
            if ($transit && $present !== []) { $this->issue('transit_also_in_sector', $location, ['occurrences' => $present]); }
            if ($active && !$transit && $present === []) { $this->issue('active_trajectory_source_missing', $location); }
            $this->walkObjects($snapshot, $location . '/asteroid_snapshot_json', $sector, $transit && $present === []);
            $attachments = $this->decode($row['attachments_snapshot_json'], $location . '/attachments_snapshot_json');
            if ($attachments !== null) { $this->references($attachments, $location . '/attachments_snapshot_json', $sector, $active); }
        }
    }

    private function effects(PDO $pdo): void
    {
        foreach ($this->rows($pdo, 'sector_effects') as $row) {
            $location = 'sql:sector_effects/' . $row['id'];
            $active = $row['status'] === 'pending';
            $sector = $this->coordinates($row, 'sector_', $location);
            $this->references(['objectId' => $row['object_id']], $location, $sector, $active);
            $payload = $this->decode($row['payload_json'], $location);
            if ($payload === null) { continue; }
            $this->references($payload, $location . '/payload_json', $sector, $active);
            if (!$active) { continue; }
            // The audit must never publish intentions, or choose the newest positive stock.
            $this->issue('pending_sector_effect', $location, ['operationId' => $row['operation_id'], 'effectType' => $row['effect_type']]);
            if ($row['effect_type'] === 'add_object') { $this->walkObjects($payload, $location . '/payload_json', $sector, false); }
            foreach ($payload['changes'] ?? [] as $index => $change) {
                foreach (['before', 'after'] as $side) {
                    if (is_array($change[$side] ?? null)) { $this->walkObjects($change[$side], $location . '/payload_json/changes/' . $index . '/' . $side, $sector, false); }
                }
            }
        }
    }

    private function operationalReferences(PDO $pdo): void
    {
        foreach (['manny_tasks', 'scheduled_events', 'others_actions', 'others_auxiliaries', 'sector_storage_transfers', 'detached_storage_containers', 'probe_damage_warnings'] as $table) {
            foreach ($this->rows($pdo, $table, $table === 'detached_storage_containers' ? 'object_id' : 'id') as $row) {
                $location = 'sql:' . $table . '/' . ($row['id'] ?? $row['object_id']);
                $active = !isset($row['status']) || in_array($row['status'], ['pending', 'running', 'failed', 'queued', 'cancel_requested', 'available', 'reserved'], true);
                $sector = isset($row['sector_x']) ? $this->coordinates($row, 'sector_', $location) : null;
                $this->references($row, $location, $sector, $active);
                foreach (['payload_json', 'manifest_json', 'result_json'] as $column) {
                    if (!is_string($row[$column] ?? null)) { continue; }
                    $payload = $this->decode($row[$column], $location . '/' . $column);
                    if ($payload !== null) { $this->references($payload, $location . '/' . $column, $sector, $active && $column !== 'result_json'); }
                }
                if ($table === 'scheduled_events' && $row['status'] === 'failed' && str_contains($row['type'], 'manny')) {
                    $this->issue('failed_manny_event_requires_review', $location, ['entityId' => $row['entity_id']], 'warning');
                }
            }
        }
    }

    private function forgottenMannies(PDO $pdo): void
    {
        $byUid = [];
        foreach ($this->forgotten as $entry) { $byUid[(string) ($entry['object']['mannyUid'] ?? '')][] = $entry; }
        foreach (array_chunk(array_keys($byUid), 400) as $uids) {
            $stmt = $pdo->prepare('SELECT m.*, p.player_id, p.status AS probe_status, pl.id AS existing_player_id FROM mannies m LEFT JOIN neumann_probes p ON p.id=m.probe_id LEFT JOIN players pl ON pl.id=p.player_id WHERE m.uid IN (' . implode(',', array_fill(0, count($uids), '?')) . ')');
            $stmt->execute($uids);
            $rows = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'uid');
            foreach ($uids as $uid) {
                $entries = $byUid[$uid];
                if (count($entries) > 1) { $this->issue('duplicate_forgotten_manny', 'manny:' . $uid); }
                foreach ($entries as $entry) {
                    $row = $rows[$uid] ?? null;
                    $result = ['uid' => $uid, 'location' => $entry['location'], 'sector' => $entry['sector'], 'sqlId' => $row === null ? null : (int) $row['id'], 'differences' => []];
                    if ($row === null) { $this->issue('forgotten_manny_missing_sql', $entry['location']); }
                    else {
                        if ($row['probe_id'] === null || $row['existing_player_id'] === null || in_array($row['probe_status'], ['dead', 'trapped_by_black_hole'], true)) {
                            $this->issue('forgotten_manny_owner_lost', $entry['location']);
                        }
                        if ($row['location_type'] !== 'sector' || $entry['sector'] !== $this->coordinates($row, 'sector_', $entry['location'])) {
                            $this->issue('forgotten_manny_location_mismatch', $entry['location']);
                        }
                        foreach (['deuterium' => 'cargo_deuterium', 'metals' => 'cargo_metals', 'ice' => 'cargo_ice', 'organicCompounds' => 'cargo_organic_compounds'] as $key => $column) {
                            $jsonAmount = $entry['object']['cargo'][$key] ?? null;
                            if (!is_numeric($jsonAmount) || round((float) $jsonAmount, 4) !== round((float) $row[$column], 4)) { $result['differences'][] = $key; }
                        }
                        if ($result['differences'] !== []) { $this->issue('forgotten_cargo_requires_review', $entry['location'], ['resources' => $result['differences']]); }
                    }
                    $this->report['forgottenMannies'][] = $result;
                }
            }
        }
    }

    private function references(array $data, string $location, ?array $sector, bool $active): void
    {
        if (($data['type'] ?? null) === 'asteroid' || (($data['type'] ?? null) === 'dormant_construct' && ($data['subtype'] ?? null) === DormantConstruct::SUBTYPE_OTHERS_MOTHERSHIP_WRECK)) {
            if (is_string($data['id'] ?? null)) {
                $this->report['references'][] = ['objectId' => $data['id'], 'location' => $location . '/id', 'sector' => $sector, 'active' => $active];
            }
        }
        $keys = ['objectId', 'targetObjectId', 'asteroidId', 'capturedByObjectId', 'object_id', 'target_object_id', 'asteroid_id', 'object_public_id'];
        foreach ($data as $key => $value) {
            $path = $location . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            if (is_array($value)) { $this->references($value, $path, $sector, $active); }
            elseif (in_array($key, $keys, true) && is_string($value) && $value !== '') {
                $this->report['references'][] = ['objectId' => $value, 'location' => $path, 'sector' => $sector, 'active' => $active];
            }
        }
    }

    private function rows(PDO $pdo, string $table, string $key = 'id'): \Generator
    {
        $cursor = $key === 'id' ? 0 : '';
        $count = 0;
        do {
            $sql = match ($table) {
                'manny_tasks' => 'SELECT t.*, m.sector_x, m.sector_y, m.sector_z FROM manny_tasks t LEFT JOIN mannies m ON m.id=t.manny_id WHERE t.id > ? ORDER BY t.id LIMIT 400',
                'scheduled_events' => "SELECT * FROM scheduled_events WHERE status IN ('pending','running','failed') AND id > ? ORDER BY id LIMIT 400",
                default => "SELECT * FROM $table WHERE $key > ? ORDER BY $key LIMIT 400",
            };
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cursor]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if ($count >= $this->maxRows) { $this->issue('row_limit', $table); $this->report['sqlRows'][$table] = $count; return; }
                $cursor = $row[$key];
                ++$count;
                yield $row;
            }
        } while (count($rows) === 400);
        $this->report['sqlRows'][$table] = $count;
    }

    private function decode(string $json, string $location): ?array
    {
        try { $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $this->issue('invalid_json', $location); return null; }
        if (!is_array($data)) { $this->issue('invalid_json_root', $location); return null; }
        return $data;
    }

    private function coordinates(array $data, string $prefix, string $location): ?array
    {
        $coordinates = [];
        foreach (['x', 'y', 'z'] as $axis) {
            $value = $data[$prefix . $axis] ?? null;
            if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/D', $value))) { $this->issue('invalid_sector', $location); return null; }
            $coordinates[$axis] = (int) $value;
        }
        return $coordinates;
    }

    private function count(string $key): void { $this->report['counts'][$key] = ($this->report['counts'][$key] ?? 0) + 1; }

    private function issue(string $code, string $location, array $details = [], string $severity = 'error'): void
    {
        $this->report['anomalies'][] = ['code' => $code, 'severity' => $severity, 'location' => $location] + $details;
    }
}
