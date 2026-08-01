<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\InventoryMannyProjection;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Sector\SectorCoordinates;

final class MannyRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config = [],
        private readonly ?ScheduledEventRepository $scheduledEvents = null,
    ) {}

    public function ensureDefaultsForProbe(NeumannProbe $probe): void
    {
        $existing = $this->findByProbeId($probe->id);
        $defaultCount = $this->defaultMannyCount();
        if (count($existing) >= $defaultCount) {
            return;
        }

        $names = array_map(static fn(Manny $manny): string => strtolower($manny->name), $existing);
        for ($i = 1; count($existing) < $defaultCount && $i <= $this->nameSearchLimit(); $i++) {
            $name = 'manny-' . $i;
            if (in_array($name, $names, true)) {
                continue;
            }

            $existing[] = $this->createForProbe($probe->id, $name);
            $names[] = $name;
        }
    }

    public function createForProbe(int $probeId, string $name, ?int $storageContainerId = null, ?string $uid = null): Manny
    {
        $now = gmdate('c');
        $uid ??= $this->uniqueUid();
        if ($this->findByUid($uid) !== null) {
            throw new \RuntimeException('Manny uid already exists.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO mannies
             (uid, probe_id, storage_container_id, name, location_type, sector_x, sector_y, sector_z, current_task, task_started_at, task_ends_at, task_scheduled_event_id, task_payload_json, reserved_cargo_type, reserved_cargo_space, reserved_storage_container_id, cargo_deuterium, cargo_metals, cargo_ice, cargo_organic_compounds, created_at, updated_at)
             VALUES (:uid, :probe_id, :storage_container_id, :name, :location_type, NULL, NULL, NULL, NULL, NULL, NULL, NULL, :task_payload_json, NULL, 0, NULL, 0, 0, 0, 0, :created_at, :updated_at)'
        );
        $stmt->execute([
            'uid' => $uid,
            'probe_id' => $probeId,
            'storage_container_id' => $storageContainerId,
            'name' => $name,
            'location_type' => Manny::LOCATION_PROBE,
            'task_payload_json' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Manny creation failed.');
    }

    private function defaultMannyCount(): int
    {
        return max(0, Config::int($this->config, 'probe.initialMannyCount', 4));
    }

    private function nameSearchLimit(): int
    {
        return max(1, Config::int($this->config, 'probe.mannyNameSearchLimit', 12));
    }

    /**
     * @return array<Manny>
     */
    public function findByProbeId(int $probeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mannies WHERE probe_id = :probe_id ORDER BY name ASC, id ASC');
        $stmt->execute(['probe_id' => $probeId]);

        return array_map(fn(array $row): Manny => $this->hydrate($row), $stmt->fetchAll());
    }

    /**
     * Inventory-only projection. It deliberately excludes task columns and
     * therefore never loads scheduled-event payloads.
     *
     * @return array<InventoryMannyProjection>
     */
    public function findInventoryProjectionsByProbeId(int $probeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT uid, storage_container_id, name, location_type,
                    cargo_deuterium, cargo_metals, cargo_ice, cargo_organic_compounds
             FROM mannies
             WHERE probe_id = :probe_id
             ORDER BY name ASC, id ASC'
        );
        $stmt->execute(['probe_id' => $probeId]);

        return array_map(
            static fn(array $row): InventoryMannyProjection => new InventoryMannyProjection(
                (string) $row['uid'],
                $row['storage_container_id'] !== null ? (int) $row['storage_container_id'] : null,
                (string) $row['name'],
                (string) $row['location_type'],
                (float) ($row['cargo_deuterium'] ?? 0),
                (float) ($row['cargo_metals'] ?? 0),
                (float) ($row['cargo_ice'] ?? 0),
                (float) ($row['cargo_organic_compounds'] ?? 0),
            ),
            $stmt->fetchAll(),
        );
    }

    /**
     * @return list<array{mannyId:int,type:string,space:float,containerId:?int}>
     */
    public function findCargoReservationsByProbeId(int $probeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reserved_cargo_type, reserved_cargo_space, reserved_storage_container_id
             FROM mannies
             WHERE probe_id = :probe_id AND reserved_cargo_space > 0
             ORDER BY id ASC'
        );
        $stmt->execute(['probe_id' => $probeId]);

        return array_map(
            static fn(array $row): array => [
                'mannyId' => (int) $row['id'],
                'type' => (string) ($row['reserved_cargo_type'] ?? ''),
                'space' => (float) $row['reserved_cargo_space'],
                'containerId' => $row['reserved_storage_container_id'] !== null ? (int) $row['reserved_storage_container_id'] : null,
            ],
            $stmt->fetchAll(),
        );
    }

    public function findAtomicPrinterAssistantByProbeId(int $probeId): ?Manny
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM mannies
             WHERE probe_id = :probe_id AND current_task = :current_task
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'current_task' => Manny::TASK_ASSISTING_ATOMIC_PRINTER,
        ]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByUidForProbe(int $probeId, string $uid): ?Manny
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mannies WHERE probe_id = :probe_id AND uid = :uid');
        $stmt->execute(['probe_id' => $probeId, 'uid' => $uid]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByUid(string $uid): ?Manny
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mannies WHERE uid = :uid');
        $stmt->execute(['uid' => $uid]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function hasExistingOwnerForUid(string $uid): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM mannies m
             INNER JOIN neumann_probes p ON p.id = m.probe_id
             INNER JOIN players pl ON pl.id = p.player_id
             WHERE m.uid = :uid'
        );
        $stmt->execute(['uid' => $uid]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function findById(int $id): ?Manny
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mannies WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @template T
     * @param callable(Manny): T $callback
     * @return T
     */
    public function withMannyLock(int $id, callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $sql = 'SELECT * FROM mannies WHERE id = :id';
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new \RuntimeException('Manny not found while acquiring task lock.');
            }
            $result = $callback($this->hydrate($row));
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function nameExistsForProbe(int $probeId, string $name, ?int $exceptId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM mannies WHERE probe_id = :probe_id');
        $stmt->execute(['probe_id' => $probeId]);
        $needle = strtolower($name);
        foreach ($stmt->fetchAll() as $row) {
            if ($exceptId !== null && (int) $row['id'] === $exceptId) {
                continue;
            }
            if (strtolower((string) $row['name']) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function save(Manny $manny): void
    {
        $manny->updatedAt = gmdate('c');
        $taskPayloadForMannyRow = $manny->taskPayload;
        if ($this->scheduledEvents !== null) {
            if ($manny->currentTask !== null) {
                $runAt = is_string($manny->taskPayload[Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY] ?? null)
                    ? $manny->taskPayload[Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY]
                    : ($manny->taskEndsAt ?? ScheduledEventRepository::UNSCHEDULED_RUN_AT);
                if ($manny->taskScheduledEventId === null) {
                    $event = $this->scheduledEvents->schedule(
                        'manny.task',
                        'manny',
                        $manny->id,
                        $runAt,
                        $manny->taskPayload,
                    );
                    $manny->taskScheduledEventId = $event->id;
                } else {
                    $this->scheduledEvents->updateRunAtAndPayload($manny->taskScheduledEventId, $runAt, $manny->taskPayload);
                }
                $taskPayloadForMannyRow = [];
            } elseif ($manny->taskScheduledEventId !== null) {
                $this->scheduledEvents->markDoneById($manny->taskScheduledEventId);
                $manny->taskScheduledEventId = null;
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE mannies SET
                probe_id = :probe_id,
                storage_container_id = :storage_container_id,
                name = :name,
                location_type = :location_type,
                sector_x = :sector_x,
                sector_y = :sector_y,
                sector_z = :sector_z,
                current_task = :current_task,
                task_started_at = :task_started_at,
                task_ends_at = :task_ends_at,
                task_scheduled_event_id = :task_scheduled_event_id,
                task_payload_json = :task_payload_json,
                reserved_cargo_type = :reserved_cargo_type,
                reserved_cargo_space = :reserved_cargo_space,
                reserved_storage_container_id = :reserved_storage_container_id,
                cargo_deuterium = :cargo_deuterium,
                cargo_metals = :cargo_metals,
                cargo_ice = :cargo_ice,
                cargo_organic_compounds = :cargo_organic_compounds,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $manny->id,
            'probe_id' => $manny->probeId,
            'storage_container_id' => $manny->storageContainerId,
            'name' => $manny->name,
            'location_type' => $manny->locationType,
            'sector_x' => $manny->sector?->getX(),
            'sector_y' => $manny->sector?->getY(),
            'sector_z' => $manny->sector?->getZ(),
            'current_task' => $manny->currentTask,
            'task_started_at' => $manny->taskStartedAt,
            'task_ends_at' => $manny->taskEndsAt,
            'task_scheduled_event_id' => $manny->taskScheduledEventId,
            'task_payload_json' => $this->encodePayload($taskPayloadForMannyRow),
            'reserved_cargo_type' => $manny->reservedCargoType,
            'reserved_cargo_space' => $manny->reservedCargoSpace,
            'reserved_storage_container_id' => $manny->reservedStorageContainerId,
            'cargo_deuterium' => $manny->cargoDeuterium,
            'cargo_metals' => $manny->cargoMetals,
            'cargo_ice' => $manny->cargoIce,
            'cargo_organic_compounds' => $manny->cargoOrganicCompounds,
            'updated_at' => $manny->updatedAt,
        ]);
    }

    private function hydrate(array $row): Manny
    {
        $payload = json_decode((string) ($row['task_payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $taskScheduledEventId = isset($row['task_scheduled_event_id']) && $row['task_scheduled_event_id'] !== null
            ? (int) $row['task_scheduled_event_id']
            : null;
        if ($taskScheduledEventId !== null && ($row['current_task'] ?? null) !== null) {
            $scheduledPayload = $this->scheduledTaskPayload($taskScheduledEventId);
            if ($scheduledPayload !== null) {
                $payload = $scheduledPayload;
            }
        }

        $sector = $row['sector_x'] === null || $row['sector_y'] === null || $row['sector_z'] === null
            ? null
            : new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);

        return new Manny(
            (int) $row['id'],
            (string) $row['uid'],
            $row['probe_id'] !== null ? (int) $row['probe_id'] : null,
            isset($row['storage_container_id']) && $row['storage_container_id'] !== null ? (int) $row['storage_container_id'] : null,
            (string) $row['name'],
            (string) $row['location_type'],
            $sector,
            $row['current_task'] !== null ? (string) $row['current_task'] : null,
            $row['task_started_at'] !== null ? (string) $row['task_started_at'] : null,
            $row['task_ends_at'] !== null ? (string) $row['task_ends_at'] : null,
            $taskScheduledEventId,
            $payload,
            isset($row['reserved_cargo_type']) && $row['reserved_cargo_type'] !== null ? (string) $row['reserved_cargo_type'] : null,
            (float) ($row['reserved_cargo_space'] ?? 0),
            isset($row['reserved_storage_container_id']) && $row['reserved_storage_container_id'] !== null ? (int) $row['reserved_storage_container_id'] : null,
            (float) ($row['cargo_deuterium'] ?? 0),
            (float) ($row['cargo_metals'] ?? 0),
            (float) ($row['cargo_ice'] ?? 0),
            (float) ($row['cargo_organic_compounds'] ?? 0),
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scheduledTaskPayload(int $scheduledEventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT payload_json FROM scheduled_events WHERE id = :id');
        $stmt->execute(['id' => $scheduledEventId]);
        $payload = json_decode((string) $stmt->fetchColumn(), true);

        return is_array($payload) ? $payload : null;
    }

    private function uniqueUid(): string
    {
        do {
            $uid = 'mny_' . bin2hex(random_bytes(12));
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mannies WHERE uid = :uid');
            $stmt->execute(['uid' => $uid]);
        } while ((int) $stmt->fetchColumn() > 0);

        return $uid;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        return $payload === []
            ? '{}'
            : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
