<?php

declare(strict_types=1);

namespace VonNeumannGame\Forum;

final class ForumPost
{
    public function __construct(
        public readonly int $id,
        public int $categoryId,
        public readonly int $authorPlayerId,
        public string $authorUsername,
        public ?string $authorDisplayName,
        public string $title,
        public bool $pinned,
        public int $messageCount,
        public readonly string $createdAt,
        public string $updatedAt,
        public string $lastMessageAt,
    ) {}
}
