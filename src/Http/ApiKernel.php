<?php

declare(strict_types=1);

namespace VonNeumannGame\Http;

use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Domain\Mission;
use VonNeumannGame\Domain\MissionStep;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeDamageWarning;
use VonNeumannGame\Domain\ProbeImprovementCatalog;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeMessage;
use VonNeumannGame\Domain\ProbeMovement;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Domain\ScutNetwork;
use VonNeumannGame\Domain\ScutRelay;
use VonNeumannGame\Domain\VisitedSector;
use VonNeumannGame\Forum\ForumRepository;
use VonNeumannGame\Http\Controller\ForumApiController;
use VonNeumannGame\Http\Controller\ProbeManniesApiController;
use VonNeumannGame\Http\Controller\ProbeManniesApiPresenter;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\ProbeImprovementRepository;
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
use VonNeumannGame\Service\ScutNetworkService;
use VonNeumannGame\Service\SectorObservationService;
use VonNeumannGame\Sector\InvalidSectorCoordinatesException;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;

final class ApiKernel
{
    /** Bump when the public API contract changes. */
    public const API_VERSION = 77;
    private ?ApiRouter $router = null;
    private ?ForumApiController $forumController = null;
    private ?ProbeManniesApiController $probeManniesController = null;
    private ?ProbeManniesApiPresenter $probeManniesPresenter = null;

