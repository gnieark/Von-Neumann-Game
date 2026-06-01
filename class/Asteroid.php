<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class Asteroid extends UniverseObject
{
    private const RESOURCE_TYPES = ['deuterium', 'metals', 'other'];
    private const RESOURCE_CONTAINERS_PER_EARTH_MASS = 1000000.0;

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
        $composition = self::resourceCompositionFromHints($estimatedResources);
        $amount = round(max(0.0, $mass) * self::RESOURCE_CONTAINERS_PER_EARTH_MASS, 4);
        $amounts = array_fill_keys(self::RESOURCE_TYPES, 0.0);
        $remaining = $amount;
        $positiveTypes = array_values(array_filter(
            self::RESOURCE_TYPES,
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
     * @param array<mixed> $hints
     * @return array<string, float>
     */
    private static function resourceCompositionFromHints(array $hints): array
    {
        $counts = array_fill_keys(self::RESOURCE_TYPES, 0);
        foreach ($hints as $hint) {
            $counts[self::resourceTypeForHint((string) $hint)]++;
        }

        if (array_sum($counts) === 0) {
            $counts['other'] = 1;
        }

        $total = (float) array_sum($counts);
        $composition = [];
        foreach (self::RESOURCE_TYPES as $type) {
            $composition[$type] = round($counts[$type] / $total, 4);
        }

        return $composition;
    }

    private static function resourceTypeForHint(string $hint): string
    {
        $hint = strtolower($hint);

        if (
            str_contains($hint, 'water')
            || str_contains($hint, 'ice')
            || str_contains($hint, 'volatile')
            || str_contains($hint, 'hydrogen')
        ) {
            return 'deuterium';
        }

        if (
            str_contains($hint, 'iron')
            || str_contains($hint, 'nickel')
            || str_contains($hint, 'metal')
            || str_contains($hint, 'platinum')
            || str_contains($hint, 'magnesium')
        ) {
            return 'metals';
        }

        return 'other';
    }

    /**
     * @param array<string, mixed> $amounts
     * @return array<string, float>
     */
    private static function normalizeResourceAmounts(array $amounts): array
    {
        $normalized = array_fill_keys(self::RESOURCE_TYPES, 0.0);
        foreach (self::RESOURCE_TYPES as $type) {
            $normalized[$type] = round(max(0.0, (float) ($amounts[$type] ?? 0.0)), 4);
        }

        return $normalized;
    }
}
