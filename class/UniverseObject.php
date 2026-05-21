<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

abstract class UniverseObject
{
    public function __construct(
        private readonly string $id,
        private readonly ?string $name,
        private readonly UniverseObjectType $type,
        private readonly float $mass,
        private readonly float $radius,
        private readonly ?string $description = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getType(): UniverseObjectType
    {
        return $this->type;
    }

    public function getMass(): float
    {
        return $this->mass;
    }

    public function getRadius(): float
    {
        return $this->radius;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'mass' => $this->mass,
            'radius' => $this->radius,
            'description' => $this->description,
        ];
    }

    public static function fromArray(array $data): self
    {
        return UniverseObjectFactory::fromArray($data);
    }
}
