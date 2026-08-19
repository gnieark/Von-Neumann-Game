<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

final class RevolutionCalculator
{
    /** @param array<int, array{maximumStarMassSolar: float|int|null, durationSeconds: int}> $durationTable */
    public function __construct(
        private readonly array $durationTable,
        private readonly float $occultationWindowFraction = 0.15,
    ) {
        if ($durationTable === [] || $occultationWindowFraction < 0.0 || $occultationWindowFraction >= 1.0) {
            throw new \InvalidArgumentException('Revolution configuration is invalid.');
        }
    }

    public function durationSeconds(float $primaryStarMassSolar): int
    {
        foreach ($this->durationTable as $entry) {
            $maximum = $entry['maximumStarMassSolar'];
            if ($maximum === null || $primaryStarMassSolar <= (float) $maximum) {
                return max(1, (int) $entry['durationSeconds']);
            }
        }

        throw new \LogicException('Revolution duration table has no catch-all entry.');
    }

    public function plannedRevolutions(int $accelerationDurationSeconds, int $revolutionDurationSeconds): int
    {
        return max(1, (int) ceil(max(0, $accelerationDurationSeconds) / max(1, $revolutionDurationSeconds)));
    }

    public function completedRevolutions(int $elapsedSeconds, int $revolutionDurationSeconds, int $plannedRevolutions): int
    {
        return min(max(1, $plannedRevolutions), max(0, intdiv(max(0, $elapsedSeconds), max(1, $revolutionDurationSeconds))));
    }

    public function isOcculted(string $trajectoryId, int $elapsedSeconds, int $revolutionDurationSeconds): bool
    {
        $duration = max(1, $revolutionDurationSeconds);
        $phaseOffset = hexdec(substr(hash('sha256', 'occultation:' . $trajectoryId), 0, 12)) / 0xFFFFFFFFFFFF;
        $phase = fmod(($elapsedSeconds / $duration) + $phaseOffset, 1.0);
        if ($phase < 0.0) {
            $phase += 1.0;
        }

        return $phase < $this->occultationWindowFraction;
    }
}
