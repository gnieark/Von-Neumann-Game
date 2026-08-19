<?php

declare(strict_types=1);

namespace VonNeumannGame;

use PDO;
use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Auth\OAuthConfig;
use VonNeumannGame\Auth\OAuthService;
use VonNeumannGame\Config\Config;
use VonNeumannGame\Config\JsonConfigLoader;
use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Forum\ForumRepository;
use VonNeumannGame\Http\ApiKernel;
use VonNeumannGame\Repository\ApiKeyRepository;
use VonNeumannGame\Repository\AsteroidTrajectoryRepository;
use VonNeumannGame\Repository\DetachedStorageContainerRepository;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\MissionRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerAuthRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\ProbeImprovementRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\ProbeLogbookRepository;
use VonNeumannGame\Repository\ProbeMessageRepository;
use VonNeumannGame\Repository\ProbeMovementRepository;
use VonNeumannGame\Repository\ScutNetworkRepository;
use VonNeumannGame\Repository\ScutRelayRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\SessionRepository;
use VonNeumannGame\Repository\StorageContainerRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\RateLimit\PhpRedisScriptExecutor;
use VonNeumannGame\RateLimit\RedisTokenRateLimiter;
use VonNeumannGame\Service\MannyService;
use VonNeumannGame\Service\MissionService;
use VonNeumannGame\Service\MovementDurationCalculator;
use VonNeumannGame\Service\ProbeMovementService;
use VonNeumannGame\Service\ProbeReinstantiationService;
use VonNeumannGame\Service\ProbeStorageService;
use VonNeumannGame\Service\SchedulerService;
use VonNeumannGame\Service\ScutNetworkService;
use VonNeumannGame\Service\SectorObservationService;
use VonNeumannGame\Service\WaypointBookmarkService;
use VonNeumannGame\Service\AsteroidTrajectory\AsteroidTrajectoryService;
use VonNeumannGame\Service\AsteroidTrajectory\AccelerationPhaseHandler;
use VonNeumannGame\Service\AsteroidTrajectory\AsteroidTrajectoryPhaseProcessor;
use VonNeumannGame\Service\AsteroidTrajectory\ImpactDamageResolver;
use VonNeumannGame\Service\AsteroidTrajectory\PhaseHandlerRegistry;
use VonNeumannGame\Service\AsteroidTrajectory\SystemImpactPhaseHandler;
use VonNeumannGame\Service\AsteroidTrajectory\SectorTransferPhaseHandler;
use VonNeumannGame\Service\AsteroidTrajectory\BlackHoleOrbitPhaseHandler;
use VonNeumannGame\Service\AsteroidTrajectory\CaptureCalculator;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorService;

final class AppFactory
{
    public function __construct(private readonly string $projectRoot) {}

    public function databaseFactory(?string $configPath = null): DatabaseConnectionFactory
    {
        return new DatabaseConnectionFactory(
            DatabaseConfig::fromFile($configPath ?? $this->projectRoot . '/config/database.json'),
            $this->projectRoot,
        );
    }

    public function pdo(?string $configPath = null, bool $initializeSchema = false): PDO
    {
        $factory = $this->databaseFactory($configPath);
        $pdo = $factory->create();
        if ($initializeSchema) {
            $factory->initializeSchema($pdo);
        }

        return $pdo;
    }

