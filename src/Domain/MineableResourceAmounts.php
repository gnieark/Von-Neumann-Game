<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

/** Four-decimal business quantities, stored as floats (not exact decimal arithmetic). */
final class MineableResourceAmounts
{
    public static function zero(): array { return array_fill_keys(ResourceComposition::TYPES, 0.0); }

    public static function normalize(array $amounts): array
    {
        if (array_diff(array_keys($amounts), ResourceComposition::TYPES) !== [] || array_diff(ResourceComposition::TYPES, array_keys($amounts)) !== []) {
            throw new \InvalidArgumentException('Mineable stock requires exactly the four canonical resources.');
        }
        $normalized = [];
        foreach (ResourceComposition::TYPES as $type) {
            $amount = $amounts[$type];
            if ((!is_int($amount) && !is_float($amount)) || !is_finite((float) $amount) || $amount < 0) {
                throw new \InvalidArgumentException('Mineable quantities must be finite nonnegative numbers.');
            }
            $normalized[$type] = round((float) $amount, 4);
        }
        return $normalized;
    }

    /** Verify an arithmetic result without concealing a lost 0.0001 in a tolerance. */
    public static function subtract(array $balance, array $amounts): array
    {
        $balance = self::normalize($balance);
        $amounts = self::normalize($amounts);
        foreach (ResourceComposition::TYPES as $type) {
            if ($amounts[$type] > $balance[$type]) { throw new \UnderflowException('Insufficient mineable stock.'); }
            self::requireArithmeticResolution($balance[$type], $amounts[$type]);
            $remaining = round($balance[$type] - $amounts[$type], 4);
            if (round($balance[$type] - $remaining, 4) !== $amounts[$type]) {
                throw new \OverflowException('Mineable stock cannot represent this debit at four-decimal precision.');
            }
            $balance[$type] = $remaining;
        }
        return $balance;
    }

    public static function add(array $balance, array $amounts): array
    {
        $balance = self::normalize($balance);
        $amounts = self::normalize($amounts);
        foreach (ResourceComposition::TYPES as $type) {
            $sum = round($balance[$type] + $amounts[$type], 4);
            self::requireArithmeticResolution($sum, $amounts[$type]);
            if (!is_finite($sum) || round($sum - $balance[$type], 4) !== $amounts[$type] || round($sum - $amounts[$type], 4) !== $balance[$type]) {
                throw new \OverflowException('Mineable stock cannot represent this credit at four-decimal precision.');
            }
            $balance[$type] = $sum;
        }
        return $balance;
    }

    private static function requireArithmeticResolution(float $balance, float $delta): void
    {
        if ($delta > 0.0 && $balance >= 549755813888.0) {
            throw new \OverflowException('Mineable arithmetic exceeds four-decimal float resolution (2^39); explicit numeric review is required.');
        }
    }
}
