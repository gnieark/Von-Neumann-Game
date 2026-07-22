<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

use VonNeumannGame\Domain\ResourceComposition;

final class Asteroid extends UniverseObject
{
    private const RESOURCE_CONTAINERS_PER_EARTH_MASS = 1000000.0;
    private const LEGACY_OTHER = 'other';
    private const NAME_PARTS = [
        ResourceComposition::ICE => 'Ice',
        ResourceComposition::DEUTERIUM => 'Deut',
        ResourceComposition::METALS => 'Metal',
        ResourceComposition::CARBON_COMPOUNDS => 'Carb',
    ];

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
        array $waypointBookmarks = [],
        ?float $resourceContainersPerEarthMass = null,
    ) {
        parent::__construct($id, $name, UniverseObjectType::Asteroid, $mass, $radius, $description, $waypointBookmarks);
        $this->resourceAmounts = $resourceAmounts === null
            ? self::initialResourceAmounts($estimatedResources, $mass, $resourceContainersPerEarthMass ?? self::RESOURCE_CONTAINERS_PER_EARTH_MASS)
            : self::normalizeResourceAmounts($resourceAmounts, $estimatedResources);
    }

    /**
     * @return array<string, float>
     */
    public function getResourceAmounts(): array
    {
        return $this->resourceAmounts;
    }

    public function withGeneratedName(string $seedMaterial): self
    {
        return new self(
            $this->getId(),
            self::generatedName($this->resourceAmounts, $seedMaterial),
            $this->composition,
            $this->estimatedResources,
            $this->sizeCategory,
            $this->getMass(),
            $this->getRadius(),
            $this->getDescription(),
            $this->resourceAmounts,
            $this->getWaypointBookmarks(),
        );
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
            $this->getWaypointBookmarks(),
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

    /**
     * @param array<string, float|int> $resourceAmounts
     */
    public static function generatedName(array $resourceAmounts, string $seedMaterial): string
    {
        $amounts = [];
        foreach (ResourceComposition::TYPES as $index => $type) {
            $amount = round(max(0.0, (float) ($resourceAmounts[$type] ?? 0.0)), 4);
            if ($amount <= 0.0) {
                continue;
            }
            $amounts[] = [
                'type' => $type,
                'amount' => $amount,
                'index' => $index,
            ];
        }

        usort($amounts, static function (array $left, array $right): int {
            $amountComparison = $right['amount'] <=> $left['amount'];
            return $amountComparison !== 0 ? $amountComparison : $left['index'] <=> $right['index'];
        });

        $parts = array_map(
            static fn(array $entry): string => self::NAME_PARTS[$entry['type']] ?? ucfirst((string) $entry['type']),
            $amounts,
        );
        if ($parts === []) {
            $parts[] = 'Inert';
        }

        $hash = substr(hash('sha256', 'asteroid-name:' . $seedMaterial), 0, 4);
        return implode(' ', [...$parts, $hash]);
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
            is_array($data['waypointBookmarks'] ?? null) ? $data['waypointBookmarks'] : [],
        );
    }

    /**
     * @param array<mixed> $estimatedResources
     * @return array<string, float>
     */
    private static function initialResourceAmounts(array $estimatedResources, float $mass, float $resourceContainersPerEarthMass): array
    {
        $composition = ResourceComposition::fromHints($estimatedResources);
        $amount = round(max(0.0, $mass) * max(0.0, $resourceContainersPerEarthMass), 4);
        return self::resourceAmountsForTotal($amount, $composition);
    }

    /**
     * @param array<string, float> $composition
     * @return array<string, float>
     */
    private static function resourceAmountsForTotal(float $amount, array $composition): array
    {
        $amounts = array_fill_keys(ResourceComposition::TYPES, 0.0);
        $remaining = round(max(0.0, $amount), 4);
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
     * @param array<mixed> $estimatedResources
     * @return array<string, float>
     */
    private static function normalizeResourceAmounts(array $amounts, array $estimatedResources): array
    {
        if (isset($amounts[self::LEGACY_OTHER])) {
            $total = 0.0;
            foreach (ResourceComposition::TYPES as $type) {
                $total += max(0.0, (float) ($amounts[$type] ?? 0.0));
            }
            $total += max(0.0, (float) $amounts[self::LEGACY_OTHER]);

            return self::resourceAmountsForTotal($total, ResourceComposition::fromHints($estimatedResources));
        }

        $normalized = array_fill_keys(ResourceComposition::TYPES, 0.0);
        foreach (ResourceComposition::TYPES as $type) {
            $normalized[$type] = round(max(0.0, (float) ($amounts[$type] ?? 0.0)), 4);
        }

        return $normalized;
    }
}
