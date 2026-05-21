<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

/**
 * Generates deterministic procedural seeds from sector coordinates.
 * 
 * Suitable for procedural generation and as a database key component.
 */
final class SectorSeedGenerator
{
    public function __construct(
        private readonly string $worldSeed,
    ) {}

    /**
     * Generate a deterministic seed string from coordinates.
     * 
     * Uses SHA256 for stability and collision-resistance.
     */
    public function generateSeed(SectorCoordinates $coordinates): string
    {
        $key = $coordinates->toKey();
        return hash('sha256', $this->worldSeed . ':' . $key);
    }

    /**
     * Generate a numeric seed (64-bit signed integer) from coordinates.
     * 
     * Useful for algorithms expecting integer seeds.
     */
    public function generateNumericSeed(SectorCoordinates $coordinates): int
    {
        $hash = $this->generateSeed($coordinates);
        $bytes = substr($hash, 0, 16); // Take first 16 hex chars (8 bytes)
        return (int) hexdec($bytes) & 0x7FFFFFFFFFFFFFFF; // Convert to positive int64
    }

    /**
     * Create a generator with a given world seed.
     */
    public static function forWorldSeed(string $worldSeed): self
    {
        return new self($worldSeed);
    }
}
