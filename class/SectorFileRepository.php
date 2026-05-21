<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class SectorFileRepository
{
    private const BUCKET_SIZE = 100;

    public function __construct(
        private readonly string $baseDirectory,
    ) {}

    public function getPath(SectorCoordinates $coordinates): string
    {
        $x = $this->formatCoordinate($coordinates->getX());
        $y = $this->formatCoordinate($coordinates->getY());
        $z = $this->formatCoordinate($coordinates->getZ());

        return rtrim($this->baseDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'sectors'
            . DIRECTORY_SEPARATOR . 'x_' . $this->formatCoordinate($this->bucket($coordinates->getX()))
            . DIRECTORY_SEPARATOR . 'y_' . $this->formatCoordinate($this->bucket($coordinates->getY()))
            . DIRECTORY_SEPARATOR . 'z_' . $this->formatCoordinate($this->bucket($coordinates->getZ()))
            . DIRECTORY_SEPARATOR . "sector_{$x}_{$y}_{$z}.json";
    }

    public function exists(SectorCoordinates $coordinates): bool
    {
        return is_file($this->getPath($coordinates));
    }

    public function load(SectorCoordinates $coordinates): SectorContent
    {
        $path = $this->getPath($coordinates);
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new SectorStorageException("Unable to read sector file '$path'");
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw SectorStorageException::invalidJson($path, $e->getMessage());
        }

        if (!is_array($data)) {
            throw SectorStorageException::invalidJson($path, 'root value must be an object');
        }

        return SectorContent::fromArray($data, 'loaded');
    }

    public function save(SectorContent $sector): void
    {
        $path = $this->getPath($sector->getCoordinates());
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw SectorStorageException::writeFailed($path);
        }

        $json = json_encode($sector->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));

        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw SectorStorageException::writeFailed($temporaryPath);
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw SectorStorageException::writeFailed($path);
        }
    }

    private function bucket(int $coordinate): int
    {
        return (int) floor($coordinate / self::BUCKET_SIZE);
    }

    private function formatCoordinate(int $coordinate): string
    {
        return ($coordinate < 0 ? 'n' . abs($coordinate) : 'p' . $coordinate);
    }
}
