<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class DeterministicRandom
{
    private int $counter = 0;

    public function __construct(
        private readonly string $seed,
    ) {}

    public function nextFloat(): float
    {
        $hex = substr(hash('sha256', $this->seed . ':' . $this->counter++), 0, 12);
        return hexdec($hex) / 0xFFFFFFFFFFFF;
    }

    public function nextInt(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException('Minimum must be lower than or equal to maximum.');
        }

        return $min + (int) floor($this->nextFloat() * ($max - $min + 1));
    }

    public function nextFloatBetween(float $min, float $max): float
    {
        return $min + (($max - $min) * $this->nextFloat());
    }

    public function pickWeighted(array $weights): string
    {
        $total = 0.0;
        foreach ($weights as $weight) {
            if (!is_numeric($weight) || (float) $weight < 0.0) {
                throw new \InvalidArgumentException('Weights must be positive numbers.');
            }
            $total += (float) $weight;
        }

        if ($total <= 0.0) {
            throw new \InvalidArgumentException('At least one weight must be greater than zero.');
        }

        $roll = $this->nextFloat() * $total;
        $cursor = 0.0;
        foreach ($weights as $key => $weight) {
            $cursor += (float) $weight;
            if ($roll <= $cursor) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }
}
