<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class Star extends UniverseObject
{
    public function __construct(
        string $id,
        ?string $name,
        private readonly string $spectralType,
        private readonly float $luminosity,
        private readonly int $temperature,
        float $mass,
        float $radius,
        ?string $description = null,
    ) {
        parent::__construct($id, $name, UniverseObjectType::Star, $mass, $radius, $description);
    }

    public function getSpectralType(): string
    {
        return $this->spectralType;
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'spectralType' => $this->spectralType,
            'luminosity' => $this->luminosity,
            'temperature' => $this->temperature,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            (string) $data['spectralType'],
            (float) $data['luminosity'],
            (int) $data['temperature'],
            (float) $data['mass'],
            (float) $data['radius'],
            $data['description'] ?? null,
        );
    }
}
