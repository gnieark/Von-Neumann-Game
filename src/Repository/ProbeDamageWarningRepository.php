<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ProbeDamageWarning;
use VonNeumannGame\Sector\SectorCoordinates;

final class ProbeDamageWarningRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function createStorageContainerBreakWarning(
        int $probeId,
        int $movementId,
        string $phase,
        string $scheduledAt,
        SectorCoordinates $sector,
        string $containerId,
        string $containerLabel,
        string $objectId,
        float $riskPercent,
        int $additionalContainerCount,
        string $message,
    ): ProbeDamageWarning {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_damage_warnings
             (probe_id, movement_id, type, status, phase, scheduled_at, sector_x, sector_y, sector_z, container_id, container_label, object_id, risk_percent, additional_container_count, message, read_at, resolved_at, created_at, updated_at)
             VALUES (:probe_id, :movement_id, :type, :status, :phase, :scheduled_at, :sector_x, :sector_y, :sector_z, :container_id, :container_label, :object_id, :risk_percent, :additional_container_count, :message, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'movement_id' => $movementId,
            'type' => ProbeDamageWarning::TYPE_STORAGE_CONTAINER_BREAK,
            'status' => ProbeDamageWarning::STATUS_UNREAD,
            'phase' => $phase,
            'scheduled_at' => $scheduledAt,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'container_id' => $containerId,
            'container_label' => $containerLabel,
            'object_id' => $objectId,
            'risk_percent' => round(max(0.0, $riskPercent), 2),
            'additional_container_count' => max(0, $additionalContainerCount),
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Damage warning creation failed.');
    }

    public function createIntelligentLifeAlert(
        int $probeId,
        int $movementId,
        SectorCoordinates $sector,
        string $planetId,
        string $planetName,
        string $message,
    ): ProbeDamageWarning {
        $existing = $this->findByProbeMovementTypeAndObject(
            $probeId,
            $movementId,
            ProbeDamageWarning::TYPE_INTELLIGENT_LIFE,
            $planetId,
        );
        if ($existing !== null) {
            return $existing;
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_damage_warnings
             (probe_id, movement_id, type, status, phase, scheduled_at, sector_x, sector_y, sector_z, container_id, container_label, object_id, risk_percent, additional_container_count, message, read_at, resolved_at, created_at, updated_at)
             VALUES (:probe_id, :movement_id, :type, :status, :phase, :scheduled_at, :sector_x, :sector_y, :sector_z, :container_id, :container_label, :object_id, :risk_percent, :additional_container_count, :message, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'movement_id' => $movementId,
            'type' => ProbeDamageWarning::TYPE_INTELLIGENT_LIFE,
            'status' => ProbeDamageWarning::STATUS_UNREAD,
            'phase' => 'arrival',
            'scheduled_at' => $now,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'container_id' => '',
            'container_label' => $planetName,
            'object_id' => $planetId,
            'risk_percent' => 0.0,
            'additional_container_count' => 0,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Probe alert creation failed.');
    }

    public function createSectorObjectDetectedAlert(
        int $probeId,
        int $movementId,
        SectorCoordinates $sector,
        string $objectId,
        string $objectType,
        string $objectLabel,
        string $message,
    ): ProbeDamageWarning {
        $existing = $this->findByProbeMovementTypeAndObject(
            $probeId,
            $movementId,
            ProbeDamageWarning::TYPE_SECTOR_OBJECT_DETECTED,
            $objectId,
        );
        if ($existing !== null) {
            return $existing;
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_damage_warnings
             (probe_id, movement_id, type, status, phase, scheduled_at, sector_x, sector_y, sector_z, container_id, container_label, object_id, risk_percent, additional_container_count, message, read_at, resolved_at, created_at, updated_at)
             VALUES (:probe_id, :movement_id, :type, :status, :phase, :scheduled_at, :sector_x, :sector_y, :sector_z, :container_id, :container_label, :object_id, :risk_percent, :additional_container_count, :message, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'movement_id' => $movementId,
            'type' => ProbeDamageWarning::TYPE_SECTOR_OBJECT_DETECTED,
            'status' => ProbeDamageWarning::STATUS_UNREAD,
            'phase' => 'detection',
            'scheduled_at' => $now,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'container_id' => $objectType,
            'container_label' => $objectLabel,
            'object_id' => $objectId,
            'risk_percent' => 0.0,
            'additional_container_count' => 0,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Probe object-detection alert creation failed.');
    }

    public function createAnomalyDetectedAlert(
        int $probeId,
        int $movementId,
        SectorCoordinates $sector,
        string $message,
    ): ProbeDamageWarning {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_damage_warnings
             (probe_id, movement_id, type, status, phase, scheduled_at, sector_x, sector_y, sector_z, container_id, container_label, object_id, risk_percent, additional_container_count, message, read_at, resolved_at, created_at, updated_at)
             VALUES (:probe_id, :movement_id, :type, :status, :phase, :scheduled_at, :sector_x, :sector_y, :sector_z, :container_id, :container_label, :object_id, :risk_percent, :additional_container_count, :message, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'movement_id' => $movementId,
            'type' => ProbeDamageWarning::TYPE_ANOMALY_DETECTED,
            'status' => ProbeDamageWarning::STATUS_UNREAD,
            'phase' => 'detection',
            'scheduled_at' => $now,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'container_id' => '',
            'container_label' => '',
            'object_id' => 'origin-anomaly-signal',
            'risk_percent' => 0.0,
            'additional_container_count' => 0,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Probe anomaly alert creation failed.');
    }

    public function createMannyReportAlert(
        int $probeId,
        SectorCoordinates $sector,
        string $objectId,
        string $objectLabel,
        string $message,
        string $objectType = 'detached_storage_container',
    ): ProbeDamageWarning {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_damage_warnings
             (probe_id, movement_id, type, status, phase, scheduled_at, sector_x, sector_y, sector_z, container_id, container_label, object_id, risk_percent, additional_container_count, message, read_at, resolved_at, created_at, updated_at)
             VALUES (:probe_id, :movement_id, :type, :status, :phase, :scheduled_at, :sector_x, :sector_y, :sector_z, :container_id, :container_label, :object_id, :risk_percent, :additional_container_count, :message, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'movement_id' => 0,
            'type' => ProbeDamageWarning::TYPE_MANNY_REPORT,
            'status' => ProbeDamageWarning::STATUS_UNREAD,
            'phase' => 'manny_report',
            'scheduled_at' => $now,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'container_id' => $objectType,
            'container_label' => $objectLabel,
            'object_id' => $objectId,
            'risk_percent' => 0.0,
            'additional_container_count' => 0,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Manny report alert creation failed.');
    }

    /**
     * @return array<ProbeDamageWarning>
     */
    public function findByProbeId(int $probeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_damage_warnings
             WHERE probe_id = :probe_id
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['probe_id' => $probeId]);

        return array_map(fn(array $row): ProbeDamageWarning => $this->hydrate($row), $stmt->fetchAll());
    }

    public function findByIdForProbe(int $id, int $probeId): ?ProbeDamageWarning
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_damage_warnings WHERE id = :id AND probe_id = :probe_id');
        $stmt->execute(['id' => $id, 'probe_id' => $probeId]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?ProbeDamageWarning
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_damage_warnings WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    private function findByProbeMovementTypeAndObject(int $probeId, int $movementId, string $type, string $objectId): ?ProbeDamageWarning
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_damage_warnings
             WHERE probe_id = :probe_id AND movement_id = :movement_id AND type = :type AND object_id = :object_id
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'movement_id' => $movementId,
            'type' => $type,
            'object_id' => $objectId,
        ]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function markRead(ProbeDamageWarning $warning): ProbeDamageWarning
    {
        if ($warning->status === ProbeDamageWarning::STATUS_READ) {
            return $warning;
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE probe_damage_warnings
             SET status = 'read', read_at = :read_at, updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $warning->id,
            'read_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById($warning->id) ?? $warning;
    }

    public function markResolved(int $id): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_damage_warnings
             SET resolved_at = :resolved_at, updated_at = :updated_at
             WHERE id = :id AND resolved_at IS NULL'
        );
        $stmt->execute([
            'id' => $id,
            'resolved_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function hydrate(array $row): ProbeDamageWarning
    {
        return new ProbeDamageWarning(
            (int) $row['id'],
            (int) $row['probe_id'],
            (int) $row['movement_id'],
            (string) $row['type'],
            (string) $row['status'],
            (string) $row['phase'],
            (string) $row['scheduled_at'],
            (int) $row['sector_x'],
            (int) $row['sector_y'],
            (int) $row['sector_z'],
            (string) $row['container_id'],
            (string) $row['container_label'],
            (string) $row['object_id'],
            (float) $row['risk_percent'],
            (int) $row['additional_container_count'],
            (string) $row['message'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            $row['read_at'] !== null ? (string) $row['read_at'] : null,
            $row['resolved_at'] !== null ? (string) $row['resolved_at'] : null,
        );
    }
}
