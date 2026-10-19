<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\Migration\MineableStockMigration;
use VonNeumannGame\Operations\MineableCutoverMonitor;

$usage = "Usage: php scripts/check-mineable-cutover.php --database-config=PATH --universe-path=PATH --manifest-dir=PATH --since=UTC_ISO8601 [--before-open] [--lease-seconds=3600] [--alert-seconds=900]\nLecture seule. --before-open vérifie aussi les valeurs et fichiers du manifeste : uniquement avant reprise du jeu. Codes : 0 aucun signal, 1 avertissement, 2 anomalie ou contrôle impossible.\n";
$options = []; $beforeOpen = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') { echo $usage; exit(0); }
    if ($argument === '--before-open' && !$beforeOpen) { $beforeOpen = true; }
    elseif (preg_match('/^--(database-config|universe-path|manifest-dir|since|lease-seconds|alert-seconds)=(.+)$/D', $argument, $match) && !isset($options[$match[1]])) { $options[$match[1]] = $match[2]; }
    else { fwrite(STDERR, $usage); exit(2); }
}
foreach (['database-config', 'universe-path', 'manifest-dir', 'since'] as $required) {
    if (!isset($options[$required])) { fwrite(STDERR, $usage); exit(2); }
}
$pdo = null;
try {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/D', $options['since'])) { throw new InvalidArgumentException('Date --since attendue en UTC ISO 8601.'); }
    $since = new DateTimeImmutable($options['since']);
    if ($since->format('Y-m-d\TH:i:s') !== substr($options['since'], 0, 19)) { throw new InvalidArgumentException('Date --since invalide.'); }
    foreach (['lease-seconds' => 3600, 'alert-seconds' => 900] as $key => $default) {
        $options[$key] = filter_var($options[$key] ?? $default, FILTER_VALIDATE_INT, ['options' => ['min_range' => $key === 'lease-seconds' ? 60 : 1]]);
        if ($options[$key] === false) { throw new InvalidArgumentException('Seuil de surveillance invalide.'); }
    }
    $universe = realpath($options['universe-path']);
    if ($universe === false || !is_dir($universe . '/sectors')) { throw new InvalidArgumentException('Répertoire de secteurs introuvable.'); }
    $config = DatabaseConfig::fromFile($options['database-config']);
    if ($config->driver === 'sqlite') {
        $path = str_starts_with($config->path ?? '', '/') ? $config->path : dirname(__DIR__) . '/' . $config->path;
        $pdo = new PDO('sqlite:file:' . str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $path) . '?mode=ro');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA query_only=ON');
    } else {
        $pdo = (new DatabaseConnectionFactory($config, dirname(__DIR__)))->create();
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    }
    $pdo->beginTransaction();
    $monitor = new MineableCutoverMonitor($pdo);
    $migrationId = $monitor->assertManifest($options['manifest-dir'], $universe);
    $verification = $beforeOpen ? (new MineableStockMigration($pdo, $universe))->verify($options['manifest-dir']) : null;
    $report = $monitor->collect(time(), $since->getTimestamp(), $options['lease-seconds'], $options['alert-seconds']);
    $pdo->rollBack();
    $report['migrationId'] = $migrationId;
    $report['beforeOpenVerification'] = $verification;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(match ($report['status']) { 'ok' => 0, 'warning' => 1, default => 2 });
} catch (Throwable $error) {
    if ($pdo?->inTransaction()) { $pdo->rollBack(); }
    // PDO errors can contain connection details; a failed check must never look green.
    fwrite(STDERR, $error instanceof PDOException ? "Contrôle SQL impossible ; vérifier la configuration explicite et le schéma.\n" : $error->getMessage() . "\n");
    exit(2);
}
