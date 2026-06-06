<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Sector\SectorCoordinates;

final class ProbeMessage
{
    public const STATUS_UNREAD = 'unread';
    public const STATUS_READ = 'read';

    public function __construct(
        public readonly int $id,
        public readonly int $senderProbeId,
        public readonly int $recipientProbeId,
        public readonly SectorCoordinates $sector,
        public readonly string $body,
        public string $status,
        public ?string $readAt,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}
}
