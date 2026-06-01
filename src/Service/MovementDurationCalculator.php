<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

final class MovementDurationCalculator
{
    private const BETA_DURATION_FACTOR = 0.5;

    public function timeline(\DateTimeImmutable $startedAt, int $distance): array
    {
        if ($distance <= 0) {
            throw new \InvalidArgumentException('Movement distance must be greater than zero.');
        }

        $preparationEndsAt = $startedAt->modify('+' . $this->minutes(10) . ' minutes');
        $accelerationEndsAt = $preparationEndsAt->modify('+' . $this->minutes(20 * $distance) . ' minutes');
        $cruiseEndsAt = $accelerationEndsAt->modify('+' . $this->minutes(30 * $distance) . ' minutes');
        $decelerationEndsAt = $cruiseEndsAt->modify('+' . $this->minutes(20 * $distance) . ' minutes');

        return [
            'startedAt' => $startedAt,
            'preparationEndsAt' => $preparationEndsAt,
            'accelerationEndsAt' => $accelerationEndsAt,
            'cruiseEndsAt' => $cruiseEndsAt,
            'decelerationEndsAt' => $decelerationEndsAt,
            'arrivalAt' => $decelerationEndsAt,
        ];
    }

    private function minutes(int $baseMinutes): int
    {
        return max(1, (int) round($baseMinutes * self::BETA_DURATION_FACTOR));
    }
}
