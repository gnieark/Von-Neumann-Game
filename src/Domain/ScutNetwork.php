<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ScutNetwork
{
    /**
     * @param array<array{x:int,y:int,z:int}> $coveredSectors
     */
    public function __construct(
        public readonly int $id,
        public string $name,
        public array $coveredSectors,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
