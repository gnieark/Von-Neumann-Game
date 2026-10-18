<?php
declare(strict_types=1);
namespace VonNeumannGame\Database\Migration;

use PDO;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorEffect;
use VonNeumannGame\Sector\SectorFileRepository;

/** Prove structural restitutions made by recalls, without trusting arbitrary changed files. */
final class MaintenanceRecallSectors
{
    public function __construct(private readonly PDO $pdo, private readonly string $universe) {}

    public function prepare(string $directory, array $events, array $expectedHashes): void
    {
        if (is_file($directory . '/recall-sectors.json')) { return; }
        $proof = ['effectBoundary' => (int) $this->pdo->query('SELECT COALESCE(MAX(id),0) FROM sector_effects')->fetchColumn(), 'files' => []];
        $repository = new SectorFileRepository($this->universe);
        foreach ($events as $event) {
            $stmt = $this->pdo->prepare('SELECT m.*,p.sector_x AS carrier_x,p.sector_y AS carrier_y,p.sector_z AS carrier_z FROM mannies m LEFT JOIN neumann_probes p ON p.id=m.probe_id WHERE m.id=?');
            $stmt->execute([$event['entity_id']]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int) $row['task_scheduled_event_id'] !== (int) $event['id'] || $row['current_task'] === null) { continue; }
            foreach (['sector_', 'carrier_'] as $prefix) {
                if ($row[$prefix . 'x'] === null) { continue; }
                $path = $repository->getPath(new SectorCoordinates((int) $row[$prefix . 'x'], (int) $row[$prefix . 'y'], (int) $row[$prefix . 'z']));
                $relative = substr($path, strlen($this->universe) + 1);
                if (!is_file($path)) { throw new \RuntimeException('Recall requires an existing sector; maintenance must not generate a world: ' . $relative); }
                $raw = file_get_contents($path); $hash = hash('sha256', $raw);
                if (($expectedHashes[$relative] ?? null) !== $hash) { throw new \RuntimeException('Recall sector changed before its business operation: ' . $relative); }
                $proof['files'][$relative] = ['before' => $raw, 'beforeHash' => $hash];
            }
        }
        MaintenanceFiles::json($directory . '/recall-sectors.json', $proof);
    }

    public function verify(string $directory, array $expectedHashes, bool $record): array
    {
        $proof = MaintenanceFiles::read($directory . '/recall-sectors.json');
        $stmt = $this->pdo->prepare('SELECT * FROM sector_effects WHERE id>? ORDER BY id'); $stmt->execute([$proof['effectBoundary']]);
        $sectors = []; $effectIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $effect) {
            $path = (new SectorFileRepository($this->universe))->getPath(new SectorCoordinates((int) $effect['sector_x'], (int) $effect['sector_y'], (int) $effect['sector_z']));
            $relative = substr($path, strlen($this->universe) + 1);
            if (!isset($proof['files'][$relative]) || $effect['status'] !== 'applied' || $effect['effect_type'] === 'create_sector') { throw new \RuntimeException('Unexpected or unpublished recall intention: ' . $effect['id']); }
            if ($expectedHashes[$relative] !== $proof['files'][$relative]['beforeHash']) { throw new \RuntimeException('Recall baseline differs from the migration plan.'); }
            $sectors[$relative] ??= SectorContent::fromDescriptor(json_decode($proof['files'][$relative]['before'], true, 512, JSON_THROW_ON_ERROR));
            SectorEffect::apply($sectors[$relative], $effect['operation_id'], $effect['effect_type'], $effect['object_id'], json_decode($effect['payload_json'], true, 512, JSON_THROW_ON_ERROR));
            $effectIds[$relative][] = (int) $effect['id'];
        }
        $receipts = [];
        foreach ($sectors as $relative => $sector) {
            $raw = file_get_contents($this->universe . '/' . $relative);
            $actual = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $expected = $sector->toDescriptor();
            // touch() records publication wall time; all game data must match the replay exactly.
            if (($actual['updatedAt'] ?? null) !== $expected['updatedAt'] && (!is_string($actual['updatedAt'] ?? null) || \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $actual['updatedAt']) === false)) { throw new \RuntimeException('Invalid recall publication timestamp.'); }
            $expected['updatedAt'] = $actual['updatedAt'];
            if ($expected != $actual) { throw new \RuntimeException('Recall publication differs from its durable intentions: ' . $relative); }
            $expectedHashes[$relative] = hash('sha256', $raw);
            $receipts[$relative] = ['beforeHash' => $proof['files'][$relative]['beforeHash'], 'afterHash' => $expectedHashes[$relative], 'effects' => $effectIds[$relative]];
        }
        $path = $directory . '/recall-files.json';
        if (is_file($path)) {
            if (MaintenanceFiles::read($path) !== $receipts) { throw new \RuntimeException('Recall publication proof changed.'); }
        } elseif ($record) { MaintenanceFiles::json($path, $receipts); }
        else { throw new \RuntimeException('Missing recall publication proof.'); }
        return $expectedHashes;
    }
}
