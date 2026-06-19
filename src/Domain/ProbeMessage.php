<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Sector\SectorCoordinates;

final class ProbeMessage
{
    public const STATUS_UNREAD = 'unread';
    public const STATUS_READ = 'read';
    public const ENDPOINT_PROBE = 'probe';
    public const ENDPOINT_PLANET = 'planet';

    public function __construct(
        public readonly int $id,
        public readonly string $senderType,
        public readonly string $senderId,
        public readonly ?string $senderName,
        public readonly ?int $senderProbeId,
        public readonly string $recipientType,
        public readonly string $recipientId,
        public readonly ?string $recipientName,
        public readonly ?int $recipientProbeId,
        public readonly SectorCoordinates $sector,
        public readonly string $body,
        public string $status,
        public ?string $readAt,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}
}
