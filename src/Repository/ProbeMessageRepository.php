<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ProbeMessage;
use VonNeumannGame\Sector\SectorCoordinates;

final class ProbeMessageRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(int $senderProbeId, int $recipientProbeId, SectorCoordinates $sector, string $body): ProbeMessage
    {
        return $this->createForEndpoints(
            ProbeMessage::ENDPOINT_PROBE,
            (string) $senderProbeId,
            null,
            $senderProbeId,
            ProbeMessage::ENDPOINT_PROBE,
            (string) $recipientProbeId,
            null,
            $recipientProbeId,
            $sector,
            $body,
        );
    }

    public function createForEndpoints(
        string $senderType,
        string $senderId,
        ?string $senderName,
        ?int $senderProbeId,
        string $recipientType,
        string $recipientId,
        ?string $recipientName,
        ?int $recipientProbeId,
        SectorCoordinates $sector,
        string $body,
    ): ProbeMessage
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_messages
             (sender_type, sender_id, sender_name, sender_probe_id, recipient_type, recipient_id, recipient_name, recipient_probe_id, sector_x, sector_y, sector_z, body, status, read_at, created_at, updated_at)
             VALUES (:sender_type, :sender_id, :sender_name, :sender_probe_id, :recipient_type, :recipient_id, :recipient_name, :recipient_probe_id, :sector_x, :sector_y, :sector_z, :body, :status, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'sender_name' => $senderName,
            'sender_probe_id' => $senderProbeId,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'recipient_name' => $recipientName,
            'recipient_probe_id' => $recipientProbeId,
            'sector_x' => $sector->getX(),
            'sector_y' => $sector->getY(),
            'sector_z' => $sector->getZ(),
            'body' => $body,
            'status' => ProbeMessage::STATUS_UNREAD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Probe message creation failed.');
    }

    public function findById(int $id): ?ProbeMessage
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_messages WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return array<ProbeMessage>
     */
    public function receivedByProbe(int $probeId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_messages
             WHERE recipient_probe_id = :probe_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':probe_id', $probeId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $row): ProbeMessage => $this->hydrate($row), $stmt->fetchAll());
    }

    public function countReceivedByProbe(int $probeId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM probe_messages
             WHERE recipient_probe_id = :probe_id'
        );
        $stmt->execute(['probe_id' => $probeId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<ProbeMessage>
     */
    public function sentByProbe(int $probeId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_messages
             WHERE sender_probe_id = :probe_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':probe_id', $probeId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $row): ProbeMessage => $this->hydrate($row), $stmt->fetchAll());
    }

    public function countSentByProbe(int $probeId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM probe_messages
             WHERE sender_probe_id = :probe_id'
        );
        $stmt->execute(['probe_id' => $probeId]);

        return (int) $stmt->fetchColumn();
    }

    public function markRead(ProbeMessage $message): ProbeMessage
    {
        if ($message->status === ProbeMessage::STATUS_READ && $message->readAt !== null) {
            return $message;
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_messages
             SET status = :status, read_at = :read_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $message->id,
            'status' => ProbeMessage::STATUS_READ,
            'read_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById($message->id) ?? throw new \RuntimeException('Probe message update failed.');
    }

    private function hydrate(array $row): ProbeMessage
    {
        $senderProbeId = isset($row['sender_probe_id']) && $row['sender_probe_id'] !== null ? (int) $row['sender_probe_id'] : null;
        $recipientProbeId = isset($row['recipient_probe_id']) && $row['recipient_probe_id'] !== null ? (int) $row['recipient_probe_id'] : null;
        $senderType = (string) ($row['sender_type'] ?? ProbeMessage::ENDPOINT_PROBE);
        $recipientType = (string) ($row['recipient_type'] ?? ProbeMessage::ENDPOINT_PROBE);
        $senderId = (string) (($row['sender_id'] ?? null) ?: ($senderProbeId !== null ? (string) $senderProbeId : ''));
        $recipientId = (string) (($row['recipient_id'] ?? null) ?: ($recipientProbeId !== null ? (string) $recipientProbeId : ''));

        return new ProbeMessage(
            (int) $row['id'],
            $senderType,
            $senderId,
            isset($row['sender_name']) && $row['sender_name'] !== null ? (string) $row['sender_name'] : null,
            $senderProbeId,
            $recipientType,
            $recipientId,
            isset($row['recipient_name']) && $row['recipient_name'] !== null ? (string) $row['recipient_name'] : null,
            $recipientProbeId,
            new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']),
            (string) $row['body'],
            (string) $row['status'],
            $row['read_at'] !== null ? (string) $row['read_at'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
