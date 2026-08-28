<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Sector\SectorCoordinates;

final class AsteroidTrajectory
{
    public const MODE_SYSTEM_IMPACT = 'system_impact';
    public const MODE_SECTOR_TRANSFER = 'sector_transfer';
    public const STATUS_ACCELERATING = 'accelerating';
    public const STATUS_COASTING = 'coasting';
    public const STATUS_CROSSING_SECTOR = 'crossing_sector';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_ORBITING_BLACK_HOLE = 'orbiting_black_hole';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_MISSED = 'missed';
    public const STATUS_NO_EFFECT = 'no_effect';
    public const STATUS_DESTROYED = 'destroyed';
    public const STATUS_LOST = 'lost';
    public const STATUS_FAILED = 'failed';

    public const ACTIVE_STATUSES = [
        self::STATUS_ACCELERATING,
        self::STATUS_COASTING,
        self::STATUS_CROSSING_SECTOR,
        self::STATUS_ORBITING_BLACK_HOLE,
    ];
    public const TERMINAL_STATUSES = [
        self::STATUS_CAPTURED,
        self::STATUS_COMPLETED,
        self::STATUS_MISSED,
        self::STATUS_NO_EFFECT,
        self::STATUS_DESTROYED,
        self::STATUS_LOST,
        self::STATUS_FAILED,
    ];

    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public string $asteroidId,
        public readonly string $mode,
        public string $status,
        public readonly SectorCoordinates $originSector,
        public SectorCoordinates $currentSector,
        public readonly ?array $direction,
        public readonly ?int $launcherProbeId,
        public readonly ?string $targetObjectId,
        public readonly ?int $targetProbeId,
        public readonly ?float $targetSpeedC,
        public readonly float $asteroidMassEarth,
        public readonly ?float $starMassSolar,
        public readonly ?string $accelerationStartedAt,
        public readonly ?string $accelerationEndsAt,
        public readonly ?int $revolutionDurationSeconds,
        public readonly ?int $plannedRevolutions,
        public string $nextTransitionAt,
        public int $sectorsCrossed,
        public int $capturePenaltySteps,
        public readonly int $maximumSectorCrossings,
        public ?string $result,
        public ?string $failureReason,
        public readonly array $asteroidSnapshot,
        public readonly array $attachmentsSnapshot,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
