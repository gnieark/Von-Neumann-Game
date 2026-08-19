<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use VonNeumannGame\Sector\DeterministicRandom;

final class MaterialDistributionCalculator
{
    public function unrecoverableMassFraction(float $effectiveRatio): float
    {
        $ratio = max(0.0, $effectiveRatio);
        return 0.5 * $ratio / (1.0 + $ratio);
    }

    /**
     * Splits a recoverable total in fixed decimal units, assigning the rounding remainder deterministically.
     *
     * @return array<int, float>
     */
    public function distribute(float $recoverableTotal, int $partCount, DeterministicRandom $random, int $precision = 4): array
    {
        if ($partCount <= 0 || $precision < 0 || $precision > 8) {
            throw new \InvalidArgumentException('Distribution parameters are invalid.');
        }

        $scale = 10 ** $precision;
        $totalUnits = (int) round(max(0.0, $recoverableTotal) * $scale);
        $weights = [];
        $weightTotal = 0.0;
        for ($index = 0; $index < $partCount; $index++) {
            $weights[$index] = max(PHP_FLOAT_EPSILON, $random->nextFloat());
            $weightTotal += $weights[$index];
        }

        $units = array_fill(0, $partCount, 0);
        $fractions = [];
        $assigned = 0;
        foreach ($weights as $index => $weight) {
            $exact = $totalUnits * $weight / $weightTotal;
            $units[$index] = (int) floor($exact);
            $fractions[$index] = $exact - $units[$index];
            $assigned += $units[$index];
        }
        arsort($fractions, SORT_NUMERIC);
        foreach (array_keys($fractions) as $index) {
            if ($assigned >= $totalUnits) {
                break;
            }
            $units[$index]++;
            $assigned++;
        }

        return array_map(static fn(int $value): float => $value / $scale, $units);
    }
}