    public function apiKernel(?PDO $pdo = null): ApiKernel
    {
        $pdo ??= $this->pdo();
        $appConfig = $this->appConfig();
        $gameplayConfig = $this->gameplayConfig();
        $universeConfig = $this->universeConfig();
        $players = new PlayerRepository($pdo);
        $authMethods = new PlayerAuthRepository($pdo);
        $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
        $scheduledEvents = new ScheduledEventRepository($pdo);
        $mannies = new MannyRepository($pdo, $gameplayConfig, $scheduledEvents);
        $items = new ProbeItemRepository($pdo);
        $improvements = new ProbeImprovementRepository($pdo);
        $storageContainers = new StorageContainerRepository($pdo, $gameplayConfig);
        $logbook = new ProbeLogbookRepository($pdo);
        $messages = new ProbeMessageRepository($pdo);
        $scutRelays = new ScutRelayRepository($pdo);
        $scutNetworks = new ScutNetworkRepository($pdo);
        $scut = new ScutNetworkService($scutRelays, $scutNetworks, $probes);
        $missions = new MissionRepository($pdo);
        $damageWarnings = new ProbeDamageWarningRepository($pdo);
        $forum = new ForumRepository($pdo);
        $movements = new ProbeMovementRepository($pdo);
        $asteroidTrajectoryRepository = new AsteroidTrajectoryRepository($pdo);
        $sessions = new SessionRepository($pdo);
        $apiKeys = new ApiKeyRepository($pdo);
        $visitedSectors = new VisitedSectorRepository($pdo);
        $sectorRepository = new SectorFileRepository($this->absolutePath((string) ($appConfig['universePath'] ?? 'data/universe')));
        $sectorService = new SectorService($sectorRepository, new SectorContentGenerator($universeConfig), (string) ($appConfig['worldSeed'] ?? 'default-world'), detachedContainers: new DetachedStorageContainerRepository($pdo));
        $asteroidTrajectoryService = new AsteroidTrajectoryService($asteroidTrajectoryRepository, $probes, $scheduledEvents, $sectorService, $gameplayConfig, $universeConfig, $damageWarnings);
        $auth = new AuthService($players, $authMethods, $probes, $sessions, $visitedSectors, (int) ($appConfig['sessionTtlDays'] ?? 7), $mannies, $apiKeys, $sectorService, gameplayConfig: $gameplayConfig, universeConfig: $universeConfig);
        $observations = new SectorObservationService($sectorService, $visitedSectors, config: $gameplayConfig, mannies: $mannies, asteroidTrajectories: $asteroidTrajectoryRepository, asteroidTrajectoryService: $asteroidTrajectoryService);
        $durations = new MovementDurationCalculator(Config::getArray($gameplayConfig, 'movement'));
        $storage = new ProbeStorageService($storageContainers, $items, $mannies, $probes, $gameplayConfig, $improvements);
        $missionService = new MissionService($missions, $messages, $gameplayConfig, (string) ($appConfig['worldSeed'] ?? 'default-world'), $sectorService, $probes, $players, $damageWarnings);
        $bookmarks = new WaypointBookmarkService($items, $sectorService);
        $mannyService = new MannyService($mannies, $probes, $sectorService, $items, $storage, $gameplayConfig, $bookmarks, $missionService, $scut, $damageWarnings, $improvements, scheduledEvents: $scheduledEvents, movements: $movements, asteroidTrajectories: $asteroidTrajectoryRepository);
        $reinstantiation = new ProbeReinstantiationService($pdo, $players, $probes, $mannies, $visitedSectors, $sectorService, $damageWarnings, gameplayConfig: $gameplayConfig, universeConfig: $universeConfig);
        $movementService = new ProbeMovementService($probes, $movements, $visitedSectors, $scheduledEvents, $sectorService, mannies: $mannies, storage: $storage, damageWarnings: $damageWarnings, missions: $missionService, improvements: $improvements, reinstantiation: $reinstantiation, scut: $scut, durations: $durations, worldSeed: (string) ($appConfig['worldSeed'] ?? 'default-world'), gameplayConfig: $gameplayConfig);
        $redisConfig = $this->redisConfig();
        $rateLimitConfig = Config::getArray($redisConfig, 'rateLimit');
        $rateLimiter = Config::bool($redisConfig, 'rateLimit.enabled', true)
            ? new RedisTokenRateLimiter(
                new PhpRedisScriptExecutor($redisConfig),
                Config::int($rateLimitConfig, 'maxRequests', 60),
                Config::int($rateLimitConfig, 'windowSeconds', 60),
                (string) ($redisConfig['keyPrefix'] ?? 'vng:'),
            )
            : null;

        return new ApiKernel($auth, $players, $probes, $observations, $movementService, $visitedSectors, $mannyService, $items, $storage, $messages, $logbook, $damageWarnings, $forum, $missionService, $reinstantiation, $scut, $gameplayConfig, $improvements, $rateLimiter, $asteroidTrajectoryService);
    }

