<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class SolarSystem extends UniverseObject
{
    /**
     * @param array<OrbitingBody> $orbitalBodies
     */
    public function __construct(
        string $id,
        ?string $name,
        private readonly Star $primaryStar,
        private readonly ?Star $secondaryStar,
        private readonly array $orbitalBodies,
        float $mass,
        float $radius,
        ?string $description = null,
    ) {
        parent::__construct($id, $name, UniverseObjectType::SolarSystem, $mass, $radius, $description);
    }

    public function getPrimaryStar(): Star
    {
        return $this->primaryStar;
    }

    public function getSecondaryStar(): ?Star
    {
        return $this->secondaryStar;
    }

    /**
     * @return array<Star>
     */
    public function getStars(): array
    {
        return $this->secondaryStar === null ? [$this->primaryStar] : [$this->primaryStar, $this->secondaryStar];
    }

    /**
     * @return array<OrbitingBody>
     */
    public function getOrbitalBodies(): array
    {
        return $this->orbitalBodies;
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'primaryStar' => $this->primaryStar->toArray(),
            'secondaryStar' => $this->secondaryStar?->toArray(),
            'orbitalBodies' => array_map(
                static fn(OrbitingBody $body): array => $body->toArray(),
                $this->orbitalBodies,
            ),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            Star::fromArray($data['primaryStar']),
            isset($data['secondaryStar']) && is_array($data['secondaryStar']) ? Star::fromArray($data['secondaryStar']) : null,
            array_map(static fn(array $body): OrbitingBody => OrbitingBody::fromArray($body), $data['orbitalBodies'] ?? []),
            (float) $data['mass'],
            (float) $data['radius'],
            $data['description'] ?? null,
        );
    }
}
