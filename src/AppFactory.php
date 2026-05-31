<?php

declare(strict_types=1);

namespace VonNeumannGame;

use PDO;
use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Auth\OAuthConfig;
use VonNeumannGame\Auth\OAuthService;
use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Http\ApiKernel;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerAuthRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeMovementRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\SessionRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Service\MannyService;
use VonNeumannGame\Service\ProbeMovementService;
use VonNeumannGame\Service\SchedulerService;
use VonNeumannGame\Service\SectorObservationService;
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
        $players = new PlayerRepository($pdo);
        $authMethods = new PlayerAuthRepository($pdo);
        $probes = new NeumannProbeRepository($pdo);
        $mannies = new MannyRepository($pdo);
        $movements = new ProbeMovementRepository($pdo);
        $scheduledEvents = new ScheduledEventRepository($pdo);
        $sessions = new SessionRepository($pdo);
        $visitedSectors = new VisitedSectorRepository($pdo);
        $auth = new AuthService($players, $authMethods, $probes, $sessions, $visitedSectors, (int) ($appConfig['sessionTtlDays'] ?? 7), $mannies);
        $sectorRepository = new SectorFileRepository($this->absolutePath((string) ($appConfig['universePath'] ?? 'data/universe')));
        $sectorService = new SectorService($sectorRepository, new SectorContentGenerator(), (string) ($appConfig['worldSeed'] ?? 'default-world'));
        $observations = new SectorObservationService($sectorService, $visitedSectors);
        $movementService = new ProbeMovementService($probes, $movements, $visitedSectors, $scheduledEvents, $sectorService, worldSeed: (string) ($appConfig['worldSeed'] ?? 'default-world'));
        $mannyService = new MannyService($mannies, $probes, $sectorService);

        return new ApiKernel($auth, $probes, $observations, $movementService, $visitedSectors, $mannyService);
    }

    public function schedulerService(?PDO $pdo = null): SchedulerService
    {
        $pdo ??= $this->pdo(initializeSchema: true);
        $appConfig = $this->appConfig();
        $probes = new NeumannProbeRepository($pdo);
        $movements = new ProbeMovementRepository($pdo);
        $visitedSectors = new VisitedSectorRepository($pdo);
        $scheduledEvents = new ScheduledEventRepository($pdo);
        $sectorRepository = new SectorFileRepository($this->absolutePath((string) ($appConfig['universePath'] ?? 'data/universe')));
        $sectorService = new SectorService($sectorRepository, new SectorContentGenerator(), (string) ($appConfig['worldSeed'] ?? 'default-world'));
        $movementService = new ProbeMovementService($probes, $movements, $visitedSectors, $scheduledEvents, $sectorService, worldSeed: (string) ($appConfig['worldSeed'] ?? 'default-world'));

        return new SchedulerService($scheduledEvents, $probes, $movements, $movementService);
    }

    public function authService(PDO $pdo): AuthService
    {
        $appConfig = $this->appConfig();

        return new AuthService(
            new PlayerRepository($pdo),
            new PlayerAuthRepository($pdo),
            new NeumannProbeRepository($pdo),
            new SessionRepository($pdo),
            new VisitedSectorRepository($pdo),
            (int) ($appConfig['sessionTtlDays'] ?? 7),
            new MannyRepository($pdo),
        );
    }

    public function oauthService(): OAuthService
    {
        return new OAuthService(OAuthConfig::fromFile($this->projectRoot . '/config/oauth.json'));
    }

    public function appConfig(): array
    {
        $path = $this->projectRoot . '/config/app.json';
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }
}
