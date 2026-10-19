<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Support/StorageTestPdo.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Operations\MineableCutoverMonitor;

if ($argc !== 2 || !str_starts_with($argv[1], '--mysql-config=')) {
    fwrite(STDERR, "Usage: php tests/MineableCutoverMysqlTests.php --mysql-config=DEVELOPMENT_CONFIG\nCreates and removes isolated test tables.\n");
    exit(2);
}
$config = DatabaseConfig::fromFile(substr($argv[1], strlen('--mysql-config=')));
if ($config->driver !== 'mysql') { throw new RuntimeException('MariaDB/MySQL development configuration required.'); }
$raw = (new DatabaseConnectionFactory($config, dirname(__DIR__)))->create();
$initializer = new SchemaInitializer('mysql');
$statements = (new ReflectionMethod(SchemaInitializer::class, 'statements'))->invoke($initializer);
$tables = []; $prefix = 'cutover_test_' . bin2hex(random_bytes(5)) . '_';
foreach ($statements as $sql) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $match)) { $tables[$match[1]] = $prefix . count($tables); }
}
$db = new StorageTestPdo($raw, $tables);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    echo 'PASS ', $message, "\n";
};
try {
    $initializer->initialize($db);
    $now = time();
    $db->exec("INSERT INTO mineable_object_identities(object_id) VALUES('mysql-live-source')");
    $db->exec("INSERT INTO mineable_object_stocks VALUES('mysql-live-source',0,12.3456,0,0,2)");
    $stmt = $db->prepare("INSERT INTO scheduled_events(type,entity_type,entity_id,run_at,status,payload_json,created_at,updated_at,processed_at) VALUES('manny.task','manny',1,?,'failed','{}',?,?,?)");
    $stmt->execute(array_fill(0, 4, gmdate('c', $now)));
    $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('SET SESSION TRANSACTION READ ONLY');
    $db->beginTransaction();
    $report = (new MineableCutoverMonitor($db))->collect($now, $now - 60);
    $assert($report['status'] === 'critical' && $report['counters']['failedEventsSince'] === 1, 'MariaDB detects the new failure in a read-only snapshot');
    $assert($report['counters']['stocks'] === 1 && $report['counters']['invalidStocks'] === 0, 'MariaDB reads canonical stocks after normal mutations');
    $assert(count($report['events']) === 1 && $report['events'][0]['count'] === 1, 'MariaDB aggregates scheduler state without payloads');
    try { $db->exec('UPDATE mineable_object_stocks SET metals=999'); $assert(false, 'read-only transaction must reject writes'); }
    catch (PDOException $error) { $assert((int) ($error->errorInfo[1] ?? 0) === 1792, 'MariaDB enforces read-only monitoring'); }
    $db->rollBack();
    $assert((float) $db->query('SELECT metals FROM mineable_object_stocks')->fetchColumn() === 12.3456, 'monitor leaves the original quantity intact');
} finally {
    if ($raw->inTransaction()) { $raw->rollBack(); }
    $raw->exec('SET SESSION TRANSACTION READ WRITE');
    $raw->exec('SET FOREIGN_KEY_CHECKS=0');
    try { foreach (array_reverse($tables) as $table) { $raw->exec('DROP TABLE IF EXISTS `' . $table . '`'); } }
    finally { $raw->exec('SET FOREIGN_KEY_CHECKS=1'); }
}