    public function schedulerService(?PDO $pdo = null): SchedulerService
    {
        $pdo ??= $this->pdo();
        $appConfig = $this->appConfig();
        $gameplayConfig = $this->gameplayConfig();
        $universeConfig = $this->universeConfig();
        $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
        $scheduledEvents = new ScheduledEventRepository($pdo);
        $mannies = new MannyRepository($pdo, $gameplayConfig, $scheduledEvents);
        $items = new ProbeItemRepository($pdo);
        $storageContainers = new StorageContainerRepository($pdo, $gameplayConfig);
        $messages = new ProbeMessageRepository($pdo);
        $missions = new MissionRepository($pdo);
        $movements = new ProbeMovementRepository($pdo);
        $asteroidTrajectories = new AsteroidTrajectoryRepository($pdo);
        $visitedSectors = new VisitedSectorRepository($pdo);
        $damageWarnings = new ProbeDamageWarningRepository($pdo);
        $sectorRepository = new SectorFileRepository($this->absolutePath((string) ($appConfig['universePath'] ?? 'data/universe')));
        $sectorService = new SectorService($sectorRepository, new SectorContentGenerator($universeConfig), (string) ($appConfig['worldSeed'] ?? 'default-world'), detachedContainers: new DetachedStorageContainerRepository($pdo));
        $durations = new MovementDurationCalculator(Config::getArray($gameplayConfig, 'movement'));
        $improvements = new ProbeImprovementRepository($pdo);
        $storage = new ProbeStorageService($storageContainers, $items, $mannies, $probes, $gameplayConfig, $improvements);
        $players = new PlayerRepository($pdo);
        $scutRelays = new ScutRelayRepository($pdo);
        $scutNetworks = new ScutNetworkRepository($pdo);
        $scut = new ScutNetworkService($scutRelays, $scutNetworks, $probes);
        $missionService = new MissionService($missions, $messages, $gameplayConfig, (string) ($appConfig['worldSeed'] ?? 'default-world'), $sectorService, $probes, $players, $damageWarnings);
        $bookmarks = new WaypointBookmarkService($items, $sectorService);
        $mannyService = new MannyService($mannies, $probes, $sectorService, $items, $storage, $gameplayConfig, $bookmarks, $missionService, $scut, $damageWarnings, $improvements, scheduledEvents: $scheduledEvents, movements: $movements, asteroidTrajectories: $asteroidTrajectories);
        $reinstantiation = new ProbeReinstantiationService($pdo, $players, $probes, $mannies, $visitedSectors, $sectorService, $damageWarnings, gameplayConfig: $gameplayConfig, universeConfig: $universeConfig);
        $movementService = new ProbeMovementService($probes, $movements, $visitedSectors, $scheduledEvents, $sectorService, mannies: $mannies, storage: $storage, damageWarnings: $damageWarnings, missions: $missionService, improvements: $improvements, reinstantiation: $reinstantiation, scut: $scut, durations: $durations, worldSeed: (string) ($appConfig['worldSeed'] ?? 'default-world'), gameplayConfig: $gameplayConfig);
        $trajectoryConfig = Config::getArray($gameplayConfig, 'asteroidTrajectories');
        $impactConfig = Config::getArray($trajectoryConfig, 'impact');
        $impactDamage = new ImpactDamageResolver(
            probeAutomaticDestructionSpeedC: Config::float($impactConfig, 'probeAutomaticDestructionSpeedC', 0.05),
            probeReferenceMassEarth: Config::float($impactConfig, 'probeReferenceAsteroidMassEarth', 0.00005),
            factorMinimum: Config::float($impactConfig, 'deterministicFactorMinimum', 0.9),
            factorMaximum: Config::float($impactConfig, 'deterministicFactorMaximum', 1.1),
            fragmentCountMinimum: Config::int($impactConfig, 'fragmentCountMinimum', 3),
            fragmentCountMaximum: Config::int($impactConfig, 'fragmentCountMaximum', 10),
            planetaryLossMinimum: Config::float($impactConfig, 'planetaryMassLossMinimumFraction', 0.01),
            planetaryLossMaximum: Config::float($impactConfig, 'planetaryMassLossMaximumFraction', 0.3),
        );
        $trajectoryProcessor = new AsteroidTrajectoryPhaseProcessor(
            $asteroidTrajectories,
            new PhaseHandlerRegistry([
                new AccelerationPhaseHandler($asteroidTrajectories, $scheduledEvents, Config::int($trajectoryConfig, 'coastingDurationSeconds', 600)),
                new SystemImpactPhaseHandler($asteroidTrajectories, $sectorService, $probes, $movements, $impactDamage),
                new SectorTransferPhaseHandler(
                    $asteroidTrajectories,
                    $scheduledEvents,
                    $sectorService,
                    new CaptureCalculator(
                        Config::int(Config::getArray($trajectoryConfig, 'capture'), 'penaltyPercentPerStep', 10),
                        Config::float(Config::getArray($trajectoryConfig, 'capture'), 'minimumPlanetMassEarth', 3.0),
                    ),
                    Config::int($trajectoryConfig, 'sectorCrossingDurationSeconds', 86400),
                    Config::int(Config::getArray($gameplayConfig, 'movement.blackHoleTrap'), 'minDelaySeconds', 5400),
                    Config::int(Config::getArray($gameplayConfig, 'movement.blackHoleTrap'), 'maxDelaySeconds', 10800),
                    Config::float(Config::getArray($gameplayConfig, 'movement.blackHoleTrap'), 'minMass', 3.0),
                    Config::float(Config::getArray($gameplayConfig, 'movement.blackHoleTrap'), 'maxMass', 30.0),
                ),
                new BlackHoleOrbitPhaseHandler($asteroidTrajectories, $sectorService),
            ]),
        );

        return new SchedulerService($scheduledEvents, $probes, $movements, $movementService, $mannyService, $trajectoryProcessor);
    }

