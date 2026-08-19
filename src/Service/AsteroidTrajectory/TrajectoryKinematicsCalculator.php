<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

final class TrajectoryKinematicsCalculator
{
    public function __construct(
        private readonly float $minimumMassEarth,
        private readonly float $maximumMassEarth,
        private readonly float $maximumTargetSpeedC = 0.5,
        private readonly int $minimumDurationSeconds = 1,
        private readonly int $maximumDurationSeconds = 259200,
    ) {
        if ($minimumMassEarth < 0.0 || $maximumMassEarth <= $minimumMassEarth) {
            throw new \InvalidArgumentException('Asteroid mass bounds are invalid.');
        }
        if ($maximumTargetSpeedC <= 0.0 || $maximumTargetSpeedC >= 1.0) {
            throw new \InvalidArgumentException('Maximum target speed must be between zero and one c.');
        }
    }

    public function accelerationDurationSeconds(float $massEarth, float $targetSpeedC): int
    {
        $this->assertTargetSpeed($targetSpeedC);
        $massRatio = self::clamp(
            ($massEarth - $this->minimumMassEarth) / ($this->maximumMassEarth - $this->minimumMassEarth),
            0.0,
            1.0,
        );
        $durationAtHalfC = 7200.0 + (252000.0 * $massRatio);
        $seconds = (int) round($durationAtHalfC * ($targetSpeedC / 0.5));

        return max($this->minimumDurationSeconds, min($this->maximumDurationSeconds, $seconds));
    }

    public function currentSpeedC(float $targetSpeedC, int $elapsedSeconds, int $durationSeconds): float
    {
        $this->assertTargetSpeed($targetSpeedC);
        if ($durationSeconds <= 0) {
            throw new \InvalidArgumentException('Acceleration duration must be positive.');
        }

        $progress = self::clamp($elapsedSeconds / $durationSeconds, 0.0, 1.0);
        return $targetSpeedC * $progress;
    }

    private function assertTargetSpeed(float $targetSpeedC): void
    {
        if ($targetSpeedC <= 0.0 || $targetSpeedC > $this->maximumTargetSpeedC) {
            throw new \InvalidArgumentException('Target speed must be positive and no greater than the configured maximum.');
        }
    }

    private static function clamp(float $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, $value));
    }
}
