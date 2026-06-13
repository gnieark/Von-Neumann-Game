<?php

declare(strict_types=1);

namespace VonNeumannGame\Forum;

use PDO;
use VonNeumannGame\Domain\Player;

final class ForumRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function createCategory(string $name, ?string $description = null, ?int $sortOrder = null): ForumCategory
    {
        $now = gmdate('c');
        $sortOrder ??= $this->nextCategorySortOrder();
        $stmt = $this->pdo->prepare(
            'INSERT INTO forum_categories (name, description, sort_order, created_at, updated_at)
             VALUES (:name, :description, :sort_order, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findCategoryById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Forum category creation failed.');
    }

    /**
     * @return array<ForumCategory>
     */
    public function categories(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM forum_categories ORDER BY sort_order ASC, id ASC');

        return array_map(fn(array $row): ForumCategory => $this->hydrateCategory($row), $stmt->fetchAll());
    }

    public function findCategoryById(int $id): ?ForumCategory
    {
        $stmt = $this->pdo->prepare('SELECT * FROM forum_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateCategory($row) : null;
    }

    public function updateCategory(ForumCategory $category): ForumCategory
    {
        $category->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE forum_categories
             SET name = :name, description = :description, sort_order = :sort_order, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'sort_order' => $category->sortOrder,
            'updated_at' => $category->updatedAt,
        ]);

        return $this->findCategoryById($category->id) ?? throw new \RuntimeException('Forum category update failed.');
    }

    public function deleteCategory(int $categoryId): void
    {
        $postIds = $this->postIdsForCategory($categoryId);
        if ($postIds !== []) {
            $placeholders = implode(',', array_fill(0, count($postIds), '?'));
            $stmt = $this->pdo->prepare('DELETE FROM forum_messages WHERE post_id IN (' . $placeholders . ')');
            $stmt->execute($postIds);
        }

        $stmt = $this->pdo->prepare('DELETE FROM forum_posts WHERE category_id = :category_id');
        $stmt->execute(['category_id' => $categoryId]);

        $stmt = $this->pdo->prepare('DELETE FROM forum_categories WHERE id = :id');
        $stmt->execute(['id' => $categoryId]);
    }

    public function createPost(Player $author, int $categoryId, string $title, string $body): ForumPost
    {
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO forum_posts (category_id, author_player_id, title, pinned, message_count, created_at, updated_at, last_message_at)
                 VALUES (:category_id, :author_player_id, :title, 0, 1, :created_at, :updated_at, :last_message_at)'
            );
            $stmt->execute([
                'category_id' => $categoryId,
                'author_player_id' => $author->id,
                'title' => $title,
                'created_at' => $now,
                'updated_at' => $now,
                'last_message_at' => $now,
            ]);
            $postId = (int) $this->pdo->lastInsertId();
            $this->insertMessage($postId, $author->id, $body, $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->findPostById($postId) ?? throw new \RuntimeException('Forum post creation failed.');
    }

    /**
     * @return array<ForumPost>
     */
    public function recentPosts(?int $categoryId = null, int $limit = 50, int $offset = 0): array
    {
        $where = $categoryId === null ? '' : 'WHERE fp.category_id = :category_id';
        $stmt = $this->pdo->prepare($this->postSelectSql() . " $where ORDER BY fp.pinned DESC, fp.last_message_at DESC, fp.id DESC LIMIT :limit OFFSET :offset");
        if ($categoryId !== null) {
            $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $row): ForumPost => $this->hydratePost($row), $stmt->fetchAll());
    }

    public function countPosts(?int $categoryId = null): int
    {
        if ($categoryId === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM forum_posts')->fetchColumn();
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM forum_posts WHERE category_id = :category_id');
        $stmt->execute(['category_id' => $categoryId]);

        return (int) $stmt->fetchColumn();
    }

    public function findPostById(int $id): ?ForumPost
    {
        $stmt = $this->pdo->prepare($this->postSelectSql() . ' WHERE fp.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydratePost($row) : null;
    }

    public function updatePost(ForumPost $post): ForumPost
    {
        $post->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE forum_posts
             SET category_id = :category_id, title = :title, pinned = :pinned, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $post->id,
            'category_id' => $post->categoryId,
            'title' => $post->title,
            'pinned' => $post->pinned ? 1 : 0,
            'updated_at' => $post->updatedAt,
        ]);

        return $this->findPostById($post->id) ?? throw new \RuntimeException('Forum post update failed.');
    }

    public function deletePost(int $postId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM forum_messages WHERE post_id = :post_id');
        $stmt->execute(['post_id' => $postId]);

        $stmt = $this->pdo->prepare('DELETE FROM forum_posts WHERE id = :id');
        $stmt->execute(['id' => $postId]);
    }

    public function createMessage(Player $author, int $postId, string $body): ForumMessage
    {
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $messageId = $this->insertMessage($postId, $author->id, $body, $now);
            $stmt = $this->pdo->prepare(
                'UPDATE forum_posts
                 SET message_count = message_count + 1, last_message_at = :last_message_at, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $postId,
                'last_message_at' => $now,
                'updated_at' => $now,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->findMessageById($messageId) ?? throw new \RuntimeException('Forum message creation failed.');
    }

    /**
     * @return array<ForumMessage>
     */
    public function recentMessagesForPost(int $postId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            $this->messageSelectSql() .
            ' WHERE fm.post_id = :post_id
              ORDER BY fm.created_at DESC, fm.id DESC
              LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':post_id', $postId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $row): ForumMessage => $this->hydrateMessage($row), $stmt->fetchAll());
    }

    public function countMessagesForPost(int $postId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM forum_messages WHERE post_id = :post_id');
        $stmt->execute(['post_id' => $postId]);

        return (int) $stmt->fetchColumn();
    }

    public function findMessageById(int $id): ?ForumMessage
    {
        $stmt = $this->pdo->prepare($this->messageSelectSql() . ' WHERE fm.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateMessage($row) : null;
    }

    public function updateMessage(ForumMessage $message): ForumMessage
    {
        $message->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE forum_messages
             SET body = :body, updated_at = :updated_at, edited_at = :edited_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $message->id,
            'body' => $message->body,
            'updated_at' => $message->updatedAt,
            'edited_at' => $message->updatedAt,
        ]);

        return $this->findMessageById($message->id) ?? throw new \RuntimeException('Forum message update failed.');
    }

    public function deleteMessage(ForumMessage $message): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM forum_messages WHERE id = :id');
        $stmt->execute(['id' => $message->id]);

        $this->syncPostMessageStats($message->postId);
    }

    private function nextCategorySortOrder(): int
    {
        $value = $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM forum_categories')->fetchColumn();

        return (int) $value;
    }

    /**
     * @return array<int>
     */
    private function postIdsForCategory(int $categoryId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM forum_posts WHERE category_id = :category_id');
        $stmt->execute(['category_id' => $categoryId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function insertMessage(int $postId, int $authorPlayerId, string $body, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO forum_messages (post_id, author_player_id, body, created_at, updated_at, edited_at)
             VALUES (:post_id, :author_player_id, :body, :created_at, :updated_at, NULL)'
        );
        $stmt->execute([
            'post_id' => $postId,
            'author_player_id' => $authorPlayerId,
            'body' => $body,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function syncPostMessageStats(int $postId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE forum_posts
             SET message_count = (SELECT COUNT(*) FROM forum_messages WHERE post_id = :post_id),
                 last_message_at = COALESCE((SELECT MAX(created_at) FROM forum_messages WHERE post_id = :post_id_last), created_at),
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'post_id' => $postId,
            'post_id_last' => $postId,
            'id' => $postId,
            'updated_at' => gmdate('c'),
        ]);
    }

    private function postSelectSql(): string
    {
        return 'SELECT fp.*, p.username AS author_username, p.display_name AS author_display_name
                FROM forum_posts fp
                INNER JOIN players p ON p.id = fp.author_player_id';
    }

    private function messageSelectSql(): string
    {
        return 'SELECT fm.*, p.username AS author_username, p.display_name AS author_display_name
                FROM forum_messages fm
                INNER JOIN players p ON p.id = fm.author_player_id';
    }

    private function hydrateCategory(array $row): ForumCategory
    {
        return new ForumCategory(
            (int) $row['id'],
            (string) $row['name'],
            $row['description'] !== null ? (string) $row['description'] : null,
            (int) $row['sort_order'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    private function hydratePost(array $row): ForumPost
    {
        return new ForumPost(
            (int) $row['id'],
            (int) $row['category_id'],
            (int) $row['author_player_id'],
            (string) $row['author_username'],
            $row['author_display_name'] !== null ? (string) $row['author_display_name'] : null,
            (string) $row['title'],
            (bool) $row['pinned'],
            (int) $row['message_count'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            (string) $row['last_message_at'],
        );
    }

    private function hydrateMessage(array $row): ForumMessage
    {
        return new ForumMessage(
            (int) $row['id'],
            (int) $row['post_id'],
            (int) $row['author_player_id'],
            (string) $row['author_username'],
            $row['author_display_name'] !== null ? (string) $row['author_display_name'] : null,
            (string) $row['body'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            $row['edited_at'] !== null ? (string) $row['edited_at'] : null,
        );
    }
}
