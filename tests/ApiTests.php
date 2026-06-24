<?php

declare(strict_types=1);

use League\OAuth2\Client\Token\AccessToken;
use VonNeumannGame\Auth\AccountDeletionService;
use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Auth\OAuthConfig;
use VonNeumannGame\Auth\OAuthService;
use VonNeumannGame\Config\JsonConfigLoader;
use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Domain\Mission;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeDirection;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Domain\ProbeMessage;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Forum\ForumRepository;
use VonNeumannGame\FrontRoute\FrontRoute;
use VonNeumannGame\FrontRoute\FrontRouteFactory;
use VonNeumannGame\Http\ApiKernel;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\MissionRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ApiKeyRepository;
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
use VonNeumannGame\Service\MovementDurationCalculator;
use VonNeumannGame\Service\MannyService;
use VonNeumannGame\Service\MissionService;
use VonNeumannGame\Service\ProbeMovementService;
use VonNeumannGame\Service\ProbeReinstantiationService;
use VonNeumannGame\Service\ProbeStorageService;
use VonNeumannGame\Service\SchedulerService;
use VonNeumannGame\Service\SectorObservationService;
use VonNeumannGame\Service\UniverseStatsService;
use VonNeumannGame\Service\WaypointBookmarkService;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\BlackHole;
use VonNeumannGame\Sector\OrbitDescriptor;
use VonNeumannGame\Sector\OrbitingBody;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorDriftingItem;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\SolarSystem;
use VonNeumannGame\Sector\Star;

require_once __DIR__ . '/../vendor/autoload.php';

final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            echo "OK $message\n";
            return;
        }

        $this->failed++;
        $this->failures[] = $message;
        echo "FAIL $message\n";
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message): void
    {
        $this->assert($expected === $actual, "$message (expected: " . var_export($expected, true) . ', got: ' . var_export($actual, true) . ')');
    }

    public function assertThrows(callable $fn, string $message): void
    {
        try {
            $fn();
            $this->assert(false, $message . ' (nothing thrown)');
        } catch (Throwable) {
            $this->assert(true, $message);
        }
    }

    public function finish(): int
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "Tests passed: {$this->passed}\n";
        echo "Tests failed: {$this->failed}\n";
        foreach ($this->failures as $failure) {
            echo "  - $failure\n";
        }
        echo str_repeat('=', 60) . "\n";

        return $this->failed > 0 ? 1 : 0;
    }
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? removeDirectory($path) : unlink($path);
    }

    rmdir($directory);
}

/**
 * @param array<string, float> $resources
 */
function setProbeTestStoredResources(
    ProbeStorageService $storage,
    StorageContainerRepository $storageContainers,
    NeumannProbeRepository $probes,
    NeumannProbe $probe,
    array $resources,
): NeumannProbe {
    $storage->ensureProbeStorage($probe);
    $storageContainers->clearResourcesForProbe($probe->id);
    $probe = $probes->findById($probe->id) ?? $probe;
    $probe->metalsStock = 0.0;
    $probe->iceStock = 0.0;
    $probe->organicCompoundsStock = 0.0;
    $probes->save($probe);
    foreach ($resources as $type => $amount) {
        $storage->addResource($probe, (string) $type, (float) $amount);
    }

    return $probes->findById($probe->id) ?? $probe;
}

function fakeIdToken(array $payload): string
{
    $encode = static fn(array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return $encode(['alg' => 'none']) . '.' . $encode($payload) . '.signature';
}

class TestFooterFrontRoute extends FrontRoute
{
    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
        return '<p>Test route</p>';
    }
}

$test = new TestRunner();
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vng_api_tests_' . bin2hex(random_bytes(4));
$dbPath = $tmp . DIRECTORY_SEPARATOR . 'database.sqlite';
$universePath = $tmp . DIRECTORY_SEPARATOR . 'universe';
mkdir($tmp, 0775, true);

$testConfigPath = $tmp . DIRECTORY_SEPARATOR . 'config';
mkdir($testConfigPath, 0775, true);
file_put_contents($testConfigPath . DIRECTORY_SEPARATOR . 'gameplay.json', json_encode([
    'probe' => [
        'initialMannyCount' => 4,
        'maxDeuteriumPercent' => 100.0,
    ],
    'movement' => [
        'durationFactor' => 0.5,
        'preparationMinutes' => 10,
    ],
    'crafting' => [
        'steel_bar' => [
            'description' => 'Test steel bar description',
            'durationSeconds' => 300,
            'metalsCost' => 0.02,
        ],
    ],
    'listValues' => ['base', 'kept'],
], JSON_THROW_ON_ERROR));
file_put_contents($testConfigPath . DIRECTORY_SEPARATOR . 'gameplay-local.json', json_encode([
    'probe' => [
        'initialMannyCount' => 6,
    ],
    'movement' => [
        'durationFactor' => 1.0,
    ],
    'crafting' => [
        'steel_bar' => [
            'durationSeconds' => 123,
        ],
    ],
    'listValues' => ['local'],
], JSON_THROW_ON_ERROR));
$loadedGameplayConfig = (new JsonConfigLoader($tmp))->load('gameplay');
$test->assertEquals(6, $loadedGameplayConfig['probe']['initialMannyCount'] ?? null, 'local gameplay config overrides scalar values');
$test->assertEquals(100, $loadedGameplayConfig['probe']['maxDeuteriumPercent'] ?? null, 'local gameplay config keeps unspecified nested defaults');
$test->assertEquals(['local'], $loadedGameplayConfig['listValues'] ?? null, 'local gameplay config replaces list values');
$configuredSteelBar = CraftingRecipeCatalog::find('steel_bar', $loadedGameplayConfig['crafting'] ?? []);
$test->assertEquals(123, $configuredSteelBar['durationSeconds'] ?? null, 'crafting recipes consume gameplay config overrides');
$test->assertEquals('Test steel bar description', $configuredSteelBar['description'] ?? null, 'crafting recipes consume gameplay config descriptions');

file_put_contents($testConfigPath . DIRECTORY_SEPARATOR . 'additionalsfooterlinks.json', json_encode([], JSON_THROW_ON_ERROR));
file_put_contents($testConfigPath . DIRECTORY_SEPARATOR . 'additionalsfooterlinks-local.json', json_encode([
    [
        'label' => 'Tip the developer',
        'url' => 'https://ko-fi.com/neumannprobe',
        'type' => 'external',
    ],
], JSON_THROW_ON_ERROR));
$footerRoute = FrontRouteFactory::getRoute([
    [
        'name' => 'Accueil',
        'methods' => ['GET'],
        'uriPattern' => '/^\/$/',
        'routeClass' => '\\TestFooterFrontRoute',
        'linkUri' => '/',
        'displayOnFooter' => true,
    ],
], 'GET', '/', null, 'en', $tmp);
ob_start();
$footerRoute->handle('GET', '/', null, 'en');
$footerHtml = (string) ob_get_clean();
$test->assert(str_contains($footerHtml, '<a class="footer-link" href="/">Accueil</a>'), 'route footer links are still rendered');
$test->assert(
    str_contains($footerHtml, '<a class="footer-link footer-link-external" href="https://ko-fi.com/neumannprobe" rel="noopener noreferrer" target="_blank">Tip the developer</a>'),
    'additional footer links are loaded from local config and rendered as external links'
);

$oauthConfigPath = $tmp . DIRECTORY_SEPARATOR . 'oauth.json';
file_put_contents($oauthConfigPath, json_encode([
    'google' => ['web' => ['client_id' => 'google-client', 'client_secret' => 'google-secret']],
    'discord' => ['web' => ['client_id' => 'discord-client', 'client_secret' => 'discord-secret']],
    'github' => ['web' => ['client_id' => 'github-client', 'client_secret' => 'github-secret']],
], JSON_THROW_ON_ERROR));
$oauthService = new OAuthService(OAuthConfig::fromFile($oauthConfigPath));
$test->assertEquals(['google', 'discord', 'github'], $oauthService->availableProviders(), 'OAuth config exposes configured providers');
$githubProvider = $oauthService->createProvider('github', 'http://127.0.0.1:8000/auth/provider/github');
$githubAuthorizationUrl = $githubProvider->getAuthorizationUrl($oauthService->authorizationOptions('github'));
parse_str((string) parse_url($githubAuthorizationUrl, PHP_URL_QUERY), $githubAuthorizationQuery);
$test->assertEquals('', $githubAuthorizationQuery['scope'] ?? null, 'GitHub OAuth requests no additional user scopes');
$idToken = fakeIdToken(['sub' => 'google-openid-subject', 'aud' => 'google-client', 'exp' => time() + 3600]);
$test->assertEquals(
    'google-openid-subject',
    $oauthService->subjectFromAccessToken('google', new AccessToken(['access_token' => 'unused', 'id_token' => $idToken])),
    'OAuth service extracts the OpenID subject without profile data'
);
$loginTemplate = file_get_contents($root . '/templates/loginview.html');
$mainScript = file_get_contents($root . '/public/assets/main.js');
$movementScript = file_get_contents($root . '/public/assets/movement.js');
$movementTemplate = file_get_contents($root . '/templates/movement.html');
$manniesTemplate = file_get_contents($root . '/templates/mannies.html');
$statsTemplate = file_get_contents($root . '/templates/stats.html');
$statsRoute = file_get_contents($root . '/src/FrontRoute/FrontRouteStats.php');
$inventoriesScript = file_get_contents($root . '/public/assets/inventories.js');
$manniesScript = file_get_contents($root . '/public/assets/mannies.js');
$statsScript = file_get_contents($root . '/public/assets/stats.js');
$appCss = file_get_contents($root . '/public/assets/app.css');
$sensorsScript = file_get_contents($root . '/public/assets/sensors.js');
$sensorsTemplate = file_get_contents($root . '/templates/sensors.html');
$databaseMigrationScript = file_get_contents($root . '/scripts/migrate-sqlite-to-mysql.php');
$test->assert(is_string($loginTemplate) && str_contains($loginTemplate, 'id="oauth-remember"'), 'OAuth login view exposes the remember-me checkbox');
$test->assert(is_string($loginTemplate) && str_contains($loginTemplate, 'data-oauth-url='), 'OAuth login links keep their base URL for remember-me synchronization');
$test->assert(is_string($mainScript) && str_contains($mainScript, 'bindOAuthRememberChoice'), 'main JS binds OAuth remember-me synchronization');
$test->assert(is_string($mainScript) && str_contains($mainScript, 'url.searchParams.set("remember", "1")'), 'main JS sends remember=1 when OAuth remember-me is checked');
$test->assert(is_string($movementScript) && str_contains($movementScript, 'hasExplicitRouteTarget'), 'movement JS preserves explicit prepare-jump route targets');
$test->assert(is_string($movementScript) && str_contains($movementScript, 'currentSectorDestination'), 'movement JS disables jumps toward the current sector');
$test->assert(is_string($movementScript) && str_contains($movementScript, 'movementDestructionRiskKnown'), 'movement JS warns about configured long-jump destruction risk');
$test->assert(is_string($movementTemplate) && str_contains($movementTemplate, 'movement-risk-warning'), 'movement view exposes the long-jump risk warning container');
$test->assert(is_string($statsTemplate) && str_contains($statsTemplate, 'data-stats-podium-more'), 'stats view exposes top-nine expansion buttons');
$test->assert(is_string($statsRoute) && str_contains($statsRoute, 'data-stats-podium-extra hidden'), 'stats route renders extra ranking rows as hidden by default');
$test->assert(is_string($statsScript) && str_contains($statsScript, '[data-stats-podium-extra]'), 'stats JS toggles extra ranking rows');
$test->assert(is_string($inventoriesScript) && str_contains($inventoriesScript, 'inventory-container-rename-button'), 'inventories JS exposes the selected-container rename action');
$test->assert(is_string($inventoriesScript) && str_contains($inventoriesScript, 'inventory-container-rename-form'), 'inventories JS renames containers through an inline form');
$test->assert(is_string($inventoriesScript) && !str_contains($inventoriesScript, 'window.prompt'), 'inventories JS does not use prompt for container rename');
$test->assert(is_string($inventoriesScript) && str_contains($inventoriesScript, '"/api/probe/storage-containers/" + encodeURIComponent(containerId)'), 'inventories JS renames containers through the storage-container PATCH endpoint');
$test->assert(is_string($inventoriesScript) && str_contains($inventoriesScript, 'container.label !== "Sonde"'), 'inventories JS honors custom probe-core container labels');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'manny-mine-storage-target'), 'mannies JS exposes a mining storage destination selector');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'container.label !== "Sonde"'), 'mannies JS honors custom probe-core container labels');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'body.targetContainerId = targetContainerId'), 'mannies JS sends targetContainerId for external mining storage');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'detection.targetObjectId'), 'mannies JS uses explicit hidden-container asteroid targets when provided');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'miningTaskTargetContainerDetail'), 'mannies JS describes external mining storage in active Manny cards');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'withMannyStateHash'), 'mannies JS adds a stable state hash to each loaded Manny');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'data-manny-hash'), 'mannies JS exposes the Manny state hash on Manny cards');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'atomicPrinterStateHash'), 'mannies JS computes an atomic-printer state hash');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'data-printer-hash'), 'mannies JS exposes the atomic-printer state hash on the printer card');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'cursor.dataset.printerHash !== printerHash'), 'mannies JS rebuilds the atomic-printer card only when its hash changes');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, '"taskProgressPercent"'), 'mannies JS excludes live progress from Manny state hashes');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'progressDataAttributes'), 'mannies JS annotates progress values for local ticking');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'updateLiveProgressValues'), 'mannies JS updates progress percentages without refreshing data');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'remainingMinutesText'), 'mannies JS shows remaining task time next to progress percentages');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, '/ 60000'), 'mannies JS rounds remaining task time to minutes');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'MANNY_REFRESH_MS = 5000'), 'mannies JS refreshes Manny data every five seconds');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'scheduleMannyRefresh'), 'mannies JS schedules repeated Manny refreshes');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'card.dataset.mannyHash !== mannyHash'), 'mannies JS rebuilds a Manny card only when its hash changes');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'isMannyTaskAssignmentForm'), 'mannies JS can identify task assignment forms');
$test->assert(is_string($manniesScript) && str_contains($manniesScript, 'await loadManniesPage();'), 'mannies JS refreshes Manny cards immediately after a successful task assignment');
$test->assert(is_string($manniesTemplate) && !str_contains($manniesTemplate, 'data-refresh="mannies"'), 'mannies view does not expose a manual refresh button');
$test->assert(is_string($manniesScript) && !str_contains($manniesScript, 'recoverDetectedContainerHint'), 'mannies JS does not render hidden-container detection alerts inside Manny cards');
$test->assert(is_string($appCss) && !str_contains($appCss, 'manny-detection-alert'), 'mannies CSS no longer includes the removed in-card detection alert');
$test->assert(is_string($appCss) && str_contains($appCss, '.inventory-icon-button[hidden]'), 'inventories CSS keeps hidden icon buttons hidden after icon button display rules');
$test->assert(is_string($appCss) && str_contains($appCss, '.stats-podium-rank[hidden]'), 'stats CSS keeps hidden ranking rows hidden after podium display rules');
$test->assert(is_string($sensorsScript) && str_contains($sensorsScript, 'fetchVisitedSectors'), 'sensors JS can load visited-sector history');
$test->assert(is_string($sensorsTemplate) && str_contains($sensorsTemplate, 'visited-sector-history-panel'), 'sensors view exposes the visited-sector history panel');
$test->assert(is_string($databaseMigrationScript) && str_contains($databaseMigrationScript, 'BEGIN IMMEDIATE'), 'SQLite to MySQL migration script locks the source database');
$test->assert(is_string($databaseMigrationScript) && str_contains($databaseMigrationScript, 'SET FOREIGN_KEY_CHECKS=0'), 'SQLite to MySQL migration script can copy relational data into MySQL');
$test->assert(is_string($databaseMigrationScript) && str_contains($databaseMigrationScript, 'config/database-futur-local.json'), 'SQLite to MySQL migration script targets the future database config by default');
$test->assert(is_string($databaseMigrationScript) && str_contains($databaseMigrationScript, 'config/database.json.old'), 'SQLite to MySQL migration script backs up the active database config');
$schemaInitializer = file_get_contents($root . '/src/Database/SchemaInitializer.php');
$test->assert(is_string($schemaInitializer) && str_contains($schemaInitializer, 'recipient_type(32), recipient_id(191), status(32), created_at(32)'), 'MySQL probe message endpoint index stays within utf8mb4 key length limits');
$test->assert(is_string($schemaInitializer) && str_contains($schemaInitializer, 'username $caseSensitiveText NOT NULL UNIQUE'), 'MySQL usernames are created with case-sensitive uniqueness like SQLite');
$test->assert(is_string($schemaInitializer) && str_contains($schemaInitializer, "ensureMysqlColumnCollation(\$pdo, 'players', 'username', 'utf8mb4_bin'"), 'MySQL schema initialization repairs username collation on existing tables');
$wrongAudience = fakeIdToken(['sub' => 'google-openid-subject', 'aud' => 'another-client', 'exp' => time() + 3600]);
$test->assertThrows(
    fn() => $oauthService->subjectFromAccessToken('google', new AccessToken(['access_token' => 'unused', 'id_token' => $wrongAudience])),
    'OAuth service rejects an OpenID token for another client'
);

