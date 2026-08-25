<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VonNeumannGame\Domain\PlanetResourceStockCalculator;
use VonNeumannGame\Domain\ResourceComposition;

final class PlanetResourceStockMigration
{
    public function __construct(private readonly PlanetResourceStockCalculator $calculator) {}

    public function migrateDirectory(string $directory, bool $dryRun): array
    {
        $report = ['filesScanned' => 0, 'filesChanged' => 0, 'planetsChanged' => 0, 'resourceTotals' => array_fill_keys(ResourceComposition::TYPES, 0.0)];
        if (!is_dir($directory)) { return $report; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') { continue; }
            $report['filesScanned']++;
            $path = $file->getPathname();
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new \RuntimeException('Malformed sector root in ' . $path); }
            $changed = 0;
            $this->walk($data, $changed, $report['resourceTotals']);
            if ($changed === 0) { continue; }
            $report['filesChanged']++; $report['planetsChanged'] += $changed;
            if (!$dryRun) { $this->writeAtomically($path, $data); }
        }
        foreach ($report['resourceTotals'] as $type => $amount) { $report['resourceTotals'][$type] = round($amount, 4); }
        return $report;
    }

    private function walk(array &$node, int &$changed, array &$totals): void
    {
        if (($node['type'] ?? null) === 'planet') {
            $planetChanged = false;
            foreach (['id', 'category', 'mass', 'radius', 'atmosphere', 'habitabilityScore'] as $required) {
                if (!array_key_exists($required, $node)) { throw new \RuntimeException('Malformed planet missing ' . $required . '.'); }
            }
            if (isset($node['resourceAmounts'])) {
                if (!is_array($node['resourceAmounts']) || array_diff(array_keys($node['resourceAmounts']), ResourceComposition::TYPES) !== [] || array_diff(ResourceComposition::TYPES, array_keys($node['resourceAmounts'])) !== []) {
                    throw new \RuntimeException('Malformed canonical planet resourceAmounts.');
                }
                foreach ($node['resourceAmounts'] as $amount) { if (!is_numeric($amount) || (float) $amount < 0.0) { throw new \RuntimeException('Planet resource amount must be non-negative.'); } }
            } else {
                $node['resourceAmounts'] = $this->calculator->calculate((string) $node['id'], (string) $node['category'], (float) $node['mass'], (float) $node['radius'], (bool) $node['atmosphere'], (float) $node['habitabilityScore'], is_array($node['resourceHints'] ?? null) ? $node['resourceHints'] : []);
                foreach ($node['resourceAmounts'] as $type => $amount) { $totals[$type] += $amount; }
                $planetChanged = true;
            }
            if (array_key_exists('harvestedByOthers', $node)) {
                if (!is_bool($node['harvestedByOthers'])) { throw new \RuntimeException('Planet harvestedByOthers must be boolean.'); }
            } else {
                $node['harvestedByOthers'] = false;
                $planetChanged = true;
            }
            if ($planetChanged) { $changed++; }
        }
        foreach ($node as &$child) { if (is_array($child)) { $this->walk($child, $changed, $totals); } }
        unset($child);
    }

    private function writeAtomically(string $path, array $data): void
    {
        $temporary = tempnam(dirname($path), '.planet-stock-');
        if ($temporary === false) { throw new \RuntimeException('Unable to allocate a temporary sector file.'); }
        try {
            if (file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false || !rename($temporary, $path)) {
                throw new \RuntimeException('Unable to rewrite sector atomically: ' . $path);
            }
        } finally {
            if (is_file($temporary)) { unlink($temporary); }
        }
    }
}
