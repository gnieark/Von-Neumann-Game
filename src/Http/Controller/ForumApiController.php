<?php

declare(strict_types=1);

namespace VonNeumannGame\Http\Controller;

use VonNeumannGame\Domain\Player;
use VonNeumannGame\Forum\ForumMessage;
use VonNeumannGame\Forum\ForumPost;
use VonNeumannGame\Forum\ForumRepository;
use VonNeumannGame\Http\ApiResponse;

final class ForumApiController
{
    private readonly ForumApiPresenter $presenter;

    public function __construct(private readonly ForumRepository $forum, ?ForumApiPresenter $presenter = null)
    {
        $this->presenter = $presenter ?? new ForumApiPresenter();
    }

    public function categories(): ApiResponse
    {
        return new ApiResponse(200, [
            'categories' => array_map(
                fn($category): array => $this->presenter->category($category),
                $this->forum->categories(),
            ),
        ]);
    }

    public function category(int $categoryId): ApiResponse
    {
        $category = $this->forum->findCategoryById($categoryId);
        if ($category === null) {
            return ApiResponse::error(404, 'not_found', 'Forum category not found.');
        }

        return new ApiResponse(200, ['category' => $this->presenter->category($category)]);
    }

    public function createCategory(Player $player, ?string $body): ApiResponse
    {
        if (!$player->forumAdmin) {
            return ApiResponse::error(403, 'forbidden', 'Forum administrator permission is required.');
        }

        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a forum category.');
        }

        $name = $this->textField($data['name'] ?? null, 'name', 1, 120);
        if ($name instanceof ApiResponse) {
            return $name;
        }
        $description = $this->optionalTextField($data['description'] ?? null, 'description', 1000);
        if ($description instanceof ApiResponse) {
            return $description;
        }
        $sortOrder = $this->optionalIntegerField($data['sortOrder'] ?? null, 'sortOrder');
        if ($sortOrder instanceof ApiResponse) {
            return $sortOrder;
        }

