<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\AsteroidTrajectory;

/** Active references follow motorization; historical records and committed effects stay immutable. */
final class MineableObjectReferenceRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function replace(string $oldId, string $newId): void
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Identity references must join the stock transaction.'); }
        MineableObjectStockRepository::validateId($oldId);
        MineableObjectStockRepository::validateId($newId);
        if ($oldId === $newId) { return; }
        $now = gmdate('c');
        foreach (['object_id', 'target_object_id'] as $column) {
            $this->pdo->prepare("UPDATE manny_tasks SET $column=?,updated_at=? WHERE $column=? AND scheduled_event_id IN (SELECT id FROM scheduled_events WHERE status IN ('pending','running'))")
                ->execute([$newId, $now, $oldId]);
        }
        $this->pdo->prepare('UPDATE detached_storage_containers SET target_object_id=?,updated_at=? WHERE target_object_id=?')->execute([$newId, $now, $oldId]);
        $this->pdo->prepare('UPDATE others_auxiliaries SET object_id=?,updated_at=? WHERE object_id=? AND destroyed_at IS NULL')->execute([$newId, $now, $oldId]);
        $statuses = implode(',', array_fill(0, count(AsteroidTrajectory::ACTIVE_STATUSES), '?'));
        $this->pdo->prepare("UPDATE asteroid_trajectories SET target_object_id=?,updated_at=? WHERE target_object_id=? AND status IN ($statuses)")
            ->execute([$newId, $now, $oldId, ...AsteroidTrajectory::ACTIVE_STATUSES]);
        foreach (['scheduled_events' => "('pending','running')", 'others_actions' => "('queued','running','cancel_requested')"] as $table => $active) {
            $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            // The LIKE is only a candidate filter; the decoded reference keys decide the change.
            $stmt = $this->pdo->prepare("SELECT id,payload_json FROM $table WHERE status IN $active AND payload_json LIKE ? ORDER BY id" . $suffix);
            $stmt->execute(['%' . $oldId . '%']);
            $update = $this->pdo->prepare("UPDATE $table SET payload_json=?,updated_at=? WHERE id=?");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                $after = $this->replacePayload($payload, $oldId, $newId);
                if ($after !== $payload) { $update->execute([json_encode($after, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $now, $row['id']]); }
            }
        }
    }

    private function replacePayload(array $payload, string $oldId, string $newId): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) { $payload[$key] = $this->replacePayload($value, $oldId, $newId); }
            elseif ($value === $oldId && in_array($key, ['id', 'objectId', 'targetObjectId', 'asteroidId', 'targetId', 'capturedByObjectId'], true)) { $payload[$key] = $newId; }
        }
        return $payload;
    }
}
