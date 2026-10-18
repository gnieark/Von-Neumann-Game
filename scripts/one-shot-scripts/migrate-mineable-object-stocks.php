<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\Migration\MaintenanceFiles;
use VonNeumannGame\Database\Migration\MineableStockMigration;
use VonNeumannGame\Database\Migration\MineableStockMigrationPlan;
use VonNeumannGame\Database\Migration\WorldMaintenanceBackup;

$usage = "Usage: php scripts/one-shot-scripts/migrate-mineable-object-stocks.php --database-config=PATH --universe-path=PATH [--resolutions=PATH] [--apply|--verify|--restore|--schema-only] [--manifest-dir=PATH] [--maintenance-confirmed]\nDry run by default. Application/restoration requires ALL writers stopped and drained. The manifest directory contains the full backup, occurrence plan and receipts; keep it private and outside the universe. --restore replaces SQL and sector files together, only before reopening the game.\n";
$options = []; $mode = 'dry-run'; $modeSeen = false; $maintenance = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') { echo $usage; exit(0); }
    if ($argument === '--maintenance-confirmed') { $maintenance = true; }
    elseif (in_array($argument, ['--apply', '--verify', '--restore', '--schema-only', '--dry-run'], true)) {
        if ($modeSeen) { fwrite(STDERR, $usage); exit(2); } $mode = substr($argument, 2); $modeSeen = true;
    } elseif (preg_match('/^--(database-config|universe-path|resolutions|manifest-dir)=(.+)$/D', $argument, $m) && !isset($options[$m[1]])) { $options[$m[1]] = $m[2]; }
    else { fwrite(STDERR, $usage); exit(2); }
}
if (!isset($options['database-config'], $options['universe-path'])
    || (in_array($mode, ['apply','restore','schema-only'], true) && !$maintenance)
    || (in_array($mode, ['apply','verify','restore'], true) && !isset($options['manifest-dir']))) { fwrite(STDERR, $usage); exit(2); }
try {
    $started = microtime(true);
    $config = DatabaseConfig::fromFile($options['database-config']);
    $universe = realpath($options['universe-path']);
    if ($universe === false || !is_dir($universe . '/sectors')) { throw new RuntimeException('Universe must contain an existing sectors directory.'); }
    if (in_array($mode, ['dry-run','verify'], true) && $config->driver === 'sqlite') {
        $path = str_starts_with($config->path ?? '', '/') ? $config->path : dirname(__DIR__, 2) . '/' . $config->path;
        $pdo = new PDO('sqlite:file:' . str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $path) . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } else { $pdo = (new DatabaseConnectionFactory($config, dirname(__DIR__, 2)))->create(); }
    if (in_array($mode, ['dry-run','verify'], true)) { $pdo->exec($config->driver === 'sqlite' ? 'PRAGMA query_only=ON' : 'SET SESSION TRANSACTION READ ONLY'); }
    $migration = new MineableStockMigration($pdo, $universe, progress: static fn(string $message) => fwrite(STDERR, gmdate('c') . ' ' . $message . "\n"));
    $directory = $options['manifest-dir'] ?? null;
    if ($mode === 'schema-only') { $migration->schema(); $result = ['schemaReady' => true, 'dataConverted' => false]; }
    elseif ($mode === 'restore') {
        (new WorldMaintenanceBackup($pdo, $universe))->restore($directory . '/backup');
        $result = ['restored' => true, 'next' => 'Restore the previous code before reopening; keep this manifest as evidence and use a new directory for another rehearsal.'];
    } elseif ($mode === 'verify') { $result = $migration->verify($directory); }
    else {
        $resolutions = isset($options['resolutions']) ? MaintenanceFiles::read($options['resolutions']) : [];
        if ($mode === 'dry-run') {
            $plan = (new MineableStockMigrationPlan($pdo, $universe))->build('dry-run', resolutions: $resolutions);
            $result = ['apply' => false, 'sources' => count($plan['sources']), 'files' => count($plan['allFiles']), 'totals' => $plan['totals'], 'counts' => $plan['counts'], 'normalizations' => $plan['normalizations'],
                'renamings' => array_values(array_filter($plan['sources'], fn($s) => $s['newId'] !== $s['objectId'])), 'failedMannyEvents' => count($plan['failedEvents'])];
        } else {
            $result = $migration->apply($directory, $resolutions, static function (string $id, array $events) use ($pdo, $universe): array {
                $sectors = new \VonNeumannGame\Sector\SectorService(new \VonNeumannGame\Sector\SectorFileRepository($universe), new \VonNeumannGame\Sector\SectorContentGenerator(), 'maintenance', $pdo);
                $service = (new \VonNeumannGame\AppFactory(dirname(__DIR__, 2)))->mannyServiceForCleanup($pdo, $sectors);
                $result = (new \VonNeumannGame\Database\Migration\FailedMannyMigration($pdo, $service))->apply($id, $events);
                // Only durable publications produced by business recalls; never run the general scheduler.
                $effects = new \VonNeumannGame\Service\SectorEffectService(new \VonNeumannGame\Repository\Storage\SectorEffectRepository($pdo), new \VonNeumannGame\Repository\ScheduledEventRepository($pdo), $sectors);
                foreach ($pdo->query("SELECT id FROM sector_effects WHERE status='pending' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $effectId) { $effects->deliverAfterCommit((int) $effectId); }
                return $result;
            });
        }
    }
    $result['execution'] = ['seconds' => round(microtime(true) - $started, 3), 'peakMemoryBytes' => memory_get_peak_usage(true)];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Migration SQL failure; check the explicit copy configuration and schema. Keep writers stopped.\n" : $error->getMessage() . "\n");
    exit(1);
}
