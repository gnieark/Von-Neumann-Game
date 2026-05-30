<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ScheduledEvent
{
    public function __construct(
        public readonly int $id,
        public string $type,
        public string $entityType,
        public int $entityId,
        public string $runAt,
        public string $status,
        public array $payload,
        public int $attempts,
        public ?string $lockedAt,
        public ?string $processedAt,
        public ?string $lastError,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}
}
