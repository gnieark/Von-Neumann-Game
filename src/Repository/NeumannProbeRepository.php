<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeDirection;
use VonNeumannGame\Domain\ProbeModel;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Sector\SectorCoordinates;

final class NeumannProbeRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config = [],
    ) {}

    public function createForPlayer(int $playerId, string $name, ?SectorCoordinates $sector = null, string $model = ProbeModel::GENERIC): NeumannProbe
    {
        if (!ProbeModel::isValid($model)) {
            throw new \InvalidArgumentException('Unknown probe model.');
        }
        $sector ??= SectorCoordinates::origin();
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO neumann_probes
             (player_id, name, model, sector_x, sector_y, sector_z, velocity_c, acceleration_c_per_day, direction_x, direction_y, direction_z, status, integrity_percent, energy_stored, deuterium_stock, internal_clock_rate, current_task, entered_current_sector_at, created_at, updated_at)
             VALUES (:player_id, :name, :model, :x, :y, :z, 0, 0, 0, 0, 0, :status, :integrity_percent, 0, :deuterium_stock, 1, NULL, :entered_current_sector_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'name' => $name,
            'model' => $model,
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

        $probe = $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Probe creation failed.');
        $this->assignDefaultProbeIfMissing($playerId, $probe->id);

        return $probe;
    }

    public function findByPlayerId(int $playerId): ?NeumannProbe
    {
        $stmt = $this->pdo->prepare(
            'SELECT neumann_probes.*
             FROM neumann_probes
             LEFT JOIN players ON players.id = neumann_probes.player_id
             WHERE neumann_probes.player_id = :player_id
             ORDER BY CASE WHEN neumann_probes.id = players.default_probe_id THEN 0 ELSE 1 END, neumann_probes.id ASC
             LIMIT 1'
        );
        $stmt->execute(['player_id' => $playerId]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return array<NeumannProbe>
     */
    public function findAllByPlayerId(int $playerId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM neumann_probes WHERE player_id = :player_id ORDER BY id ASC');
        $stmt->execute(['player_id' => $playerId]);

        return array_map(fn(array $row): NeumannProbe => $this->hydrate($row), $stmt->fetchAll());
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
     * Returns probes whose persisted sector or active movement destination may
     * make them observable in the requested sector. The movement service must
     * still resolve the live phase and final observable sector.
     *
     * @return array<NeumannProbe>
     */
    public function findObservableCandidatesBySector(SectorCoordinates $sector): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT p.*
             FROM neumann_probes p
             LEFT JOIN probe_movements m
               ON m.probe_id = p.id
              AND m.status IN ('preparing', 'accelerating', 'cruising', 'decelerating')
             WHERE (p.sector_x = :current_x AND p.sector_y = :current_y AND p.sector_z = :current_z)
                OR (m.target_x = :target_x AND m.target_y = :target_y AND m.target_z = :target_z)
             ORDER BY p.id ASC"
        );
        $stmt->execute([
            'current_x' => $sector->getX(),
            'current_y' => $sector->getY(),
            'current_z' => $sector->getZ(),
            'target_x' => $sector->getX(),
            'target_y' => $sector->getY(),
            'target_z' => $sector->getZ(),
        ]);

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

    /**
     * @return array<NeumannProbe>
     */
    public function findCoveredByScutNetworkId(int $networkId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT neumann_probes.*
             FROM neumann_probes
             INNER JOIN scut_covered_sectors coverage
                ON coverage.sector_x = neumann_probes.sector_x
               AND coverage.sector_y = neumann_probes.sector_y
               AND coverage.sector_z = neumann_probes.sector_z
             WHERE coverage.scut_network_id = :network_id
             ORDER BY neumann_probes.id ASC'
        );
        $stmt->execute(['network_id' => $networkId]);

        return array_map(fn(array $row): NeumannProbe => $this->hydrate($row), $stmt->fetchAll());
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
            'internal_clock_rate' => $probe->internalClockRate,
            'current_task' => $probe->currentTask,
            'entered_current_sector_at' => $probe->enteredCurrentSectorAt,
            'updated_at' => $probe->updatedAt,
            'exclude_from_stats' => $probe->excludeFromStats ? 1 : 0,
        ]);
    }

    private function assignDefaultProbeIfMissing(int $playerId, int $probeId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE players
             SET default_probe_id = :probe_id, updated_at = :updated_at
             WHERE id = :player_id
               AND (
                   default_probe_id IS NULL
                   OR NOT EXISTS (
                       SELECT 1
                       FROM neumann_probes
                       WHERE neumann_probes.id = players.default_probe_id
                         AND neumann_probes.player_id = players.id
                   )
               )'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'probe_id' => $probeId,
            'updated_at' => gmdate('c'),
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

    public function adjustDeuteriumStock(int $probeId, float $stockPercent): float
    {
        $stockPercent = round($stockPercent, 4);
        if (abs($stockPercent) <= 0.00001) {
            return $this->findById($probeId)?->deuteriumStock ?? 0.0;
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $update = $this->pdo->prepare(
                'UPDATE neumann_probes
                 SET deuterium_stock = ROUND(
                         CASE
                             WHEN deuterium_stock + :stock_percent_floor < 0 THEN 0
                             ELSE deuterium_stock + :stock_percent_value
                         END,
                         4
                     ),
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $probeId,
                'stock_percent_floor' => $stockPercent,
                'stock_percent_value' => $stockPercent,
                'updated_at' => gmdate('c'),
            ]);
            $select = $this->pdo->prepare('SELECT deuterium_stock FROM neumann_probes WHERE id = :id');
            $select->execute(['id' => $probeId]);
            $stock = (float) $select->fetchColumn();

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $stock;
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
            (float) $row['internal_clock_rate'],
            $row['current_task'] !== null ? (string) $row['current_task'] : null,
            (string) ($row['entered_current_sector_at'] ?? $row['created_at']),
            (string) $row['created_at'],
            (string) $row['updated_at'],
            (int) ($row['exclude_from_stats'] ?? 0) === 1,
            (string) ($row['model'] ?? ProbeModel::GENERIC),
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
