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
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerAuthRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\ProbeMessageRepository;
use VonNeumannGame\Repository\ProbeMovementRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\SessionRepository;
use VonNeumannGame\Repository\StorageContainerRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Service\MannyService;
use VonNeumannGame\Service\MovementDurationCalculator;
use VonNeumannGame\Service\ProbeMovementService;
use VonNeumannGame\Service\ProbeStorageService;
use VonNeumannGame\Service\SchedulerService;
use VonNeumannGame\Service\SectorObservationService;
use VonNeumannGame\Service\WaypointBookmarkService;
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
        $pdo ??= $this->pdo(initializeSchema: true);
        $appConfig = $this->appConfig();
        $gameplayConfig = $this->gameplayConfig();
        $universeConfig = $this->universeConfig();
        $players = new PlayerRepository($pdo);
        $authMethods = new PlayerAuthRepository($pdo);
        $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
        $mannies = new MannyRepository($pdo, $gameplayConfig);
        $items = new ProbeItemRepository($pdo);
        $storageContainers = new StorageContainerRepository($pdo, $gameplayConfig);
        $messages = new ProbeMessageRepository($pdo);
        $damageWarnings = new ProbeDamageWarningRepository($pdo);
        $forum = new ForumRepository($pdo);
        $movements = new ProbeMovementRepository($pdo);
        $scheduledEvents = new ScheduledEventRepository($pdo);
        $sessions = new SessionRepository($pdo);
        $apiKeys = new ApiKeyRepository($pdo);
        $visitedSectors = new VisitedSectorRepository($pdo);
        $sectorRepository = new SectorFileRepository($this->absolutePath((string) ($appConfig['universePath'] ?? 'data/universe')));
        $sectorService = new SectorService($sectorRepository, new SectorContentGenerator($universeConfig), (string) ($appConfig['worldSeed'] ?? 'default-world'));
        $auth = new AuthService($players, $authMethods, $probes, $sessions, $visitedSectors, (int) ($appConfig['sessionTtlDays'] ?? 7), $mannies, $apiKeys, $sectorService, gameplayConfig: $gameplayConfig, universeConfig: $universeConfig);
        $observations = new SectorObservationService($sectorService, $visitedSectors, config: $gameplayConfig, mannies: $mannies);
        $durations = new MovementDurationCalculator(Config::getArray($gameplayConfig, 'movement'));
        $storage = new ProbeStorageService($storageContainers, $items, $mannies, $probes, $gameplayConfig);
        $movementService = new ProbeMovementService($probes, $movements, $visitedSectors, $scheduledEvents, $sectorService, mannies: $mannies, storage: $storage, damageWarnings: $damageWarnings, durations: $durations, worldSeed: (string) ($appConfig['worldSeed'] ?? 'default-world'), gameplayConfig: $gameplayConfig);
        $bookmarks = new WaypointBookmarkService($items, $sectorService);
        $mannyService = new MannyService($mannies, $probes, $sectorService, $items, $storage, $gameplayConfig, $bookmarks);

        return new ApiKernel($auth, $probes, $observations, $movementService, $visitedSectors, $mannyService, $items, $storage, $messages, $damageWarnings, $forum, $gameplayConfig);
    }

    public function schedulerService(?PDO $pdo = null): SchedulerService
    {
        $pdo ??= $this->pdo(initializeSchema: true);
        $appConfig = $this->appConfig();
        $gameplayConfig = $this->gameplayConfig();
        $universeConfig = $this->universeConfig();
        $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
        $mannies = new MannyRepository($pdo, $gameplayConfig);
        $items = new ProbeItemRepository($pdo);
        $storageContainers = new StorageContainerRepository($pdo, $gameplayConfig);
        $movements = new ProbeMovementRepository($pdo);
        $visitedSectors = new VisitedSectorRepository($pdo);
        $scheduledEvents = new ScheduledEventRepository($pdo);
        $damageWarnings = new ProbeDamageWarningRepository($pdo);
        $sectorRepository = new SectorFileRepository($this->absolutePath((string) ($appConfig['universePath'] ?? 'data/universe')));
        $sectorService = new SectorService($sectorRepository, new SectorContentGenerator($universeConfig), (string) ($appConfig['worldSeed'] ?? 'default-world'));
        $durations = new MovementDurationCalculator(Config::getArray($gameplayConfig, 'movement'));
        $storage = new ProbeStorageService($storageContainers, $items, $mannies, $probes, $gameplayConfig);
        $movementService = new ProbeMovementService($probes, $movements, $visitedSectors, $scheduledEvents, $sectorService, mannies: $mannies, storage: $storage, damageWarnings: $damageWarnings, durations: $durations, worldSeed: (string) ($appConfig['worldSeed'] ?? 'default-world'), gameplayConfig: $gameplayConfig);

        return new SchedulerService($scheduledEvents, $probes, $movements, $movementService);
    }

    public function authService(PDO $pdo): AuthService
    {
        $appConfig = $this->appConfig();
        $gameplayConfig = $this->gameplayConfig();
        $universeConfig = $this->universeConfig();
        $sectorRepository = new SectorFileRepository($this->absolutePath((string) ($appConfig['universePath'] ?? 'data/universe')));
        $sectorService = new SectorService($sectorRepository, new SectorContentGenerator($universeConfig), (string) ($appConfig['worldSeed'] ?? 'default-world'));

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
