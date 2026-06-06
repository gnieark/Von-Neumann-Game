<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class SectorManny extends UniverseObject
{
    public const STATE_ABANDONED = 'abandoned';
    public const STATE_FORGOTTEN = 'forgotten';

    private readonly array $cargo;

    public static function objectIdForUid(string $uid): string
    {
        return 'manny-' . $uid;
    }

    public function __construct(
        string $id,
        ?string $name,
        private readonly string $mannyUid,
        private readonly string $state,
        array $cargo = [],
        ?string $description = null,
        array $waypointBookmarks = [],
    ) {
        parent::__construct($id, $name, UniverseObjectType::Manny, 0.0, 0.0, $description, $waypointBookmarks);
        $this->cargo = self::normalizeCargo($cargo);
    }

    public function getMannyUid(): string
    {
        return $this->mannyUid;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getCargo(): array
    {
        return $this->cargo;
    }

    public function withState(string $state, ?string $description = null): self
    {
        return new self(
            $this->getId(),
            $this->getName(),
            $this->mannyUid,
            $state,
            $this->cargo,
            $description ?? $this->getDescription(),
            $this->getWaypointBookmarks(),
        );
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'mannyUid' => $this->mannyUid,
            'state' => $this->state,
            'cargo' => $this->cargo,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            (string) ($data['mannyUid'] ?? ''),
            (string) ($data['state'] ?? self::STATE_ABANDONED),
            is_array($data['cargo'] ?? null) ? $data['cargo'] : [],
            $data['description'] ?? null,
            is_array($data['waypointBookmarks'] ?? null) ? $data['waypointBookmarks'] : [],
        );
    }

    private static function normalizeCargo(array $cargo): array
    {
        return [
            'capacity' => (float) ($cargo['capacity'] ?? 0.3),
            'deuterium' => (float) ($cargo['deuterium'] ?? 0.0),
            'metals' => (float) ($cargo['metals'] ?? 0.0),
            'ice' => (float) ($cargo['ice'] ?? 0.0),
            'organicCompounds' => (float) (
                $cargo['organicCompounds']
                ?? $cargo['organic_compounds']
                ?? $cargo['carbon_compounds']
                ?? $cargo['other']
                ?? 0.0
            ),
            'capacityUnit' => (string) ($cargo['capacityUnit'] ?? 'earth_container_equivalent'),
        ];
    }
}
