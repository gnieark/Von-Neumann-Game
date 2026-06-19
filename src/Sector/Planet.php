<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class Planet extends UniverseObject
{
    public function __construct(
        string $id,
        ?string $name,
        private readonly string $category,
        float $mass,
        float $radius,
        private readonly bool $atmosphere,
        private readonly float $habitabilityScore,
        private readonly array $resourceHints = [],
        ?string $description = null,
        array $waypointBookmarks = [],
    ) {
        parent::__construct($id, $name, UniverseObjectType::Planet, $mass, $radius, $description, $waypointBookmarks);
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getHabitabilityScore(): float
    {
        return $this->habitabilityScore;
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'category' => $this->category,
            'atmosphere' => $this->atmosphere,
            'habitabilityScore' => $this->habitabilityScore,
            'resourceHints' => $this->resourceHints,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            (string) $data['category'],
            (float) $data['mass'],
            (float) $data['radius'],
            (bool) $data['atmosphere'],
            (float) $data['habitabilityScore'],
            $data['resourceHints'] ?? [],
            $data['description'] ?? null,
            is_array($data['waypointBookmarks'] ?? null) ? $data['waypointBookmarks'] : [],
        );
    }
}