        return new ApiResponse(201, [
            'category' => $this->presenter->category($this->forum->createCategory($name, $description, $sortOrder)),
        ]);
    }

    public function updateCategory(Player $player, int $categoryId, ?string $body): ApiResponse
    {
        if (!$player->forumAdmin) {
            return ApiResponse::error(403, 'forbidden', 'Forum administrator permission is required.');
        }

        $category = $this->forum->findCategoryById($categoryId);
        if ($category === null) {
            return ApiResponse::error(404, 'not_found', 'Forum category not found.');
        }

        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain forum category fields.');
        }
        if (array_key_exists('name', $data)) {
            $name = $this->textField($data['name'], 'name', 1, 120);
            if ($name instanceof ApiResponse) {
                return $name;
            }
            $category->name = $name;
        }
        if (array_key_exists('description', $data)) {
            $description = $this->optionalTextField($data['description'], 'description', 1000);
            if ($description instanceof ApiResponse) {
                return $description;
            }
            $category->description = $description;
        }
        if (array_key_exists('sortOrder', $data)) {
            $sortOrder = $this->optionalIntegerField($data['sortOrder'], 'sortOrder');
            if ($sortOrder instanceof ApiResponse) {
                return $sortOrder;
            }
            $category->sortOrder = $sortOrder ?? $category->sortOrder;
        }

        return new ApiResponse(200, ['category' => $this->presenter->category($this->forum->updateCategory($category))]);
    }

    public function deleteCategory(Player $player, int $categoryId): ApiResponse
    {
        if (!$player->forumAdmin) {
            return ApiResponse::error(403, 'forbidden', 'Forum administrator permission is required.');
        }
        if ($this->forum->findCategoryById($categoryId) === null) {
            return ApiResponse::error(404, 'not_found', 'Forum category not found.');
        }

        $this->forum->deleteCategory($categoryId);

        return new ApiResponse(200, ['deleted' => true]);
    }

    public function posts(array $query): ApiResponse
    {
        $categoryId = $this->optionalPositiveIntegerQuery($query, 'categoryId');
        if ($categoryId instanceof ApiResponse) {
            return $categoryId;
        }
        if ($categoryId !== null && $this->forum->findCategoryById($categoryId) === null) {
            return ApiResponse::error(404, 'not_found', 'Forum category not found.');
        }

        $limit = $this->paginationParameter($query, 'limit', 50, 1, 200);
        if ($limit instanceof ApiResponse) {
            return $limit;
        }
        $offset = $this->paginationParameter($query, 'offset', 0, 0);
        if ($offset instanceof ApiResponse) {
            return $offset;
        }

        $posts = $this->forum->recentPosts($categoryId, $limit, $offset);
        $total = $this->forum->countPosts($categoryId);

        return new ApiResponse(200, [
            'posts' => array_map(fn(ForumPost $post): array => $this->presenter->post($post), $posts),
            'pagination' => $this->presenter->pagination($limit, $offset, count($posts), $total),
        ]);
    }

    public function post(int $postId, array $query): ApiResponse
    {
        $post = $this->forum->findPostById($postId);
        if ($post === null) {
            return ApiResponse::error(404, 'not_found', 'Forum post not found.');
        }

        $messages = $this->messagesPage($postId, $query);
        if ($messages instanceof ApiResponse) {
            return $messages;
        }

        return new ApiResponse(200, [
            'post' => $this->presenter->post($post),
            'firstMessage' => $this->presenter->optionalMessage($this->forum->firstMessageForPost($post)),
            'messages' => array_map(fn(ForumMessage $message): array => $this->presenter->message($message), $messages['items']),
            'pagination' => $messages['pagination'],
        ]);
    }

    public function createPost(Player $player, ?string $body): ApiResponse
    {
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a forum post.');
        }

        $categoryId = $this->positiveIntegerField($data['categoryId'] ?? null, 'categoryId');
        if ($categoryId instanceof ApiResponse) {
            return $categoryId;
        }
        if ($this->forum->findCategoryById($categoryId) === null) {
            return ApiResponse::error(404, 'not_found', 'Forum category not found.');
        }

        $title = $this->textField($data['title'] ?? null, 'title', 1, 160);
        if ($title instanceof ApiResponse) {
            return $title;
        }
        $messageBody = $this->textField($data['body'] ?? null, 'body', 1, 5000);
        if ($messageBody instanceof ApiResponse) {
            return $messageBody;
        }

        return new ApiResponse(201, $this->postPayload($this->forum->createPost($player, $categoryId, $title, $messageBody)));
    }

    public function updatePost(Player $player, int $postId, ?string $body): ApiResponse
    {
        if (!$this->canModerate($player)) {
            return ApiResponse::error(403, 'forbidden', 'Forum moderator permission is required.');
        }

        $post = $this->forum->findPostById($postId);
        if ($post === null) {
            return ApiResponse::error(404, 'not_found', 'Forum post not found.');
        }

        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain forum post fields.');
        }
        if (array_key_exists('title', $data)) {
            $title = $this->textField($data['title'], 'title', 1, 160);
            if ($title instanceof ApiResponse) {
                return $title;
            }
            $post->title = $title;
        }
        if (array_key_exists('categoryId', $data)) {
            $categoryId = $this->positiveIntegerField($data['categoryId'], 'categoryId');
            if ($categoryId instanceof ApiResponse) {
                return $categoryId;
            }
            if ($this->forum->findCategoryById($categoryId) === null) {
                return ApiResponse::error(404, 'not_found', 'Forum category not found.');
            }
            $post->categoryId = $categoryId;
        }
        if (array_key_exists('pinned', $data)) {
            if (!is_bool($data['pinned'])) {
                return ApiResponse::error(400, 'bad_request', 'Forum post pinned must be a boolean.');
            }
            $post->pinned = $data['pinned'];
        }

        return new ApiResponse(200, $this->postPayload($this->forum->updatePost($post)));
    }

    public function deletePost(Player $player, int $postId): ApiResponse
    {
        if (!$this->canModerate($player)) {
            return ApiResponse::error(403, 'forbidden', 'Forum moderator permission is required.');
        }
        if ($this->forum->findPostById($postId) === null) {
            return ApiResponse::error(404, 'not_found', 'Forum post not found.');
        }

        $this->forum->deletePost($postId);

        return new ApiResponse(200, ['deleted' => true]);
    }

    public function postMessages(int $postId, array $query): ApiResponse
    {
        if ($this->forum->findPostById($postId) === null) {
            return ApiResponse::error(404, 'not_found', 'Forum post not found.');
        }

        $messages = $this->messagesPage($postId, $query);
        if ($messages instanceof ApiResponse) {
            return $messages;
        }

        return new ApiResponse(200, [
            'messages' => array_map(fn(ForumMessage $message): array => $this->presenter->message($message), $messages['items']),
            'pagination' => $messages['pagination'],
        ]);
    }

    public function createMessage(Player $player, int $postId, ?string $body): ApiResponse
    {
        if ($this->forum->findPostById($postId) === null) {
            return ApiResponse::error(404, 'not_found', 'Forum post not found.');
        }
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a forum message.');
        }

        $messageBody = $this->textField($data['body'] ?? null, 'body', 1, 5000);
        if ($messageBody instanceof ApiResponse) {
            return $messageBody;
        }

        return new ApiResponse(201, ['message' => $this->presenter->message($this->forum->createMessage($player, $postId, $messageBody))]);
    }

    public function updateMessage(Player $player, int $messageId, ?string $body): ApiResponse
    {
        $message = $this->forum->findMessageById($messageId);
        if ($message === null) {
            return ApiResponse::error(404, 'not_found', 'Forum message not found.');
        }
        if (!$this->canModerate($player) && $message->authorPlayerId !== $player->id) {
            return ApiResponse::error(403, 'forbidden', 'Forum moderator permission or message authorship is required.');
        }
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain forum message fields.');
        }
        $messageBody = $this->textField($data['body'] ?? null, 'body', 1, 5000);
        if ($messageBody instanceof ApiResponse) {
            return $messageBody;
        }
        $message->body = $messageBody;

        return new ApiResponse(200, ['message' => $this->presenter->message($this->forum->updateMessage($message))]);
    }

    public function deleteMessage(Player $player, int $messageId): ApiResponse
    {
        if (!$this->canModerate($player)) {
            return ApiResponse::error(403, 'forbidden', 'Forum moderator permission is required.');
        }
        $message = $this->forum->findMessageById($messageId);
        if ($message === null) {
            return ApiResponse::error(404, 'not_found', 'Forum message not found.');
        }

        $this->forum->deleteMessage($message);

        return new ApiResponse(200, ['deleted' => true]);
    }

    private function postPayload(ForumPost $post): array
    {
        return [
            'post' => $this->presenter->post($post),
            'firstMessage' => $this->presenter->optionalMessage($this->forum->firstMessageForPost($post)),
        ];
    }

    /**
     * @return array{items: array<ForumMessage>, pagination: array<string, int|bool>}|ApiResponse
     */
    private function messagesPage(int $postId, array $query): array|ApiResponse
    {
        $limit = $this->paginationParameter($query, 'limit', 50, 1, 200);
        if ($limit instanceof ApiResponse) {
            return $limit;
        }
        $offset = $this->paginationParameter($query, 'offset', 0, 0);
        if ($offset instanceof ApiResponse) {
            return $offset;
        }

        $messages = $this->forum->recentMessagesForPost($postId, $limit, $offset);
        $total = $this->forum->countMessagesForPost($postId);

        return [
            'items' => $messages,
            'pagination' => $this->presenter->pagination($limit, $offset, count($messages), $total),
        ];
    }

    private function canModerate(Player $player): bool
    {
        return $player->forumAdmin || $player->forumModerator;
    }

    private function paginationParameter(array $query, string $name, int $default, int $min, ?int $max = null): int|ApiResponse
    {
        $value = $query[$name] ?? $default;
        if (is_array($value)) {
            return $this->paginationError($name, $min, $max);
        }
        if (!is_numeric($value)) {
            return $this->paginationError($name, $min, $max);
        }

        $integer = (int) $value;
        if ((string) $integer !== (string) $value && !is_int($value)) {
            return $this->paginationError($name, $min, $max);
        }
        if ($integer < $min || ($max !== null && $integer > $max)) {
            return $this->paginationError($name, $min, $max);
        }

        return $integer;
    }

    private function paginationError(string $name, int $min, ?int $max): ApiResponse
    {
        $range = $max === null ? sprintf('at least %d', $min) : sprintf('between %d and %d', $min, $max);

        return ApiResponse::error(400, 'bad_request', sprintf('Query parameter %s must be an integer %s.', $name, $range));
    }

    private function textField(mixed $value, string $name, int $minLength, int $maxLength): string|ApiResponse
    {
        if (!is_string($value)) {
            return ApiResponse::error(400, 'bad_request', sprintf('Forum %s must be a string.', $name));
        }

        $trimmed = trim($value);
        $length = strlen($trimmed);
        if ($length < $minLength || $length > $maxLength) {
            return ApiResponse::error(400, 'bad_request', sprintf('Forum %s must contain %d to %d characters.', $name, $minLength, $maxLength));
        }

        return $trimmed;
    }

    private function optionalTextField(mixed $value, string $name, int $maxLength): string|ApiResponse|null
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return ApiResponse::error(400, 'bad_request', sprintf('Forum %s must be a string or null.', $name));
        }

        $trimmed = trim($value);
        if (strlen($trimmed) > $maxLength) {
            return ApiResponse::error(400, 'bad_request', sprintf('Forum %s must contain at most %d characters.', $name, $maxLength));
        }

        return $trimmed === '' ? null : $trimmed;
    }

    private function positiveIntegerField(mixed $value, string $name): int|ApiResponse
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return ApiResponse::error(400, 'bad_request', sprintf('Forum %s must be a positive integer.', $name));
    }

    private function optionalIntegerField(mixed $value, string $name): int|ApiResponse|null
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return ApiResponse::error(400, 'bad_request', sprintf('Forum %s must be an integer or null.', $name));
    }

    private function optionalPositiveIntegerQuery(array $query, string $name): int|ApiResponse|null
    {
        if (!array_key_exists($name, $query) || $query[$name] === '' || $query[$name] === null) {
            return null;
        }

        return $this->positiveIntegerField($query[$name], $name);
    }

    private function decodeJsonBody(?string $body): ?array
    {
        try {
            $decoded = json_decode($body ?? '', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
