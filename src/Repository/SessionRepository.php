<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\SessionToken;

final class SessionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function createSession(int $playerId, string $plainToken, string $expiresAt, bool $rememberMe = false): SessionToken
    {
        $now = gmdate('c');
        $tokenHash = self::hashToken($plainToken);
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (player_id, token_hash, created_at, expires_at, last_used_at, remember_me, revoked_at)
             VALUES (:player_id, :token_hash, :created_at, :expires_at, :last_used_at, :remember_me, NULL)'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'token_hash' => $tokenHash,
            'created_at' => $now,
            'expires_at' => $expiresAt,
            'last_used_at' => $now,
            'remember_me' => $rememberMe ? 1 : 0,
        ]);

        return new SessionToken((int) $this->pdo->lastInsertId(), $playerId, $tokenHash, $now, $expiresAt, $now, null, $rememberMe);
    }

    public function findValidSessionByToken(string $plainToken): ?SessionToken
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sessions WHERE token_hash = :token_hash AND revoked_at IS NULL AND expires_at > :now LIMIT 1'
        );
        $stmt->execute(['token_hash' => self::hashToken($plainToken), 'now' => gmdate('c')]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function revokeSession(string $plainToken): void
    {
        $stmt = $this->pdo->prepare('UPDATE sessions SET revoked_at = :now WHERE token_hash = :token_hash');
        $stmt->execute(['now' => gmdate('c'), 'token_hash' => self::hashToken($plainToken)]);
    }

    public function touchSession(SessionToken $session): void
    {
        $session->lastUsedAt = gmdate('c');
        $stmt = $this->pdo->prepare('UPDATE sessions SET last_used_at = :last_used_at WHERE id = :id');
        $stmt->execute(['id' => $session->id, 'last_used_at' => $session->lastUsedAt]);
    }

    public function extendSession(SessionToken $session, string $expiresAt): void
    {
        $now = gmdate('c');
        $session->expiresAt = $expiresAt;
        $session->lastUsedAt = $now;
        $stmt = $this->pdo->prepare(
            'UPDATE sessions SET expires_at = :expires_at, last_used_at = :last_used_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $session->id,
            'expires_at' => $expiresAt,
            'last_used_at' => $now,
        ]);
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function hydrate(array $row): SessionToken
    {
        return new SessionToken(
            (int) $row['id'],
            (int) $row['player_id'],
            (string) $row['token_hash'],
            (string) $row['created_at'],
            (string) $row['expires_at'],
            (string) $row['last_used_at'],
            $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            isset($row['remember_me']) && (bool) $row['remember_me'],
        );
    }
}
