<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Config\Config;

final class PlanetResourceStockCalculator
{
    private const TYPES = ResourceComposition::TYPES;
    private const DEFAULT_FRACTIONS = ['gas_giant' => 0.40, 'ice_giant' => 0.35, 'frozen' => 0.28, 'ocean' => 0.22, 'rocky' => 0.18, 'dwarf' => 0.16, 'lava' => 0.12];
    private const DEFAULT_COMPOSITION = [
        'gas_giant' => [0.78, 0.04, 0.04, 0.14], 'ice_giant' => [0.18, 0.10, 0.55, 0.17],
        'frozen' => [0.06, 0.22, 0.60, 0.12], 'ocean' => [0.03, 0.18, 0.62, 0.17],
        'rocky' => [0.01, 0.72, 0.07, 0.20], 'dwarf' => [0.02, 0.58, 0.32, 0.08],
        'lava' => [0.00, 0.92, 0.00, 0.08],
    ];

    public function __construct(private readonly array $config = []) {}

    /** @return array<string, float> */
    public function calculate(string $planetId, string $category, float $massEarth, float $radiusEarth, bool $atmosphere, float $habitabilityScore, array $resourceHints): array
    {
        if ($radiusEarth <= 0.0 || $massEarth <= 0.0 || !isset(self::DEFAULT_FRACTIONS[$category])) {
            throw new \InvalidArgumentException('Planet resource stock requires a supported category and positive mass and radius.');
        }
        $radiusCube = $radiusEarth ** 3;
        $bulkVolume = Config::float($this->config, 'planetResourceStocks.resourceContainersPerEarthVolume', 1_000_000.0) * $radiusCube;
        $relativeDensity = $massEarth / $radiusCube;
        $surfaceGravity = $massEarth / ($radiusEarth ** 2);
        $densityFactor = self::clamp($relativeDensity ** -0.15, 0.75, 1.25);
        $gravityFactor = self::clamp($surfaceGravity ** -0.10, 0.80, 1.15);
        $attributeFactor = ($atmosphere ? 1.03 : 0.95) * (1 + 0.05 * $habitabilityScore);
        $randomFactor = 0.85 + 0.30 * $this->uniform($planetId, 'total');
        $fractions = Config::getArray($this->config, 'planetResourceStocks.categoryRecoverableFractions', self::DEFAULT_FRACTIONS);
        $fraction = (float) ($fractions[$category] ?? self::DEFAULT_FRACTIONS[$category]);
        $total = round($bulkVolume * $fraction * $densityFactor * $gravityFactor * $attributeFactor * $randomFactor, 4);

        $configuredMatrix = Config::getArray($this->config, 'planetResourceStocks.compositionWeights', []);
        $base = is_array($configuredMatrix[$category] ?? null) ? $configuredMatrix[$category] : [];
        if ($base === []) {
            $base = array_combine(self::TYPES, self::DEFAULT_COMPOSITION[$category]);
        }
        $rareMetals = false;
        foreach ($resourceHints as $hint) { if (str_contains(strtolower((string) $hint), 'rare_metal') || str_contains(strtolower((string) $hint), 'rare metal')) { $rareMetals = true; } }
        $weights = [];
        foreach (self::TYPES as $type) {
            $weight = max(0.0, (float) ($base[$type] ?? 0.0));
            if ($type === ResourceComposition::ICE) { $weight *= 1 + 0.05 * $habitabilityScore; }
            if ($type === ResourceComposition::CARBON_COMPOUNDS) { $weight *= 1 + 0.25 * $habitabilityScore; }
            if ($type === ResourceComposition::METALS && $rareMetals) { $weight *= 1.10; }
            $weights[$type] = $weight * (0.90 + 0.20 * $this->uniform($planetId, $type));
        }
        $weightTotal = array_sum($weights);
        if ($weightTotal <= 0.0) { throw new \InvalidArgumentException('Planet resource composition must contain a positive weight.'); }
        $amounts = []; $remaining = $total;
        foreach (self::TYPES as $index => $type) {
            if ($index === count(self::TYPES) - 1) { $amounts[$type] = round(max(0.0, $remaining), 4); break; }
            $amounts[$type] = round($total * $weights[$type] / $weightTotal, 4);
            $remaining = round($remaining - $amounts[$type], 4);
        }
        return $amounts;
    }

    private function uniform(string $planetId, string $factor): float
    {
        $bytes = hash('sha256', 'planet-resource-stock:v1|' . $planetId . '|' . $factor, true);
        $value = unpack('N', substr($bytes, 0, 4));
        return ((int) $value[1]) / 4294967295;
    }

    private static function clamp(float $value, float $min, float $max): float { return max($min, min($max, $value)); }
}
