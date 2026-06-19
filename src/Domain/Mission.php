<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class Mission
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ABANDONED = 'abandoned';

    public const STEP_ORDER_FREE = 'free';
    public const STEP_ORDER_SEQUENTIAL = 'sequential';

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $createdByEvent
     * @param array<MissionStep> $steps
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly int $probeId,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $description,
        public string $status,
        public readonly string $stepOrder,
        public array $metadata,
        public ?array $createdByEvent,
        public readonly string $startedAt,
        public ?string $completedAt,
        public ?string $failedAt,
        public ?string $abandonedAt,
        public readonly string $createdAt,
        public string $updatedAt,
        public array $steps = [],
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_ABANDONED], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->uid,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'stepOrder' => $this->stepOrder,
            'metadata' => $this->metadata,
            'createdByEvent' => $this->createdByEvent,
            'startedAt' => $this->startedAt,
            'completedAt' => $this->completedAt,
            'failedAt' => $this->failedAt,
            'abandonedAt' => $this->abandonedAt,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'steps' => array_map(static fn(MissionStep $step): array => $step->toArray(), $this->steps),
        ];
    }
}
