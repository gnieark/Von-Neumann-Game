<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class SectorObservation
{
    public function __construct(
        public readonly array $relativeCoordinates,
        public readonly int $distance,
        public readonly SectorKnowledgeLevel $knowledgeLevel,
        public readonly float $confidence,
        public readonly array $payload,
        public readonly array $scan,
    ) {}

    public function toArray(): array
    {
        return [
            'relativeCoordinates' => $this->relativeCoordinates,
            'distance' => $this->distance,
            'knowledgeLevel' => $this->knowledgeLevel->value,
            'confidence' => $this->confidence,
        ] + $this->payload + [
            'scan' => $this->scan,
        ];
    }
}
