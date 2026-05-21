<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class OrbitingBody
{
    public function __construct(
        private readonly UniverseObject $object,
        private readonly OrbitDescriptor $orbit,
    ) {}

    public function getObject(): UniverseObject
    {
        return $this->object;
    }

    public function getOrbit(): OrbitDescriptor
    {
        return $this->orbit;
    }

    public function toArray(): array
    {
        return [
            'object' => $this->object->toArray(),
            'orbit' => $this->orbit->toArray(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            UniverseObject::fromArray($data['object']),
            OrbitDescriptor::fromArray($data['orbit']),
        );
    }
}
