<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Config\Config;

final class MovementDurationCalculator
{
    private const BETA_DURATION_FACTOR = 0.5;

    public function __construct(private readonly array $config = []) {}

    public function timeline(\DateTimeImmutable $startedAt, int $distance): array
    {
        if ($distance <= 0) {
            throw new \InvalidArgumentException('Movement distance must be greater than zero.');
        }

        $preparationEndsAt = $startedAt->modify('+' . $this->minutes($this->int('preparationMinutes', 10)) . ' minutes');
        $accelerationEndsAt = $preparationEndsAt->modify('+' . $this->minutes($this->int('accelerationMinutesPerDistance', 20) * $distance) . ' minutes');
        $cruiseEndsAt = $accelerationEndsAt->modify('+' . $this->minutes($this->int('cruiseMinutesPerDistance', 30) * $distance) . ' minutes');
        $decelerationEndsAt = $cruiseEndsAt->modify('+' . $this->minutes($this->int('decelerationMinutesPerDistance', 20) * $distance) . ' minutes');

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
        return max(1, (int) round($baseMinutes * $this->float('durationFactor', self::BETA_DURATION_FACTOR)));
    }

    private function int(string $path, int $default): int
    {
        return Config::int($this->config, $path, $default);
    }

    private function float(string $path, float $default): float
    {
        return Config::float($this->config, $path, $default);
    }
}
