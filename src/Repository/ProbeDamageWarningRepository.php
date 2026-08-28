<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ProbeDamageWarning;
use VonNeumannGame\Domain\ProbeImprovementCatalog;
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

    public function createOthersAlert(int $probeId, ?int $movementId, string $type, string $eventKey, SectorCoordinates $sector, string $message, string $phase = 'detection', ?string $scheduledAt = null): ProbeDamageWarning
    {
        if (!in_array($type, [ProbeDamageWarning::TYPE_OTHERS_PRESENCE, ProbeDamageWarning::TYPE_OTHERS_WEAPON, ProbeDamageWarning::TYPE_OTHERS_HARVEST_TRACES], true)) {
            throw new \InvalidArgumentException('Unsupported Others alert type.');
        }
        $existing = $this->findByProbeMovementTypeAndObject($probeId, $movementId, $type, $eventKey);
        if ($existing !== null) { return $existing; }
        $now = gmdate('c'); $scheduledAt ??= $now;
        $stmt = $this->pdo->prepare('INSERT INTO probe_damage_warnings (probe_id,movement_id,type,status,phase,scheduled_at,sector_x,sector_y,sector_z,container_id,container_label,object_id,risk_percent,additional_container_count,message,read_at,resolved_at,created_at,updated_at) VALUES (:probe_id,:movement_id,:type,:status,:phase,:scheduled_at,:x,:y,:z,\'\',\'\',:object_id,0,0,:message,NULL,NULL,:created_at,:updated_at)');
        $stmt->execute(['probe_id' => $probeId, 'movement_id' => $movementId, 'type' => $type, 'status' => ProbeDamageWarning::STATUS_UNREAD, 'phase' => $phase, 'scheduled_at' => $scheduledAt, 'x' => $sector->getX(), 'y' => $sector->getY(), 'z' => $sector->getZ(), 'object_id' => $eventKey, 'message' => $message, 'created_at' => $now, 'updated_at' => $now]);
        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Others alert creation failed.');
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
        ?int $movementId,
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
        ?string $scheduledAt = null,
        ?string $illustrationImageUrl = null,
    ): ProbeDamageWarning {
        $illustrationImageUrl = $this->normalizeIllustrationImageUrl($illustrationImageUrl);
        $now = gmdate('c');
        $scheduledAt = $scheduledAt !== null && trim($scheduledAt) !== '' ? $scheduledAt : $now;
        $existing = $this->findMannyReportAlert(
            $probeId,
            $scheduledAt,
            $sector,
            $objectId,
            $objectType,
            $objectLabel,
            $message,
        );
        if ($existing !== null) {
            return $existing;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_damage_warnings
             (probe_id, movement_id, type, status, phase, scheduled_at, sector_x, sector_y, sector_z, container_id, container_label, object_id, risk_percent, additional_container_count, message, illustration_image_url, read_at, resolved_at, created_at, updated_at)
             VALUES (:probe_id, :movement_id, :type, :status, :phase, :scheduled_at, :sector_x, :sector_y, :sector_z, :container_id, :container_label, :object_id, :risk_percent, :additional_container_count, :message, :illustration_image_url, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'movement_id' => null,
            'type' => ProbeDamageWarning::TYPE_MANNY_REPORT,
            'status' => ProbeDamageWarning::STATUS_UNREAD,
            'phase' => 'manny_report',
            'scheduled_at' => $scheduledAt,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'container_id' => $objectType,
            'container_label' => $objectLabel,
            'object_id' => $objectId,
            'risk_percent' => 0.0,
            'additional_container_count' => 0,
            'message' => $message,
            'illustration_image_url' => $illustrationImageUrl,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Manny report alert creation failed.');
    }

    public function createAsteroidTrajectoryAlert(
        int $probeId,
        SectorCoordinates $sector,
        string $trajectoryUid,
        string $asteroidId,
        string $message,
    ): ProbeDamageWarning {
        $existing = $this->findByProbeMovementTypeAndObject(
            $probeId,
            null,
            ProbeDamageWarning::TYPE_ASTEROID_TRAJECTORY,
            $trajectoryUid,
        );
        if ($existing !== null) {
            return $existing;
        }
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_damage_warnings
             (probe_id, movement_id, type, status, phase, scheduled_at, sector_x, sector_y, sector_z, container_id, container_label, object_id, risk_percent, additional_container_count, message, read_at, resolved_at, created_at, updated_at)
             VALUES (:probe_id, NULL, :type, :status, :phase, :scheduled_at, :sector_x, :sector_y, :sector_z, :container_id, :container_label, :object_id, 0, 0, :message, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'type' => ProbeDamageWarning::TYPE_ASTEROID_TRAJECTORY,
            'status' => ProbeDamageWarning::STATUS_UNREAD,
            'phase' => 'ignition',
            'scheduled_at' => $now,
            'sector_x' => $sector->getX(), 'sector_y' => $sector->getY(), 'sector_z' => $sector->getZ(),
            'container_id' => $asteroidId,
            'container_label' => 'Motorized asteroid',
            'object_id' => $trajectoryUid,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Asteroid trajectory alert creation failed.');
    }

    public function createAsteroidImpactAlert(
        int $probeId,
        SectorCoordinates $sector,
        string $eventKey,
        string $asteroidId,
        string $message,
        string $phase,
        string $scheduledAt,
    ): ProbeDamageWarning {
        $existing = $this->findByProbeMovementTypeAndObject(
            $probeId,
            null,
            ProbeDamageWarning::TYPE_ASTEROID_TRAJECTORY,
            $eventKey,
        );
        if ($existing !== null) {
            return $existing;
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_damage_warnings
             (probe_id, movement_id, type, status, phase, scheduled_at, sector_x, sector_y, sector_z, container_id, container_label, object_id, risk_percent, additional_container_count, message, read_at, resolved_at, created_at, updated_at)
             VALUES (:probe_id, NULL, :type, :status, :phase, :scheduled_at, :sector_x, :sector_y, :sector_z, :container_id, :container_label, :object_id, 0, 0, :message, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'type' => ProbeDamageWarning::TYPE_ASTEROID_TRAJECTORY,
            'status' => ProbeDamageWarning::STATUS_UNREAD,
            'phase' => $phase,
            'scheduled_at' => $scheduledAt,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'container_id' => $asteroidId,
            'container_label' => 'Motorized asteroid',
            'object_id' => $eventKey,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Asteroid impact alert creation failed.');
    }

    public function createBlueprintSharedAlert(
        int $probeId,
        SectorCoordinates $sector,
        string $improvementId,
        int $senderProbeId,
        string $senderProbeName,
        string $message,
    ): ProbeDamageWarning {
        $existing = $this->findBlueprintSharedAlert($probeId, $improvementId, $senderProbeId);
        if ($existing !== null) {
            return $existing;
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_damage_warnings
             (probe_id, movement_id, type, status, phase, scheduled_at, sector_x, sector_y, sector_z, container_id, container_label, object_id, risk_percent, additional_container_count, message, read_at, resolved_at, created_at, updated_at)
             VALUES (:probe_id, NULL, :type, :status, :phase, :scheduled_at, :sector_x, :sector_y, :sector_z, :container_id, :container_label, :object_id, 0, 0, :message, NULL, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'type' => ProbeDamageWarning::TYPE_BLUEPRINT_SHARED,
            'status' => ProbeDamageWarning::STATUS_UNREAD,
            'phase' => 'blueprint_share',
            'scheduled_at' => $now,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'container_id' => (string) $senderProbeId,
            'container_label' => $senderProbeName,
            'object_id' => ProbeImprovementCatalog::normalizeId($improvementId),
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Blueprint share alert creation failed.');
    }

    public function createMindSnapshotTransferredAlert(
        int $probeId,
        SectorCoordinates $sector,
        int $previousProbeId,
        string $reason,
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
            'movement_id' => null,
            'type' => ProbeDamageWarning::TYPE_MIND_SNAPSHOT_TRANSFERRED,
            'status' => ProbeDamageWarning::STATUS_UNREAD,
            'phase' => 'instance_switch',
            'scheduled_at' => $now,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'container_id' => $reason,
            'container_label' => 'Previous probe',
            'object_id' => (string) $previousProbeId,
            'risk_percent' => 0.0,
            'additional_container_count' => 0,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Mind snapshot transfer alert creation failed.');
    }

    public function createProbeDestroyedAlert(
        int $probeId,
        SectorCoordinates $sector,
        int $destroyedProbeId,
        string $reason,
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
            'movement_id' => null,
            'type' => ProbeDamageWarning::TYPE_PROBE_DESTROYED,
            'status' => ProbeDamageWarning::STATUS_UNREAD,
            'phase' => 'probe_loss',
            'scheduled_at' => $now,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'container_id' => $reason,
            'container_label' => 'Destroyed probe',
            'object_id' => (string) $destroyedProbeId,
            'risk_percent' => 0.0,
            'additional_container_count' => 0,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Probe destruction alert creation failed.');
    }

    /**
     * @return array<ProbeDamageWarning>
     */
    public function findByProbeId(int $probeId, bool $unreadOnly = false): array
    {
        $statusClause = $unreadOnly ? ' AND status = :status' : '';
        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_damage_warnings
             WHERE probe_id = :probe_id' . $statusClause . '
             ORDER BY created_at DESC, id DESC'
        );
        $parameters = ['probe_id' => $probeId];
        if ($unreadOnly) {
            $parameters['status'] = ProbeDamageWarning::STATUS_UNREAD;
        }
        $stmt->execute($parameters);

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

    private function findByProbeMovementTypeAndObject(int $probeId, ?int $movementId, string $type, string $objectId): ?ProbeDamageWarning
    {
        $movementCondition = $movementId === null ? 'movement_id IS NULL' : 'movement_id = :movement_id';
        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_damage_warnings
             WHERE probe_id = :probe_id AND ' . $movementCondition . ' AND type = :type AND object_id = :object_id
             ORDER BY id ASC
             LIMIT 1'
        );
        $params = [
            'probe_id' => $probeId,
            'type' => $type,
            'object_id' => $objectId,
        ];
        if ($movementId !== null) {
            $params['movement_id'] = $movementId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    private function findBlueprintSharedAlert(int $probeId, string $improvementId, int $senderProbeId): ?ProbeDamageWarning
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_damage_warnings
             WHERE probe_id = :probe_id
               AND type = :type
               AND object_id = :object_id
               AND container_id = :container_id
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'type' => ProbeDamageWarning::TYPE_BLUEPRINT_SHARED,
            'object_id' => ProbeImprovementCatalog::normalizeId($improvementId),
            'container_id' => (string) $senderProbeId,
        ]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    private function findMannyReportAlert(
        int $probeId,
        string $scheduledAt,
        SectorCoordinates $sector,
        string $objectId,
        string $objectType,
        string $objectLabel,
        string $message,
    ): ?ProbeDamageWarning {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_damage_warnings
             WHERE probe_id = :probe_id
               AND movement_id IS NULL
               AND type = :type
               AND phase = :phase
               AND scheduled_at = :scheduled_at
               AND sector_x = :sector_x
               AND sector_y = :sector_y
               AND sector_z = :sector_z
               AND container_id = :container_id
               AND container_label = :container_label
               AND object_id = :object_id
               AND message = :message
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'type' => ProbeDamageWarning::TYPE_MANNY_REPORT,
            'phase' => 'manny_report',
            'scheduled_at' => $scheduledAt,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'container_id' => $objectType,
            'container_label' => $objectLabel,
            'object_id' => $objectId,
            'message' => $message,
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

    public function setIllustrationImageUrl(ProbeDamageWarning $warning, ?string $illustrationImageUrl): ProbeDamageWarning
    {
        $illustrationImageUrl = $this->normalizeIllustrationImageUrl($illustrationImageUrl);
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_damage_warnings
             SET illustration_image_url = :illustration_image_url, updated_at = :updated_at
             WHERE id = :id AND probe_id = :probe_id'
        );
        $stmt->execute([
            'id' => $warning->id,
            'probe_id' => $warning->probeId,
            'illustration_image_url' => $illustrationImageUrl,
            'updated_at' => $now,
        ]);

        return $this->findById($warning->id) ?? throw new \RuntimeException('Alert illustration update failed.');
    }

    public function delete(ProbeDamageWarning $warning): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM probe_damage_warnings WHERE id = :id AND probe_id = :probe_id'
        );
        $stmt->execute([
            'id' => $warning->id,
            'probe_id' => $warning->probeId,
        ]);
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

    /**
     * @return array<int>
     */
    public function idsByMovementId(int $movementId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM probe_damage_warnings WHERE movement_id = :movement_id');
        $stmt->execute(['movement_id' => $movementId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function hydrate(array $row): ProbeDamageWarning
    {
        return new ProbeDamageWarning(
            (int) $row['id'],
            (int) $row['probe_id'],
            $row['movement_id'] !== null ? (int) $row['movement_id'] : null,
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
            $this->normalizeIllustrationImageUrl($row['illustration_image_url'] !== null ? (string) $row['illustration_image_url'] : null),
            (string) $row['created_at'],
            (string) $row['updated_at'],
            $row['read_at'] !== null ? (string) $row['read_at'] : null,
            $row['resolved_at'] !== null ? (string) $row['resolved_at'] : null,
        );
    }

    private function normalizeIllustrationImageUrl(?string $illustrationImageUrl): ?string
    {
        $illustrationImageUrl = trim((string) $illustrationImageUrl);
        if ($illustrationImageUrl === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($illustrationImageUrl, PHP_URL_SCHEME));
        if (
            strlen($illustrationImageUrl) > 2048
            || filter_var($illustrationImageUrl, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)
        ) {
            throw new \InvalidArgumentException('Alert illustration must be an absolute HTTP or HTTPS URL of at most 2048 bytes.');
        }

        return $illustrationImageUrl;
    }
}
