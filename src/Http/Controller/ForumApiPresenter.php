<?php

declare(strict_types=1);

namespace VonNeumannGame\Http\Controller;

use VonNeumannGame\Forum\ForumCategory;
use VonNeumannGame\Forum\ForumMessage;
use VonNeumannGame\Forum\ForumPost;

final class ForumApiPresenter
{
    public function category(ForumCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'sortOrder' => $category->sortOrder,
            'createdAt' => $category->createdAt,
            'updatedAt' => $category->updatedAt,
        ];
    }

    public function post(ForumPost $post): array
    {
        return [
            'id' => $post->id,
            'categoryId' => $post->categoryId,
            'author' => $this->author($post->authorPlayerId, $post->authorUsername, $post->authorDisplayName),
            'title' => $post->title,
            'pinned' => $post->pinned,
            'firstMessageId' => $post->firstMessageId,
            'messageCount' => $post->messageCount,
            'createdAt' => $post->createdAt,
            'updatedAt' => $post->updatedAt,
            'lastMessageAt' => $post->lastMessageAt,
        ];
    }

    public function optionalMessage(?ForumMessage $message): ?array
    {
        return $message !== null ? $this->message($message) : null;
    }

    public function message(ForumMessage $message): array
    {
        return [
            'id' => $message->id,
            'postId' => $message->postId,
            'author' => $this->author($message->authorPlayerId, $message->authorUsername, $message->authorDisplayName),
            'body' => $message->body,
            'createdAt' => $message->createdAt,
            'updatedAt' => $message->updatedAt,
            'editedAt' => $message->editedAt,
        ];
    }

    public function pagination(int $limit, int $offset, int $count, int $total): array
    {
        return [
            'limit' => $limit,
            'offset' => $offset,
            'count' => $count,
            'total' => $total,
            'hasMore' => $offset + $count < $total,
        ];
    }

    private function author(int $id, string $username, ?string $displayName): array
    {
        return [
            'playerId' => $id,
            'username' => $username,
            'displayName' => $displayName,
        ];
    }
}
