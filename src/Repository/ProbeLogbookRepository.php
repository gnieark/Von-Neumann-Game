<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ProbeLogbookPage;

final class ProbeLogbookRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(int $probeId, string $title, string $content): ProbeLogbookPage
    {
        $now = gmdate('c');
        $sortOrder = $this->nextSortOrder($probeId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_logbook_pages
             (probe_id, title, content, sort_order, created_at, updated_at)
             VALUES (:probe_id, :title, :content, :sort_order, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'title' => $title,
            'content' => $content,
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByIdForProbe((int) $this->pdo->lastInsertId(), $probeId) ?? throw new \RuntimeException('Logbook page creation failed.');
    }

    public function findByIdForProbe(int $id, int $probeId): ?ProbeLogbookPage
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_logbook_pages WHERE id = :id AND probe_id = :probe_id');
        $stmt->execute([
            'id' => $id,
            'probe_id' => $probeId,
        ]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return list<ProbeLogbookPage>
     */
    public function listByProbe(int $probeId, int $limit = 10, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM probe_logbook_pages
             WHERE probe_id = :probe_id
             ORDER BY sort_order ASC, created_at ASC, id ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':probe_id', $probeId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $row): ProbeLogbookPage => $this->hydrate($row), $stmt->fetchAll());
    }

    public function countByProbe(int $probeId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM probe_logbook_pages WHERE probe_id = :probe_id');
        $stmt->execute(['probe_id' => $probeId]);

        return (int) $stmt->fetchColumn();
    }

    public function update(ProbeLogbookPage $page, ?string $title = null, ?string $content = null): ProbeLogbookPage
    {
        $page->title = $title ?? $page->title;
        $page->content = $content ?? $page->content;
        $page->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_logbook_pages
             SET title = :title, content = :content, updated_at = :updated_at
             WHERE id = :id AND probe_id = :probe_id'
        );
        $stmt->execute([
            'id' => $page->id,
            'probe_id' => $page->probeId,
            'title' => $page->title,
            'content' => $page->content,
            'updated_at' => $page->updatedAt,
        ]);

        return $this->findByIdForProbe($page->id, $page->probeId) ?? throw new \RuntimeException('Logbook page update failed.');
    }

    public function delete(ProbeLogbookPage $page): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM probe_logbook_pages WHERE id = :id AND probe_id = :probe_id');
        $stmt->execute([
            'id' => $page->id,
            'probe_id' => $page->probeId,
        ]);
    }

    private function nextSortOrder(int $probeId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM probe_logbook_pages WHERE probe_id = :probe_id');
        $stmt->execute(['probe_id' => $probeId]);

        return max(1, (int) $stmt->fetchColumn());
    }

    private function hydrate(array $row): ProbeLogbookPage
    {
        return new ProbeLogbookPage(
            (int) $row['id'],
            (int) $row['probe_id'],
            (string) $row['title'],
            (string) $row['content'],
            (int) $row['sort_order'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