    public function authService(PDO $pdo): AuthService
    {
        $appConfig = $this->appConfig();
        $gameplayConfig = $this->gameplayConfig();
        $universeConfig = $this->universeConfig();
        $sectorRepository = new SectorFileRepository($this->absolutePath((string) ($appConfig['universePath'] ?? 'data/universe')));
        $sectorService = new SectorService($sectorRepository, new SectorContentGenerator($universeConfig), (string) ($appConfig['worldSeed'] ?? 'default-world'), detachedContainers: new DetachedStorageContainerRepository($pdo));

        return new AuthService(
            new PlayerRepository($pdo),
            new PlayerAuthRepository($pdo),
            new NeumannProbeRepository($pdo, $gameplayConfig),
            new SessionRepository($pdo),
            new VisitedSectorRepository($pdo),
            (int) ($appConfig['sessionTtlDays'] ?? 7),
            new MannyRepository($pdo, $gameplayConfig),
            new ApiKeyRepository($pdo),
            $sectorService,
            gameplayConfig: $gameplayConfig,
            universeConfig: $universeConfig,
        );
    }

    public function oauthService(): OAuthService
    {
        return new OAuthService(OAuthConfig::fromFile($this->projectRoot . '/config/oauth.json'));
    }

    public function appConfig(): array
    {
        return $this->configLoader()->load('app');
    }

    public function gameplayConfig(): array
    {
        return $this->configLoader()->load('gameplay');
    }

    public function universeConfig(): array
    {
        return $this->configLoader()->load('universe');
    }

    public function redisConfig(): array
    {
        return $this->configLoader()->load('redis');
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    private function configLoader(): JsonConfigLoader
    {
        return new JsonConfigLoader($this->projectRoot);
    }
}
