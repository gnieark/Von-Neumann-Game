<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeDirection;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Sector\SectorCoordinates;

final class NeumannProbeRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config = [],
    ) {}

    public function createForPlayer(int $playerId, string $name, ?SectorCoordinates $sector = null): NeumannProbe
    {
        $sector ??= SectorCoordinates::origin();
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO neumann_probes
             (player_id, name, sector_x, sector_y, sector_z, velocity_c, acceleration_c_per_day, direction_x, direction_y, direction_z, status, integrity_percent, energy_stored, deuterium_stock, metals_stock, ice_stock, organic_compounds_stock, internal_clock_rate, current_task, entered_current_sector_at, created_at, updated_at)
             VALUES (:player_id, :name, :x, :y, :z, 0, 0, 0, 0, 0, :status, :integrity_percent, 0, :deuterium_stock, 0, 0, 0, 1, NULL, :entered_current_sector_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'name' => $name,
            'x' => $sector->getX(),
            'y' => $sector->getY(),
            'z' => $sector->getZ(),
            'status' => ProbeStatus::Idle->value,
            'integrity_percent' => $this->initialIntegrityPercent(),
            'deuterium_stock' => $this->initialDeuteriumPercent(),
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

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withProbeLock(int $probeId, callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $lock = $this->pdo->prepare('UPDATE neumann_probes SET updated_at = updated_at WHERE id = :id');
            $lock->execute(['id' => $probeId]);
            $result = $callback();

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @return array<NeumannProbe>
     */
    public function findBySector(SectorCoordinates $sector, ?int $excludeId = null): array
    {
        $sql = 'SELECT * FROM neumann_probes WHERE sector_x = :x AND sector_y = :y AND sector_z = :z';
        $params = [
            'x' => $sector->getX(),
            'y' => $sector->getY(),
            'z' => $sector->getZ(),
        ];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn(array $row): NeumannProbe => $this->hydrate($row), $stmt->fetchAll());
    }

    /**
     * @return array<NeumannProbe>
     */
    public function findWithinRange(SectorCoordinates $sector, int $radius, ?int $excludeId = null): array
    {
        $radius = max(0, $radius);
        $sql = 'SELECT * FROM neumann_probes
                WHERE sector_x BETWEEN :min_x AND :max_x
                  AND sector_y BETWEEN :min_y AND :max_y
                  AND sector_z BETWEEN :min_z AND :max_z';
        $params = [
            'min_x' => $sector->getX() - $radius,
            'max_x' => $sector->getX() + $radius,
            'min_y' => $sector->getY() - $radius,
            'max_y' => $sector->getY() + $radius,
            'min_z' => $sector->getZ() - $radius,
            'max_z' => $sector->getZ() + $radius,
        ];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_values(array_filter(
            array_map(fn(array $row): NeumannProbe => $this->hydrate($row), $stmt->fetchAll()),
            static fn(NeumannProbe $probe): bool => max(
                abs($probe->currentSector->getX() - $sector->getX()),
                abs($probe->currentSector->getY() - $sector->getY()),
                abs($probe->currentSector->getZ() - $sector->getZ()),
            ) <= $radius,
        ));
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
                deuterium_stock = :deuterium_stock,
                metals_stock = :metals_stock,
                ice_stock = :ice_stock,
                organic_compounds_stock = :organic_compounds_stock,
                internal_clock_rate = :internal_clock_rate,
                current_task = :current_task,
                entered_current_sector_at = :entered_current_sector_at,
                updated_at = :updated_at,
                exclude_from_stats = :exclude_from_stats
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
            'deuterium_stock' => $probe->deuteriumStock,
            'metals_stock' => $probe->metalsStock,
            'ice_stock' => $probe->iceStock,
            'organic_compounds_stock' => $probe->organicCompoundsStock,
            'internal_clock_rate' => $probe->internalClockRate,
            'current_task' => $probe->currentTask,
            'entered_current_sector_at' => $probe->enteredCurrentSectorAt,
            'updated_at' => $probe->updatedAt,
            'exclude_from_stats' => $probe->excludeFromStats ? 1 : 0,
        ]);
    }

    /**
     * @return array{accepted:float, stock:float}
     */
    public function addDeuteriumStock(int $probeId, float $stockPercent, float $maxStockPercent): array
    {
        $stockPercent = round(max(0.0, $stockPercent), 4);
        $maxStockPercent = round(max(0.0001, $maxStockPercent), 4);
        if ($stockPercent <= 0.0) {
            $probe = $this->findById($probeId);

            return [
                'accepted' => 0.0,
                'stock' => $probe?->deuteriumStock ?? 0.0,
            ];
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $sql = 'SELECT deuterium_stock FROM neumann_probes WHERE id = :id';
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $sql .= ' FOR UPDATE';
            }
            $select = $this->pdo->prepare($sql);
            $select->execute(['id' => $probeId]);
            $before = max(0.0, min($maxStockPercent, (float) $select->fetchColumn()));
            $accepted = round(min($stockPercent, max(0.0, $maxStockPercent - $before)), 4);
            $after = round(min($maxStockPercent, $before + $accepted), 4);

            if ($accepted > 0.0) {
                $stmt = $this->pdo->prepare(
                    'UPDATE neumann_probes
                     SET deuterium_stock = :deuterium_stock, updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => $probeId,
                    'deuterium_stock' => $after,
                    'updated_at' => gmdate('c'),
                ]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return [
                'accepted' => $accepted,
                'stock' => $after,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
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
            max(0.0, min($this->maxIntegrityPercent(), (float) $row['integrity_percent'])),
            (float) $row['energy_stored'],
            max(0.0, (float) ($row['deuterium_stock'] ?? $this->initialDeuteriumPercent())),
            (float) ($row['metals_stock'] ?? 0),
            (float) ($row['ice_stock'] ?? 0),
            (float) ($row['organic_compounds_stock'] ?? 0),
            (float) $row['internal_clock_rate'],
            $row['current_task'] !== null ? (string) $row['current_task'] : null,
            (string) ($row['entered_current_sector_at'] ?? $row['created_at']),
            (string) $row['created_at'],
            (string) $row['updated_at'],
            (int) ($row['exclude_from_stats'] ?? 0) === 1,
        );
    }

    private function initialIntegrityPercent(): float
    {
        return max(0.0, min($this->maxIntegrityPercent(), Config::float($this->config, 'probe.initialIntegrityPercent', 100.0)));
    }

    private function maxIntegrityPercent(): float
    {
        return max(0.0001, Config::float($this->config, 'probe.maxIntegrityPercent', 100.0));
    }

    private function initialDeuteriumPercent(): float
    {
        return max(0.0, min($this->maxDeuteriumPercent(), Config::float($this->config, 'probe.initialDeuteriumPercent', 100.0)));
    }

    private function maxDeuteriumPercent(): float
    {
        return max(0.0001, Config::float($this->config, 'probe.maxDeuteriumPercent', 100.0));
    }
}
