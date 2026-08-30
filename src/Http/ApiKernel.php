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
use VonNeumannGame\Domain\ProbeLogbookPage;
use VonNeumannGame\Domain\ProbeMessage;
use VonNeumannGame\Domain\ProbeModel;
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
use VonNeumannGame\Repository\OthersAuditRepository;
use VonNeumannGame\Repository\OthersIdempotencyRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\ProbeImprovementRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\ProbeLogbookRepository;
use VonNeumannGame\Repository\ProbeMessageRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\RateLimit\TokenRateLimiter;
use VonNeumannGame\Service\MannyActionException;
use VonNeumannGame\Service\MannyService;
use VonNeumannGame\Service\MissionService;
use VonNeumannGame\Service\ObservationAccessException;
use VonNeumannGame\Service\OthersActionException;
use VonNeumannGame\Service\OthersService;
use VonNeumannGame\Service\ProbeMovementException;
use VonNeumannGame\Service\ProbeMovementService;
use VonNeumannGame\Service\ProbeReinstantiationException;
use VonNeumannGame\Service\ProbeReinstantiationService;
use VonNeumannGame\Service\ProbeStorageService;
use VonNeumannGame\Service\ScutNetworkService;
use VonNeumannGame\Service\SectorObservationService;
use VonNeumannGame\Service\AsteroidTrajectory\AsteroidTrajectoryException;
use VonNeumannGame\Service\AsteroidTrajectory\AsteroidTrajectoryService;
use VonNeumannGame\Service\AutonomousUnitObservationService;
use VonNeumannGame\Sector\InvalidSectorCoordinatesException;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;

