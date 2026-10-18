<?php
declare(strict_types=1);
namespace VonNeumannGame\Database\Migration;

use PDO;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;

/** Explicit offline projection of pre-migration intentions, retaining their JSON stocks. */
final class LegacySectorEffectDrain
{
    public function __construct(private readonly PDO $pdo, private readonly string $universe, private readonly ?\Closure $checkpoint = null) {}

    public function apply(string $directory): int
    {
        $rows = $this->pdo->query("SELECT * FROM sector_effects WHERE status='pending' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $path = (new SectorFileRepository($this->universe))->getPath(new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']));
            $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $data = is_file($path) ? MaintenanceFiles::read($path) : null;
            if (!in_array($row['operation_id'], $data['appliedSectorEffects'] ?? [], true)) {
                $before = $data;
                if ($row['effect_type'] === 'create_sector') {
                    if ($data !== null) { throw new \RuntimeException('Creation intention conflicts with an existing sector.'); }
                    $data = $payload;
                    if (($data['coordinates'] ?? null) != ['x' => $row['sector_x'], 'y' => $row['sector_y'], 'z' => $row['sector_z']]) { throw new \RuntimeException('Creation intention sector mismatch.'); }
                } else {
                    if ($data === null) { throw new \RuntimeException('Cannot drain an intention for a missing sector.'); }
                    if ($row['effect_type'] === 'add_object') {
                        $this->change($data['objects'], $row['object_id'], null, $payload);
                    } elseif ($row['effect_type'] === 'consume_object') {
                        if ($this->occurrences($data, $row['object_id']) > 1) { throw new \RuntimeException('Ambiguous intention source: ' . $row['object_id']); }
                        $this->remove($data, $row['object_id']);
                    } elseif ($row['effect_type'] === 'patch_objects') {
                        foreach ($payload['changes'] ?? [] as $change) { $this->change($data['objects'], $change['id'], $change['before'], $change['after']); }
                    } else { throw new \RuntimeException('Unsupported maintenance intention: ' . $row['effect_type']); }
                }
                $data['appliedSectorEffects'][] = $row['operation_id'];
                $record = $directory . '/drain/' . $row['id'] . '.json';
                if (!is_file($record)) { MaintenanceFiles::json($record, ['effect' => $row, 'file' => $path, 'before' => $before, 'after' => $data]); }
                else {
                    $saved = MaintenanceFiles::read($record);
                    if ($saved['before'] != $before || $saved['after'] != $data || $saved['effect'] != $row) { throw new \RuntimeException('Intention changed since maintenance planning.'); }
                }
                MaintenanceFiles::json($path, $data);
                if ($this->checkpoint !== null) { ($this->checkpoint)('effect:file'); }
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare("UPDATE sector_effects SET status='applied',updated_at=? WHERE id=? AND status='pending'")->execute([gmdate('c'), $row['id']]);
                $this->pdo->prepare("UPDATE scheduled_events SET status='done',locked_at=NULL,locked_by=NULL,processed_at=? WHERE type='sector.effect' AND entity_id=? AND status IN ('pending','running','failed')")->execute([gmdate('c'), $row['id']]);
                $this->pdo->commit();
            } catch (\Throwable $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $e; }
        }
        return count($rows);
    }

    private function change(array &$objects, string $id, ?array $before, ?array $after): void
    {
        $matches = array_keys(array_filter($objects, static fn($o) => ($o['id'] ?? null) === $id));
        if (count($matches) > 1) { throw new \RuntimeException('Ambiguous intention source: ' . $id); }
        $key = $matches[0] ?? null;
        $current = $key === null ? null : $objects[$key];
        if ($current != $before) { throw new \RuntimeException('Intention before-image mismatch: ' . $id); }
        if ($after === null) { if ($key !== null) { unset($objects[$key]); } }
        elseif ($key === null) { $objects[] = $after; }
        else { $objects[$key] = $after; }
        $objects = array_values($objects);
    }

    private function remove(array &$data, string $id): void
    {
        foreach ($data as $key => &$value) {
            if (!is_array($value)) { continue; }
            if (($value['id'] ?? null) === $id || ($value['object']['id'] ?? null) === $id) { unset($data[$key]); }
            else { $this->remove($value, $id); }
        }
        unset($value);
        if (isset($data['objects'])) { $data['objects'] = array_values($data['objects']); }
        if (isset($data['orbitalBodies'])) { $data['orbitalBodies'] = array_values($data['orbitalBodies']); }
    }

    private function occurrences(array $data, string $id): int
    {
        $count = ($data['id'] ?? null) === $id ? 1 : 0;
        foreach ($data as $value) { if (is_array($value)) { $count += $this->occurrences($value, $id); } }
        return $count;
    }
}
