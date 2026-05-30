<?php

declare(strict_types=1);

use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Http\ApiKernel;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerAuthRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeMovementRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\SessionRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Service\ProbeMovementService;
use VonNeumannGame\Service\SchedulerService;
use VonNeumannGame\Service\SectorObservationService;
use VonNeumannGame\Sector\BlackHole;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\SectorService;

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

$test = new TestRunner();
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vng_api_tests_' . bin2hex(random_bytes(4));
$dbPath = $tmp . DIRECTORY_SEPARATOR . 'database.sqlite';
$universePath = $tmp . DIRECTORY_SEPARATOR . 'universe';
mkdir($tmp, 0775, true);

$dbFactory = new DatabaseConnectionFactory(new DatabaseConfig('sqlite', $dbPath), $root);
$pdo = $dbFactory->create();
$dbFactory->initializeSchema($pdo);
$test->assert(is_file($dbPath), 'temporary SQLite database is created');

$players = new PlayerRepository($pdo);
$authMethods = new PlayerAuthRepository($pdo);
$probes = new NeumannProbeRepository($pdo);
$movements = new ProbeMovementRepository($pdo);
$scheduledEvents = new ScheduledEventRepository($pdo);
$sessions = new SessionRepository($pdo);
$visitedSectors = new VisitedSectorRepository($pdo);
$auth = new AuthService($players, $authMethods, $probes, $sessions, $visitedSectors, 7);
$sectorRepository = new SectorFileRepository($universePath);
$sectorService = new SectorService($sectorRepository, new SectorContentGenerator(), 'api-test-world');
$movementService = new ProbeMovementService($probes, $movements, $visitedSectors, $scheduledEvents, $sectorService, worldSeed: 'api-test-world');
$scheduler = new SchedulerService($scheduledEvents, $probes, $movements, $movementService);
$kernel = new ApiKernel($auth, $probes, new SectorObservationService($sectorService, $visitedSectors), $movementService, $visitedSectors);

$player = $auth->registerPlayerWithPassword('remi', 'secret', 'Remi');
$test->assert($player->id > 0, 'user creation returns a persisted player');
$createdProbe = $probes->findByPlayerId($player->id);
$test->assert($createdProbe !== null, 'a probe is automatically created for a new player');
$test->assert(!$player->homeSector->equals(SectorCoordinates::origin()), 'new player receives a pseudo-random absolute home sector');
$homeSum = $player->homeSector->getX() + $player->homeSector->getY() + $player->homeSector->getZ();
$test->assert($homeSum % 2 === 0, 'random initial sector respects the FCC parity constraint');
$test->assert($visitedSectors->hasVisited($player, $player->homeSector), 'initial sector is automatically marked as visited');
if ($createdProbe !== null) {
    $test->assert($createdProbe->currentSector->equals($player->homeSector), 'initial probe starts in the player home sector');
}
$test->assertThrows(
    fn() => $auth->registerPlayerWithPassword('remi', 'other-secret', 'Duplicate'),
    'creating two users with the same username is rejected'
);
$test->assert($auth->authenticateWithPassword('remi', 'secret')?->id === $player->id, 'AuthService verifies a valid password');
$test->assert($auth->authenticateWithPassword('remi', 'bad-password') === null, 'AuthService rejects an invalid password');

$goodSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'remi', 'password' => 'secret'], JSON_THROW_ON_ERROR));
$test->assertEquals(200, $goodSession->status, 'POST /api/session with good password returns 200');
$token = $goodSession->body['token'] ?? null;
$test->assert(is_string($token) && strlen($token) >= 40, 'POST /api/session returns a sufficiently long token');

$badSession = $kernel->handle('POST', '/api/session', [], json_encode(['username' => 'remi', 'password' => 'wrong'], JSON_THROW_ON_ERROR));
$test->assertEquals(401, $badSession->status, 'POST /api/session with bad password returns 401');

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

$probe = $kernel->handle('GET', '/api/probe', $headers);
$test->assertEquals(200, $probe->status, 'valid token allows GET /api/probe');
$test->assertEquals('idle', $probe->body['probe']['status'] ?? null, 'GET /api/probe returns probe status');
$test->assert(isset($probe->body['probe']['sector']['relative']), 'GET /api/probe exposes relative sector coordinates');
$test->assertEquals(['x' => 0, 'y' => 0, 'z' => 0], $probe->body['probe']['sector']['relative'] ?? null, 'player sees initial sector as relative coordinates [0,0,0]');
$test->assert(!str_contains(json_encode($probe->body, JSON_THROW_ON_ERROR), 'absolute'), 'GET /api/probe does not expose absolute coordinates');

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

$inventoryItems = $sector->body['inventory']['items'] ?? [];
$printer = $inventoryItems[0] ?? null;
$test->assertEquals('atomic_3d_printer', $printer['type'] ?? null, 'default inventory starts with an atomic 3D printer');
$test->assertEquals(0.3, $printer['containerSpace'] ?? null, 'atomic 3D printer occupies 0.3 containers');

$mannyItems = array_values(array_filter($inventoryItems, static fn(array $item): bool => ($item['type'] ?? null) === 'manny'));
$test->assertEquals(4, count($mannyItems), 'default inventory contains four mannies');
$test->assertEquals(0.05, $mannyItems[0]['containerSpace'] ?? null, 'each manny occupies 0.05 containers');

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

    $pdo->prepare('UPDATE neumann_probes SET deuterium_stock = 100 WHERE id = :id')->execute(['id' => $moveProbe->id]);
    $startMove = $kernel->handle('POST', '/api/probe/move', $moveHeaders, json_encode(['target' => ['x' => 1, 'y' => 1, 'z' => 0]], JSON_THROW_ON_ERROR));
    $test->assertEquals(202, $startMove->status, 'POST /api/probe/move starts movement with 202');
    $test->assertEquals('preparing', $startMove->body['movement']['status'] ?? null, 'new movement starts in preparing status');
    $test->assertEquals(1, $startMove->body['movement']['distance'] ?? null, 'movement distance is computed on FCC layers');
    $test->assertEquals(2.0, $startMove->body['movement']['fuelCostDeuterium'] ?? null, 'movement consumes 2 percent of current deuterium');
    $test->assert(!str_contains(json_encode($startMove->body, JSON_THROW_ON_ERROR), 'sector_x'), 'movement response does not expose absolute database coordinates');

    $afterStartProbe = $probes->findByPlayerId($player->id);
    $test->assertEquals(98.0, $afterStartProbe?->deuteriumStock, 'probe deuterium stock is persisted after movement start');
    $test->assertEquals('preparing', $afterStartProbe?->status->value, 'probe status becomes preparing');
    $test->assertEquals(4, $scheduledEvents->countByStatus('pending'), 'starting a movement schedules its phase events');

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

        $arrivedProbeEntity = $probes->findByPlayerId($player->id);
        if ($arrivedProbeEntity !== null) {
            $test->assert($visitedSectors->hasVisited($player, $arrivedProbeEntity->currentSector), 'arrival marks target sector as visited');
            $test->assertEquals('normal', $arrivedProbe->body['probe']['sensorMode'] ?? null, 'idle sensor mode is normal after arrival');
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

foreach (['/api/me', '/api/probe', '/api/probe/sector', '/api/sector?x=0&y=0&z=0'] as $path) {
    $response = $kernel->handle('GET', $path);
    $test->assertEquals(401, $response->status, "protected endpoint $path rejects missing Authorization Bearer");
}

removeDirectory($tmp);
exit($test->finish());
