<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

/** SQL projection. Never serialize this object into sector files. */
final class SectorGerminationDepot extends UniverseObject
{
    public function __construct(string $publicId)
    {
        parent::__construct($publicId, 'Structure dormante', UniverseObjectType::DormantConstruct, 0.0, 0.0);
    }

    public function toArray(): array
    {
        return ['id' => $this->getId(), 'name' => $this->getName(), 'type' => 'dormant_construct', 'inspectable' => true];
    }
}
