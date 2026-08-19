<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

final class SectorAsteroidFormatMigration
{
    /** @return array{filesScanned:int, filesChanged:int, asteroidsChanged:int} */
    public function migrate(string $universeDirectory, bool $dryRun = false): array
    {
        $sectorDirectory = rtrim($universeDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
        if (!is_dir($sectorDirectory)) {
            return ['filesScanned' => 0, 'filesChanged' => 0, 'asteroidsChanged' => 0];
        }

        $report = ['filesScanned' => 0, 'filesChanged' => 0, 'asteroidsChanged' => 0];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sectorDirectory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'json') {
                continue;
            }
            $path = $file->getPathname();
            $report['filesScanned']++;
            $json = file_get_contents($path);
            if ($json === false) {
                throw new \RuntimeException("Unable to read sector file '{$path}'.");
            }
            try {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new \RuntimeException("Invalid JSON in sector file '{$path}': {$error->getMessage()}", 0, $error);
            }
            if (!is_array($data)) {
                throw new \RuntimeException("Sector file '{$path}' does not contain a JSON object.");
            }

            $changed = $this->migrateValue($data);
            if ($changed === 0) {
                continue;
            }
            $report['filesChanged']++;
            $report['asteroidsChanged'] += $changed;
            if (!$dryRun) {
                $this->writeAtomically($path, $data);
            }
        }

        return $report;
    }

    private function migrateValue(array &$value): int
    {
        $changed = 0;
        if (($value['type'] ?? null) === 'asteroid') {
            if (($value['motorized'] ?? false) === true && !array_key_exists('motorFuelStatus', $value)) {
                $value['motorFuelStatus'] = 'full';
                $changed++;
            } elseif (($value['motorized'] ?? false) !== true && array_key_exists('motorFuelStatus', $value)) {
                unset($value['motorFuelStatus']);
                $changed++;
            }
        }
        foreach ($value as &$nested) {
            if (is_array($nested)) {
                $changed += $this->migrateValue($nested);
            }
        }
        unset($nested);

        return $changed;
    }

    /** @param array<string, mixed> $data */
    private function writeAtomically(string $path, array $data): void
    {
        $temporaryPath = $path . '.migration.' . bin2hex(random_bytes(6));
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                throw new \RuntimeException("Unable to write temporary migration file '{$temporaryPath}'.");
            }
            $permissions = fileperms($path);
            if ($permissions !== false) {
                @chmod($temporaryPath, $permissions & 0777);
            }
            if (!rename($temporaryPath, $path)) {
                throw new \RuntimeException("Unable to atomically replace sector file '{$path}'.");
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}
