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
             (uid, probe_id, storage_container_id, name, location_type, sector_x, sector_y, sector_z, current_task, task_started_at, task_ends_at, task_scheduled_event_id, reserved_cargo_type, reserved_cargo_space, reserved_storage_container_id, cargo_deuterium, cargo_metals, cargo_ice, cargo_organic_compounds, created_at, updated_at)
             VALUES (:uid, :probe_id, :storage_container_id, :name, :location_type, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 0, 0, 0, :created_at, :updated_at)'
        );
        $stmt->execute([
            'uid' => $uid,
            'probe_id' => $probeId,
            'storage_container_id' => $storageContainerId,
            'name' => $name,
            'location_type' => Manny::LOCATION_PROBE,
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
        $stmt = $this->pdo->prepare($this->taskJoinSql('m.probe_id = :probe_id') . ' ORDER BY m.name ASC, m.id ASC');
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

    /** Locks active crafting reservation owners inside the caller transaction. */
    public function lockCraftingReservationsForContainer(int $probeId, int $containerId): void
    {
        $sql = 'SELECT id FROM mannies
                WHERE probe_id = :probe_id
                  AND reserved_storage_container_id = :container_id
                  AND reserved_cargo_space > 0
                  AND current_task IN (:crafting, :atomic_printing)
                ORDER BY id ASC';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'probe_id' => $probeId,
            'container_id' => $containerId,
            'crafting' => Manny::TASK_CRAFTING,
            'atomic_printing' => Manny::TASK_ASSISTING_ATOMIC_PRINTER,
        ]);
        $stmt->fetchAll();
    }

    public function findAtomicPrinterAssistantByProbeId(int $probeId): ?Manny
    {
        $stmt = $this->pdo->prepare(
            $this->taskJoinSql('m.probe_id = :probe_id AND m.current_task = :current_task') .
            ' ORDER BY m.id ASC LIMIT 1'
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
        $stmt = $this->pdo->prepare($this->taskJoinSql('m.probe_id = :probe_id AND m.uid = :uid'));
        $stmt->execute(['probe_id' => $probeId, 'uid' => $uid]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByUid(string $uid): ?Manny
    {
        $stmt = $this->pdo->prepare($this->taskJoinSql('m.uid = :uid'));
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
        $stmt = $this->pdo->prepare($this->taskJoinSql('m.id = :id'));
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
            $sql = $this->taskJoinSql('m.id = :id');
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
                        $this->eventPayload($manny->taskPayload),
                    );
                    $manny->taskScheduledEventId = $event->id;
                } else {
                    $this->scheduledEvents->updateRunAtAndPayload($manny->taskScheduledEventId, $runAt, $this->eventPayload($manny->taskPayload));
                }
                $this->saveTaskProjection($manny);
            } elseif ($manny->taskScheduledEventId !== null) {
                $this->scheduledEvents->updateRunAtAndPayload(
                    $manny->taskScheduledEventId,
                    $manny->taskEndsAt ?? ScheduledEventRepository::UNSCHEDULED_RUN_AT,
                    $this->eventPayload($manny->taskPayload),
                );
                $this->saveTaskProjection($manny);
                $this->scheduledEvents->markDoneById($manny->taskScheduledEventId);
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
        $payload = json_decode((string) ($row['active_task_payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $taskScheduledEventId = isset($row['task_scheduled_event_id']) && $row['task_scheduled_event_id'] !== null
            ? (int) $row['task_scheduled_event_id']
            : null;
        $payload = array_merge($payload, $this->operationalPayload($row));

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
            ($row['current_task'] ?? null) !== null ? $taskScheduledEventId : null,
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

    public function loadConsumedItems(Manny $manny): Manny
    {
        if ($manny->taskScheduledEventId === null || array_key_exists('consumedItems', $manny->taskPayload)) {
            return $manny;
        }
        $stmt = $this->pdo->prepare(
            'SELECT i.uid, i.type, i.name, i.container_space, i.storage_container_id, i.metadata_json
             FROM manny_task_consumed_items i
             INNER JOIN manny_tasks t ON t.id = i.manny_task_id
             WHERE t.scheduled_event_id = :event_id
             ORDER BY i.sort_order ASC'
        );
        $stmt->execute(['event_id' => $manny->taskScheduledEventId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $metadata = json_decode((string) $row['metadata_json'], true);
            $items[] = [
                'uid' => (string) $row['uid'],
                'type' => (string) $row['type'],
                'name' => (string) $row['name'],
                'containerSpace' => (float) $row['container_space'],
                'storageContainerId' => $row['storage_container_id'] !== null ? (int) $row['storage_container_id'] : null,
                'metadata' => is_array($metadata) ? $metadata : [],
            ];
        }
        $manny->taskPayload['consumedItems'] = $items;

        return $manny;
    }

    private function taskJoinSql(string $where): string
    {
        return 'SELECT m.*, se.payload_json AS active_task_payload_json,
                       mt.task_type AS projected_task_type, mt.recipe AS projected_recipe,
                       mt.crafting_run_id AS projected_crafting_run_id,
                       mt.resource_type AS projected_resource_type, mt.target_amount AS projected_target_amount,
                       mt.extracted_amount AS projected_extracted_amount, mt.object_id AS projected_object_id,
                       mt.target_object_id AS projected_target_object_id,
                       mt.target_container_id AS projected_target_container_id,
                       mt.source_container_id AS projected_source_container_id,
                       mt.destination_container_id AS projected_destination_container_id,
                       mt.target_probe_id AS projected_target_probe_id, mt.relay_id AS projected_relay_id,
                       mt.improvement AS projected_improvement
                FROM mannies m
                LEFT JOIN scheduled_events se ON se.id = m.task_scheduled_event_id
                LEFT JOIN manny_tasks mt ON mt.scheduled_event_id = se.id
                WHERE ' . $where;
    }

    /** @return array<string, mixed> */
    private function eventPayload(array $payload): array
    {
        unset(
            $payload['recipe'], $payload['craftingRunId'], $payload['resourceType'],
            $payload['targetAmount'], $payload['extractedAmount'], $payload['objectId'],
            $payload['targetObjectId'], $payload['targetContainerId'], $payload['fromContainerId'],
            $payload['toContainerId'], $payload['targetProbeId'], $payload['relayId'],
            $payload['improvement'], $payload['consumedItems']
        );
        return $payload;
    }

    /** @return array<string, mixed> */
    private function operationalPayload(array $row): array
    {
        $map = [
            'projected_recipe' => 'recipe', 'projected_crafting_run_id' => 'craftingRunId',
            'projected_resource_type' => 'resourceType', 'projected_target_amount' => 'targetAmount',
            'projected_extracted_amount' => 'extractedAmount', 'projected_object_id' => 'objectId',
            'projected_target_object_id' => 'targetObjectId', 'projected_target_container_id' => 'targetContainerId',
            'projected_source_container_id' => 'fromContainerId', 'projected_destination_container_id' => 'toContainerId',
            'projected_target_probe_id' => 'targetProbeId', 'projected_relay_id' => 'relayId',
            'projected_improvement' => 'improvement',
        ];
        $payload = [];
        foreach ($map as $column => $key) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $payload[$key] = in_array($key, ['targetAmount', 'extractedAmount'], true) ? (float) $row[$column] : $row[$column];
            }
        }
        return $payload;
    }

    private function saveTaskProjection(Manny $manny): void
    {
        if ($manny->taskScheduledEventId === null) {
            return;
        }
        $taskType = $manny->currentTask;
        if ($taskType === null) {
            $existing = $this->pdo->prepare('SELECT task_type FROM manny_tasks WHERE manny_id = :manny_id');
            $existing->execute(['manny_id' => $manny->id]);
            $value = $existing->fetchColumn();
            $taskType = $value !== false ? (string) $value : (string) ($manny->taskPayload['lastTask'] ?? 'completed');
        }
        $now = gmdate('c');
        $values = [
            'manny_id' => $manny->id, 'event_id' => $manny->taskScheduledEventId,
            'task_type' => $taskType, 'recipe' => $manny->taskPayload['recipe'] ?? null,
            'run_id' => $manny->taskPayload['craftingRunId'] ?? null, 'resource_type' => $manny->taskPayload['resourceType'] ?? null,
            'target_amount' => $manny->taskPayload['targetAmount'] ?? null, 'extracted_amount' => $manny->taskPayload['extractedAmount'] ?? null,
            'object_id' => $manny->taskPayload['objectId'] ?? null, 'target_object_id' => $manny->taskPayload['targetObjectId'] ?? null,
            'target_container_id' => $manny->taskPayload['targetContainerId'] ?? null, 'source_container_id' => $manny->taskPayload['fromContainerId'] ?? null,
            'destination_container_id' => $manny->taskPayload['toContainerId'] ?? null, 'target_probe_id' => $manny->taskPayload['targetProbeId'] ?? null,
            'relay_id' => $manny->taskPayload['relayId'] ?? null, 'improvement' => $manny->taskPayload['improvement'] ?? null,
            'created_at' => $now, 'updated_at' => $now,
        ];
        $columns = 'manny_id, scheduled_event_id, task_type, recipe, crafting_run_id, resource_type, target_amount, extracted_amount, object_id, target_object_id, target_container_id, source_container_id, destination_container_id, target_probe_id, relay_id, improvement, created_at, updated_at';
        $params = ':manny_id, :event_id, :task_type, :recipe, :run_id, :resource_type, :target_amount, :extracted_amount, :object_id, :target_object_id, :target_container_id, :source_container_id, :destination_container_id, :target_probe_id, :relay_id, :improvement, :created_at, :updated_at';
        $updates = 'task_type = :task_type, recipe = :recipe, crafting_run_id = :run_id, resource_type = :resource_type, target_amount = :target_amount, extracted_amount = :extracted_amount, object_id = :object_id, target_object_id = :target_object_id, target_container_id = :target_container_id, source_container_id = :source_container_id, destination_container_id = :destination_container_id, target_probe_id = :target_probe_id, relay_id = :relay_id, improvement = :improvement, updated_at = :updated_at';
        $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "INSERT INTO manny_tasks ($columns) VALUES ($params) ON CONFLICT(manny_id) DO UPDATE SET scheduled_event_id = :event_id, $updates"
            : "INSERT INTO manny_tasks ($columns) VALUES ($params) ON DUPLICATE KEY UPDATE scheduled_event_id = :event_id, $updates";
        $this->pdo->prepare($sql)->execute($values);

        if (array_key_exists('consumedItems', $manny->taskPayload)) {
            $taskIdStmt = $this->pdo->prepare('SELECT id FROM manny_tasks WHERE manny_id = :manny_id');
            $taskIdStmt->execute(['manny_id' => $manny->id]);
            $taskId = (int) $taskIdStmt->fetchColumn();
            $this->pdo->prepare('DELETE FROM manny_task_consumed_items WHERE manny_task_id = :task_id')->execute(['task_id' => $taskId]);
            $insert = $this->pdo->prepare('INSERT INTO manny_task_consumed_items (manny_task_id, sort_order, uid, type, name, container_space, storage_container_id, metadata_json) VALUES (:task_id, :sort_order, :uid, :type, :name, :space, :container_id, :metadata)');
            foreach (array_values(is_array($manny->taskPayload['consumedItems']) ? $manny->taskPayload['consumedItems'] : []) as $order => $item) {
                if (!is_array($item)) continue;
                $insert->execute(['task_id'=>$taskId,'sort_order'=>$order,'uid'=>(string)($item['uid']??''),'type'=>(string)($item['type']??''),'name'=>(string)($item['name']??''),'space'=>(float)($item['containerSpace']??0),'container_id'=>$item['storageContainerId']??null,'metadata'=>json_encode(is_array($item['metadata']??null)?$item['metadata']:[], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            }
        }
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
