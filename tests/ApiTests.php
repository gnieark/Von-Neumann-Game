<?php

declare(strict_types=1);

use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Http\ApiKernel;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerAuthRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\SessionRepository;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorFileRepository;
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
$sessions = new SessionRepository($pdo);
$auth = new AuthService($players, $authMethods, $probes, $sessions, 7);
$sectorService = new SectorService(new SectorFileRepository($universePath), new SectorContentGenerator(), 'api-test-world');
$kernel = new ApiKernel($auth, $probes, $sectorService);

$player = $auth->registerPlayerWithPassword('remi', 'secret', 'Remi');
$test->assert($player->id > 0, 'user creation returns a persisted player');
$test->assert($probes->findByPlayerId($player->id) !== null, 'a probe is automatically created for a new player');
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

$sector = $kernel->handle('GET', '/api/probe/sector', $headers);
$test->assertEquals(200, $sector->status, 'valid token allows GET /api/probe/sector');
$test->assert(isset($sector->body['sector']['objects']), 'GET /api/probe/sector returns sector objects');

foreach (['/api/me', '/api/probe', '/api/probe/sector'] as $path) {
    $response = $kernel->handle('GET', $path);
    $test->assertEquals(401, $response->status, "protected endpoint $path rejects missing Authorization Bearer");
}

removeDirectory($tmp);
exit($test->finish());
