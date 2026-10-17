<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\Migration\ForgottenMannyMigration;

$usage = "Usage: php scripts/one-shot-scripts/migrate-forgotten-mannies.php --database-config=PATH --universe-path=PATH [--resolutions=PATH] [--apply --manifest=PATH]\nDry run by default. Stop API and workers for application and resume. Manifest keeps original files and SQL snapshots.\n";
$options = []; $apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') { $apply = true; }
    elseif ($arg === '--dry-run') { $apply = false; }
    elseif ($arg === '--help') { echo $usage; exit(0); }
    elseif (preg_match('/^--(database-config|universe-path|resolutions|manifest)=(.+)$/D', $arg, $match) && !isset($options[$match[1]])) { $options[$match[1]] = $match[2]; }
    else { fwrite(STDERR, $usage); exit(2); }
}
if (!isset($options['database-config'], $options['universe-path']) || ($apply && !isset($options['manifest']))) { fwrite(STDERR, $usage); exit(2); }
try {
    $config = DatabaseConfig::fromFile($options['database-config']);
    if (!$apply && $config->driver === 'sqlite') {
        $path = str_starts_with($config->path ?? '', '/') ? $config->path : dirname(__DIR__, 2) . '/' . $config->path;
        $pdo = new PDO('sqlite:file:' . str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $path) . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        $pdo = (new DatabaseConnectionFactory($config, dirname(__DIR__, 2)))->create();
        if (!$apply) { $pdo->exec('SET SESSION TRANSACTION READ ONLY'); }
    }
    if (!$apply) { $pdo->beginTransaction(); }
    $migration = new ForgottenMannyMigration($pdo, $options['universe-path']);
    $resolutions = isset($options['resolutions']) ? json_decode(file_get_contents($options['resolutions']), true, 512, JSON_THROW_ON_ERROR) : [];
    if ($apply) { $result = $migration->apply($options['manifest'], $resolutions); }
    else { $plan = $migration->plan($resolutions); $result = ['apply' => false, 'mannies' => count($plan['entries']), 'files' => count($plan['files']), 'entries' => $plan['entries']]; }
    if (!$apply) { $pdo->rollBack(); }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Forgotten Manny migration SQL failure.\n" : $error->getMessage() . "\n");
    exit(1);
}
