<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\AsteroidTrajectory;
use VonNeumannGame\Sector\SectorCoordinates;

final class AsteroidTrajectoryRepository
{
    private const MUTABLE_COLUMNS = [
        'asteroid_id', 'status', 'current_sector_x', 'current_sector_y', 'current_sector_z',
        'next_transition_at', 'sectors_crossed', 'capture_penalty_steps', 'result', 'failure_reason',
        'asteroid_snapshot_json', 'attachments_snapshot_json',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string, mixed> $values */
    public function create(array $values): AsteroidTrajectory
    {
        $now = gmdate('c');
        $uid = 'atr_' . bin2hex(random_bytes(12));
        $stmt = $this->pdo->prepare(
            'INSERT INTO asteroid_trajectories
             (uid, asteroid_id, mode, status, origin_sector_x, origin_sector_y, origin_sector_z,
              current_sector_x, current_sector_y, current_sector_z, direction_x, direction_y, direction_z,
              target_object_id, target_probe_id, target_speed_c, asteroid_mass_earth, star_mass_solar,
              acceleration_started_at, acceleration_ends_at, revolution_duration_seconds, planned_revolutions,
              next_transition_at, sectors_crossed, capture_penalty_steps, maximum_sector_crossings,
              result, failure_reason, asteroid_snapshot_json, attachments_snapshot_json, created_at, updated_at)
             VALUES
             (:uid, :asteroid_id, :mode, :status, :origin_x, :origin_y, :origin_z,
              :current_x, :current_y, :current_z, :direction_x, :direction_y, :direction_z,
              :target_object_id, :target_probe_id, :target_speed_c, :asteroid_mass_earth, :star_mass_solar,
              :acceleration_started_at, :acceleration_ends_at, :revolution_duration_seconds, :planned_revolutions,
              :next_transition_at, :sectors_crossed, :capture_penalty_steps, :maximum_sector_crossings,
              NULL, NULL, :asteroid_snapshot_json, :attachments_snapshot_json, :created_at, :updated_at)'
        );
        $origin = $values['originSector'];
        $current = $values['currentSector'] ?? $origin;
        if (!$origin instanceof SectorCoordinates || !$current instanceof SectorCoordinates) {
            throw new \InvalidArgumentException('Trajectory sectors must be SectorCoordinates values.');
        }
        $direction = is_array($values['direction'] ?? null) ? $values['direction'] : [];
        $stmt->execute([
            'uid' => $uid,
            'asteroid_id' => (string) $values['asteroidId'],
            'mode' => (string) $values['mode'],
            'status' => (string) $values['status'],
            'origin_x' => $origin->getX(), 'origin_y' => $origin->getY(), 'origin_z' => $origin->getZ(),
            'current_x' => $current->getX(), 'current_y' => $current->getY(), 'current_z' => $current->getZ(),
            'direction_x' => $direction['x'] ?? null, 'direction_y' => $direction['y'] ?? null, 'direction_z' => $direction['z'] ?? null,
            'target_object_id' => $values['targetObjectId'] ?? null,
            'target_probe_id' => $values['targetProbeId'] ?? null,
            'target_speed_c' => $values['targetSpeedC'] ?? null,
            'asteroid_mass_earth' => (float) $values['asteroidMassEarth'],
            'star_mass_solar' => $values['starMassSolar'] ?? null,
            'acceleration_started_at' => $values['accelerationStartedAt'] ?? null,
            'acceleration_ends_at' => $values['accelerationEndsAt'] ?? null,
            'revolution_duration_seconds' => $values['revolutionDurationSeconds'] ?? null,
            'planned_revolutions' => $values['plannedRevolutions'] ?? null,
            'next_transition_at' => (string) $values['nextTransitionAt'],
            'sectors_crossed' => (int) ($values['sectorsCrossed'] ?? 0),
            'capture_penalty_steps' => (int) ($values['capturePenaltySteps'] ?? 0),
            'maximum_sector_crossings' => (int) $values['maximumSectorCrossings'],
            'asteroid_snapshot_json' => json_encode($values['asteroidSnapshot'] ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'attachments_snapshot_json' => json_encode($values['attachmentsSnapshot'] ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Trajectory creation failed.');
    }

    public function findById(int $id): ?AsteroidTrajectory
    {
        $stmt = $this->pdo->prepare('SELECT * FROM asteroid_trajectories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Serializes a phase resolver on the trajectory row. The callback may use
     * the other repositories sharing this PDO connection in the same transaction.
     */
    public function withLockedTrajectory(int $id, callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare('SELECT * FROM asteroid_trajectories WHERE id = :id' . $suffix);
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $result = $callback($row ? $this->hydrate($row) : null);
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

    public function findByUid(string $uid): ?AsteroidTrajectory
    {
        $stmt = $this->pdo->prepare('SELECT * FROM asteroid_trajectories WHERE uid = :uid');
        $stmt->execute(['uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function findActiveByAsteroidId(string $asteroidId): ?AsteroidTrajectory
    {
        $placeholders = implode(',', array_fill(0, count(AsteroidTrajectory::ACTIVE_STATUSES), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM asteroid_trajectories WHERE asteroid_id = ? AND status IN ({$placeholders}) ORDER BY id DESC LIMIT 1");
        $stmt->execute([$asteroidId, ...AsteroidTrajectory::ACTIVE_STATUSES]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** @return list<AsteroidTrajectory> */
    public function findActiveInSector(SectorCoordinates $sector): array
    {
        $placeholders = implode(',', array_fill(0, count(AsteroidTrajectory::ACTIVE_STATUSES), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM asteroid_trajectories
             WHERE current_sector_x = ? AND current_sector_y = ? AND current_sector_z = ?
               AND status IN ({$placeholders}) ORDER BY id"
        );
        $stmt->execute([$sector->getX(), $sector->getY(), $sector->getZ(), ...AsteroidTrajectory::ACTIVE_STATUSES]);
        return array_map(fn(array $row): AsteroidTrajectory => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Applies a phase transition only from an expected status. Replays after another handler advanced the
     * trajectory simply return its current state without applying the mutation twice.
     *
     * @param list<string> $expectedStatuses
     * @param array<string, mixed> $changes
     */
    public function transition(int $id, array $expectedStatuses, array $changes): AsteroidTrajectory
    {
        if ($expectedStatuses === [] || $changes === []) {
            return $this->findById($id) ?? throw new \RuntimeException('Trajectory not found.');
        }
        foreach (array_keys($changes) as $column) {
            if (!in_array($column, self::MUTABLE_COLUMNS, true)) {
                throw new \InvalidArgumentException("Trajectory column '{$column}' cannot be changed by a phase handler.");
            }
        }
        $assignments = [];
        $parameters = ['id' => $id, 'updated_at' => gmdate('c')];
        foreach ($changes as $column => $value) {
            $assignments[] = "{$column} = :set_{$column}";
            $parameters["set_{$column}"] = $value;
        }
        $statusParameters = [];
        foreach (array_values($expectedStatuses) as $index => $status) {
            $name = 'expected_' . $index;
            $statusParameters[] = ':' . $name;
            $parameters[$name] = $status;
        }
        $assignments[] = 'updated_at = :updated_at';
        $stmt = $this->pdo->prepare(
            'UPDATE asteroid_trajectories SET ' . implode(', ', $assignments)
            . ' WHERE id = :id AND status IN (' . implode(',', $statusParameters) . ')'
        );
        $stmt->execute($parameters);

        return $this->findById($id) ?? throw new \RuntimeException('Trajectory not found after transition.');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AsteroidTrajectory
    {
        $direction = $row['direction_x'] === null ? null : [
            'x' => (int) $row['direction_x'], 'y' => (int) $row['direction_y'], 'z' => (int) $row['direction_z'],
        ];
        return new AsteroidTrajectory(
            (int) $row['id'], (string) $row['uid'], (string) $row['asteroid_id'], (string) $row['mode'], (string) $row['status'],
            new SectorCoordinates((int) $row['origin_sector_x'], (int) $row['origin_sector_y'], (int) $row['origin_sector_z']),
            new SectorCoordinates((int) $row['current_sector_x'], (int) $row['current_sector_y'], (int) $row['current_sector_z']),
            $direction,
            $row['target_object_id'] !== null ? (string) $row['target_object_id'] : null,
            $row['target_probe_id'] !== null ? (int) $row['target_probe_id'] : null,
            $row['target_speed_c'] !== null ? (float) $row['target_speed_c'] : null,
            (float) $row['asteroid_mass_earth'],
            $row['star_mass_solar'] !== null ? (float) $row['star_mass_solar'] : null,
            $row['acceleration_started_at'] !== null ? (string) $row['acceleration_started_at'] : null,
            $row['acceleration_ends_at'] !== null ? (string) $row['acceleration_ends_at'] : null,
            $row['revolution_duration_seconds'] !== null ? (int) $row['revolution_duration_seconds'] : null,
            $row['planned_revolutions'] !== null ? (int) $row['planned_revolutions'] : null,
            (string) $row['next_transition_at'], (int) $row['sectors_crossed'], (int) $row['capture_penalty_steps'],
            (int) $row['maximum_sector_crossings'],
            $row['result'] !== null ? (string) $row['result'] : null,
            $row['failure_reason'] !== null ? (string) $row['failure_reason'] : null,
            json_decode((string) $row['asteroid_snapshot_json'], true, 512, JSON_THROW_ON_ERROR),
            json_decode((string) $row['attachments_snapshot_json'], true, 512, JSON_THROW_ON_ERROR),
            (string) $row['created_at'], (string) $row['updated_at'],
        );
    }
}
