<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class DustCloud extends UniverseObject
{
    public function __construct(
        string $id,
        ?string $name,
        private readonly float $density,
        private readonly string $composition,
        private readonly float $dangerLevel,
        private readonly float $sensorInterference,
        float $mass,
        float $radius,
        ?string $description = null,
        array $waypointBookmarks = [],
    ) {
        parent::__construct($id, $name, UniverseObjectType::DustCloud, $mass, $radius, $description, $waypointBookmarks);
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'density' => $this->density,
            'composition' => $this->composition,
            'dangerLevel' => $this->dangerLevel,
            'sensorInterference' => $this->sensorInterference,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            (float) $data['density'],
            (string) $data['composition'],
            (float) $data['dangerLevel'],
            (float) $data['sensorInterference'],
            (float) $data['mass'],
            (float) $data['radius'],
            $data['description'] ?? null,
            is_array($data['waypointBookmarks'] ?? null) ? $data['waypointBookmarks'] : [],
        );
    }
}
