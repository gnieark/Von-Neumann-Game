<?php

declare(strict_types=1);

namespace VonNeumannGame\Forum;

final class ForumMessage
{
    public function __construct(
        public readonly int $id,
        public readonly int $postId,
        public readonly int $authorPlayerId,
        public string $authorUsername,
        public ?string $authorDisplayName,
        public string $body,
        public readonly string $createdAt,
        public string $updatedAt,
        public ?string $editedAt = null,
    ) {}
}