final class ApiKernel
{
    /** Bump when the public API contract changes. */
    public const API_VERSION = 128;
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
        private readonly ProbeLogbookRepository $logbook,
        private readonly ProbeDamageWarningRepository $damageWarnings,
        private readonly ForumRepository $forum,
        private readonly MissionService $missions,
        private readonly ProbeReinstantiationService $reinstantiation,
        private readonly ScutNetworkService $scut,
        private readonly array $gameplayConfig = [],
        private readonly ?ProbeImprovementRepository $improvements = null,
        private readonly ?TokenRateLimiter $rateLimiter = null,
        private readonly ?AsteroidTrajectoryService $asteroidTrajectories = null,
        private readonly ?OthersRepository $others = null,
        private readonly ?OthersIdempotencyRepository $othersIdempotency = null,
        private readonly ?OthersAuditRepository $othersAudit = null,
        private readonly ?OthersService $othersService = null,
        private readonly ?AutonomousUnitObservationService $autonomousUnits = null,
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
        } catch (AsteroidTrajectoryException $e) {
            return ApiResponse::error($e->httpStatus, $e->errorCode, $e->getMessage());
        } catch (OthersActionException $e) {
            return ApiResponse::error($e->httpStatus, $e->errorCode, $e->getMessage());
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
            ApiRoute::regex('#^/api/others/alerts/([^/]+)$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersAlertReadResponse($player, $ctx->stringParam(0)))),
            ApiRoute::path('/api/others/alerts', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersAlertsResponse($player, $ctx->query))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/missiles$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersCommand($ctx, $player, fn(): ApiResponse => $this->othersMissileCreateResponse($player, $ctx->stringParam(0), $ctx->body)))),
            ApiRoute::regex('#^/api/others/missiles/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->missileResponse($player, $ctx->stringParam(0)))),
            ApiRoute::regex('#^/api/probe/(\d+)/missiles$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->othersCommand($ctx, $player, fn(): ApiResponse => $this->probeMissileCreateResponse($player, $probe, $ctx->body)), $ctx->intParam(0), ['POST'])),
            ApiRoute::regex('#^/api/probe/(\d+)/mannies/([^/]+)/ignite_missile$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeMannyMissileCreateResponse($player, $probe, $ctx->stringParam(1), $ctx->body), $ctx->intParam(0), ['POST'])),
            ApiRoute::regex('#^/api/probe/(\d+)/missiles/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $_probe): ApiResponse => $this->missileResponse($player, $ctx->stringParam(1)), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/weapons/laser$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersCommand($ctx, $player, fn(): ApiResponse => $this->othersLaserResponse($player, $ctx->stringParam(0), $ctx->body)))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/crafts$#', ['GET', 'POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $ctx->method === 'POST' ? $this->othersCommand($ctx, $player, fn(): ApiResponse => $this->othersCraftCreateResponse($player, $ctx->stringParam(0), $ctx->body)) : $this->othersCraftsResponse($player, $ctx->stringParam(0)))),
            ApiRoute::regex('#^/api/others/crafts/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersCraftResponse($player, $ctx->stringParam(0)))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/harvest$#', ['POST', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersCommand($ctx, $player, fn(): ApiResponse => $ctx->method === 'POST' ? $this->othersHarvestCreateResponse($player, $ctx->stringParam(0), $ctx->body) : $this->othersHarvestCancelResponse($player, $ctx->stringParam(0))))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/auxiliaries/tasks$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersCommand($ctx, $player, fn(): ApiResponse => $this->othersAuxiliaryBatchResponse($player, $ctx->stringParam(0), $ctx->body)))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/auxiliaries/([^/]+)/(mine|recall|recover-dormant-auxiliary)$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersCommand($ctx, $player, fn(): ApiResponse => $this->othersAuxiliaryTaskResponse($player, $ctx->stringParam(0), $ctx->stringParam(1), $ctx->stringParam(2), $ctx->body)))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/inventory-transfers$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersCommand($ctx, $player, fn(): ApiResponse => $this->othersInventoryTransferCreateResponse($player, $ctx->stringParam(0), $ctx->body)))),
            ApiRoute::regex('#^/api/others/inventory-transfers/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersInventoryTransferResponse($player, $ctx->stringParam(0)))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/auxiliaries/([^/]+)/transfer-deuterium$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersCommand($ctx, $player, fn(): ApiResponse => $this->othersDeuteriumTransferResponse($player, $ctx->stringParam(0), $ctx->stringParam(1), $ctx->body)))),
            ApiRoute::regex('#^/api/probe/(\d+)/sector/autonomous-units$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeAutonomousUnitsResponse($player, $ctx->intParam(0), $ctx->query))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/sector/autonomous-units$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersAutonomousUnitsResponse($player, $ctx->stringParam(0), $ctx->query))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/move$#', ['POST', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersCommand($ctx, $player, fn(): ApiResponse => $ctx->method === 'POST' ? $this->othersShipMoveResponse($player, $ctx->stringParam(0), $ctx->body) : $this->othersShipMoveCancelResponse($player, $ctx->stringParam(0))))),
            ApiRoute::regex('#^/api/others/fleets/([^/]+)/move$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersCommand($ctx, $player, fn(): ApiResponse => $this->othersFleetMoveResponse($player, $ctx->stringParam(0), $ctx->body)))),
            ApiRoute::regex('#^/api/others/fleets/([^/]+)/visited-sectors$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersFleetVisitedSectorsResponse($player, $ctx->stringParam(0), $ctx->query))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/auxiliaries/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersAuxiliaryResponse($player, $ctx->stringParam(0), $ctx->stringParam(1)))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/auxiliaries$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersAuxiliariesResponse($player, $ctx->stringParam(0), $ctx->query))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)/inventory$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersInventoryResponse($player, $ctx->stringParam(0)))),
            ApiRoute::regex('#^/api/others/ships/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersShipResponse($player, $ctx->stringParam(0)))),
            ApiRoute::regex('#^/api/others/fleets/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersFleetResponse($player, $ctx->stringParam(0)))),
            ApiRoute::regex('#^/api/others/actions/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersActionResponse($player, $ctx->stringParam(0)))),
            ApiRoute::path('/api/others/crafting/recipes', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersRecipesResponse())),
            ApiRoute::path('/api/others/fleets', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersFleetsResponse($player))),
            ApiRoute::path('/api/others', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedOthersRoute($ctx, fn(Player $player): ApiResponse => $this->othersOverviewResponse($player))),
            ApiRoute::regex('#^/api/probe/(\d+)/asteroids/([^/]+)/trajectories$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->asteroidTrajectoryCreateResponse($player, $probe, $ctx->stringParam(1), $ctx->body), $ctx->intParam(0), ['POST'])),
            ApiRoute::regex('#^/api/probe/(\d+)/asteroid-trajectories/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->asteroidTrajectoryResponse($probe, $ctx->stringParam(1)), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/storage-containers/([^/]+)/crafting-reservations/reassign$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeStorageCraftingReservationsReassignResponse($player, $ctx->stringParam(1), $probe), $ctx->intParam(0), ['POST'])),
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
            ApiRoute::regex('#^/api/probe/(\d+)/mannies/([^/]+)/(repair|mine|motorize-asteroid|refuel-motorized-asteroid|sculpt-duck-asteroid|craft|salvage|install-bookmark|detach-storage-container|drop-storage-container|drop-manny-cargo|inspect-sector-object|recover-storage-container|refill-deuterium-tank|transfer-deuterium-to-probe|transfer-to-probe|turn-on-relay|install-scut-transit-beacon|improve-probe|assemble-probe|recall)$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeManniesController()->action($player, $ctx->stringParam(1), $ctx->params[2], $ctx->body, $probe), $ctx->intParam(0), ['POST'])),
            ApiRoute::regex('#^/api/probe/(\d+)/mannies/tasks$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeManniesController()->batchActions($player, $ctx->body, $probe), $ctx->intParam(0), ['POST'])),
            ApiRoute::regex('#^/api/probe/mannies/([^/]+)/(repair|mine|craft|salvage|install-bookmark|detach-storage-container|drop-storage-container|drop-manny-cargo|inspect-sector-object|recover-storage-container|refill-deuterium-tank|transfer-deuterium-to-probe|transfer-to-probe|turn-on-relay|install-scut-transit-beacon|improve-probe|assemble-probe|recall)$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeManniesController()->action($player, $ctx->stringParam(0), $ctx->params[1], $ctx->body))),
            ApiRoute::regex('#^/api/probe/(\d+)/scut-network/(\d+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeScutNetworkResponse($player, $ctx->intParam(1), $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/scut-network/(\d+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeScutNetworkResponse($player, $ctx->intParam(0)))),
            ApiRoute::regex('#^/api/probe/(\d+)/mannies/([^/]+)$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeManniesController()->rename($player, $ctx->stringParam(1), $ctx->body, $probe), $ctx->intParam(0), ['PATCH'])),
            ApiRoute::regex('#^/api/probe/(\d+)/mannies/([^/]+)$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeManniesController()->detail($player, $ctx->stringParam(1), $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/mannies/([^/]+)$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['PATCH'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeManniesController()->rename($player, $ctx->stringParam(0), $ctx->body))),
            ApiRoute::regex('#^/api/probe/missions/([^/]+)/abandon$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMissionAbandonResponse($player, $ctx->stringParam(0)))),
            ApiRoute::regex('#^/api/probe/(\d+)/messages/(\d+)/read$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeMessageReadResponse($player, $ctx->intParam(1), $probe), $ctx->intParam(0), ['PATCH'])),
            ApiRoute::regex('#^/api/probe/messages/(\d+)/read$#', ['PATCH'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['PATCH'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMessageReadResponse($player, $ctx->intParam(0)))),
            ApiRoute::regex('#^/api/probe/(\d+)/logbook-pages/(\d+)$#', ['GET', 'PATCH', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute(
                $ctx,
                fn(Player $player, NeumannProbe $probe): ApiResponse => match ($ctx->method) {
                    'GET' => $this->probeLogbookPageResponse($player, $ctx->intParam(1), $probe),
                    'PATCH' => $this->probeLogbookPageUpdateResponse($player, $ctx->intParam(1), $ctx->body, $probe),
                    'DELETE' => $this->probeLogbookPageDeleteResponse($player, $ctx->intParam(1), $probe),
                },
                $ctx->intParam(0),
                ['GET', 'PATCH', 'DELETE'],
            )),
            ApiRoute::regex('#^/api/probe/(\d+)/logbook-page/(\d+)$#', ['GET', 'PATCH', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute(
                $ctx,
                fn(Player $player, NeumannProbe $probe): ApiResponse => match ($ctx->method) {
                    'GET' => $this->probeLogbookPageResponse($player, $ctx->intParam(1), $probe),
                    'PATCH' => $this->probeLogbookPageUpdateResponse($player, $ctx->intParam(1), $ctx->body, $probe),
                    'DELETE' => $this->probeLogbookPageDeleteResponse($player, $ctx->intParam(1), $probe),
                },
                $ctx->intParam(0),
                ['GET', 'PATCH', 'DELETE'],
            )),
            ApiRoute::regex('#^/api/probe/(\d+)/alerts/(\d+)$#', ['PATCH', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute(
                $ctx,
                fn(Player $player, NeumannProbe $probe): ApiResponse => $ctx->method === 'DELETE'
                    ? $this->probeAlertDeleteResponse($player, $ctx->intParam(1), $probe)
                    : $this->probeAlertReadResponse($player, $ctx->intParam(1), $probe),
                $ctx->intParam(0),
                ['PATCH', 'DELETE'],
            )),
            ApiRoute::regex('#^/api/probe/alerts/(\d+)$#', ['PATCH', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute(
                $ctx->method,
                ['PATCH', 'DELETE'],
                $ctx->headers,
                fn(Player $player): ApiResponse => $ctx->method === 'DELETE'
                    ? $this->probeAlertDeleteResponse($player, $ctx->intParam(0))
                    : $this->probeAlertReadResponse($player, $ctx->intParam(0)),
            )),
            ApiRoute::regex('#^/api/probe/(\d+)/damage-warnings/(\d+)$#', ['PATCH', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute(
                $ctx,
                fn(Player $player, NeumannProbe $probe): ApiResponse => $ctx->method === 'DELETE'
                    ? $this->probeDamageWarningDeleteResponse($player, $ctx->intParam(1), $probe)
                    : $this->probeDamageWarningReadResponse($player, $ctx->intParam(1), $probe),
                $ctx->intParam(0),
                ['PATCH', 'DELETE'],
            )),
            ApiRoute::regex('#^/api/probe/damage-warnings/(\d+)$#', ['PATCH', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute(
                $ctx->method,
                ['PATCH', 'DELETE'],
                $ctx->headers,
                fn(Player $player): ApiResponse => $ctx->method === 'DELETE'
                    ? $this->probeDamageWarningDeleteResponse($player, $ctx->intParam(0))
                    : $this->probeDamageWarningReadResponse($player, $ctx->intParam(0)),
            )),
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
            ApiRoute::path('/api/visited-sectors', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->visitedSectorsResponse($player))),
            ApiRoute::path('/api/probe', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeResponse($player))),
            ApiRoute::path('/api/probe/mind-snapshot/reassign', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMindSnapshotReassignResponse($player))),
            ApiRoute::path('/api/probe/storage-containers', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeStorageContainersResponse($player))),
            ApiRoute::path('/api/probe/probe-improvements-available', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeImprovementsResponse($player, $ctx->query))),
            ApiRoute::path('/api/probe/storage-moves', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeStorageMoveResponse($player, $ctx->body))),
            ApiRoute::path('/api/probe/atomic-printer/craft', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeManniesController()->atomicPrinterCraft($player, $ctx->body))),
            ApiRoute::path('/api/probe/messages/sent', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeSentMessagesResponse($player, $ctx->query))),
            ApiRoute::path('/api/probe/messages', ['GET', 'POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET', 'POST'], $ctx->headers, fn(Player $player): ApiResponse => $ctx->method === 'POST' ? $this->probeMessageSendResponse($player, $ctx->body) : $this->probeMessagesResponse($player, $ctx->query))),
            ApiRoute::path('/api/probe/alerts', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeAlertsResponse($player, $ctx->query))),
            ApiRoute::path('/api/probe/damage-warnings', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeDamageWarningsResponse($player))),
            ApiRoute::path('/api/probe/visited-sectors', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeVisitedSectorsResponse($player))),
            ApiRoute::path('/api/probe/sector', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeSectorResponse($player))),
            ApiRoute::path('/api/probe/mission', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMissionsResponse($player))),
            ApiRoute::path('/api/probe/missions', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMissionsResponse($player))),
            ApiRoute::path('/api/probe/move', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['POST'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeMoveResponse($player, $ctx->body))),
            ApiRoute::path('/api/probe/mannies', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedRoute($ctx->method, ['GET'], $ctx->headers, fn(Player $player): ApiResponse => $this->probeManniesController()->list($player))),
            ApiRoute::regex('#^/api/probe/(\d+)/storage-containers$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeStorageContainersResponse($player, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/probe-improvements-available$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeImprovementsResponse($player, $ctx->query, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/probe-improvement-blueprints/([^/]+)/share$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute(
                $ctx,
                fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeImprovementBlueprintShareResponse($player, $probe, $ctx->stringParam(1), $ctx->body),
                $ctx->intParam(0),
                ['POST'],
            )),
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
            ApiRoute::regex('#^/api/probe/(\d+)/logbook-pages$#', ['GET', 'POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute(
                $ctx,
                fn(Player $player, NeumannProbe $probe): ApiResponse => $ctx->method === 'POST'
                    ? $this->probeLogbookPageCreateResponse($player, $ctx->body, $probe)
                    : $this->probeLogbookPagesResponse($player, $ctx->query, $probe),
                $ctx->intParam(0),
                ['GET', 'POST'],
            )),
            ApiRoute::regex('#^/api/probe/(\d+)/logbook-page$#', ['POST'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeLogbookPageCreateResponse($player, $ctx->body, $probe), $ctx->intParam(0), ['POST'])),
            ApiRoute::regex('#^/api/probe/(\d+)/alerts$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeAlertsResponse($player, $ctx->query, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/damage-warnings$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeDamageWarningsResponse($player, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/visited-sectors$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeVisitedSectorsResponse($player, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/sector$#', ['GET'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute($ctx, fn(Player $player, NeumannProbe $probe): ApiResponse => $this->probeSectorResponse($player, $probe), $ctx->intParam(0), ['GET'])),
            ApiRoute::regex('#^/api/probe/(\d+)/move$#', ['POST', 'DELETE'], fn(ApiRouteContext $ctx): ApiResponse => $this->protectedProbeRoute(
                $ctx,
                fn(Player $player, NeumannProbe $probe): ApiResponse => $ctx->method === 'DELETE'
                    ? $this->probeMoveCancelResponse($probe)
                    : $this->probeMoveResponse($player, $ctx->body, $probe),
                $ctx->intParam(0),
                ['POST', 'DELETE'],
            )),
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

        $authorization = $this->authorizationHeader($headers);
        $player = $this->auth->getPlayerFromBearerToken($authorization);
        if ($player === null) {
            return ApiResponse::error(401, 'unauthorized', 'Missing or invalid bearer token');
        }

        $token = $this->bearerToken($authorization);
        if ($this->rateLimiter === null || $token === null) {
            return $handler($player);
        }

        $decision = $this->rateLimiter->check($token);
        if (!$decision->available) {
            return $handler($player);
        }

        $headers = [
            'Content-Type' => 'application/json',
            'X-RateLimit-Limit' => (string) $decision->limit,
            'X-RateLimit-Remaining' => (string) $decision->remaining,
            'X-RateLimit-Reset' => (string) $decision->resetAt,
        ];
        if (!$decision->allowed) {
            $headers['Retry-After'] = (string) $decision->retryAfterSeconds;
            $error = ApiResponse::error(429, 'rate_limit_exceeded', 'Too many API requests for this bearer token.');

            return new ApiResponse($error->status, $error->body, $headers);
        }

        $response = $handler($player);

        return new ApiResponse($response->status, $response->body, array_merge($response->headers, $headers));
    }

    private function protectedOthersRoute(ApiRouteContext $ctx, callable $handler): ApiResponse
    {
        return $this->protectedRoute($ctx->method, [$ctx->method], $ctx->headers, function (Player $player) use ($handler): ApiResponse {
            if (!$player->canControlOthers) {
                return ApiResponse::error(403, 'others_permission_required', 'This account is not allowed to control Others fleets.');
            }
            if ($this->others === null) {
                return ApiResponse::error(503, 'others_unavailable', 'Others operations are unavailable.');
            }

            return $handler($player);
        });
    }

    private function othersOverviewResponse(Player $player): ApiResponse
    {
        $fleets = $this->others?->findFleetSummariesByPlayerId($player->id) ?? [];
        return new ApiResponse(200, ['others' => [
            'fleetCount' => count($fleets),
            'shipCount' => array_sum(array_map(static fn(array $row): int => (int) $row['ship_count'], $fleets)),
            'auxiliaryCount' => array_sum(array_map(static fn(array $row): int => (int) $row['auxiliary_count'], $fleets)),
            'activeActionCount' => array_sum(array_map(static fn(array $row): int => (int) $row['active_action_count'], $fleets)),
        ]]);
    }

    private function othersAlertsResponse(Player $player, array $query): ApiResponse
    {
        $status = isset($query['status']) ? trim((string) $query['status']) : null;
        if ($status !== null && $status !== 'unread') {
            return ApiResponse::error(400, 'bad_request', 'status must be unread');
        }
        $alerts = $this->others?->findAlertsForPlayer($player->id, $status === 'unread') ?? [];

        return new ApiResponse(200, ['alerts' => array_map(fn(array $alert): array => $this->presentOthersAlert($alert), $alerts)]);
    }

    private function othersAlertReadResponse(Player $player, string $alertId): ApiResponse
    {
        $alert = $this->others?->findAlertForPlayer($alertId, $player->id);
        if ($alert === null) {
            return ApiResponse::error(404, 'others_alert_not_found', 'Others alert not found.');
        }

        return new ApiResponse(200, ['alert' => $this->presentOthersAlert($this->others->markAlertRead($alert))]);
    }

    private function othersFleetsResponse(Player $player): ApiResponse
    {
        $rows = $this->others?->findFleetSummariesByPlayerId($player->id) ?? [];
        return new ApiResponse(200, ['fleets' => array_map(fn(array $row): array => $this->presentOthersFleetSummary($row), $rows)]);
    }

    private function othersFleetResponse(Player $player, string $fleetId): ApiResponse
    {
        $fleet = $this->others?->findFleetForPlayer($fleetId, $player->id);
        if ($fleet === null) {
            return ApiResponse::error(404, 'others_fleet_not_found', 'Others fleet not found.');
        }
        $ships = $this->others?->findShipsByFleetId((int) $fleet['id']) ?? [];
        $actions = $this->others?->findActiveAuxiliaryTasksByFleetId((int) $fleet['id']) ?? [];
        return new ApiResponse(200, ['fleet' => [
            'id' => (string) $fleet['public_id'],
            'status' => (string) $fleet['status'],
            'ships' => array_map(fn(array $ship): array => $this->presentOthersShip($player, $ship), $ships),
            'activeActions' => array_map(fn(array $action): array => $this->presentOthersAction($action), $actions),
            'createdAt' => (string) $fleet['created_at'],
            'updatedAt' => (string) $fleet['updated_at'],
        ]]);
    }

    private function othersFleetVisitedSectorsResponse(Player $player, string $fleetId, array $query): ApiResponse
    {
        $fleet = $this->others?->findFleetForPlayer($fleetId, $player->id);
        if ($fleet === null) {
            return ApiResponse::error(404, 'others_fleet_not_found', 'Others fleet not found.');
        }
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 100;
        if ($limit < 1 || $limit > 500) {
            return ApiResponse::error(400, 'bad_request', 'limit must be between 1 and 500.');
        }
        $cursor = null;
        if (isset($query['cursor'])) {
            $decoded = base64_decode((string) $query['cursor'], true);
            $cursor = $decoded !== false ? json_decode($decoded, true) : null;
            if (
                !is_array($cursor)
                || !is_string($cursor['lastVisitedAt'] ?? null)
                || !is_int($cursor['id'] ?? null)
                || $cursor['lastVisitedAt'] === ''
                || $cursor['id'] <= 0
            ) {
                return ApiResponse::error(400, 'bad_request', 'cursor is invalid.');
            }
        }
        $page = $this->others?->findFleetVisitedSectorsPage((int) $fleet['id'], $cursor, $limit) ?? ['rows' => [], 'nextCursor' => null];
        $frame = new PlayerReferenceFrame($player->homeSector);
        $response = ['visitedSectors' => array_map(
            static fn(array $row): array => [
                'relativeCoordinates' => $frame->globalToRelative(new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z'])),
                'firstVisitedAt' => (string) $row['first_visited_at'],
                'lastVisitedAt' => (string) $row['last_visited_at'],
                'visitCount' => (int) $row['visit_count'],
            ],
            $page['rows'],
        )];
        if ($page['nextCursor'] !== null) {
            $response['nextCursor'] = base64_encode(json_encode($page['nextCursor'], JSON_THROW_ON_ERROR));
        }

        return new ApiResponse(200, $response);
    }

    private function othersShipResponse(Player $player, string $shipId): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        return $ship === null
            ? ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.')
            : new ApiResponse(200, ['ship' => $this->presentOthersShip($player, $ship)]);
    }

    private function othersAuxiliariesResponse(Player $player, string $shipId, array $query): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) {
            return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.');
        }
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 100;
        if ($limit < 1 || $limit > 500) {
            return ApiResponse::error(400, 'bad_request', 'limit must be between 1 and 500.');
        }
        $cursor = null;
        if (isset($query['cursor'])) {
            $decoded = base64_decode((string) $query['cursor'], true);
            if ($decoded === false || !preg_match('/^aux_[a-f0-9]{20}$/', $decoded)) {
                return ApiResponse::error(400, 'bad_request', 'cursor is invalid.');
            }
            $cursor = $decoded;
        }
        $page = $this->others?->findAuxiliariesPageByShipId((int) $ship['id'], $cursor, $limit) ?? ['rows' => [], 'nextCursor' => null];
        $response = ['auxiliaries' => array_map(fn(array $row): array => $this->presentOthersAuxiliary($player, $ship, $row), $page['rows'])];
        if ($page['nextCursor'] !== null) {
            $response['nextCursor'] = base64_encode((string) $page['nextCursor']);
        }
        return new ApiResponse(200, $response);
    }

    private function othersAuxiliaryResponse(Player $player, string $shipId, string $auxiliaryId): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) {
            return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.');
        }
        $auxiliary = $this->others?->findAuxiliaryForShip($auxiliaryId, (int) $ship['id']);
        return $auxiliary === null
            ? ApiResponse::error(404, 'others_auxiliary_not_found', 'Others auxiliary not found.')
            : new ApiResponse(200, ['auxiliary' => $this->presentOthersAuxiliary($player, $ship, $auxiliary)]);
    }

    private function othersAuxiliaryTaskResponse(Player $player, string $shipId, string $auxiliaryId, string $task, ?string $body): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) { return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.'); }
        $auxiliary = $this->others?->findAuxiliaryForShip($auxiliaryId, (int) $ship['id']);
        if ($auxiliary === null) { return ApiResponse::error(404, 'others_auxiliary_not_found', 'Others auxiliary not found.'); }
        $payload = $this->decodeJsonBody($body);
        if (!is_array($payload)) { return ApiResponse::error(400, 'bad_request', 'A JSON object is required.'); }
        $action = $this->othersService?->startAuxiliaryTask($ship, $auxiliary, $task, $payload) ?? throw new \RuntimeException('Others auxiliary task service is unavailable.');
        return new ApiResponse(202, ['action' => $this->presentOthersAction($action)]);
    }

    private function othersAuxiliaryBatchResponse(Player $player, string $shipId, ?string $body): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) { return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.'); }
        $payload = $this->decodeJsonBody($body); $tasks = is_array($payload) ? ($payload['tasks'] ?? null) : null;
        if (!is_array($tasks) || $tasks === []) { return ApiResponse::error(400, 'bad_request', 'tasks must be a non-empty array.'); }
        $seen = [];
        foreach ($tasks as $task) {
            if (!is_array($task) || !is_string($task['auxiliaryId'] ?? null) || !is_string($task['task'] ?? null) || !is_array($task['payload'] ?? null) || isset($seen[$task['auxiliaryId']])) { return ApiResponse::error(400, 'bad_request', 'Each auxiliary may appear exactly once with task and payload.'); }
            $seen[$task['auxiliaryId']] = true;
        }
        $actions = $this->others?->transaction(function () use ($ship, $tasks): array {
            $result = [];
            foreach ($tasks as $task) {
                $auxiliary = $this->others?->findAuxiliaryForShip($task['auxiliaryId'], (int) $ship['id']);
                if ($auxiliary === null) { throw new OthersActionException(404, 'others_auxiliary_not_found', 'Others auxiliary not found.'); }
                $result[] = $this->othersService?->startAuxiliaryTask($ship, $auxiliary, $task['task'], $task['payload']) ?? throw new \RuntimeException('Others auxiliary task service is unavailable.');
            }
            return $result;
        }) ?? [];
        return new ApiResponse(202, ['actions' => array_map(fn(array $action): array => $this->presentOthersAction($action), $actions)]);
    }

    private function othersInventoryResponse(Player $player, string $shipId): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) {
            return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.');
        }
        $inventory = $this->others?->inventory((int) $ship['id']) ?? ['resources' => [], 'items' => []];
        $used = array_sum(array_map(static fn(array $resource): float => (float) $resource['amount'], $inventory['resources']))
            + array_sum(array_map(static fn(array $item): float => (float) $item['container_space'], $inventory['items']));
        return new ApiResponse(200, ['inventory' => [
            'shipId' => (string) $ship['public_id'],
            'capacityEce' => (float) $ship['inventory_capacity'],
            'usedEce' => round($used, 4),
            'reservedEce' => (float) $ship['inventory_reserved'],
            'resources' => $inventory['resources'],
            'items' => array_map(static fn(array $item): array => ['id' => (string) $item['public_id'], 'type' => (string) $item['type'], 'containerSpaceEce' => (float) $item['container_space']], $inventory['items']),
        ]]);
    }

    private function othersInventoryTransferCreateResponse(Player $player, string $sourceShipId, ?string $body): ApiResponse
    {
        $source = $this->others?->findShipForPlayer($sourceShipId, $player->id);
        if ($source === null) { return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.'); }
        $payload = $this->decodeJsonBody($body);
        if (!is_array($payload)) { return ApiResponse::error(400, 'bad_request', 'A JSON object is required.'); }
        $created = $this->othersService?->createInventoryTransfer($source, $payload) ?? throw new \RuntimeException('Others transfer service is unavailable.');
        return new ApiResponse(202, ['transfer' => $this->presentInventoryTransfer($created['transfer']), 'action' => $this->presentOthersAction($created['action'])]);
    }

    private function othersInventoryTransferResponse(Player $player, string $transferId): ApiResponse
    {
        $transfer = $this->others?->findInventoryTransferForPlayer($transferId, $player->id);
        return $transfer === null
            ? ApiResponse::error(404, 'others_inventory_transfer_not_found', 'Others inventory transfer not found.')
            : new ApiResponse(200, ['transfer' => $this->presentInventoryTransfer($transfer)]);
    }

    private function othersDeuteriumTransferResponse(Player $player, string $shipId, string $auxiliaryId, ?string $body): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) { return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.'); }
        $auxiliary = $this->others?->findAuxiliaryForShip($auxiliaryId, (int) $ship['id']);
        if ($auxiliary === null) { return ApiResponse::error(404, 'others_auxiliary_not_found', 'Others auxiliary not found.'); }
        $payload = $this->decodeJsonBody($body);
        if (!is_array($payload)) { return ApiResponse::error(400, 'bad_request', 'A JSON object is required.'); }
        $action = $this->othersService?->transferDeuterium($ship, $auxiliary, $payload) ?? throw new \RuntimeException('Others transfer service is unavailable.');
        return new ApiResponse(202, ['action' => $this->presentOthersAction($action)]);
    }

    private function presentInventoryTransfer(array $transfer): array
    {
        $result = ['id' => (string) $transfer['public_id'], 'kind' => (string) $transfer['kind'], 'status' => (string) ($transfer['action_status'] ?? $transfer['status']), 'actionId' => (string) ($transfer['action_public_id'] ?? '')];
        if ($transfer['resource_type'] !== null) { $result['resourceType'] = (string) $transfer['resource_type']; $result['amount'] = (float) $transfer['amount']; }
        else { $result['itemIds'] = json_decode((string) $transfer['item_ids_json'], true); }
        if (($transfer['ends_at'] ?? null) !== null) { $result['endsAt'] = (string) $transfer['ends_at']; }
        if (($transfer['result_json'] ?? null) !== null) { $result['result'] = json_decode((string) $transfer['result_json'], true); }
        if (($transfer['error_json'] ?? null) !== null) { $result['error'] = json_decode((string) $transfer['error_json'], true); }
        return $result;
    }

    private function othersActionResponse(Player $player, string $actionId): ApiResponse
    {
        $action = $this->others?->findActionForPlayer($actionId, $player->id);
        return $action === null
            ? ApiResponse::error(404, 'others_action_not_found', 'Others action not found.')
            : new ApiResponse(200, ['action' => $this->presentOthersAction($action)]);
    }

    private function othersRecipesResponse(): ApiResponse
    {
        return new ApiResponse(200, ['recipes' => [
            ['id' => 'standard_ship', 'durationSeconds' => 604800, 'ingredients' => ['metals' => 6000.0, 'ice' => 1000.0, 'carbon_compounds' => 2000.0, 'deuterium' => 100.0], 'output' => ['kind' => 'standard_ship', 'quantity' => 1]],
            ['id' => 'others_auxiliary', 'durationSeconds' => 3600, 'ingredients' => ['metals' => 5.0, 'ice' => 0.5, 'carbon_compounds' => 1.0, 'deuterium' => 0.05], 'output' => ['kind' => 'others_auxiliary', 'quantity' => 1]],
            ['id' => 'missile', 'durationSeconds' => 1800, 'ingredients' => ['metals' => 20.0, 'ice' => 2.0, 'carbon_compounds' => 5.0, 'deuterium' => 1.0], 'output' => ['kind' => 'missile', 'quantity' => 1, 'containerSpaceEce' => 2.0]],
        ]]);
    }

    private function othersCraftCreateResponse(Player $player, string $shipId, ?string $body): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) { return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.'); }
        $payload = $this->decodeJsonBody($body);
        if (!is_array($payload)) { return ApiResponse::error(400, 'bad_request', 'A JSON object is required.'); }
        $created = $this->othersService?->startCraft($ship, $payload) ?? throw new \RuntimeException('Others crafting service is unavailable.');
        return new ApiResponse(202, ['craft' => $this->presentOthersCraft($created['craft']), 'action' => $this->presentOthersAction($created['action'])]);
    }

    private function othersCraftsResponse(Player $player, string $shipId): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) { return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.'); }
        $rows = $this->others?->findCraftsByShipForPlayer((int) $ship['id'], $player->id) ?? [];
        return new ApiResponse(200, ['crafts' => array_map(fn(array $craft): array => $this->presentOthersCraft($craft), $rows)]);
    }

    private function othersCraftResponse(Player $player, string $craftId): ApiResponse
    {
        $craft = $this->others?->findCraftForPlayer($craftId, $player->id);
        return $craft === null ? ApiResponse::error(404, 'others_craft_not_found', 'Others craft not found.') : new ApiResponse(200, ['craft' => $this->presentOthersCraft($craft)]);
    }

    private function presentOthersCraft(array $craft): array
    {
        $result = ['id' => (string) $craft['public_id'], 'recipeId' => (string) $craft['recipe_id'], 'status' => (string) ($craft['action_status'] ?? $craft['status']), 'actionId' => (string) ($craft['action_public_id'] ?? ''), 'createdAt' => (string) $craft['created_at'], 'updatedAt' => (string) $craft['updated_at']];
        if (($craft['ends_at'] ?? null) !== null) { $result['endsAt'] = (string) $craft['ends_at']; }
        if (($craft['result_json'] ?? null) !== null) { $result['result'] = json_decode((string) $craft['result_json'], true); }
        if (($craft['error_json'] ?? null) !== null) { $result['error'] = json_decode((string) $craft['error_json'], true); }
        return $result;
    }

    private function othersShipMoveResponse(Player $player, string $shipId, ?string $body): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) {
            return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.');
        }
        $payload = $this->decodeJsonBody($body);
        if (!is_array($payload)) {
            return ApiResponse::error(400, 'bad_request', 'A JSON object is required.');
        }
        $action = $this->othersService?->moveShip($ship, $payload, $player->homeSector) ?? throw new \RuntimeException('Others movement service is unavailable.');
        return new ApiResponse(202, ['action' => $this->presentOthersAction($action)]);
    }

    private function othersHarvestCreateResponse(Player $player, string $shipId, ?string $body): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) { return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.'); }
        $payload = $this->decodeJsonBody($body);
        if (!is_array($payload)) { return ApiResponse::error(400, 'bad_request', 'A JSON object is required.'); }
        $action = $this->othersService?->startHarvest($ship, $payload) ?? throw new \RuntimeException('Others harvest service is unavailable.');
        return new ApiResponse(202, ['action' => $this->presentOthersAction($action)]);
    }

    private function othersLaserResponse(Player $player, string $shipId, ?string $body): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) { return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.'); }
        $payload = $this->decodeJsonBody($body);
        if (!is_array($payload)) { return ApiResponse::error(400, 'bad_request', 'A JSON object is required.'); }
        $action = $this->othersService?->startLaser($ship, $payload) ?? throw new \RuntimeException('Others laser service is unavailable.');
        return new ApiResponse(202, ['action' => $this->presentOthersAction($action)]);
    }

    private function othersMissileCreateResponse(Player $player, string $shipId, ?string $body): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) { return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.'); }
        $payload = $this->decodeJsonBody($body);
        if (!is_array($payload)) { return ApiResponse::error(400, 'bad_request', 'A JSON object is required.'); }
        $created = $this->othersService?->launchOthersMissile($ship, $payload) ?? throw new \RuntimeException('Others missile service is unavailable.');
        return new ApiResponse(202, ['missile' => $this->presentMissile($created['missile']), 'action' => $this->presentOthersAction($created['action'])]);
    }

    private function probeMissileCreateResponse(Player $player, NeumannProbe $probe, ?string $body): ApiResponse
    {
        $payload = $this->decodeJsonBody($body);
        if (!is_array($payload)) { return ApiResponse::error(400, 'bad_request', 'A JSON object is required.'); }
        $missile = $this->othersService?->prepareProbeMissile($probe, $player->id, $payload) ?? throw new \RuntimeException('Probe missile service is unavailable.');
        return new ApiResponse(202, ['missile' => $this->presentMissile($missile)]);
    }

    private function probeMannyMissileCreateResponse(Player $player, NeumannProbe $probe, string $mannyId, ?string $body): ApiResponse
    {
        $payload = $this->decodeJsonBody($body);
        if (!is_array($payload)) { return ApiResponse::error(400, 'bad_request', 'A JSON object is required.'); }
        $missile = $this->othersService?->igniteProbeMissile($probe, $player->id, $mannyId, $payload) ?? throw new \RuntimeException('Probe missile service is unavailable.');
        return new ApiResponse(202, ['missile' => $this->presentMissile($missile)]);
    }

    private function missileResponse(Player $player, string $missileId): ApiResponse
    {
        $missile = $this->othersService?->findMissileForPlayer($missileId, $player->id);
        return $missile === null ? ApiResponse::error(404, 'missile_not_found', 'Missile not found.') : new ApiResponse(200, ['missile' => $this->presentMissile($missile)]);
    }

    /** @return array<string,mixed> */
    private function presentMissile(array $missile): array
    {
        $result = ['id'=>(string)$missile['public_id'],'launcherKind'=>(string)$missile['launcher_kind'],'launcherId'=>(string)$missile['launcher_public_id'],'targetId'=>(string)$missile['target_public_id'],'status'=>(string)$missile['status'],'launchAt'=>(string)$missile['launch_at'],'createdAt'=>(string)$missile['created_at'],'updatedAt'=>(string)$missile['updated_at']];
        if (($missile['impact_at'] ?? null) !== null) { $result['impactAt'] = (string)$missile['impact_at']; }
        if (($missile['result'] ?? null) !== null) { $result['result'] = (string)$missile['result']; }
        if (is_string($missile['action_public_id'] ?? null)) { $result['actionId'] = $missile['action_public_id']; }
        if (($missile['details_json'] ?? null) !== null) { $result['details'] = json_decode((string)$missile['details_json'], true); }
        return $result;
    }

    private function othersHarvestCancelResponse(Player $player, string $shipId): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) { return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.'); }
        $action = $this->othersService?->cancelHarvest($ship) ?? throw new \RuntimeException('Others harvest service is unavailable.');
        return new ApiResponse(202, ['action' => $this->presentOthersAction($action)]);
    }

    private function othersShipMoveCancelResponse(Player $player, string $shipId): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null) {
            return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.');
        }
        $action = $this->othersService?->cancelMove($ship) ?? throw new \RuntimeException('Others movement service is unavailable.');
        return new ApiResponse(202, ['action' => $this->presentOthersAction($action)]);
    }

    private function othersFleetMoveResponse(Player $player, string $fleetId, ?string $body): ApiResponse
    {
        $fleet = $this->others?->findFleetForPlayer($fleetId, $player->id);
        if ($fleet === null) {
            return ApiResponse::error(404, 'others_fleet_not_found', 'Others fleet not found.');
        }
        $payload = $this->decodeJsonBody($body);
        if (!is_array($payload)) {
            return ApiResponse::error(400, 'bad_request', 'A JSON object is required.');
        }
        $result = $this->othersService?->moveFleet($fleet, $payload, $player->homeSector) ?? throw new \RuntimeException('Others movement service is unavailable.');
        return new ApiResponse(202, [
            'actions' => array_map(fn(array $entry): array => ['shipId' => $entry['shipId'], 'action' => $this->presentOthersAction($entry['action'])], $result['created']),
            'ignored' => $result['ignored'], 'blocked' => $result['blocked'],
        ]);
    }

    private function othersCommand(ApiRouteContext $ctx, Player $player, callable $command): ApiResponse
    {
        $key = $this->headerValue($ctx->headers, 'Idempotency-Key');
        if ($key === null) {
            $response = $command();
            $this->othersAudit?->record($player->id, 'http', $ctx->method . ' ' . $ctx->path, $response->status < 400 ? 'accepted' : 'refused');
            return $response;
        }
        if (!preg_match('/^[\x21-\x7E]{1,128}$/', $key)) {
            return ApiResponse::error(400, 'bad_request', 'Idempotency-Key must contain 1 to 128 visible ASCII characters.');
        }
        if ($this->othersIdempotency === null || $this->others === null) {
            return ApiResponse::error(503, 'others_unavailable', 'Others idempotency storage is unavailable.');
        }
        $hash = hash('sha256', $this->canonicalJsonBody($ctx->body));
        return $this->others->transaction(function () use ($ctx, $player, $command, $key, $hash): ApiResponse {
            // Commands sharing an account also share the idempotency namespace.
            // Lock that account so the first lookup and insert are serialized.
            $pdo = $this->others?->pdo() ?? throw new \RuntimeException('Others storage is unavailable.');
            $lockSql = 'SELECT id FROM players WHERE id = :player_id';
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $lockSql .= ' FOR UPDATE';
            }
            $lock = $pdo->prepare($lockSql);
            $lock->execute(['player_id' => $player->id]);
            if ($lock->fetchColumn() === false) {
                throw new \RuntimeException('Unable to lock the idempotency account.');
            }
            $existing = $this->othersIdempotency?->find($player->id, $key);
            if ($existing !== null) {
                if ($existing['request_method'] !== $ctx->method || $existing['request_path'] !== $ctx->path || !hash_equals((string) $existing['request_body_hash'], $hash)) {
                    return ApiResponse::error(409, 'idempotency_key_conflict', 'This idempotency key is already bound to another command.');
                }
                return $this->othersIdempotency?->responseFrom($existing) ?? throw new \RuntimeException('Stored idempotent response is unavailable.');
            }
            $response = $command();
            $this->othersIdempotency?->store($player->id, $key, $ctx->method, $ctx->path, $hash, $response);
            $this->othersAudit?->record($player->id, 'http', $ctx->method . ' ' . $ctx->path, $response->status < 400 ? 'accepted' : 'refused', details: ['idempotencyKeyHash' => hash('sha256', $key)]);
            return $response;
        });
    }

    private function headerValue(array $headers, string $wanted): ?string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, $wanted) === 0) {
                return trim(is_array($value) ? (string) reset($value) : (string) $value);
            }
        }
        return null;
    }

    private function canonicalJsonBody(?string $body): string
    {
        if ($body === null || trim($body) === '') { return '{}'; }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) { return $body; }
        $sort = function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) { return $value; }
            if (!array_is_list($value)) { ksort($value); }
            foreach ($value as $key => $child) { $value[$key] = $sort($child); }
            return $value;
        };
        return json_encode($sort($decoded), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function presentOthersFleetSummary(array $fleet): array
    {
        return ['id' => (string) $fleet['public_id'], 'status' => (string) $fleet['status'], 'shipCount' => (int) $fleet['ship_count'], 'standardShipCount' => (int) $fleet['standard_ship_count'], 'auxiliaryCount' => (int) $fleet['auxiliary_count'], 'deployedAuxiliaryCount' => (int) $fleet['deployed_auxiliary_count'], 'activeActionCount' => (int) $fleet['active_action_count']];
    }

    private function presentOthersAlert(array $alert): array
    {
        return [
            'id' => (string) $alert['public_id'],
            'shipId' => (string) $alert['ship_public_id'],
            'type' => (string) $alert['type'],
            'status' => (string) $alert['status'],
            'phase' => (string) $alert['phase'],
            'message' => (string) $alert['message'],
            'createdAt' => (string) $alert['created_at'],
            'updatedAt' => (string) $alert['updated_at'],
            'readAt' => $alert['read_at'] !== null ? (string) $alert['read_at'] : null,
        ];
    }

    private function presentOthersShip(Player $player, array $ship): array
    {
        $state = in_array((string) $ship['status'], ['transit', 'removed', 'destroyed'], true) ? (string) $ship['status'] : 'in_sector';
        $result = [
            'id' => (string) $ship['public_id'], 'fleetId' => (string) ($ship['fleet_public_id'] ?? ''),
            'type' => (string) $ship['type'], 'status' => (string) $ship['status'],
            'integrity' => (int) $ship['integrity'], 'maxIntegrity' => (int) $ship['max_integrity'],
            'deuterium' => ['amount' => (float) $ship['deuterium_stock'], 'capacity' => (float) $ship['deuterium_capacity']],
            'inventoryCapacityEce' => (float) $ship['inventory_capacity'],
            'location' => ['state' => $state],
            'sector' => $state === 'in_sector' ? ['relative' => $this->relativeOthersSector($player, $ship)] : null,
            'movement' => $this->presentOthersMovement($player, $ship),
            'createdAt' => (string) $ship['created_at'], 'updatedAt' => (string) $ship['updated_at'],
        ];
        if (isset($ship['auxiliary_count'])) {
            $result['auxiliaryCount'] = (int) $ship['auxiliary_count'];
            $result['deployedAuxiliaryCount'] = (int) $ship['deployed_auxiliary_count'];
        }
        return $result;
    }

    private function presentOthersMovement(Player $player, array $ship): ?array
    {
        if (
            ($ship['movement_phase'] ?? null) === null
            || ($ship['movement_target_x'] ?? null) === null
            || ($ship['movement_target_y'] ?? null) === null
            || ($ship['movement_target_z'] ?? null) === null
            || ($ship['movement_arrive_at'] ?? null) === null
        ) {
            return null;
        }

        return [
            'phase' => (string) $ship['movement_phase'],
            'target' => $this->relativeOthersCoordinates(
                $player,
                (int) $ship['movement_target_x'],
                (int) $ship['movement_target_y'],
                (int) $ship['movement_target_z'],
            ),
            'arrivalAt' => (string) $ship['movement_arrive_at'],
        ];
    }

    private function presentOthersAuxiliary(Player $player, array $ship, array $auxiliary): array
    {
        $result = [
            'id' => (string) $auxiliary['public_id'], 'status' => (string) $auxiliary['status'],
            'locationType' => (string) $auxiliary['location_type'], 'spatialState' => (string) $auxiliary['spatial_state'],
            'capacityEce' => 2.0,
            'cargo' => ['deuterium' => (float) $auxiliary['cargo_deuterium'], 'metals' => (float) $auxiliary['cargo_metals'], 'ice' => (float) $auxiliary['cargo_ice'], 'carbon_compounds' => (float) $auxiliary['cargo_carbon_compounds']],
        ];
        if (!empty($auxiliary['action_public_id'])) {
            $result['action'] = ['id' => (string) $auxiliary['action_public_id'], 'type' => (string) $auxiliary['action_type'], 'status' => (string) $auxiliary['action_status'], 'endsAt' => (string) $auxiliary['action_ends_at']];
        }
        if (
            $auxiliary['sector_x'] !== null
            && $auxiliary['sector_y'] !== null
            && $auxiliary['sector_z'] !== null
            && (
                (string) $ship['status'] === 'transit'
                || (int) $auxiliary['sector_x'] !== (int) $ship['sector_x']
                || (int) $auxiliary['sector_y'] !== (int) $ship['sector_y']
                || (int) $auxiliary['sector_z'] !== (int) $ship['sector_z']
            )
        ) {
            $result['sector'] = ['relative' => $this->relativeOthersSector($player, $auxiliary)];
        }
        return $result;
    }

    private function relativeOthersSector(Player $player, array $entity): array
    {
        return $this->relativeOthersCoordinates(
            $player,
            (int) $entity['sector_x'],
            (int) $entity['sector_y'],
            (int) $entity['sector_z'],
        );
    }

    private function relativeOthersCoordinates(Player $player, int $x, int $y, int $z): array
    {
        return (new PlayerReferenceFrame($player->homeSector))->globalToRelative(new SectorCoordinates($x, $y, $z));
    }

    private function presentOthersAction(array $action): array
    {
        $result = [
            'id' => (string) $action['public_id'], 'type' => (string) $action['type'], 'status' => $action['status'] === 'cancel_requested' ? 'queued' : (string) $action['status'],
            'createdAt' => (string) $action['created_at'], 'updatedAt' => (string) $action['updated_at'],
            'actor' => ['kind' => (string) $action['actor_kind'], 'id' => (string) $action['actor_public_id']],
        ];
        foreach (['ends_at' => 'endsAt', 'cancelable_until' => 'cancelableUntil', 'completed_at' => 'completedAt'] as $column => $field) {
            if (($action[$column] ?? null) !== null) { $result[$field] = (string) $action[$column]; }
        }
        foreach (['result_json' => 'result', 'error_json' => 'error'] as $column => $field) {
            if (($action[$column] ?? null) !== null) { $result[$field] = json_decode((string) $action[$column], true); }
        }
        return $result;
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
        $probe = $this->probes->findById($probeId);
        if ($probe === null || $probe->playerId !== $player->id) {
            return ApiResponse::error(404, 'not_found', 'Probe not found.');
        }

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

        $defaultProbe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $probe = $this->probes->findById($probeId);
        if ($probe === null || $probe->playerId !== $player->id) {
            return ApiResponse::error(404, 'not_found', 'Probe not found.');
        }

        $probe = $this->movements->refreshProbeMovementState($probe);
        $probe = $this->probes->findById($probeId);
        if ($probe === null || $probe->playerId !== $player->id) {
            return ApiResponse::error(404, 'not_found', 'Probe not found.');
        }

        if ($probe->id !== $defaultProbe->id && !$this->scut->canSectorsCommunicate($defaultProbe->currentSector, $probe->currentSector)) {
            $relative = PlayerReferenceFrame::atGlobalCoordinates(
                $player->homeSector->getX(),
                $player->homeSector->getY(),
                $player->homeSector->getZ(),
            )->globalToRelative($probe->currentSector);

            return new ApiResponse(200, [
                'probe' => [
                    'id' => $probe->id,
                    'name' => $probe->name,
                    'status' => 'out_of_scut_range',
                    'sector' => ['relative' => $relative],
                ],
            ]);
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
                    'model' => $probe->model,
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
                    'model' => $probe->model,
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
        foreach ($this->probes->findAllByPlayerId($player->id) as $probe) {
            $this->movements->refreshProbeMovementState($probe);
        }

        $player = $this->players->findById($player->id) ?? $player;
        $defaultProbe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $player = $this->players->findById($player->id) ?? $player;

        return new ApiResponse(200, [
            'defaultProbeId' => $player->defaultProbeId,
            'probes' => array_map(
                fn(NeumannProbe $probe): array => $this->probeSummaryArray($player, $probe, $defaultProbe),
                $this->probes->findAllByPlayerId($player->id),
            ),
        ]);
    }

    /**
     * @return array{id:int, name:string, status:string, isDefault:bool, isReachable:bool}
     */
    private function probeSummaryArray(Player $player, NeumannProbe $probe, NeumannProbe $defaultProbe): array
    {
        $probe = $this->movements->refreshProbeMovementState($probe);
        $isDefault = $player->defaultProbeId === $probe->id;

        return [
            'id' => $probe->id,
            'name' => $probe->name,
            'model' => $probe->model,
            'status' => $probe->status->value,
            'isDefault' => $isDefault,
            'isReachable' => $isDefault || $this->scut->canSectorsCommunicate($defaultProbe->currentSector, $probe->currentSector),
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

    private function probeImprovementBlueprintShareResponse(
        Player $player,
        NeumannProbe $probe,
        string $improvementId,
        ?string $body,
    ): ApiResponse {
        if ($this->improvements === null) {
            return ApiResponse::error(503, 'probe_improvements_unavailable', 'Probe improvement storage is unavailable.');
        }

        $improvementId = ProbeImprovementCatalog::normalizeId($improvementId);
        $definition = $this->probeImprovementDefinition($probe, $improvementId);
        if ($definition === null) {
            return ApiResponse::error(404, 'blueprint_not_found', 'Blueprint not found.');
        }
        if (!$this->improvements->playerHasBlueprint($player->id, $improvementId)) {
            return ApiResponse::error(403, 'blueprint_not_known', 'The authenticated player does not know this blueprint.');
        }

        $data = $this->decodeJsonBody($body);
        $recipientProbeId = is_array($data) && is_int($data['recipientProbeId'] ?? null)
            ? $data['recipientProbeId']
            : 0;
        if ($recipientProbeId <= 0) {
            return ApiResponse::error(400, 'bad_request', 'recipientProbeId must be a positive integer.');
        }

        $recipientProbe = $this->probes->findById($recipientProbeId);
        if ($recipientProbe === null) {
            return ApiResponse::error(404, 'recipient_probe_not_found', 'Recipient probe not found.');
        }
        if ($recipientProbe->playerId === $player->id) {
            return ApiResponse::error(422, 'invalid_blueprint_recipient', 'A blueprint can only be shared with another player.');
        }
        $recipientProbe = $this->movements->refreshProbeMovementState($recipientProbe);

        $sharedNetworkIds = $this->scut->sharedActiveNetworkIds($probe->currentSector, $recipientProbe->currentSector);
        if ($sharedNetworkIds === []) {
            return ApiResponse::error(422, 'probes_not_in_same_scut_network', 'Both probes must be covered by the same active SCUT network.');
        }

        $created = $this->improvements->grantBlueprintToPlayer($recipientProbe->playerId, $improvementId);
        $senderName = trim((string) ($player->displayName ?? '')) !== '' ? trim((string) $player->displayName) : $player->username;
        $blueprintName = (string) ($definition['name'] ?? $improvementId);
        $this->damageWarnings->createBlueprintSharedAlert(
            $recipientProbe->id,
            $recipientProbe->currentSector,
            $improvementId,
            $probe->id,
            $probe->name,
            'SCUT blueprint received: ' . $blueprintName . ' was shared by ' . $senderName
                . ' via probe ' . $probe->name . '. It is now available to all your probes.',
        );

        return new ApiResponse($created ? 201 : 200, [
            'blueprint' => [
                'id' => $improvementId,
                'name' => $blueprintName,
            ],
            'recipientProbe' => [
                'id' => $recipientProbe->id,
                'name' => $recipientProbe->name,
            ],
            'alreadyKnown' => !$created,
            'recipientNotified' => true,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function probeImprovementDefinition(NeumannProbe $probe, string $improvementId): ?array
    {
        foreach ($this->probeManniesPresenter()->probeImprovements($probe, true) as $definition) {
            if (($definition['id'] ?? null) === $improvementId) {
                return $definition;
            }
        }

        return null;
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
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));

        return $this->visitedSectorsApiResponse(
            $player,
            $this->visitedSectors->listVisitedByProbe($probe),
        );
    }

    private function visitedSectorsResponse(Player $player): ApiResponse
    {
        foreach ($this->probes->findAllByPlayerId($player->id) as $probe) {
            $this->movements->refreshProbeMovementState($probe);
        }

        return $this->visitedSectorsApiResponse($player, $this->visitedSectors->listVisited($player));
    }

    /**
     * @param array<VisitedSector> $visitedSectors
     */
    private function visitedSectorsApiResponse(Player $player, array $visitedSectors): ApiResponse
    {
        $frame = new PlayerReferenceFrame($player->homeSector);

        return new ApiResponse(200, [
            'visitedSectors' => array_map(
                fn(VisitedSector $sector): array => [
                    'relativeCoordinates' => $frame->globalToRelative($sector->coordinates),
                    'firstVisitedAt' => $sector->firstVisitedAt,
                    'lastVisitedAt' => $sector->lastVisitedAt,
                    'visitCount' => $sector->visitCount,
                ],
                $visitedSectors,
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
                'inventory' => $this->lightweightInventoryForProbe($probe)->toArray(),
            ]);
        }

        $this->missions->completeReadyReturnToSpacePrograms($probe);
        $observation = $this->observations->observe($player, $probe, $observableSector)->toArray();
        $observation['sensorMode'] = $sensorMode;
        $observation['dataFreshness'] = 'live';
        $observation = $this->withBlackHoleTrapCountdown($observation, $probe);
        $observation = $this->withObservedProbePresence($observation, $probe, $observableSector);
        $observation = $this->addObservedOthersEntities($observation, $observableSector, includeProjectiles: false);
        $observation = $this->withObservedMovingMissiles($observation, $probe, $observableSector);
        $observation = $this->withScutSectorData($player, $observation, $observableSector, includeRelays: true);

        return new ApiResponse(200, [
            'sector' => $observation,
            'inventory' => $this->lightweightInventoryForProbe($probe)->toArray(),
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

    private function probeStorageCraftingReservationsReassignResponse(Player $player, string $containerId, NeumannProbe $probe): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe);
        $this->movements->ensureProbeOperational($probe);

        return new ApiResponse(200, $this->storage->reassignCraftingReservations($probe, $containerId));
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
        $status = isset($query['status']) ? trim((string) $query['status']) : null;
        if (!$sent && $status !== null && $status !== ProbeMessage::STATUS_UNREAD) {
            return new ApiResponse(400, ['error' => 'status must be unread']);
        }
        $unreadOnly = !$sent && $status === ProbeMessage::STATUS_UNREAD;

        $messages = $sent
            ? $this->messages->sentByProbe($probe->id, $limit, $offset)
            : $this->messages->receivedByProbe($probe->id, $limit, $offset, $unreadOnly);
        $total = $sent
            ? $this->messages->countSentByProbe($probe->id)
            : $this->messages->countReceivedByProbe($probe->id, $unreadOnly);

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

    private function probeLogbookPagesResponse(Player $player, array $query, NeumannProbe $probe): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe);
        $this->movements->ensureProbeOperational($probe);
        $limit = $this->messagePaginationParameter($query, 'limit', 10, 1, 100);
        if ($limit instanceof ApiResponse) {
            return $limit;
        }
        $offset = $this->messagePaginationParameter($query, 'offset', 0, 0);
        if ($offset instanceof ApiResponse) {
            return $offset;
        }

        $pages = $this->logbookRepository()->listByProbe($probe->id, $limit, $offset);
        $total = $this->logbookRepository()->countByProbe($probe->id);

        return new ApiResponse(200, [
            'pages' => array_map(fn(ProbeLogbookPage $page): array => $this->probeLogbookPageArray($page, includeContent: false), $pages),
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($pages),
                'total' => $total,
                'hasMore' => $offset + count($pages) < $total,
            ],
        ]);
    }

    private function probeLogbookPageResponse(Player $player, int $pageId, NeumannProbe $probe): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe);
        $this->movements->ensureProbeOperational($probe);
        $page = $this->logbookRepository()->findByIdForProbe($pageId, $probe->id);
        if ($page === null) {
            return ApiResponse::error(404, 'not_found', 'Logbook page not found.');
        }

        return new ApiResponse(200, ['page' => $this->probeLogbookPageArray($page)]);
    }

    private function probeLogbookPageCreateResponse(Player $player, ?string $body, NeumannProbe $probe): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe);
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a logbook page.');
        }

        $payload = $this->probeLogbookPagePayload($data, requireBoth: true);
        if ($payload instanceof ApiResponse) {
            return $payload;
        }

        $page = $this->logbookRepository()->create($probe->id, $payload['title'], $payload['content']);

        return new ApiResponse(201, ['page' => $this->probeLogbookPageArray($page)]);
    }

    private function probeLogbookPageUpdateResponse(Player $player, int $pageId, ?string $body, NeumannProbe $probe): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe);
        $this->movements->ensureProbeOperational($probe);
        $page = $this->logbookRepository()->findByIdForProbe($pageId, $probe->id);
        if ($page === null) {
            return ApiResponse::error(404, 'not_found', 'Logbook page not found.');
        }

        $data = $this->decodeJsonBody($body);
        if (!is_array($data)) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a logbook page update.');
        }

        $payload = $this->probeLogbookPagePayload($data, requireBoth: false);
        if ($payload instanceof ApiResponse) {
            return $payload;
        }
        if (!array_key_exists('title', $payload) && !array_key_exists('content', $payload)) {
            return ApiResponse::error(400, 'bad_request', 'Logbook page update must contain title or content.');
        }

        return new ApiResponse(200, [
            'page' => $this->probeLogbookPageArray($this->logbookRepository()->update(
                $page,
                $payload['title'] ?? null,
                $payload['content'] ?? null,
            )),
        ]);
    }

    private function probeLogbookPageDeleteResponse(Player $player, int $pageId, NeumannProbe $probe): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe);
        $this->movements->ensureProbeOperational($probe);
        $page = $this->logbookRepository()->findByIdForProbe($pageId, $probe->id);
        if ($page === null) {
            return ApiResponse::error(404, 'not_found', 'Logbook page not found.');
        }

        $this->logbookRepository()->delete($page);

        return new ApiResponse(204, []);
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

    private function probeAlertsResponse(Player $player, array $query = [], ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $status = isset($query['status']) ? trim((string) $query['status']) : null;
        if ($status !== null && $status !== ProbeDamageWarning::STATUS_UNREAD) {
            return new ApiResponse(400, ['error' => 'status must be unread']);
        }
        $alerts = $this->damageWarnings->findByProbeId($probe->id, $status === ProbeDamageWarning::STATUS_UNREAD);

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

    private function probeAlertDeleteResponse(Player $player, int $alertId, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $alert = $this->damageWarnings->findByIdForProbe($alertId, $probe->id);
        if ($alert === null) {
            return ApiResponse::error(404, 'not_found', 'Alert not found.');
        }

        $this->damageWarnings->delete($alert);

        return new ApiResponse(204, []);
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

    private function probeDamageWarningDeleteResponse(Player $player, int $warningId, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $warning = $this->damageWarnings->findByIdForProbe($warningId, $probe->id);
        if ($warning === null) {
            return ApiResponse::error(404, 'not_found', 'Damage warning not found.');
        }

        $this->damageWarnings->delete($warning);

        return new ApiResponse(204, []);
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
        $startsAtAdditionalContainers = $this->containerBreakThreshold($probe);

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

    private function containerBreakThreshold(?NeumannProbe $probe): int
    {
        if ($probe === null) {
            return ProbeModel::containerBreakThreshold(ProbeModel::GENERIC, false);
        }

        return ProbeModel::containerBreakThreshold(
            $probe->model,
            $this->fragileContainerRiskDiscount($probe) > 0,
        );
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

        if (isset($query['shipId'])) {
            return $this->othersSectorResponse($player, (string) $query['shipId'], (int) $query['x'], (int) $query['y'], (int) $query['z']);
        }

        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $target = $this->observations->relativeToAbsolute($player, (int) $query['x'], (int) $query['y'], (int) $query['z']);

        return new ApiResponse(200, [
            'sector' => $this->addObservedOthersEntities($this->bestSectorObservation($player, $probe, $target), $target),
        ]);
    }

    private function othersSectorResponse(Player $player, string $shipId, int $x, int $y, int $z): ApiResponse
    {
        if (!$player->canControlOthers) {
            return ApiResponse::error(403, 'others_permission_required', 'This account is not allowed to control Others fleets.');
        }
        $designated = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($designated === null) {
            return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.');
        }
        try {
            $target = (new PlayerReferenceFrame($player->homeSector))->relativeToGlobal($x, $y, $z);
        } catch (\Throwable) {
            return ApiResponse::error(422, 'invalid_destination', 'The relative sector coordinates are invalid.');
        }
        $grid = new SectorGrid();
        $candidates = $this->others?->findActiveShipsByFleetId((int) $designated['fleet_id']) ?? [];
        $candidates = array_values(array_filter($candidates, static fn(array $ship): bool => $ship['status'] !== 'transit'));
        if ($candidates === []) {
            return ApiResponse::error(409, 'others_scan_source_unavailable', 'No active ship in this fleet can provide the scan.');
        }
        usort($candidates, static function (array $a, array $b) use ($target, $grid): int {
            $aDistance = $grid->getDistance(new SectorCoordinates((int) $a['sector_x'], (int) $a['sector_y'], (int) $a['sector_z']), $target);
            $bDistance = $grid->getDistance(new SectorCoordinates((int) $b['sector_x'], (int) $b['sector_y'], (int) $b['sector_z']), $target);
            return [$aDistance, (string) $a['public_id']] <=> [$bDistance, (string) $b['public_id']];
        });
        $source = $candidates[0];
        $sourceSector = new SectorCoordinates((int) $source['sector_x'], (int) $source['sector_y'], (int) $source['sector_z']);
        $syntheticProbe = new NeumannProbe(
            -(int) $source['id'], $player->id, (string) $source['public_id'], $sourceSector, 0.0, 0.0,
            new \VonNeumannGame\Domain\ProbeDirection(0.0, 0.0, 0.0), ProbeStatus::Idle, 100.0, 0.0,
            (float) $source['deuterium_stock'], 1.0, null, (string) $source['entered_sector_at'], (string) $source['created_at'], (string) $source['updated_at'], true,
        );
        $knowledge = $this->others?->fleetSectorKnowledge((int) $designated['fleet_id'], $target)
            ?? ['targetVisited' => false, 'visitedSectorCount' => 0];
        $sector = $this->observations->observeForOthers(
            $player,
            $syntheticProbe,
            $target,
            $knowledge['targetVisited'],
            $knowledge['visitedSectorCount'],
        )->toArray();
        $sector['scan']['source'] = ['kind' => 'others_ship', 'id' => (string) $source['public_id']];
        $sector = $this->addObservedOthersEntities($sector, $target);
        return new ApiResponse(200, ['sector' => $sector]);
    }

    private function addObservedOthersEntities(array $sector, SectorCoordinates $target, bool $includeProjectiles = true): array
    {
        $entities = $this->others?->observableEntitiesBySector($target->getX(), $target->getY(), $target->getZ()) ?? ['ships' => [], 'projectiles' => []];
        if (($sector['knowledgeLevel'] ?? null) === 'detailed') {
            $sector['objects'] ??= [];
            foreach ($entities['ships'] as $ship) {
                $observedShip = ['id' => (string) $ship['public_id'], 'observedClass' => $ship['type'] === 'mothership' ? 'large_ship' : 'ship', 'estimated' => false];
                $direction = $this->observedOthersMovementDirection($ship);
                if ($direction !== null) {
                    $observedShip['movement'] = ['direction' => $direction];
                }
                $sector['objects'][] = $observedShip;
            }
            if ($includeProjectiles) {
                foreach ($entities['projectiles'] as $projectile) {
                    $sector['objects'][] = $this->observedMovingMissileArray($projectile);
                }
            }
        } elseif (($sector['knowledgeLevel'] ?? null) === 'neighbor_scan') {
            foreach ($entities['ships'] as $ship) {
                if ($ship['type'] === 'mothership') {
                    $sector['signals'] = ['unclassified_disturbance'];
                    break;
                }
            }
        }
        return $sector;
    }

    private function observedOthersMovementDirection(array $ship): ?array
    {
        if (
            ($ship['movement_direction_x'] ?? null) === null
            || ($ship['movement_direction_y'] ?? null) === null
            || ($ship['movement_direction_z'] ?? null) === null
        ) {
            return null;
        }
        $x = (float) $ship['movement_direction_x'];
        $y = (float) $ship['movement_direction_y'];
        $z = (float) $ship['movement_direction_z'];
        $length = sqrt(($x * $x) + ($y * $y) + ($z * $z));
        if ($length <= 0.0) {
            return null;
        }
        $normalize = static function (float $component) use ($length): float {
            $normalized = round($component / $length, 6);
            return abs($normalized) < 0.0000005 ? 0.0 : $normalized;
        };

        return ['x' => $normalize($x), 'y' => $normalize($y), 'z' => $normalize($z)];
    }

    private function probeAutonomousUnitsResponse(Player $player, int $probeId, array $query): ApiResponse
    {
        $probe = $this->probes->findById($probeId);
        if ($probe === null || $probe->playerId !== $player->id || in_array($probe->status, [ProbeStatus::Dead, ProbeStatus::Accelerating, ProbeStatus::Cruising, ProbeStatus::Decelerating], true)) {
            return ApiResponse::error(404, 'not_found', 'Observer probe not found.');
        }
        return $this->autonomousUnitsResponse($probe->currentSector, $query);
    }

    private function othersAutonomousUnitsResponse(Player $player, string $shipId, array $query): ApiResponse
    {
        $ship = $this->others?->findShipForPlayer($shipId, $player->id);
        if ($ship === null || $ship['status'] === 'transit') {
            return ApiResponse::error(404, 'others_ship_not_found', 'Others ship not found.');
        }
        return $this->autonomousUnitsResponse(new SectorCoordinates((int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z']), $query);
    }

    private function autonomousUnitsResponse(SectorCoordinates $sector, array $query): ApiResponse
    {
        if ($this->autonomousUnits === null) { return ApiResponse::error(503, 'autonomous_units_unavailable', 'Autonomous-unit observation is unavailable.'); }
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 100;
        if ($limit < 1 || $limit > 500) { return ApiResponse::error(400, 'bad_request', 'limit must be between 1 and 500.'); }
        $cursor = null;
        if (isset($query['cursor'])) {
            $cursor = base64_decode((string) $query['cursor'], true);
            if ($cursor === false || !str_contains($cursor, "\0")) { return ApiResponse::error(400, 'bad_request', 'cursor is invalid.'); }
        }
        $page = $this->autonomousUnits->page($sector, $cursor, $limit);
        $response = ['autonomousUnits' => $page['units']];
        if ($page['nextCursor'] !== null) { $response['nextCursor'] = base64_encode($page['nextCursor']); }
        return new ApiResponse(200, $response);
    }

    /**
     * @return array<string, mixed>
     */
    private function bestSectorObservation(Player $player, NeumannProbe $defaultProbe, SectorCoordinates $target): array
    {
        $grid = new SectorGrid();
        $defaultDistance = $grid->getDistance($defaultProbe->currentSector, $target);
        $ownedProbes = [$defaultProbe->id => $defaultProbe];
        foreach ($this->probes->findAllByPlayerId($player->id) as $candidate) {
            $ownedProbes[$candidate->id] = $candidate->id === $defaultProbe->id
                ? $defaultProbe
                : $this->movements->refreshProbeMovementState($candidate);
        }

        $candidates = [[
            'probe' => $defaultProbe,
            'distance' => $defaultDistance,
            'default' => true,
        ]];

        foreach ($ownedProbes as $candidate) {
            if ($candidate->id === $defaultProbe->id) {
                continue;
            }

            if (in_array($candidate->status, [ProbeStatus::Dead, ProbeStatus::TrappedByBlackHole], true)) {
                continue;
            }
            if (!$this->scut->canSectorsCommunicate($defaultProbe->currentSector, $candidate->currentSector)) {
                continue;
            }

            $distance = $grid->getDistance($candidate->currentSector, $target);
            if ($distance >= $defaultDistance) {
                continue;
            }

            $candidates[] = [
                'probe' => $candidate,
                'distance' => $distance,
                'default' => false,
            ];
        }

        usort(
            $candidates,
            static fn(array $a, array $b): int => [$a['distance'], $a['default'] ? 1 : 0, $a['probe']->id]
                <=> [$b['distance'], $b['default'] ? 1 : 0, $b['probe']->id],
        );

        $insufficientScanData = null;
        foreach ($candidates as $candidate) {
            try {
                $observation = $this->sectorObservationFromProbe($player, $candidate['probe'], $target);
                $observation['distance'] = $defaultDistance;
                $observation['distances'] = $this->sectorProbeDistances($ownedProbes, $defaultProbe->id, $target, $grid, $candidate['probe']->id);

                return $observation;
            } catch (ObservationAccessException $e) {
                if ($e->errorCode !== 'insufficient_scan_data') {
                    throw $e;
                }
                $insufficientScanData = $e;
            }
        }

        throw $insufficientScanData ?? new \RuntimeException('No sector observation candidate available.');
    }

    /**
     * @param array<int, NeumannProbe> $ownedProbes
     * @return list<array{probeId:int, probeName:string, distance:int, isDefault:bool, usedForScan:bool}>
     */
    private function sectorProbeDistances(array $ownedProbes, int $defaultProbeId, SectorCoordinates $target, SectorGrid $grid, int $scanSourceProbeId): array
    {
        $distances = [];
        foreach ($ownedProbes as $probe) {
            $distances[] = [
                'probeId' => $probe->id,
                'probeName' => $probe->name,
                'distance' => $grid->getDistance($probe->currentSector, $target),
                'isDefault' => $probe->id === $defaultProbeId,
                'usedForScan' => $probe->id === $scanSourceProbeId,
            ];
        }

        usort(
            $distances,
            static fn(array $a, array $b): int => [$a['isDefault'] ? 0 : 1, $a['distance'], $a['probeId']]
                <=> [$b['isDefault'] ? 0 : 1, $b['distance'], $b['probeId']],
        );

        return $distances;
    }

    /**
     * @return array<string, mixed>
     */
    private function sectorObservationFromProbe(Player $player, NeumannProbe $probe, SectorCoordinates $target): array
    {
        $probe = $this->movements->refreshProbeMovementState($probe);
        $this->movements->ensureProbeOperational($probe);
        $movement = $this->movements->activeMovementForProbe($probe);
        $sensorMode = $this->movements->sensorModeFor($movement, $probe->status);
        if ($movement === null && $target->equals($probe->currentSector)) {
            $this->movements->refreshCurrentSectorHazards($probe);
        }

        if ($sensorMode === 'blind' && !$this->visitedSectors->hasVisited($player, $target)) {
            throw new ObservationAccessException(
                'sensors_unavailable',
                'External sensors are unavailable at current relativistic velocity.',
            );
        }
        if ($sensorMode === 'degraded' && !$this->visitedSectors->hasVisited($player, $target) && !$target->equals($probe->currentSector)) {
            $observable = $this->movements->observableSectorFor($probe, $movement) ?? $probe->currentSector;
            $frame = new PlayerReferenceFrame($player->homeSector);

            return [
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
            ];
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

        return $this->withScutSectorData($player, $observation, $target, $includeRelays);
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

    private function probeMoveCancelResponse(NeumannProbe $probe): ApiResponse
    {
        $this->movements->cancelMovement($probe);

        return new ApiResponse(204, []);
    }

    private function probeScutNetworkResponse(Player $player, int $networkId, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $network = $this->scut->networkSummaryById($networkId);
        if ($network === null) {
            return ApiResponse::error(404, 'not_found', 'SCUT network not found.');
        }
        if (!$this->scut->networkCoversSector($network->id, $probe->currentSector)) {
            return ApiResponse::error(403, 'forbidden', 'Probe must be inside this SCUT network coverage.');
        }

        $frame = new PlayerReferenceFrame($player->homeSector);
        $relays = array_map(
            fn(ScutRelay $relay): array => $this->scutRelayArray(
                $player,
                $relay,
                includeSector: true,
                idAsString: false,
                knownNetwork: $network,
            ),
            $this->scut->relaySummariesForNetwork($network->id),
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
                'coveredSectorCount' => $this->scut->coveredSectorCount($network->id),
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

    private function withObservedMovingMissiles(array $observation, NeumannProbe $probe, SectorCoordinates $observableSector): array
    {
        if (
            ($observation['knowledgeLevel'] ?? null) !== 'detailed'
            || !$observableSector->equals($probe->currentSector)
        ) {
            return $observation;
        }

        $projectiles = $this->others?->movingProjectilesBySector(
            $observableSector->getX(),
            $observableSector->getY(),
            $observableSector->getZ(),
        ) ?? [];
        if ($projectiles === []) {
            return $observation;
        }

        $observation['objects'] = is_array($observation['objects'] ?? null) ? $observation['objects'] : [];
        foreach ($projectiles as $projectile) {
            $observation['objects'][] = $this->observedMovingMissileArray($projectile, $probe);
        }

        return $observation;
    }

    /** @param array<string, mixed> $projectile */
    private function observedMovingMissileArray(array $projectile, ?NeumannProbe $observingProbe = null): array
    {
        $targetKind = (string) $projectile['target_kind'];
        $targetId = (string) $projectile['target_public_id'];
        $missile = [
            'id' => (string) $projectile['public_id'],
            'type' => 'missile',
            'observedClass' => 'suspected_missile',
            'name' => (string) $projectile['public_id'],
            'estimated' => false,
            'summary' => 'Kinetic projectile in motion.',
            'dangerLevel' => 'moderate',
            'status' => (string) $projectile['status'],
            'launcherKind' => (string) $projectile['launcher_kind'],
            'targetKind' => $targetKind,
            'targetId' => $targetId,
            'launchedAt' => (string) $projectile['launched_at'],
            'impactAt' => (string) $projectile['impact_at'],
        ];
        if ($observingProbe !== null) {
            $missile['targetsCurrentProbe'] = $targetKind === 'probe' && $targetId === (string) $observingProbe->id;
            if ($missile['targetsCurrentProbe']) {
                $missile['dangerLevel'] = 'extreme';
            }
        }

        return $missile;
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
        $sectorWasVisited = $this->visitedSectors->hasVisited($player, $sector);
        if (!$sectorWasVisited && $networks !== []) {
            $knownNetworkIds = array_fill_keys($this->visitedSectors->knownScutNetworkIds(
                $player,
                array_map(static fn(ScutNetwork $network): int => $network->id, $networks),
            ), true);
            $networks = array_values(array_filter(
                $networks,
                static fn(ScutNetwork $network): bool => isset($knownNetworkIds[$network->id]),
            ));
        }

        if ($networks !== []) {
            $observation['scutCoverageStatus'] = 'covered';
            $observation['scutNetworks'] = array_map(
                fn(ScutNetwork $network): array => $this->scutNetworkReferenceArray($network),
                $networks,
            );
        } elseif ($sectorWasVisited) {
            $observation['scutCoverageStatus'] = 'uncovered';
            $observation['scutNetworks'] = [];
        } else {
            $observation['scutCoverageStatus'] = 'unknown';
            unset($observation['scutNetworks']);
        }

        return $observation;
    }

    private function scutRelayArray(
        Player $player,
        ScutRelay $relay,
        bool $includeSector,
        bool $idAsString,
        ?ScutNetwork $knownNetwork = null,
    ): array
    {
        $network = $knownNetwork !== null && $knownNetwork->id === $relay->networkId
            ? $knownNetwork
            : ($relay->networkId !== null ? $this->scut->networkSummaryById($relay->networkId) : null);
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
            'isTransitBeacon' => $relay->isTransitBeacon,
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
     * @return array<array{id:int, name:string, moving:bool, owned:bool}>
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
                'owned' => $otherProbe->playerId === $probe->playerId,
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
            'model' => $probe->model,
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
            'inventory' => $this->lightweightInventoryForProbe($probe)->toArray(),
        ];
    }

    private function inventoryForProbe(NeumannProbe $probe): ProbeInventory
    {
        $mannies = $this->mannies->manniesForProbe($probe);

        return $this->storage->inventoryForProbe(
            $probe,
            $mannies,
            $this->items->findByProbeId($probe->id),
            storageAlreadyEnsured: true,
        );
    }

    private function lightweightInventoryForProbe(NeumannProbe $probe): ProbeInventory
    {
        // Sector return recovery is a gameplay rule, not a task refresh. Keep
        // it while avoiding the full Manny task-list preparation.
        $this->mannies->recoverForgottenManniesForProbe($probe);

        return $this->storage->lightweightInventoryForProbe(
            $probe,
            probeItems: $this->items->findByProbeId($probe->id),
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

    /**
     * @return array<string, mixed>
     */
    private function probeLogbookPageArray(ProbeLogbookPage $page, bool $includeContent = true): array
    {
        $payload = [
            'id' => $page->id,
            'probeId' => $page->probeId,
            'title' => $page->title,
            'sortOrder' => $page->sortOrder,
            'createdAt' => $page->createdAt,
            'updatedAt' => $page->updatedAt,
        ];

        if ($includeContent) {
            $payload['content'] = $page->content;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{title?: string, content?: string}|ApiResponse
     */
    private function probeLogbookPagePayload(array $data, bool $requireBoth): array|ApiResponse
    {
        $payload = [];
        foreach (['title', 'content'] as $field) {
            if (!array_key_exists($field, $data)) {
                if ($requireBoth) {
                    return ApiResponse::error(400, 'bad_request', 'Logbook page requires title and content.');
                }
                continue;
            }
            if (!is_string($data[$field])) {
                return ApiResponse::error(400, 'bad_request', 'Logbook page title and content must be strings.');
            }

            $value = trim($data[$field]);
            $maxLength = $field === 'title' ? 120 : 20000;
            if ($value === '' || strlen($value) > $maxLength) {
                return ApiResponse::error(400, 'bad_request', $field === 'title'
                    ? 'Logbook page title must contain 1 to 120 characters.'
                    : 'Logbook page content must contain 1 to 20000 characters.');
            }

            $payload[$field] = $value;
        }

        return $payload;
    }

    private function logbookRepository(): ProbeLogbookRepository
    {
        return $this->logbook;
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
            'illustrationImageUrl' => $warning->illustrationImageUrl,
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
            $startsAtAdditionalContainers = $this->containerBreakThreshold($this->probes->findById($warning->probeId));
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

        if ($warning->type === ProbeDamageWarning::TYPE_MIND_SNAPSHOT_TRANSFERRED) {
            $alert['instanceSwitch'] = [
                'previousProbeId' => ctype_digit($warning->objectId) ? (int) $warning->objectId : null,
                'reason' => $warning->containerId !== '' ? $warning->containerId : null,
            ];
        }

        if ($warning->type === ProbeDamageWarning::TYPE_PROBE_DESTROYED) {
            $alert['destroyedProbe'] = [
                'probeId' => ctype_digit($warning->objectId) ? (int) $warning->objectId : null,
                'reason' => $warning->containerId !== '' ? $warning->containerId : null,
            ];
        }

        if ($warning->type === ProbeDamageWarning::TYPE_BLUEPRINT_SHARED) {
            $alert['blueprintShare'] = [
                'blueprintId' => $warning->objectId,
                'senderProbe' => [
                    'id' => ctype_digit($warning->containerId) ? (int) $warning->containerId : null,
                    'name' => $warning->containerLabel !== '' ? $warning->containerLabel : null,
                ],
            ];
        }

        return $alert;
    }

    private function movementArray(Player $player, ProbeMovement $movement, bool $includeLive = true): array
    {
        $frame = new PlayerReferenceFrame($player->homeSector);
        $livePhase = $includeLive ? $this->movements->phaseFor($movement) : $movement->status;

        return [
            'status' => $livePhase,
            'origin' => $frame->globalToRelative($movement->origin),
            'target' => $frame->globalToRelative($movement->target),
            'distance' => $movement->distance,
            'fuelCostDeuterium' => $movement->fuelCostDeuterium,
            'startedAt' => $movement->startedAt,
            'arrivalAt' => $movement->arrivalAt,
        ] + ($includeLive ? [
            'phase' => $livePhase,
            'secondsRemaining' => $this->movements->secondsRemaining($movement),
            'sensorMode' => $this->movements->sensorModeFor($movement, ProbeStatus::from($livePhase === 'destroyed' ? 'dead' : ($livePhase === 'arrived' ? 'idle' : $livePhase))),
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

    private function asteroidTrajectoryCreateResponse(Player $player, NeumannProbe $probe, string $asteroidId, ?string $body): ApiResponse
    {
        if ($this->asteroidTrajectories === null) {
            return ApiResponse::error(503, 'asteroid_trajectories_unavailable', 'Asteroid trajectory service is unavailable.');
        }
        $data = $this->decodeJsonBody($body);
        if ($data === null) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain an asteroid trajectory order.');
        }
        $trajectory = $this->asteroidTrajectories->create($player, $probe, $asteroidId, $data);

        return new ApiResponse(202, ['trajectory' => $this->asteroidTrajectories->publicArray($trajectory)]);
    }

    private function asteroidTrajectoryResponse(NeumannProbe $probe, string $trajectoryId): ApiResponse
    {
        if ($this->asteroidTrajectories === null) {
            return ApiResponse::error(503, 'asteroid_trajectories_unavailable', 'Asteroid trajectory service is unavailable.');
        }

        return new ApiResponse(200, ['trajectory' => $this->asteroidTrajectories->getForLocalProbe($probe, $trajectoryId)]);
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

    private function bearerToken(?string $authorizationHeader): ?string
    {
        if ($authorizationHeader === null || !preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }
}
