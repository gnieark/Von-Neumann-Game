<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\Mission;
use VonNeumannGame\Domain\MissionStep;

final class MissionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<Mission>
     */
    public function activeForProbe(int $probeId): array
    {
        return $this->findForProbe($probeId, [Mission::STATUS_ACTIVE]);
    }

    /**
     * @param array<string>|null $statuses
     * @return array<Mission>
     */
    public function findForProbe(int $probeId, ?array $statuses = null): array
    {
        $params = ['probe_id' => $probeId];
        $statusSql = '';
        if ($statuses !== null) {
            $placeholders = [];
            foreach (array_values($statuses) as $index => $status) {
                $key = 'status_' . $index;
                $params[$key] = $status;
                $placeholders[] = ':' . $key;
            }
            $statusSql = ' AND status IN (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_missions
             WHERE probe_id = :probe_id' . $statusSql . '
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute($params);

        return array_map(fn(array $row): Mission => $this->hydrateMission($row, true), $stmt->fetchAll());
    }

    public function findByUidForProbe(int $probeId, string $uid): ?Mission
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_missions WHERE probe_id = :probe_id AND uid = :uid');
        $stmt->execute(['probe_id' => $probeId, 'uid' => $uid]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateMission($row, true) : null;
    }

    /**
     * @return array<Mission>
     */
    public function findByType(string $type, ?array $statuses = null): array
    {
        $params = ['type' => $type];
        $statusSql = '';
        if ($statuses !== null) {
            $placeholders = [];
            foreach (array_values($statuses) as $index => $status) {
                $key = 'status_' . $index;
                $params[$key] = $status;
                $placeholders[] = ':' . $key;
            }
            $statusSql = ' AND status IN (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_missions
             WHERE type = :type' . $statusSql . '
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute($params);

        return array_map(fn(array $row): Mission => $this->hydrateMission($row, true), $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $createdByEvent
     * @param list<array{uid?:string,title:string,description?:?string,metadata?:array<string,mixed>}> $steps
     */
    public function create(
        int $probeId,
        string $type,
        string $title,
        ?string $description = null,
        string $stepOrder = Mission::STEP_ORDER_FREE,
        array $metadata = [],
        ?array $createdByEvent = null,
        array $steps = [],
        ?string $uid = null,
    ): Mission {
        $now = gmdate('c');
        $uid ??= $this->uniqueUid('mis_', 'probe_missions');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_missions
             (uid, probe_id, type, title, description, status, step_order, metadata_json, created_by_event_json, started_at, completed_at, failed_at, abandoned_at, created_at, updated_at)
             VALUES (:uid, :probe_id, :type, :title, :description, :status, :step_order, :metadata_json, :created_by_event_json, :started_at, NULL, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'uid' => $uid,
            'probe_id' => $probeId,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'status' => Mission::STATUS_ACTIVE,
            'step_order' => $stepOrder,
            'metadata_json' => $this->encodeJsonObject($metadata),
            'created_by_event_json' => $createdByEvent === null ? null : $this->encodeJsonObject($createdByEvent),
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $missionId = (int) $this->pdo->lastInsertId();
        foreach ($steps as $index => $step) {
            $this->createStep(
                $missionId,
                (string) ($step['title'] ?? 'Step ' . ($index + 1)),
                $step['description'] ?? null,
                (array) ($step['metadata'] ?? []),
                $index + 1,
                $step['uid'] ?? null,
            );
        }

        return $this->findByUidForProbe($probeId, $uid) ?? throw new \RuntimeException('Mission creation failed.');
    }

    public function markAbandoned(Mission $mission): Mission
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_missions
             SET status = :status, abandoned_at = :abandoned_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $mission->id,
            'status' => Mission::STATUS_ABANDONED,
            'abandoned_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByUidForProbe($mission->probeId, $mission->uid) ?? throw new \RuntimeException('Mission abandon failed.');
    }

    public function markCompleted(Mission $mission): Mission
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_missions
             SET status = :status, completed_at = :completed_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $mission->id,
            'status' => Mission::STATUS_COMPLETED,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByUidForProbe($mission->probeId, $mission->uid) ?? throw new \RuntimeException('Mission completion failed.');
    }

    public function markFailed(Mission $mission): Mission
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_missions
             SET status = :status, failed_at = :failed_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $mission->id,
            'status' => Mission::STATUS_FAILED,
            'failed_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByUidForProbe($mission->probeId, $mission->uid) ?? throw new \RuntimeException('Mission failure update failed.');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createStep(int $missionId, string $title, ?string $description, array $metadata, int $sortOrder, ?string $uid = null): MissionStep
    {
        $now = gmdate('c');
        $uid ??= $this->uniqueUid('mst_', 'probe_mission_steps');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_mission_steps
             (uid, mission_id, sort_order, title, description, status, metadata_json, completed_at, failed_at, created_at, updated_at)
             VALUES (:uid, :mission_id, :sort_order, :title, :description, :status, :metadata_json, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'uid' => $uid,
            'mission_id' => $missionId,
            'sort_order' => $sortOrder,
            'title' => $title,
            'description' => $description,
            'status' => MissionStep::STATUS_PENDING,
            'metadata_json' => $this->encodeJsonObject($metadata),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findStepById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Mission step creation failed.');
    }

    public function findStepById(int $id): ?MissionStep
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_mission_steps WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateStep($row) : null;
    }

    public function findStepByUid(int $missionId, string $uid): ?MissionStep
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_mission_steps WHERE mission_id = :mission_id AND uid = :uid');
        $stmt->execute(['mission_id' => $missionId, 'uid' => $uid]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateStep($row) : null;
    }

    public function markStepCompleted(MissionStep $step): MissionStep
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_mission_steps
             SET status = :status, completed_at = :completed_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $step->id,
            'status' => MissionStep::STATUS_COMPLETED,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findStepById($step->id) ?? throw new \RuntimeException('Mission step completion failed.');
    }

    public function markStepFailed(MissionStep $step): MissionStep
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_mission_steps
             SET status = :status, failed_at = :failed_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $step->id,
            'status' => MissionStep::STATUS_FAILED,
            'failed_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findStepById($step->id) ?? throw new \RuntimeException('Mission step failure update failed.');
    }

    /**
     * @return array<MissionStep>
     */
    private function stepsForMission(int $missionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_mission_steps
             WHERE mission_id = :mission_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['mission_id' => $missionId]);

        return array_map(fn(array $row): MissionStep => $this->hydrateStep($row), $stmt->fetchAll());
    }

    private function hydrateMission(array $row, bool $withSteps): Mission
    {
        return new Mission(
            (int) $row['id'],
            (string) $row['uid'],
            (int) $row['probe_id'],
            (string) $row['type'],
            (string) $row['title'],
            $row['description'] !== null ? (string) $row['description'] : null,
            (string) $row['status'],
            (string) $row['step_order'],
            $this->decodeJsonObject($row['metadata_json'] ?? '{}'),
            $row['created_by_event_json'] !== null ? $this->decodeJsonObject($row['created_by_event_json']) : null,
            (string) $row['started_at'],
            $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            $row['failed_at'] !== null ? (string) $row['failed_at'] : null,
            $row['abandoned_at'] !== null ? (string) $row['abandoned_at'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
            $withSteps ? $this->stepsForMission((int) $row['id']) : [],
        );
    }

    private function hydrateStep(array $row): MissionStep
    {
        return new MissionStep(
            (int) $row['id'],
            (int) $row['mission_id'],
            (string) $row['uid'],
            (int) $row['sort_order'],
            (string) $row['title'],
            $row['description'] !== null ? (string) $row['description'] : null,
            (string) $row['status'],
            $this->decodeJsonObject($row['metadata_json'] ?? '{}'),
            $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            $row['failed_at'] !== null ? (string) $row['failed_at'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encodeJsonObject(array $value): string
    {
        return json_encode((object) $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function uniqueUid(string $prefix, string $table): string
    {
        do {
            $uid = $prefix . bin2hex(random_bytes(12));
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE uid = :uid');
            $stmt->execute(['uid' => $uid]);
        } while ((int) $stmt->fetchColumn() > 0);

        return $uid;
    }
}
