<?php

declare(strict_types=1);

namespace VonNeumannGame\Forum;

final class ForumCategory
{
    public function __construct(
        public readonly int $id,
        public string $name,
        public ?string $description,
        public int $sortOrder,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}
}
