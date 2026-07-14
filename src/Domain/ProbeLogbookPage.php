<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeLogbookPage
{
    public function __construct(
        public readonly int $id,
        public readonly int $probeId,
        public string $title,
        public string $content,
        public int $sortOrder,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}
}
