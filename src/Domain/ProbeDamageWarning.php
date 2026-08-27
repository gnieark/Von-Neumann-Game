<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeDamageWarning
{
    public const TYPE_STORAGE_CONTAINER_BREAK = 'storage_container_break';
    public const TYPE_INTELLIGENT_LIFE = 'intelligent_life';
    public const TYPE_SECTOR_OBJECT_DETECTED = 'sector_object_detected';
    public const TYPE_ANOMALY_DETECTED = 'anomaly_detected';
    public const TYPE_MANNY_REPORT = 'manny_report';
    public const TYPE_MIND_SNAPSHOT_TRANSFERRED = 'mind_snapshot_transferred';
    public const TYPE_PROBE_DESTROYED = 'probe_destroyed';
    public const TYPE_ASTEROID_TRAJECTORY = 'asteroid_trajectory';
    public const TYPE_BLUEPRINT_SHARED = 'blueprint_shared';
    public const TYPE_OTHERS_PRESENCE = 'others_presence';
    public const TYPE_OTHERS_WEAPON = 'others_weapon';
    public const TYPE_OTHERS_HARVEST_TRACES = 'others_harvest_traces';
    public const PHASE_WEAPON = 'weapon';
    public const PHASE_WEAPON_TARGETED = 'weapon_targeted';
    public const PHASE_WEAPON_RESULT = 'weapon_result';
    public const PHASE_WEAPON_DAMAGE = 'weapon_damage';
    public const STATUS_UNREAD = 'unread';
    public const STATUS_READ = 'read';

    public function __construct(
        public readonly int $id,
        public readonly int $probeId,
        public readonly ?int $movementId,
        public readonly string $type,
        public string $status,
        public readonly string $phase,
        public readonly string $scheduledAt,
        public readonly int $sectorX,
        public readonly int $sectorY,
        public readonly int $sectorZ,
        public readonly string $containerId,
        public readonly string $containerLabel,
        public readonly string $objectId,
        public readonly float $riskPercent,
        public readonly int $additionalContainerCount,
        public readonly string $message,
        public readonly ?string $illustrationImageUrl,
        public readonly string $createdAt,
        public string $updatedAt,
        public ?string $readAt = null,
        public ?string $resolvedAt = null,
    ) {}

    public function sectorArray(): array
    {
        return [
            'x' => $this->sectorX,
            'y' => $this->sectorY,
            'z' => $this->sectorZ,
        ];
    }
}
