<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class Asteroid extends UniverseObject
{
    public function __construct(
        string $id,
        ?string $name,
        private readonly string $composition,
        private readonly array $estimatedResources,
        private readonly string $sizeCategory,
        float $mass,
        float $radius,
        ?string $description = null,
    ) {
        parent::__construct($id, $name, UniverseObjectType::Asteroid, $mass, $radius, $description);
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'composition' => $this->composition,
            'estimatedResources' => $this->estimatedResources,
            'sizeCategory' => $this->sizeCategory,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            (string) $data['composition'],
            $data['estimatedResources'] ?? [],
            (string) $data['sizeCategory'],
            (float) $data['mass'],
            (float) $data['radius'],
            $data['description'] ?? null,
        );
    }
}
