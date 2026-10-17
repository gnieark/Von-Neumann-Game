<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\Migration\MineableObjectStockAudit;

$usage = "Usage: php scripts/one-shot-scripts/audit-mineable-object-stock-migration.php --database-config=PATH --universe-path=PATH [--max-files=100000] [--max-rows=1000000] [--missing-resources=zero]\nRead-only inventory; JSON on stdout. No schema initialization or sector generation. The explicit zero policy records exhausted asteroid fields; present quantities are preserved.\nExit: 0 inventory valid, 1 anomalies, 2 invocation/read failure.\n";
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') { echo $usage; exit(0); }
    if (!preg_match('/^--(database-config|universe-path|max-files|max-rows|missing-resources)=(.+)$/D', $argument, $match) || isset($options[$match[1]])) {
        fwrite(STDERR, $usage); exit(2);
    }
    $options[$match[1]] = $match[2];
}
if (!isset($options['database-config'], $options['universe-path'])) { fwrite(STDERR, $usage); exit(2); }
if (isset($options['missing-resources']) && $options['missing-resources'] !== 'zero') { fwrite(STDERR, $usage); exit(2); }
foreach (['max-files', 'max-rows'] as $key) {
    if (isset($options[$key]) && (!ctype_digit($options[$key]) || (int) $options[$key] < 1 || strlen($options[$key]) > 9)) {
        fwrite(STDERR, 'Invalid positive bound: ' . $key . "\n"); exit(2);
    }
}
$pdo = null;
try {
    $root = dirname(__DIR__, 2);
    $config = DatabaseConfig::fromFile($options['database-config']);
    // The ordinary connection factory creates missing SQLite files: forbid that in an audit.
    if ($config->driver === 'sqlite') {
        $path = $config->path === null ? '' : (str_starts_with($config->path, '/') ? $config->path : $root . '/' . $config->path);
        if (!is_file($path)) { throw new InvalidArgumentException('The SQLite audit database must already exist.'); }
        $pdo = new PDO('sqlite:file:' . str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $path) . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only=ON');
    } else {
        $pdo = (new DatabaseConnectionFactory($config, $root))->create();
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    }
    $pdo->beginTransaction();
    $report = (new MineableObjectStockAudit((int) ($options['max-files'] ?? 100000), (int) ($options['max-rows'] ?? 1000000), isset($options['missing-resources'])))->inspect($pdo, $options['universe-path']);
    $pdo->rollBack();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($report['inventoryValid'] ? 0 : 1);
} catch (Throwable $error) {
    if ($pdo?->inTransaction()) { $pdo->rollBack(); }
    // PDO connection errors can contain connection details; do not echo their messages.
    fwrite(STDERR, $error instanceof PDOException ? "SQL audit failed; verify the explicit database configuration and schema.\n" : $error->getMessage() . "\n");
    exit(2);
}
