<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

/**
 * Represents a 3D coordinate in the FCC lattice.
 * 
 * Only coordinates where x + y + z is even are valid.
 * Instances are immutable.
 */
final class SectorCoordinates
{
    public function __construct(
        private readonly int $x,
        private readonly int $y,
        private readonly int $z,
    ) {
        if (($x + $y + $z) % 2 !== 0) {
            throw InvalidSectorCoordinatesException::invalidParity($x, $y, $z);
        }
    }

    public function getX(): int
    {
        return $this->x;
    }

    public function getY(): int
    {
        return $this->y;
    }

    public function getZ(): int
    {
        return $this->z;
    }

    /**
     * Check equality with another coordinate.
     */
    public function equals(self $other): bool
    {
        return $this->x === $other->x && $this->y === $other->y && $this->z === $other->z;
    }

    /**
     * Add integer offsets and return a new SectorCoordinates.
     * 
     * @throws InvalidSectorCoordinatesException If result coordinates are invalid.
     */
    public function add(int $dx, int $dy, int $dz): self
    {
        return new self($this->x + $dx, $this->y + $dy, $this->z + $dz);
    }

    /**
     * Subtract another coordinate and return the vector difference as an array.
     * Equivalent to [this.x - other.x, this.y - other.y, this.z - other.z]
     */
    public function subtract(self $other): array
    {
        return [
            'x' => $this->x - $other->x,
            'y' => $this->y - $other->y,
            'z' => $this->z - $other->z,
        ];
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'z' => $this->z];
    }

    /**
     * Convert to a stable key string for storage or hashing.
     * Format: "x:y:z"
     */
    public function toKey(): string
    {
        return "{$this->x}:{$this->y}:{$this->z}";
    }

    /**
     * Parse a key string back into coordinates.
     * 
     * @throws InvalidSectorCoordinatesException If key format is invalid or coordinates are invalid.
     */
    public static function fromKey(string $key): self
    {
        $parts = explode(':', $key);
        if (count($parts) !== 3) {
            throw InvalidSectorCoordinatesException::invalidKey($key);
        }

        $x = (int) $parts[0];
        $y = (int) $parts[1];
        $z = (int) $parts[2];

        return new self($x, $y, $z);
    }

    /**
     * Create the origin coordinate (0, 0, 0).
     */
    public static function origin(): self
    {
        return new self(0, 0, 0);
    }
}
