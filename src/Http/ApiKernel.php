<?php

declare(strict_types=1);

namespace VonNeumannGame\Http;

use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeDamageWarning;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeMessage;
use VonNeumannGame\Domain\ProbeMovement;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Domain\VisitedSector;
use VonNeumannGame\Forum\ForumCategory;
use VonNeumannGame\Forum\ForumMessage;
use VonNeumannGame\Forum\ForumPost;
use VonNeumannGame\Forum\ForumRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\ProbeMessageRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Service\MannyActionException;
use VonNeumannGame\Service\MannyService;
use VonNeumannGame\Service\MissionService;
use VonNeumannGame\Service\ObservationAccessException;
use VonNeumannGame\Service\ProbeMovementException;
use VonNeumannGame\Service\ProbeMovementService;
use VonNeumannGame\Service\ProbeReinstantiationException;
use VonNeumannGame\Service\ProbeReinstantiationService;
use VonNeumannGame\Service\ProbeStorageService;
use VonNeumannGame\Service\SectorObservationService;
use VonNeumannGame\Sector\InvalidSectorCoordinatesException;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;

final class ApiKernel
{
    /** Bump when the public API contract changes. */
    public const API_VERSION = 36;

    public function __construct(
        private readonly AuthService $auth,
        private readonly NeumannProbeRepository $probes,
        private readonly SectorObservationService $observations,
        private readonly ProbeMovementService $movements,
        private readonly VisitedSectorRepository $visitedSectors,
        private readonly MannyService $mannies,
        private readonly ProbeItemRepository $items,
        private readonly ProbeStorageService $storage,
        private readonly ProbeMessageRepository $messages,
        private readonly ProbeDamageWarningRepository $damageWarnings,
        private readonly ForumRepository $forum,
        private readonly MissionService $missions,
        private readonly ProbeReinstantiationService $reinstantiation,
        private readonly array $gameplayConfig = [],
    ) {}

