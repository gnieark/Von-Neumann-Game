<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

/**
 * Manages the FCC lattice structure and provides sector neighbors, distances, and queries.
 * 
 * A dodecahedral/FCC lattice with 12 neighbors per cell.
 */
final class SectorGrid
{
    /**
     * The 12 neighbor offsets for a dodecahedral/rhombic FCC lattice.
     * These are the face-diagonal offsets at distance 1.
     * Each offset sums to an even number (FCC parity constraint).
     */
    private const NEIGHBOR_OFFSETS = [
        [1, 1, 0],
        [1, -1, 0],
        [-1, 1, 0],
        [-1, -1, 0],
        [1, 0, 1],
        [1, 0, -1],
        [-1, 0, 1],
        [-1, 0, -1],
        [0, 1, 1],
        [0, 1, -1],
        [0, -1, 1],
        [0, -1, -1],
    ];

    /**
     * Get the raw neighbor offsets (for debugging or iteration).
     * 
     * @return array<array{int, int, int}>
     */
    public function getNeighborOffsets(): array
    {
        return self::NEIGHBOR_OFFSETS;
    }

    /**
     * Get all 12 direct neighbors of a sector.
     * 
     * @return array<SectorCoordinates>
     */
    public function getNeighbors(SectorCoordinates $coordinates): array
    {
        $neighbors = [];
        foreach (self::NEIGHBOR_OFFSETS as [$dx, $dy, $dz]) {
            try {
                $neighbors[] = $coordinates->add($dx, $dy, $dz);
            } catch (InvalidSectorCoordinatesException) {
                // This should never happen if offsets are correct
            }
        }
        return $neighbors;
    }

    /**
     * Calculate the Chebyshev distance (max coordinate distance) between two sectors.
     * 
     * For the FCC lattice with 12 face-diagonal neighbors, this represents
     * the minimum number of steps needed to move between sectors.
     */
    public function getDistance(SectorCoordinates $a, SectorCoordinates $b): int
    {
        $dx = abs($b->getX() - $a->getX());
        $dy = abs($b->getY() - $a->getY());
        $dz = abs($b->getZ() - $a->getZ());

        return max($dx, $dy, $dz);
    }

    /**
     * Get all sectors at exactly a given distance from center.
     * 
     * Warning: This can be computationally expensive for large distances.
     * The number of sectors grows quadratically with distance.
     * 
     * @return array<SectorCoordinates>
     */
    public function getSectorsAtDistance(SectorCoordinates $center, int $distance): array
    {
        if ($distance < 0) {
            return [];
        }

        if ($distance === 0) {
            return [$center];
        }

        $sectors = [];
        $x0 = $center->getX();
        $y0 = $center->getY();
        $z0 = $center->getZ();

        // Brute-force search within a bounding cube
        for ($x = $x0 - 2 * $distance; $x <= $x0 + 2 * $distance; $x++) {
            for ($y = $y0 - 2 * $distance; $y <= $y0 + 2 * $distance; $y++) {
                for ($z = $z0 - 2 * $distance; $z <= $z0 + 2 * $distance; $z++) {
                    if (($x + $y + $z) % 2 === 0) {
                        try {
                            $coord = new SectorCoordinates($x, $y, $z);
                            if ($this->getDistance($center, $coord) === $distance) {
                                $sectors[] = $coord;
                            }
                        } catch (InvalidSectorCoordinatesException) {
                            // Skip invalid coordinates
                        }
                    }
                }
            }
        }

        return $sectors;
    }

    /**
     * Get all sectors within a given distance from center (inclusive).
     * 
     * Warning: This can be computationally expensive for large distances.
     * 
     * @return array<SectorCoordinates>
     */
    public function getSectorsWithinDistance(SectorCoordinates $center, int $maxDistance): array
    {
        if ($maxDistance < 0) {
            return [];
        }

        $sectors = [];
        $x0 = $center->getX();
        $y0 = $center->getY();
        $z0 = $center->getZ();

        for ($x = $x0 - 2 * $maxDistance; $x <= $x0 + 2 * $maxDistance; $x++) {
            for ($y = $y0 - 2 * $maxDistance; $y <= $y0 + 2 * $maxDistance; $y++) {
                for ($z = $z0 - 2 * $maxDistance; $z <= $z0 + 2 * $maxDistance; $z++) {
                    if (($x + $y + $z) % 2 === 0) {
                        try {
                            $coord = new SectorCoordinates($x, $y, $z);
                            if ($this->getDistance($center, $coord) <= $maxDistance) {
                                $sectors[] = $coord;
                            }
                        } catch (InvalidSectorCoordinatesException) {
                            // Skip invalid coordinates
                        }
                    }
                }
            }
        }

        return $sectors;
    }
}
