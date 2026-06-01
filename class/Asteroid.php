<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

use VonNeumannGame\Domain\ResourceComposition;

final class Asteroid extends UniverseObject
{
    private const RESOURCE_CONTAINERS_PER_EARTH_MASS = 1000000.0;
    private const LEGACY_OTHER = 'other';

    private readonly array $resourceAmounts;

    public function __construct(
        string $id,
        ?string $name,
        private readonly string $composition,
        private readonly array $estimatedResources,
        private readonly string $sizeCategory,
        float $mass,
        float $radius,
        ?string $description = null,
        ?array $resourceAmounts = null,
    ) {
        parent::__construct($id, $name, UniverseObjectType::Asteroid, $mass, $radius, $description);
        $this->resourceAmounts = $resourceAmounts === null
            ? self::initialResourceAmounts($estimatedResources, $mass)
            : self::normalizeResourceAmounts($resourceAmounts);
    }

    /**
     * @return array<string, float>
     */
    public function getResourceAmounts(): array
    {
        return $this->resourceAmounts;
    }

    public function withResourceAmounts(array $resourceAmounts): self
    {
        return new self(
            $this->getId(),
            $this->getName(),
            $this->composition,
            $this->estimatedResources,
            $this->sizeCategory,
            $this->getMass(),
            $this->getRadius(),
            $this->getDescription(),
            $resourceAmounts,
        );
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'composition' => $this->composition,
            'estimatedResources' => $this->estimatedResources,
            'sizeCategory' => $this->sizeCategory,
            'resourceAmounts' => $this->resourceAmounts,
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
            isset($data['resourceAmounts']) && is_array($data['resourceAmounts'])
                ? $data['resourceAmounts']
                : null,
        );
    }

    /**
     * @param array<mixed> $estimatedResources
     * @return array<string, float>
     */
    private static function initialResourceAmounts(array $estimatedResources, float $mass): array
    {
        $composition = ResourceComposition::fromHints($estimatedResources);
        $amount = round(max(0.0, $mass) * self::RESOURCE_CONTAINERS_PER_EARTH_MASS, 4);
        $amounts = array_fill_keys(ResourceComposition::TYPES, 0.0);
        $remaining = $amount;
        $positiveTypes = array_values(array_filter(
            ResourceComposition::TYPES,
            static fn(string $type): bool => (float) ($composition[$type] ?? 0.0) > 0.0,
        ));
        $lastIndex = count($positiveTypes) - 1;

        foreach ($positiveTypes as $index => $type) {
            if ($index === $lastIndex) {
                $amounts[$type] = round(max(0.0, $remaining), 4);
                break;
            }

            $resourceAmount = round($amount * (float) ($composition[$type] ?? 0.0), 4);
            $amounts[$type] = $resourceAmount;
            $remaining = round($remaining - $resourceAmount, 4);
        }

        return $amounts;
    }

    /**
     * @param array<string, mixed> $amounts
     * @return array<string, float>
     */
    private static function normalizeResourceAmounts(array $amounts): array
    {
        if (isset($amounts[self::LEGACY_OTHER])) {
            $amounts[ResourceComposition::CARBON_COMPOUNDS] = (float) ($amounts[ResourceComposition::CARBON_COMPOUNDS] ?? 0.0)
                + (float) $amounts[self::LEGACY_OTHER];
        }

        $normalized = array_fill_keys(ResourceComposition::TYPES, 0.0);
        foreach (ResourceComposition::TYPES as $type) {
            $normalized[$type] = round(max(0.0, (float) ($amounts[$type] ?? 0.0)), 4);
        }

        return $normalized;
    }
}