    public function handle(string $method, string $path, array $headers = [], ?string $body = null): ApiResponse
    {
        $uri = parse_url($path);
        $routePath = (string) ($uri['path'] ?? $path);
        $query = [];
        if (isset($uri['query'])) {
            parse_str((string) $uri['query'], $query);
        }

        try {
            if (preg_match('#^/api/probe/inventory/([^/]+)/jettison$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeInventoryJettisonResponse($player, rawurldecode($matches[1]), $body));
            }
            if (preg_match('#^/api/probe/inventory/([^/]+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeInventoryItemResponse($player, rawurldecode($matches[1])));
            }
            if (preg_match('#^/api/probe/storage-containers/([^/]+)/rules$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['PATCH'], $headers, fn(Player $player): ApiResponse => $this->probeStorageContainerRulesResponse($player, rawurldecode($matches[1]), $body));
            }
            if (preg_match('#^/api/probe/storage-containers/([^/]+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeStorageContainerResponse($player, rawurldecode($matches[1])));
            }
            if (preg_match('#^/api/probe/mannies/([^/]+)/(repair|mine|craft|salvage|install-bookmark|detach-storage-container|drop-storage-container|inspect-asteroid|recover-storage-container|recall)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeMannyActionResponse($player, rawurldecode($matches[1]), $matches[2], $body));
            }
            if (preg_match('#^/api/probe/mannies/([^/]+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['PATCH'], $headers, fn(Player $player): ApiResponse => $this->probeMannyRenameResponse($player, rawurldecode($matches[1]), $body));
            }
            if (preg_match('#^/api/probe/missions/([^/]+)/abandon$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeMissionAbandonResponse($player, rawurldecode($matches[1])));
            }
            if (preg_match('#^/api/probe/messages/(\d+)/read$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['PATCH'], $headers, fn(Player $player): ApiResponse => $this->probeMessageReadResponse($player, (int) $matches[1]));
            }
            if (preg_match('#^/api/probe/alerts/(\d+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['PATCH'], $headers, fn(Player $player): ApiResponse => $this->probeAlertReadResponse($player, (int) $matches[1]));
            }
            if (preg_match('#^/api/probe/damage-warnings/(\d+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['PATCH'], $headers, fn(Player $player): ApiResponse => $this->probeDamageWarningReadResponse($player, (int) $matches[1]));
            }
            if (preg_match('#^/api/forum/categories/(\d+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['GET', 'PATCH', 'DELETE'], $headers, fn(Player $player): ApiResponse => match ($method) {
                    'GET' => $this->forumCategoryResponse((int) $matches[1]),
                    'PATCH' => $this->forumCategoryUpdateResponse($player, (int) $matches[1], $body),
                    'DELETE' => $this->forumCategoryDeleteResponse($player, (int) $matches[1]),
                });
            }
            if (preg_match('#^/api/forum/posts/(\d+)/messages$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['GET', 'POST'], $headers, fn(Player $player): ApiResponse => $method === 'POST'
                    ? $this->forumMessageCreateResponse($player, (int) $matches[1], $body)
                    : $this->forumPostMessagesResponse((int) $matches[1], $query));
            }
            if (preg_match('#^/api/forum/posts/(\d+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['GET', 'PATCH', 'DELETE'], $headers, fn(Player $player): ApiResponse => match ($method) {
                    'GET' => $this->forumPostResponse((int) $matches[1], $query),
                    'PATCH' => $this->forumPostUpdateResponse($player, (int) $matches[1], $body),
                    'DELETE' => $this->forumPostDeleteResponse($player, (int) $matches[1]),
                });
            }
            if (preg_match('#^/api/forum/messages/(\d+)$#', $routePath, $matches) === 1) {
                return $this->protectedRoute($method, ['PATCH', 'DELETE'], $headers, fn(Player $player): ApiResponse => $method === 'PATCH'
                    ? $this->forumMessageUpdateResponse($player, (int) $matches[1], $body)
                    : $this->forumMessageDeleteResponse($player, (int) $matches[1]));
            }

            return match ($routePath) {
                '/api/version' => $this->routeApiVersion($method),
                '/api/session' => $this->routeSession($method, $body),
                '/api/me' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => new ApiResponse(200, ['player' => $player->publicArray()])),
                '/api/me/api-key' => $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->apiKeyResponse($player)),
                '/api/crafting-recipes' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $_player): ApiResponse => $this->craftingRecipesResponse()),
                '/api/probe' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeResponse($player)),
                '/api/probe/mind-snapshot/reassign' => $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeMindSnapshotReassignResponse($player)),
                '/api/probe/storage-containers' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeStorageContainersResponse($player)),
                '/api/probe/storage-moves' => $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeStorageMoveResponse($player, $body)),
                '/api/probe/atomic-printer/craft' => $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeAtomicPrinterCraftResponse($player, $body)),
                '/api/probe/messages/sent' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeSentMessagesResponse($player, $query)),
                '/api/probe/messages' => $this->protectedRoute($method, ['GET', 'POST'], $headers, fn(Player $player): ApiResponse => $method === 'POST' ? $this->probeMessageSendResponse($player, $body) : $this->probeMessagesResponse($player, $query)),
                '/api/probe/alerts' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeAlertsResponse($player)),
                '/api/probe/damage-warnings' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeDamageWarningsResponse($player)),
                '/api/probe/visited-sectors' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeVisitedSectorsResponse($player)),
                '/api/probe/sector' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeSectorResponse($player)),
                '/api/probe/mission' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeMissionsResponse($player)),
                '/api/probe/missions' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeMissionsResponse($player)),
                '/api/probe/move' => $this->protectedRoute($method, ['POST'], $headers, fn(Player $player): ApiResponse => $this->probeMoveResponse($player, $body)),
                '/api/probe/mannies' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->probeManniesResponse($player)),
                '/api/sector' => $this->protectedRoute($method, ['GET'], $headers, fn(Player $player): ApiResponse => $this->sectorResponse($player, $query)),
                '/api/forum/categories' => $this->protectedRoute($method, ['GET', 'POST'], $headers, fn(Player $player): ApiResponse => $method === 'POST' ? $this->forumCategoryCreateResponse($player, $body) : $this->forumCategoriesResponse()),
                '/api/forum/posts' => $this->protectedRoute($method, ['GET', 'POST'], $headers, fn(Player $player): ApiResponse => $method === 'POST' ? $this->forumPostCreateResponse($player, $body) : $this->forumPostsResponse($query)),
                default => ApiResponse::error(404, 'not_found', 'Endpoint not found'),
            };
        } catch (ProbeMovementException $e) {
            return ApiResponse::error($e->httpStatus, $e->errorCode, $e->getMessage());
        } catch (ProbeReinstantiationException $e) {
            return ApiResponse::error($e->httpStatus, $e->errorCode, $e->getMessage());
        } catch (MannyActionException $e) {
            return ApiResponse::error($e->httpStatus, $e->errorCode, $e->getMessage());
        } catch (ObservationAccessException $e) {
            return ApiResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->details);
        } catch (InvalidSectorCoordinatesException|\InvalidArgumentException $e) {
            return ApiResponse::error(400, 'bad_request', $e->getMessage());
        } catch (\Throwable) {
            return ApiResponse::error(500, 'internal_error', 'Internal server error');
        }
    }

    private function routeApiVersion(string $method): ApiResponse
    {
        if ($method !== 'GET') {
            return ApiResponse::error(405, 'method_not_allowed', 'Method not allowed');
        }

        return new ApiResponse(200, ['apiVersion' => self::API_VERSION]);
    }

    private function routeSession(string $method, ?string $body): ApiResponse
    {
        if ($method !== 'POST') {
            return ApiResponse::error(405, 'method_not_allowed', 'Method not allowed');
        }

        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['username'], $data['password'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain username and password');
        }

        $player = $this->auth->authenticateWithPassword((string) $data['username'], (string) $data['password']);
        if ($player === null) {
            return ApiResponse::error(401, 'unauthorized', 'Invalid credentials');
        }

        $session = $this->auth->createSessionForPlayer($player);

        return new ApiResponse(200, [
            'token' => $session['token'],
            'expiresAt' => $session['expiresAt'],
            'player' => $player->publicArray(),
        ]);
    }

    private function protectedRoute(string $method, array $allowedMethods, array $headers, callable $handler): ApiResponse
    {
        if (!in_array($method, $allowedMethods, true)) {
            return ApiResponse::error(405, 'method_not_allowed', 'Method not allowed');
        }

        $player = $this->auth->getPlayerFromBearerToken($this->authorizationHeader($headers));
        if ($player === null) {
            return ApiResponse::error(401, 'unauthorized', 'Missing or invalid bearer token');
        }

        return $handler($player);
    }

    private function apiKeyResponse(Player $player): ApiResponse
    {
        return new ApiResponse(201, ['apiKey' => $this->auth->createApiKeyForPlayer($player)]);
    }

    private function craftingRecipesResponse(): ApiResponse
    {
        return new ApiResponse(200, ['recipes' => CraftingRecipeCatalog::all($this->gameplayConfig['crafting'] ?? [])]);
    }

    private function forumCategoriesResponse(): ApiResponse
    {
        return new ApiResponse(200, [
            'categories' => array_map(
                fn(ForumCategory $category): array => $this->forumCategoryArray($category),
                $this->forum->categories(),
            ),
        ]);
    }

    private function forumCategoryResponse(int $categoryId): ApiResponse
    {
        $category = $this->forum->findCategoryById($categoryId);
        if ($category === null) {
            return ApiResponse::error(404, 'not_found', 'Forum category not found.');
        }

        return new ApiResponse(200, ['category' => $this->forumCategoryArray($category)]);
    }

    private function forumCategoryCreateResponse(Player $player, ?string $body): ApiResponse
    {
        if (!$player->forumAdmin) {
            return ApiResponse::error(403, 'forbidden', 'Forum administrator permission is required.');
        }

        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a forum category.');
        }

        $name = $this->forumTextField($data['name'] ?? null, 'name', 1, 120);
        if ($name instanceof ApiResponse) {
            return $name;
        }
        $description = $this->forumOptionalTextField($data['description'] ?? null, 'description', 1000);
        if ($description instanceof ApiResponse) {
            return $description;
        }
        $sortOrder = $this->forumOptionalIntegerField($data['sortOrder'] ?? null, 'sortOrder');
        if ($sortOrder instanceof ApiResponse) {
            return $sortOrder;
        }

        return new ApiResponse(201, [
            'category' => $this->forumCategoryArray($this->forum->createCategory($name, $description, $sortOrder)),
        ]);
    }

    private function forumCategoryUpdateResponse(Player $player, int $categoryId, ?string $body): ApiResponse
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
            $name = $this->forumTextField($data['name'], 'name', 1, 120);
            if ($name instanceof ApiResponse) {
                return $name;
            }
            $category->name = $name;
        }
        if (array_key_exists('description', $data)) {
            $description = $this->forumOptionalTextField($data['description'], 'description', 1000);
            if ($description instanceof ApiResponse) {
                return $description;
            }
            $category->description = $description;
        }
        if (array_key_exists('sortOrder', $data)) {
            $sortOrder = $this->forumOptionalIntegerField($data['sortOrder'], 'sortOrder');
            if ($sortOrder instanceof ApiResponse) {
                return $sortOrder;
            }
            $category->sortOrder = $sortOrder ?? $category->sortOrder;
        }

        return new ApiResponse(200, ['category' => $this->forumCategoryArray($this->forum->updateCategory($category))]);
    }

    private function forumCategoryDeleteResponse(Player $player, int $categoryId): ApiResponse
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

    private function forumPostsResponse(array $query): ApiResponse
    {
        $categoryId = $this->forumOptionalPositiveIntegerQuery($query, 'categoryId');
        if ($categoryId instanceof ApiResponse) {
            return $categoryId;
        }
        if ($categoryId !== null && $this->forum->findCategoryById($categoryId) === null) {
            return ApiResponse::error(404, 'not_found', 'Forum category not found.');
        }

        $limit = $this->messagePaginationParameter($query, 'limit', 50, 1, 200);
        if ($limit instanceof ApiResponse) {
            return $limit;
        }
        $offset = $this->messagePaginationParameter($query, 'offset', 0, 0);
        if ($offset instanceof ApiResponse) {
            return $offset;
        }

        $posts = $this->forum->recentPosts($categoryId, $limit, $offset);
        $total = $this->forum->countPosts($categoryId);

        return new ApiResponse(200, [
            'posts' => array_map(fn(ForumPost $post): array => $this->forumPostArray($post), $posts),
            'pagination' => $this->paginationArray($limit, $offset, count($posts), $total),
        ]);
    }

    private function forumPostResponse(int $postId, array $query): ApiResponse
    {
        $post = $this->forum->findPostById($postId);
        if ($post === null) {
            return ApiResponse::error(404, 'not_found', 'Forum post not found.');
        }

        $messages = $this->forumMessagesPage($postId, $query);
        if ($messages instanceof ApiResponse) {
            return $messages;
        }

        return new ApiResponse(200, [
            'post' => $this->forumPostArray($post),
            'firstMessage' => $this->forumOptionalMessageArray($this->forum->firstMessageForPost($post)),
            'messages' => array_map(fn(ForumMessage $message): array => $this->forumMessageArray($message), $messages['items']),
            'pagination' => $messages['pagination'],
        ]);
    }

    private function forumPostCreateResponse(Player $player, ?string $body): ApiResponse
    {
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a forum post.');
        }

        $categoryId = $this->forumPositiveIntegerField($data['categoryId'] ?? null, 'categoryId');
        if ($categoryId instanceof ApiResponse) {
            return $categoryId;
        }
        if ($this->forum->findCategoryById($categoryId) === null) {
            return ApiResponse::error(404, 'not_found', 'Forum category not found.');
        }

        $title = $this->forumTextField($data['title'] ?? null, 'title', 1, 160);
        if ($title instanceof ApiResponse) {
            return $title;
        }
        $messageBody = $this->forumTextField($data['body'] ?? null, 'body', 1, 5000);
        if ($messageBody instanceof ApiResponse) {
            return $messageBody;
        }

        $post = $this->forum->createPost($player, $categoryId, $title, $messageBody);

        return new ApiResponse(201, $this->forumPostPayload($post));
    }

    private function forumPostUpdateResponse(Player $player, int $postId, ?string $body): ApiResponse
    {
        if (!$this->canModerateForum($player)) {
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
            $title = $this->forumTextField($data['title'], 'title', 1, 160);
            if ($title instanceof ApiResponse) {
                return $title;
            }
            $post->title = $title;
        }
        if (array_key_exists('categoryId', $data)) {
            $categoryId = $this->forumPositiveIntegerField($data['categoryId'], 'categoryId');
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

        return new ApiResponse(200, $this->forumPostPayload($this->forum->updatePost($post)));
    }

    private function forumPostDeleteResponse(Player $player, int $postId): ApiResponse
    {
        if (!$this->canModerateForum($player)) {
            return ApiResponse::error(403, 'forbidden', 'Forum moderator permission is required.');
        }
        if ($this->forum->findPostById($postId) === null) {
            return ApiResponse::error(404, 'not_found', 'Forum post not found.');
        }

        $this->forum->deletePost($postId);

        return new ApiResponse(200, ['deleted' => true]);
    }

    private function forumPostMessagesResponse(int $postId, array $query): ApiResponse
    {
        if ($this->forum->findPostById($postId) === null) {
            return ApiResponse::error(404, 'not_found', 'Forum post not found.');
        }

        $messages = $this->forumMessagesPage($postId, $query);
        if ($messages instanceof ApiResponse) {
            return $messages;
        }

        return new ApiResponse(200, [
            'messages' => array_map(fn(ForumMessage $message): array => $this->forumMessageArray($message), $messages['items']),
            'pagination' => $messages['pagination'],
        ]);
    }

    private function forumMessageCreateResponse(Player $player, int $postId, ?string $body): ApiResponse
    {
        if ($this->forum->findPostById($postId) === null) {
            return ApiResponse::error(404, 'not_found', 'Forum post not found.');
        }
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a forum message.');
        }

        $messageBody = $this->forumTextField($data['body'] ?? null, 'body', 1, 5000);
        if ($messageBody instanceof ApiResponse) {
            return $messageBody;
        }

        return new ApiResponse(201, ['message' => $this->forumMessageArray($this->forum->createMessage($player, $postId, $messageBody))]);
    }

    private function forumMessageUpdateResponse(Player $player, int $messageId, ?string $body): ApiResponse
    {
        $message = $this->forum->findMessageById($messageId);
        if ($message === null) {
            return ApiResponse::error(404, 'not_found', 'Forum message not found.');
        }
        if (!$this->canModerateForum($player) && $message->authorPlayerId !== $player->id) {
            return ApiResponse::error(403, 'forbidden', 'Forum moderator permission or message authorship is required.');
        }
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain forum message fields.');
        }
        $messageBody = $this->forumTextField($data['body'] ?? null, 'body', 1, 5000);
        if ($messageBody instanceof ApiResponse) {
            return $messageBody;
        }
        $message->body = $messageBody;

        return new ApiResponse(200, ['message' => $this->forumMessageArray($this->forum->updateMessage($message))]);
    }

    private function forumMessageDeleteResponse(Player $player, int $messageId): ApiResponse
    {
        if (!$this->canModerateForum($player)) {
            return ApiResponse::error(403, 'forbidden', 'Forum moderator permission is required.');
        }
        $message = $this->forum->findMessageById($messageId);
        if ($message === null) {
            return ApiResponse::error(404, 'not_found', 'Forum message not found.');
        }

        $this->forum->deleteMessage($message);

        return new ApiResponse(200, ['deleted' => true]);
    }

    private function probeResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        if ($probe->status === ProbeStatus::TrappedByBlackHole) {
            return new ApiResponse(200, [
                'probe' => [
                    'id' => $probe->id,
                    'name' => $probe->name,
                    'status' => 'trapped_by_black_hole',
                    'message' => 'The probe has crossed a black hole escape threshold. No signal or actuator response can be recovered.',
                    'alert' => $this->terminalProbeAlert($probe),
                    'fuel' => ['deuterium' => $probe->deuteriumStock],
                    'sensorMode' => 'blind',
                    'systems' => [
                        'integrityPercent' => $probe->integrityPercent,
                    ],
                ],
            ]);
        }
        if ($probe->status === ProbeStatus::Dead) {
            return new ApiResponse(200, [
                'probe' => [
                    'id' => $probe->id,
                    'name' => $probe->name,
                    'status' => 'dead',
                    'message' => 'The probe is no longer operational. Its intelligence core is isolated from all sensors and actuators.',
                    'alert' => $this->terminalProbeAlert($probe),
                    'fuel' => ['deuterium' => $probe->deuteriumStock],
                    'sensorMode' => 'blind',
                    'systems' => [
                        'integrityPercent' => $probe->integrityPercent,
                    ],
                ],
            ]);
        }

        $relative = PlayerReferenceFrame::atGlobalCoordinates(
            $player->homeSector->getX(),
            $player->homeSector->getY(),
            $player->homeSector->getZ(),
        )->globalToRelative($probe->currentSector);

        return new ApiResponse(200, ['probe' => $this->probeArray($player, $probe, $relative)]);
    }

    private function probeMindSnapshotReassignResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $result = $this->reinstantiation->reassignMindSnapshot($player, $probe);
        $newPlayer = $result['player'];
        $newProbe = $result['probe'];

        return new ApiResponse(200, [
            'reassigned' => true,
            'previousProbeId' => $result['previousProbeId'],
            'probe' => $this->probeArray($newPlayer, $newProbe, ['x' => 0, 'y' => 0, 'z' => 0]),
            'message' => 'Mind snapshot reassigned to a fresh probe chassis. Local reference frame reset to 0,0,0.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function terminalProbeAlert(NeumannProbe $probe): array
    {
        $trapped = $probe->status === ProbeStatus::TrappedByBlackHole;

        return [
            'type' => 'mind_snapshot_reassignment_available',
            'severity' => 'critical',
            'title' => $trapped ? 'No-return threshold crossed' : 'Probe destroyed',
            'message' => $trapped
                ? 'Your probe crossed a black-hole escape threshold. From the outside, no signal can return. The last stable backup of your mind awaits, stored cold, a new chassis.'
                : 'Your probe was destroyed and the hull is gone. The last stable mind snapshot is still coherent, Bobiverse-style, and can be assigned to a fresh Von Neumann chassis.',
            'action' => [
                'label' => 'Restore your mind into a new probe',
                'method' => 'POST',
                'endpoint' => '/api/probe/mind-snapshot/reassign',
            ],
        ];
    }

    private function probeVisitedSectorsResponse(Player $player): ApiResponse
    {
        $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $frame = new PlayerReferenceFrame($player->homeSector);

        return new ApiResponse(200, [
            'visitedSectors' => array_map(
                fn(VisitedSector $sector): array => [
                    'relativeCoordinates' => $frame->globalToRelative($sector->coordinates),
                    'firstVisitedAt' => $sector->firstVisitedAt,
                    'lastVisitedAt' => $sector->lastVisitedAt,
                    'visitCount' => $sector->visitCount,
                ],
                $this->visitedSectors->listVisited($player),
            ),
        ]);
    }

    private function probeSectorResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $movement = $this->movements->activeMovementForProbe($probe);
        $sensorMode = $this->movements->sensorModeFor($movement, $probe->status);
        if ($sensorMode === 'blind') {
            return ApiResponse::error(400, 'sensors_unavailable', 'External sensors are unavailable at current relativistic velocity.');
        }

        $observableSector = $this->movements->observableSectorFor($probe, $movement) ?? $probe->currentSector;
        if ($movement === null && $observableSector->equals($probe->currentSector)) {
            $this->movements->refreshCurrentSectorHazards($probe);
        }
        if ($sensorMode === 'degraded') {
            $frame = new PlayerReferenceFrame($player->homeSector);
            $observation = [
                'relativeCoordinates' => $frame->globalToRelative($observableSector),
                'distance' => 0,
                'knowledgeLevel' => 'long_range_estimation',
                'confidence' => 0.2,
                'sensorMode' => 'degraded',
                'dataFreshness' => 'degraded_live',
                'message' => 'Sensors are degraded during intersector maneuvering.',
                'scan' => [
                    'currentSectorResidenceSeconds' => 0,
                    'requiredResidenceSeconds' => 0,
                    'scanQuality' => 0.2,
                ],
            ];

            return new ApiResponse(200, [
                'sector' => $this->withObservedProbePresence($observation, $probe, $observableSector),
                'inventory' => $this->inventoryForProbe($probe)->toArray(),
            ]);
        }

        $observation = $this->observations->observe($player, $probe, $observableSector)->toArray();
        $observation['sensorMode'] = $sensorMode;
        $observation['dataFreshness'] = 'live';
        $observation = $this->withBlackHoleTrapCountdown($observation, $probe);
        $observation = $this->withObservedProbePresence($observation, $probe, $observableSector);

        return new ApiResponse(200, [
            'sector' => $observation,
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    private function probeInventoryItemResponse(Player $player, string $itemId): ApiResponse
    {
        $probe = $this->requiredProbe($player);
        $this->movements->ensureProbeOperational($probe);
        $item = $this->inventoryForProbe($probe)->findItem($itemId);

        if ($item === null) {
            return ApiResponse::error(404, 'not_found', 'Inventory item not found.');
        }

        return new ApiResponse(200, ['item' => $item->taskArray()]);
    }

    private function probeStorageContainersResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);

        return new ApiResponse(200, ['containers' => $this->storage->containersForProbe($probe)]);
    }

    private function probeStorageContainerResponse(Player $player, string $containerId): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);

        return new ApiResponse(200, $this->storage->containerInventory($probe, $containerId));
    }

    private function probeStorageContainerRulesResponse(Player $player, string $containerId, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain storage rules.');
        }
        foreach (['priority', 'exclusion', 'strictExclusion'] as $field) {
            if (isset($data[$field]) && !is_array($data[$field])) {
                return ApiResponse::error(400, 'bad_request', 'Storage rule filters must be arrays.');
            }
        }

        return new ApiResponse(200, [
            'container' => $this->storage->updateContainerRules(
                $probe,
                $containerId,
                $data['priority'] ?? [],
                $data['exclusion'] ?? [],
                $data['strictExclusion'] ?? [],
            ),
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    private function probeStorageMoveResponse(Player $player, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a storage move order.');
        }
        $mannyId = $data['actorMannyId'] ?? $data['mannyId'] ?? null;
        if (!is_string($mannyId) || $mannyId === '') {
            return ApiResponse::error(400, 'bad_request', 'Storage move order requires actorMannyId.');
        }

        $manny = $this->mannies->startStorageMove($probe, $mannyId, $data);

        return new ApiResponse(202, [
            'manny' => $this->mannyArray($player, $probe, $manny),
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    private function probeAtomicPrinterCraftResponse(Player $player, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['recipe']) || !is_string($data['recipe'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain recipe.');
        }

        $manny = $this->mannies->startAtomicPrinterCrafting($probe, $data['recipe']);

        return new ApiResponse(202, [
            'manny' => $this->mannyArray($player, $probe, $manny),
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    private function probeMessageSendResponse(Player $player, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a probe message.');
        }

        $messageBody = isset($data['body']) && is_string($data['body']) ? trim($data['body']) : '';
        if ($messageBody === '' || strlen($messageBody) > 2000) {
            return ApiResponse::error(400, 'bad_request', 'Message body must contain 1 to 2000 characters.');
        }

        $recipient = $this->messageRecipientEndpoint($data);
        if ($recipient instanceof ApiResponse) {
            return $recipient;
        }

        if ($recipient['type'] === ProbeMessage::ENDPOINT_PROBE) {
            $recipientProbeId = $this->messageRecipientProbeId($recipient['id']);
            if ($recipientProbeId === null) {
                return ApiResponse::error(400, 'bad_request', 'Message requires a recipient probe id.');
            }
            if ($recipientProbeId === $probe->id) {
                return ApiResponse::error(422, 'invalid_message_recipient', 'A probe cannot send a message to itself.');
            }

            $recipientProbe = $this->probes->findById($recipientProbeId);
            if ($recipientProbe === null) {
                return ApiResponse::error(404, 'not_found', 'Recipient probe not found.');
            }
            $recipientProbe = $this->movements->refreshProbeMovementState($recipientProbe);
            if (!$recipientProbe->currentSector->equals($probe->currentSector)) {
                return ApiResponse::error(422, 'probe_not_in_same_sector', 'Recipient probe must be in the same sector.');
            }

            $message = $this->messages->createForEndpoints(
                ProbeMessage::ENDPOINT_PROBE,
                (string) $probe->id,
                null,
                $probe->id,
                ProbeMessage::ENDPOINT_PROBE,
                (string) $recipientProbe->id,
                null,
                $recipientProbe->id,
                $probe->currentSector,
                $messageBody,
            );

            return new ApiResponse(201, ['message' => $this->probeMessageArray($player, $message)]);
        }

        $recipientPlanet = $this->currentSectorIntelligentLifePlanet($player, $probe, $recipient['id']);
        if ($recipientPlanet === null) {
            return ApiResponse::error(422, 'invalid_message_recipient', 'Recipient planet must be an inhabited planet in the current sector.');
        }

        $message = $this->messages->createForEndpoints(
            ProbeMessage::ENDPOINT_PROBE,
            (string) $probe->id,
            null,
            $probe->id,
            ProbeMessage::ENDPOINT_PLANET,
            $recipientPlanet['id'],
            $recipientPlanet['name'],
            null,
            $probe->currentSector,
            $messageBody,
        );

        return new ApiResponse(201, ['message' => $this->probeMessageArray($player, $message)]);
    }

    private function probeMessagesResponse(Player $player, array $query): ApiResponse
    {
        return $this->probeMessageListResponse($player, $query, false);
    }

    private function probeSentMessagesResponse(Player $player, array $query): ApiResponse
    {
        return $this->probeMessageListResponse($player, $query, true);
    }

    private function probeMessageListResponse(Player $player, array $query, bool $sent): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $limit = $this->messagePaginationParameter($query, 'limit', 50, 1, 200);
        if ($limit instanceof ApiResponse) {
            return $limit;
        }
        $offset = $this->messagePaginationParameter($query, 'offset', 0, 0);
        if ($offset instanceof ApiResponse) {
            return $offset;
        }

        $messages = $sent
            ? $this->messages->sentByProbe($probe->id, $limit, $offset)
            : $this->messages->receivedByProbe($probe->id, $limit, $offset);
        $total = $sent
            ? $this->messages->countSentByProbe($probe->id)
            : $this->messages->countReceivedByProbe($probe->id);

        return new ApiResponse(200, [
            'messages' => array_map(
                fn(ProbeMessage $message): array => $this->probeMessageArray($player, $message, includeReadState: !$sent),
                $messages,
            ),
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($messages),
                'total' => $total,
                'hasMore' => $offset + count($messages) < $total,
            ],
        ]);
    }

    private function probeMessageReadResponse(Player $player, int $messageId): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $message = $this->messages->findById($messageId);
        if ($message === null || $message->recipientType !== ProbeMessage::ENDPOINT_PROBE || $message->recipientProbeId !== $probe->id) {
            return ApiResponse::error(404, 'not_found', 'Message not found.');
        }

        return new ApiResponse(200, ['message' => $this->probeMessageArray($player, $this->messages->markRead($message))]);
    }

    private function probeAlertsResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $alerts = $this->damageWarnings->findByProbeId($probe->id);

        return new ApiResponse(200, [
            'alerts' => array_map(
                fn(ProbeDamageWarning $alert): array => $this->probeAlertArray($player, $alert),
                $alerts,
            ),
            'rules' => $this->probeAlertRules(),
        ]);
    }

    private function probeDamageWarningsResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $warnings = $this->damageWarnings->findByProbeId($probe->id);

        return new ApiResponse(200, [
            'damageWarnings' => array_map(
                fn(ProbeDamageWarning $warning): array => $this->probeAlertArray($player, $warning),
                $warnings,
            ),
            'rule' => $this->storageContainerBreakRule(),
        ]);
    }

    private function probeAlertReadResponse(Player $player, int $alertId): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $alert = $this->damageWarnings->findByIdForProbe($alertId, $probe->id);
        if ($alert === null) {
            return ApiResponse::error(404, 'not_found', 'Alert not found.');
        }

        return new ApiResponse(200, [
            'alert' => $this->probeAlertArray($player, $this->damageWarnings->markRead($alert)),
        ]);
    }

    private function probeDamageWarningReadResponse(Player $player, int $warningId): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $warning = $this->damageWarnings->findByIdForProbe($warningId, $probe->id);
        if ($warning === null) {
            return ApiResponse::error(404, 'not_found', 'Damage warning not found.');
        }

        return new ApiResponse(200, [
            'damageWarning' => $this->probeAlertArray($player, $this->damageWarnings->markRead($warning)),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function probeAlertRules(): array
    {
        return [
            'storageContainerBreak' => $this->storageContainerBreakRule(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storageContainerBreakRule(): array
    {
        return [
            'type' => ProbeDamageWarning::TYPE_STORAGE_CONTAINER_BREAK,
            'startsAtAdditionalContainers' => 5,
            'riskPerAdditionalContainerAfterFourPercent' => 10,
            'maximumRiskPercent' => 100,
            'message' => 'From 5 additional containers onward, movement stress can break one container link. Risk is 10% at 5 containers, 20% at 6, and continues up to 100%.',
        ];
    }

    private function messageRecipientProbeId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{type: string, id: mixed}|ApiResponse
     */
    private function messageRecipientEndpoint(array $data): array|ApiResponse
    {
        $recipient = is_array($data['recipient'] ?? null) ? $data['recipient'] : [];
        $typeValue = $recipient['type'] ?? $data['recipientType'] ?? $data['type'] ?? ProbeMessage::ENDPOINT_PROBE;
        $type = is_string($typeValue) ? strtolower(trim($typeValue)) : ProbeMessage::ENDPOINT_PROBE;
        if ($type === '') {
            $type = ProbeMessage::ENDPOINT_PROBE;
        }
        if (!in_array($type, [ProbeMessage::ENDPOINT_PROBE, ProbeMessage::ENDPOINT_PLANET], true)) {
            return ApiResponse::error(400, 'bad_request', 'Recipient type must be probe or planet.');
        }

        $id = $recipient['id'] ?? $data['recipientId'] ?? null;
        if ($type === ProbeMessage::ENDPOINT_PROBE && array_key_exists('recipientProbeId', $data)) {
            $id = $data['recipientProbeId'];
        }
        if ($type === ProbeMessage::ENDPOINT_PLANET && array_key_exists('recipientPlanetId', $data)) {
            $id = $data['recipientPlanetId'];
        }

        if (($type === ProbeMessage::ENDPOINT_PROBE && $this->messageRecipientProbeId($id) === null) || ($type === ProbeMessage::ENDPOINT_PLANET && (!is_string($id) || trim($id) === ''))) {
            return ApiResponse::error(400, 'bad_request', 'Message requires a recipient id.');
        }

        return [
            'type' => $type,
            'id' => $type === ProbeMessage::ENDPOINT_PLANET ? trim((string) $id) : $id,
        ];
    }

    /**
     * @return array{id: string, name: ?string}|null
     */
    private function currentSectorIntelligentLifePlanet(Player $player, NeumannProbe $probe, string $planetId): ?array
    {
        $observation = $this->observations->observe($player, $probe, $probe->currentSector)->toArray();
        foreach (($observation['objects'] ?? []) as $object) {
            if (!is_array($object)) {
                continue;
            }
            $planet = $this->findIntelligentLifePlanetInObservationObject($object, $planetId);
            if ($planet !== null) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $object
     * @return array{id: string, name: ?string}|null
     */
    private function findIntelligentLifePlanetInObservationObject(array $object, string $planetId): ?array
    {
        if (($object['type'] ?? null) === ProbeMessage::ENDPOINT_PLANET && (string) ($object['id'] ?? '') === $planetId && (bool) ($object['intelligentLife'] ?? false)) {
            return [
                'id' => (string) $object['id'],
                'name' => isset($object['name']) && $object['name'] !== null ? (string) $object['name'] : null,
            ];
        }

        foreach (['minableTargets', 'bookmarkTargets'] as $childKey) {
            foreach (($object[$childKey] ?? []) as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $planet = $this->findIntelligentLifePlanetInObservationObject($child, $planetId);
                if ($planet !== null) {
                    return $planet;
                }
            }
        }

        return null;
    }

    private function messagePaginationParameter(array $query, string $name, int $default, int $min, ?int $max = null): int|ApiResponse
    {
        if (!array_key_exists($name, $query)) {
            return $default;
        }

        $value = $query[$name];
        if (is_array($value) || !is_string($value) || !ctype_digit($value)) {
            return ApiResponse::error(400, 'bad_request', $this->messagePaginationError($name, $min, $max));
        }

        $integer = (int) $value;
        if ($integer < $min || ($max !== null && $integer > $max)) {
            return ApiResponse::error(400, 'bad_request', $this->messagePaginationError($name, $min, $max));
        }

        return $integer;
    }

    private function messagePaginationError(string $name, int $min, ?int $max): string
    {
        if ($max === null) {
            return sprintf('Query parameter %s must be an integer greater than or equal to %d.', $name, $min);
        }

        return sprintf('Query parameter %s must be an integer between %d and %d.', $name, $min, $max);
    }

    private function probeInventoryJettisonResponse(Player $player, string $itemId, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body) ?? [];
        if (isset($data['amount']) && !is_numeric($data['amount'])) {
            return ApiResponse::error(400, 'bad_request', 'Jettison amount must be numeric.');
        }

        $amount = isset($data['amount']) ? round((float) $data['amount'], 4) : null;
        if ($amount !== null && $amount <= 0.0) {
            return ApiResponse::error(400, 'bad_request', 'Jettison amount must be greater than zero.');
        }
        $containerId = isset($data['containerId']) && is_string($data['containerId']) && $data['containerId'] !== ''
            ? $data['containerId']
            : null;

        $inventory = $this->inventoryForProbe($probe);
        $item = $inventory->findItem($itemId);
        if ($item !== null) {
            if ($item->type === 'atomic_3d_printer') {
                return ApiResponse::error(422, 'item_not_jettisonable', 'This inventory item cannot be jettisoned.');
            }

            if ($item->type === 'manny') {
                $manny = $this->mannies->jettisonMannyFromProbe($probe, $itemId);
                $this->mannies->manniesForProbe($probe);
                $probe = $this->requiredProbe($player);

                return new ApiResponse(200, [
                    'inventory' => $this->inventoryForProbe($probe)->toArray(),
                    'manny' => $this->mannyArray($player, $probe, $manny),
                ]);
            }

            $jettisoned = $this->mannies->jettisonProbeItemFromProbe($probe, $itemId);
            $this->mannies->manniesForProbe($probe);
            $probe = $this->requiredProbe($player);
            return new ApiResponse(200, [
                'inventory' => $this->inventoryForProbe($probe)->toArray(),
                'jettisoned' => $jettisoned,
            ]);
        }

        $resourceType = $this->inventoryResourceType($probe, $itemId);
        if ($resourceType !== null) {
            $available = $containerId !== null
                ? $this->storage->resourceStockInContainer($probe, $resourceType, $containerId)
                : $this->storage->resourceStock($probe, $resourceType);
            $discarded = $this->jettisonAmount($amount, $available);
            if ($discarded instanceof ApiResponse) {
                return $discarded;
            }

            if ($containerId !== null) {
                $this->storage->consumeResourceFromContainer($probe, $resourceType, $discarded, $containerId);
            } else {
                $this->storage->consumeResource($probe, $resourceType, $discarded);
            }
            $this->mannies->manniesForProbe($probe);
            $probe = $this->requiredProbe($player);

            return new ApiResponse(200, [
                'inventory' => $this->inventoryForProbe($probe)->toArray(),
                'jettisoned' => ['type' => $resourceType, 'amount' => $discarded],
            ]);
        }

        if ($itemId === 'probe-' . $probe->id . '-deuterium-tank' || $itemId === 'deuterium') {
            return ApiResponse::error(422, 'item_not_jettisonable', 'The external deuterium tank cannot be jettisoned.');
        }

        return ApiResponse::error(404, 'not_found', 'Inventory item not found.');
    }

    private function inventoryResourceType(NeumannProbe $probe, string $itemId): ?string
    {
        return match ($itemId) {
            'metals', 'probe-' . $probe->id . '-stock-metals' => 'metals',
            'ice', 'probe-' . $probe->id . '-stock-ice' => 'ice',
            'carbon_compounds',
            'organic_compounds',
            'organicCompounds',
            'probe-' . $probe->id . '-stock-carbon-compounds',
            'probe-' . $probe->id . '-stock-organic-compounds' => 'carbon_compounds',
            default => null,
        };
    }

    private function jettisonAmount(?float $requested, float $available): float|ApiResponse
    {
        $available = round(max(0.0, $available), 4);
        $amount = $requested === null ? $available : round($requested, 4);
        if ($amount <= 0.0 || $available <= 0.0) {
            return ApiResponse::error(422, 'nothing_to_jettison', 'There is nothing to jettison for this inventory entry.');
        }
        if ($amount > $available + 0.00001) {
            return ApiResponse::error(422, 'insufficient_inventory_amount', 'The requested jettison amount is not available.');
        }

        return min($amount, $available);
    }

    private function sectorResponse(Player $player, array $query): ApiResponse
    {
        foreach (['x', 'y', 'z'] as $field) {
            if (!isset($query[$field]) || !is_numeric($query[$field]) || (string) (int) $query[$field] !== (string) $query[$field]) {
                return ApiResponse::error(400, 'bad_request', 'Query parameters x, y and z must be integer relative coordinates.');
            }
        }
        if (!$this->validRelativeCoordinateParity((int) $query['x'], (int) $query['y'], (int) $query['z'])) {
            return $this->invalidRelativeCoordinateResponse();
        }

        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $target = $this->observations->relativeToAbsolute($player, (int) $query['x'], (int) $query['y'], (int) $query['z']);
        $movement = $this->movements->activeMovementForProbe($probe);
        $sensorMode = $this->movements->sensorModeFor($movement, $probe->status);
        if ($movement === null && $target->equals($probe->currentSector)) {
            $this->movements->refreshCurrentSectorHazards($probe);
        }

        if ($sensorMode === 'blind' && !$this->visitedSectors->hasVisited($player, $target)) {
            return ApiResponse::error(400, 'sensors_unavailable', 'External sensors are unavailable at current relativistic velocity.');
        }
        if ($sensorMode === 'degraded' && !$this->visitedSectors->hasVisited($player, $target) && !$target->equals($probe->currentSector)) {
            $observable = $this->movements->observableSectorFor($probe, $movement) ?? $probe->currentSector;
            $frame = new PlayerReferenceFrame($player->homeSector);

            return new ApiResponse(200, [
                'sector' => [
                    'relativeCoordinates' => $frame->globalToRelative($target),
                    'distance' => (new SectorGrid())->getDistance($observable, $target),
                    'knowledgeLevel' => 'long_range_estimation',
                    'confidence' => 0.12,
                    'sensorMode' => 'degraded',
                    'dataFreshness' => 'degraded_live',
                    'message' => 'Sensors are degraded during intersector maneuvering.',
                    'scan' => [
                        'currentSectorResidenceSeconds' => 0,
                        'requiredResidenceSeconds' => 0,
                        'scanQuality' => 0.12,
                    ],
                ],
            ]);
        }

        $observation = $this->observations->observe($player, $probe, $target)->toArray();
        $observation['sensorMode'] = $sensorMode;
        $observation['dataFreshness'] = $sensorMode === 'blind' ? 'historical' : ($sensorMode === 'degraded' ? 'degraded_live' : 'live');
        if ($movement === null && $target->equals($probe->currentSector)) {
            $observation = $this->withBlackHoleTrapCountdown($observation, $probe);
        }

        return new ApiResponse(200, ['sector' => $observation]);
    }

    private function probeMoveResponse(Player $player, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['target']) || !is_array($data['target'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain target coordinates.');
        }
        foreach (['x', 'y', 'z'] as $field) {
            if (!isset($data['target'][$field]) || !is_int($data['target'][$field])) {
                return ApiResponse::error(400, 'bad_request', 'Target coordinates x, y and z must be integers.');
            }
        }
        if (!$this->validRelativeCoordinateParity($data['target']['x'], $data['target']['y'], $data['target']['z'])) {
            return $this->invalidRelativeCoordinateResponse();
        }

        $target = $this->observations->relativeToAbsolute($player, $data['target']['x'], $data['target']['y'], $data['target']['z']);
        $movement = $this->movements->startMovement($probe, $target);

        return new ApiResponse(202, ['movement' => $this->movementArray($player, $movement)]);
    }

    private function probeManniesResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $mannies = $this->mannies->manniesForProbe($probe);

        return new ApiResponse(200, [
            'mannies' => array_map(fn($manny): array => $this->mannyArray($player, $probe, $manny), $mannies),
        ]);
    }

    private function probeMissionsResponse(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));

        return new ApiResponse(200, [
            'missions' => array_map(
                static fn($mission): array => $mission->toArray(),
                $this->missions->activeMissionsForProbe($probe),
            ),
        ]);
    }

    private function probeMissionAbandonResponse(Player $player, string $missionId): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $mission = $this->missions->abandonMission($probe, $missionId);

        return new ApiResponse(200, ['mission' => $mission->toArray()]);
    }

    private function probeMannyRenameResponse(Player $player, string $uid, ?string $body): ApiResponse
    {
        $probe = $this->requiredProbe($player);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a Manny name.');
        }

        $manny = $this->mannies->renameManny($probe, $uid, $data['name']);

        return new ApiResponse(200, ['manny' => $this->mannyArray($player, $probe, $manny)]);
    }

    private function probeMannyActionResponse(Player $player, string $uid, string $action, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $data = $this->decodeJsonBody($body) ?? [];

        if ($action === 'repair') {
            $repairPercent = $data['integrityPercent'] ?? $data['percent'] ?? null;
            if (!is_numeric($repairPercent)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain repair percent.');
            }

            $manny = $this->mannies->startRepair($probe, $uid, (float) $repairPercent);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'mine') {
            if (!isset($data['objectId'], $data['targetAmount']) || !is_string($data['objectId']) || !is_numeric($data['targetAmount'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId, resources and targetAmount.');
            }

            $resources = $data['resources'] ?? $data['resource'] ?? null;
            if (is_array($resources)) {
                foreach ($resources as $resource) {
                    if (!is_string($resource)) {
                        return ApiResponse::error(400, 'bad_request', 'Mining resources must be strings.');
                    }
                }
            } elseif (!is_string($resources)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain resources or resource.');
            }

            $manny = $this->mannies->startMining($probe, $uid, $data['objectId'], $resources, (float) $data['targetAmount']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'craft') {
            if (!isset($data['recipe']) || !is_string($data['recipe'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain recipe.');
            }

            $manny = $this->mannies->startCrafting($probe, $uid, $data['recipe']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'salvage') {
            if (!isset($data['objectId']) || !is_string($data['objectId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId.');
            }

            $manny = $this->mannies->startSalvage($probe, $uid, $data['objectId']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'detach-storage-container') {
            $this->movements->ensureProbeOperational($probe);
            if ($this->movements->activeMovementForProbe($probe) !== null) {
                return ApiResponse::error(409, 'probe_already_moving', 'The probe is already moving between sectors.');
            }
            if (!isset($data['containerId'], $data['mode']) || !is_string($data['containerId']) || !is_string($data['mode'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain containerId and mode.');
            }
            $objectId = isset($data['objectId']) && is_string($data['objectId']) ? $data['objectId'] : null;
            $manny = $this->mannies->startDetachStorageContainer($probe, $player->id, $uid, $data['containerId'], $data['mode'], $objectId);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'drop-storage-container') {
            $this->movements->ensureProbeOperational($probe);
            if ($this->movements->activeMovementForProbe($probe) !== null) {
                return ApiResponse::error(409, 'probe_already_moving', 'The probe is already moving between sectors.');
            }
            if (!isset($data['containerId'], $data['planetId']) || !is_string($data['containerId']) || !is_string($data['planetId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain containerId and planetId.');
            }

            $manny = $this->mannies->startDropStorageContainerOnPlanet($probe, $player->id, $uid, $data['containerId'], $data['planetId']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'inspect-asteroid') {
            if (!isset($data['objectId']) || !is_string($data['objectId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId.');
            }

            $manny = $this->mannies->startInspectAsteroid($probe, $uid, $data['objectId']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'recover-storage-container') {
            if (!isset($data['objectId']) || !is_string($data['objectId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId.');
            }

            $manny = $this->mannies->startRecoverDetachedContainer($probe, $uid, $data['objectId']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        if ($action === 'install-bookmark') {
            $this->movements->ensureProbeOperational($probe);
            if ($this->movements->activeMovementForProbe($probe) !== null) {
                return ApiResponse::error(409, 'probe_already_moving', 'The probe is already moving between sectors.');
            }
            if (!isset($data['objectId'], $data['name']) || !is_string($data['objectId']) || !is_string($data['name'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId and name.');
            }

            $manny = $this->mannies->startWaypointBookmarkInstallation($probe, $player, $uid, $data['objectId'], $data['name']);

            return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
        }

        $manny = $this->mannies->recallManny($probe, $uid);

        return new ApiResponse(202, ['manny' => $this->mannyArray($player, $probe, $manny)]);
    }

    private function requiredProbe(Player $player): NeumannProbe
    {
        return $this->probes->findByPlayerId($player->id) ?? throw new \RuntimeException('Probe not found.');
    }

    private function withObservedProbePresence(array $observation, NeumannProbe $probe, SectorCoordinates $observableSector): array
    {
        if (!$observableSector->equals($probe->currentSector)) {
            return $observation;
        }

        $observedProbes = $this->observedProbePresence($probe, $observableSector);
        if ($observedProbes !== []) {
            $observation['probes'] = $observedProbes;
        }

        return $observation;
    }

    /**
     * @return array<array{id:int, name:string, moving:bool}>
     */
    private function observedProbePresence(NeumannProbe $probe, SectorCoordinates $sector): array
    {
        $observed = [];
        foreach ($this->probes->findBySector($sector, $probe->id) as $otherProbe) {
            $otherProbe = $this->movements->refreshProbeMovementState($otherProbe);
            if (!$otherProbe->currentSector->equals($sector)) {
                continue;
            }

            $observed[] = [
                'id' => $otherProbe->id,
                'name' => $otherProbe->name,
                'moving' => $this->movements->activeMovementForProbe($otherProbe) !== null,
            ];
        }

        return $observed;
    }

    private function probeArray(Player $player, NeumannProbe $probe, array $relative): array
    {
        $movement = $this->movements->activeMovementForProbe($probe);
        $latest = $movement ?? $this->movements->latestMovementForProbe($probe);
        $sensorMode = $this->movements->sensorModeFor($movement, $probe->status);

        return [
            'id' => $probe->id,
            'name' => $probe->name,
            'status' => $probe->status->value,
            'fuel' => ['deuterium' => $probe->deuteriumStock],
            'sensorMode' => $sensorMode,
            'sector' => $movement === null ? ['relative' => $relative] : null,
            'navigation' => [
                'velocityC' => $probe->velocityC,
                'accelerationCPerDay' => $probe->accelerationCPerDay,
                'direction' => $probe->direction->toArray(),
            ],
            'movement' => $latest !== null ? $this->movementArray($player, $latest, $movement !== null) : null,
            'systems' => [
                'integrityPercent' => $probe->integrityPercent,
                'energyStored' => $probe->energyStored,
                'internalClockRate' => $probe->internalClockRate,
                'currentTask' => $probe->currentTask,
            ],
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ];
    }

    private function inventoryForProbe(NeumannProbe $probe): ProbeInventory
    {
        return $this->storage->inventoryForProbe(
            $probe,
            $this->mannies->manniesForProbe($probe),
            $this->items->findByProbeId($probe->id),
        );
    }

    private function mannyArray(Player $player, NeumannProbe $probe, Manny $manny): array
    {
        $relativeSector = $manny->sector === null
            ? null
            : (new PlayerReferenceFrame($player->homeSector))->globalToRelative($manny->sector);

        return $this->mannies->publicArray($probe, $manny, $relativeSector);
    }

    private function probeMessageArray(Player $player, ProbeMessage $message, bool $includeReadState = true): array
    {
        $payload = [
            'id' => $message->id,
            'sender' => $this->probeMessageEndpointArray($message->senderType, $message->senderId, $message->senderName, $message->senderProbeId),
            'recipient' => $this->probeMessageEndpointArray($message->recipientType, $message->recipientId, $message->recipientName, $message->recipientProbeId),
            'sector' => [
                'relative' => (new PlayerReferenceFrame($player->homeSector))->globalToRelative($message->sector),
            ],
            'body' => $message->body,
            'createdAt' => $message->createdAt,
        ];

        if ($includeReadState) {
            $payload['status'] = $message->status;
            $payload['readAt'] = $message->readAt;
            $payload['updatedAt'] = $message->updatedAt;
        }

        return $payload;
    }

    private function probeMessageEndpointArray(string $type, string $id, ?string $name, ?int $probeId): array
    {
        if ($type === ProbeMessage::ENDPOINT_PROBE) {
            $probe = $probeId !== null ? $this->probes->findById($probeId) : null;
            $publicId = $probeId ?? (int) $id;

            return [
                'type' => ProbeMessage::ENDPOINT_PROBE,
                'id' => $publicId,
                'probeId' => $publicId,
                'name' => $probe?->name ?? $name ?? 'Probe #' . $publicId,
            ];
        }

        return [
            'type' => ProbeMessage::ENDPOINT_PLANET,
            'id' => $id,
            'planetId' => $id,
            'name' => $name ?? 'Planet #' . $id,
        ];
    }

    private function canModerateForum(Player $player): bool
    {
        return $player->forumAdmin || $player->forumModerator;
    }

    private function forumCategoryArray(ForumCategory $category): array
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

    private function forumPostArray(ForumPost $post): array
    {
        return [
            'id' => $post->id,
            'categoryId' => $post->categoryId,
            'author' => $this->forumAuthorArray($post->authorPlayerId, $post->authorUsername, $post->authorDisplayName),
            'title' => $post->title,
            'pinned' => $post->pinned,
            'firstMessageId' => $post->firstMessageId,
            'messageCount' => $post->messageCount,
            'createdAt' => $post->createdAt,
            'updatedAt' => $post->updatedAt,
            'lastMessageAt' => $post->lastMessageAt,
        ];
    }

    private function forumPostPayload(ForumPost $post): array
    {
        return [
            'post' => $this->forumPostArray($post),
            'firstMessage' => $this->forumOptionalMessageArray($this->forum->firstMessageForPost($post)),
        ];
    }

    private function forumOptionalMessageArray(?ForumMessage $message): ?array
    {
        return $message !== null ? $this->forumMessageArray($message) : null;
    }

    private function forumMessageArray(ForumMessage $message): array
    {
        return [
            'id' => $message->id,
            'postId' => $message->postId,
            'author' => $this->forumAuthorArray($message->authorPlayerId, $message->authorUsername, $message->authorDisplayName),
            'body' => $message->body,
            'createdAt' => $message->createdAt,
            'updatedAt' => $message->updatedAt,
            'editedAt' => $message->editedAt,
        ];
    }

    private function forumAuthorArray(int $id, string $username, ?string $displayName): array
    {
        return [
            'playerId' => $id,
            'username' => $username,
            'displayName' => $displayName,
        ];
    }

    private function paginationArray(int $limit, int $offset, int $count, int $total): array
    {
        return [
            'limit' => $limit,
            'offset' => $offset,
            'count' => $count,
            'total' => $total,
            'hasMore' => $offset + $count < $total,
        ];
    }

    /**
     * @return array{items: array<ForumMessage>, pagination: array<string, int|bool>}|ApiResponse
     */
    private function forumMessagesPage(int $postId, array $query): array|ApiResponse
    {
        $limit = $this->messagePaginationParameter($query, 'limit', 50, 1, 200);
        if ($limit instanceof ApiResponse) {
            return $limit;
        }
        $offset = $this->messagePaginationParameter($query, 'offset', 0, 0);
        if ($offset instanceof ApiResponse) {
            return $offset;
        }

        $messages = $this->forum->recentMessagesForPost($postId, $limit, $offset);
        $total = $this->forum->countMessagesForPost($postId);

        return [
            'items' => $messages,
            'pagination' => $this->paginationArray($limit, $offset, count($messages), $total),
        ];
    }

    private function forumTextField(mixed $value, string $name, int $minLength, int $maxLength): string|ApiResponse
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

    private function forumOptionalTextField(mixed $value, string $name, int $maxLength): string|ApiResponse|null
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

    private function forumPositiveIntegerField(mixed $value, string $name): int|ApiResponse
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return ApiResponse::error(400, 'bad_request', sprintf('Forum %s must be a positive integer.', $name));
    }

    private function forumOptionalIntegerField(mixed $value, string $name): int|ApiResponse|null
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

        return ApiResponse::error(400, 'bad_request', sprintf('Forum %s must be an integer.', $name));
    }

    private function forumOptionalPositiveIntegerQuery(array $query, string $name): int|ApiResponse|null
    {
        if (!array_key_exists($name, $query)) {
            return null;
        }
        $value = $query[$name];
        if (is_array($value) || !is_string($value) || !ctype_digit($value) || (int) $value <= 0) {
            return ApiResponse::error(400, 'bad_request', sprintf('Query parameter %s must be a positive integer.', $name));
        }

        return (int) $value;
    }

    private function probeAlertArray(Player $player, ProbeDamageWarning $warning): array
    {
        $frame = new PlayerReferenceFrame($player->homeSector);

        $alert = [
            'id' => $warning->id,
            'type' => $warning->type,
            'status' => $warning->status,
            'message' => $warning->message,
            'phase' => $warning->phase,
            'scheduledAt' => $warning->scheduledAt,
            'sector' => [
                'relative' => $frame->globalToRelative(new SectorCoordinates($warning->sectorX, $warning->sectorY, $warning->sectorZ)),
            ],
            'createdAt' => $warning->createdAt,
            'updatedAt' => $warning->updatedAt,
            'readAt' => $warning->readAt,
            'resolvedAt' => $warning->resolvedAt,
        ];

        if ($warning->type === ProbeDamageWarning::TYPE_STORAGE_CONTAINER_BREAK) {
            $alert['container'] = [
                'id' => $warning->containerId,
                'label' => $warning->containerLabel,
                'detachedObjectId' => $warning->objectId,
            ];
            $alert['risk'] = [
                'percent' => $warning->riskPercent,
                'additionalContainerCount' => $warning->additionalContainerCount,
                'ruleStartsAtAdditionalContainers' => 5,
            ];
        }

        if ($warning->type === ProbeDamageWarning::TYPE_INTELLIGENT_LIFE) {
            $alert['planet'] = [
                'id' => $warning->objectId,
                'name' => $warning->containerLabel !== '' ? $warning->containerLabel : null,
            ];
        }

        return $alert;
    }

    private function movementArray(Player $player, ProbeMovement $movement, bool $includeLive = true): array
    {
        $frame = new PlayerReferenceFrame($player->homeSector);

        return [
            'status' => $movement->status,
            'origin' => $frame->globalToRelative($movement->origin),
            'target' => $frame->globalToRelative($movement->target),
            'distance' => $movement->distance,
            'fuelCostDeuterium' => $movement->fuelCostDeuterium,
            'startedAt' => $movement->startedAt,
            'arrivalAt' => $movement->arrivalAt,
        ] + ($includeLive ? [
            'phase' => $this->movements->phaseFor($movement),
            'secondsRemaining' => $this->movements->secondsRemaining($movement),
            'sensorMode' => $this->movements->sensorModeFor($movement, ProbeStatus::from($movement->status === 'destroyed' ? 'dead' : ($movement->status === 'arrived' ? 'idle' : $movement->status))),
            'estimatedVelocityC' => $this->movements->estimatedVelocityC($movement),
        ] : []);
    }

    private function withBlackHoleTrapCountdown(array $observation, NeumannProbe $probe): array
    {
        $trap = $this->movements->pendingBlackHoleTrapForProbe($probe);
        if ($trap === null || !isset($observation['objects']) || !is_array($observation['objects'])) {
            return $observation;
        }

        foreach ($observation['objects'] as &$object) {
            if (($object['type'] ?? null) === 'black_hole') {
                $object['noReturnCountdown'] = $trap;
            }
        }
        unset($object);

        return $observation;
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

    private function validRelativeCoordinateParity(int $x, int $y, int $z): bool
    {
        return ($x + $y + $z) % 2 === 0;
    }

    private function invalidRelativeCoordinateResponse(): ApiResponse
    {
        return ApiResponse::error(400, 'bad_request', 'Relative coordinates are invalid: x + y + z must be even.');
    }

    private function authorizationHeader(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        return null;
    }
}
