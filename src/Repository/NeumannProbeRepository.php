<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeDirection;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Sector\SectorCoordinates;

final class NeumannProbeRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function createForPlayer(int $playerId, string $name, ?SectorCoordinates $sector = null): NeumannProbe
    {
        $sector ??= SectorCoordinates::origin();
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO neumann_probes
             (player_id, name, sector_x, sector_y, sector_z, velocity_c, acceleration_c_per_day, direction_x, direction_y, direction_z, status, integrity_percent, energy_stored, internal_clock_rate, current_task, entered_current_sector_at, created_at, updated_at)
             VALUES (:player_id, :name, :x, :y, :z, 0, 0, 0, 0, 0, :status, 100, 0, 1, NULL, :entered_current_sector_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'name' => $name,
            'x' => $sector->getX(),
            'y' => $sector->getY(),
            'z' => $sector->getZ(),
            'status' => ProbeStatus::Idle->value,
            'entered_current_sector_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Probe creation failed.');
    }

    public function findByPlayerId(int $playerId): ?NeumannProbe
    {
        $stmt = $this->pdo->prepare('SELECT * FROM neumann_probes WHERE player_id = :player_id');
        $stmt->execute(['player_id' => $playerId]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?NeumannProbe
    {
        $stmt = $this->pdo->prepare('SELECT * FROM neumann_probes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function save(NeumannProbe $probe): void
    {
        $probe->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE neumann_probes SET
                name = :name,
                sector_x = :x,
                sector_y = :y,
                sector_z = :z,
                velocity_c = :velocity_c,
                acceleration_c_per_day = :acceleration_c_per_day,
                direction_x = :direction_x,
                direction_y = :direction_y,
                direction_z = :direction_z,
                status = :status,
                integrity_percent = :integrity_percent,
                energy_stored = :energy_stored,
                internal_clock_rate = :internal_clock_rate,
                current_task = :current_task,
                entered_current_sector_at = :entered_current_sector_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $probe->id,
            'name' => $probe->name,
            'x' => $probe->currentSector->getX(),
            'y' => $probe->currentSector->getY(),
            'z' => $probe->currentSector->getZ(),
            'velocity_c' => $probe->velocityC,
            'acceleration_c_per_day' => $probe->accelerationCPerDay,
            'direction_x' => $probe->direction->x,
            'direction_y' => $probe->direction->y,
            'direction_z' => $probe->direction->z,
            'status' => $probe->status->value,
            'integrity_percent' => $probe->integrityPercent,
            'energy_stored' => $probe->energyStored,
            'internal_clock_rate' => $probe->internalClockRate,
            'current_task' => $probe->currentTask,
            'entered_current_sector_at' => $probe->enteredCurrentSectorAt,
            'updated_at' => $probe->updatedAt,
        ]);
    }

    private function hydrate(array $row): NeumannProbe
    {
        return new NeumannProbe(
            (int) $row['id'],
            (int) $row['player_id'],
            (string) $row['name'],
            new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']),
            (float) $row['velocity_c'],
            (float) $row['acceleration_c_per_day'],
            new ProbeDirection((float) $row['direction_x'], (float) $row['direction_y'], (float) $row['direction_z']),
            ProbeStatus::from((string) $row['status']),
            (float) $row['integrity_percent'],
            (float) $row['energy_stored'],
            (float) $row['internal_clock_rate'],
            $row['current_task'] !== null ? (string) $row['current_task'] : null,
            (string) ($row['entered_current_sector_at'] ?? $row['created_at']),
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
