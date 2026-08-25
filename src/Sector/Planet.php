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
        private readonly bool $intelligentLife = false,
        ?string $description = null,
        array $waypointBookmarks = [],
        private readonly array $resourceAmounts = ['deuterium' => 0.0, 'metals' => 0.0, 'ice' => 0.0, 'carbon_compounds' => 0.0],
        private readonly bool $harvestedByOthers = false,
    ) {
        $unsupported = array_diff(array_keys($resourceAmounts), \VonNeumannGame\Domain\ResourceComposition::TYPES);
        if ($unsupported !== [] || array_diff(\VonNeumannGame\Domain\ResourceComposition::TYPES, array_keys($resourceAmounts)) !== []) {
            throw new \InvalidArgumentException('Planet resourceAmounts must contain exactly the four canonical resource types.');
        }
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

    public function hasIntelligentLife(): bool
    {
        return $this->intelligentLife;
    }

    public function hasAtmosphere(): bool { return $this->atmosphere; }
    public function getResourceHints(): array { return $this->resourceHints; }
    public function getResourceAmounts(): array { return array_map(static fn(mixed $value): float => round(max(0.0, (float) $value), 4), $this->resourceAmounts); }
    public function wasHarvestedByOthers(): bool { return $this->harvestedByOthers; }

    public function withResourceAmounts(array $amounts, ?bool $harvestedByOthers = null, ?bool $intelligentLife = null): self
    {
        return new self($this->getId(), $this->getName(), $this->category, $this->getMass(), $this->getRadius(), $this->atmosphere,
            $this->habitabilityScore, $this->resourceHints, $intelligentLife ?? $this->intelligentLife, $this->getDescription(),
            $this->getWaypointBookmarks(), $amounts, $harvestedByOthers ?? $this->harvestedByOthers);
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'category' => $this->category,
            'atmosphere' => $this->atmosphere,
            'habitabilityScore' => $this->habitabilityScore,
            'intelligentLife' => $this->intelligentLife,
            'resourceHints' => $this->resourceHints,
            'resourceAmounts' => $this->getResourceAmounts(),
            'harvestedByOthers' => $this->harvestedByOthers,
        ];
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['resourceAmounts']) || !is_array($data['resourceAmounts']) || !array_key_exists('harvestedByOthers', $data) || !is_bool($data['harvestedByOthers'])) {
            throw new \InvalidArgumentException('Planet is missing canonical resourceAmounts or harvestedByOthers; run the planet resource migration.');
        }
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            (string) $data['category'],
            (float) $data['mass'],
            (float) $data['radius'],
            (bool) $data['atmosphere'],
            (float) $data['habitabilityScore'],
            $data['resourceHints'] ?? [],
            (bool) ($data['intelligentLife'] ?? false),
            $data['description'] ?? null,
            is_array($data['waypointBookmarks'] ?? null) ? $data['waypointBookmarks'] : [],
            $data['resourceAmounts'],
            $data['harvestedByOthers'],
        );
    }
}
