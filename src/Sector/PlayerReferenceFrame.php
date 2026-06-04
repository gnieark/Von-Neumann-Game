<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

/**
 * Represents a player's or lineage's local reference frame.
 * 
 * Currently implements simple translation. Future extensions (rotation/permutation)
 * should be added via composition or subclassing without breaking this interface.
 */
final class PlayerReferenceFrame
{
    public function __construct(
        private readonly SectorCoordinates $globalOrigin,
    ) {}

    /**
     * Get the global origin of this reference frame.
     */
    public function getGlobalOrigin(): SectorCoordinates
    {
        return $this->globalOrigin;
    }

    /**
     * Convert global coordinates to relative coordinates.
     * 
     * Result is an array [x, y, z] where each value is the difference
     * from this frame's origin to the given global coordinates.
     * 
     * @return array{x: int, y: int, z: int}
     */
    public function globalToRelative(SectorCoordinates $globalCoords): array
    {
        return $globalCoords->subtract($this->globalOrigin);
    }

    /**
     * Convert relative coordinates to global coordinates.
     * 
     * @throws InvalidSectorCoordinatesException If resulting coordinates are invalid.
     */
    public function relativeToGlobal(int $relX, int $relY, int $relZ): SectorCoordinates
    {
        return $this->globalOrigin->add($relX, $relY, $relZ);
    }

    /**
     * Create a reference frame with origin at (0, 0, 0).
     */
    public static function atOrigin(): self
    {
        return new self(SectorCoordinates::origin());
    }

    /**
     * Create a reference frame with a specific global origin.
     */
    public static function atGlobalCoordinates(int $x, int $y, int $z): self
    {
        return new self(new SectorCoordinates($x, $y, $z));
    }
}
