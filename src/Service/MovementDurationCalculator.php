<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

final class MovementDurationCalculator
{
    public function timeline(\DateTimeImmutable $startedAt, int $distance): array
    {
        if ($distance <= 0) {
            throw new \InvalidArgumentException('Movement distance must be greater than zero.');
        }

        $preparationEndsAt = $startedAt->modify('+10 minutes');
        $accelerationEndsAt = $preparationEndsAt->modify('+' . (20 * $distance) . ' minutes');
        $cruiseEndsAt = $accelerationEndsAt->modify('+' . (30 * $distance) . ' minutes');
        $decelerationEndsAt = $cruiseEndsAt->modify('+' . (20 * $distance) . ' minutes');

        return [
            'startedAt' => $startedAt,
            'preparationEndsAt' => $preparationEndsAt,
            'accelerationEndsAt' => $accelerationEndsAt,
            'cruiseEndsAt' => $cruiseEndsAt,
            'decelerationEndsAt' => $decelerationEndsAt,
            'arrivalAt' => $decelerationEndsAt,
        ];
    }
}
