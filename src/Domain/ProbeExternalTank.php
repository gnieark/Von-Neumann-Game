<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeExternalTank
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $name,
        public readonly float $fillPercent,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'fillPercent' => $this->fillPercent,
            'external' => true,
            'usesCargoCapacity' => false,
        ];
    }
}
