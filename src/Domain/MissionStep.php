<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class MissionStep
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $id,
        public readonly int $missionId,
        public readonly string $uid,
        public readonly int $sortOrder,
        public readonly string $title,
        public readonly ?string $description,
        public string $status,
        public array $metadata,
        public ?string $completedAt,
        public ?string $failedAt,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->uid,
            'sortOrder' => $this->sortOrder,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'completedAt' => $this->completedAt,
            'failedAt' => $this->failedAt,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
