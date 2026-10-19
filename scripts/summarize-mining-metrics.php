<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$usage = "Usage: php scripts/summarize-mining-metrics.php --log-file=PATH [--window-seconds=N]\nJournal scheduler brut (journalctl -o cat), fenêtre sélectionnée par l'opérateur. Aucun accès SQL. Codes : 0 mesures sans alerte, 1 alerte/mesures absentes ou invalides, 2 échec permanent ou lecture impossible.\n";
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') { echo $usage; exit(0); }
    if (preg_match('/^--(log-file|window-seconds)=(.+)$/D', $argument, $match) && !isset($options[$match[1]])) { $options[$match[1]] = $match[2]; }
    else { fwrite(STDERR, $usage); exit(2); }
}
if (!isset($options['log-file'])) { fwrite(STDERR, $usage); exit(2); }
try {
    $window = isset($options['window-seconds']) ? filter_var($options['window-seconds'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
    if ($window === false) { throw new InvalidArgumentException('Durée de fenêtre invalide.'); }
    $handle = @fopen($options['log-file'], 'rb');
    if ($handle === false) { throw new RuntimeException('Journal illisible.'); }
    try { $result = (new \VonNeumannGame\Operations\MiningMetricsSummary())->read($handle, $window); }
    finally { fclose($handle); }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($result['outcomes']['permanent_failure'] > 0 ? 2 : (($result['records'] === 0 || $result['malformedRecords'] > 0 || $result['conflictAlerts'] > 0) ? 1 : 0));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}
