<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use VonNeumannGame\Sector\DeterministicRandom;

final class CaptureCalculator
{
    public function __construct(
        private readonly int $penaltyPercentPerStep = 10,
        private readonly float $minimumPlanetMassEarth = 3.0,
    ) {}

    public function chancePercent(int $penaltySteps): int
    {
        return max(0, 100 - ($this->penaltyPercentPerStep * max(0, $penaltySteps)));
    }

    /**
     * @param array<int, array{id: string, type: string, mass: float|int}> $bodies
     * @return array{id: string, type: string, mass: float}|null
     */
    public function resolve(string $trajectoryId, int $crossingNumber, string $sectorId, int $penaltySteps, array $bodies): ?array
    {
        $candidates = $this->eligibleCandidates($bodies);
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);
        foreach ($candidates as $candidate) {
            if ($candidate['type'] === 'black_hole') {
                return $candidate;
            }
        }

        $random = new DeterministicRandom('asteroid-capture:' . $trajectoryId . ':' . $crossingNumber . ':' . $sectorId);
        if (($random->nextFloat() * 100.0) >= $this->chancePercent($penaltySteps)) {
            return null;
        }

        $weights = [];
        foreach ($candidates as $index => $candidate) {
            $weights[(string) $index] = $candidate['mass'];
        }

        return $candidates[(int) $random->pickWeighted($weights)];
    }

    /** @return array<int, array{id: string, type: string, mass: float}> */
    private function eligibleCandidates(array $bodies): array
    {
        $eligible = [];
        foreach ($bodies as $body) {
            $type = (string) ($body['type'] ?? '');
            $mass = (float) ($body['mass'] ?? 0.0);
            if (!in_array($type, ['star', 'black_hole', 'planet'], true)) {
                continue;
            }
            if ($type === 'planet' && $mass < $this->minimumPlanetMassEarth) {
                continue;
            }
            if ($mass <= 0.0) {
                continue;
            }
            $eligible[] = ['id' => (string) $body['id'], 'type' => $type, 'mass' => $mass];
        }

        return $eligible;
    }
}
