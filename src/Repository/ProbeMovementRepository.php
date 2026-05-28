<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ProbeMovement;
use VonNeumannGame\Sector\SectorCoordinates;

final class ProbeMovementRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(
        int $probeId,
        SectorCoordinates $origin,
        SectorCoordinates $target,
        int $distance,
        array $timeline,
        float $fuelCostDeuterium,
    ): ProbeMovement {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_movements
             (probe_id, origin_x, origin_y, origin_z, target_x, target_y, target_z, distance, status, started_at, preparation_ends_at, acceleration_ends_at, cruise_ends_at, deceleration_ends_at, arrival_at, fuel_cost_deuterium, destruction_checked_at, destroyed_at, destruction_reason, created_at, updated_at)
             VALUES (:probe_id, :origin_x, :origin_y, :origin_z, :target_x, :target_y, :target_z, :distance, :status, :started_at, :preparation_ends_at, :acceleration_ends_at, :cruise_ends_at, :deceleration_ends_at, :arrival_at, :fuel_cost_deuterium, NULL, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'origin_x' => $origin->getX(),
            'origin_y' => $origin->getY(),
            'origin_z' => $origin->getZ(),
            'target_x' => $target->getX(),
            'target_y' => $target->getY(),
            'target_z' => $target->getZ(),
            'distance' => $distance,
            'status' => 'preparing',
            'started_at' => $timeline['startedAt']->format('c'),
            'preparation_ends_at' => $timeline['preparationEndsAt']->format('c'),
            'acceleration_ends_at' => $timeline['accelerationEndsAt']->format('c'),
            'cruise_ends_at' => $timeline['cruiseEndsAt']->format('c'),
            'deceleration_ends_at' => $timeline['decelerationEndsAt']->format('c'),
            'arrival_at' => $timeline['arrivalAt']->format('c'),
            'fuel_cost_deuterium' => $fuelCostDeuterium,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Movement creation failed.');
    }

    public function findById(int $id): ?ProbeMovement
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_movements WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findActiveByProbeId(int $probeId): ?ProbeMovement
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM probe_movements
             WHERE probe_id = :probe_id AND status IN ('preparing', 'accelerating', 'cruising', 'decelerating')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['probe_id' => $probeId]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findLatestByProbeId(int $probeId): ?ProbeMovement
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_movements WHERE probe_id = :probe_id ORDER BY id DESC LIMIT 1');
        $stmt->execute(['probe_id' => $probeId]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function save(ProbeMovement $movement): void
    {
        $movement->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_movements SET status = :status, destruction_checked_at = :destruction_checked_at, destroyed_at = :destroyed_at, destruction_reason = :destruction_reason, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $movement->id,
            'status' => $movement->status,
            'destruction_checked_at' => $movement->destructionCheckedAt,
            'destroyed_at' => $movement->destroyedAt,
            'destruction_reason' => $movement->destructionReason,
            'updated_at' => $movement->updatedAt,
        ]);
    }

    private function hydrate(array $row): ProbeMovement
    {
        return new ProbeMovement(
            (int) $row['id'],
            (int) $row['probe_id'],
            new SectorCoordinates((int) $row['origin_x'], (int) $row['origin_y'], (int) $row['origin_z']),
            new SectorCoordinates((int) $row['target_x'], (int) $row['target_y'], (int) $row['target_z']),
            (int) $row['distance'],
            (string) $row['status'],
            (string) $row['started_at'],
            (string) $row['preparation_ends_at'],
            (string) $row['acceleration_ends_at'],
            (string) $row['cruise_ends_at'],
            (string) $row['deceleration_ends_at'],
            (string) $row['arrival_at'],
            (float) $row['fuel_cost_deuterium'],
            $row['destruction_checked_at'] !== null ? (string) $row['destruction_checked_at'] : null,
            $row['destroyed_at'] !== null ? (string) $row['destroyed_at'] : null,
            $row['destruction_reason'] !== null ? (string) $row['destruction_reason'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
