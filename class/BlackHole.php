<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class BlackHole extends UniverseObject
{
    public function __construct(
        string $id,
        ?string $name,
        float $mass,
        private readonly float $schwarzschildRadius,
        private readonly bool $accretionDisk,
        private readonly float $dangerRadius,
        ?string $description = null,
        array $waypointBookmarks = [],
    ) {
        parent::__construct($id, $name, UniverseObjectType::BlackHole, $mass, $schwarzschildRadius, $description, $waypointBookmarks);
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'schwarzschildRadius' => $this->schwarzschildRadius,
            'accretionDisk' => $this->accretionDisk,
            'dangerRadius' => $this->dangerRadius,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            (float) $data['mass'],
            (float) $data['schwarzschildRadius'],
            (bool) $data['accretionDisk'],
            (float) $data['dangerRadius'],
            $data['description'] ?? null,
            is_array($data['waypointBookmarks'] ?? null) ? $data['waypointBookmarks'] : [],
        );
    }
}
