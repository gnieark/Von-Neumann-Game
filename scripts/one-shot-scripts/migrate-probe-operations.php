<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\SchemaInitializer;

$usage = "Usage: php scripts/one-shot-scripts/migrate-probe-operations.php --database-config=PATH [--apply|--dry-run]\nCreates probe admission/loss coordination and seeds guards for existing probes. Stop API writers and workers before applying. Dry run by default.\n";
$path = null;
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=') && $path === null) { $path = substr($argument, 18); }
    elseif ($argument === '--apply') { $apply = true; }
    elseif ($argument === '--dry-run') { $apply = false; }
    elseif ($argument === '--help') { echo $usage; exit(0); }
    else { fwrite(STDERR, $usage); exit(2); }
}
if ($path === null || $path === '') { fwrite(STDERR, $usage); exit(2); }
try {
    $config = DatabaseConfig::fromFile($path);
    if (!in_array($config->driver, ['sqlite', 'mysql'], true)) { throw new InvalidArgumentException('Unsupported database driver.'); }
    $statements = (new SchemaInitializer($config->driver))->probeOperationStatements();
    if (!$apply) {
        echo "Dry run; no connection opened.\n", implode(";\n", $statements), ";\n";
        echo "Apply seeds missing guards, preserves closed guards and queues cleanup of existing dead/trapped probes (cause migration_terminal; causal time = stored updated_at).\n";
        exit(0);
    }
    $pdo = (new DatabaseConnectionFactory($config, dirname(__DIR__, 2)))->create();
    foreach ($statements as $statement) { $pdo->exec($statement); }
    (new \VonNeumannGame\Database\StorageTransaction($pdo))->run(function () use ($pdo): void {
        $rows = $pdo->query('SELECT p.id FROM neumann_probes p LEFT JOIN probe_operation_gates g ON g.probe_id=p.id WHERE g.probe_id IS NULL')->fetchAll(PDO::FETCH_COLUMN);
        $operations = new \VonNeumannGame\Repository\ProbeOperationRepository($pdo);
        foreach ($rows as $id) { $operations->create((int) $id); }
        $losses = new \VonNeumannGame\Repository\ProbeReinstantiationRepository($pdo);
        $terminal = $pdo->query("SELECT id,updated_at FROM neumann_probes WHERE status IN ('dead','trapped_by_black_hole') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($terminal as $row) {
            $losses->requestLoss((int) $row['id'], 'migration_terminal', static function (): void {}, $row['updated_at']);
            if (!$operations->close((int) $row['id'])) { throw new RuntimeException('Active writer detected: stop API writers and workers before migration.'); }
        }
    });
    echo "Probe guards initialized; terminal cleanup queued; no inventory or sector files modified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Probe coordination migration failed.\n" : $error->getMessage() . "\n");
    exit(1);
}