    public function __construct(
        private readonly AuthService $auth,
        private readonly PlayerRepository $players,
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
        private readonly ScutNetworkService $scut,
        private readonly array $gameplayConfig = [],
        private readonly ?ProbeImprovementRepository $improvements = null,
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
            return $this->router()->dispatch(new ApiRouteContext($method, $routePath, $query, $headers, $body));
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

    private function router(): ApiRouter
    {
        return $this->router ??= new ApiRouter($this->routes());
    }

    /**
     * @return list<ApiRoute>
     */
    private function routes(): array
    {
        return [
            ApiRoute::regex('#^/api/probe/(\d+)/inventory/([^/]+)/jettison$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeInventoryJettisonResponse($player, $ctx->stringParam(1), $ctx->body, probe: $probe), $ctx->intParam(0), ['POST'])),
            ApiRoute::regex('#^/api/probe/inventory/([^/]+)/jettison$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeInventoryJettisonResponse($player, $ctx->stringParam(0), $ctx->body))),
            ApiRoute::regex('#^/api/probe/(\d+)/inventory/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeInventoryItemResponse($player, $ctx->stringParam(1), $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/inventory/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeInventoryItemResponse($player, $ctx->stringParam(0)))),
            ApiRoute::regex('#^/api/probe/(\d+)/storage-containers/([^/]+)/rules$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeStorageContainerRulesResponse($player, $ctx->stringParam(1), $ctx->body, $probe), $ctx->intParam(0), ['PATCH'])),
            ApiRoute::regex('#^/api/probe/storage-containers/([^/]+)/rules$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['PATCH'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeStorageContainerRulesResponse($player, $ctx->stringParam(0), $ctx->body))),
            ApiRoute::regex('#^/api/probe/(\d+)/storage-containers/([^/]+)$#', ['GET', 'PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute(
                $ctx,
                fn(Player $player, NeumannProbe $probe): ApiResponse => $ctx->method === 'PATCH'
                    ? $this->probeStorageContainerRenameResponse($player, $ctx->stringParam(1), $ctx->body, $probe)
                    : $this->probeStorageContainerResponse($player, $ctx->stringParam(1), $probe),
                $ctx->intParam(0),
                ['GET', 'PATCH'],
            )),
            ApiRoute::regex('#^/api/probe/storage-containers/([^/]+)$#', ['GET', 'PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute(
                $ctx->method,
                ['GET', 'PATCH'],
                $ctx->headers,
                fn(Player $player): ApiResponse => $ctx->method === 'PATCH'
                    ? $this->probeStorageContainerRenameResponse($player, $ctx->stringParam(0), $ctx->body)
                    : $this->probeStorageContainerResponse($player, $ctx->stringParam(0)),
            )),
            ApiRoute::regex('#^/api/probe/(\d+)/mannies/([^/]+)/(repair|mine|craft|salvage|install-bookmark|detach-storage-container|drop-storage-container|drop-manny-cargo|inspect-sector-object|inspect-asteroid|recover-storage-container|refill-deuterium-tank|turn-on-relay|improve-probe|recall)$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeManniesController()->action($player, $ctx->stringParam(1), $ctx->params[2], $ctx->body, $probe), $ctx->intParam(0), ['POST'])),
            ApiRoute::regex('#^/api/probe/mannies/([^/]+)/(repair|mine|craft|salvage|install-bookmark|detach-storage-container|drop-storage-container|drop-manny-cargo|inspect-sector-object|inspect-asteroid|recover-storage-container|refill-deuterium-tank|turn-on-relay|improve-probe|recall)$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeManniesController()->action($player, $ctx->stringParam(0), $ctx->params[1], $ctx->body))),
            ApiRoute::regex('#^/api/probe/(\d+)/scut-network/(\d+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeScutNetworkResponse($player, $ctx->intParam(1), $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/scut-network/(\d+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeScutNetworkResponse($player, $ctx->intParam(0)))),
            ApiRoute::regex('#^/api/probe/(\d+)/mannies/([^/]+)$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeManniesController()->rename($player, $ctx->stringParam(1), $ctx->body, $probe), $ctx->intParam(0), ['PATCH'])),
            ApiRoute::regex('#^/api/probe/mannies/([^/]+)$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['PATCH'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeManniesController()->rename($player, $ctx->stringParam(0), $ctx->body))),
            ApiRoute::regex('#^/api/probe/missions/([^/]+)/abandon$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMissionAbandonResponse($player, $ctx->stringParam(0)))),
            ApiRoute::regex('#^/api/probe/(\d+)/messages/(\d+)/read$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeMessageReadResponse($player, $ctx->intParam(1), $probe), $ctx->intParam(0), ['PATCH'])),
            ApiRoute::regex('#^/api/probe/messages/(\d+)/read$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['PATCH'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMessageReadResponse($player, $ctx->intParam(0)))),
            ApiRoute::regex('#^/api/probe/(\d+)/alerts/(\d+)$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeAlertReadResponse($player, $ctx->intParam(1), $probe), $ctx->intParam(0), ['PATCH'])),
            ApiRoute::regex('#^/api/probe/alerts/(\d+)$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['PATCH'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeAlertReadResponse($player, $ctx->intParam(0)))),
            ApiRoute::regex('#^/api/probe/(\d+)/damage-warnings/(\d+)$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeDamageWarningReadResponse($player, $ctx->intParam(1), $probe), $ctx->intParam(0), ['PATCH'])),
            ApiRoute::regex('#^/api/probe/damage-warnings/(\d+)$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['PATCH'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeDamageWarningReadResponse($player, $ctx->intParam(0)))),
            ApiRoute::regex('#^/api/forum/categories/(\d+)$#', ['GET', 'PATCH', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET', 'PATCH', 'DELETE'], $ctx->headers, fn(Player $player): ApiResponse => match ($ctx->method) {
                'GET' => $this->forumController()->category($ctx->intParam(0)),
                'PATCH' => $this->forumController()->updateCategory($player, $ctx->intParam(0), $ctx->body),
                'DELETE' => $this->forumController()->deleteCategory($player, $ctx->intParam(0)),
            })),
            ApiRoute::regex('#^/api/forum/posts/(\d+)/messages$#', ['GET', 'POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET', 'POST'], $ctx->headers, fn(Player $player): ApiResponse => $ctx->method === 'POST'
                ? $this->forumController()->createMessage($player, $ctx->intParam(0), $ctx->body)
                : $this->forumController()->postMessages($ctx->intParam(0), $ctx->query))),
            ApiRoute::regex('#^/api/forum/posts/(\d+)$#', ['GET', 'PATCH', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET', 'PATCH', 'DELETE'], $ctx->headers, fn(Player $player): ApiResponse => match ($ctx->method) {
                'GET' => $this->forumController()->post($ctx->intParam(0), $ctx->query),
                'PATCH' => $this->forumController()->updatePost($player, $ctx->intParam(0), $ctx->body),
                'DELETE' => $this->forumController()->deletePost($player, $ctx->intParam(0)),
            })),
            ApiRoute::regex('#^/api/forum/messages/(\d+)$#', ['PATCH', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['PATCH', 'DELETE'], $ctx->headers, fn(Player $player): ApiResponse => $ctx->method === 'PATCH'
                ? $this->forumController()->updateMessage($player, $ctx->intParam(0), $ctx->body)
                : $this->forumController()->deleteMessage($player, $ctx->intParam(0)))),
            ApiRoute::path('/api/version', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->routeApiVersion($ctx->method)),
            ApiRoute::path('/api/session', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->routeSession($ctx->method, $ctx->body)),
            ApiRoute::path('/api/me', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => new ApiResponse(200, ['player' => $player->publicArray()]))),
            ApiRoute::path('/api/me/api-key', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->apiKeyResponse($player))),
            ApiRoute::path('/api/crafting-recipes', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $_player): ApiResponse => $this->craftingRecipesResponse())),
            ApiRoute::path('/api/probes', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeListResponse($player))),
            ApiRoute::path('/api/probe', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeResponse($player))),
            ApiRoute::path('/api/probe/mind-snapshot/reassign', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMindSnapshotReassignResponse($player))),
            ApiRoute::path('/api/probe/storage-containers', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeStorageContainersResponse($player))),
            ApiRoute::path('/api/probe/probe-improvements-available', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeImprovementsResponse($player, $ctx->query))),
            ApiRoute::path('/api/probe/storage-moves', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeStorageMoveResponse($player, $ctx->body))),
            ApiRoute::path('/api/probe/atomic-printer/craft', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeManniesController()->atomicPrinterCraft($player, $ctx->body))),
            ApiRoute::path('/api/probe/messages/sent', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeSentMessagesResponse($player, $ctx->query))),
            ApiRoute::path('/api/probe/messages', ['GET', 'POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET', 'POST'], $ctx->headers, fn(Player $player): ApiResponse => $ctx->method === 'POST' ? $this->probeMessageSendResponse($player, $ctx->body) : $this->probeMessagesResponse($player, $ctx->query))),
            ApiRoute::path('/api/probe/alerts', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeAlertsResponse($player))),
            ApiRoute::path('/api/probe/damage-warnings', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeDamageWarningsResponse($player))),
            ApiRoute::path('/api/probe/visited-sectors', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeVisitedSectorsResponse($player))),
            ApiRoute::path('/api/probe/sector', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeSectorResponse($player))),
            ApiRoute::path('/api/probe/mission', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMissionsResponse($player))),
            ApiRoute::path('/api/probe/missions', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMissionsResponse($player))),
            ApiRoute::path('/api/probe/move', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMoveResponse($player, $ctx->body))),
            ApiRoute::path('/api/probe/mannies', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeManniesController()->list($player))),
            ApiRoute::regex('#^/api/probe/(\d+)/storage-containers$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeStorageContainersResponse($player, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/probe-improvements-available$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeImprovementsResponse($player, $ctx->query, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/storage-moves$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeStorageMoveResponse($player, $ctx->body, $probe), $ctx->intParam(0), ['POST'])),
            ApiRoute::regex('#^/api/probe/(\d+)/atomic-printer/craft$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeManniesController()->atomicPrinterCraft($player, $ctx->body, $probe), $ctx->intParam(0), ['POST'])),
            ApiRoute::regex('#^/api/probe/(\d+)/messages$#', ['GET', 'POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute(
                $ctx,
                fn(Player $player, NeumannProbe $probe): ApiResponse => $ctx->method === 'POST'
                    ? $this->probeMessageSendResponse($player, $ctx->body, $probe)
                    : $this->probeMessagesResponse($player, $ctx->query, $probe),
                $ctx->intParam(0),
                ['GET', 'POST'],
            )),
            ApiRoute::regex('#^/api/probe/(\d+)/alerts$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeAlertsResponse($player, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/damage-warnings$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeDamageWarningsResponse($player, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/visited-sectors$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeVisitedSectorsResponse($player, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/sector$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeSectorResponse($player, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/move$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeMoveResponse($player, $ctx->body, $probe), $ctx->intParam(0), ['POST'])),
            ApiRoute::regex('#^/api/probe/(\d+)/mannies$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeManniesController()->list($player, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)$#', ['GET', 'PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute(
                $ctx->method,
                ['GET', 'PATCH'],
                $ctx->headers,
                fn(Player $player): ApiResponse => $ctx->method === 'PATCH'
                    ? $this->probeDefaultSelectionResponse($player, $ctx->intParam(0), $ctx->body)
                    : $this->probeByIdResponse($player, $ctx->intParam(0)),
            )),
            ApiRoute::path('/api/sector', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->sectorResponse($player, $ctx->query))),
            ApiRoute::path('/api/forum/categories', ['GET', 'POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET', 'POST'], $ctx->headers, fn(Player $player): ApiResponse => $ctx->method === 'POST' ? $this->forumController()->createCategory($player, $ctx->body) : $this->forumController()->categories())),
            ApiRoute::path('/api/forum/posts', ['GET', 'POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET', 'POST'], $ctx->headers, fn(Player $player): ApiResponse => $ctx->method === 'POST' ? $this->forumController()->createPost($player, $ctx->body) : $this->forumController()->posts($ctx->query))),
        ];
    }

    private function forumController(): ForumApiController
    {
        return $this->forumController ??= new ForumApiController($this->forum);
    }

    private function probeManniesController(): ProbeManniesApiController
    {
        return $this->probeManniesController ??= new ProbeManniesApiController(
            $this->probes,
            $this->movements,
            $this->mannies,
            $this->storage,
            $this->items,
            $this->probeManniesPresenter(),
        );
    }

    private function probeManniesPresenter(): ProbeManniesApiPresenter
    {
        return $this->probeManniesPresenter ??= new ProbeManniesApiPresenter(
            $this->mannies,
            $this->improvements,
            $this->gameplayConfig,
        );
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

    private function protectedProbeRoute(ApiRouteContext $ctx, callable $handler, int $probeId, array $allowedMethods): ApiResponse
    {
        return $this->protectedRoute(
            $ctx->method,
            $allowedMethods,
            $ctx->headers,
            function (Player $player) use ($handler, $probeId): ApiResponse {
                $probe = $this->routeProbe($player, $probeId);
                if ($probe instanceof ApiResponse) {
                    return $probe;
                }

                return $handler($player, $probe);
            },
        );
    }

    private function routeProbe(Player $player, int $probeId): NeumannProbe|ApiResponse
    {
        $defaultProbe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        if ($probeId === $defaultProbe->id) {
            return $defaultProbe;
        }

        $probe = $this->probes->findById($probeId);
        if ($probe === null || $probe->playerId !== $player->id) {
            return ApiResponse::error(404, 'not_found', 'Probe not found.');
        }

        $probe = $this->movements->refreshProbeMovementState($probe);
        if (!$this->scut->canSectorsCommunicate($defaultProbe->currentSector, $probe->currentSector)) {
            return ApiResponse::error(422, 'probe_not_in_same_sector', 'This probe can only be controlled when it is in the same sector as the default probe or inside the same SCUT network coverage.');
        }

        return $probe;
    }

    private function apiKeyResponse(Player $player): ApiResponse
    {
        return new ApiResponse(201, ['apiKey' => $this->auth->createApiKeyForPlayer($player)]);
    }

    private function craftingRecipesResponse(): ApiResponse
    {
        return new ApiResponse(200, ['recipes' => CraftingRecipeCatalog::all($this->gameplayConfig['crafting'] ?? [])]);
    }

    private function probeResponse(Player $player): ApiResponse
    {
        return $this->probeDetailsResponse($player, $this->requiredProbe($player));
    }

    private function probeByIdResponse(Player $player, int $probeId): ApiResponse
    {
        $probe = $this->probes->findById($probeId);
        if ($probe === null || $probe->playerId !== $player->id) {
            return ApiResponse::error(404, 'not_found', 'Probe not found.');
        }

        return $this->probeDetailsResponse($player, $probe);
    }

    private function probeDefaultSelectionResponse(Player $player, int $probeId, ?string $body = null): ApiResponse
    {
        $targetProbe = $this->probes->findById($probeId);
        if ($targetProbe === null || $targetProbe->playerId !== $player->id) {
            return ApiResponse::error(404, 'not_found', 'Probe not found.');
        }

        $data = null;
        if ($body !== null && trim($body) !== '') {
            $decoded = $this->decodeJsonBody($body);
            if (!is_array($decoded)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body is invalid.');
            }

            $data = $decoded;
        }

        $modified = false;

        // Handle renaming when provided in JSON body
        if (is_array($data) && array_key_exists('name', $data)) {
            if (!is_string($data['name'])) {
                return ApiResponse::error(400, 'bad_request', 'Probe name must be a string.');
            }

            $targetProbe->name = $data['name'];
            $this->probes->save($targetProbe);
            $modified = true;
        }

        // Determine whether we should perform the default selection.
        // Backwards compatibility: a PATCH with no body (legacy clients) should still switch the default probe.
        $shouldSetDefault = false;
        if ($body === null || trim((string) $body) === '') {
            // legacy behavior: empty body => set as default
            $shouldSetDefault = true;
        } elseif (is_array($data) && array_key_exists('isDefault', $data)) {
            // explicit isDefault field controls default selection
            $isDefaultVal = $data['isDefault'];
            $shouldSetDefault = ($isDefaultVal === true || $isDefaultVal === 1 || $isDefaultVal === '1');
        }

        if ($shouldSetDefault) {
            $currentProbe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
            $targetProbe = $this->movements->refreshProbeMovementState($targetProbe);
            if (!$this->scut->canSectorsCommunicate($currentProbe->currentSector, $targetProbe->currentSector)) {
                return ApiResponse::error(422, 'probe_not_in_same_sector', 'Default probe can only be changed when both probes are in the same sector or inside the same SCUT network coverage.');
            }

            $player->defaultProbeId = $targetProbe->id;
            $this->players->save($player);
            $modified = true;
        }

        return $this->probeListResponse($player);
    }

    private function probeDetailsResponse(Player $player, NeumannProbe $probe): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe);
        if ($probe->status === ProbeStatus::TrappedByBlackHole) {
            return new ApiResponse(200, [
                'probe' => [
                    'id' => $probe->id,
                    'name' => $probe->name,
                    'status' => 'trapped_by_black_hole',
                    'message' => 'The probe has crossed a black hole escape threshold. No signal or actuator response can be recovered.',
                    'alert' => $this->terminalProbeAlert($probe),
                    'fuel' => [
                        'deuterium' => $probe->deuteriumStock,
                        'maxDeuterium' => $this->mannies->maxDeuteriumPercentForProbe($probe),
                    ],
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
                    'fuel' => [
                        'deuterium' => $probe->deuteriumStock,
                        'maxDeuterium' => $this->mannies->maxDeuteriumPercentForProbe($probe),
                    ],
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

    private function probeListResponse(Player $player): ApiResponse
    {
        return new ApiResponse(200, [
            'defaultProbeId' => $player->defaultProbeId,
            'probes' => array_map(
                fn(NeumannProbe $probe): array => $this->probeSummaryArray($player, $probe),
                $this->probes->findAllByPlayerId($player->id),
            ),
        ]);
    }

    /**
     * @return array{id:int, name:string, status:string, isDefault:bool}
     */
    private function probeSummaryArray(Player $player, NeumannProbe $probe): array
    {
        $probe = $this->movements->refreshProbeMovementState($probe);

        return [
            'id' => $probe->id,
            'name' => $probe->name,
            'status' => $probe->status->value,
            'isDefault' => $player->defaultProbeId === $probe->id,
        ];
    }

    private function probeImprovementsResponse(Player $player, array $query, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe ??= $this->requiredProbe($player);
        $includeAll = $this->truthyQuery($query['includeAll'] ?? $query['all'] ?? null);

        return new ApiResponse(200, [
            'improvements' => $this->probeManniesPresenter()->probeImprovements($probe, $includeAll),
        ]);
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

    private function probeVisitedSectorsResponse(Player $player, ?NeumannProbe $probe = null): ApiResponse
    {
        $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
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

    private function probeSectorResponse(Player $player, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $movement = $this->movements->activeMovementForProbe($probe);
        $sensorMode = $this->movements->sensorModeFor($movement, $probe->status);
        if ($sensorMode === 'blind') {
            return ApiResponse::error(400, 'sensors_unavailable', 'External sensors are unavailable at current relativistic velocity.');
        }

        $observableSector = $this->movements->observableSectorFor($probe, $movement) ?? $probe->currentSector;
        if ($movement === null && $observableSector->equals($probe->currentSector)) {
            $this->movements->refreshCurrentSectorHazards($probe);
            $this->movements->ensureCurrentSectorIntelligentLifeScenarios($probe);
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

        $this->missions->completeReadyReturnToSpacePrograms($probe);
        $observation = $this->observations->observe($player, $probe, $observableSector)->toArray();
        $observation['sensorMode'] = $sensorMode;
        $observation['dataFreshness'] = 'live';
        $observation = $this->withBlackHoleTrapCountdown($observation, $probe);
        $observation = $this->withObservedProbePresence($observation, $probe, $observableSector);
        $observation = $this->withScutSectorData($player, $observation, $observableSector, includeRelays: true);

        return new ApiResponse(200, [
            'sector' => $observation,
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    private function probeInventoryItemResponse(Player $player, string $itemId, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe ??= $this->requiredProbe($player);
        $this->movements->ensureProbeOperational($probe);
        $item = $this->inventoryForProbe($probe)->findItem($itemId);

        if ($item === null) {
            return ApiResponse::error(404, 'not_found', 'Inventory item not found.');
        }

        return new ApiResponse(200, ['item' => $item->taskArray()]);
    }

    private function probeStorageContainersResponse(Player $player, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);

        return new ApiResponse(200, ['containers' => $this->storage->containersForProbe($probe)]);
    }

    private function probeStorageContainerResponse(Player $player, string $containerId, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);

        return new ApiResponse(200, $this->storage->containerInventory($probe, $containerId));
    }

    private function probeStorageContainerRenameResponse(Player $player, string $containerId, ?string $body, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['label']) || !is_string($data['label'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a storage container label.');
        }

        return new ApiResponse(200, [
            'container' => $this->storage->renameContainer($probe, $containerId, $data['label']),
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    private function probeStorageContainerRulesResponse(Player $player, string $containerId, ?string $body, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
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

    private function probeStorageMoveResponse(Player $player, ?string $body, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
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
            'manny' => $this->probeManniesPresenter()->manny($player, $probe, $manny),
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    private function probeMessageSendResponse(Player $player, ?string $body, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
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
            if (!$this->scut->canSectorsCommunicate($probe->currentSector, $recipientProbe->currentSector)) {
                return ApiResponse::error(422, 'probe_not_in_same_sector', 'Recipient probe must be in the same sector or inside the same SCUT network coverage.');
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
        $this->missions->handlePlanetReply($probe, $recipientPlanet['id'], $messageBody);

        return new ApiResponse(201, ['message' => $this->probeMessageArray($player, $message)]);
    }

    private function probeMessagesResponse(Player $player, array $query, ?NeumannProbe $probe = null): ApiResponse
    {
        return $this->probeMessageListResponse($player, $query, false, $probe);
    }

    private function probeSentMessagesResponse(Player $player, array $query, ?NeumannProbe $probe = null): ApiResponse
    {
        return $this->probeMessageListResponse($player, $query, true, $probe);
    }

    private function probeMessageListResponse(Player $player, array $query, bool $sent, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
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

    private function probeMessageReadResponse(Player $player, int $messageId, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $message = $this->messages->findById($messageId);
        if ($message === null || $message->recipientType !== ProbeMessage::ENDPOINT_PROBE || $message->recipientProbeId !== $probe->id) {
            return ApiResponse::error(404, 'not_found', 'Message not found.');
        }

        return new ApiResponse(200, ['message' => $this->probeMessageArray($player, $this->messages->markRead($message))]);
    }

    private function probeDamageWarningsResponse(Player $player, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $warnings = array_values(array_filter(
            $this->damageWarnings->findByProbeId($probe->id),
            static fn(ProbeDamageWarning $warning): bool => $warning->type === ProbeDamageWarning::TYPE_STORAGE_CONTAINER_BREAK,
        ));

        return new ApiResponse(200, [
            'damageWarnings' => array_map(
                fn(ProbeDamageWarning $warning): array => $this->probeAlertArray($player, $warning),
                $warnings,
            ),
            'rule' => $this->storageContainerBreakRule($probe),
        ]);
    }

    private function probeAlertsResponse(Player $player, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $alerts = $this->damageWarnings->findByProbeId($probe->id);

        return new ApiResponse(200, [
            'alerts' => array_map(
                fn(ProbeDamageWarning $alert): array => $this->probeAlertArray($player, $alert),
                $alerts,
            ),
            'rules' => $this->probeAlertRules($probe),
        ]);
    }

    private function probeAlertReadResponse(Player $player, int $alertId, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $alert = $this->damageWarnings->findByIdForProbe($alertId, $probe->id);
        if ($alert === null) {
            return ApiResponse::error(404, 'not_found', 'Alert not found.');
        }

        return new ApiResponse(200, [
            'alert' => $this->probeAlertArray($player, $this->damageWarnings->markRead($alert)),
        ]);
    }

    private function probeDamageWarningReadResponse(Player $player, int $warningId, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
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
    private function probeAlertRules(NeumannProbe $probe): array
    {
        return [
            'storageContainerBreak' => $this->storageContainerBreakRule($probe),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storageContainerBreakRule(?NeumannProbe $probe = null): array
    {
        $startsAtAdditionalContainers = 5 + $this->fragileContainerRiskDiscount($probe);

        return [
            'type' => ProbeDamageWarning::TYPE_STORAGE_CONTAINER_BREAK,
            'startsAtAdditionalContainers' => $startsAtAdditionalContainers,
            'riskPerAdditionalContainerAfterFourPercent' => 10,
            'maximumRiskPercent' => 100,
            'message' => 'From ' . $startsAtAdditionalContainers . ' additional containers onward, movement stress can break one container link. Risk is 10% at ' . $startsAtAdditionalContainers . ' containers, 20% at ' . ($startsAtAdditionalContainers + 1) . ', and continues up to 100%.',
        ];
    }

    private function fragileContainerRiskDiscount(?NeumannProbe $probe): int
    {
        if (
            $probe === null
            || $this->improvements === null
            || !$this->improvements->isDone($probe->id, ProbeImprovementCatalog::REINFORCED_CONTAINER_COUPLINGS)
        ) {
            return 0;
        }

        $definition = ProbeImprovementCatalog::find(
            ProbeImprovementCatalog::REINFORCED_CONTAINER_COUPLINGS,
            $this->gameplayConfig['probeImprovements'] ?? [],
        );
        $effects = is_array($definition['effects'] ?? null) ? $definition['effects'] : [];

        return max(0, (int) ($effects['fragileContainerRiskAdditionalContainerDiscount'] ?? ProbeImprovementCatalog::REINFORCED_CONTAINER_COUPLINGS_CONTAINER_RISK_DISCOUNT));
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

    private function probeInventoryJettisonResponse(Player $player, string $itemId, ?string $body, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
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
                $probe = $this->freshProbe($probe);

                return new ApiResponse(200, [
                    'inventory' => $this->inventoryForProbe($probe)->toArray(),
                    'manny' => $this->probeManniesPresenter()->manny($player, $probe, $manny),
                ]);
            }

            $jettisoned = $this->mannies->jettisonProbeItemFromProbe($probe, $itemId);
            $this->mannies->manniesForProbe($probe);
            $probe = $this->freshProbe($probe);
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
            $probe = $this->freshProbe($probe);

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

        if ($target->equals($probe->currentSector)) {
            $this->missions->completeReadyReturnToSpacePrograms($probe);
        }
        $observation = $this->observations->observe($player, $probe, $target)->toArray();
        $observation['sensorMode'] = $sensorMode;
        $observation['dataFreshness'] = $sensorMode === 'blind' ? 'historical' : ($sensorMode === 'degraded' ? 'degraded_live' : 'live');
        if ($movement === null && $target->equals($probe->currentSector)) {
            $observation = $this->withBlackHoleTrapCountdown($observation, $probe);
        }
        $includeRelays = ($observation['knowledgeLevel'] ?? null) === 'detailed' || (int) ($observation['distance'] ?? 999999) <= ScutRelay::RADIUS_SECTORS;
        $observation = $this->withScutSectorData($player, $observation, $target, $includeRelays);

        return new ApiResponse(200, ['sector' => $observation]);
    }

    private function probeMoveResponse(Player $player, ?string $body, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
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
        $movement = $this->movements->startMovement($probe, $target, $player);

        return new ApiResponse(202, ['movement' => $this->movementArray($player, $movement)]);
    }

    private function probeScutNetworkResponse(Player $player, int $networkId, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $network = $this->scut->networkById($networkId);
        if ($network === null) {
            return ApiResponse::error(404, 'not_found', 'SCUT network not found.');
        }
        if (!$this->scut->networkCoversSector($network->id, $probe->currentSector)) {
            return ApiResponse::error(403, 'forbidden', 'Probe must be inside this SCUT network coverage.');
        }

        $frame = new PlayerReferenceFrame($player->homeSector);
        $relays = array_map(
            fn(ScutRelay $relay): array => $this->scutRelayArray($player, $relay, includeSector: true, idAsString: false),
            $this->scut->relaysForNetwork($network->id),
        );
        $probes = array_map(
            static fn(NeumannProbe $coveredProbe): array => [
                'id' => $coveredProbe->id,
                'name' => $coveredProbe->name,
                'sector' => [
                    'relative' => $frame->globalToRelative($coveredProbe->currentSector),
                ],
            ],
            $this->scut->probesCoveredByNetwork($network->id),
        );

        return new ApiResponse(200, [
            'network' => $this->scutNetworkArray($network) + [
                'relayCount' => count($relays),
                'coveredSectorCount' => count($network->coveredSectors),
                'relays' => $relays,
                'probes' => $probes,
            ],
        ]);
    }

    private function probeMissionsResponse(Player $player): ApiResponse
    {
        return new ApiResponse(200, [
            'missions' => array_map(
                fn(Mission $mission): array => $this->missionArray($player, $mission),
                $this->missions->activeMissionsForPlayer($player->id),
            ),
        ]);
    }

    private function probeMissionAbandonResponse(Player $player, string $missionId): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $mission = $this->missions->abandonMission($probe, $missionId);

        return new ApiResponse(200, ['mission' => $this->missionArray($player, $mission)]);
    }

    private function requiredProbe(Player $player): NeumannProbe
    {
        return $this->probes->findByPlayerId($player->id) ?? throw new \RuntimeException('Probe not found.');
    }

    private function freshProbe(NeumannProbe $probe): NeumannProbe
    {
        return $this->probes->findById($probe->id) ?? $probe;
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

    private function withScutSectorData(Player $player, array $observation, SectorCoordinates $sector, bool $includeRelays): array
    {
        if ($includeRelays) {
            $relays = $this->scut->relaysInSector($sector);
            if ($relays !== []) {
                $objects = is_array($observation['objects'] ?? null) ? $observation['objects'] : [];
                foreach ($relays as $relay) {
                    $objects[] = $this->scutRelayArray($player, $relay, includeSector: false, idAsString: true);
                }
                $observation['objects'] = $objects;
            }
        }

        $networks = $this->scut->networksCoveringSector($sector);
        if ($networks !== []) {
            $observation['scutNetworks'] = array_map(
                fn(ScutNetwork $network): array => $this->scutNetworkReferenceArray($network),
                $networks,
            );
        } elseif (($observation['knowledgeLevel'] ?? null) === 'detailed') {
            $observation['scutNetworks'] = [];
        }

        return $observation;
    }

    private function scutRelayArray(Player $player, ScutRelay $relay, bool $includeSector, bool $idAsString): array
    {
        $network = $relay->networkId !== null ? $this->scut->networkById($relay->networkId) : null;
        $createdByProbeName = $this->scutRelayCreatorProbeName($relay);
        $payload = [
            'id' => $idAsString ? (string) $relay->id : $relay->id,
            'type' => 'scut_relay',
            'name' => 'Relais SCUT',
            'estimated' => false,
            'summary' => $relay->isOn() ? 'Active long-range SCUT communication relay.' : 'Inactive long-range SCUT communication relay.',
            'mass' => 0.0,
            'radius' => 0.0,
            'dangerLevel' => 'low',
            'status' => $relay->status,
            'createdByProbeId' => $relay->createdByProbeId,
            'createdByProbeName' => $createdByProbeName,
            'createdAt' => $relay->createdAt,
            'activatedAt' => $relay->activatedAt,
            'coverageRadiusSectors' => ScutRelay::RADIUS_SECTORS,
            'network' => $network !== null ? $this->scutNetworkReferenceArray($network) : null,
        ] + (!$relay->isOn() ? ['salvageable' => true] : []);
        if ($includeSector) {
            $payload['sector'] = [
                'relative' => (new PlayerReferenceFrame($player->homeSector))->globalToRelative($relay->sector),
            ];
        }

        return $payload;
    }

    private function scutRelayCreatorProbeName(ScutRelay $relay): ?string
    {
        if ($relay->createdByProbeId === null) {
            return null;
        }

        return $this->probes->findById($relay->createdByProbeId)?->name ?? 'death probe';
    }

    private function scutNetworkReferenceArray(ScutNetwork $network): array
    {
        return [
            'id' => $network->id,
            'name' => $network->name,
        ];
    }

    private function scutNetworkArray(ScutNetwork $network): array
    {
        return $this->scutNetworkReferenceArray($network) + [
            'createdAt' => $network->createdAt,
            'updatedAt' => $network->updatedAt,
        ];
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
            'fuel' => [
                'deuterium' => $probe->deuteriumStock,
                'maxDeuterium' => $this->mannies->maxDeuteriumPercentForProbe($probe),
            ],
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

    private function truthyQuery(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function missionArray(Player $player, Mission $mission): array
    {
        $metadata = $this->publicMissionData($player, $mission->metadata);
        $payload = [
            'id' => $mission->uid,
            'type' => $mission->type,
            'title' => $mission->title,
            'description' => $this->publicMissionDescription($mission, $metadata),
            'status' => $mission->status,
            'stepOrder' => $mission->stepOrder,
            'metadata' => $metadata,
            'createdByEvent' => $mission->createdByEvent === null ? null : $this->publicMissionData($player, $mission->createdByEvent),
            'startedAt' => $mission->startedAt,
            'completedAt' => $mission->completedAt,
            'failedAt' => $mission->failedAt,
            'abandonedAt' => $mission->abandonedAt,
            'createdAt' => $mission->createdAt,
            'updatedAt' => $mission->updatedAt,
            'steps' => array_map(fn(MissionStep $step): array => $this->missionStepArray($player, $step), $mission->steps),
        ];

        return $payload;
    }

    private function missionStepArray(Player $player, MissionStep $step): array
    {
        return [
            'id' => $step->uid,
            'sortOrder' => $step->sortOrder,
            'title' => $step->title,
            'description' => $step->description,
            'status' => $step->status,
            'metadata' => $this->publicMissionData($player, $step->metadata),
            'completedAt' => $step->completedAt,
            'failedAt' => $step->failedAt,
            'createdAt' => $step->createdAt,
            'updatedAt' => $step->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function publicMissionData(Player $player, array $data): array
    {
        $public = [];
        foreach ($data as $key => $value) {
            if ($key === 'planetName' && is_string($value)) {
                $public[$key] = $this->publicPlanetName($value, null, 'Monde habite');
                continue;
            }
            if ($key === 'sector' && is_array($value) && $this->isCoordinateArray($value)) {
                $public[$key] = [
                    'relative' => $this->relativeCoordinatesFromArray($player, $value),
                ];
                continue;
            }

            $public[$key] = is_array($value) ? $this->publicMissionNestedData($player, $value) : $value;
        }

        return $public;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function publicMissionNestedData(Player $player, array $data): array
    {
        $public = [];
        foreach ($data as $key => $value) {
            if ($key === 'sector' && is_array($value) && $this->isCoordinateArray($value)) {
                $public[$key] = [
                    'relative' => $this->relativeCoordinatesFromArray($player, $value),
                ];
                continue;
            }
            $public[$key] = is_array($value) ? $this->publicMissionNestedData($player, $value) : $value;
        }

        return $public;
    }

    /**
     * @param array<mixed> $value
     */
    private function isCoordinateArray(array $value): bool
    {
        return isset($value['x'], $value['y'], $value['z'])
            && is_numeric($value['x'])
            && is_numeric($value['y'])
            && is_numeric($value['z']);
    }

    /**
     * @param array<mixed> $coordinates
     * @return array{x:int, y:int, z:int}
     */
    private function relativeCoordinatesFromArray(Player $player, array $coordinates): array
    {
        return PlayerReferenceFrame::atGlobalCoordinates(
            $player->homeSector->getX(),
            $player->homeSector->getY(),
            $player->homeSector->getZ(),
        )->globalToRelative(new SectorCoordinates((int) $coordinates['x'], (int) $coordinates['y'], (int) $coordinates['z']));
    }

    /**
     * @param array<string, mixed> $publicMetadata
     */
    private function publicMissionDescription(Mission $mission, array $publicMetadata): ?string
    {
        if ($mission->type !== 'first_contact.return_to_space_program') {
            return $mission->description;
        }

        $planetName = is_string($publicMetadata['planetName'] ?? null) ? $publicMetadata['planetName'] : 'Monde habite';
        $signal = is_string($publicMetadata['initialSignal'] ?? null) ? $publicMetadata['initialSignal'] : MissionService::FIRST_CONTACT_SIGNAL;
        $relative = is_array($publicMetadata['sector']['relative'] ?? null) ? $publicMetadata['sector']['relative'] : null;
        $sector = $relative !== null
            ? 'secteur relatif ' . (int) ($relative['x'] ?? 0) . ':' . (int) ($relative['y'] ?? 0) . ':' . (int) ($relative['z'] ?? 0)
            : 'secteur detecte';

        return 'Un signal bref venu de la planete ' . $planetName . ', ' . $sector . ', semble s\'adresser a votre sonde. Il contient "' . $signal . '".';
    }

    private function probeMessageArray(Player $player, ProbeMessage $message, bool $includeReadState = true): array
    {
        $payload = [
            'id' => $message->id,
            'sender' => $this->probeMessageEndpointArray($message->senderType, $message->senderId, $message->senderName, $message->senderProbeId, $message->sector),
            'recipient' => $this->probeMessageEndpointArray($message->recipientType, $message->recipientId, $message->recipientName, $message->recipientProbeId, $message->sector),
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

    private function probeMessageEndpointArray(string $type, string $id, ?string $name, ?int $probeId, SectorCoordinates $sector): array
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
        if ($type === ProbeMessage::ENDPOINT_UNKNOWN) {
            return [
                'type' => ProbeMessage::ENDPOINT_UNKNOWN,
                'id' => $id,
                'name' => $name !== null && trim($name) !== '' ? $name : 'Unknown sender',
            ];
        }

        return [
            'type' => ProbeMessage::ENDPOINT_PLANET,
            'id' => $id,
            'planetId' => $id,
            'name' => $this->publicPlanetName($name, $sector, 'Monde habite'),
        ];
    }

    private function publicPlanetName(?string $name, ?SectorCoordinates $sector, string $fallback): string
    {
        if ($name !== null && trim($name) !== '' && !$this->nameContainsAbsoluteCoordinates($name, $sector)) {
            return $name;
        }

        return $fallback;
    }

    /**
     * @param array{x: int, y: int, z: int} $coordinates
     */
    private function coordinateLabel(array $coordinates): string
    {
        return (string) ($coordinates['x'] ?? 0)
            . ':' . (string) ($coordinates['y'] ?? 0)
            . ':' . (string) ($coordinates['z'] ?? 0);
    }

    private function percentLabel(float $percent): string
    {
        $rounded = round($percent, 2);

        return (abs($rounded - round($rounded)) < 0.0001 ? (string) (int) round($rounded) : (string) $rounded) . '%';
    }

    private function nameContainsAbsoluteCoordinates(string $name, ?SectorCoordinates $sector): bool
    {
        if ($sector !== null) {
            $absoluteKey = $sector->toKey();
            if (
                str_contains($name, $absoluteKey)
                || str_contains($name, str_replace(':', '-', $absoluteKey))
                || str_contains($name, str_replace(':', ' ', $absoluteKey))
            ) {
                return true;
            }
        }

        return preg_match('/-?\d+:-?\d+:-?\d+/', $name) === 1
            || preg_match('/--?\d+-\d+-\d+/', $name) === 1
            || preg_match('/-?\d+\s+-?\d+\s+-?\d+/', $name) === 1;
    }

    private function probeAlertArray(Player $player, ProbeDamageWarning $warning): array
    {
        $frame = new PlayerReferenceFrame($player->homeSector);
        $sector = new SectorCoordinates($warning->sectorX, $warning->sectorY, $warning->sectorZ);
        $relativeSector = $frame->globalToRelative($sector);

        $alert = [
            'id' => $warning->id,
            'type' => $warning->type,
            'status' => $warning->status,
            'message' => $warning->message,
            'phase' => $warning->phase,
            'scheduledAt' => $warning->scheduledAt,
            'sector' => [
                'relative' => $relativeSector,
            ],
            'createdAt' => $warning->createdAt,
            'updatedAt' => $warning->updatedAt,
            'readAt' => $warning->readAt,
            'resolvedAt' => $warning->resolvedAt,
        ];

        if ($warning->type === ProbeDamageWarning::TYPE_STORAGE_CONTAINER_BREAK) {
            $startsAtAdditionalContainers = 5 + $this->fragileContainerRiskDiscount($this->probes->findById($warning->probeId));
            $alert['container'] = [
                'id' => $warning->containerId,
                'label' => $warning->containerLabel,
                'detachedObjectId' => $warning->objectId,
            ];
            $alert['risk'] = [
                'percent' => $warning->riskPercent,
                'additionalContainerCount' => $warning->additionalContainerCount,
                'ruleStartsAtAdditionalContainers' => $startsAtAdditionalContainers,
            ];
            $when = $warning->phase === 'deceleration_start' ? 'arrival sector' : 'origin sector';
            $alert['message'] = 'Fragile storage warning: '
                . ($warning->containerLabel !== '' ? $warning->containerLabel : 'Detached container')
                . ' may break loose during this jump near the ' . $when
                . ' (relative sector ' . $this->coordinateLabel($relativeSector) . '). Risk: '
                . $this->percentLabel($warning->riskPercent)
                . '. This can happen from ' . $startsAtAdditionalContainers . ' additional containers onward.';
        }

        if ($warning->type === ProbeDamageWarning::TYPE_INTELLIGENT_LIFE) {
            $planetName = $this->publicPlanetName(
                $warning->containerLabel !== '' ? $warning->containerLabel : null,
                $sector,
                'Monde habite',
            );
            $alert['planet'] = [
                'id' => $warning->objectId,
                'name' => $planetName,
            ];
            $alert['message'] = 'Intelligent life detected: technological signatures confirmed on '
                . $planetName
                . ' in relative sector '
                . (int) ($relativeSector['x'] ?? 0)
                . ':' . (int) ($relativeSector['y'] ?? 0)
                . ':' . (int) ($relativeSector['z'] ?? 0)
                . '.';
        }

        if ($warning->type === ProbeDamageWarning::TYPE_SECTOR_OBJECT_DETECTED) {
            $objectType = $warning->containerId !== '' ? $warning->containerId : 'object';
            $alert['object'] = [
                'id' => $warning->objectId,
                'type' => $objectType,
                'label' => $warning->containerLabel !== '' ? $warning->containerLabel : null,
                'resourceTypes' => $objectType === 'asteroid' ? ['deuterium'] : [],
            ];
        }

        if ($warning->type === ProbeDamageWarning::TYPE_MANNY_REPORT) {
            $alert['report'] = [
                'title' => 'Manny report',
                'objectId' => $warning->objectId,
                'objectType' => $warning->containerId !== '' ? $warning->containerId : 'object',
                'objectLabel' => $warning->containerLabel !== '' ? $warning->containerLabel : null,
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
