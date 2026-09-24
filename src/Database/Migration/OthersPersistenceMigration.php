<?php

declare(strict_types=1);

namespace VonNeumannGame\Database\Migration;

use PDO;
use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\Storage\SectorEffectRepository;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorDriftingItem;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Service\SectorEffectService;

/** Offline migration: workers and command writers must be stopped. */
final class OthersPersistenceMigration
{
    public function __construct(private readonly PDO $pdo, private readonly SectorService $sectors) {}

    public function run(bool $apply): array
    {
        $pending = $this->pdo->query("SELECT * FROM others_cross_store_operations WHERE sql_applied=0 OR sector_applied=0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $operations = [];
        foreach ($pending as $row) {
            if (!(bool) $row['sql_applied']) {
                throw new \RuntimeException('Ambiguous legacy SQL operation ' . $row['public_id'] . ': reconcile its SQL balance before migration.');
            }
            $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $statement = $this->pdo->prepare('SELECT * FROM others_ships WHERE public_id=?');
            $statement->execute([$payload['shipId']]);
            $ship = $statement->fetch(PDO::FETCH_ASSOC);
            $coordinates = $payload['sector'] ?? ($ship ? ['x' => (int) $ship['sector_x'], 'y' => (int) $ship['sector_y'], 'z' => (int) $ship['sector_z']] : null);
            if ($coordinates === null) { throw new \RuntimeException('Missing sector for legacy operation ' . $row['public_id']); }
            $sector = new SectorCoordinates($coordinates['x'], $coordinates['y'], $coordinates['z']);
            // Never generate files in dry-run mode.
            if (!$this->sectors->sectorExists($sector)) { throw new \RuntimeException('Missing sector file for ' . $row['public_id']); }
            $content = $this->sectors->getOrCreateSector($sector);
            $objects = [];
            if ($row['operation_type'] === 'mothership_wreck') {
                $wreck = DormantConstruct::fromOthersMothership($payload['shipId'], $payload['resourceAmounts']);
                if ($content->findObjectById($wreck->getId()) === null) {
                    $objects[] = $wreck;
                    foreach ($payload['driftingItems'] ?? [] as $item) {
                        $id = SectorDriftingItem::objectIdForItemType($item['type']);
                        $existing = $content->findObjectById($id);
                        $objects[] = $existing instanceof SectorDriftingItem
                            ? $existing->withQuantity($existing->getQuantity() + (int) $item['quantity'])
                            : new SectorDriftingItem($id, ProbeItem::MISSILE_NAME, $item['type'], (int) $item['quantity'], (float) $item['containerSpace']);
                    }
                }
            } elseif ($row['operation_type'] === 'dormant_auxiliaries') {
                foreach ($payload['auxiliaryIds'] as $uid) {
                    $object = DormantConstruct::fromOthersAuxiliary($uid);
                    if ($content->findObjectById($object->getId()) === null) { $objects[] = $object; }
                }
            } else { throw new \RuntimeException('Unsupported legacy operation ' . $row['operation_type']); }
            $changes = [];
            foreach ($objects as $object) { $changes[] = ['id' => $object->getId(), 'before' => $content->findObjectById($object->getId())?->toArray(), 'after' => $object->toArray()]; }
            $operations[] = [$row, $sector, $changes];
        }
        if (!$apply) { return ['apply' => false, 'pendingOperations' => count($operations)]; }
        $this->upgradeSchema();
        $outbox = new SectorEffectService(new SectorEffectRepository($this->pdo), new ScheduledEventRepository($this->pdo), $this->sectors);
        $this->pdo->beginTransaction();
        try {
            foreach ($operations as [$row, $sector, $changes]) {
                if ($changes !== []) { $outbox->enqueue('migrated-' . $row['public_id'], $sector, 'patch_objects', '', ['changes' => $changes], gmdate('c')); }
                $this->pdo->prepare("UPDATE others_cross_store_operations SET sector_applied=1,status='migrated',updated_at=? WHERE id=?")->execute([gmdate('c'), $row['id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $error; }
        return ['apply' => true, 'migratedOperations' => count($operations)];
    }

    private function upgradeSchema(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $definition = (string) $this->pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='sector_effects'")->fetchColumn();
            if (!str_contains($definition, 'patch_objects')) {
                $this->pdo->beginTransaction();
                try {
                    $definition = preg_replace('/CREATE TABLE (?:IF NOT EXISTS )?["`]?sector_effects["`]?/i', 'CREATE TABLE sector_effects_next', $definition, 1);
                    $definition = str_replace("'add_object','consume_object'", "'add_object','consume_object','patch_objects'", $definition);
                    if (!str_contains($definition, 'patch_objects')) { throw new \RuntimeException('Unexpected sector effects schema.'); }
                    $this->pdo->exec($definition);
                    $this->pdo->exec('INSERT INTO sector_effects_next SELECT * FROM sector_effects');
                    $this->pdo->exec('DROP TABLE sector_effects');
                    $this->pdo->exec('ALTER TABLE sector_effects_next RENAME TO sector_effects');
                    $this->pdo->commit();
                } catch (\Throwable $error) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $error; }
            }
        } else {
            $checks = $this->pdo->query("SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='sector_effects'")->fetchAll(PDO::FETCH_ASSOC);
            $old = array_filter($checks, static fn(array $check): bool => str_contains($check['CHECK_CLAUSE'], 'effect_type') && !str_contains($check['CHECK_CLAUSE'], 'patch_objects'));
            if ($old !== []) {
                // MariaDB inline column checks cannot be dropped by DROP CONSTRAINT.
                // Redefining the canonical column removes that inline check first.
                $this->pdo->exec('ALTER TABLE sector_effects MODIFY COLUMN effect_type VARCHAR(255) NOT NULL');
                $checks = $this->pdo->query("SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='sector_effects'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($checks as $check) {
                    if (!str_contains($check['CHECK_CLAUSE'], 'effect_type')) { continue; }
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $check['CONSTRAINT_NAME'])) { throw new \RuntimeException('Unexpected check constraint identifier.'); }
                    $this->pdo->exec('ALTER TABLE sector_effects DROP CONSTRAINT `' . $check['CONSTRAINT_NAME'] . '`');
                }
                $this->pdo->exec("ALTER TABLE sector_effects ADD CONSTRAINT chk_sector_effect_type CHECK(effect_type IN ('add_object','consume_object','patch_objects'))");
            }
        }
        (new SchemaInitializer($driver))->initialize($this->pdo);
    }
}