$legacyMessageDbPath = $tmp . DIRECTORY_SEPARATOR . 'legacy-message-schema.sqlite';
$legacyMessageFactory = new DatabaseConnectionFactory(new DatabaseConfig('sqlite', $legacyMessageDbPath), $root);
$legacyMessagePdo = $legacyMessageFactory->create();
$legacyMessagePdo->exec(
    "CREATE TABLE probe_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_probe_id INTEGER NOT NULL,
        recipient_probe_id INTEGER NOT NULL,
        sector_x INTEGER NOT NULL,
        sector_y INTEGER NOT NULL,
        sector_z INTEGER NOT NULL,
        body TEXT NOT NULL,
        status TEXT NOT NULL,
        read_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )"
);
$legacyMessagePdo->exec(
    "INSERT INTO probe_messages
     (sender_probe_id, recipient_probe_id, sector_x, sector_y, sector_z, body, status, read_at, created_at, updated_at)
     VALUES (1, 2, 0, 0, 0, 'Legacy hello', 'unread', NULL, '2026-06-18T00:00:00+00:00', '2026-06-18T00:00:00+00:00')"
);
$legacyMessageFactory->initializeSchema($legacyMessagePdo);
$legacyMessageColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $legacyMessagePdo->query('PRAGMA table_info(probe_messages)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('recipient_type', $legacyMessageColumns, true), 'legacy probe message schema migrates recipient_type before endpoint indexes');
$legacyEndpointIndexes = array_map(
    static fn(array $row): string => (string) $row['name'],
    $legacyMessagePdo->query('PRAGMA index_list(probe_messages)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('idx_probe_messages_recipient_endpoint', $legacyEndpointIndexes, true), 'legacy probe message schema creates endpoint recipient index after migration');
$legacyMessage = $legacyMessagePdo->query('SELECT sender_type, sender_id, recipient_type, recipient_id FROM probe_messages')->fetch(PDO::FETCH_ASSOC);
$test->assertEquals(['sender_type' => 'probe', 'sender_id' => '1', 'recipient_type' => 'probe', 'recipient_id' => '2'], $legacyMessage, 'legacy probe message migration backfills typed endpoints');

$statsRankingDbPath = $tmp . DIRECTORY_SEPARATOR . 'stats-ranking.sqlite';
$statsRankingFactory = new DatabaseConnectionFactory(new DatabaseConfig('sqlite', $statsRankingDbPath), $root);
$statsRankingPdo = $statsRankingFactory->create();
$statsRankingFactory->initializeSchema($statsRankingPdo);
$statsRankingPlayers = new PlayerRepository($statsRankingPdo);
$statsRankingProbes = new NeumannProbeRepository($statsRankingPdo);
$statsRankingVisitedSectors = new VisitedSectorRepository($statsRankingPdo);
for ($ranking = 1; $ranking <= 10; $ranking++) {
    $rankingPlayer = $statsRankingPlayers->createPlayer(
        'stats-ranking-' . $ranking,
        'Stats Ranking ' . $ranking,
        null,
        new SectorCoordinates(200 + ($ranking * 2), 0, 0),
    );
    $statsRankingProbes->createForPlayer($rankingPlayer->id, 'Stats Ranking Probe ' . $ranking, $rankingPlayer->homeSector);
    for ($visit = 0; $visit <= 10 - $ranking; $visit++) {
        $statsRankingVisitedSectors->markVisited($rankingPlayer, new SectorCoordinates(200 + ($ranking * 2), $visit * 2, 0));
    }
}
$rankingStats = (new UniverseStatsService($statsRankingPdo, $tmp . DIRECTORY_SEPARATOR . 'stats-ranking-universe'))->collect();
$topRankingProbes = $rankingStats['metrics']['topVisitedProbes'] ?? [];
$test->assertEquals(9, count($topRankingProbes), 'public stats visited-sector ranking exposes the first nine rows');
$test->assertEquals('Stats Ranking Probe 1', $topRankingProbes[0]['probeName'] ?? null, 'public stats top-nine ranking keeps the first-ranked probe first');
$test->assertEquals('Stats Ranking Probe 9', $topRankingProbes[8]['probeName'] ?? null, 'public stats top-nine ranking keeps the ninth-ranked probe visible');
$test->assert(!in_array('Stats Ranking Probe 10', array_column($topRankingProbes, 'probeName'), true), 'public stats top-nine ranking excludes the tenth row');

$dbFactory = new DatabaseConnectionFactory(new DatabaseConfig('sqlite', $dbPath), $root);
$pdo = $dbFactory->create();
$dbFactory->initializeSchema($pdo);
$test->assert(is_file($dbPath), 'temporary SQLite database is created');
$probeSchemaColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $pdo->query('PRAGMA table_info(neumann_probes)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('ice_stock', $probeSchemaColumns, true), 'Probe table stores ice stock explicitly');
$test->assert(in_array('organic_compounds_stock', $probeSchemaColumns, true), 'Probe table stores organic-compound stock explicitly');
$test->assert(in_array('exclude_from_stats', $probeSchemaColumns, true), 'Probe table can exclude probes from public stats');
$test->assert(!in_array('other_stock', $probeSchemaColumns, true), 'Probe table no longer stores generic other_stock');
$mannySchemaColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $pdo->query('PRAGMA table_info(mannies)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('cargo_ice', $mannySchemaColumns, true), 'Manny table stores ice cargo explicitly');
$test->assert(in_array('cargo_organic_compounds', $mannySchemaColumns, true), 'Manny table stores organic-compound cargo explicitly');
$test->assert(in_array('storage_container_id', $mannySchemaColumns, true), 'Manny table stores its storage container');
$test->assert(!in_array('cargo_other', $mannySchemaColumns, true), 'Manny table no longer stores generic cargo_other');
$itemSchemaColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $pdo->query('PRAGMA table_info(probe_items)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('storage_container_id', $itemSchemaColumns, true), 'Probe item table stores its storage container');
$messageSchemaColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $pdo->query('PRAGMA table_info(probe_messages)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('sender_probe_id', $messageSchemaColumns, true), 'Probe message table stores sender probes');
$test->assert(in_array('recipient_probe_id', $messageSchemaColumns, true), 'Probe message table stores recipient probes');
$test->assert(in_array('sender_type', $messageSchemaColumns, true), 'Probe message table stores typed senders');
$test->assert(in_array('recipient_type', $messageSchemaColumns, true), 'Probe message table stores typed recipients');
$test->assert(in_array('read_at', $messageSchemaColumns, true), 'Probe message table stores read timestamps');
$playerSchemaColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $pdo->query('PRAGMA table_info(players)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('forum_admin', $playerSchemaColumns, true), 'Player table stores forum admin flag');
$test->assert(in_array('forum_moderator', $playerSchemaColumns, true), 'Player table stores forum moderator flag');
$damageWarningSchemaColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $pdo->query('PRAGMA table_info(probe_damage_warnings)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('container_id', $damageWarningSchemaColumns, true), 'Probe damage warning table stores container ids');
$test->assert(in_array('status', $damageWarningSchemaColumns, true), 'Probe damage warning table stores read status');
$forumCategorySchemaColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $pdo->query('PRAGMA table_info(forum_categories)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('sort_order', $forumCategorySchemaColumns, true), 'Forum categories store a sort order');
$forumPostSchemaColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $pdo->query('PRAGMA table_info(forum_posts)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('pinned', $forumPostSchemaColumns, true), 'Forum posts store pinned state');
$test->assert(in_array('first_message_id', $forumPostSchemaColumns, true), 'Forum posts link their first message');
$test->assert(in_array('message_count', $forumPostSchemaColumns, true), 'Forum posts store message counts');
$forumMessageSchemaColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $pdo->query('PRAGMA table_info(forum_messages)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('body', $forumMessageSchemaColumns, true), 'Forum messages store a body');
$test->assert(in_array('edited_at', $forumMessageSchemaColumns, true), 'Forum messages store an explicit edit timestamp');
$missionSchemaColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $pdo->query('PRAGMA table_info(probe_missions)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('probe_id', $missionSchemaColumns, true), 'Mission table assigns missions to probes');
$test->assert(in_array('status', $missionSchemaColumns, true), 'Mission table stores mission status');
$test->assert(in_array('step_order', $missionSchemaColumns, true), 'Mission table stores step ordering mode');
$missionStepSchemaColumns = array_map(
    static fn(array $row): string => (string) $row['name'],
    $pdo->query('PRAGMA table_info(probe_mission_steps)')->fetchAll(PDO::FETCH_ASSOC),
);
$test->assert(in_array('mission_id', $missionStepSchemaColumns, true), 'Mission step table links steps to missions');
$test->assert(in_array('sort_order', $missionStepSchemaColumns, true), 'Mission step table stores step order');

$players = new PlayerRepository($pdo);
$authMethods = new PlayerAuthRepository($pdo);
$probes = new NeumannProbeRepository($pdo);
$mannies = new MannyRepository($pdo);
$items = new ProbeItemRepository($pdo);
$messages = new ProbeMessageRepository($pdo);
$missions = new MissionRepository($pdo);
$damageWarnings = new ProbeDamageWarningRepository($pdo);
$forum = new ForumRepository($pdo);
$storageContainers = new StorageContainerRepository($pdo);
$movements = new ProbeMovementRepository($pdo);
$scheduledEvents = new ScheduledEventRepository($pdo);
$sessions = new SessionRepository($pdo);
$apiKeys = new ApiKeyRepository($pdo);
$visitedSectors = new VisitedSectorRepository($pdo);
$sectorRepository = new SectorFileRepository($universePath);
$sectorService = new SectorService($sectorRepository, new SectorContentGenerator(), 'api-test-world');
$auth = new AuthService($players, $authMethods, $probes, $sessions, $visitedSectors, 7, $mannies, $apiKeys, $sectorService);
$storage = new ProbeStorageService($storageContainers, $items, $mannies, $probes);
$missionService = new MissionService($missions, $messages, [], 'api-test-world', $sectorService, $probes, $players);
$movementService = new ProbeMovementService($probes, $movements, $visitedSectors, $scheduledEvents, $sectorService, mannies: $mannies, storage: $storage, damageWarnings: $damageWarnings, missions: $missionService, worldSeed: 'api-test-world');
$bookmarkService = new WaypointBookmarkService($items, $sectorService);
$mannyService = new MannyService($mannies, $probes, $sectorService, $items, $storage, bookmarks: $bookmarkService, missions: $missionService);
$scheduler = new SchedulerService($scheduledEvents, $probes, $movements, $movementService);
$reinstantiation = new ProbeReinstantiationService($pdo, $players, $probes, $mannies, $visitedSectors, $sectorService);
$kernel = new ApiKernel($auth, $probes, new SectorObservationService($sectorService, $visitedSectors, mannies: $mannies), $movementService, $visitedSectors, $mannyService, $items, $storage, $messages, $damageWarnings, $forum, $missionService, $reinstantiation);

$apiVersion = $kernel->handle('GET', '/api/version');
$test->assertEquals(200, $apiVersion->status, 'GET /api/version is public');
$test->assertEquals(47, $apiVersion->body['apiVersion'] ?? null, 'GET /api/version exposes the current API version');
$apiVersionWrongMethod = $kernel->handle('POST', '/api/version');
$test->assertEquals(405, $apiVersionWrongMethod->status, 'POST /api/version is rejected');

$privacyHome = new SectorCoordinates(1000, 1000, 0);
$privacySector = new SectorCoordinates(1002, 1000, 0);
$privacyRelativeLabel = '2:0:0';
$leakyPlanetName = 'Signal-' . str_replace(':', '-', $privacySector->toKey());
$sectorRepository->save(new SectorContent($privacySector, [
    new Planet('privacy-life-planet', $leakyPlanetName, 'ocean', 1.0, 1.0, true, 0.82, ['water_ice'], intelligentLife: true),
    new SolarSystem(
        'privacy-system',
        null,
        new Star('privacy-star', null, 'G', 1.0, 5778, 1.0, 1.0),
        null,
        [
            new OrbitingBody(
                new Planet('privacy-nested-life-planet', $leakyPlanetName, 'rocky', 1.0, 1.0, true, 0.72, ['silicates'], intelligentLife: true),
                new OrbitDescriptor(1.0, 0.01, 0.0),
            ),
        ],
        1.0,
        4.0,
    ),
]));
$privacyObservation = (new SectorObservationService($sectorService, $visitedSectors))->observe(
    new Player(424242, 'privacy-observer', 'Privacy Observer', $privacyHome, gmdate('c'), gmdate('c')),
    new NeumannProbe(424242, 424242, 'Privacy probe', $privacySector, 0.0, 0.0, new ProbeDirection(), ProbeStatus::Idle, 100.0, 0.0, 100.0, 0.0, 0.0, 0.0, 1.0, null, gmdate('c'), gmdate('c'), gmdate('c'), false),
    $privacySector,
)->toArray();
$privacyJson = json_encode($privacyObservation, JSON_THROW_ON_ERROR);
$privacyTopObject = $privacyObservation['objects'][0] ?? [];
$privacyNestedTarget = $privacyObservation['objects'][1]['bookmarkTargets'][1] ?? [];
$test->assertEquals(['x' => 2, 'y' => 0, 'z' => 0], $privacyObservation['relativeCoordinates'] ?? null, 'sector observation computes player-relative coordinates');
$test->assertEquals('Monde habite du secteur relatif ' . $privacyRelativeLabel, $privacyTopObject['name'] ?? null, 'sector observation hides absolute coordinates in unnamed inhabited planet labels');
$test->assertEquals('Monde habite du secteur relatif ' . $privacyRelativeLabel, $privacyNestedTarget['name'] ?? null, 'nested inhabited planet targets hide absolute-coordinate debug names');
$test->assert(!str_contains($privacyJson, $privacySector->toKey()), 'sector observation payload does not expose absolute sector keys through inhabited planet names');
$test->assert(!str_contains($privacyJson, str_replace(':', '-', $privacySector->toKey())), 'sector observation payload does not expose hyphenated absolute sector keys through inhabited planet names');
$forceInhabitedOutput = [];
$forceInhabitedExit = 0;
exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../scripts/force-inhabited-planet.php') . ' --sector=-114:-348:-650 --dry-run 2>&1', $forceInhabitedOutput, $forceInhabitedExit);
$forceInhabitedText = implode("\n", $forceInhabitedOutput);
$test->assertEquals(0, $forceInhabitedExit, 'force-inhabited-planet dry-run exits successfully');
$test->assert(str_contains($forceInhabitedText, '- planet id: debug-inhabited-'), 'force-inhabited-planet dry-run reports an opaque debug planet id');
$test->assert(!str_contains($forceInhabitedText, 'debug-inhabited-n114-n348-n650'), 'force-inhabited-planet default id does not expose encoded absolute coordinates');
$test->assert(!str_contains($forceInhabitedText, '- planet id: debug-inhabited--114:-348:-650'), 'force-inhabited-planet default id does not expose raw absolute coordinates');

$movementTimeline = (new MovementDurationCalculator())->timeline(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 2);
$test->assertEquals('2026-01-01T00:05:00+00:00', $movementTimeline['preparationEndsAt']->format('c'), 'beta movement preparation delay is halved');
$test->assertEquals('2026-01-01T00:25:00+00:00', $movementTimeline['accelerationEndsAt']->format('c'), 'beta movement acceleration delay is halved');
$test->assertEquals('2026-01-01T00:55:00+00:00', $movementTimeline['cruiseEndsAt']->format('c'), 'beta movement cruise delay is halved');
$test->assertEquals('2026-01-01T01:15:00+00:00', $movementTimeline['arrivalAt']->format('c'), 'beta movement total delay is halved');

$player = $auth->registerPlayerWithPassword('remi', 'secret', 'Remi');
$test->assert($player->id > 0, 'user creation returns a persisted player');
$test->assert($player->forumAdmin === false, 'new players are not forum admins by default');
$test->assert($player->forumModerator === false, 'new players are not forum moderators by default');
$player->forumModerator = true;
$players->save($player);
$player = $players->findById($player->id) ?? throw new RuntimeException('Expected player to be persisted.');
$test->assert($player->forumModerator === true, 'player repository persists forum moderator flag');
$test->assert(($player->publicArray()['forumAdmin'] ?? null) === false, 'player public array exposes forum admin flag');
$test->assert(($player->publicArray()['forumModerator'] ?? null) === true, 'player public array exposes forum moderator flag');
$createdProbe = $probes->findByPlayerId($player->id);
$test->assert($createdProbe !== null, 'a probe is automatically created for a new player');
$test->assert($createdProbe?->excludeFromStats === false, 'new probes are included in public stats by default');
$test->assert(!$player->homeSector->equals(SectorCoordinates::origin()), 'new player receives a pseudo-random absolute home sector');
$homeSum = $player->homeSector->getX() + $player->homeSector->getY() + $player->homeSector->getZ();
$test->assert($homeSum % 2 === 0, 'random initial sector respects the FCC parity constraint');
$test->assert($visitedSectors->hasVisited($player, $player->homeSector), 'initial sector is automatically marked as visited');
if ($createdProbe !== null) {
    $test->assert($createdProbe->currentSector->equals($player->homeSector), 'initial probe starts in the player home sector');
    $missionHeaders = ['Authorization' => 'Bearer ' . $auth->createSessionForPlayer($player)['token']];
    $emptyMissions = $kernel->handle('GET', '/api/probe/missions', $missionHeaders);
    $test->assertEquals(200, $emptyMissions->status, 'GET /api/probe/missions returns mission list');
    $test->assertEquals([], $emptyMissions->body['missions'] ?? null, 'new probes start without active missions');
    $legacyMissionRoute = $kernel->handle('GET', '/api/probe/mission', $missionHeaders);
    $test->assertEquals(200, $legacyMissionRoute->status, 'GET /api/probe/mission remains a mission-list alias');
    $createdMission = $missionService->startMission(
        $createdProbe,
        'test_signal',
        'Signal de test',
        'Mission générée par le test API.',
        \VonNeumannGame\Domain\Mission::STEP_ORDER_SEQUENTIAL,
        ['test' => true],
        ['type' => 'api_test'],
        [
            ['title' => 'Identifier le signal', 'metadata' => ['event' => 'scan']],
            ['title' => 'Répondre', 'description' => 'Attendre une future action joueur.'],
        ],
    );
    $activeMissions = $kernel->handle('GET', '/api/probe/missions', $missionHeaders);
    $test->assertEquals(200, $activeMissions->status, 'GET /api/probe/missions lists active missions');
    $test->assertEquals($createdMission->uid, $activeMissions->body['missions'][0]['id'] ?? null, 'mission response exposes a stable public mission id');
    $test->assertEquals('sequential', $activeMissions->body['missions'][0]['stepOrder'] ?? null, 'mission response exposes step ordering');
    $test->assertEquals(2, count($activeMissions->body['missions'][0]['steps'] ?? []), 'mission response exposes mission steps');
    $abandonedMission = $kernel->handle('POST', '/api/probe/missions/' . rawurlencode($createdMission->uid) . '/abandon', $missionHeaders);
    $test->assertEquals(200, $abandonedMission->status, 'POST /api/probe/missions/{missionId}/abandon abandons an active mission');
    $test->assertEquals('abandoned', $abandonedMission->body['mission']['status'] ?? null, 'abandoned mission response exposes terminal status');
    $missionsAfterAbandon = $kernel->handle('GET', '/api/probe/missions', $missionHeaders);
    $test->assertEquals([], $missionsAfterAbandon->body['missions'] ?? null, 'abandoned missions are no longer listed as active');
    $secondAbandon = $kernel->handle('POST', '/api/probe/missions/' . rawurlencode($createdMission->uid) . '/abandon', $missionHeaders);
    $test->assertEquals(409, $secondAbandon->status, 'abandoning a terminal mission is rejected');
    $missingMission = $kernel->handle('POST', '/api/probe/missions/missing/abandon', $missionHeaders);
    $test->assertEquals(404, $missingMission->status, 'abandoning an unknown mission is rejected');
    $sequentialMission = $missionService->startMission(
        $createdProbe,
        'sequential_test',
        'Sequential mission test',
        stepOrder: \VonNeumannGame\Domain\Mission::STEP_ORDER_SEQUENTIAL,
        steps: [
            ['title' => 'First sequential step'],
            ['title' => 'Second sequential step'],
        ],
    );
    $sequentialStepIds = array_map(static fn($step): string => $step->uid, $sequentialMission->steps);
    $test->assertThrows(
        fn() => $missionService->completeStep($createdProbe, $sequentialMission->uid, $sequentialStepIds[1] ?? ''),
        'sequential missions block later steps until earlier steps complete'
    );
    $afterFirstStep = $missionService->completeStep($createdProbe, $sequentialMission->uid, $sequentialStepIds[0] ?? '');
    $test->assertEquals('active', $afterFirstStep->status, 'sequential mission remains active after an intermediate step');
    $afterSecondStep = $missionService->completeStep($createdProbe, $sequentialMission->uid, $sequentialStepIds[1] ?? '');
    $test->assertEquals('completed', $afterSecondStep->status, 'sequential mission completes after its final step');
    $userinfosDbConfig = $tmp . DIRECTORY_SEPARATOR . 'userinfos-database.json';
    file_put_contents($userinfosDbConfig, json_encode([
        'driver' => 'sqlite',
        'path' => $dbPath,
    ], JSON_THROW_ON_ERROR));
    $userinfosCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/scripts/userinfos.php')
        . ' --database-config=' . escapeshellarg($userinfosDbConfig)
        . ' --probe-id=' . $createdProbe->id
        . ' --limit=3';
    exec($userinfosCommand . ' 2>&1', $userinfosOutput, $userinfosStatus);
    $userinfosText = implode("\n", $userinfosOutput);
    $test->assertEquals(0, $userinfosStatus, 'userinfos CLI exits successfully for an existing probe');
    $test->assert(str_contains($userinfosText, 'absolute: '), 'userinfos CLI reports absolute position');
    $test->assert(str_contains($userinfosText, 'relative: 0:0:0 from player home'), 'userinfos CLI reports relative position');
    $test->assert(str_contains($userinfosText, 'Inventory'), 'userinfos CLI reports inventory state');
    $test->assert(str_contains($userinfosText, 'Visited sectors'), 'userinfos CLI reports visited sector history');

    $cliSectorUniverse = $tmp . DIRECTORY_SEPARATOR . 'cli-sector-universe';
    $cliSectorRepository = new SectorFileRepository($cliSectorUniverse);
    $cliSectorCoordinates = new SectorCoordinates(4, 0, 0);
    $cliSectorRepository->save(new SectorContent($cliSectorCoordinates, source: 'cli-test'));
    $sectorJsonCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/scripts/sector-json.php')
        . ' --universe-path=' . escapeshellarg($cliSectorUniverse)
        . ' 4 0 0';
    exec($sectorJsonCommand . ' 2>&1', $sectorJsonOutput, $sectorJsonStatus);
    $sectorJsonText = implode("\n", $sectorJsonOutput);
    $test->assertEquals(0, $sectorJsonStatus, 'sector-json CLI exits successfully for an existing sector');
    $test->assertEquals(['x' => 4, 'y' => 0, 'z' => 0], json_decode($sectorJsonText, true, 512, JSON_THROW_ON_ERROR)['coordinates'] ?? null, 'sector-json CLI prints the sector JSON');
    $sectorJsonPathCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/scripts/sector-json.php')
        . ' --universe-path=' . escapeshellarg($cliSectorUniverse)
        . ' --path-only'
        . ' --sector=4,0,0';
    exec($sectorJsonPathCommand . ' 2>&1', $sectorJsonPathOutput, $sectorJsonPathStatus);
    $test->assertEquals(0, $sectorJsonPathStatus, 'sector-json CLI path-only exits successfully');
    $test->assertEquals($cliSectorRepository->getPath($cliSectorCoordinates), $sectorJsonPathOutput[0] ?? null, 'sector-json CLI path-only prints the resolved sector path');

    $cliInventoryPlayer = $auth->registerPlayerWithPassword('cli-inventory', 'secret', 'CLI Inventory');
    $cliInventoryProbe = $probes->findByPlayerId($cliInventoryPlayer->id);
    if ($cliInventoryProbe !== null) {
        $pdo->prepare('UPDATE neumann_probes SET exclude_from_stats = 1 WHERE id = :id')->execute(['id' => $cliInventoryProbe->id]);
        $addInventoryItemCommand = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($root . '/scripts/add-inventory-item.php')
            . ' --database-config=' . escapeshellarg($userinfosDbConfig)
            . ' ' . escapeshellarg(ProbeItem::TYPE_STEEL_BAR)
            . ' 2'
            . ' ' . $cliInventoryPlayer->id;
        exec($addInventoryItemCommand . ' 2>&1', $addInventoryItemOutput, $addInventoryItemStatus);
        $addInventoryItemText = implode("\n", $addInventoryItemOutput);
        $test->assertEquals(0, $addInventoryItemStatus, 'add-inventory-item CLI exits successfully for craftable items');
        $test->assert(str_contains($addInventoryItemText, 'Added 2 x Steel bar'), 'add-inventory-item CLI reports added craftable items');
        $cliSteelBars = array_values(array_filter(
            $items->findByProbeId($cliInventoryProbe->id),
            static fn(ProbeItem $item): bool => $item->type === ProbeItem::TYPE_STEEL_BAR,
        ));
        $test->assertEquals(2, count($cliSteelBars), 'add-inventory-item CLI creates one item row per requested quantity');

        $beforeDryRunCount = count($items->findByProbeId($cliInventoryProbe->id));
        $dryRunInventoryItemCommand = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($root . '/scripts/add-inventory-item.php')
            . ' --database-config=' . escapeshellarg($userinfosDbConfig)
            . ' --dry-run'
            . ' ' . escapeshellarg(ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT)
            . ' 1'
            . ' ' . $cliInventoryPlayer->id;
        exec($dryRunInventoryItemCommand . ' 2>&1', $dryRunInventoryItemOutput, $dryRunInventoryItemStatus);
        $test->assertEquals(0, $dryRunInventoryItemStatus, 'add-inventory-item CLI dry-run exits successfully');
        $test->assertEquals($beforeDryRunCount, count($items->findByProbeId($cliInventoryProbe->id)), 'add-inventory-item CLI dry-run leaves inventory unchanged');

        $addContainerCommand = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($root . '/scripts/add-inventory-item.php')
            . ' --database-config=' . escapeshellarg($userinfosDbConfig)
            . ' ' . escapeshellarg(ProbeItem::TYPE_ADDITIONAL_CONTAINER)
            . ' 1'
            . ' ' . $cliInventoryPlayer->id;
        exec($addContainerCommand . ' 2>&1', $addContainerOutput, $addContainerStatus);
        $test->assertEquals(0, $addContainerStatus, 'add-inventory-item CLI exits successfully for additional containers');
        $cliContainers = array_values(array_filter(
            $items->findByProbeId($cliInventoryProbe->id),
            static fn(ProbeItem $item): bool => $item->type === ProbeItem::TYPE_ADDITIONAL_CONTAINER,
        ));
        $test->assertEquals(1, count($cliContainers), 'add-inventory-item CLI creates an additional-container item');
        $test->assert(
            $storageContainers->findByUidForProbe($cliInventoryProbe->id, 'container-' . ($cliContainers[0]->uid ?? 'missing')) !== null,
            'add-inventory-item CLI creates the paired storage container for additional containers',
        );

        $beforeMannyCount = count($mannies->findByProbeId($cliInventoryProbe->id));
        $addMannyCommand = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($root . '/scripts/add-inventory-item.php')
            . ' --database-config=' . escapeshellarg($userinfosDbConfig)
            . ' manny 1'
            . ' ' . $cliInventoryPlayer->id;
        exec($addMannyCommand . ' 2>&1', $addMannyOutput, $addMannyStatus);
        $test->assertEquals(0, $addMannyStatus, 'add-inventory-item CLI exits successfully for Mannys');
        $cliMannies = $mannies->findByProbeId($cliInventoryProbe->id);
        $test->assertEquals($beforeMannyCount + 1, count($cliMannies), 'add-inventory-item CLI creates real Manny entities');
        $debugMannies = array_values(array_filter(
            $cliMannies,
            static fn($manny): bool => str_starts_with($manny->name, 'manny-debug-'),
        ));
        $test->assert($debugMannies !== [] && $debugMannies[0]->storageContainerId !== null, 'add-inventory-item CLI places created Mannys in storage');
    }

    $pdo->prepare('UPDATE neumann_probes SET deuterium_stock = 9 WHERE id = :id')->execute(['id' => $createdProbe->id]);
    $deuteriumAsteroidCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/scripts/add-deuterium-asteroid-alert.php')
        . ' --database-config=' . escapeshellarg($userinfosDbConfig)
        . ' --universe-path=' . escapeshellarg($universePath)
        . ' --amount=12.5'
        . ' ' . escapeshellarg($player->username);
    exec($deuteriumAsteroidCommand . ' 2>&1', $deuteriumAsteroidOutput, $deuteriumAsteroidStatus);
    $deuteriumAsteroidText = implode("\n", $deuteriumAsteroidOutput);
    $test->assertEquals(0, $deuteriumAsteroidStatus, 'deuterium asteroid CLI exits successfully');
    $test->assert(str_contains($deuteriumAsteroidText, 'alert id:'), 'deuterium asteroid CLI creates an alert');
    $deuteriumSector = $sectorRepository->load($createdProbe->currentSector);
    $deuteriumAsteroids = array_values(array_filter(
        $deuteriumSector->getObjects(),
        static fn($object): bool => $object instanceof Asteroid
            && ($object->toArray()['resourceAmounts']['deuterium'] ?? 0.0) === 12.5,
    ));
    $test->assert(count($deuteriumAsteroids) >= 1, 'deuterium asteroid CLI persists a mineable asteroid in the current sector');
    $deuteriumAsteroidData = $deuteriumAsteroids[0]->toArray();
    $test->assertEquals('Astéroïde contenant du Deutérium', $deuteriumAsteroidData['name'] ?? null, 'deuterium asteroid CLI gives the asteroid a public non-debug name');
    $test->assert(!str_contains(strtolower(json_encode($deuteriumAsteroidData, JSON_THROW_ON_ERROR)), 'debug'), 'deuterium asteroid CLI does not expose debug wording on the asteroid');
    $deuteriumAlerts = $kernel->handle('GET', '/api/probe/alerts', $missionHeaders);
    $deuteriumAlert = null;
    foreach ($deuteriumAlerts->body['alerts'] ?? [] as $alert) {
        if (($alert['type'] ?? null) === 'sector_object_detected') {
            $deuteriumAlert = $alert;
            break;
        }
    }
    $test->assert($deuteriumAlert !== null, 'GET /api/probe/alerts exposes CLI object-detection alerts');
    if ($deuteriumAlert !== null) {
        $test->assertEquals('asteroid', $deuteriumAlert['object']['type'] ?? null, 'object-detection alert exposes the object type');
        $test->assertEquals(['deuterium'], $deuteriumAlert['object']['resourceTypes'] ?? null, 'object-detection alert exposes deuterium resources');
        $test->assert(str_contains((string) ($deuteriumAlert['message'] ?? ''), 'deut'), 'object-detection alert stores the detection message');
        $test->assert(!str_contains(json_encode($deuteriumAlert, JSON_THROW_ON_ERROR), $createdProbe->currentSector->toKey()), 'object-detection alert response does not expose absolute sector keys');
    }
    $legacyDamageWarnings = $kernel->handle('GET', '/api/probe/damage-warnings', $missionHeaders);
    $test->assertEquals([], $legacyDamageWarnings->body['damageWarnings'] ?? null, 'legacy damage warnings route excludes object-detection alerts');
    $alertsScript = file_get_contents($root . '/public/assets/alerts.js');
    $test->assert(is_string($alertsScript) && str_contains($alertsScript, '/api/probe/alerts'), 'alerts page uses the generic persistent-alert endpoint');

    $lowFuelCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/scripts/add-deuterium-asteroid-alerts-for-low-fuel.php')
        . ' --database-config=' . escapeshellarg($userinfosDbConfig)
        . ' --universe-path=' . escapeshellarg($universePath)
        . ' --amount=7'
        . ' 10';
    exec($lowFuelCommand . ' 2>&1', $lowFuelOutput, $lowFuelStatus);
    $lowFuelText = implode("\n", $lowFuelOutput);
    $test->assertEquals(0, $lowFuelStatus, 'low-fuel deuterium asteroid CLI exits successfully');
    $test->assert(str_contains($lowFuelText, 'processed: 1'), 'low-fuel CLI calls the per-player script for matching players');
    $pdo->prepare('UPDATE neumann_probes SET deuterium_stock = 100 WHERE id = :id')->execute(['id' => $createdProbe->id]);

    $storageRepairSession = $auth->createSessionForPlayer($player);
    $storageRepairHeaders = ['Authorization' => 'Bearer ' . $storageRepairSession['token']];
    $initialMannyStorageRepair = $kernel->handle('GET', '/api/probe/mannies', $storageRepairHeaders);
    $test->assertEquals(200, $initialMannyStorageRepair->status, 'GET /api/probe/mannies initializes storage for a newly created probe');
    $coreContainer = $storageContainers->findByUidForProbe($createdProbe->id, 'probe-core');
    $test->assert($coreContainer !== null, 'Manny list repair creates the probe core storage container');
    if ($coreContainer !== null) {
        foreach ($mannies->findByProbeId($createdProbe->id) as $manny) {
            $test->assertEquals($coreContainer->id, $manny->storageContainerId, 'Manny list repair assigns onboard Mannys to the core container');
        }
    }

    $orphanedItem = $items->create($createdProbe->id, ProbeItem::TYPE_STEEL_BAR, ProbeItem::STEEL_BAR_NAME, 0.01, storageContainerId: 999999);
    $pdo->prepare('UPDATE mannies SET storage_container_id = 999999 WHERE probe_id = :probe_id AND location_type = :location_type')->execute([
        'probe_id' => $createdProbe->id,
        'location_type' => 'probe',
    ]);
    $pdo->prepare('DELETE FROM storage_container_resources WHERE container_id IN (SELECT id FROM storage_containers WHERE probe_id = :probe_id)')->execute(['probe_id' => $createdProbe->id]);
    $pdo->prepare('DELETE FROM storage_containers WHERE probe_id = :probe_id')->execute(['probe_id' => $createdProbe->id]);

    $orphanedStorageRepair = $kernel->handle('GET', '/api/probe/mannies', $storageRepairHeaders);
    $test->assertEquals(200, $orphanedStorageRepair->status, 'GET /api/probe/mannies repairs missing storage containers');
    $recreatedCore = $storageContainers->findByUidForProbe($createdProbe->id, 'probe-core');
    $test->assert($recreatedCore !== null, 'orphaned storage repair recreates the probe core container');
    if ($recreatedCore !== null) {
        foreach ($mannies->findByProbeId($createdProbe->id) as $manny) {
            $test->assertEquals($recreatedCore->id, $manny->storageContainerId, 'orphaned storage repair moves onboard Mannys back to a known container');
        }
        $test->assertEquals($recreatedCore->id, $items->findByUidForProbe($createdProbe->id, $orphanedItem->uid)?->storageContainerId, 'orphaned storage repair moves items back to a known container');
    }
    $storedOrphanedItem = $items->findByUidForProbe($createdProbe->id, $orphanedItem->uid);
    if ($storedOrphanedItem !== null) {
        $items->delete($storedOrphanedItem);
    }
}
$test->assertThrows(
    fn() => $auth->registerPlayerWithPassword('remi', 'other-secret', 'Duplicate'),
    'creating two users with the same username is rejected'
);
$test->assert($auth->authenticateWithPassword('remi', 'secret')?->id === $player->id, 'AuthService verifies a valid password');
$test->assert($auth->authenticateWithPassword('remi', 'bad-password') === null, 'AuthService rejects an invalid password');

$oauthPlayer = $auth->registerPlayerWithExternalAuth('Nova Pilot', 'google', 'google-openid-subject');
$test->assert($oauthPlayer->id > 0, 'OAuth registration creates a persisted player');
$test->assertEquals('Nova Pilot', $oauthPlayer->username, 'OAuth registration uses the chosen pseudonym');
$test->assert($auth->authenticateWithExternal('google', 'google-openid-subject')?->id === $oauthPlayer->id, 'OAuth identity authenticates the existing player');
$oauthProbe = $probes->findByPlayerId($oauthPlayer->id);
$test->assert($oauthProbe !== null, 'OAuth registration creates an initial probe');
$test->assert($oauthProbe?->currentSector->equals($oauthPlayer->homeSector), 'OAuth probe starts in the player home sector');
$test->assert($visitedSectors->hasVisited($oauthPlayer, $oauthPlayer->homeSector), 'OAuth registration marks the initial sector as visited');
if ($oauthProbe !== null) {
    $oauthProbe->excludeFromStats = true;
    $probes->save($oauthProbe);
    $stats = (new UniverseStatsService($pdo, $universePath))->collect();
    $test->assertEquals(1, $stats['metrics']['probesInUniverse'] ?? null, 'public stats exclude probes marked as test probes');
    $topVisitedProbes = $stats['metrics']['topVisitedProbes'] ?? [];
    $test->assertEquals('Probe of remi', $topVisitedProbes[0]['probeName'] ?? null, 'public stats podium ranks included probes by visited sectors');
    $test->assertEquals(1, $topVisitedProbes[0]['visitedSectors'] ?? null, 'public stats podium exposes visited-sector counts');
    $test->assert(!in_array('Sonde de Nova Pilot', array_column($topVisitedProbes, 'probeName'), true), 'public stats podium excludes hidden test probes');

    $statsUniversePath = $tmp . DIRECTORY_SEPARATOR . 'stats-universe';
    $statsSectorRepository = new SectorFileRepository($statsUniversePath);
    $statsVisitedSector = $player->homeSector;
    $statsGeneratedOnlySector = new SectorCoordinates(
        $statsVisitedSector->getX() + 2,
        $statsVisitedSector->getY(),
        $statsVisitedSector->getZ(),
    );
    $statsSectorRepository->save(new SectorContent($statsVisitedSector, [
        new SolarSystem(
            'stats-visited-system',
            'Stats visited system',
            new Star('stats-visited-star', null, 'G', 1.0, 5800, 1.0, 1.0),
            null,
            [
                new OrbitingBody(
                    new Planet('stats-visited-habitable', null, 'rocky', 1.0, 1.0, true, 0.7, intelligentLife: true),
                    new OrbitDescriptor(1.0, 0.01, 0.0),
                ),
                new OrbitingBody(
                    new Planet('stats-visited-background', null, 'rocky', 1.0, 1.0, true, 0.18),
                    new OrbitDescriptor(1.6, 0.01, 0.0),
                ),
            ],
            3.0,
            1.6,
        ),
    ]));
    $statsSectorRepository->save(new SectorContent($statsGeneratedOnlySector, [
        new Planet('stats-generated-habitable-top-level', null, 'ocean', 1.0, 1.1, true, 0.8, intelligentLife: true),
        new SolarSystem(
            'stats-generated-system',
            'Stats generated system',
            new Star('stats-generated-star', null, 'K', 0.8, 4900, 0.8, 0.9),
            null,
            [
                new OrbitingBody(
                    new Planet('stats-generated-habitable-nested', null, 'rocky', 1.0, 1.0, true, 0.4),
                    new OrbitDescriptor(0.8, 0.01, 0.0),
                ),
            ],
            2.0,
            0.8,
        ),
    ]));
    $habitabilityStats = (new UniverseStatsService($pdo, $statsUniversePath))->collect();
    $test->assertEquals(3, $habitabilityStats['metrics']['habitablePlanetsInGeneratedSectors'] ?? null, 'public stats count habitable planets in generated sectors');
    $test->assertEquals(1, $habitabilityStats['metrics']['habitablePlanetsInVisitedSectors'] ?? null, 'public stats count habitable planets in visited sectors');
    $test->assertEquals(1, $habitabilityStats['metrics']['intelligentLifeWorlds'] ?? null, 'public stats count discovered intelligent-life worlds');
    $topIntelligentLifeDiscoverers = $habitabilityStats['metrics']['topIntelligentLifeDiscoverers'] ?? [];
    $test->assertEquals('Remi', $topIntelligentLifeDiscoverers[0]['playerName'] ?? null, 'public stats intelligent-life podium ranks discoverers');
    $test->assertEquals(1, $topIntelligentLifeDiscoverers[0]['intelligentLifeWorlds'] ?? null, 'public stats intelligent-life podium exposes discovered world counts');
}
$test->assertThrows(
    fn() => $auth->registerPlayerWithExternalAuth('Nova Pilot', 'discord', 'discord-openid-subject'),
    'OAuth registration rejects an already used pseudonym'
);
$test->assertThrows(
    fn() => $auth->registerPlayerWithExternalAuth('x', 'google', 'other-google-subject'),
    'OAuth registration rejects invalid pseudonyms'
);

$deletePlayer = $auth->registerPlayerWithPassword('delete-me', 'secret', 'Delete Me', 'Delete probe');
$deleteProbe = $probes->findByPlayerId($deletePlayer->id);
$deleteMannies = $deleteProbe === null ? [] : $mannies->findByProbeId($deleteProbe->id);
$deleteSession = $auth->createSessionForPlayer($deletePlayer);
$auth->createApiKeyForPlayer($deletePlayer);
if ($deleteProbe !== null && $createdProbe !== null && count($deleteMannies) >= 2) {
    $deleteSentMessage = $messages->create($deleteProbe->id, $createdProbe->id, $deleteProbe->currentSector, 'Deleting sender ping');
    $deleteReceivedMessage = $messages->create($createdProbe->id, $deleteProbe->id, $deleteProbe->currentSector, 'Deleting recipient ping');
    $deleteMission = $missionService->startMission(
        $deleteProbe,
        'delete_test',
        'Delete test mission',
        steps: [
            ['title' => 'First deleted step'],
            ['title' => 'Second deleted step'],
        ],
    );
    $outsideManny = $deleteMannies[0];
    $onboardManny = $deleteMannies[1];
    $outsideManny->locationType = 'sector';
    $outsideManny->sector = $deleteProbe->currentSector;
    $outsideManny->currentTask = 'mining';
    $outsideManny->taskStartedAt = gmdate('c', time() - 60);
    $outsideManny->taskEndsAt = gmdate('c', time() + 60);
    $outsideManny->taskPayload = ['objectId' => 'delete-test-rock'];
    $mannies->save($outsideManny);

    $deleteSector = $sectorService->getOrCreateSector($deleteProbe->currentSector);
    $forgottenMannyObject = new SectorManny(
        SectorManny::objectIdForUid($outsideManny->uid),
        $outsideManny->name,
        $outsideManny->uid,
        SectorManny::STATE_FORGOTTEN,
        $outsideManny->cargoArray(),
        'Manny left behind by its probe.',
    );
    if (!$deleteSector->replaceObject($forgottenMannyObject)) {
        $deleteSector->addObject($forgottenMannyObject);
    }
    $sectorService->saveSector($deleteSector);

    $deleteStats = (new AccountDeletionService($pdo, $probes, $mannies, $sectorService))->deletePlayer($deletePlayer);
    $test->assertEquals(1, $deleteStats['players'] ?? null, 'account deletion reports the deleted player');
    $test->assertEquals(1, $deleteStats['probes'] ?? null, 'account deletion reports the deleted probe');
    $test->assertEquals(1, $deleteStats['probeMessagesSent'] ?? null, 'account deletion reports sent probe messages');
    $test->assertEquals(1, $deleteStats['probeMessagesReceived'] ?? null, 'account deletion reports received probe messages');
    $test->assertEquals(1, $deleteStats['probeMissions'] ?? null, 'account deletion reports probe missions');
    $test->assertEquals(2, $deleteStats['probeMissionSteps'] ?? null, 'account deletion reports probe mission steps');
    $test->assertEquals(1, $deleteStats['manniesDetachedAsAbandoned'] ?? null, 'account deletion detaches outside Mannys as abandoned');
    $test->assert($players->findById($deletePlayer->id) === null, 'account deletion removes the player row');
    $test->assert($probes->findByPlayerId($deletePlayer->id) === null, 'account deletion removes the player probe');
    $test->assert($messages->findById($deleteSentMessage->id) === null, 'account deletion removes messages sent by the deleted probe');
    $test->assert($messages->findById($deleteReceivedMessage->id) === null, 'account deletion removes messages received by the deleted probe');
    $test->assert($missions->findByUidForProbe($deleteProbe->id, $deleteMission->uid) === null, 'account deletion removes probe missions');
    $test->assert($auth->getPlayerFromBearerToken('Bearer ' . $deleteSession['token']) === null, 'account deletion removes active sessions');
    $test->assert($mannies->findByUid($onboardManny->uid) === null, 'account deletion removes onboard Mannys');
    $detachedManny = $mannies->findByUid($outsideManny->uid);
    $test->assert($detachedManny !== null, 'account deletion keeps outside Mannys recoverable');
    $test->assertEquals(null, $detachedManny?->probeId, 'account deletion detaches outside Mannys from the deleted probe');
    $test->assertEquals(null, $detachedManny?->currentTask, 'account deletion clears outside Manny tasks');
    $deletedPlayerSector = $sectorRepository->load($deleteProbe->currentSector);
    $abandonedMannyObject = $deletedPlayerSector->findObjectById(SectorManny::objectIdForUid($outsideManny->uid));
    $test->assertEquals(SectorManny::STATE_ABANDONED, $abandonedMannyObject?->toArray()['state'] ?? null, 'account deletion marks outside sector Mannys as abandoned');
}

$orphanObserver = $auth->registerPlayerWithPassword('orphan-observer', 'secret', 'Orphan Observer', 'Orphan observer probe');
$orphanProbe = $probes->findByPlayerId($orphanObserver->id);
if ($orphanProbe !== null) {
    $sectorRepository->save(new SectorContent($orphanProbe->currentSector, [
        new SectorManny(
            SectorManny::objectIdForUid('mny_deleted_owner'),
            'orphaned-manny',
            'mny_deleted_owner',
            SectorManny::STATE_FORGOTTEN,
            [],
            'Manny left behind by a vanished probe.',
        ),
    ]));
    $orphanObservation = (new SectorObservationService($sectorService, $visitedSectors, mannies: $mannies))
        ->observe($orphanObserver, $orphanProbe, $orphanProbe->currentSector)
        ->toArray();
    $orphanManny = $orphanObservation['objects'][0] ?? null;
    $test->assertEquals(SectorManny::STATE_ABANDONED, $orphanManny['mannyState'] ?? null, 'orphaned forgotten sector Mannys become abandoned on observation');
    $test->assertEquals(true, $orphanManny['salvageable'] ?? null, 'orphaned forgotten sector Mannys become salvageable');
    $persistedOrphanSector = $sectorRepository->load($orphanProbe->currentSector);
    $test->assertEquals(
        SectorManny::STATE_ABANDONED,
        $persistedOrphanSector->findObjectById(SectorManny::objectIdForUid('mny_deleted_owner'))?->toArray()['state'] ?? null,
        'orphaned forgotten Manny conversion is persisted to sector JSON',
    );
}

$englishObserver = $auth->registerPlayerWithPassword('english-observer', 'secret', 'English Observer', 'English probe');
$englishProbe = $probes->findByPlayerId($englishObserver->id);
if ($englishProbe !== null) {
    $sectorRepository->save(new SectorContent($englishProbe->currentSector, [
        new Asteroid(
            'english-asteroid',
            null,
            'iron',
            ['iron'],
            'small',
            0.01,
            0.1,
        ),
    ]));
    $englishObservation = (new SectorObservationService($sectorService, $visitedSectors, mannies: $mannies))
        ->observe($englishObserver, $englishProbe, $englishProbe->currentSector)
        ->toArray();
    $test->assertEquals('Wandering asteroid body.', $englishObservation['objects'][0]['summary'] ?? null, 'sector observation summaries stay in API English');
}

$goodSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'remi', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$test->assertEquals(200, $goodSession->status, 'POST /api/session with good password returns 200');
$token = $goodSession->body['token'] ?? null;
$test->assert(is_string($token) && strlen($token) >= 40, 'POST /api/session returns a sufficiently long token');

$badSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'remi', 'password' => 'wrong'], JSON_THROW_ON_ERROR));
$test->assertEquals(401, $badSession->status, 'POST /api/session with bad password returns 401');
$recipesWithoutToken = $kernel->handle('GET', '/api/crafting-recipes');
$test->assertEquals(401, $recipesWithoutToken->status, 'GET /api/crafting-recipes requires authentication');

$plainStored = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE token_hash = :token');
$plainStored->execute(['token' => $token]);
$test->assertEquals(0, (int) $plainStored->fetchColumn(), 'session token is not stored in clear text');

$hashedStored = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE token_hash = :hash');
$hashedStored->execute(['hash' => SessionRepository::hashToken((string) $token)]);
$test->assertEquals(1, (int) $hashedStored->fetchColumn(), 'session token hash is stored');

$headers = ['Authorization' => 'Bearer ' . $token];
$me = $kernel->handle('GET', '/api/me', $headers);
$test->assertEquals(200, $me->status, 'valid token allows GET /api/me');
$test->assertEquals('remi', $me->body['player']['username'] ?? null, 'GET /api/me returns the player');

$forumUser = $auth->registerPlayerWithPassword('forum-user', 'secret', 'Forum User');
$forumUserSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'forum-user', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$forumUserHeaders = ['Authorization' => 'Bearer ' . (string) ($forumUserSession->body['token'] ?? '')];
$forumOtherUser = $auth->registerPlayerWithPassword('forum-other-user', 'secret', 'Forum Other User');
$forumOtherUserSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'forum-other-user', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$forumOtherUserHeaders = ['Authorization' => 'Bearer ' . (string) ($forumOtherUserSession->body['token'] ?? '')];
$forumAdmin = $auth->registerPlayerWithPassword('forum-admin', 'secret', 'Forum Admin');
$forumAdmin->forumAdmin = true;
$players->save($forumAdmin);
$forumAdminSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'forum-admin', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$forumAdminHeaders = ['Authorization' => 'Bearer ' . (string) ($forumAdminSession->body['token'] ?? '')];

$forumCategoryDenied = $kernel->handle('POST', '/api/forum/categories', $forumUserHeaders, json_encode(['name' => 'Operations'], JSON_THROW_ON_ERROR));
$test->assertEquals(403, $forumCategoryDenied->status, 'forum category creation requires forum admin permission');
$forumCategoryCreated = $kernel->handle('POST', '/api/forum/categories', $forumAdminHeaders, json_encode(['name' => 'Operations', 'description' => 'Shipboard discussions', 'sortOrder' => 20], JSON_THROW_ON_ERROR));
$test->assertEquals(201, $forumCategoryCreated->status, 'forum admins can create categories');
$forumCategoryId = (int) ($forumCategoryCreated->body['category']['id'] ?? 0);
$forumCategoryUpdated = $kernel->handle('PATCH', '/api/forum/categories/' . $forumCategoryId, $forumAdminHeaders, json_encode(['sortOrder' => 10], JSON_THROW_ON_ERROR));
$test->assertEquals(200, $forumCategoryUpdated->status, 'forum admins can update categories');
$test->assertEquals(10, $forumCategoryUpdated->body['category']['sortOrder'] ?? null, 'forum category sort order is mutable');
$forumCategories = $kernel->handle('GET', '/api/forum/categories', $forumUserHeaders);
$test->assertEquals(200, $forumCategories->status, 'forum users can list categories');
$test->assertEquals('Operations', $forumCategories->body['categories'][0]['name'] ?? null, 'forum category list exposes created category');

$forumPostCreated = $kernel->handle('POST', '/api/forum/posts', $forumUserHeaders, json_encode(['categoryId' => $forumCategoryId, 'title' => 'First contact', 'body' => 'Hello forum.'], JSON_THROW_ON_ERROR));
$test->assertEquals(201, $forumPostCreated->status, 'forum users can create posts');
$forumPostId = (int) ($forumPostCreated->body['post']['id'] ?? 0);
$test->assertEquals(1, $forumPostCreated->body['post']['messageCount'] ?? null, 'forum post creation also creates the first message');
$forumFirstMessageId = (int) ($forumPostCreated->body['post']['firstMessageId'] ?? 0);
$test->assert($forumFirstMessageId > 0, 'forum post stores its first message id');
$test->assertEquals($forumFirstMessageId, $forumPostCreated->body['firstMessage']['id'] ?? null, 'forum post creation exposes the first message');
$test->assertEquals('Hello forum.', $forumPostCreated->body['firstMessage']['body'] ?? null, 'forum first message exposes the post body');
$forumMessageCreated = $kernel->handle('POST', '/api/forum/posts/' . $forumPostId . '/messages', $forumUserHeaders, json_encode(['body' => 'A first reply.'], JSON_THROW_ON_ERROR));
$test->assertEquals(201, $forumMessageCreated->status, 'forum users can reply to posts');
$forumMessageId = (int) ($forumMessageCreated->body['message']['id'] ?? 0);
$forumSecondPostCreated = $kernel->handle('POST', '/api/forum/posts', $forumUserHeaders, json_encode(['categoryId' => $forumCategoryId, 'title' => 'Second contact', 'body' => 'Another thread.'], JSON_THROW_ON_ERROR));
$test->assertEquals(201, $forumSecondPostCreated->status, 'forum users can create multiple posts');
$forumPosts = $kernel->handle('GET', '/api/forum/posts?limit=1&offset=0', $forumUserHeaders);
$test->assertEquals(200, $forumPosts->status, 'forum users can list posts');
$test->assertEquals(1, $forumPosts->body['pagination']['limit'] ?? null, 'forum post list accepts a limit');
$test->assertEquals(true, $forumPosts->body['pagination']['hasMore'] ?? null, 'forum post list exposes pagination hasMore');
$forumPostWithMessages = $kernel->handle('GET', '/api/forum/posts/' . $forumPostId . '?limit=1&offset=1', $forumUserHeaders);
$test->assertEquals(200, $forumPostWithMessages->status, 'forum users can read one post with messages');
$test->assertEquals($forumFirstMessageId, $forumPostWithMessages->body['firstMessage']['id'] ?? null, 'forum post detail exposes the first message separately');
$test->assertEquals([], $forumPostWithMessages->body['messages'] ?? null, 'forum post messages pagination excludes the first message from replies');
$test->assertEquals(1, $forumPostWithMessages->body['pagination']['offset'] ?? null, 'forum post message list accepts an offset for older messages');
$forumPostPatchDenied = $kernel->handle('PATCH', '/api/forum/posts/' . $forumPostId, $forumUserHeaders, json_encode(['pinned' => true], JSON_THROW_ON_ERROR));
$test->assertEquals(403, $forumPostPatchDenied->status, 'regular forum users cannot pin posts');
$forumPostPinned = $kernel->handle('PATCH', '/api/forum/posts/' . $forumPostId, $headers, json_encode(['pinned' => true, 'title' => 'Pinned contact'], JSON_THROW_ON_ERROR));
$test->assertEquals(200, $forumPostPinned->status, 'forum moderators can pin and edit posts');
$test->assertEquals(true, $forumPostPinned->body['post']['pinned'] ?? null, 'forum post pinned state is persisted');
$forumMessageOtherUserEditDenied = $kernel->handle('PATCH', '/api/forum/messages/' . $forumMessageId, $forumOtherUserHeaders, json_encode(['body' => 'Hijacked reply.'], JSON_THROW_ON_ERROR));
$test->assertEquals(403, $forumMessageOtherUserEditDenied->status, 'regular forum users cannot edit other users messages');
$forumMessageAuthorEdited = $kernel->handle('PATCH', '/api/forum/messages/' . $forumMessageId, $forumUserHeaders, json_encode(['body' => 'An author-edited reply.'], JSON_THROW_ON_ERROR));
$test->assertEquals(200, $forumMessageAuthorEdited->status, 'forum message authors can edit their own messages');
$test->assertEquals('An author-edited reply.', $forumMessageAuthorEdited->body['message']['body'] ?? null, 'forum author edit updates message body');
$test->assert(is_string($forumMessageAuthorEdited->body['message']['editedAt'] ?? null), 'forum author edit exposes editedAt');
$forumMessageEdited = $kernel->handle('PATCH', '/api/forum/messages/' . $forumMessageId, $headers, json_encode(['body' => 'A moderated reply.'], JSON_THROW_ON_ERROR));
$test->assertEquals(200, $forumMessageEdited->status, 'forum moderators can edit messages');
$test->assertEquals('A moderated reply.', $forumMessageEdited->body['message']['body'] ?? null, 'forum message body is updated');
$forumMessageDeleted = $kernel->handle('DELETE', '/api/forum/messages/' . $forumMessageId, $headers);
$test->assertEquals(200, $forumMessageDeleted->status, 'forum moderators can delete messages');
$forumPostDeleted = $kernel->handle('DELETE', '/api/forum/posts/' . $forumPostId, $headers);
$test->assertEquals(200, $forumPostDeleted->status, 'forum moderators can delete posts');
$forumCategoryDeleted = $kernel->handle('DELETE', '/api/forum/categories/' . $forumCategoryId, $forumAdminHeaders);
$test->assertEquals(200, $forumCategoryDeleted->status, 'forum admins can delete categories');

$craftingRecipes = $kernel->handle('GET', '/api/crafting-recipes', $headers);
$test->assertEquals(200, $craftingRecipes->status, 'valid token allows GET /api/crafting-recipes');
$test->assertEquals('waypoint_bookmark', $craftingRecipes->body['recipes'][0]['id'] ?? null, 'crafting recipes expose waypoint bookmark');
$test->assertEquals(['manny'], $craftingRecipes->body['recipes'][0]['craftableBy'] ?? null, 'waypoint bookmark is craftable by Manny');
$test->assertEquals(
    'A transmitting beacon placed on an object such as an asteroid or planet, or set in orbit around a star or gas giant. Its message can be read by every Neumann probe present in the sector.',
    $craftingRecipes->body['recipes'][0]['description'] ?? null,
    'waypoint bookmark recipe exposes its description',
);
$test->assertEquals('metals', $craftingRecipes->body['recipes'][0]['ingredients'][0]['type'] ?? null, 'waypoint bookmark recipe uses metals');
$test->assertEquals(0.01, $craftingRecipes->body['recipes'][0]['ingredients'][0]['quantity'] ?? null, 'waypoint bookmark recipe consumes 0.01 metal containers');
$test->assertEquals('earth_container_equivalent', $craftingRecipes->body['recipes'][0]['ingredients'][0]['unit'] ?? null, 'waypoint bookmark ingredient quantity uses cargo units');
$test->assertEquals(600, $craftingRecipes->body['recipes'][0]['durationSeconds'] ?? null, 'waypoint bookmark takes ten real minutes to craft');
$test->assertEquals('waypoint_bookmark', $craftingRecipes->body['recipes'][0]['output']['type'] ?? null, 'waypoint bookmark recipe exposes its output item');
$recipesById = [];
foreach ($craftingRecipes->body['recipes'] ?? [] as $recipe) {
    $recipesById[$recipe['id'] ?? ''] = $recipe;
}
$test->assert(isset($recipesById['steel_bar']), 'crafting recipes expose steel bars');
$test->assertEquals(0.02, $recipesById['steel_bar']['ingredients'][0]['quantity'] ?? null, 'steel bar recipe consumes 0.02 metal containers');
$test->assertEquals(300, $recipesById['steel_bar']['durationSeconds'] ?? null, 'steel bar takes five real minutes to craft');
$test->assertEquals(0.01, $recipesById['steel_bar']['output']['containerSpace'] ?? null, 'steel bar occupies 0.01 containers');
$test->assert(isset($recipesById['steel_plate']), 'crafting recipes expose steel plates');
$test->assertEquals(0.02, $recipesById['steel_plate']['ingredients'][0]['quantity'] ?? null, 'steel plate recipe consumes 0.02 metal containers');
$test->assertEquals(300, $recipesById['steel_plate']['durationSeconds'] ?? null, 'steel plate takes five real minutes to craft');
$test->assertEquals(0.01, $recipesById['steel_plate']['output']['containerSpace'] ?? null, 'steel plate occupies 0.01 containers');
$test->assert(isset($recipesById['additional_container']), 'crafting recipes expose additional containers');
$test->assertEquals('steel_plate', $recipesById['additional_container']['ingredients'][0]['type'] ?? null, 'additional container requires steel plates');
$test->assertEquals(12, $recipesById['additional_container']['ingredients'][0]['quantity'] ?? null, 'additional container requires twelve steel plates');
$test->assertEquals('steel_bar', $recipesById['additional_container']['ingredients'][1]['type'] ?? null, 'additional container requires steel bars');
$test->assertEquals(15, $recipesById['additional_container']['ingredients'][1]['quantity'] ?? null, 'additional container requires fifteen steel bars');
$test->assertEquals(180, $recipesById['additional_container']['durationSeconds'] ?? null, 'additional container base assembly takes three real minutes');
$test->assertEquals(0.0, $recipesById['additional_container']['output']['containerSpace'] ?? null, 'additional container occupies no storage');
$test->assertEquals(1.0, $recipesById['additional_container']['output']['capacityBonus'] ?? null, 'additional container adds one container of storage');
$test->assert(isset($recipesById['micro_conductor']), 'crafting recipes expose micro-etched conductors');
$test->assertEquals(['atomic_3d_printer'], $recipesById['micro_conductor']['craftableBy'] ?? null, 'micro-conductor recipe uses the atomic printer');
$test->assertEquals('metals', $recipesById['micro_conductor']['ingredients'][0]['type'] ?? null, 'micro-conductor recipe uses metals');
$test->assertEquals(0.04, $recipesById['micro_conductor']['ingredients'][0]['quantity'] ?? null, 'micro-conductor recipe consumes 0.04 metal containers');
$test->assertEquals('deuterium', $recipesById['micro_conductor']['ingredients'][1]['type'] ?? null, 'micro-conductor recipe uses deuterium energy');
$test->assertEquals(0.01, $recipesById['micro_conductor']['ingredients'][1]['quantity'] ?? null, 'micro-conductor recipe consumes 0.01 deuterium containers');
$test->assert(isset($recipesById['ceramic_insulator']), 'crafting recipes expose ceramo-organic insulators');
$test->assertEquals('ice', $recipesById['ceramic_insulator']['ingredients'][0]['type'] ?? null, 'ceramic insulator recipe uses ice');
$test->assertEquals('carbon_compounds', $recipesById['ceramic_insulator']['ingredients'][1]['type'] ?? null, 'ceramic insulator recipe uses organic compounds');
$test->assert(isset($recipesById['crystal_substrate']), 'crafting recipes expose crystal substrates');
$test->assert(isset($recipesById['dopant_matrix']), 'crafting recipes expose dopant matrices');
$test->assert(isset($recipesById['integrated_circuit']), 'crafting recipes expose integrated circuits');
$test->assertEquals(['atomic_3d_printer'], $recipesById['integrated_circuit']['craftableBy'] ?? null, 'integrated-circuit recipe uses the atomic printer');
$test->assertEquals('micro_conductor', $recipesById['integrated_circuit']['ingredients'][0]['type'] ?? null, 'integrated circuit requires micro conductors');
$test->assertEquals(2, $recipesById['integrated_circuit']['ingredients'][0]['quantity'] ?? null, 'integrated circuit requires two micro conductors');
$test->assertEquals('integrated_circuit', $recipesById['integrated_circuit']['output']['type'] ?? null, 'integrated circuit recipe exposes its output item');
$test->assertEquals(0.001, $recipesById['integrated_circuit']['output']['containerSpace'] ?? null, 'integrated circuit occupies a tiny storage space');
$test->assert(isset($recipesById['electric_motor']), 'crafting recipes expose electric motors');
$test->assertEquals(['manny'], $recipesById['electric_motor']['craftableBy'] ?? null, 'electric motor is assembled by Manny');
$test->assertEquals('steel_bar', $recipesById['electric_motor']['ingredients'][0]['type'] ?? null, 'electric motor requires steel bars');
$test->assert(isset($recipesById['battery_pack']), 'crafting recipes expose battery packs');
$test->assertEquals('carbon_compounds', $recipesById['battery_pack']['ingredients'][2]['type'] ?? null, 'battery pack uses organic compounds');
$test->assert(isset($recipesById['linear_actuator']), 'crafting recipes expose linear actuators');
$test->assertEquals('electric_motor', $recipesById['linear_actuator']['ingredients'][2]['type'] ?? null, 'linear actuator requires an electric motor');
$test->assert(isset($recipesById['thermal_protection_shell']), 'crafting recipes expose thermal protection shells');
$test->assertEquals('ceramic_insulator', $recipesById['thermal_protection_shell']['ingredients'][0]['type'] ?? null, 'thermal protection shells require ceramic insulators');
$test->assert(isset($recipesById['parachute_pack']), 'crafting recipes expose parachute packs');
$test->assertEquals('carbon_compounds', $recipesById['parachute_pack']['ingredients'][2]['type'] ?? null, 'parachute packs use organic compounds');
$test->assert(isset($recipesById['descent_guidance_module']), 'crafting recipes expose descent guidance modules');
$test->assertEquals('integrated_circuit', $recipesById['descent_guidance_module']['ingredients'][0]['type'] ?? null, 'descent guidance modules require integrated circuits');
$test->assert(isset($recipesById['atmospheric_drop_kit']), 'crafting recipes expose atmospheric drop kits');
$test->assertEquals('thermal_protection_shell', $recipesById['atmospheric_drop_kit']['ingredients'][0]['type'] ?? null, 'atmospheric drop kits require thermal protection shells');
$test->assertEquals(0.08, $recipesById['atmospheric_drop_kit']['output']['containerSpace'] ?? null, 'atmospheric drop kits occupy their configured storage space');
$test->assert(isset($recipesById['manny']), 'crafting recipes expose Mannys');
$test->assertEquals('manny', $recipesById['manny']['output']['type'] ?? null, 'Manny recipe outputs a real Manny');
$test->assertEquals(0.05, $recipesById['manny']['output']['containerSpace'] ?? null, 'crafted Manny occupies the standard Manny storage space');

$craftPlayer = $auth->registerPlayerWithPassword('crafter', 'secret', 'Crafter');
$craftProbeEntity = $probes->findByPlayerId($craftPlayer->id);
$craftSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'crafter', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$craftHeaders = ['Authorization' => 'Bearer ' . (string) ($craftSession->body['token'] ?? '')];
$craftMannyList = $kernel->handle('GET', '/api/probe/mannies', $craftHeaders);
$craftMannyId = (string) ($craftMannyList->body['mannies'][0]['id'] ?? '');
$craftSpareMannyId = (string) ($craftMannyList->body['mannies'][1]['id'] ?? '');
$test->assert($craftProbeEntity !== null && $craftMannyId !== '', 'crafting test probe has a Manny');

if ($craftProbeEntity !== null && $craftMannyId !== '') {
    if ($craftSpareMannyId !== '') {
        $pdo->prepare(
            'UPDATE mannies SET location_type = :location_type, sector_x = :x, sector_y = :y, sector_z = :z, storage_container_id = NULL WHERE uid = :uid'
        )->execute([
            'uid' => $craftSpareMannyId,
            'location_type' => 'sector',
            'x' => $craftProbeEntity->currentSector->getX(),
            'y' => $craftProbeEntity->currentSector->getY(),
            'z' => $craftProbeEntity->currentSector->getZ(),
        ]);
    }
    $craftProbeEntity = setProbeTestStoredResources($storage, $storageContainers, $probes, $craftProbeEntity, ['metals' => 0.54]);
    $rawContainerCraft = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($craftMannyId) . '/craft', $craftHeaders, json_encode([
        'recipe' => 'additional_container',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $rawContainerCraft->status, 'Manny can start an additional-container craft from raw metals');
    $test->assertEquals('additional_container', $rawContainerCraft->body['manny']['task']['recipe'] ?? null, 'additional-container task stores its recipe');
    $test->assertEquals(0.54, $rawContainerCraft->body['manny']['task']['metalsCost'] ?? null, 'raw additional-container craft consumes all component metal costs');
    $test->assertEquals(8280, $rawContainerCraft->body['manny']['task']['durationSeconds'] ?? null, 'raw additional-container craft includes virtual component fabrication time');
    $test->assertEquals(0.0, $probes->findByPlayerId($craftPlayer->id)?->metalsStock, 'raw additional-container craft commits the raw metals immediately');

    $craftMannyRow = $pdo->prepare('SELECT id FROM mannies WHERE uid = :uid');
    $craftMannyRow->execute(['uid' => $craftMannyId]);
    $craftMannyDbId = (int) $craftMannyRow->fetchColumn();
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $craftMannyDbId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $staleContainerCraftA = $mannies->findById($craftMannyDbId);
    $staleContainerCraftB = $mannies->findById($craftMannyDbId);
    $craftProbeForStaleRefresh = $probes->findByPlayerId($craftPlayer->id);
    if ($staleContainerCraftA !== null && $staleContainerCraftB !== null && $craftProbeForStaleRefresh !== null) {
        $mannyService->refreshMannyState($staleContainerCraftA, $craftProbeForStaleRefresh);
        $mannyService->refreshMannyState($staleContainerCraftB, $craftProbeForStaleRefresh);
    }
    $kernel->handle('GET', '/api/probe/mannies', $craftHeaders);
    $containerProbe = $kernel->handle('GET', '/api/probe', $craftHeaders);
    $additionalContainers = array_values(array_filter(
        $containerProbe->body['probe']['inventory']['items'] ?? [],
        static fn(array $item): bool => ($item['type'] ?? null) === 'additional_container',
    ));
    $test->assertEquals(1, count($additionalContainers), 'completed additional-container craft adds exactly one container item after duplicate stale refreshes');
    $test->assertEquals(0.0, $additionalContainers[0]['containerSpace'] ?? null, 'additional container item occupies no storage');
    $test->assertEquals(1.0, (float) ($additionalContainers[0]['metadata']['capacityBonus'] ?? 0), 'additional container item carries its capacity bonus');
    $test->assertEquals(2.0, $containerProbe->body['probe']['inventory']['capacity'] ?? null, 'additional container increases probe storage capacity');
    $storageContainersResponse = $kernel->handle('GET', '/api/probe/storage-containers', $craftHeaders);
    $test->assertEquals(200, $storageContainersResponse->status, 'GET /api/probe/storage-containers lists storage containers');
    $craftStorageContainers = $storageContainersResponse->body['containers'] ?? [];
    $test->assertEquals(2, count($craftStorageContainers), 'additional container creates one individual storage container');
    $additionalStorageContainer = array_values(array_filter(
        $craftStorageContainers,
        static fn(array $container): bool => ($container['kind'] ?? null) === 'container',
    ))[0] ?? null;
    $test->assert(is_string($additionalStorageContainer['id'] ?? null), 'additional storage container has a stable public id');
    $renamedStorageContainer = $kernel->handle('PATCH', '/api/probe/storage-containers/' . rawurlencode((string) ($additionalStorageContainer['id'] ?? 'missing')), $craftHeaders, json_encode([
        'label' => 'Soute metaux',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(200, $renamedStorageContainer->status, 'PATCH /api/probe/storage-containers/{id} renames a storage container');
    $test->assertEquals('Soute metaux', $renamedStorageContainer->body['container']['label'] ?? null, 'renamed storage container response exposes the new label');
    $test->assertEquals('Soute metaux', $storageContainers->findByUidForProbe($craftProbeEntity->id, (string) ($additionalStorageContainer['id'] ?? 'missing'))?->label, 'storage container label is persisted');
    $emptyStorageContainerLabel = $kernel->handle('PATCH', '/api/probe/storage-containers/' . rawurlencode((string) ($additionalStorageContainer['id'] ?? 'missing')), $craftHeaders, json_encode([
        'label' => '   ',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(400, $emptyStorageContainerLabel->status, 'PATCH /api/probe/storage-containers/{id} rejects empty labels');
    $storageRules = $kernel->handle('PATCH', '/api/probe/storage-containers/' . rawurlencode((string) ($additionalStorageContainer['id'] ?? 'missing')) . '/rules', $craftHeaders, json_encode([
        'priority' => ['metals'],
        'exclusion' => ['ice'],
        'strictExclusion' => ['manny'],
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(200, $storageRules->status, 'PATCH /api/probe/storage-containers/{id}/rules updates routing rules');
    $test->assertEquals(['metals'], $storageRules->body['container']['rules']['priority'] ?? null, 'storage priority rule is persisted');

    $staleProbeBeforeStorageRace = $probes->findById($craftProbeEntity->id);
    $freshProbeForStorageRace = $probes->findById($craftProbeEntity->id);
    if ($staleProbeBeforeStorageRace !== null && $freshProbeForStorageRace !== null) {
        setProbeTestStoredResources($storage, $storageContainers, $probes, $freshProbeForStorageRace, []);
        $staleProbeBeforeStorageRace = $probes->findById($craftProbeEntity->id) ?? $staleProbeBeforeStorageRace;
        $freshProbeForStorageRace = $probes->findById($craftProbeEntity->id) ?? $freshProbeForStorageRace;
        $storage->addResource($freshProbeForStorageRace, 'metals', 0.1);
        $storage->addResource($staleProbeBeforeStorageRace, 'ice', 0.1);
        $test->assertEquals(0.1, $storage->resourceStock($freshProbeForStorageRace, 'metals'), 'stale probe storage refresh keeps resources added by another request');
        $test->assertEquals(0.1, $storage->resourceStock($freshProbeForStorageRace, 'ice'), 'stale probe storage refresh adds its own resource without rebuilding from old totals');
        $craftProbeEntity = setProbeTestStoredResources($storage, $storageContainers, $probes, $craftProbeEntity, []);
    }

    $mannyComponentSeeds = [
        [ProbeItem::TYPE_LINEAR_ACTUATOR, ProbeItem::LINEAR_ACTUATOR_NAME, 0.01, 6],
        [ProbeItem::TYPE_ELECTRIC_MOTOR, ProbeItem::ELECTRIC_MOTOR_NAME, 0.006, 12],
        [ProbeItem::TYPE_BATTERY_PACK, ProbeItem::BATTERY_PACK_NAME, 0.008, 4],
        [ProbeItem::TYPE_INTEGRATED_CIRCUIT, ProbeItem::INTEGRATED_CIRCUIT_NAME, 0.001, 6],
        [ProbeItem::TYPE_STEEL_PLATE, ProbeItem::STEEL_PLATE_NAME, 0.01, 18],
        [ProbeItem::TYPE_STEEL_BAR, ProbeItem::STEEL_BAR_NAME, 0.01, 12],
    ];
    foreach ($mannyComponentSeeds as [$type, $name, $space, $count]) {
        for ($index = 0; $index < $count; $index++) {
            $storage->addItem($craftProbeEntity, $type, $name, $space, ['seededFor' => 'manny-duplicate-refresh-test']);
        }
    }
    $mannyCountBeforeCraft = count($mannies->findByProbeId($craftProbeEntity->id));
    $mannyCraft = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($craftMannyId) . '/craft', $craftHeaders, json_encode([
        'recipe' => 'manny',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $mannyCraft->status, 'Manny can start a Manny craft from stored components');
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $craftMannyDbId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $staleMannyCraftA = $mannies->findById($craftMannyDbId);
    $staleMannyCraftB = $mannies->findById($craftMannyDbId);
    $craftProbeForStaleMannyRefresh = $probes->findByPlayerId($craftPlayer->id);
    if ($staleMannyCraftA !== null && $staleMannyCraftB !== null && $craftProbeForStaleMannyRefresh !== null) {
        $mannyService->refreshMannyState($staleMannyCraftA, $craftProbeForStaleMannyRefresh);
        $mannyService->refreshMannyState($staleMannyCraftB, $craftProbeForStaleMannyRefresh);
    }
    $test->assertEquals($mannyCountBeforeCraft + 1, count($mannies->findByProbeId($craftProbeEntity->id)), 'completed Manny craft creates exactly one Manny after duplicate stale refreshes');

    $pdo->prepare('UPDATE neumann_probes SET deuterium_stock = 100 WHERE id = :id')->execute(['id' => $craftProbeEntity->id]);
    $craftProbeEntity = setProbeTestStoredResources($storage, $storageContainers, $probes, $craftProbeEntity, ['metals' => 0.2, 'ice' => 0.09, 'carbon_compounds' => 0.11]);
    $directAtomicMannyCraft = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($craftMannyId) . '/craft', $craftHeaders, json_encode([
        'recipe' => 'integrated_circuit',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(400, $directAtomicMannyCraft->status, 'Manny craft endpoint refuses atomic-printer recipes');
    $test->assertEquals('invalid_recipe', $directAtomicMannyCraft->body['error']['code'] ?? null, 'atomic-printer recipes require the printer endpoint');

    $integratedCircuitCraft = $kernel->handle('POST', '/api/probe/atomic-printer/craft', $craftHeaders, json_encode([
        'recipe' => 'integrated_circuit',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $integratedCircuitCraft->status, 'Atomic printer can start an integrated-circuit craft');
    $test->assertEquals('assisting_atomic_printer', $integratedCircuitCraft->body['manny']['currentTask'] ?? null, 'atomic-printer craft reserves a Manny assistant');
    $test->assertEquals('integrated_circuit', $integratedCircuitCraft->body['manny']['task']['recipe'] ?? null, 'integrated-circuit task stores its recipe');
    $test->assertEquals('atomic_3d_printer', $integratedCircuitCraft->body['manny']['task']['fabricator'] ?? null, 'integrated-circuit task records the atomic printer as fabricator');
    $test->assertEquals(5400, $integratedCircuitCraft->body['manny']['task']['durationSeconds'] ?? null, 'raw integrated-circuit craft includes intermediate component fabrication time');
    $test->assertEquals(0.2, $integratedCircuitCraft->body['manny']['task']['resourceCosts']['metals'] ?? null, 'raw integrated-circuit craft commits metal costs');
    $test->assertEquals(0.09, $integratedCircuitCraft->body['manny']['task']['resourceCosts']['ice'] ?? null, 'raw integrated-circuit craft commits ice costs');
    $test->assertEquals(0.11, $integratedCircuitCraft->body['manny']['task']['resourceCosts']['carbon_compounds'] ?? null, 'raw integrated-circuit craft commits organic-compound costs');
    $test->assertEquals(0.13, $integratedCircuitCraft->body['manny']['task']['resourceCosts']['deuterium'] ?? null, 'raw integrated-circuit craft commits deuterium energy costs');
    $printerAfterStart = array_values(array_filter(
        $integratedCircuitCraft->body['inventory']['items'] ?? [],
        static fn(array $item): bool => ($item['type'] ?? null) === 'atomic_3d_printer',
    ))[0] ?? null;
    $test->assertEquals('atomic_printing', $printerAfterStart['currentTask'] ?? null, 'atomic printer exposes its active crafting task');
    $test->assertEquals($integratedCircuitCraft->body['manny']['id'] ?? null, $printerAfterStart['metadata']['assistantMannyId'] ?? null, 'atomic printer exposes its assistant Manny');
    $circuitProbeAfterStart = $probes->findByPlayerId($craftPlayer->id);
    $test->assertEquals(87.0, $circuitProbeAfterStart?->deuteriumStock, 'integrated-circuit craft consumes thirteen percent of the deuterium tank');

    $printerAssistantRow = $pdo->prepare('SELECT id FROM mannies WHERE uid = :uid');
    $printerAssistantRow->execute(['uid' => (string) ($integratedCircuitCraft->body['manny']['id'] ?? '')]);
    $printerAssistantDbId = (int) $printerAssistantRow->fetchColumn();
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $printerAssistantDbId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $kernel->handle('GET', '/api/probe/mannies', $craftHeaders);
    $circuitProbe = $kernel->handle('GET', '/api/probe', $craftHeaders);
    $integratedCircuits = array_values(array_filter(
        $circuitProbe->body['probe']['inventory']['items'] ?? [],
        static fn(array $item): bool => ($item['type'] ?? null) === 'integrated_circuit',
    ));
    $test->assertEquals(1, count($integratedCircuits), 'completed integrated-circuit craft adds a circuit item');
    $test->assertEquals(0.001, $integratedCircuits[0]['containerSpace'] ?? null, 'integrated circuit item occupies a tiny storage space');

    $craftProbeEntity = setProbeTestStoredResources($storage, $storageContainers, $probes, $craftProbeEntity, ['metals' => 0.15]);
    $rawLinearActuatorCraft = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($craftMannyId) . '/craft', $craftHeaders, json_encode([
        'recipe' => 'linear_actuator',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $rawLinearActuatorCraft->status, 'Manny can start a linear-actuator craft from raw metals');
    $test->assertEquals(0.15, $rawLinearActuatorCraft->body['manny']['task']['metalsCost'] ?? null, 'recursive linear-actuator craft commits all nested metal costs');
    $test->assertEquals(3900, $rawLinearActuatorCraft->body['manny']['task']['durationSeconds'] ?? null, 'recursive linear-actuator craft includes nested motor, bar and plate fabrication time');

    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $craftMannyDbId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $kernel->handle('GET', '/api/probe/mannies', $craftHeaders);
    $linearProbe = $kernel->handle('GET', '/api/probe', $craftHeaders);
    $linearActuators = array_values(array_filter(
        $linearProbe->body['probe']['inventory']['items'] ?? [],
        static fn(array $item): bool => ($item['type'] ?? null) === ProbeItem::TYPE_LINEAR_ACTUATOR,
    ));
    $test->assertEquals(1, count($linearActuators), 'completed linear-actuator craft adds a linear actuator item');

    $craftProbeEntity = setProbeTestStoredResources($storage, $storageContainers, $probes, $craftProbeEntity, ['metals' => 0.02]);
    $steelBarCraft = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($craftMannyId) . '/craft', $craftHeaders, json_encode([
        'recipe' => 'steel_bar',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $steelBarCraft->status, 'Manny can start a steel-bar craft');
    $test->assertEquals(300, $steelBarCraft->body['manny']['task']['durationSeconds'] ?? null, 'steel-bar craft task lasts five minutes');
    $test->assertEquals(0.0, $probes->findByPlayerId($craftPlayer->id)?->metalsStock, 'steel-bar craft commits its metals immediately');

    $freeBeforeCrowding = $storage->freeCargoCapacity($craftProbeEntity);
    $storage->addResource($craftProbeEntity, 'metals', max(0.0, $freeBeforeCrowding - 0.005));
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $craftMannyDbId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $crowdedCraftRefresh = $kernel->handle('GET', '/api/probe/mannies', $craftHeaders);
    $test->assertEquals(200, $crowdedCraftRefresh->status, 'completed craft waiting for storage does not break Manny refresh');
    $crowdedCraftManny = array_values(array_filter(
        $crowdedCraftRefresh->body['mannies'] ?? [],
        static fn(array $manny): bool => ($manny['id'] ?? null) === $craftMannyId,
    ))[0] ?? null;
    $test->assertEquals('crafting', $crowdedCraftManny['currentTask'] ?? null, 'completed craft remains pending while storage is full');
    $test->assertEquals('storage_space', $crowdedCraftManny['task']['waitingFor'] ?? null, 'completed craft records that storage space is required');
    $crowdedProbe = $kernel->handle('GET', '/api/probe', $craftHeaders);
    $test->assertEquals(200, $crowdedProbe->status, 'GET /api/probe tolerates completed craft output waiting for storage');
    $jettisonCrowdedMetals = $kernel->handle('POST', '/api/probe/inventory/probe-' . $craftProbeEntity->id . '-stock-metals/jettison', $craftHeaders, json_encode([
        'amount' => 0.02,
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(200, $jettisonCrowdedMetals->status, 'jettisoning resources frees space for a pending craft output');
    $steelBarProbe = $kernel->handle('GET', '/api/probe', $craftHeaders);
    $steelBars = array_values(array_filter(
        $steelBarProbe->body['probe']['inventory']['items'] ?? [],
        static fn(array $item): bool => ($item['type'] ?? null) === 'steel_bar',
    ));
    $test->assertEquals(1, count($steelBars), 'completed steel-bar craft adds a steel bar item');
    $test->assertEquals(0.01, $steelBars[0]['containerSpace'] ?? null, 'steel bar item occupies 0.01 containers');
    $storage->consumeResource($craftProbeEntity, 'metals', $storage->resourceStock($craftProbeEntity, 'metals'));
    $storageMove = $kernel->handle('POST', '/api/probe/storage-moves', $craftHeaders, json_encode([
        'actorMannyId' => $craftMannyId,
        'kind' => 'item',
        'itemId' => $steelBars[0]['id'] ?? '',
        'toContainerId' => $additionalStorageContainer['id'] ?? '',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $storageMove->status, 'POST /api/probe/storage-moves assigns a Manny to move an item');
    $test->assertEquals('moving_stockage', $storageMove->body['manny']['currentTask'] ?? null, 'storage move uses moving_stockage task');
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $craftMannyDbId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $kernel->handle('GET', '/api/probe/mannies', $craftHeaders);
    $afterStorageMoveProbe = $kernel->handle('GET', '/api/probe', $craftHeaders);
    $movedSteelBars = array_values(array_filter(
        $afterStorageMoveProbe->body['probe']['inventory']['items'] ?? [],
        static fn(array $item): bool => ($item['type'] ?? null) === 'steel_bar',
    ));
    $test->assertEquals($additionalStorageContainer['id'] ?? null, $movedSteelBars[0]['container']['id'] ?? null, 'completed storage move updates the item container');

    $coreStorageContainer = $storageContainers->findByUidForProbe($craftProbeEntity->id, 'probe-core');
    $additionalStorageContainerEntity = $storageContainers->findByUidForProbe($craftProbeEntity->id, (string) ($additionalStorageContainer['id'] ?? 'missing'));
    if ($coreStorageContainer !== null && $additionalStorageContainerEntity !== null) {
        $batchItemA = $items->create($craftProbeEntity->id, ProbeItem::TYPE_STEEL_PLATE, 'Plaque d’acier', 0.01, storageContainerId: $coreStorageContainer->id);
        $batchItemB = $items->create($craftProbeEntity->id, ProbeItem::TYPE_STEEL_PLATE, 'Plaque d’acier', 0.01, storageContainerId: $coreStorageContainer->id);
        $batchStorageMove = $kernel->handle('POST', '/api/probe/storage-moves', $craftHeaders, json_encode([
            'actorMannyId' => $craftMannyId,
            'kind' => 'item',
            'itemIds' => [$batchItemA->uid, $batchItemB->uid],
            'quantity' => 2,
            'toContainerId' => $additionalStorageContainer['id'] ?? '',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(202, $batchStorageMove->status, 'POST /api/probe/storage-moves accepts batch item ids');
        $test->assertEquals(20, $batchStorageMove->body['manny']['task']['durationSeconds'] ?? null, 'batch item storage move lasts 10 seconds per item');
        $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
            'id' => $craftMannyDbId,
            'ended' => gmdate('c', time() - 1),
        ]);
        $kernel->handle('GET', '/api/probe/mannies', $craftHeaders);
        $test->assertEquals($additionalStorageContainerEntity->id, $items->findByUidForProbe($craftProbeEntity->id, $batchItemA->uid)?->storageContainerId, 'completed batch move updates the first item container');
        $test->assertEquals($additionalStorageContainerEntity->id, $items->findByUidForProbe($craftProbeEntity->id, $batchItemB->uid)?->storageContainerId, 'completed batch move updates the second item container');

        $pdo->prepare('UPDATE mannies SET current_task = :task, task_started_at = :started, task_ends_at = :ended, task_payload_json = :payload WHERE uid = :uid')->execute([
            'uid' => $craftMannyId,
            'task' => 'moving_stockage',
            'started' => gmdate('c', time() - 120),
            'ended' => gmdate('c', time() - 60),
            'payload' => json_encode([
                'kind' => 'resource',
                'fromContainerId' => 'probe-core',
                'toContainerId' => $additionalStorageContainer['id'] ?? '',
                'resourceType' => 'ice',
                'amount' => 0.3,
                'durationSeconds' => 60,
            ], JSON_THROW_ON_ERROR),
        ]);
        $failedStorageMoveRefresh = $kernel->handle('GET', '/api/probe/mannies', $craftHeaders);
        $test->assertEquals(200, $failedStorageMoveRefresh->status, 'expired impossible storage moves do not break Manny refresh');
        $failedStorageMoveManny = $mannies->findByUidForProbe($craftProbeEntity->id, $craftMannyId);
        $test->assertEquals(null, $failedStorageMoveManny?->currentTask, 'expired impossible storage move releases the Manny');
        $test->assertEquals('failed', $failedStorageMoveManny?->taskPayload['result'] ?? null, 'expired impossible storage move records a failed result');
        $test->assertEquals('insufficient_inventory_amount', $failedStorageMoveManny?->taskPayload['failureReason'] ?? null, 'expired impossible storage move records its failure reason');

        for ($index = 0; $index < 5; $index++) {
            $items->create($craftProbeEntity->id, ProbeItem::TYPE_LINEAR_ACTUATOR, ProbeItem::LINEAR_ACTUATOR_NAME, 0.01, storageContainerId: $additionalStorageContainerEntity->id);
        }
        for ($index = 0; $index < 12; $index++) {
            $items->create($craftProbeEntity->id, ProbeItem::TYPE_ELECTRIC_MOTOR, ProbeItem::ELECTRIC_MOTOR_NAME, 0.006, storageContainerId: $additionalStorageContainerEntity->id);
        }
        for ($index = 0; $index < 4; $index++) {
            $items->create($craftProbeEntity->id, ProbeItem::TYPE_BATTERY_PACK, ProbeItem::BATTERY_PACK_NAME, 0.008, storageContainerId: $additionalStorageContainerEntity->id);
        }
        for ($index = 0; $index < 5; $index++) {
            $items->create($craftProbeEntity->id, ProbeItem::TYPE_INTEGRATED_CIRCUIT, ProbeItem::INTEGRATED_CIRCUIT_NAME, 0.001, storageContainerId: $additionalStorageContainerEntity->id);
        }
        for ($index = 0; $index < 18; $index++) {
            $items->create($craftProbeEntity->id, ProbeItem::TYPE_STEEL_PLATE, ProbeItem::STEEL_PLATE_NAME, 0.01, storageContainerId: $additionalStorageContainerEntity->id);
        }
        for ($index = 0; $index < 12; $index++) {
            $items->create($craftProbeEntity->id, ProbeItem::TYPE_STEEL_BAR, ProbeItem::STEEL_BAR_NAME, 0.01, storageContainerId: $additionalStorageContainerEntity->id);
        }
        $mannyCountBeforeCraft = count($mannies->findByProbeId($craftProbeEntity->id));
        $preparedMannyCraft = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($craftMannyId) . '/craft', $craftHeaders, json_encode([
            'recipe' => 'manny',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(202, $preparedMannyCraft->status, 'Manny can start crafting another Manny from prepared components');
        $test->assertEquals(3600, $preparedMannyCraft->body['manny']['task']['durationSeconds'] ?? null, 'prepared Manny assembly lasts one hour');
        $test->assertEquals('manny', $preparedMannyCraft->body['manny']['task']['output']['type'] ?? null, 'Manny craft task stores a Manny output');

        $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
            'id' => $craftMannyDbId,
            'ended' => gmdate('c', time() - 1),
        ]);
        $craftedMannyList = $kernel->handle('GET', '/api/probe/mannies', $craftHeaders);
        $test->assertEquals($mannyCountBeforeCraft + 1, count($craftedMannyList->body['mannies'] ?? []), 'completed Manny craft creates a real Manny entity');
        $craftedMannyProbe = $kernel->handle('GET', '/api/probe', $craftHeaders);
        $craftedMannyItems = array_values(array_filter(
            $craftedMannyProbe->body['probe']['inventory']['items'] ?? [],
            static fn(array $item): bool => ($item['type'] ?? null) === 'manny',
        ));
        $test->assertEquals($mannyCountBeforeCraft + 1, count($craftedMannyItems), 'crafted Manny appears in probe inventory');

        setProbeTestStoredResources($storage, $storageContainers, $probes, $craftProbeEntity, []);
        $storageContainers->setResourceAmount($coreStorageContainer->id, 'metals', 0.2);
        $storageContainers->setResourceAmount($additionalStorageContainerEntity->id, 'metals', 0.3);
        $craftProbeEntity = $probes->findById($craftProbeEntity->id) ?? $craftProbeEntity;
        $storage->ensureProbeStorage($craftProbeEntity);
        $jettisonContainerMetals = $kernel->handle('POST', '/api/probe/inventory/probe-' . $craftProbeEntity->id . '-stock-metals/jettison', $craftHeaders, json_encode([
            'amount' => 0.1,
            'containerId' => $additionalStorageContainer['id'] ?? '',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(200, $jettisonContainerMetals->status, 'POST /api/probe/inventory/{itemId}/jettison can target a resource container');
        $test->assertEquals(0.2, $storageContainers->resourceAmounts($coreStorageContainer->id)['metals'] ?? null, 'container-targeted jettison keeps the core stock untouched');
        $test->assertEquals(0.2, $storageContainers->resourceAmounts($additionalStorageContainerEntity->id)['metals'] ?? null, 'container-targeted jettison consumes the selected container stock');
        $test->assertEquals(0.4, $probes->findByPlayerId($craftPlayer->id)?->metalsStock, 'container-targeted jettison syncs the global resource total');

        setProbeTestStoredResources($storage, $storageContainers, $probes, $craftProbeEntity, []);
        $storageContainers->setResourceAmount($additionalStorageContainerEntity->id, 'carbon_compounds', 0.3);
        $craftProbeEntity = $probes->findById($craftProbeEntity->id) ?? $craftProbeEntity;
        $storage->ensureProbeStorage($craftProbeEntity);
        $jettisonContainerCarbonCompounds = $kernel->handle('POST', '/api/probe/inventory/probe-' . $craftProbeEntity->id . '-stock-carbon-compounds/jettison', $craftHeaders, json_encode([
            'amount' => 0.1,
            'containerId' => $additionalStorageContainer['id'] ?? '',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(200, $jettisonContainerCarbonCompounds->status, 'POST /api/probe/inventory/{itemId}/jettison accepts exposed carbon-compound stock ids');
        $test->assertEquals(0.2, $storageContainers->resourceAmounts($additionalStorageContainerEntity->id)['carbon_compounds'] ?? null, 'container-targeted carbon-compound jettison consumes the selected container stock');
        $test->assertEquals(0.2, $probes->findByPlayerId($craftPlayer->id)?->organicCompoundsStock, 'carbon-compound jettison syncs the global organic-compound total');
    }
}

$detachPlayer = $auth->registerPlayerWithPassword('container-detacher', 'secret', 'Container Detacher', 'Detach test probe');
$detachProbe = $probes->findByPlayerId($detachPlayer->id);
$detachSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'container-detacher', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$detachHeaders = ['Authorization' => 'Bearer ' . (string) ($detachSession->body['token'] ?? '')];
$detachMannyList = $kernel->handle('GET', '/api/probe/mannies', $detachHeaders);
$detachMannyId = (string) ($detachMannyList->body['mannies'][0]['id'] ?? '');
$detachSecondMannyId = (string) ($detachMannyList->body['mannies'][1]['id'] ?? '');
$detachThirdMannyId = (string) ($detachMannyList->body['mannies'][2]['id'] ?? '');
$detachFourthMannyId = (string) ($detachMannyList->body['mannies'][3]['id'] ?? '');
if ($detachProbe !== null && $detachMannyId !== '') {
    $detachContainerItem = $storage->addItem($detachProbe, ProbeItem::TYPE_ADDITIONAL_CONTAINER, ProbeItem::ADDITIONAL_CONTAINER_NAME, 0.0, ['capacityBonus' => 1.0]);
    $detachContainerId = 'container-' . $detachContainerItem->uid;
    $detachContainer = $storageContainers->findByUidForProbe($detachProbe->id, $detachContainerId);
    if ($detachContainer !== null) {
        $storageContainers->updateRules($detachContainer, ['metals'], ['ice'], ['manny']);
        $storageContainers->setResourceAmount($detachContainer->id, 'metals', 0.2);
        $storage->ensureProbeStorage($detachProbe);
        $storedBar = $items->create($detachProbe->id, ProbeItem::TYPE_STEEL_BAR, ProbeItem::STEEL_BAR_NAME, 0.01, ['test' => 'detached'], $detachContainer->id);

        $detachCore = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachMannyId) . '/detach-storage-container', $detachHeaders, json_encode([
            'containerId' => 'probe-core',
            'mode' => 'drifting',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(422, $detachCore->status, 'detaching probe-core is rejected');
        $test->assertEquals('storage_container_not_detachable', $detachCore->body['error']['code'] ?? null, 'probe-core detach returns a specific error');

        $detachMissing = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachMannyId) . '/detach-storage-container', $detachHeaders, json_encode([
            'containerId' => 'container-missing',
            'mode' => 'drifting',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(404, $detachMissing->status, 'detaching an unknown container is rejected');
        $test->assertEquals('invalid_storage_container', $detachMissing->body['error']['code'] ?? null, 'missing container detach returns a specific error');

        $detachDrifting = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachMannyId) . '/detach-storage-container', $detachHeaders, json_encode([
            'containerId' => $detachContainerId,
            'mode' => 'drifting',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(202, $detachDrifting->status, 'Manny can start detaching an additional container as drifting');
        $test->assertEquals('detaching_storage_container', $detachDrifting->body['manny']['currentTask'] ?? null, 'detach task uses the storage detach task type');
        $test->assertEquals(360, $detachDrifting->body['manny']['task']['durationSeconds'] ?? null, 'detaching a storage container takes salvage duration plus sixty seconds');
        $detachedObjectId = (string) ($detachDrifting->body['manny']['task']['objectId'] ?? '');
        $test->assert($storageContainers->findByUidForProbe($detachProbe->id, $detachContainerId) === null, 'accepted detach removes the storage container from probe inventory immediately');
        $test->assert($items->findByUidForProbe($detachProbe->id, $storedBar->uid) === null, 'accepted detach removes stored items with the container immediately');
        $test->assertEquals(0.0, $probes->findByPlayerId($detachPlayer->id)?->metalsStock, 'accepted detach removes stored resources from probe totals');

        $detachRow = $pdo->prepare('SELECT id FROM mannies WHERE uid = :uid');
        $detachRow->execute(['uid' => $detachMannyId]);
        $detachMannyDbId = (int) $detachRow->fetchColumn();
        $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
            'id' => $detachMannyDbId,
            'ended' => gmdate('c', time() - 1),
        ]);
        $kernel->handle('GET', '/api/probe/mannies', $detachHeaders);
        $driftingContainerSector = $sectorRepository->load($detachProbe->currentSector);
        $driftingDetachedContainer = $driftingContainerSector->findObjectById($detachedObjectId);
        $test->assertEquals('detached_container', $driftingDetachedContainer?->getType()->value, 'completed drifting detach persists a detached container sector object');
        $driftingObservation = $kernel->handle('GET', '/api/probe/sector', $detachHeaders);
        $observedDetached = array_values(array_filter(
            $driftingObservation->body['sector']['objects'] ?? [],
            static fn(array $object): bool => ($object['id'] ?? null) === $detachedObjectId,
        ))[0] ?? null;
        $test->assertEquals(true, $observedDetached['salvageable'] ?? null, 'drifting detached containers are visible as salvageable sector objects');
        $test->assert(!array_key_exists('payload', $observedDetached ?? []), 'sector observation does not expose detached container contents');

        $driftingMineSector = $sectorRepository->load($detachProbe->currentSector);
        $driftingMineSector->addObject(new Asteroid('drifting-mine-rock', null, 'iron', ['iron', 'nickel'], 'small', 0.000001, 0.001));
        $sectorRepository->save($driftingMineSector);
        $mineDriftingContainer = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachSecondMannyId) . '/mine', $detachHeaders, json_encode([
            'objectId' => 'drifting-mine-rock',
            'resource' => 'metals',
            'targetAmount' => 0.01,
            'targetContainerId' => $detachedObjectId,
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(202, $mineDriftingContainer->status, 'Manny mining can target a drifting detached container');
        $test->assertEquals($detachedObjectId, $mineDriftingContainer->body['manny']['task']['targetContainer']['id'] ?? null, 'mining task exposes its target detached container id');
        $test->assertEquals(900, $mineDriftingContainer->body['manny']['task']['miningTravelSeconds'] ?? null, 'drifting target container mining keeps normal travel time');
        $activeDriftingTargetContainerMannies = $kernel->handle('GET', '/api/probe/mannies', $detachHeaders);
        $activeDriftingTargetContainerManny = array_values(array_filter(
            $activeDriftingTargetContainerMannies->body['mannies'] ?? [],
            static fn(array $manny): bool => ($manny['id'] ?? null) === $detachSecondMannyId,
        ))[0] ?? null;
        $activeDriftingTargetContainerTask = is_array($activeDriftingTargetContainerManny) && is_array($activeDriftingTargetContainerManny['task'] ?? null) ? $activeDriftingTargetContainerManny['task'] : [];
        $test->assertEquals($detachedObjectId, $activeDriftingTargetContainerTask['targetContainer']['id'] ?? null, 'GET /api/probe/mannies exposes the drifting mining target container id');
        $test->assertEquals('drifting', $activeDriftingTargetContainerTask['targetContainer']['mode'] ?? null, 'GET /api/probe/mannies exposes the drifting mining target container mode');
        $test->assertEquals(false, $activeDriftingTargetContainerTask['targetContainer']['travelDeducted'] ?? null, 'GET /api/probe/mannies exposes normal travel for drifting target containers');
        $detachSecondRow = $pdo->prepare('SELECT id FROM mannies WHERE uid = :uid');
        $detachSecondRow->execute(['uid' => $detachSecondMannyId]);
        $detachSecondMannyDbId = (int) $detachSecondRow->fetchColumn();
        $pdo->prepare('UPDATE mannies SET task_started_at = :started, task_ends_at = :ended WHERE id = :id')->execute([
            'id' => $detachSecondMannyDbId,
            'started' => gmdate('c', time() - 2200),
            'ended' => gmdate('c', time() - 1),
        ]);
        $kernel->handle('GET', '/api/probe/mannies', $detachHeaders);
        $driftingMinedContainer = $sectorRepository->load($detachProbe->currentSector)->findObjectById($detachedObjectId);
        $test->assertEquals(0.21, $driftingMinedContainer?->toArray()['payload']['resources']['metals'] ?? null, 'mining into a drifting detached container updates its stored resources');
        $test->assertEquals(0.0, $probes->findByPlayerId($detachPlayer->id)?->metalsStock, 'mining into a detached container does not add mined resources to the probe inventory');

        $recoverDrifting = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachMannyId) . '/salvage', $detachHeaders, json_encode([
            'objectId' => $detachedObjectId,
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(202, $recoverDrifting->status, 'existing salvage endpoint can recover a drifting detached container');
        $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
            'id' => $detachMannyDbId,
            'ended' => gmdate('c', time() - 1),
        ]);
        $kernel->handle('GET', '/api/probe/mannies', $detachHeaders);
        $restoredContainer = $storageContainers->findByUidForProbe($detachProbe->id, $detachContainerId);
        $test->assert($restoredContainer !== null, 'recovering a detached container restores its storage container id');
        $test->assertEquals(0.21, $restoredContainer !== null ? ($storageContainers->resourceAmounts($restoredContainer->id)['metals'] ?? null) : null, 'recovering a detached container restores its resources');
        $test->assertEquals($restoredContainer?->id, $items->findByUidForProbe($detachProbe->id, $storedBar->uid)?->storageContainerId, 'recovering a detached container restores stored items inside it');
        $test->assertEquals(['metals'], $restoredContainer?->priorityFilter ?? null, 'recovering a detached container restores routing rules');

        $hiddenSector = new SectorContent($detachProbe->currentSector, [
            new Asteroid('cache-rock', null, 'iron', ['iron', 'nickel'], 'small', 0.000001, 0.001),
        ]);
        $sectorRepository->save($hiddenSector);
        $detachHidden = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachMannyId) . '/detach-storage-container', $detachHeaders, json_encode([
            'containerId' => $detachContainerId,
            'mode' => 'hidden_on_asteroid',
            'objectId' => 'cache-rock',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(202, $detachHidden->status, 'Manny can start hiding an additional container on an asteroid');
        $hiddenDetachedObjectId = (string) ($detachHidden->body['manny']['task']['objectId'] ?? '');
        $test->assertEquals($hiddenDetachedObjectId, $detachHidden->body['manny']['task']['artificialObjectDetected']['objectId'] ?? null, 'hidden detach reports the detached container as a detected artificial object');
        $test->assertEquals('cache-rock', $detachHidden->body['manny']['task']['artificialObjectDetected']['targetObjectId'] ?? null, 'hidden detach detection reports the asteroid target for immediate client reuse');
        $test->assert(!str_contains(json_encode($detachHidden->body['manny']['task']['artificialObjectDetected'] ?? [], JSON_THROW_ON_ERROR), 'resources'), 'hidden detach detection does not expose container contents');
        $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
            'id' => $detachMannyDbId,
            'ended' => gmdate('c', time() - 1),
        ]);
        $completedHiddenDetachList = $kernel->handle('GET', '/api/probe/mannies', $detachHeaders);
        $completedHiddenDetachManny = array_values(array_filter(
            $completedHiddenDetachList->body['mannies'] ?? [],
            static fn(array $manny): bool => ($manny['id'] ?? null) === $detachMannyId,
        ))[0] ?? null;
        $completedHiddenDetachTask = is_array($completedHiddenDetachManny) && is_array($completedHiddenDetachManny['task'] ?? null) ? $completedHiddenDetachManny['task'] : [];
        $test->assertEquals($hiddenDetachedObjectId, $completedHiddenDetachTask['artificialObjectDetected']['objectId'] ?? null, 'completed hidden detach keeps the hidden container detection in the Manny result');
        $test->assertEquals('cache-rock', $completedHiddenDetachTask['artificialObjectDetected']['targetObjectId'] ?? null, 'completed hidden detach keeps the asteroid target in the Manny result');
        $hiddenStoredSector = $sectorRepository->load($detachProbe->currentSector);
        $test->assertEquals(1, count($hiddenStoredSector->hiddenDetachedContainersForObject('cache-rock')), 'completed hidden detach persists the container on the asteroid');
        $hiddenStoredContainer = $hiddenStoredSector->hiddenDetachedContainersForObject('cache-rock')[0] ?? null;
        $test->assert($hiddenStoredContainer !== null && in_array($detachPlayer->id, $hiddenStoredContainer->getDiscoveredByPlayerIds(), true), 'completed hidden detach marks the owner player as a discoverer');
        if ($hiddenStoredContainer !== null) {
            $hiddenStoredSector->addHiddenDetachedContainer($hiddenStoredContainer);
            $sectorRepository->save($hiddenStoredSector);
            $hiddenStoredSector = $sectorRepository->load($detachProbe->currentSector);
            $test->assertEquals(1, count($hiddenStoredSector->hiddenDetachedContainersForObject('cache-rock')), 're-adding the same hidden detached container id does not duplicate it');
        }
        $hiddenObservation = $kernel->handle('GET', '/api/probe/sector', $detachHeaders);
        $visibleHiddenContainers = array_values(array_filter(
            $hiddenObservation->body['sector']['objects'] ?? [],
            static fn(array $object): bool => ($object['id'] ?? null) === $hiddenDetachedObjectId,
        ));
        $test->assertEquals(1, count($visibleHiddenContainers), 'owner-discovered hidden detached containers appear in sector observation');
        $test->assertEquals('hidden_on_asteroid', $visibleHiddenContainers[0]['mode'] ?? null, 'visible hidden detached container keeps its hidden mode');
        $test->assertEquals('cache-rock', $visibleHiddenContainers[0]['targetObjectId'] ?? null, 'visible hidden detached container exposes its asteroid target');
        $test->assert(!array_key_exists('payload', $visibleHiddenContainers[0] ?? []), 'visible hidden detached container observation does not expose contents');

        $scoutPlayer = $auth->registerPlayerWithPassword('container-scout', 'secret', 'Container Scout', 'Scout test probe');
        $scoutProbe = $probes->findByPlayerId($scoutPlayer->id);
        if ($scoutProbe !== null) {
            $pdo->prepare('UPDATE neumann_probes SET sector_x = :x, sector_y = :y, sector_z = :z, entered_current_sector_at = :entered WHERE id = :id')->execute([
                'id' => $scoutProbe->id,
                'x' => $detachProbe->currentSector->getX(),
                'y' => $detachProbe->currentSector->getY(),
                'z' => $detachProbe->currentSector->getZ(),
                'entered' => gmdate('c', time() - 3600),
            ]);
            $scoutSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'container-scout', 'password' => 'secret'], JSON_THROW_ON_ERROR));
            $scoutHeaders = ['Authorization' => 'Bearer ' . (string) ($scoutSession->body['token'] ?? '')];
            $scoutObservationBefore = $kernel->handle('GET', '/api/probe/sector', $scoutHeaders);
            $scoutHiddenBefore = array_values(array_filter(
                $scoutObservationBefore->body['sector']['objects'] ?? [],
                static fn(array $object): bool => ($object['id'] ?? null) === $hiddenDetachedObjectId,
            ));
            $test->assertEquals(0, count($scoutHiddenBefore), 'undiscovered hidden detached containers stay hidden from other players');
            $scoutMannies = $kernel->handle('GET', '/api/probe/mannies', $scoutHeaders);
            $scoutMannyId = (string) ($scoutMannies->body['mannies'][0]['id'] ?? '');
            $scoutInspectHidden = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($scoutMannyId) . '/inspect-asteroid', $scoutHeaders, json_encode([
                'objectId' => 'cache-rock',
            ], JSON_THROW_ON_ERROR));
            $test->assertEquals($hiddenDetachedObjectId, $scoutInspectHidden->body['manny']['task']['artificialObjectDetected']['objectId'] ?? null, 'asteroid inspection by another player detects the hidden container');
            $scoutDiscoveredContainer = $sectorRepository->load($detachProbe->currentSector)->findHiddenDetachedContainerById($hiddenDetachedObjectId);
            $test->assert($scoutDiscoveredContainer !== null && in_array($scoutPlayer->id, $scoutDiscoveredContainer->getDiscoveredByPlayerIds(), true), 'asteroid inspection records the discovering player');
            $scoutObservationAfter = $kernel->handle('GET', '/api/probe/sector', $scoutHeaders);
            $scoutHiddenAfter = array_values(array_filter(
                $scoutObservationAfter->body['sector']['objects'] ?? [],
                static fn(array $object): bool => ($object['id'] ?? null) === $hiddenDetachedObjectId,
            ));
            $test->assertEquals(1, count($scoutHiddenAfter), 'discovered hidden detached containers appear in sector observation for the discovering player');
        }

        $mineHidden = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachSecondMannyId) . '/mine', $detachHeaders, json_encode([
            'objectId' => 'cache-rock',
            'resource' => 'metals',
            'targetAmount' => 0.001,
            'targetContainerId' => $hiddenDetachedObjectId,
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals($hiddenDetachedObjectId, $mineHidden->body['manny']['task']['artificialObjectDetected']['objectId'] ?? null, 'mining an asteroid with a hidden container reports an artificial object id');
        $test->assertEquals(0, $mineHidden->body['manny']['task']['miningTravelSeconds'] ?? null, 'mining into a hidden container on the target asteroid deducts travel time');
        $test->assert(!str_contains(json_encode($mineHidden->body['manny']['task']['artificialObjectDetected'] ?? [], JSON_THROW_ON_ERROR), 'resources'), 'hidden-container mining detection does not expose contents');
        $activeHiddenTargetContainerMannies = $kernel->handle('GET', '/api/probe/mannies', $detachHeaders);
        $activeHiddenTargetContainerManny = array_values(array_filter(
            $activeHiddenTargetContainerMannies->body['mannies'] ?? [],
            static fn(array $manny): bool => ($manny['id'] ?? null) === $detachSecondMannyId,
        ))[0] ?? null;
        $activeHiddenTargetContainerTask = is_array($activeHiddenTargetContainerManny) && is_array($activeHiddenTargetContainerManny['task'] ?? null) ? $activeHiddenTargetContainerManny['task'] : [];
        $test->assertEquals($hiddenDetachedObjectId, $activeHiddenTargetContainerTask['targetContainer']['id'] ?? null, 'GET /api/probe/mannies exposes the hidden mining target container id');
        $test->assertEquals('hidden_on_asteroid', $activeHiddenTargetContainerTask['targetContainer']['mode'] ?? null, 'GET /api/probe/mannies exposes the hidden mining target container mode');
        $test->assertEquals('cache-rock', $activeHiddenTargetContainerTask['targetContainer']['targetObjectId'] ?? null, 'GET /api/probe/mannies exposes the hidden mining target asteroid id');
        $test->assertEquals(true, $activeHiddenTargetContainerTask['targetContainer']['travelDeducted'] ?? null, 'GET /api/probe/mannies exposes same-asteroid travel deduction for hidden target containers');
        $hiddenMineRow = $pdo->prepare('SELECT task_started_at, task_ends_at FROM mannies WHERE uid = :uid');
        $hiddenMineRow->execute(['uid' => $detachSecondMannyId]);
        $hiddenMineTiming = $hiddenMineRow->fetch(PDO::FETCH_ASSOC) ?: [];
        $hiddenMineDuration = (new DateTimeImmutable((string) ($hiddenMineTiming['task_ends_at'] ?? 'now')))->getTimestamp()
            - (new DateTimeImmutable((string) ($hiddenMineTiming['task_started_at'] ?? 'now')))->getTimestamp();
        $test->assertEquals(300, $hiddenMineDuration, 'hidden same-asteroid target container mining only takes extraction time');
        $pdo->prepare('UPDATE mannies SET task_started_at = :started, task_ends_at = :ended WHERE uid = :uid')->execute([
            'uid' => $detachSecondMannyId,
            'started' => gmdate('c', time() - 400),
            'ended' => gmdate('c', time() - 1),
        ]);
        $kernel->handle('GET', '/api/probe/mannies', $detachHeaders);
        $hiddenMinedSector = $sectorRepository->load($detachProbe->currentSector);
        $hiddenMinedContainer = $hiddenMinedSector->findHiddenDetachedContainerById($hiddenDetachedObjectId);
        $test->assertEquals(1, count($hiddenMinedSector->hiddenDetachedContainersForObject('cache-rock')), 'mining into a hidden target container keeps a single persisted container entry');
        $test->assertEquals(0.211, $hiddenMinedContainer?->toArray()['payload']['resources']['metals'] ?? null, 'mining into a hidden same-asteroid container updates its stored resources');

        $inspectHidden = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachThirdMannyId) . '/inspect-asteroid', $detachHeaders, json_encode([
            'objectId' => 'cache-rock',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(202, $inspectHidden->status, 'Manny can inspect an asteroid without mining');
        $test->assertEquals('inspecting_asteroid', $inspectHidden->body['manny']['currentTask'] ?? null, 'asteroid inspection uses the inspecting task type');
        $test->assertEquals($hiddenDetachedObjectId, $inspectHidden->body['manny']['task']['artificialObjectDetected']['objectId'] ?? null, 'asteroid inspection reports hidden detached containers');

        $recoverHidden = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachFourthMannyId) . '/recover-storage-container', $detachHeaders, json_encode([
            'objectId' => $hiddenDetachedObjectId,
            'source' => 'asteroid',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(202, $recoverHidden->status, 'Manny can recover a hidden detached container by detected id');
        $secondRecoverHidden = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachMannyId) . '/recover-storage-container', $detachHeaders, json_encode([
            'objectId' => $hiddenDetachedObjectId,
            'source' => 'asteroid',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(404, $secondRecoverHidden->status, 'a second recovery cannot reserve the same hidden container');
        $detachFourthRow = $pdo->prepare('SELECT id FROM mannies WHERE uid = :uid');
        $detachFourthRow->execute(['uid' => $detachFourthMannyId]);
        $detachFourthMannyDbId = (int) $detachFourthRow->fetchColumn();
        $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
            'id' => $detachFourthMannyDbId,
            'ended' => gmdate('c', time() - 1),
        ]);
        $kernel->handle('GET', '/api/probe/mannies', $detachHeaders);
        $restoredHiddenContainer = $storageContainers->findByUidForProbe($detachProbe->id, $detachContainerId);
        $test->assert($restoredHiddenContainer !== null, 'recovering a hidden detached container restores the container');
        $test->assertEquals(0.211, $restoredHiddenContainer !== null ? ($storageContainers->resourceAmounts($restoredHiddenContainer->id)['metals'] ?? null) : null, 'recovering a hidden detached container restores its resources');
        $test->assertEquals(0, count($sectorRepository->load($detachProbe->currentSector)->hiddenDetachedContainersForObject('cache-rock')), 'recovering a hidden detached container removes it from sector JSON');

        $dropSector = new SectorContent($detachProbe->currentSector, [
            new Planet('drop-target-planet', 'Drop target', 'rocky', 1.0, 1.0, true, 0.42, ['metals']),
        ]);
        $sectorRepository->save($dropSector);
        $dropWithoutKit = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachMannyId) . '/drop-storage-container', $detachHeaders, json_encode([
            'containerId' => $detachContainerId,
            'planetId' => 'drop-target-planet',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(422, $dropWithoutKit->status, 'dropping a container on a planet requires an atmospheric drop kit');
        $test->assertEquals('missing_atmospheric_drop_kit', $dropWithoutKit->body['error']['code'] ?? null, 'missing drop kit returns a specific error');

        $dropKit = $storage->addItem($detachProbe, ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT, ProbeItem::ATMOSPHERIC_DROP_KIT_NAME, 0.08);
        $dropAccepted = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($detachMannyId) . '/drop-storage-container', $detachHeaders, json_encode([
            'containerId' => $detachContainerId,
            'planetId' => 'drop-target-planet',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(202, $dropAccepted->status, 'Manny can start dropping an additional container on a planet');
        $test->assertEquals('dropping_storage_container', $dropAccepted->body['manny']['currentTask'] ?? null, 'planet container drop uses its own Manny task type');
        $test->assertEquals(420, $dropAccepted->body['manny']['task']['durationSeconds'] ?? null, 'dropping a container takes salvage duration plus two minutes');
        $test->assert(!array_key_exists('snapshot', $dropAccepted->body['manny']['task'] ?? []), 'planet drop task does not expose the detached container snapshot');
        $droppedObjectId = (string) ($dropAccepted->body['manny']['task']['objectId'] ?? '');
        $test->assert($storageContainers->findByUidForProbe($detachProbe->id, $detachContainerId) === null, 'accepted planet drop removes the storage container from probe inventory immediately');
        $test->assert($items->findByUidForProbe($detachProbe->id, $dropKit->uid) === null, 'accepted planet drop consumes the atmospheric drop kit immediately');

        $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
            'id' => $detachMannyDbId,
            'ended' => gmdate('c', time() - 1),
        ]);
        $kernel->handle('GET', '/api/probe/mannies', $detachHeaders);
        $planetDropSector = $sectorRepository->load($detachProbe->currentSector);
        $planetDroppedContainers = $planetDropSector->planetDroppedContainersForObject('drop-target-planet');
        $test->assertEquals(1, count($planetDroppedContainers), 'completed planet drop persists the container on the planet');
        $test->assertEquals($droppedObjectId, $planetDroppedContainers[0]->getId(), 'planet-dropped container keeps the task object id');
        $test->assertEquals($detachProbe->id, $planetDroppedContainers[0]->getOriginProbeId(), 'planet-dropped container records the origin probe id');
        $planetDropObservation = $kernel->handle('GET', '/api/probe/sector', $detachHeaders);
        $observedPlanetDropped = array_values(array_filter(
            $planetDropObservation->body['sector']['objects'] ?? [],
            static fn(array $object): bool => ($object['id'] ?? null) === $droppedObjectId,
        ));
        $test->assertEquals(0, count($observedPlanetDropped), 'planet-dropped containers are not exposed in sector observation yet');
    }
}

$collisionRecoverPlayer = $auth->registerPlayerWithPassword('container-recover-collision', 'secret', 'Container Recover Collision', 'Collision probe');
$collisionRecoverProbe = $probes->findByPlayerId($collisionRecoverPlayer->id);
if ($collisionRecoverProbe !== null) {
    $collisionContainerItem = $storage->addItem($collisionRecoverProbe, ProbeItem::TYPE_ADDITIONAL_CONTAINER, ProbeItem::ADDITIONAL_CONTAINER_NAME, 0.0, ['capacityBonus' => 1.0]);
    $collisionContainerId = 'container-' . $collisionContainerItem->uid;
    $collisionContainer = $storageContainers->findByUidForProbe($collisionRecoverProbe->id, $collisionContainerId);
    if ($collisionContainer !== null) {
        $storageContainers->rename($collisionContainer, 'Recovered duplicate label');
        $storageContainers->setResourceAmount($collisionContainer->id, 'ice', 0.123);
        $collisionSnapshot = $storage->detachAdditionalContainerSnapshot($collisionRecoverProbe, $collisionContainerId, $collisionRecoverPlayer->id);
        $replacementItem = $storage->addItem($collisionRecoverProbe, ProbeItem::TYPE_ADDITIONAL_CONTAINER, ProbeItem::ADDITIONAL_CONTAINER_NAME, 0.0, ['capacityBonus' => 1.0], $collisionContainerItem->uid);
        $replacementContainerId = 'container-' . $replacementItem->uid;
        $test->assert($storageContainers->findByUidForProbe($collisionRecoverProbe->id, $replacementContainerId) !== null, 'replacement container reuses the detached container identifier');

        $storage->restoreDetachedContainerSnapshot($collisionRecoverProbe, $collisionSnapshot);
        $test->assert($storageContainers->findByUidForProbe($collisionRecoverProbe->id, $collisionContainerId) !== null, 'restoring with an identifier collision keeps the replacement container');
        $collisionContainers = $storageContainers->findByProbeId($collisionRecoverProbe->id);
        $restoredCollisionContainers = array_values(array_filter(
            $collisionContainers,
            static fn($container): bool => $container->uid !== $collisionContainerId && $container->label === 'Recovered duplicate label',
        ));
        $test->assertEquals(1, count($restoredCollisionContainers), 'restoring with an identifier collision creates a new technical container id');
        $restoredCollisionContainer = $restoredCollisionContainers[0] ?? null;
        $test->assertEquals(0.123, $restoredCollisionContainer !== null ? ($storageContainers->resourceAmounts($restoredCollisionContainer->id)['ice'] ?? null) : null, 'restoring with an identifier collision keeps detached resources');
    }
}

$damageWarningPlayer = $auth->registerPlayerWithPassword('fragile-storage', 'secret', 'Fragile Storage', 'Fragile probe');
$damageWarningProbe = $probes->findByPlayerId($damageWarningPlayer->id);
$damageWarningSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'fragile-storage', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$damageWarningHeaders = ['Authorization' => 'Bearer ' . (string) ($damageWarningSession->body['token'] ?? '')];
if ($damageWarningProbe !== null) {
    for ($index = 0; $index < 14; $index++) {
        $storage->addItem($damageWarningProbe, ProbeItem::TYPE_ADDITIONAL_CONTAINER, ProbeItem::ADDITIONAL_CONTAINER_NAME, 0.0, ['capacityBonus' => 1.0]);
    }
    $damageMove = $kernel->handle('POST', '/api/probe/move', $damageWarningHeaders, json_encode([
        'target' => [
            'x' => 2,
            'y' => 0,
            'z' => 0,
        ],
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $damageMove->status, 'movement with fourteen additional containers starts');

    $damageWarningsResponse = $kernel->handle('GET', '/api/probe/damage-warnings', $damageWarningHeaders);
    $test->assertEquals(200, $damageWarningsResponse->status, 'GET /api/probe/damage-warnings lists movement damage warnings');
    $damageWarning = $damageWarningsResponse->body['damageWarnings'][0] ?? null;
    $test->assertEquals('unread', $damageWarning['status'] ?? null, 'damage warning starts unread');
    $test->assertEquals(100.0, $damageWarning['risk']['percent'] ?? null, 'fourteen additional containers produce a 100 percent break risk');
    $test->assertEquals(14, $damageWarning['risk']['additionalContainerCount'] ?? null, 'damage warning exposes the additional container count');
    $warningContainerId = (string) ($damageWarning['container']['id'] ?? '');
    $warningObjectId = (string) ($damageWarning['container']['detachedObjectId'] ?? '');
    $warningId = (int) ($damageWarning['id'] ?? 0);

    $warningContainer = $storageContainers->findByUidForProbe($damageWarningProbe->id, $warningContainerId);
    if ($warningContainer !== null) {
        $storageContainers->setResourceAmount($warningContainer->id, 'metals', 0.2);
        $storage->ensureProbeStorage($damageWarningProbe);
    }

    $markDamageWarningRead = $kernel->handle('PATCH', '/api/probe/damage-warnings/' . $warningId, $damageWarningHeaders, json_encode([], JSON_THROW_ON_ERROR));
    $test->assertEquals(200, $markDamageWarningRead->status, 'PATCH /api/probe/damage-warnings/{id} marks the warning read');
    $test->assertEquals('read', $markDamageWarningRead->body['damageWarning']['status'] ?? null, 'damage warning read response exposes read status');

    $pdo->exec("UPDATE scheduled_events SET run_at = '2000-01-01T00:00:00+00:00' WHERE type = 'probe.storage_container.break'");
    $schedulerStats = $scheduler->processDueEvents();
    $test->assert($schedulerStats['processed'] >= 1, 'scheduler processes due storage container break events');

    $storedWarning = $damageWarnings->findById($warningId);
    $test->assert($storedWarning?->resolvedAt !== null, 'processed storage break resolves the damage warning');
    $test->assert($storageContainers->findByUidForProbe($damageWarningProbe->id, $warningContainerId) === null, 'storage break removes the selected container from probe storage');
    $test->assertEquals(0.0, $probes->findByPlayerId($damageWarningPlayer->id)?->metalsStock, 'storage break removes selected container resources from probe totals');
    if ($storedWarning !== null) {
        $warningSector = new SectorCoordinates($storedWarning->sectorX, $storedWarning->sectorY, $storedWarning->sectorZ);
        $detachedByMovement = $sectorRepository->load($warningSector)->findObjectById($warningObjectId);
        $test->assertEquals('detached_container', $detachedByMovement?->getType()->value, 'storage break persists the lost container as a drifting sector object');
    }
}

$intelligentLifePlayer = $auth->registerPlayerWithPassword('life-alert', 'secret', 'Life Alert', 'Life probe');
$intelligentLifeProbe = $probes->findByPlayerId($intelligentLifePlayer->id);
$intelligentLifeSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'life-alert', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$intelligentLifeHeaders = ['Authorization' => 'Bearer ' . (string) ($intelligentLifeSession->body['token'] ?? '')];

$teleportLifePlayer = $auth->registerPlayerWithPassword('teleport-life', 'secret', 'Teleport Life', 'Teleport probe');
$teleportLifeProbe = $probes->findByPlayerId($teleportLifePlayer->id);
$teleportLifeSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'teleport-life', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$teleportLifeHeaders = ['Authorization' => 'Bearer ' . (string) ($teleportLifeSession->body['token'] ?? '')];
if ($teleportLifeProbe !== null) {
    $teleportLifeTarget = $teleportLifeProbe->currentSector->add(4, 0, 0);
    $sectorRepository->save(new SectorContent($teleportLifeTarget, [
        new Planet('teleport-life-planet', null, 'ocean', 1.0, 1.0, true, 0.91, ['water_ice'], intelligentLife: true),
    ]));
    $pdo->prepare(
        "UPDATE neumann_probes
         SET sector_x = :x, sector_y = :y, sector_z = :z, status = 'idle', current_task = NULL, entered_current_sector_at = :now, updated_at = :now
         WHERE id = :id"
    )->execute([
        'id' => $teleportLifeProbe->id,
        'x' => $teleportLifeTarget->getX(),
        'y' => $teleportLifeTarget->getY(),
        'z' => $teleportLifeTarget->getZ(),
        'now' => gmdate('c'),
    ]);
    $visitedSectors->markVisited($teleportLifePlayer, $teleportLifeTarget);

    $teleportLifeSector = $kernel->handle('GET', '/api/probe/sector', $teleportLifeHeaders);
    $test->assertEquals(200, $teleportLifeSector->status, 'GET /api/probe/sector succeeds after debug teleport into intelligent-life sector');
    $teleportLifeMessages = $kernel->handle('GET', '/api/probe/messages', $teleportLifeHeaders);
    $test->assertEquals(MissionService::FIRST_CONTACT_SIGNAL, $teleportLifeMessages->body['messages'][0]['body'] ?? null, 'observing current intelligent-life sector creates missing first-contact message');
    $teleportLifeMissions = $kernel->handle('GET', '/api/probe/missions', $teleportLifeHeaders);
    $test->assertEquals('first_contact.return_to_space_program', $teleportLifeMissions->body['missions'][0]['type'] ?? null, 'observing current intelligent-life sector creates missing first-contact mission');
}

if ($intelligentLifeProbe !== null) {
    $intelligentLifeTarget = $intelligentLifeProbe->currentSector->add(2, 0, 0);
    $intelligentLifeLeakyPlanetName = 'Signal-' . str_replace(':', '-', $intelligentLifeTarget->toKey());
    $sectorRepository->save(new SectorContent($intelligentLifeTarget, [
        new Planet('life-alert-planet', $intelligentLifeLeakyPlanetName, 'ocean', 1.0, 1.0, true, 0.82, ['water_ice'], intelligentLife: true),
    ]));

    $intelligentLifeMove = $kernel->handle('POST', '/api/probe/move', $intelligentLifeHeaders, json_encode([
        'target' => [
            'x' => 2,
            'y' => 0,
            'z' => 0,
        ],
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $intelligentLifeMove->status, 'movement toward an intelligent-life sector starts');
    $intelligentLifeMovement = $movements->findActiveByProbeId($intelligentLifeProbe->id);
    if ($intelligentLifeMovement !== null) {
        $pdo->prepare("UPDATE probe_movements SET status = 'decelerating', arrival_at = :arrival, deceleration_ends_at = :arrival WHERE id = :id")->execute([
            'id' => $intelligentLifeMovement->id,
            'arrival' => gmdate('c', time() - 60),
        ]);
        $scheduledEvents->schedule(SchedulerService::PROBE_MOVEMENT_PHASE, 'probe_movement', $intelligentLifeMovement->id, gmdate('c'), ['probeId' => $intelligentLifeMovement->probeId, 'phase' => 'arrived']);
        $lifeSchedulerStats = $scheduler->processDueEvents();
        $test->assert($lifeSchedulerStats['processed'] >= 1, 'scheduler finalizes movement into intelligent-life sector');

        $lifeAlertsResponse = $kernel->handle('GET', '/api/probe/alerts', $intelligentLifeHeaders);
        $test->assertEquals(200, $lifeAlertsResponse->status, 'GET /api/probe/alerts exposes intelligent-life alerts');
        $lifeAlerts = array_values(array_filter(
            $lifeAlertsResponse->body['alerts'] ?? [],
            static fn(array $alert): bool => ($alert['type'] ?? null) === 'intelligent_life',
        ));
        $lifeAlert = $lifeAlerts[0] ?? null;
        $test->assertEquals('unread', $lifeAlert['status'] ?? null, 'intelligent-life alert starts unread');
        $test->assertEquals('life-alert-planet', $lifeAlert['planet']['id'] ?? null, 'intelligent-life alert exposes the planet id');
        $test->assertEquals('Monde habite', $lifeAlert['planet']['name'] ?? null, 'intelligent-life alert hides absolute-coordinate debug planet names');
        $test->assertEquals(['x' => 2, 'y' => 0, 'z' => 0], $lifeAlert['sector']['relative'] ?? null, 'intelligent-life alert exposes the relative sector');
        $test->assert(is_string($lifeAlert['message'] ?? null) && str_contains((string) $lifeAlert['message'], 'Intelligent life detected'), 'intelligent-life alert stores a readable message');
        $test->assert(is_string($lifeAlert['message'] ?? null) && str_contains((string) $lifeAlert['message'], 'relative sector 2:0:0'), 'intelligent-life alert message uses relative sector coordinates');
        $test->assert(!str_contains(json_encode($lifeAlert, JSON_THROW_ON_ERROR), $intelligentLifeTarget->toKey()), 'intelligent-life alert response does not expose absolute sector keys');
        $test->assert(!str_contains(json_encode($lifeAlert, JSON_THROW_ON_ERROR), str_replace(':', '-', $intelligentLifeTarget->toKey())), 'intelligent-life alert response does not expose hyphenated absolute sector keys');

        $firstContactMessages = $kernel->handle('GET', '/api/probe/messages', $intelligentLifeHeaders);
        $test->assertEquals(200, $firstContactMessages->status, 'GET /api/probe/messages exposes first-contact planet messages');
        $test->assertEquals(MissionService::FIRST_CONTACT_SIGNAL, $firstContactMessages->body['messages'][0]['body'] ?? null, 'first-contact planet message contains the prime-count signal');
        $test->assertEquals('planet', $firstContactMessages->body['messages'][0]['sender']['type'] ?? null, 'first-contact message comes from the planet endpoint');
        $test->assertEquals('life-alert-planet', $firstContactMessages->body['messages'][0]['sender']['planetId'] ?? null, 'first-contact message exposes the sender planet id');
        $test->assertEquals('Monde habite', $firstContactMessages->body['messages'][0]['sender']['name'] ?? null, 'first-contact message hides absolute-coordinate debug planet names');
        $test->assertEquals(['x' => 2, 'y' => 0, 'z' => 0], $firstContactMessages->body['messages'][0]['sector']['relative'] ?? null, 'first-contact message exposes only relative sector coordinates');
        $test->assert(!str_contains(json_encode($firstContactMessages->body['messages'][0], JSON_THROW_ON_ERROR), $intelligentLifeTarget->toKey()), 'first-contact message response does not expose absolute sector keys');
        $test->assert(!str_contains(json_encode($firstContactMessages->body['messages'][0], JSON_THROW_ON_ERROR), str_replace(':', '-', $intelligentLifeTarget->toKey())), 'first-contact message response does not expose hyphenated absolute sector keys');

        $firstContactMissions = $kernel->handle('GET', '/api/probe/missions', $intelligentLifeHeaders);
        $test->assertEquals(200, $firstContactMissions->status, 'GET /api/probe/missions exposes first-contact missions');
        $firstContactMission = $firstContactMissions->body['missions'][0] ?? null;
        $test->assertEquals('Premier contact', $firstContactMission['title'] ?? null, 'first-contact mission exposes its title');
        $test->assertEquals('first_contact.return_to_space_program', $firstContactMission['type'] ?? null, 'first-contact mission exposes the selected scenario type');
        $test->assertEquals('return_to_space_program', $firstContactMission['metadata']['scenario'] ?? null, 'first-contact mission metadata exposes the selected scenario key');
        $test->assertEquals('life-alert-planet', $firstContactMission['metadata']['planetId'] ?? null, 'first-contact mission metadata exposes the planet id');
        $test->assertEquals('Monde habite', $firstContactMission['metadata']['planetName'] ?? null, 'first-contact mission metadata hides absolute-coordinate debug planet names');
        $test->assertEquals(['x' => 2, 'y' => 0, 'z' => 0], $firstContactMission['metadata']['sector']['relative'] ?? null, 'first-contact mission metadata exposes relative sector coordinates');
        $test->assert(!array_key_exists('x', $firstContactMission['metadata']['sector'] ?? []), 'first-contact mission metadata does not expose absolute sector x');
        $test->assertEquals(['x' => 2, 'y' => 0, 'z' => 0], $firstContactMission['createdByEvent']['sector']['relative'] ?? null, 'first-contact mission event exposes relative sector coordinates');
        $test->assert(!str_contains(json_encode($firstContactMission, JSON_THROW_ON_ERROR), $intelligentLifeTarget->toKey()), 'first-contact mission response does not expose absolute sector keys');
        $test->assert(is_string($firstContactMission['description'] ?? null) && str_contains((string) $firstContactMission['description'], MissionService::FIRST_CONTACT_SIGNAL), 'first-contact mission description includes the received signal');
        $test->assert(is_string($firstContactMission['description'] ?? null) && str_contains((string) $firstContactMission['description'], 'secteur relatif 2:0:0'), 'first-contact mission description uses relative sector coordinates');
        $test->assertEquals('pending', $firstContactMission['steps'][0]['status'] ?? null, 'first-contact reply step starts pending');

        $firstContactReply = $kernel->handle('POST', '/api/probe/messages', $intelligentLifeHeaders, json_encode([
            'recipient' => [
                'type' => 'planet',
                'id' => 'life-alert-planet',
            ],
            'body' => '-----------',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(201, $firstContactReply->status, 'POST /api/probe/messages sends the prime-sequence reply to the planet');
        $firstContactMessagesAfterReply = $kernel->handle('GET', '/api/probe/messages', $intelligentLifeHeaders);
        $planetScenarioReply = $firstContactMessagesAfterReply->body['messages'][0] ?? null;
        $test->assertEquals('planet', $planetScenarioReply['sender']['type'] ?? null, 'planet answers the valid prime-sequence reply');
        $test->assert(is_string($planetScenarioReply['body'] ?? null) && str_contains((string) $planetScenarioReply['body'], 'Nous sommes les habitants de ce monde.'), 'planet reply introduces the inhabited world');
        $test->assert(is_string($planetScenarioReply['body'] ?? null) && str_contains((string) $planetScenarioReply['body'], '5 ECE'), 'planet reply asks for five ECE of metals');
        $test->assert(is_string($planetScenarioReply['body'] ?? null) && str_contains((string) $planetScenarioReply['body'], 'Composés carbonés: 1ECE'), 'planet reply asks for one ECE of carbon compounds');
        $firstContactAfterReply = $kernel->handle('GET', '/api/probe/missions', $intelligentLifeHeaders);
        $firstContactMissionAfterReply = $firstContactAfterReply->body['missions'][0] ?? null;
        $test->assertEquals('active', $firstContactMissionAfterReply['status'] ?? null, 'first-contact mission remains active for its next scenario step');
        $test->assertEquals('completed', $firstContactMissionAfterReply['steps'][0]['status'] ?? null, 'valid prime-sequence reply completes the first-contact decoding step');
        $test->assertEquals('completed', $firstContactMissionAfterReply['steps'][1]['status'] ?? null, 'planet reply completes the waiting-for-reply step');
        $test->assertEquals('pending', $firstContactMissionAfterReply['steps'][2]['status'] ?? null, 'first-contact mission waits for metal delivery');
        $test->assertEquals(5, $firstContactMissionAfterReply['steps'][2]['metadata']['amount'] ?? null, 'metal delivery step exposes the requested amount');
        $test->assertEquals('pending', $firstContactMissionAfterReply['steps'][3]['status'] ?? null, 'first-contact mission waits for carbon-compound delivery');
        $test->assertEquals('carbon_compounds', $firstContactMissionAfterReply['steps'][3]['metadata']['resourceType'] ?? null, 'carbon delivery step exposes the requested resource type');
        $test->assertEquals(1, $firstContactMissionAfterReply['steps'][3]['metadata']['amount'] ?? null, 'carbon delivery step exposes the requested amount');
        $initialMaterialCounter = $sectorRepository->load($intelligentLifeTarget)->returnToSpaceProgramMaterialCounterForPlanet('life-alert-planet');
        $test->assertEquals(5, $initialMaterialCounter['requirements']['metals'] ?? null, 'return-to-space counter stores the requested metals');
        $test->assertEquals(1, $initialMaterialCounter['requirements']['carbon_compounds'] ?? null, 'return-to-space counter stores the requested carbon compounds');
        $test->assertEquals([], $initialMaterialCounter['donations'] ?? null, 'return-to-space counter starts without donations');

        $intelligentLifeProbeForDrop = $probes->findByPlayerId($intelligentLifePlayer->id);
        $missionDropContainerItem = $intelligentLifeProbeForDrop !== null
            ? $storage->addItem($intelligentLifeProbeForDrop, ProbeItem::TYPE_ADDITIONAL_CONTAINER, ProbeItem::ADDITIONAL_CONTAINER_NAME, 0.0, ['capacityBonus' => 1.0])
            : null;
        $missionDropContainerUid = $missionDropContainerItem !== null ? 'container-' . $missionDropContainerItem->uid : '';
        $missionDropContainer = $intelligentLifeProbeForDrop !== null ? $storageContainers->findByUidForProbe($intelligentLifeProbeForDrop->id, $missionDropContainerUid) : null;
        if ($intelligentLifeProbeForDrop !== null && $missionDropContainer !== null) {
            $storage->addItem($intelligentLifeProbeForDrop, ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT, ProbeItem::ATMOSPHERIC_DROP_KIT_NAME, 0.08);
            $storageContainers->setResourceAmount($missionDropContainer->id, 'metals', 0.5);
            $storageContainers->setResourceAmount($missionDropContainer->id, 'carbon_compounds', 0.4);
            $intelligentLifeProbeForDrop->metalsStock = 0.5;
            $intelligentLifeProbeForDrop->organicCompoundsStock = 0.4;
            $probes->save($intelligentLifeProbeForDrop);
            $missionDropManny = array_values(array_filter(
                $mannies->findByProbeId($intelligentLifeProbeForDrop->id),
                static fn($manny): bool => $manny->isOnProbe() && $manny->currentTask === null,
            ))[0] ?? null;
            if ($missionDropManny !== null) {
                $missionDropAccepted = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($missionDropManny->uid) . '/drop-storage-container', $intelligentLifeHeaders, json_encode([
                    'containerId' => $missionDropContainerUid,
                    'planetId' => 'life-alert-planet',
                ], JSON_THROW_ON_ERROR));
                $test->assertEquals(202, $missionDropAccepted->status, 'first-contact material drop starts through the Manny drop endpoint');
                $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
                    'id' => $missionDropManny->id,
                    'ended' => gmdate('c', time() - 1),
                ]);
                $kernel->handle('GET', '/api/probe/mannies', $intelligentLifeHeaders);
                $materialCounterAfterDrop = $sectorRepository->load($intelligentLifeTarget)->returnToSpaceProgramMaterialCounterForPlanet('life-alert-planet');
                $test->assertEquals(0.5, $materialCounterAfterDrop['totals']['metals'] ?? null, 'material drop counter adds dropped metals');
                $test->assertEquals(0.4, $materialCounterAfterDrop['totals']['carbon_compounds'] ?? null, 'material drop counter adds dropped carbon compounds');
                $test->assertEquals(4.5, $materialCounterAfterDrop['remaining']['metals'] ?? null, 'material drop counter tracks remaining metals');
                $test->assertEquals(0.6, $materialCounterAfterDrop['remaining']['carbon_compounds'] ?? null, 'material drop counter tracks remaining carbon compounds');
                $donation = $materialCounterAfterDrop['donations'][0] ?? null;
                $test->assertEquals($intelligentLifePlayer->id, $donation['playerId'] ?? null, 'material drop donation records the donating player id');
                $test->assertEquals(0.5, $donation['resources']['metals'] ?? null, 'material drop donation records donated metals');
                $test->assertEquals(0.4, $donation['resources']['carbon_compounds'] ?? null, 'material drop donation records donated carbon compounds');
                $messagesAfterMaterialDrop = $kernel->handle('GET', '/api/probe/messages', $intelligentLifeHeaders);
                $materialThanks = $messagesAfterMaterialDrop->body['messages'][0] ?? null;
                $test->assertEquals('planet', $materialThanks['sender']['type'] ?? null, 'planet thanks the player after a material drop');
                $test->assert(is_string($materialThanks['body'] ?? null) && str_contains((string) $materialThanks['body'], 'Merci pour votre aide.'), 'material drop thanks message thanks the player');
                $test->assert(is_string($materialThanks['body'] ?? null) && str_contains((string) $materialThanks['body'], 'Métaux : 4.5 ECE'), 'material drop thanks message reports remaining metals');
                $test->assert(is_string($materialThanks['body'] ?? null) && str_contains((string) $materialThanks['body'], 'Composés carbonés : 0.6 ECE'), 'material drop thanks message reports remaining carbon compounds');

                $fellowContributor = $auth->registerPlayerWithPassword('mission-fellow', 'secret', 'Fellow Visitor', 'Fellow probe');
                $fellowContributorProbe = $probes->findByPlayerId($fellowContributor->id);
                if ($fellowContributorProbe !== null) {
                    $fellowContributorProbe->currentSector = $intelligentLifeTarget;
                    $probes->save($fellowContributorProbe);
                    $sectorWithFellowDonation = $sectorRepository->load($intelligentLifeTarget);
                    $sectorWithFellowDonation->recordReturnToSpaceProgramMaterialDonation(
                        'life-alert-planet',
                        'Monde habite',
                        [
                            ResourceComposition::METALS => 5.0,
                            ResourceComposition::CARBON_COMPOUNDS => 1.0,
                        ],
                        $fellowContributor->id,
                        $fellowContributorProbe->id,
                        'test-fellow-container',
                        [
                            ResourceComposition::METALS => 4.0,
                            ResourceComposition::CARBON_COMPOUNDS => 0.5,
                        ],
                    );
                    $sectorRepository->save($sectorWithFellowDonation);
                }

                $nonSolverContributor = $auth->registerPlayerWithPassword('mission-non-solver', 'secret', 'Non Solver', 'Non solver probe');
                $nonSolverProbe = $probes->findByPlayerId($nonSolverContributor->id);
                if ($nonSolverProbe !== null) {
                    $nonSolverProbe->currentSector = $intelligentLifeTarget;
                    $probes->save($nonSolverProbe);
                    $sectorBeforeIgnoredDonation = $sectorRepository->load($intelligentLifeTarget);
                    $ignoredDonation = $missionService->handleReturnToSpaceProgramMaterialDrop(
                        $nonSolverProbe,
                        $sectorBeforeIgnoredDonation,
                        'life-alert-planet',
                        $nonSolverContributor->id,
                        'test-non-solver-container',
                        [
                            ResourceComposition::METALS => 5.0,
                            ResourceComposition::CARBON_COMPOUNDS => 1.0,
                        ],
                    );
                    $counterAfterIgnoredDonation = $sectorBeforeIgnoredDonation->returnToSpaceProgramMaterialCounterForPlanet('life-alert-planet');
                    $test->assertEquals(null, $ignoredDonation, 'first-contact material drops from players without the decoded mission are ignored');
                    $test->assertEquals(4.5, (float) ($counterAfterIgnoredDonation['totals'][ResourceComposition::METALS] ?? -1), 'ignored first-contact drop does not change donated metals');
                    $test->assertEquals(0.9, (float) ($counterAfterIgnoredDonation['totals'][ResourceComposition::CARBON_COMPOUNDS] ?? -1), 'ignored first-contact drop does not change donated carbon compounds');
                }

                $intelligentLifeProbeForFinalDrop = $probes->findByPlayerId($intelligentLifePlayer->id);
                $finalDropContainerItem = $intelligentLifeProbeForFinalDrop !== null
                    ? $storage->addItem($intelligentLifeProbeForFinalDrop, ProbeItem::TYPE_ADDITIONAL_CONTAINER, ProbeItem::ADDITIONAL_CONTAINER_NAME, 0.0, ['capacityBonus' => 1.0])
                    : null;
                $finalDropContainerUid = $finalDropContainerItem !== null ? 'container-' . $finalDropContainerItem->uid : '';
                $finalDropContainer = $intelligentLifeProbeForFinalDrop !== null ? $storageContainers->findByUidForProbe($intelligentLifeProbeForFinalDrop->id, $finalDropContainerUid) : null;
                if ($intelligentLifeProbeForFinalDrop !== null && $finalDropContainer !== null) {
                    $storage->addItem($intelligentLifeProbeForFinalDrop, ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT, ProbeItem::ATMOSPHERIC_DROP_KIT_NAME, 0.08);
                    $storageContainers->setResourceAmount($finalDropContainer->id, ResourceComposition::METALS, 0.5);
                    $storageContainers->setResourceAmount($finalDropContainer->id, ResourceComposition::CARBON_COMPOUNDS, 0.1);
                    $intelligentLifeProbeForFinalDrop->metalsStock = 0.5;
                    $intelligentLifeProbeForFinalDrop->organicCompoundsStock = 0.1;
                    $probes->save($intelligentLifeProbeForFinalDrop);
                    $finalDropManny = array_values(array_filter(
                        $mannies->findByProbeId($intelligentLifeProbeForFinalDrop->id),
                        static fn($manny): bool => $manny->isOnProbe() && $manny->currentTask === null,
                    ))[0] ?? null;
                    if ($finalDropManny !== null) {
                        $finalDropAccepted = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($finalDropManny->uid) . '/drop-storage-container', $intelligentLifeHeaders, json_encode([
                            'containerId' => $finalDropContainerUid,
                            'planetId' => 'life-alert-planet',
                        ], JSON_THROW_ON_ERROR));
                        $test->assertEquals(202, $finalDropAccepted->status, 'final first-contact material drop starts through the Manny drop endpoint');
                        $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
                            'id' => $finalDropManny->id,
                            'ended' => gmdate('c', time() - 1),
                        ]);
                        $kernel->handle('GET', '/api/probe/mannies', $intelligentLifeHeaders);

                        $materialCounterAfterFinalDrop = $sectorRepository->load($intelligentLifeTarget)->returnToSpaceProgramMaterialCounterForPlanet('life-alert-planet');
                        $test->assertEquals(5.0, (float) ($materialCounterAfterFinalDrop['totals'][ResourceComposition::METALS] ?? -1), 'material drop counter reaches required metals');
                        $test->assertEquals(1.0, (float) ($materialCounterAfterFinalDrop['totals'][ResourceComposition::CARBON_COMPOUNDS] ?? -1), 'material drop counter reaches required carbon compounds');
                        $test->assertEquals(0.0, (float) ($materialCounterAfterFinalDrop['remaining'][ResourceComposition::METALS] ?? -1), 'material drop counter has no remaining metals');
                        $test->assertEquals(0.0, (float) ($materialCounterAfterFinalDrop['remaining'][ResourceComposition::CARBON_COMPOUNDS] ?? -1), 'material drop counter has no remaining carbon compounds');
                        $test->assert(isset($materialCounterAfterFinalDrop['completionMessageSentAt']), 'material drop counter records the final completion message delivery');

                        $messagesAfterFinalDrop = $kernel->handle('GET', '/api/probe/messages', $intelligentLifeHeaders);
                        $completionThanks = $messagesAfterFinalDrop->body['messages'][0] ?? null;
                        $test->assertEquals('planet', $completionThanks['sender']['type'] ?? null, 'planet sends the final return-to-space completion message');
                        $test->assert(is_string($completionThanks['body'] ?? null) && str_contains((string) $completionThanks['body'], 'Voyageur des étoiles,'), 'final return-to-space message starts with the requested salutation');
                        $test->assert(is_string($completionThanks['body'] ?? null) && str_contains((string) $completionThanks['body'], 'Fellow Visitor'), 'final return-to-space message lists the other contributor');
                        $test->assert(is_string($completionThanks['body'] ?? null) && str_contains((string) $completionThanks['body'], 'Revenez dans 48 heures.'), 'final return-to-space message announces the forty-eight-hour follow-up');
                        $test->assert(!str_contains((string) ($completionThanks['body'] ?? ''), 'Ressources restantes à envoyer'), 'final return-to-space message replaces the partial remaining-resource thanks');

                        if ($fellowContributorProbe !== null) {
                            $fellowMessages = $messages->receivedByProbe($fellowContributorProbe->id);
                            $fellowCompletionThanks = $fellowMessages[0] ?? null;
                            $test->assert(is_string($fellowCompletionThanks?->body ?? null) && str_contains((string) $fellowCompletionThanks?->body, 'Voyageur des étoiles,'), 'planet also sends the final message to present contributor probes');
                            $test->assert(is_string($fellowCompletionThanks?->body ?? null) && str_contains((string) $fellowCompletionThanks?->body, 'Life Alert'), 'present contributor message lists the triggering contributor');
                        }

                        $completedFirstContactMissions = array_values(array_filter(
                            $missions->findForProbe($intelligentLifeProbeForFinalDrop->id, [Mission::STATUS_COMPLETED]),
                            static fn(Mission $mission): bool => $mission->type === 'first_contact.return_to_space_program',
                        ));
                        $test->assertEquals(1, count($completedFirstContactMissions), 'first-contact mission completes when the requested materials are fully delivered');
                    }
                }
            }
        }

        $duplicateFirstContactReply = $kernel->handle('POST', '/api/probe/messages', $intelligentLifeHeaders, json_encode([
            'recipient' => [
                'type' => 'planet',
                'id' => 'life-alert-planet',
            ],
            'body' => '-----------',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(201, $duplicateFirstContactReply->status, 'POST /api/probe/messages accepts a repeated prime-sequence reply');
        $messagesAfterDuplicateReply = $kernel->handle('GET', '/api/probe/messages', $intelligentLifeHeaders);
        $planetScenarioReplies = array_values(array_filter(
            $messagesAfterDuplicateReply->body['messages'] ?? [],
            static fn(array $message): bool => is_string($message['body'] ?? null)
                && str_contains((string) $message['body'], 'Nous sommes les habitants de ce monde.')
        ));
        $test->assertEquals(1, count($planetScenarioReplies), 'repeated prime-sequence replies do not duplicate the planet scenario response');

        $markLifeAlertRead = $kernel->handle('PATCH', '/api/probe/alerts/' . (int) ($lifeAlert['id'] ?? 0), $intelligentLifeHeaders, json_encode([], JSON_THROW_ON_ERROR));
        $test->assertEquals(200, $markLifeAlertRead->status, 'PATCH /api/probe/alerts/{id} marks an intelligent-life alert read');
        $test->assertEquals('read', $markLifeAlertRead->body['alert']['status'] ?? null, 'intelligent-life alert read response exposes read status');
    }
}

$apiKeyResponse = $kernel->handle('POST', '/api/me/api-key', $headers, json_encode([], JSON_THROW_ON_ERROR));
$test->assertEquals(201, $apiKeyResponse->status, 'POST /api/me/api-key creates an API key');
$apiKeyToken = $apiKeyResponse->body['apiKey']['token'] ?? null;
$test->assert(is_string($apiKeyToken) && str_starts_with($apiKeyToken, 'vng_'), 'API key response returns a generated vng_ token');
$plainApiKeyStored = $pdo->prepare('SELECT COUNT(*) FROM api_keys WHERE token_hash = :token');
$plainApiKeyStored->execute(['token' => $apiKeyToken]);
$test->assertEquals(0, (int) $plainApiKeyStored->fetchColumn(), 'API key is not stored in clear text');
$hashedApiKeyStored = $pdo->prepare('SELECT COUNT(*) FROM api_keys WHERE token_hash = :hash');
$hashedApiKeyStored->execute(['hash' => ApiKeyRepository::hashToken((string) $apiKeyToken)]);
$test->assertEquals(1, (int) $hashedApiKeyStored->fetchColumn(), 'API key hash is stored');
$meViaApiKey = $kernel->handle('GET', '/api/me', ['Authorization' => 'Bearer ' . $apiKeyToken]);
$test->assertEquals(200, $meViaApiKey->status, 'generated API key can authenticate GET /api/me');
$test->assertEquals('remi', $meViaApiKey->body['player']['username'] ?? null, 'API key authenticates as its player');

$probe = $kernel->handle('GET', '/api/probe', $headers);
$test->assertEquals(200, $probe->status, 'valid token allows GET /api/probe');
$test->assertEquals('idle', $probe->body['probe']['status'] ?? null, 'GET /api/probe returns probe status');
$test->assertEquals(100.0, $probe->body['probe']['systems']['integrityPercent'] ?? null, 'new probe starts with full integrity');
$test->assert(!array_key_exists('damagePercent', $probe->body['probe']['systems'] ?? []), 'GET /api/probe no longer exposes damage percent');
$test->assert(isset($probe->body['probe']['sector']['relative']), 'GET /api/probe exposes relative sector coordinates');
$test->assertEquals(['x' => 0, 'y' => 0, 'z' => 0], $probe->body['probe']['sector']['relative'] ?? null, 'player sees initial sector as relative coordinates [0,0,0]');
$test->assert(!str_contains(json_encode($probe->body, JSON_THROW_ON_ERROR), 'absolute'), 'GET /api/probe does not expose absolute coordinates');
$aliveReassignment = $kernel->handle('POST', '/api/probe/mind-snapshot/reassign', $headers, json_encode([], JSON_THROW_ON_ERROR));
$test->assertEquals(409, $aliveReassignment->status, 'POST /api/probe/mind-snapshot/reassign rejects an operational probe');
$test->assertEquals('probe_reassignment_unavailable', $aliveReassignment->body['error']['code'] ?? null, 'operational probe reassignment error code is explicit');

$visitedSectorList = $kernel->handle('GET', '/api/probe/visited-sectors', $headers);
$test->assertEquals(200, $visitedSectorList->status, 'valid token allows GET /api/probe/visited-sectors');
$initialVisitedSector = $visitedSectorList->body['visitedSectors'][0] ?? null;
$test->assertEquals(['x' => 0, 'y' => 0, 'z' => 0], $initialVisitedSector['relativeCoordinates'] ?? null, 'visited-sector list exposes relative coordinates');
$test->assertEquals(1, $initialVisitedSector['visitCount'] ?? null, 'visited-sector list exposes visit count');
$test->assert(isset($initialVisitedSector['firstVisitedAt']) && strtotime((string) $initialVisitedSector['firstVisitedAt']) !== false, 'visited-sector list exposes first visit date');
$test->assert(isset($initialVisitedSector['lastVisitedAt']) && strtotime((string) $initialVisitedSector['lastVisitedAt']) !== false, 'visited-sector list exposes last visit date');
$test->assert(!str_contains(json_encode($visitedSectorList->body, JSON_THROW_ON_ERROR), 'absolute'), 'visited-sector list does not expose absolute coordinates');

$sector = $kernel->handle('GET', '/api/probe/sector', $headers);
$test->assertEquals(200, $sector->status, 'valid token allows GET /api/probe/sector');
$test->assert(isset($sector->body['sector']['objects']), 'GET /api/probe/sector returns sector objects');
$test->assertEquals('detailed', $sector->body['sector']['knowledgeLevel'] ?? null, 'current sector returns detailed information');
$test->assertEquals(1.0, $sector->body['inventory']['capacity'] ?? null, 'GET /api/probe/sector returns default probe transport capacity');
$test->assertEquals('earth_container_equivalent', $sector->body['inventory']['capacityUnit'] ?? null, 'probe transport capacity uses earth container equivalent units');
$test->assertEquals(0.5, $sector->body['inventory']['usedCapacity'] ?? null, 'default inventory occupies half a container');
$test->assertEquals(0.5, $sector->body['inventory']['freeCapacity'] ?? null, 'external deuterium tank does not consume cargo capacity');
$test->assertEquals(5, count($sector->body['inventory']['items'] ?? []), 'default inventory contains one printer and four mannies');
$test->assertEquals(1, count($sector->body['inventory']['externalTanks'] ?? []), 'default probe has one external tank');
$deuteriumTank = $sector->body['inventory']['externalTanks'][0] ?? null;
$test->assertEquals('deuterium', $deuteriumTank['type'] ?? null, 'default external tank stores deuterium');
$test->assertEquals(100.0, $deuteriumTank['fillPercent'] ?? null, 'default deuterium tank starts full');
$test->assertEquals(false, $deuteriumTank['usesCargoCapacity'] ?? null, 'deuterium tank is outside cargo capacity');
$jettisonDeuteriumTank = $kernel->handle('POST', '/api/probe/inventory/' . rawurlencode((string) ($deuteriumTank['id'] ?? 'missing')) . '/jettison', $headers, json_encode([
    'amount' => 100,
], JSON_THROW_ON_ERROR));
$test->assertEquals(422, $jettisonDeuteriumTank->status, 'external deuterium tank cannot be jettisoned');
$test->assertEquals('item_not_jettisonable', $jettisonDeuteriumTank->body['error']['code'] ?? null, 'deuterium tank jettison returns an explicit error');
$test->assertEquals(100.0, $probes->findByPlayerId($player->id)?->deuteriumStock, 'failed deuterium tank jettison keeps fuel stock unchanged');
if ($createdProbe !== null) {
    $initialNeighbor = (new SectorGrid())->getNeighbors($createdProbe->currentSector)[0];
    $initialNeighborRelative = $initialNeighbor->subtract($player->homeSector);
    $initialNeighborScan = $kernel->handle('GET', '/api/sector?x=' . $initialNeighborRelative['x'] . '&y=' . $initialNeighborRelative['y'] . '&z=' . $initialNeighborRelative['z'], $headers);
    $test->assertEquals(200, $initialNeighborScan->status, 'initial probe can scan a neighbor without residence delay');
    $test->assertEquals(0, $initialNeighborScan->body['sector']['scan']['requiredResidenceSeconds'] ?? null, 'initial neighbor scan exposes no residence delay');
}

$stationaryNeighbor = $auth->registerPlayerWithPassword('stationary-neighbor', 'secret', 'Stationary Neighbor', 'Stationary neighbor probe');
$movingNeighbor = $auth->registerPlayerWithPassword('moving-neighbor', 'secret', 'Moving Neighbor', 'Moving neighbor probe');
$stationaryNeighborProbe = $probes->findByPlayerId($stationaryNeighbor->id);
$movingNeighborProbe = $probes->findByPlayerId($movingNeighbor->id);
if ($createdProbe !== null && $stationaryNeighborProbe !== null && $movingNeighborProbe !== null) {
    $stationaryNeighborProbe->currentSector = $createdProbe->currentSector;
    $probes->save($stationaryNeighborProbe);
    $movingNeighborProbe->currentSector = $createdProbe->currentSector;
    $probes->save($movingNeighborProbe);
    $sectorRepository->save(new SectorContent($createdProbe->currentSector, [
        new Planet('message-life-planet', 'Warm Chorus', 'ocean', 1.0, 1.0, true, 0.79, ['water_ice'], intelligentLife: true),
        new Planet('message-empty-planet', 'Quiet Rock', 'rocky', 0.8, 0.9, true, 0.33, ['silicates']),
    ]));

    $neighborTarget = (new SectorGrid())->getNeighbors($createdProbe->currentSector)[0];
    $movements->create(
        $movingNeighborProbe->id,
        $movingNeighborProbe->currentSector,
        $neighborTarget,
        1,
        (new MovementDurationCalculator())->timeline(new DateTimeImmutable('now', new DateTimeZone('UTC')), 1),
        2.0,
    );

    $sectorWithProbePresence = $kernel->handle('GET', '/api/probe/sector', $headers);
    $detectedProbesById = [];
    foreach ($sectorWithProbePresence->body['sector']['probes'] ?? [] as $detectedProbe) {
        $detectedProbesById[(int) ($detectedProbe['id'] ?? 0)] = $detectedProbe;
    }
    $test->assert(!isset($detectedProbesById[$createdProbe->id]), 'current probe is not listed as another sector probe');
    $test->assertEquals('Stationary neighbor probe', $detectedProbesById[$stationaryNeighborProbe->id]['name'] ?? null, 'current-sector scan exposes another probe name');
    $test->assertEquals(false, $detectedProbesById[$stationaryNeighborProbe->id]['moving'] ?? null, 'current-sector scan marks idle neighbor probe as not moving');
    $test->assertEquals('Moving neighbor probe', $detectedProbesById[$movingNeighborProbe->id]['name'] ?? null, 'current-sector scan exposes moving probe name');
    $test->assertEquals(true, $detectedProbesById[$movingNeighborProbe->id]['moving'] ?? null, 'current-sector scan marks active neighbor probe as moving');

    $stationarySession = $auth->createSessionForPlayer($stationaryNeighbor);
    $stationaryHeaders = ['Authorization' => 'Bearer ' . $stationarySession['token']];
    $badMessage = $kernel->handle('POST', '/api/probe/messages', $headers, json_encode(['body' => 'ping'], JSON_THROW_ON_ERROR));
    $test->assertEquals(400, $badMessage->status, 'POST /api/probe/messages requires recipientProbeId');
    $selfMessage = $kernel->handle('POST', '/api/probe/messages', $headers, json_encode([
        'recipientProbeId' => $createdProbe->id,
        'body' => 'self ping',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(422, $selfMessage->status, 'POST /api/probe/messages rejects self messages');
    $test->assertEquals('invalid_message_recipient', $selfMessage->body['error']['code'] ?? null, 'self message returns an explicit recipient error');

    $distantMessagePlayer = $auth->registerPlayerWithPassword('distant-message', 'secret', 'Distant Message', 'Distant message probe');
    $distantMessageProbe = $probes->findByPlayerId($distantMessagePlayer->id);
    if ($distantMessageProbe !== null) {
        $distantMessageProbe->currentSector = $neighborTarget;
        $probes->save($distantMessageProbe);
        $outOfSectorMessage = $kernel->handle('POST', '/api/probe/messages', $headers, json_encode([
            'recipientProbeId' => $distantMessageProbe->id,
            'body' => 'too far',
        ], JSON_THROW_ON_ERROR));
        $test->assertEquals(422, $outOfSectorMessage->status, 'POST /api/probe/messages requires the recipient probe to share the sector');
        $test->assertEquals('probe_not_in_same_sector', $outOfSectorMessage->body['error']['code'] ?? null, 'out-of-sector message returns an explicit error');
    }

    $sentMessage = $kernel->handle('POST', '/api/probe/messages', $headers, json_encode([
        'recipientProbeId' => $stationaryNeighborProbe->id,
        'body' => '  Signal de bienvenue  ',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(201, $sentMessage->status, 'POST /api/probe/messages sends a message to a probe in the same sector');
    $test->assertEquals('Signal de bienvenue', $sentMessage->body['message']['body'] ?? null, 'sent probe message body is trimmed');
    $test->assertEquals($createdProbe->id, $sentMessage->body['message']['sender']['probeId'] ?? null, 'sent probe message exposes sender probe id');
    $test->assertEquals('probe', $sentMessage->body['message']['sender']['type'] ?? null, 'sent probe message exposes sender type');
    $test->assertEquals($stationaryNeighborProbe->id, $sentMessage->body['message']['recipient']['probeId'] ?? null, 'sent probe message exposes recipient probe id');
    $test->assertEquals('probe', $sentMessage->body['message']['recipient']['type'] ?? null, 'sent probe message exposes recipient type');
    $test->assertEquals('unread', $sentMessage->body['message']['status'] ?? null, 'new probe message starts unread');
    $sentMessageId = (int) ($sentMessage->body['message']['id'] ?? 0);

    $senderMessages = $kernel->handle('GET', '/api/probe/messages', $headers);
    $test->assertEquals(200, $senderMessages->status, 'GET /api/probe/messages lists received messages');
    $senderReceivedOwnProbeMessages = array_values(array_filter(
        $senderMessages->body['messages'] ?? [],
        static fn(array $message): bool => ($message['body'] ?? null) === 'Signal de bienvenue'
    ));
    $test->assertEquals(0, count($senderReceivedOwnProbeMessages), 'sender does not receive its own outbound probe message');
    $test->assertEquals(50, $senderMessages->body['pagination']['limit'] ?? null, 'probe message list defaults to a 50 message limit');
    $test->assertEquals(1, $senderMessages->body['pagination']['total'] ?? null, 'probe message list exposes the total received message count');
    $senderSentMessages = $kernel->handle('GET', '/api/probe/messages/sent', $headers);
    $test->assertEquals(200, $senderSentMessages->status, 'GET /api/probe/messages/sent lists sent messages');
    $test->assertEquals('Signal de bienvenue', $senderSentMessages->body['messages'][0]['body'] ?? null, 'sender sees the outbound probe message body');
    $test->assertEquals('Stationary neighbor probe', $senderSentMessages->body['messages'][0]['recipient']['name'] ?? null, 'sent probe message exposes recipient name');
    $test->assert(!array_key_exists('status', $senderSentMessages->body['messages'][0] ?? []), 'sent probe message list does not expose read status');
    $test->assert(!array_key_exists('readAt', $senderSentMessages->body['messages'][0] ?? []), 'sent probe message list does not expose read timestamp');
    $test->assert(!array_key_exists('updatedAt', $senderSentMessages->body['messages'][0] ?? []), 'sent probe message list does not expose read-driven update timestamp');
    $test->assertEquals(1, $senderSentMessages->body['pagination']['total'] ?? null, 'sent probe message list exposes the total sent message count');

    $planetMessage = $kernel->handle('POST', '/api/probe/messages', $headers, json_encode([
        'recipient' => [
            'type' => 'planet',
            'id' => 'message-life-planet',
        ],
        'body' => 'Salutations orbitales',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(201, $planetMessage->status, 'POST /api/probe/messages sends a message to an inhabited planet in the current sector');
    $test->assertEquals('planet', $planetMessage->body['message']['recipient']['type'] ?? null, 'planet message exposes a planet recipient type');
    $test->assertEquals('message-life-planet', $planetMessage->body['message']['recipient']['planetId'] ?? null, 'planet message exposes recipient planet id');
    $test->assertEquals('Warm Chorus', $planetMessage->body['message']['recipient']['name'] ?? null, 'planet message exposes recipient planet name');

    $uninhabitedPlanetMessage = $kernel->handle('POST', '/api/probe/messages', $headers, json_encode([
        'recipient' => [
            'type' => 'planet',
            'id' => 'message-empty-planet',
        ],
        'body' => 'Y a-t-il quelqu’un ?',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(422, $uninhabitedPlanetMessage->status, 'POST /api/probe/messages rejects uninhabited planet recipients');
    $test->assertEquals('invalid_message_recipient', $uninhabitedPlanetMessage->body['error']['code'] ?? null, 'uninhabited planet recipient returns an explicit error');

    $recipientSentMessages = $kernel->handle('GET', '/api/probe/messages/sent', $stationaryHeaders);
    $test->assertEquals(0, $recipientSentMessages->body['pagination']['total'] ?? null, 'recipient does not see inbound messages in sent messages');
    $receivedMessages = $kernel->handle('GET', '/api/probe/messages', $stationaryHeaders);
    $test->assertEquals(200, $receivedMessages->status, 'recipient can list received probe messages');
    $test->assertEquals('Signal de bienvenue', $receivedMessages->body['messages'][0]['body'] ?? null, 'recipient sees the received probe message body');
    $test->assertEquals('Probe of remi', $receivedMessages->body['messages'][0]['sender']['name'] ?? null, 'received probe message exposes sender name');
    $test->assert(isset($receivedMessages->body['messages'][0]['sector']['relative']), 'received probe message exposes a relative sector');
    $test->assertEquals(1, $receivedMessages->body['pagination']['count'] ?? null, 'probe message list exposes the page item count');
    $test->assertEquals(false, $receivedMessages->body['pagination']['hasMore'] ?? null, 'probe message list reports when no older page remains');

    $planetInbound = $messages->createForEndpoints(
        ProbeMessage::ENDPOINT_PLANET,
        'message-life-planet',
        'Warm Chorus',
        null,
        ProbeMessage::ENDPOINT_PROBE,
        (string) $createdProbe->id,
        null,
        $createdProbe->id,
        $createdProbe->currentSector,
        'Nous vous recevons.',
    );
    $probeReceivedPlanetMessages = $kernel->handle('GET', '/api/probe/messages', $headers);
    $test->assertEquals(200, $probeReceivedPlanetMessages->status, 'probe can list messages received from inhabited planets');
    $test->assertEquals('Nous vous recevons.', $probeReceivedPlanetMessages->body['messages'][0]['body'] ?? null, 'planet inbound message body is exposed');
    $test->assertEquals('planet', $probeReceivedPlanetMessages->body['messages'][0]['sender']['type'] ?? null, 'planet inbound message exposes sender type');
    $test->assertEquals('message-life-planet', $probeReceivedPlanetMessages->body['messages'][0]['sender']['planetId'] ?? null, 'planet inbound message exposes sender planet id');
    $planetInboundRead = $kernel->handle('PATCH', '/api/probe/messages/' . $planetInbound->id . '/read', $headers);
    $test->assertEquals(200, $planetInboundRead->status, 'probe can mark a planet-origin message as read');

    $senderRead = $kernel->handle('PATCH', '/api/probe/messages/' . $sentMessageId . '/read', $headers);
    $test->assertEquals(404, $senderRead->status, 'only the recipient can mark a probe message read');
    $readMessage = $kernel->handle('PATCH', '/api/probe/messages/' . $sentMessageId . '/read', $stationaryHeaders);
    $test->assertEquals(200, $readMessage->status, 'PATCH /api/probe/messages/{messageId}/read marks a message read');
    $test->assertEquals('read', $readMessage->body['message']['status'] ?? null, 'read probe message exposes read status');
    $test->assert(is_string($readMessage->body['message']['readAt'] ?? null), 'read probe message exposes read timestamp');
    $senderSentAfterRead = $kernel->handle('GET', '/api/probe/messages/sent', $headers);
    $test->assert(!array_key_exists('status', $senderSentAfterRead->body['messages'][0] ?? []), 'sent message list still hides read status after recipient reads it');
    $test->assert(!array_key_exists('readAt', $senderSentAfterRead->body['messages'][0] ?? []), 'sent message list still hides read timestamp after recipient reads it');
    $test->assert(!array_key_exists('updatedAt', $senderSentAfterRead->body['messages'][0] ?? []), 'sent message list still hides read-driven update timestamp after recipient reads it');

    for ($i = 1; $i <= 55; $i++) {
        $messages->create($createdProbe->id, $stationaryNeighborProbe->id, $createdProbe->currentSector, 'Archive message ' . $i);
    }

    $defaultMessagePage = $kernel->handle('GET', '/api/probe/messages', $stationaryHeaders);
    $test->assertEquals(200, $defaultMessagePage->status, 'GET /api/probe/messages returns the default message page');
    $test->assertEquals(50, count($defaultMessagePage->body['messages'] ?? []), 'GET /api/probe/messages returns at most the 50 latest messages by default');
    $test->assertEquals(50, $defaultMessagePage->body['pagination']['limit'] ?? null, 'default message page exposes its limit');
    $test->assertEquals(0, $defaultMessagePage->body['pagination']['offset'] ?? null, 'default message page starts at offset zero');
    $test->assertEquals(56, $defaultMessagePage->body['pagination']['total'] ?? null, 'default message page exposes the total available messages');
    $test->assertEquals(true, $defaultMessagePage->body['pagination']['hasMore'] ?? null, 'default message page reports older messages');
    $test->assertEquals('Archive message 55', $defaultMessagePage->body['messages'][0]['body'] ?? null, 'default message page is sorted newest first');

    $olderMessagePage = $kernel->handle('GET', '/api/probe/messages?limit=10&offset=50', $stationaryHeaders);
    $olderMessageBodies = array_map(static fn(array $message): string => (string) ($message['body'] ?? ''), $olderMessagePage->body['messages'] ?? []);
    $test->assertEquals(200, $olderMessagePage->status, 'GET /api/probe/messages accepts limit and offset query parameters');
    $test->assertEquals(10, $olderMessagePage->body['pagination']['limit'] ?? null, 'older message page exposes the requested limit');
    $test->assertEquals(50, $olderMessagePage->body['pagination']['offset'] ?? null, 'older message page exposes the requested offset');
    $test->assertEquals(6, count($olderMessageBodies), 'older message page returns remaining messages after the offset');
    $test->assertEquals(false, $olderMessagePage->body['pagination']['hasMore'] ?? null, 'older message page reports the end of the history');
    $test->assert(in_array('Signal de bienvenue', $olderMessageBodies, true), 'older message page can reach messages beyond the first 50');

    $invalidLimitMessagePage = $kernel->handle('GET', '/api/probe/messages?limit=0', $stationaryHeaders);
    $test->assertEquals(400, $invalidLimitMessagePage->status, 'GET /api/probe/messages rejects a zero limit');
    $tooLargeLimitMessagePage = $kernel->handle('GET', '/api/probe/messages?limit=201', $stationaryHeaders);
    $test->assertEquals(400, $tooLargeLimitMessagePage->status, 'GET /api/probe/messages rejects a limit over 200');
    $invalidOffsetMessagePage = $kernel->handle('GET', '/api/probe/messages?offset=-1', $stationaryHeaders);
    $test->assertEquals(400, $invalidOffsetMessagePage->status, 'GET /api/probe/messages rejects a negative offset');

    $defaultSentMessagePage = $kernel->handle('GET', '/api/probe/messages/sent', $headers);
    $test->assertEquals(200, $defaultSentMessagePage->status, 'GET /api/probe/messages/sent returns the default sent message page');
    $test->assertEquals(50, count($defaultSentMessagePage->body['messages'] ?? []), 'GET /api/probe/messages/sent returns at most the 50 latest sent messages by default');
    $test->assertEquals(57, $defaultSentMessagePage->body['pagination']['total'] ?? null, 'default sent message page exposes the total available sent messages');
    $test->assertEquals('Archive message 55', $defaultSentMessagePage->body['messages'][0]['body'] ?? null, 'default sent message page is sorted newest first');
    $test->assert(!array_key_exists('status', $defaultSentMessagePage->body['messages'][0] ?? []), 'default sent message page does not expose read status');
    $test->assert(!array_key_exists('updatedAt', $defaultSentMessagePage->body['messages'][0] ?? []), 'default sent message page does not expose update timestamp');
    $olderSentMessagePage = $kernel->handle('GET', '/api/probe/messages/sent?limit=10&offset=50', $headers);
    $olderSentBodies = array_map(static fn(array $message): string => (string) ($message['body'] ?? ''), $olderSentMessagePage->body['messages'] ?? []);
    $test->assertEquals(200, $olderSentMessagePage->status, 'GET /api/probe/messages/sent accepts limit and offset query parameters');
    $test->assertEquals(7, count($olderSentBodies), 'older sent message page returns remaining messages after the offset');
    $test->assert(in_array('Signal de bienvenue', $olderSentBodies, true), 'older sent message page can reach messages beyond the first 50');
    $invalidSentLimitMessagePage = $kernel->handle('GET', '/api/probe/messages/sent?limit=0', $headers);
    $test->assertEquals(400, $invalidSentLimitMessagePage->status, 'GET /api/probe/messages/sent rejects a zero limit');
}

$inventoryItems = $sector->body['inventory']['items'] ?? [];
$printer = $inventoryItems[0] ?? null;
$test->assertEquals('atomic_3d_printer', $printer['type'] ?? null, 'default inventory starts with an atomic printer');
$test->assertEquals('Imprimante atomique', $printer['name'] ?? null, 'default inventory displays the renamed atomic printer');
$test->assertEquals(0.3, $printer['containerSpace'] ?? null, 'atomic printer occupies 0.3 containers');

$mannyItems = array_values(array_filter($inventoryItems, static fn(array $item): bool => ($item['type'] ?? null) === 'manny'));
$test->assertEquals(4, count($mannyItems), 'default inventory contains four mannies');
$test->assertEquals(0.05, $mannyItems[0]['containerSpace'] ?? null, 'each manny occupies 0.05 containers');
$defaultMannyCargo = $mannyItems[0]['cargo'] ?? [];
$test->assertEquals(0.05, $defaultMannyCargo['capacity'] ?? null, 'each Manny can carry 0.05 containers of mined resources');
$test->assertEquals(0.0, $defaultMannyCargo['ice'] ?? null, 'default Manny cargo exposes ice');
$test->assertEquals(0.0, $defaultMannyCargo['organicCompounds'] ?? null, 'default Manny cargo exposes organic compounds');
$test->assert(!array_key_exists('other', $defaultMannyCargo), 'default Manny cargo no longer exposes generic other');
$resourceStocks = $sector->body['inventory']['resourceStocks'] ?? [];
$test->assert(is_string($resourceStocks[0]['id'] ?? null), 'resource stocks expose stable inventory ids for jettison orders');
$resourceStockTypes = array_column($resourceStocks, 'type');
$test->assertEquals(['metals', 'ice', 'carbon_compounds'], $resourceStockTypes, 'resource stocks expose metals, ice and organic compounds separately');

$printerTask = $kernel->handle('GET', '/api/probe/inventory/' . rawurlencode((string) ($printer['id'] ?? 'missing')), $headers);
$test->assertEquals(200, $printerTask->status, 'inventory item id endpoint returns printer task state');
$test->assert(array_key_exists('currentTask', $printerTask->body['item'] ?? []) && $printerTask->body['item']['currentTask'] === null, 'default printer has no current task');
$test->assertEquals(0.0, $printerTask->body['item']['taskProgressPercent'] ?? null, 'default printer task progress is zero');

$mannyTask = $kernel->handle('GET', '/api/probe/inventory/' . rawurlencode((string) ($mannyItems[0]['id'] ?? 'missing')), $headers);
$test->assertEquals(200, $mannyTask->status, 'inventory item id endpoint returns manny task state');
$test->assert(array_key_exists('currentTask', $mannyTask->body['item'] ?? []) && $mannyTask->body['item']['currentTask'] === null, 'default manny has no current task');
$test->assertEquals(0.0, $mannyTask->body['item']['taskProgressPercent'] ?? null, 'default manny task progress is zero');

$missingItem = $kernel->handle('GET', '/api/probe/inventory/missing-item', $headers);
$test->assertEquals(404, $missingItem->status, 'unknown inventory item id returns 404');

$mannyList = $kernel->handle('GET', '/api/probe/mannies', $headers);
$test->assertEquals(200, $mannyList->status, 'GET /api/probe/mannies returns persisted Mannies');
$test->assertEquals(4, count($mannyList->body['mannies'] ?? []), 'new probe starts with four persisted Mannies');
$firstMannyId = (string) ($mannyList->body['mannies'][0]['id'] ?? '');
$secondMannyId = (string) ($mannyList->body['mannies'][1]['id'] ?? '');
$thirdMannyId = (string) ($mannyList->body['mannies'][2]['id'] ?? '');
$fourthMannyId = (string) ($mannyList->body['mannies'][3]['id'] ?? '');
$test->assert(str_starts_with($firstMannyId, 'mny_'), 'Manny public API id is a stable generated uid');
$test->assertEquals('manny-1', $mannyList->body['mannies'][0]['name'] ?? null, 'default Manny names are player-facing names');
$test->assert(array_key_exists('taskEstimatedEndTime', $mannyList->body['mannies'][0] ?? []) && $mannyList->body['mannies'][0]['taskEstimatedEndTime'] === null, 'idle Manny exposes a null task estimated end time');

$renameManny = $kernel->handle('PATCH', '/api/probe/mannies/' . rawurlencode($firstMannyId), $headers, json_encode(['name' => 'atelier'], JSON_THROW_ON_ERROR));
$test->assertEquals(200, $renameManny->status, 'PATCH /api/probe/mannies/{id} renames a Manny');
$test->assertEquals('atelier', $renameManny->body['manny']['name'] ?? null, 'renamed Manny response exposes the new name');
$duplicateManny = $kernel->handle('PATCH', '/api/probe/mannies/' . rawurlencode($firstMannyId), $headers, json_encode(['name' => 'manny-2'], JSON_THROW_ON_ERROR));
$test->assertEquals(409, $duplicateManny->status, 'Manny names must remain unique per probe');

if ($createdProbe !== null) {
    $pdo->prepare('UPDATE neumann_probes SET integrity_percent = 95 WHERE id = :id')->execute(['id' => $createdProbe->id]);
    $createdProbe = setProbeTestStoredResources($storage, $storageContainers, $probes, $createdProbe, ['metals' => 0.05]);
    $repairManny = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($firstMannyId) . '/repair', $headers, json_encode(['integrityPercent' => 2], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $repairManny->status, 'POST /api/probe/mannies/{id}/repair starts a real-time repair task');
    $test->assertEquals('repair', $repairManny->body['manny']['currentTask'] ?? null, 'repair task is exposed on Manny');
    $test->assert(is_string($repairManny->body['manny']['taskEstimatedEndTime'] ?? null), 'active repair exposes a task estimated end time');
    $test->assertEquals(0.03, $probes->findByPlayerId($player->id)?->metalsStock, 'repair consumes 0.01 containers of metals per integrity percent');
    $test->assertEquals(2, $repairManny->body['manny']['task']['integrityPercent'] ?? null, 'repair task stores planned integrity restoration');
    $repairRow = $pdo->prepare('SELECT id FROM mannies WHERE uid = :uid');
    $repairRow->execute(['uid' => $firstMannyId]);
    $repairMannyDbId = (int) $repairRow->fetchColumn();
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $repairMannyDbId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $kernel->handle('GET', '/api/probe/mannies', $headers);
    $repairedProbe = $probes->findByPlayerId($player->id);
    $test->assertEquals(97.0, $repairedProbe?->integrityPercent, 'completed Manny repair restores probe integrity');

    $sectorRepository->save(new SectorContent($createdProbe->currentSector, [
        new Asteroid('mine-rock', null, 'iron', ['iron', 'nickel'], 'small', 0.000001, 0.001),
        new Asteroid('large-asteroid', null, 'iron', ['iron', 'nickel'], 'large', 0.019, 0.12),
        new Star('unit-star', null, 'G', 1.0, 5778, 1.0, 1.0),
        new Planet('current-habitable-planet', null, 'rocky', 1.0, 1.0, true, 0.72, ['silicates']),
    ]));
    $mineableSector = $kernel->handle('GET', '/api/probe/sector', $headers);
    $test->assertEquals('mine-rock', $mineableSector->body['sector']['objects'][0]['id'] ?? null, 'sector observation exposes object ids for Manny mining orders');
    $test->assertEquals(true, $mineableSector->body['sector']['objects'][0]['mannyMineable'] ?? null, 'small asteroids are marked as Manny-mineable');
    $test->assertEquals(true, $mineableSector->body['sector']['objects'][1]['mannyMineable'] ?? null, 'all asteroids are marked as Manny-mineable');
    $test->assertEquals(['metals'], $mineableSector->body['sector']['objects'][0]['resourceTypes'] ?? null, 'sector observation exposes mineable resource categories');
    $test->assertEquals(1.0, $mineableSector->body['sector']['objects'][0]['resourceComposition']['metals'] ?? null, 'sector observation exposes resource composition shares');
    $test->assertEquals('earth_mass', $mineableSector->body['sector']['objects'][0]['massUnit'] ?? null, 'asteroid sector object exposes earth mass unit');
    $test->assertEquals('earth_radius', $mineableSector->body['sector']['objects'][0]['radiusUnit'] ?? null, 'asteroid sector object exposes earth radius unit');
    $unitObjectsById = [];
    foreach ($mineableSector->body['sector']['objects'] ?? [] as $unitObject) {
        $unitObjectsById[(string) ($unitObject['id'] ?? '')] = $unitObject;
    }
    $test->assertEquals('solar_mass', $unitObjectsById['unit-star']['massUnit'] ?? null, 'star sector object exposes solar mass unit');
    $test->assertEquals('solar_radius', $unitObjectsById['unit-star']['radiusUnit'] ?? null, 'star sector object exposes solar radius unit');
    $test->assertEquals(0.72, $unitObjectsById['current-habitable-planet']['habitabilityScore'] ?? null, 'current-sector planet observations expose habitability score');

    $visitedPlanetSector = new SectorCoordinates($createdProbe->currentSector->getX() + 8, $createdProbe->currentSector->getY(), $createdProbe->currentSector->getZ());
    $visitedSectors->markVisited($player, $visitedPlanetSector);
    $sectorRepository->save(new SectorContent($visitedPlanetSector, [
        new Planet('visited-habitable-planet', null, 'ocean', 1.1, 1.2, true, 0.81, ['water_ice']),
    ]));
    $visitedPlanetObservation = $kernel->handle('GET', '/api/sector?x=8&y=0&z=0', $headers);
    $test->assertEquals(200, $visitedPlanetObservation->status, 'visited planet sector can be scanned through GET /api/sector');
    $test->assertEquals(0.81, $visitedPlanetObservation->body['sector']['objects'][0]['habitabilityScore'] ?? null, 'visited-sector planet observations expose habitability score');

    $mineManny = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($secondMannyId) . '/mine', $headers, json_encode([
        'objectId' => 'mine-rock',
        'resource' => 'metals',
        'targetAmount' => 0.01,
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $mineManny->status, 'POST /api/probe/mannies/{id}/mine starts a mining task');
    $test->assertEquals('mining', $mineManny->body['manny']['currentTask'] ?? null, 'mining task is exposed on Manny');
    $test->assertEquals('sector', $mineManny->body['manny']['location']['type'] ?? null, 'mining moves the Manny outside the probe');

    $mineRow = $pdo->prepare('SELECT id FROM mannies WHERE uid = :uid');
    $mineRow->execute(['uid' => $secondMannyId]);
    $mineMannyDbId = (int) $mineRow->fetchColumn();
    $pdo->prepare('UPDATE mannies SET task_started_at = :started, task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $mineMannyDbId,
        'started' => gmdate('c', time() - 2200),
        'ended' => gmdate('c', time() - 1),
    ]);
    $kernel->handle('GET', '/api/probe/mannies', $headers);
    $minedProbe = $probes->findByPlayerId($player->id);
    $test->assertEquals(0.04, $minedProbe?->metalsStock, 'completed Manny mining transfers metals to the probe inventory');
    $test->assertEquals('probe', $mannies->findByUidForProbe($createdProbe->id, $secondMannyId)?->locationType, 'completed mining returns the Manny to the probe');
    $depletedSector = $sectorRepository->load($createdProbe->currentSector);
    $depletedAsteroid = $depletedSector->getObjects()[0] ?? null;
    $test->assertEquals(0.99, $depletedAsteroid?->toArray()['resourceAmounts']['metals'] ?? null, 'completed Manny mining subtracts the mined metals from the asteroid');

    $sectorRepository->save(new SectorContent($createdProbe->currentSector, [
        new Asteroid('thin-rock', null, 'iron', ['iron', 'nickel'], 'small', 0.000001, 0.001, null, ['metals' => 0.005]),
    ]));
    $oversizedMine = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($secondMannyId) . '/mine', $headers, json_encode([
        'objectId' => 'thin-rock',
        'resource' => 'metals',
        'targetAmount' => 0.01,
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(422, $oversizedMine->status, 'Manny mining refuses an order larger than the asteroid material reserve');

    $sectorRepository->save(new SectorContent($createdProbe->currentSector, [
        new Asteroid('mixed-rock', null, 'mixed', ['iron', 'water_ice', 'carbon'], 'small', 0.000001, 0.001),
    ]));
    $mixedMine = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($thirdMannyId) . '/mine', $headers, json_encode([
        'objectId' => 'mixed-rock',
        'resources' => ['metals', 'ice', 'carbon_compounds'],
        'targetAmount' => 0.03,
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $mixedMine->status, 'POST /api/probe/mannies/{id}/mine accepts multiple resource categories');
    $test->assertEquals(0.3333, $mixedMine->body['manny']['task']['resourceProfile']['metals'] ?? null, 'multi-resource mining keeps the metals composition share');
    $test->assertEquals(0.3333, $mixedMine->body['manny']['task']['resourceProfile']['ice'] ?? null, 'multi-resource mining keeps the ice composition share');
    $test->assertEquals(0.3334, $mixedMine->body['manny']['task']['resourceProfile']['carbon_compounds'] ?? null, 'multi-resource mining assigns the remaining composition share');
    $test->assertEquals('mixed-rock', $mixedMine->body['manny']['task']['target']['id'] ?? null, 'mining task exposes its target details');
    $test->assertEquals('mixed', $mixedMine->body['manny']['task']['target']['composition'] ?? null, 'mining task exposes asteroid composition');

    $mixedRow = $pdo->prepare('SELECT id FROM mannies WHERE uid = :uid');
    $mixedRow->execute(['uid' => $thirdMannyId]);
    $mixedMannyDbId = (int) $mixedRow->fetchColumn();
    $pdo->prepare('UPDATE mannies SET task_started_at = :started WHERE id = :id')->execute([
        'id' => $mixedMannyDbId,
        'started' => gmdate('c', time() - 1500),
    ]);
    $haulingMannies = $kernel->handle('GET', '/api/probe/mannies', $headers);
    $haulingMixedManny = array_values(array_filter(
        $haulingMannies->body['mannies'] ?? [],
        static fn(array $manny): bool => ($manny['id'] ?? null) === $thirdMannyId,
    ))[0] ?? null;
    $haulingCargo = $haulingMixedManny['cargo'] ?? [];
    $test->assertEquals(0.0067, $haulingCargo['metals'] ?? null, 'active multi-resource mining exposes Manny metals cargo');
    $test->assertEquals(0.0067, $haulingCargo['ice'] ?? null, 'active multi-resource mining exposes Manny ice cargo');
    $test->assertEquals(0.0066, $haulingCargo['organicCompounds'] ?? null, 'active multi-resource mining exposes Manny organic-compound cargo');
    $test->assert(!array_key_exists('other', $haulingCargo), 'active Manny cargo no longer exposes generic other');
    $pdo->prepare('UPDATE mannies SET task_started_at = :started, task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $mixedMannyDbId,
        'started' => gmdate('c', time() - 3000),
        'ended' => gmdate('c', time() - 1),
    ]);
    $kernel->handle('GET', '/api/probe/mannies', $headers);
    $mixedProbe = $probes->findByPlayerId($player->id);
    $test->assertEquals(0.05, $mixedProbe?->metalsStock, 'completed multi-resource mining transfers the metals share');
    $test->assertEquals(0.01, $mixedProbe?->iceStock, 'completed multi-resource mining transfers the ice share');
    $test->assertEquals(0.01, $mixedProbe?->organicCompoundsStock, 'completed multi-resource mining transfers the organic-compound share');
    $mixedSector = $sectorRepository->load($createdProbe->currentSector);
    $mixedAsteroid = $mixedSector->getObjects()[0] ?? null;
    $test->assertEquals(0.3233, $mixedAsteroid?->toArray()['resourceAmounts']['metals'] ?? null, 'multi-resource mining subtracts the metals share from the asteroid');
    $test->assertEquals(0.3233, $mixedAsteroid?->toArray()['resourceAmounts']['ice'] ?? null, 'multi-resource mining subtracts the ice share from the asteroid');
    $test->assertEquals(0.3234, $mixedAsteroid?->toArray()['resourceAmounts']['carbon_compounds'] ?? null, 'multi-resource mining subtracts the carbon-compound share from the asteroid');

    $sectorRepository->save(new SectorContent($createdProbe->currentSector, [
        new Asteroid('haul-rock', null, 'iron', ['iron', 'nickel'], 'small', 0.000001, 0.001),
    ]));
    $multiTripMine = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($secondMannyId) . '/mine', $headers, json_encode([
        'objectId' => 'haul-rock',
        'resource' => 'metals',
        'targetAmount' => 0.06,
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $multiTripMine->status, 'Manny mining accepts an order larger than one cargo trip');
    $pdo->prepare('UPDATE mannies SET task_started_at = :started WHERE id = :id')->execute([
        'id' => $mineMannyDbId,
        'started' => gmdate('c', time() - 3500),
    ]);
    $haulingMannies = $kernel->handle('GET', '/api/probe/mannies', $headers);
    $haulingSecondManny = array_values(array_filter(
        $haulingMannies->body['mannies'] ?? [],
        static fn(array $manny): bool => ($manny['id'] ?? null) === $secondMannyId,
    ))[0] ?? null;
    $test->assertEquals(2, $haulingSecondManny['task']['tripIndex'] ?? null, 'Manny mining starts a second trip after carrying 0.05 containers');
    $test->assertEquals(0.05, $haulingSecondManny['task']['depositedAmount'] ?? null, 'Manny deposits one 0.05-container cargo before the next trip');
    $test->assertEquals(0.0, $haulingSecondManny['cargo']['metals'] ?? null, 'Manny begins the second trip without cargo');
    $pdo->prepare('UPDATE mannies SET task_started_at = :started, task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $mineMannyDbId,
        'started' => gmdate('c', time() - 6000),
        'ended' => gmdate('c', time() - 1),
    ]);
    $kernel->handle('GET', '/api/probe/mannies', $headers);
    $test->assertEquals('probe', $mannies->findByUidForProbe($createdProbe->id, $secondMannyId)?->locationType, 'Manny returns to the probe after completing all mining trips');

    $sectorRepository->save(new SectorContent($createdProbe->currentSector, [
        new Asteroid('recall-rock', null, 'iron', ['iron', 'nickel'], 'small', 0.000001, 0.001),
    ]));
    $quickRecallMine = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($secondMannyId) . '/mine', $headers, json_encode([
        'objectId' => 'recall-rock',
        'resource' => 'metals',
        'targetAmount' => 0.05,
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $quickRecallMine->status, 'Manny can start a mining task before quick recall');
    $quickRecallOriginalStart = gmdate('c', time() - 300);
    $pdo->prepare('UPDATE mannies SET task_started_at = :started WHERE uid = :uid')->execute([
        'uid' => $secondMannyId,
        'started' => $quickRecallOriginalStart,
    ]);
    $quickRecall = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($secondMannyId) . '/recall', $headers, json_encode([], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $quickRecall->status, 'POST /api/probe/mannies/{id}/recall accepts a Manny before it reaches the mining target');
    $test->assertEquals('returning', $quickRecall->body['manny']['currentTask'] ?? null, 'quick recalled Manny starts returning');
    $quickRecallReturning = $mannies->findByUidForProbe($createdProbe->id, $secondMannyId);
    $quickRecallDuration = (new DateTimeImmutable((string) $quickRecallReturning?->taskEndsAt))->getTimestamp()
        - (new DateTimeImmutable((string) $quickRecallReturning?->taskStartedAt))->getTimestamp();
    $quickRecallElapsed = (new DateTimeImmutable((string) $quickRecallReturning?->taskStartedAt))->getTimestamp()
        - (new DateTimeImmutable($quickRecallOriginalStart))->getTimestamp();
    $test->assertEquals($quickRecallElapsed, $quickRecallDuration, 'recall duration matches elapsed outbound time when below Manny travel time');
    $test->assert($quickRecallDuration < 900, 'quick recall duration is shorter than the default Manny travel time');
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE uid = :uid')->execute([
        'uid' => $secondMannyId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $kernel->handle('GET', '/api/probe/mannies', $headers);
    $test->assertEquals('probe', $mannies->findByUidForProbe($createdProbe->id, $secondMannyId)?->locationType, 'quick recalled Manny returns to the probe after its shortened return');

    $pdo->prepare('UPDATE neumann_probes SET integrity_percent = 96 WHERE id = :id')->execute(['id' => $createdProbe->id]);
    $createdProbe = setProbeTestStoredResources($storage, $storageContainers, $probes, $createdProbe, ['metals' => 0.2]);
    $cancelRepair = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($fourthMannyId) . '/repair', $headers, json_encode(['integrityPercent' => 1], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $cancelRepair->status, 'fourth Manny can start a repair before cancellation');
    $cancelRepair = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($fourthMannyId) . '/recall', $headers, json_encode([], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $cancelRepair->status, 'POST /api/probe/mannies/{id}/recall cancels an active repair task');
    $test->assertEquals(null, $cancelRepair->body['manny']['currentTask'] ?? null, 'cancelled repair returns the Manny to idle');
    $test->assertEquals(0.2, $probes->findByPlayerId($player->id)?->metalsStock, 'cancelled repair refunds committed metals when capacity is available');

    $craftManny = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($firstMannyId) . '/craft', $headers, json_encode([
        'recipe' => 'waypoint_bookmark',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $craftManny->status, 'POST /api/probe/mannies/{id}/craft starts a crafting task');
    $test->assertEquals('crafting', $craftManny->body['manny']['currentTask'] ?? null, 'crafting task is exposed on Manny');
    $test->assertEquals('waypoint_bookmark', $craftManny->body['manny']['task']['recipe'] ?? null, 'crafting task stores its recipe');
    $test->assertEquals(0.19, $probes->findByPlayerId($player->id)?->metalsStock, 'waypoint bookmark crafting consumes 0.01 metal containers');

    $craftRow = $pdo->prepare('SELECT id FROM mannies WHERE uid = :uid');
    $craftRow->execute(['uid' => $firstMannyId]);
    $craftMannyDbId = (int) $craftRow->fetchColumn();
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $craftMannyDbId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $kernel->handle('GET', '/api/probe/mannies', $headers);
    $craftedProbe = $kernel->handle('GET', '/api/probe', $headers);
    $craftedItems = array_values(array_filter(
        $craftedProbe->body['probe']['inventory']['items'] ?? [],
        static fn(array $item): bool => ($item['type'] ?? null) === 'waypoint_bookmark',
    ));
    $test->assertEquals(1, count($craftedItems), 'completed crafting adds a waypoint bookmark to inventory');
    $test->assertEquals(0.01, $craftedItems[0]['containerSpace'] ?? null, 'waypoint bookmark occupies 0.01 containers');

    $sectorRepository->save(new SectorContent($createdProbe->currentSector, [
        new Asteroid('bookmark-rock', null, 'iron', ['iron', 'nickel'], 'small', 0.000001, 0.001),
    ]));
    $installBookmark = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($firstMannyId) . '/install-bookmark', $headers, json_encode([
        'objectId' => 'bookmark-rock',
        'name' => 'Balise test',
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $installBookmark->status, 'POST /api/probe/mannies/{id}/install-bookmark starts a bookmark installation task');
    $test->assertEquals('installing_waypoint_bookmark', $installBookmark->body['manny']['currentTask'] ?? null, 'bookmark installation task is exposed on Manny');
    $test->assertEquals(10, $installBookmark->body['manny']['task']['durationSeconds'] ?? null, 'bookmark installation takes ten seconds');
    $test->assertEquals('Balise test', $installBookmark->body['manny']['task']['name'] ?? null, 'bookmark installation stores the requested label');
    $bookmarkOrderProbe = $kernel->handle('GET', '/api/probe', $headers);
    $remainingBookmarks = array_values(array_filter(
        $bookmarkOrderProbe->body['probe']['inventory']['items'] ?? [],
        static fn(array $item): bool => ($item['type'] ?? null) === 'waypoint_bookmark',
    ));
    $test->assertEquals(0, count($remainingBookmarks), 'bookmark installation reserves the waypoint bookmark item when the Manny starts');
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE id = :id')->execute([
        'id' => $craftMannyDbId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $completedBookmarkMannies = $kernel->handle('GET', '/api/probe/mannies', $headers);
    $completedBookmarkManny = array_values(array_filter(
        $completedBookmarkMannies->body['mannies'] ?? [],
        static fn(array $manny): bool => ($manny['id'] ?? null) === $firstMannyId,
    ))[0] ?? null;
    $test->assertEquals(null, $completedBookmarkManny['currentTask'] ?? null, 'completed bookmark installation returns the Manny to idle');
    $test->assertEquals('success', $completedBookmarkManny['task']['result'] ?? null, 'completed bookmark installation records a success result');
    $bookmarkedSector = $sectorRepository->load($createdProbe->currentSector);
    $bookmarkedObject = $bookmarkedSector->findObjectById('bookmark-rock');
    $bookmarkedData = $bookmarkedObject?->toArray() ?? [];
    $test->assertEquals('Balise test', $bookmarkedObject?->getName(), 'bookmark installation persists the celestial object name in the sector file');
    $test->assert(isset($bookmarkedData['waypointBookmarks'][0]['createdAt']), 'bookmark installation persists a timestamped history entry');
    $test->assertEquals('Remi', $bookmarkedData['waypointBookmarks'][0]['playerName'] ?? null, 'bookmark history stores the player display name');
    $bookmarkObservation = $kernel->handle('GET', '/api/probe/sector', $headers);
    $test->assertEquals('Balise test', $bookmarkObservation->body['sector']['objects'][0]['waypointBookmarks'][0]['name'] ?? null, 'sector observation exposes bookmark history');
    $bookmarkStats = (new UniverseStatsService($pdo, $universePath))->collect();
    $test->assertEquals(1, $bookmarkStats['metrics']['waypointBookmarksInstalled'] ?? null, 'public stats count installed waypoint bookmarks');
    $topWaypointPlayers = $bookmarkStats['metrics']['topWaypointPlayers'] ?? [];
    $test->assertEquals('Remi', $topWaypointPlayers[0]['playerName'] ?? null, 'public stats waypoint podium ranks players by installed bookmarks');
    $test->assertEquals(1, $topWaypointPlayers[0]['waypointBookmarks'] ?? null, 'public stats waypoint podium exposes bookmark counts');

    $fourthRow = $pdo->prepare('SELECT id FROM mannies WHERE uid = :uid');
    $fourthRow->execute(['uid' => $fourthMannyId]);
    $fourthMannyDbId = (int) $fourthRow->fetchColumn();
    $storageContainers->clearResourcesForProbe($createdProbe->id);
    $createdCoreContainer = $storageContainers->findByUidForProbe($createdProbe->id, 'probe-core');
    if ($createdCoreContainer !== null) {
        $storageContainers->setResourceAmount($createdCoreContainer->id, 'metals', 0.45);
    }
    $createdProbe = $probes->findById($createdProbe->id) ?? $createdProbe;
    $storage->ensureProbeStorage($createdProbe);
    $pdo->prepare(
        'UPDATE mannies
         SET storage_container_id = NULL,
             location_type = :location_type,
             sector_x = :sector_x,
             sector_y = :sector_y,
             sector_z = :sector_z,
             current_task = :current_task,
             task_started_at = :started,
             task_ends_at = :ended,
             task_payload_json = :payload,
             cargo_deuterium = 0,
             cargo_metals = 0.45,
             cargo_ice = 0,
             cargo_organic_compounds = 0
         WHERE id = :id'
    )->execute([
        'id' => $fourthMannyDbId,
        'location_type' => 'sector',
        'sector_x' => $createdProbe->currentSector->getX(),
        'sector_y' => $createdProbe->currentSector->getY(),
        'sector_z' => $createdProbe->currentSector->getZ(),
        'current_task' => 'returning',
        'started' => gmdate('c', time() - 1800),
        'ended' => gmdate('c', time() - 1),
        'payload' => json_encode(['reason' => 'test_return'], JSON_THROW_ON_ERROR),
    ]);
    $waitingMannies = $kernel->handle('GET', '/api/probe/mannies', $headers);
    $waitingFourth = array_values(array_filter(
        $waitingMannies->body['mannies'] ?? [],
        static fn(array $manny): bool => ($manny['id'] ?? null) === $fourthMannyId,
    ))[0] ?? null;
    $test->assertEquals('waiting_for_space', $waitingFourth['currentTask'] ?? null, 'returning Manny waits outside when the probe has no storage slot');
    $test->assertEquals('sector', $waitingFourth['location']['type'] ?? null, 'waiting Manny remains in the sector');

    $dropMannyCargo = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($fourthMannyId) . '/drop-manny-cargo', $headers, json_encode([], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $dropMannyCargo->status, 'POST /api/probe/mannies/{id}/drop-manny-cargo accepts a waiting Manny cargo drop');
    $test->assertEquals('probe', $dropMannyCargo->body['manny']['location']['type'] ?? null, 'dropping cargo lets a waiting Manny retry docking immediately');
    $test->assertEquals(null, $dropMannyCargo->body['manny']['currentTask'] ?? null, 'Manny is idle after dropping cargo and docking');
    $test->assertEquals(0.0, $dropMannyCargo->body['manny']['cargo']['metals'] ?? null, 'dropped Manny cargo is cleared');
    $test->assertEquals(0.45, $probes->findByPlayerId($player->id)?->metalsStock, 'dropped Manny cargo is not transferred into probe storage');

    $storageContainers->clearResourcesForProbe($createdProbe->id);
    if ($createdCoreContainer !== null) {
        $storageContainers->setResourceAmount($createdCoreContainer->id, 'metals', 0.55);
    }
    $createdProbe = $probes->findById($createdProbe->id) ?? $createdProbe;
    $storage->ensureProbeStorage($createdProbe);
    $pdo->prepare(
        'UPDATE mannies
         SET storage_container_id = NULL,
             location_type = :location_type,
             sector_x = :sector_x,
             sector_y = :sector_y,
             sector_z = :sector_z,
             current_task = :current_task,
             task_started_at = :started,
             task_ends_at = :ended,
             task_payload_json = :payload,
             cargo_deuterium = 0,
             cargo_metals = 0,
             cargo_ice = 0,
             cargo_organic_compounds = 0
         WHERE id = :id'
    )->execute([
        'id' => $fourthMannyDbId,
        'location_type' => 'sector',
        'sector_x' => $createdProbe->currentSector->getX(),
        'sector_y' => $createdProbe->currentSector->getY(),
        'sector_z' => $createdProbe->currentSector->getZ(),
        'current_task' => 'returning',
        'started' => gmdate('c', time() - 1800),
        'ended' => gmdate('c', time() - 1),
        'payload' => json_encode(['reason' => 'test_return'], JSON_THROW_ON_ERROR),
    ]);
    $waitingMannies = $kernel->handle('GET', '/api/probe/mannies', $headers);
    $waitingFourth = array_values(array_filter(
        $waitingMannies->body['mannies'] ?? [],
        static fn(array $manny): bool => ($manny['id'] ?? null) === $fourthMannyId,
    ))[0] ?? null;
    $test->assertEquals('waiting_for_space', $waitingFourth['currentTask'] ?? null, 'returning Manny can still wait outside when only its storage slot is missing');

    $jettisonMetals = $kernel->handle('POST', '/api/probe/inventory/probe-' . $createdProbe->id . '-stock-metals/jettison', $headers, json_encode([
        'amount' => 0.05,
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(200, $jettisonMetals->status, 'POST /api/probe/inventory/{itemId}/jettison discards stored resources');
    $test->assertEquals(0.5, $probes->findByPlayerId($player->id)?->metalsStock, 'jettisoning metals lowers the probe stock');
    $test->assertEquals('probe', $mannies->findByUidForProbe($createdProbe->id, $fourthMannyId)?->locationType, 'freeing storage lets a waiting Manny enter the probe');
    $test->assertEquals(null, $mannies->findByUidForProbe($createdProbe->id, $fourthMannyId)?->currentTask, 'Manny waiting for storage returns to idle after docking');

    $createdProbe = setProbeTestStoredResources($storage, $storageContainers, $probes, $createdProbe, ['metals' => 0.45]);
    $steelBarIds = [];
    for ($index = 0; $index < 7; $index++) {
        $steelBarIds[] = $items->create(
            $createdProbe->id,
            ProbeItem::TYPE_STEEL_BAR,
            ProbeItem::STEEL_BAR_NAME,
            0.01,
            ['test' => 'drifting-stack'],
        )->uid;
    }
    foreach ($steelBarIds as $steelBarId) {
        $jettisonSteelBar = $kernel->handle('POST', '/api/probe/inventory/' . rawurlencode($steelBarId) . '/jettison', $headers, json_encode([], JSON_THROW_ON_ERROR));
        $test->assertEquals(200, $jettisonSteelBar->status, 'craftable items can be jettisoned into the current sector');
    }
    $driftingObjectId = SectorDriftingItem::objectIdForItemType(ProbeItem::TYPE_STEEL_BAR);
    $driftingSector = $sectorRepository->load($createdProbe->currentSector);
    $driftingSteelBars = $driftingSector->findObjectById($driftingObjectId);
    $test->assertEquals('drifting_item', $driftingSteelBars?->getType()->value, 'jettisoned craftable items are persisted as a drifting sector object');
    $test->assertEquals(7, $driftingSteelBars?->toArray()['quantity'] ?? null, 'drifting craftable items are aggregated by type in the sector');
    $scanWithDriftingItems = $kernel->handle('GET', '/api/probe/sector', $headers);
    $scanDriftingItems = array_values(array_filter(
        $scanWithDriftingItems->body['sector']['objects'] ?? [],
        static fn(array $object): bool => ($object['type'] ?? null) === 'drifting_item'
    ));
    $test->assertEquals(true, $scanDriftingItems[0]['salvageable'] ?? null, 'drifting craftable item stacks are exposed as salvageable');
    $test->assertEquals(7, $scanDriftingItems[0]['quantity'] ?? null, 'current-sector scan exposes drifting item quantity');

    $salvageSteelBars = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($firstMannyId) . '/salvage', $headers, json_encode([
        'objectId' => $driftingObjectId,
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $salvageSteelBars->status, 'Manny can start recovering a drifting craftable item stack');
    $test->assertEquals(5, $salvageSteelBars->body['manny']['task']['reservedItem']['quantity'] ?? null, 'Manny recovery reserves at most one cargo trip of craftable items');
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE uid = :uid')->execute([
        'uid' => $firstMannyId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $afterSteelBarSalvage = $kernel->handle('GET', '/api/probe/mannies', $headers);
    $steelBarInventory = $kernel->handle('GET', '/api/probe', $headers);
    $recoveredSteelBars = array_values(array_filter(
        $steelBarInventory->body['probe']['inventory']['items'] ?? [],
        static fn(array $item): bool => ($item['type'] ?? null) === ProbeItem::TYPE_STEEL_BAR,
    ));
    $test->assertEquals(5, count($recoveredSteelBars), 'completed drifting-item recovery creates only the Manny cargo capacity worth of items');
    $driftingSectorAfterRecovery = $sectorRepository->load($createdProbe->currentSector);
    $remainingDriftingSteelBars = $driftingSectorAfterRecovery->findObjectById($driftingObjectId);
    $test->assertEquals(2, $remainingDriftingSteelBars?->toArray()['quantity'] ?? null, 'unrecovered drifting item quantity remains in the sector');
    $steelBarSalvageActor = array_values(array_filter(
        $afterSteelBarSalvage->body['mannies'] ?? [],
        static fn(array $manny): bool => ($manny['id'] ?? null) === $firstMannyId,
    ))[0] ?? null;
    $test->assertEquals(null, $steelBarSalvageActor['currentTask'] ?? null, 'Manny returns to idle after recovering drifting craftable items');
    $test->assertEquals('success', $steelBarSalvageActor['task']['result'] ?? null, 'drifting craftable item recovery records success');

    $beforeMannyJettisonProbe = $kernel->handle('GET', '/api/probe', $headers);
    $beforeMannyJettisonFreeCapacity = (float) ($beforeMannyJettisonProbe->body['probe']['inventory']['freeCapacity'] ?? 0.0);
    $jettisonManny = $kernel->handle('POST', '/api/probe/inventory/' . rawurlencode($fourthMannyId) . '/jettison', $headers, json_encode([], JSON_THROW_ON_ERROR));
    $test->assertEquals(200, $jettisonManny->status, 'POST /api/probe/inventory/{itemId}/jettison can eject an idle onboard Manny');
    $jettisonedManny = $mannies->findByUid($fourthMannyId);
    $test->assertEquals(null, $jettisonedManny?->probeId, 'jettisoned Manny has no owner probe link in the database');
    $test->assertEquals('sector', $jettisonedManny?->locationType, 'jettisoned Manny is moved outside the probe');
    $test->assertEquals(round($beforeMannyJettisonFreeCapacity + 0.05, 4), $jettisonManny->body['inventory']['freeCapacity'] ?? null, 'jettisoning an onboard Manny frees its storage slot');
    $jettisonedSector = $sectorRepository->load($createdProbe->currentSector);
    $jettisonedSectorManny = $jettisonedSector->findObjectById(SectorManny::objectIdForUid($fourthMannyId));
    $test->assertEquals('manny', $jettisonedSectorManny?->getType()->value, 'jettisoned Manny is registered as a sector object');
    $test->assertEquals(SectorManny::STATE_ABANDONED, $jettisonedSectorManny?->toArray()['state'] ?? null, 'jettisoned sector Manny is marked abandoned');
    $scanWithAbandonedManny = $kernel->handle('GET', '/api/probe/sector', $headers);
    $scanMannyObjects = array_values(array_filter(
        $scanWithAbandonedManny->body['sector']['objects'] ?? [],
        static fn(array $object): bool => ($object['type'] ?? null) === 'manny'
    ));
    $test->assertEquals(SectorManny::STATE_ABANDONED, $scanMannyObjects[0]['mannyState'] ?? null, 'current-sector scan exposes abandoned Mannys');
    $test->assertEquals(true, $scanMannyObjects[0]['salvageable'] ?? null, 'abandoned Mannys are exposed as salvageable sector objects');

    $salvageManny = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($firstMannyId) . '/salvage', $headers, json_encode([
        'objectId' => SectorManny::objectIdForUid($fourthMannyId),
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $salvageManny->status, 'POST /api/probe/mannies/{id}/salvage starts salvage of an abandoned Manny');
    $test->assertEquals('salvage', $salvageManny->body['manny']['currentTask'] ?? null, 'salvage task is exposed on Manny');
    $test->assertEquals(300, $salvageManny->body['manny']['task']['durationSeconds'] ?? null, 'Manny salvage takes five minutes');

    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE uid = :uid')->execute([
        'uid' => $firstMannyId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $afterSalvageList = $kernel->handle('GET', '/api/probe/mannies', $headers);
    $recoveredManny = $mannies->findByUidForProbe($createdProbe->id, $fourthMannyId);
    $test->assert($recoveredManny !== null, 'salvaged abandoned Manny is attached to the recovering probe');
    $test->assertEquals('sector', $recoveredManny?->locationType, 'salvaged Manny joins the owner list outside the probe');
    $sectorAfterSalvage = $sectorRepository->load($createdProbe->currentSector);
    $test->assertEquals(null, $sectorAfterSalvage->findObjectById(SectorManny::objectIdForUid($fourthMannyId)), 'salvaged Manny disappears from the sector definition');
    $salvageActor = array_values(array_filter(
        $afterSalvageList->body['mannies'] ?? [],
        static fn(array $manny): bool => ($manny['id'] ?? null) === $firstMannyId,
    ))[0] ?? null;
    $test->assertEquals(null, $salvageActor['currentTask'] ?? null, 'salvage actor returns to idle after successful recovery');
    $test->assertEquals('success', $salvageActor['task']['result'] ?? null, 'successful salvage records its result');

    $createdProbe = setProbeTestStoredResources($storage, $storageContainers, $probes, $createdProbe, ['metals' => 0.45]);
    $pdo->prepare(
        'UPDATE mannies
         SET storage_container_id = NULL,
             location_type = :location_type,
             sector_x = :sector_x,
             sector_y = :sector_y,
             sector_z = :sector_z,
             current_task = :current_task,
             task_started_at = :started,
             task_ends_at = NULL,
             task_payload_json = :payload,
             cargo_deuterium = 0,
             cargo_metals = 0,
             cargo_ice = 0,
             cargo_organic_compounds = 0
         WHERE uid = :uid'
    )->execute([
        'uid' => $firstMannyId,
        'location_type' => 'sector',
        'sector_x' => $createdProbe->currentSector->getX(),
        'sector_y' => $createdProbe->currentSector->getY(),
        'sector_z' => $createdProbe->currentSector->getZ(),
        'current_task' => 'waiting_for_space',
        'started' => gmdate('c'),
        'payload' => json_encode([
            'reason' => 'salvage_return',
            'waitingFor' => 'storage_space',
            'salvaged' => [
                'type' => 'manny',
                'id' => $fourthMannyId,
                'name' => $recoveredManny?->name,
            ],
        ], JSON_THROW_ON_ERROR),
    ]);
    $dropSalvagedMannyCargo = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($firstMannyId) . '/drop-manny-cargo', $headers, json_encode([], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $dropSalvagedMannyCargo->status, 'dropping a salvaged Manny cargo is accepted');
    $test->assertEquals('probe', $dropSalvagedMannyCargo->body['manny']['location']['type'] ?? null, 'salvage actor retries docking after dropping Manny cargo');
    $droppedRecoveredManny = $mannies->findByUid($fourthMannyId);
    $test->assertEquals(null, $droppedRecoveredManny?->probeId, 'dropped salvaged Manny becomes unowned again');
    $sectorAfterDroppedMannyCargo = $sectorRepository->load($createdProbe->currentSector);
    $droppedMannyCargoObject = $sectorAfterDroppedMannyCargo->findObjectById(SectorManny::objectIdForUid($fourthMannyId));
    $test->assertEquals(SectorManny::STATE_ABANDONED, $droppedMannyCargoObject?->toArray()['state'] ?? null, 'dropped salvaged Manny is restored as an abandoned sector object');

    $raceManny = $mannies->createForProbe($createdProbe->id, 'race-manny');
    $raceManny->probeId = null;
    $raceManny->locationType = 'sector';
    $raceManny->sector = $createdProbe->currentSector;
    $mannies->save($raceManny);
    $raceSector = $sectorRepository->load($createdProbe->currentSector);
    $raceObject = new SectorManny(
        SectorManny::objectIdForUid($raceManny->uid),
        $raceManny->name,
        $raceManny->uid,
        SectorManny::STATE_ABANDONED,
        $raceManny->cargoArray(),
        'Manny abandoned in open space.',
    );
    if (!$raceSector->replaceObject($raceObject)) {
        $raceSector->addObject($raceObject);
    }
    $sectorRepository->save($raceSector);

    $firstRaceSalvage = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($firstMannyId) . '/salvage', $headers, json_encode([
        'objectId' => SectorManny::objectIdForUid($raceManny->uid),
    ], JSON_THROW_ON_ERROR));
    $secondRaceSalvage = $kernel->handle('POST', '/api/probe/mannies/' . rawurlencode($secondMannyId) . '/salvage', $headers, json_encode([
        'objectId' => SectorManny::objectIdForUid($raceManny->uid),
    ], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $firstRaceSalvage->status, 'first concurrent salvage order can start');
    $test->assertEquals(202, $secondRaceSalvage->status, 'second concurrent salvage order can start before the object is gone');
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE uid = :uid')->execute([
        'uid' => $firstMannyId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $kernel->handle('GET', '/api/probe/mannies', $headers);
    $pdo->prepare('UPDATE mannies SET task_ends_at = :ended WHERE uid = :uid')->execute([
        'uid' => $secondMannyId,
        'ended' => gmdate('c', time() - 1),
    ]);
    $afterFailedRace = $kernel->handle('GET', '/api/probe/mannies', $headers);
    $failedRaceActor = array_values(array_filter(
        $afterFailedRace->body['mannies'] ?? [],
        static fn(array $manny): bool => ($manny['id'] ?? null) === $secondMannyId,
    ))[0] ?? null;
    $test->assertEquals('failed', $failedRaceActor['task']['result'] ?? null, 'salvage fails if another order already recovered the object');
    $test->assertEquals('target_unavailable', $failedRaceActor['task']['failureReason'] ?? null, 'failed salvage exposes the unavailable target reason');

    $pdo->prepare('UPDATE neumann_probes SET integrity_percent = 97 WHERE id = :id')->execute(['id' => $createdProbe->id]);
}

$currentProbe = $probes->findByPlayerId($player->id);
if ($currentProbe !== null) {
    $grid = new SectorGrid();
    $neighbors = $grid->getNeighbors($currentProbe->currentSector);
    $visitedNeighbor = $neighbors[0];
    $visitedSectors->markVisited($player, $visitedNeighbor);
    $visitedRelative = $visitedNeighbor->subtract($player->homeSector);
    $visitedResponse = $kernel->handle('GET', '/api/sector?x=' . $visitedRelative['x'] . '&y=' . $visitedRelative['y'] . '&z=' . $visitedRelative['z'], $headers);
    $test->assertEquals(200, $visitedResponse->status, 'visited sector can be consulted through GET /api/sector');
    $test->assertEquals('detailed', $visitedResponse->body['sector']['knowledgeLevel'] ?? null, 'visited sector returns detailed information');

    $currentProbe->enteredCurrentSectorAt = gmdate('c', time() - 8 * 3600);
    $probes->save($currentProbe);

    $distanceOne = $neighbors[1];
    $relOne = $distanceOne->subtract($player->homeSector);
    $distanceOneResponse = $kernel->handle('GET', '/api/sector?x=' . $relOne['x'] . '&y=' . $relOne['y'] . '&z=' . $relOne['z'], $headers);
    $test->assertEquals(200, $distanceOneResponse->status, 'distance 1 sector returns partial scan data');
    $test->assertEquals('neighbor_scan', $distanceOneResponse->body['sector']['knowledgeLevel'] ?? null, 'distance 1 uses neighbor_scan knowledge');

    $distanceTwo = $currentProbe->currentSector->add(2, 0, 0);
    $relTwo = $distanceTwo->subtract($player->homeSector);
    $distanceTwoResponse = $kernel->handle('GET', '/api/sector?x=' . $relTwo['x'] . '&y=' . $relTwo['y'] . '&z=' . $relTwo['z'], $headers);
    $test->assertEquals(200, $distanceTwoResponse->status, 'distance 2 sector returns distant scan data');
    $test->assertEquals('distant_scan', $distanceTwoResponse->body['sector']['knowledgeLevel'] ?? null, 'distance 2 uses distant_scan knowledge');

    $distanceThree = $currentProbe->currentSector->add(3, 3, 0);
    $relThree = $distanceThree->subtract($player->homeSector);
    $distanceThreeResponse = $kernel->handle('GET', '/api/sector?x=' . $relThree['x'] . '&y=' . $relThree['y'] . '&z=' . $relThree['z'], $headers);
    $test->assertEquals(200, $distanceThreeResponse->status, 'distance >=3 sector returns minimal long range estimation');
    $test->assertEquals('long_range_estimation', $distanceThreeResponse->body['sector']['knowledgeLevel'] ?? null, 'distance >=3 uses long_range_estimation knowledge');

    $currentProbe->enteredCurrentSectorAt = gmdate('c', time() - 60);
    $probes->save($currentProbe);
    $tooEarly = $kernel->handle('GET', '/api/sector?x=' . $relOne['x'] . '&y=' . $relOne['y'] . '&z=' . $relOne['z'], $headers);
    $test->assertEquals(400, $tooEarly->status, 'distance 1 scan before enough residence time returns an error');
    $test->assertEquals('insufficient_scan_data', $tooEarly->body['error']['code'] ?? null, 'insufficient scan data error code is explicit');
}

$invalidCoordinates = $kernel->handle('GET', '/api/sector?x=1&y=0&z=0', $headers);
$test->assertEquals(400, $invalidCoordinates->status, 'invalid relative FCC coordinates return a clean API error');
$test->assertEquals('bad_request', $invalidCoordinates->body['error']['code'] ?? null, 'invalid coordinates use bad_request error code');
$test->assertEquals('Relative coordinates are invalid: x + y + z must be even.', $invalidCoordinates->body['error']['message'] ?? null, 'invalid sector coordinates return a relative-coordinate message');

$moveSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'remi', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$moveHeaders = ['Authorization' => 'Bearer ' . (string) ($moveSession->body['token'] ?? '')];
$moveProbe = $probes->findByPlayerId($player->id);

$moveWithoutToken = $kernel->handle('POST', '/api/probe/move', [], json_encode(['target' => ['x' => 1, 'y' => 1, 'z' => 0]], JSON_THROW_ON_ERROR));
$test->assertEquals(401, $moveWithoutToken->status, 'POST /api/probe/move rejects missing token');

$invalidMove = $kernel->handle('POST', '/api/probe/move', $moveHeaders, json_encode(['target' => ['x' => 1, 'y' => 0, 'z' => 0]], JSON_THROW_ON_ERROR));
$test->assertEquals(400, $invalidMove->status, 'POST /api/probe/move rejects invalid relative FCC coordinates');
$test->assertEquals('Relative coordinates are invalid: x + y + z must be even.', $invalidMove->body['error']['message'] ?? null, 'invalid movement coordinates return a relative-coordinate message');

$sameMove = $kernel->handle('POST', '/api/probe/move', $moveHeaders, json_encode(['target' => ['x' => 0, 'y' => 0, 'z' => 0]], JSON_THROW_ON_ERROR));
$test->assertEquals(400, $sameMove->status, 'POST /api/probe/move rejects current sector destination');

if ($moveProbe !== null) {
    $pdo->prepare('UPDATE neumann_probes SET deuterium_stock = 0 WHERE id = :id')->execute(['id' => $moveProbe->id]);
    $noFuel = $kernel->handle('POST', '/api/probe/move', $moveHeaders, json_encode(['target' => ['x' => 1, 'y' => 1, 'z' => 0]], JSON_THROW_ON_ERROR));
    $test->assertEquals(422, $noFuel->status, 'POST /api/probe/move rejects insufficient deuterium');

    $originBeforeMove = $moveProbe->currentSector;
    $pdo->prepare('UPDATE neumann_probes SET deuterium_stock = 100 WHERE id = :id')->execute(['id' => $moveProbe->id]);
    $pdo->prepare(
        'UPDATE mannies
         SET location_type = :location_type,
             sector_x = :sector_x,
             sector_y = :sector_y,
             sector_z = :sector_z,
             current_task = NULL,
             task_started_at = NULL,
             task_ends_at = NULL,
             task_payload_json = :payload
         WHERE uid = :uid'
    )->execute([
        'uid' => $firstMannyId,
        'location_type' => 'sector',
        'sector_x' => $originBeforeMove->getX(),
        'sector_y' => $originBeforeMove->getY(),
        'sector_z' => $originBeforeMove->getZ(),
        'payload' => '{}',
    ]);
    $startMove = $kernel->handle('POST', '/api/probe/move', $moveHeaders, json_encode(['target' => ['x' => 1, 'y' => 1, 'z' => 0]], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $startMove->status, 'POST /api/probe/move starts movement with 202');
    $test->assertEquals('preparing', $startMove->body['movement']['status'] ?? null, 'new movement starts in preparing status');
    $test->assertEquals(1, $startMove->body['movement']['distance'] ?? null, 'movement distance is computed on FCC layers');
    $test->assertEquals(2.0, $startMove->body['movement']['fuelCostDeuterium'] ?? null, 'movement consumes 2 percent of current deuterium');
    $test->assert(!str_contains(json_encode($startMove->body, JSON_THROW_ON_ERROR), 'sector_x'), 'movement response does not expose absolute database coordinates');

    $afterStartProbe = $probes->findByPlayerId($player->id);
    $test->assertEquals(98.0, $afterStartProbe?->deuteriumStock, 'probe deuterium stock is persisted after movement start');
    $test->assertEquals('preparing', $afterStartProbe?->status->value, 'probe status becomes preparing');
    $startedMovement = $movements->findActiveByProbeId($moveProbe->id);
    $startedMovementPhaseEvents = $pdo->prepare("SELECT COUNT(*) FROM scheduled_events WHERE status = 'pending' AND type = 'probe.movement.phase' AND entity_type = 'probe_movement' AND entity_id = :movement_id");
    $startedMovementPhaseEvents->execute(['movement_id' => $startedMovement?->id ?? 0]);
    $test->assertEquals(4, (int) $startedMovementPhaseEvents->fetchColumn(), 'starting a movement schedules its phase events');
    $forgottenSector = $sectorRepository->load($originBeforeMove);
    $forgottenSectorManny = $forgottenSector->findObjectById(SectorManny::objectIdForUid($firstMannyId));
    $test->assertEquals(SectorManny::STATE_FORGOTTEN, $forgottenSectorManny?->toArray()['state'] ?? null, 'movement registers outside owned Mannys as forgotten sector objects');
    $test->assertEquals($moveProbe->id, $mannies->findByUid($firstMannyId)?->probeId, 'forgotten Manny keeps its owner probe link in the database');

    $secondMove = $kernel->handle('POST', '/api/probe/move', $moveHeaders, json_encode(['target' => ['x' => 2, 'y' => 0, 'z' => 0]], JSON_THROW_ON_ERROR));
    $test->assertEquals(409, $secondMove->status, 'second movement cannot start while active movement exists');

    $movement = $movements->findActiveByProbeId($moveProbe->id);
    if ($movement !== null) {
        $probeDuringPreparation = $kernel->handle('GET', '/api/probe', $moveHeaders);
        $test->assertEquals('preparing', $probeDuringPreparation->body['probe']['movement']['phase'] ?? null, 'GET /api/probe reports preparation phase');
        $test->assertEquals('normal', $probeDuringPreparation->body['probe']['movement']['sensorMode'] ?? null, 'preparation sensor mode is normal');

        $base = time();
        $pdo->prepare("UPDATE probe_movements SET started_at = :started, preparation_ends_at = :prep, acceleration_ends_at = :accel, cruise_ends_at = :cruise, deceleration_ends_at = :decel, arrival_at = :arrival WHERE id = :id")->execute([
            'id' => $movement->id,
            'started' => gmdate('c', $base - 20 * 60),
            'prep' => gmdate('c', $base - 10 * 60),
            'accel' => gmdate('c', $base + 10 * 60),
            'cruise' => gmdate('c', $base + 40 * 60),
            'decel' => gmdate('c', $base + 60 * 60),
            'arrival' => gmdate('c', $base + 60 * 60),
        ]);
        $acceleratingProbe = $kernel->handle('GET', '/api/probe', $moveHeaders);
        $test->assertEquals('accelerating', $acceleratingProbe->body['probe']['movement']['phase'] ?? null, 'GET /api/probe reports acceleration phase from dates');
        $test->assertEquals('degraded', $acceleratingProbe->body['probe']['movement']['sensorMode'] ?? null, 'acceleration sensor mode is degraded');

        $pdo->prepare("UPDATE probe_movements SET status = 'accelerating', started_at = :started, preparation_ends_at = :prep, acceleration_ends_at = :accel, cruise_ends_at = :cruise, deceleration_ends_at = :decel, arrival_at = :arrival, destruction_checked_at = NULL WHERE id = :id")->execute([
            'id' => $movement->id,
            'started' => gmdate('c', $base - 60 * 60),
            'prep' => gmdate('c', $base - 50 * 60),
            'accel' => gmdate('c', $base - 30 * 60),
            'cruise' => gmdate('c', $base + 30 * 60),
            'decel' => gmdate('c', $base + 50 * 60),
            'arrival' => gmdate('c', $base + 50 * 60),
        ]);
        $cruisingProbe = $kernel->handle('GET', '/api/probe', $moveHeaders);
        $test->assertEquals('cruising', $cruisingProbe->body['probe']['movement']['phase'] ?? null, 'GET /api/probe reports cruise phase from dates');
        $test->assertEquals('blind', $cruisingProbe->body['probe']['movement']['sensorMode'] ?? null, 'cruise sensor mode is blind');

        $blindSector = $kernel->handle('GET', '/api/probe/sector', $moveHeaders);
        $test->assertEquals(400, $blindSector->status, 'GET /api/probe/sector is unavailable while blind');
        $test->assertEquals('sensors_unavailable', $blindSector->body['error']['code'] ?? null, 'blind probe sector error code is explicit');

        $historicalSector = $kernel->handle('GET', '/api/sector?x=0&y=0&z=0', $moveHeaders);
        $test->assertEquals(200, $historicalSector->status, 'GET /api/sector returns historical data for visited sectors while blind');
        $test->assertEquals('historical', $historicalSector->body['sector']['dataFreshness'] ?? null, 'blind visited sector response is marked historical');

        $pdo->prepare("UPDATE probe_movements SET status = 'cruising', cruise_ends_at = :cruise, deceleration_ends_at = :decel, arrival_at = :arrival WHERE id = :id")->execute([
            'id' => $movement->id,
            'cruise' => gmdate('c', $base - 5 * 60),
            'decel' => gmdate('c', $base + 15 * 60),
            'arrival' => gmdate('c', $base + 15 * 60),
        ]);
        $deceleratingProbe = $kernel->handle('GET', '/api/probe', $moveHeaders);
        $test->assertEquals('decelerating', $deceleratingProbe->body['probe']['movement']['phase'] ?? null, 'GET /api/probe reports deceleration phase from dates');
        $test->assertEquals('degraded', $deceleratingProbe->body['probe']['movement']['sensorMode'] ?? null, 'deceleration sensor mode is degraded');

        $pdo->prepare("UPDATE probe_movements SET status = 'decelerating', arrival_at = :arrival, deceleration_ends_at = :arrival WHERE id = :id")->execute([
            'id' => $movement->id,
            'arrival' => gmdate('c', $base - 60),
        ]);
        $scheduledEvents->schedule(SchedulerService::PROBE_MOVEMENT_PHASE, 'probe_movement', $movement->id, gmdate('c'), ['probeId' => $movement->probeId, 'phase' => 'arrived']);
        $schedulerStats = $scheduler->processDueEvents();
        $test->assertEquals(1, $schedulerStats['processed'], 'scheduler processes due movement events');
        $test->assertEquals('idle', $probes->findByPlayerId($player->id)?->status->value, 'scheduler finalizes arrived movement without an API read');

        $arrivedProbe = $kernel->handle('GET', '/api/probe', $moveHeaders);
        $test->assertEquals('idle', $arrivedProbe->body['probe']['status'] ?? null, 'GET /api/probe finalizes arrived movement');
        $test->assertEquals(['x' => 1, 'y' => 1, 'z' => 0], $arrivedProbe->body['probe']['sector']['relative'] ?? null, 'after arrival target sector becomes current relative sector');
        $arrivalIntegrity = (float) ($arrivedProbe->body['probe']['systems']['integrityPercent'] ?? -1);
        $test->assert($arrivalIntegrity >= 94.0 && $arrivalIntegrity <= 97.0, 'intersector arrival applies 0 to 3 percent integrity loss per traversed sector');

        $arrivedProbeEntity = $probes->findByPlayerId($player->id);
        if ($arrivedProbeEntity !== null) {
            $test->assert($visitedSectors->hasVisited($player, $arrivedProbeEntity->currentSector), 'arrival marks target sector as visited');
            $visitedAfterArrival = $kernel->handle('GET', '/api/probe/visited-sectors', $moveHeaders);
            $visitedRelativeKeys = array_map(
                static fn(array $sector): string => implode(':', $sector['relativeCoordinates'] ?? []),
                $visitedAfterArrival->body['visitedSectors'] ?? [],
            );
            $test->assert(in_array('1:1:0', $visitedRelativeKeys, true), 'visited-sector list includes the movement target after arrival');
            $test->assertEquals('normal', $arrivedProbe->body['probe']['sensorMode'] ?? null, 'idle sensor mode is normal after arrival');
            $originRelativeAfterArrival = $originBeforeMove->subtract($player->homeSector);
            $oldSectorResponse = $kernel->handle('GET', '/api/sector?x=' . $originRelativeAfterArrival['x'] . '&y=' . $originRelativeAfterArrival['y'] . '&z=' . $originRelativeAfterArrival['z'], $moveHeaders);
            $oldSectorMannyObjects = array_values(array_filter(
                $oldSectorResponse->body['sector']['objects'] ?? [],
                static fn(array $object): bool => ($object['type'] ?? null) === 'manny'
            ));
            $test->assertEquals(0, count($oldSectorMannyObjects), 'visited-sector scan hides abandoned and forgotten Mannys when the probe is no longer in that sector');

            $returnToForgottenMannySector = $kernel->handle('POST', '/api/probe/move', $moveHeaders, json_encode(['target' => ['x' => 0, 'y' => 0, 'z' => 0]], JSON_THROW_ON_ERROR));
            $test->assertEquals(202, $returnToForgottenMannySector->status, 'probe can return to a sector containing one of its forgotten Mannys');
            $returnMovement = $movements->findActiveByProbeId($moveProbe->id);
            if ($returnMovement !== null) {
                $pdo->prepare("UPDATE probe_movements SET status = 'decelerating', arrival_at = :arrival, deceleration_ends_at = :arrival WHERE id = :id")->execute([
                    'id' => $returnMovement->id,
                    'arrival' => gmdate('c', time() - 60),
                ]);
                $scheduledEvents->schedule(SchedulerService::PROBE_MOVEMENT_PHASE, 'probe_movement', $returnMovement->id, gmdate('c'), ['probeId' => $returnMovement->probeId, 'phase' => 'arrived']);
                $scheduler->processDueEvents();
                $kernel->handle('GET', '/api/probe', $moveHeaders);
                $returnedManny = $mannies->findByUidForProbe($moveProbe->id, $firstMannyId);
                $test->assertEquals('probe', $returnedManny?->locationType, 'returning to a forgotten Manny sector brings the owned idle Manny back aboard');
                $test->assertEquals(null, $returnedManny?->sector, 'recovered forgotten Manny no longer exposes an exterior sector position');
                $revisitedForgottenSector = $sectorRepository->load($originBeforeMove);
                $test->assertEquals(null, $revisitedForgottenSector->findObjectById(SectorManny::objectIdForUid($firstMannyId)), 'recovered forgotten Manny is removed from the sector object list');
            }
        }
    }
}

$riskPlayer = $auth->registerPlayerWithPassword('risk', 'secret', 'Risk');
$riskSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'risk', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$riskHeaders = ['Authorization' => 'Bearer ' . (string) ($riskSession->body['token'] ?? '')];
$riskProbe = $probes->findByPlayerId($riskPlayer->id);
if ($riskProbe !== null) {
    $shortRiskMove = $kernel->handle('POST', '/api/probe/move', $riskHeaders, json_encode(['target' => ['x' => 2, 'y' => 0, 'z' => 0]], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $shortRiskMove->status, 'distance <= 2 movement can start for destruction safety test');
    $shortMovement = $movements->findActiveByProbeId($riskProbe->id);
    if ($shortMovement !== null) {
        $base = time();
        $pdo->prepare("UPDATE probe_movements SET started_at = :started, preparation_ends_at = :prep, acceleration_ends_at = :accel, cruise_ends_at = :cruise, deceleration_ends_at = :decel, arrival_at = :arrival WHERE id = :id")->execute([
            'id' => $shortMovement->id,
            'started' => gmdate('c', $base - 120 * 60),
            'prep' => gmdate('c', $base - 110 * 60),
            'accel' => gmdate('c', $base - 70 * 60),
            'cruise' => gmdate('c', $base + 30 * 60),
            'decel' => gmdate('c', $base + 70 * 60),
            'arrival' => gmdate('c', $base + 70 * 60),
        ]);
        $shortCruise = $kernel->handle('GET', '/api/probe', $riskHeaders);
        $test->assertEquals('cruising', $shortCruise->body['probe']['status'] ?? null, 'distance <= 2 movement reaches cruise');
        $test->assert(($movements->findActiveByProbeId($riskProbe->id)?->destructionCheckedAt ?? null) !== null, 'distance <= 2 destruction check is recorded');
        $test->assertEquals('cruising', $probes->findByPlayerId($riskPlayer->id)?->status->value, 'distance <= 2 movement does not destroy the probe');

        $pdo->prepare("UPDATE probe_movements SET arrival_at = :arrival, deceleration_ends_at = :arrival WHERE id = :id")->execute([
            'id' => $shortMovement->id,
            'arrival' => gmdate('c', $base - 60),
        ]);
        $kernel->handle('GET', '/api/probe', $riskHeaders);
    }

    $longRiskMove = $kernel->handle('POST', '/api/probe/move', $riskHeaders, json_encode(['target' => ['x' => 8, 'y' => 0, 'z' => 0]], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $longRiskMove->status, 'distance > 2 movement can start for deterministic destruction test');
    $longMovement = $movements->findActiveByProbeId($riskProbe->id);
    if ($longMovement !== null) {
        $base = time();
        $chosenStartedAt = gmdate('c', $base - 240 * 60);
        for ($i = 0; $i < 2000; $i++) {
            $candidate = gmdate('c', $base - (240 * 60) - $i);
            $payload = implode('|', [
                'api-test-world',
                $longMovement->probeId,
                $longMovement->id,
                $longMovement->origin->toKey(),
                $longMovement->target->toKey(),
                $candidate,
            ]);
            $roll = hexdec(substr(hash('sha256', $payload), 0, 15)) / hexdec(str_repeat('f', 15));
            if ($roll < 0.40) {
                $chosenStartedAt = $candidate;
                break;
            }
        }

        $pdo->prepare("UPDATE probe_movements SET started_at = :started, preparation_ends_at = :prep, acceleration_ends_at = :accel, cruise_ends_at = :cruise, deceleration_ends_at = :decel, arrival_at = :arrival, destruction_checked_at = NULL WHERE id = :id")->execute([
            'id' => $longMovement->id,
            'started' => $chosenStartedAt,
            'prep' => gmdate('c', $base - 230 * 60),
            'accel' => gmdate('c', $base - 110 * 60),
            'cruise' => gmdate('c', $base + 70 * 60),
            'decel' => gmdate('c', $base + 190 * 60),
            'arrival' => gmdate('c', $base + 190 * 60),
        ]);

        $destroyedProbe = $kernel->handle('GET', '/api/probe', $riskHeaders);
        $test->assertEquals('dead', $destroyedProbe->body['probe']['status'] ?? null, 'deterministic destruction sets probe status dead');
        $test->assertEquals('mind_snapshot_reassignment_available', $destroyedProbe->body['probe']['alert']['type'] ?? null, 'dead probe response exposes mind snapshot reassignment alert');
        $test->assertEquals('/api/probe/mind-snapshot/reassign', $destroyedProbe->body['probe']['alert']['action']['endpoint'] ?? null, 'dead probe alert exposes the reassignment endpoint');
        $destroyedMovement = $movements->findLatestByProbeId($riskProbe->id);
        $test->assertEquals('destroyed', $destroyedMovement?->status, 'deterministic destruction sets movement status destroyed');
        $firstCheckedAt = $destroyedMovement?->destructionCheckedAt;
        $kernel->handle('GET', '/api/probe', $riskHeaders);
        $test->assertEquals($firstCheckedAt, $movements->findLatestByProbeId($riskProbe->id)?->destructionCheckedAt, 'destruction risk roll is performed only once');

        $deadMove = $kernel->handle('POST', '/api/probe/move', $riskHeaders, json_encode(['target' => ['x' => 10, 'y' => 0, 'z' => 0]], JSON_THROW_ON_ERROR));
        $test->assertEquals(409, $deadMove->status, 'dead probe cannot start a movement');
        $test->assertEquals('probe_dead', $deadMove->body['error']['code'] ?? null, 'dead action error code is explicit');
        $deadSector = $kernel->handle('GET', '/api/probe/sector', $riskHeaders);
        $test->assertEquals(409, $deadSector->status, 'dead probe cannot access current sector details');
        $test->assert(!str_contains(json_encode($destroyedProbe->body, JSON_THROW_ON_ERROR), 'sector_x'), 'dead probe response does not expose absolute coordinates');

        $reassignedProbe = $kernel->handle('POST', '/api/probe/mind-snapshot/reassign', $riskHeaders, json_encode([], JSON_THROW_ON_ERROR));
        $test->assertEquals(200, $reassignedProbe->status, 'POST /api/probe/mind-snapshot/reassign reassigns a dead probe snapshot');
        $test->assertEquals(true, $reassignedProbe->body['reassigned'] ?? null, 'mind snapshot reassignment response confirms the reset');
        $test->assertEquals($riskProbe->id, $reassignedProbe->body['previousProbeId'] ?? null, 'mind snapshot reassignment identifies the destroyed probe');
        $test->assertEquals(['x' => 0, 'y' => 0, 'z' => 0], $reassignedProbe->body['probe']['sector']['relative'] ?? null, 'reassigned probe starts at a fresh relative origin');
        $test->assertEquals('idle', $reassignedProbe->body['probe']['status'] ?? null, 'reassigned probe is operational');
        $test->assert($probes->findById($riskProbe->id) === null, 'mind snapshot reassignment deletes the terminal probe');
        $newRiskProbe = $probes->findByPlayerId($riskPlayer->id);
        $updatedRiskPlayer = $players->findById($riskPlayer->id);
        $test->assert($newRiskProbe !== null && $newRiskProbe->id !== $riskProbe->id, 'mind snapshot reassignment creates a fresh probe row');
        $test->assert($newRiskProbe !== null && $updatedRiskPlayer !== null && $newRiskProbe->currentSector->equals($updatedRiskPlayer->homeSector), 'fresh probe starts in the player new home sector');
        $test->assert($updatedRiskPlayer !== null && count($visitedSectors->listVisited($updatedRiskPlayer)) === 1, 'mind snapshot reassignment resets visited sectors to the fresh origin only');
    }
}

$blackHolePlayer = $auth->registerPlayerWithPassword('black-hole', 'secret', 'Black Hole');
$blackHoleSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'black-hole', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$blackHoleHeaders = ['Authorization' => 'Bearer ' . (string) ($blackHoleSession->body['token'] ?? '')];
$blackHoleProbe = $probes->findByPlayerId($blackHolePlayer->id);
if ($blackHoleProbe !== null) {
    $sectorRepository->save(new SectorContent($blackHoleProbe->currentSector, [
        new BlackHole('test-black-hole', null, 7.5, 22.0, true, 160.0),
    ]));

    $blackHoleSector = $kernel->handle('GET', '/api/probe/sector', $blackHoleHeaders);
    $test->assertEquals(200, $blackHoleSector->status, 'black hole sector remains observable before trap threshold');
    $test->assertEquals('extreme', $blackHoleSector->body['sector']['objects'][0]['dangerLevel'] ?? null, 'black holes are exposed as extreme danger objects');
    $test->assert(isset($blackHoleSector->body['sector']['objects'][0]['noReturnCountdown']), 'black hole sector exposes the no-return countdown');

    $trapEvent = $scheduledEvents->findPendingByTypeAndEntity(SchedulerService::PROBE_BLACK_HOLE_TRAP, 'probe', $blackHoleProbe->id);
    $test->assert($trapEvent !== null, 'observing a current black hole sector schedules a trap event');
    if ($trapEvent !== null) {
        $trapDelay = (new DateTimeImmutable($trapEvent->runAt))->getTimestamp() - time();
        $test->assert($trapDelay >= 9890 && $trapDelay <= 9910, 'black hole trap delay is modulated by black hole mass');
        $test->assertEquals(9900, $blackHoleSector->body['sector']['objects'][0]['noReturnCountdown']['delaySeconds'] ?? null, 'black hole countdown exposes the mass-modulated delay');
        $pdo->prepare('UPDATE scheduled_events SET run_at = :run_at WHERE id = :id')->execute([
            'id' => $trapEvent->id,
            'run_at' => gmdate('c', time() - 1),
        ]);
        $trapStats = $scheduler->processDueEvents();
        $test->assertEquals(1, $trapStats['processed'], 'scheduler processes due black hole trap events');
        $test->assertEquals('trapped_by_black_hole', $probes->findByPlayerId($blackHolePlayer->id)?->status->value, 'black hole trap sets the terminal probe status');

        $trappedProbe = $kernel->handle('GET', '/api/probe', $blackHoleHeaders);
        $test->assertEquals('trapped_by_black_hole', $trappedProbe->body['probe']['status'] ?? null, 'GET /api/probe reports black hole trapped status');
        $test->assertEquals('blind', $trappedProbe->body['probe']['sensorMode'] ?? null, 'black hole trapped probe has blind sensors');
        $test->assertEquals('mind_snapshot_reassignment_available', $trappedProbe->body['probe']['alert']['type'] ?? null, 'black hole trapped probe response exposes mind snapshot reassignment alert');
        $trappedSector = $kernel->handle('GET', '/api/probe/sector', $blackHoleHeaders);
        $test->assertEquals(409, $trappedSector->status, 'black hole trapped probe cannot access sector sensors');
        $trappedMove = $kernel->handle('POST', '/api/probe/move', $blackHoleHeaders, json_encode(['target' => ['x' => 1, 'y' => 1, 'z' => 0]], JSON_THROW_ON_ERROR));
        $test->assertEquals(409, $trappedMove->status, 'black hole trapped probe cannot start movement');
        $test->assertEquals('probe_trapped_by_black_hole', $trappedMove->body['error']['code'] ?? null, 'black hole trapped action error code is explicit');
    }
}

$escapePlayer = $auth->registerPlayerWithPassword('escape-black-hole', 'secret', 'Escape');
$escapeSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'escape-black-hole', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$escapeHeaders = ['Authorization' => 'Bearer ' . (string) ($escapeSession->body['token'] ?? '')];
$escapeProbe = $probes->findByPlayerId($escapePlayer->id);
if ($escapeProbe !== null) {
    $sectorRepository->save(new SectorContent($escapeProbe->currentSector, [
        new BlackHole('escape-black-hole', null, 5.0, 18.0, false, 120.0),
    ]));
    $kernel->handle('GET', '/api/probe/sector', $escapeHeaders);
    $test->assert($scheduledEvents->findPendingByTypeAndEntity(SchedulerService::PROBE_BLACK_HOLE_TRAP, 'probe', $escapeProbe->id) !== null, 'black hole escape test starts with a pending trap event');

    $escapeMove = $kernel->handle('POST', '/api/probe/move', $escapeHeaders, json_encode(['target' => ['x' => 1, 'y' => 1, 'z' => 0]], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $escapeMove->status, 'probe can start escaping from a black hole sector before trap threshold');
    $test->assert($scheduledEvents->findPendingByTypeAndEntity(SchedulerService::PROBE_BLACK_HOLE_TRAP, 'probe', $escapeProbe->id) === null, 'starting a movement cancels the pending black hole trap event');
}

foreach ([
    'GET /api/me',
    'GET /api/probe',
    'POST /api/probe/mind-snapshot/reassign',
    'POST /api/probe/atomic-printer/craft',
    'GET /api/probe/messages',
    'GET /api/probe/messages/sent',
    'GET /api/probe/visited-sectors',
    'GET /api/probe/sector',
    'GET /api/sector?x=0&y=0&z=0',
    'GET /api/forum/categories',
    'GET /api/forum/posts',
] as $route) {
    [$routeMethod, $path] = explode(' ', $route, 2);
    $response = $kernel->handle($routeMethod, $path);
    $test->assertEquals(401, $response->status, "protected endpoint $path rejects missing Authorization Bearer");
}

removeDirectory($tmp);
exit($test->finish());
