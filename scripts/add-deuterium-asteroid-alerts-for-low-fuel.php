<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = lowFuelAsteroidsParseArguments($argv);
    if ($options['help']) {
        echo lowFuelAsteroidsUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: true);
    $rows = lowFuelAsteroidsRows($pdo, $options['threshold']);
    $script = __DIR__ . '/add-deuterium-asteroid-alert.php';
    $successes = 0;
    $failures = 0;

    echo 'Players below ' . $options['threshold'] . "% deuterium: " . count($rows) . "\n";
    foreach ($rows as $row) {
        $username = (string) $row['username'];
        $command = lowFuelAsteroidsBuildCommand($script, $username, $options);
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);
        echo '== ' . $username . ' (' . round((float) $row['deuterium_stock'], 4) . "%) ==\n";
        echo implode("\n", $output) . ($output === [] ? '' : "\n");
        if ($status === 0) {
            $successes++;
        } else {
            $failures++;
        }
    }

    echo "processed: {$successes}\n";
    echo "failed: {$failures}\n";
    exit($failures > 0 ? 1 : 0);
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . lowFuelAsteroidsUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to add low-fuel deuterium asteroids: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{threshold: float, databaseConfig: ?string, universePath: ?string, amount: float, dryRun: bool, help: bool}
 */
function lowFuelAsteroidsParseArguments(array $argv): array
{
    $options = [
        'threshold' => -1.0,
        'databaseConfig' => null,
        'universePath' => null,
        'amount' => 10.0,
        'dryRun' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }
        if (str_starts_with($argument, '--database-config=')) {
            $value = substr($argument, strlen('--database-config='));
            $options['databaseConfig'] = $value !== '' ? $value : null;
            continue;
        }
        if (str_starts_with($argument, '--universe-path=')) {
            $value = substr($argument, strlen('--universe-path='));
            $options['universePath'] = $value !== '' ? $value : null;
            continue;
        }
        if (str_starts_with($argument, '--amount=')) {
            $options['amount'] = lowFuelAsteroidsPositiveFloat(substr($argument, strlen('--amount=')), 'amount');
            continue;
        }
        if (str_starts_with($argument, '--threshold=')) {
            $options['threshold'] = lowFuelAsteroidsPercent(substr($argument, strlen('--threshold=')));
            continue;
        }
        if ($options['threshold'] < 0.0) {
            $options['threshold'] = lowFuelAsteroidsPercent($argument);
            continue;
        }

        throw new InvalidArgumentException("Unexpected argument: {$argument}");
    }

    if (!$options['help'] && $options['threshold'] < 0.0) {
        throw new InvalidArgumentException('Missing deuterium threshold.');
    }

    return $options;
}

function lowFuelAsteroidsUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/add-deuterium-asteroid-alerts-for-low-fuel.php <threshold-percent>
  php scripts/add-deuterium-asteroid-alerts-for-low-fuel.php --threshold=<threshold-percent>

Options:
  --database-config=<path>  Use another database config.
  --universe-path=<path>    Use another universe storage path.
  --amount=<n>             Deuterium reserve per asteroid (default: 10).
  --dry-run                Call the per-player script without saving.
  -h, --help               Show this help.

The script calls scripts/add-deuterium-asteroid-alert.php once per matching player.

TEXT;
}

function lowFuelAsteroidsPercent(string $value): float
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('threshold must be a number.');
    }
    $threshold = (float) $value;
    if ($threshold < 0.0 || $threshold > 100.0) {
        throw new InvalidArgumentException('threshold must be between 0 and 100.');
    }

    return round($threshold, 4);
}

function lowFuelAsteroidsPositiveFloat(string $value, string $label): float
{
    if (!is_numeric($value) || (float) $value <= 0.0) {
        throw new InvalidArgumentException("{$label} must be a positive number.");
    }

    return round((float) $value, 4);
}

/**
 * @return list<array<string, mixed>>
 */
function lowFuelAsteroidsRows(PDO $pdo, float $threshold): array
{
    $stmt = $pdo->prepare(
        'SELECT p.username, np.deuterium_stock
         FROM players p
         INNER JOIN neumann_probes np ON np.player_id = p.id
         WHERE np.deuterium_stock < :threshold
         ORDER BY p.username ASC'
    );
    $stmt->execute(['threshold' => $threshold]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param array{databaseConfig: ?string, universePath: ?string, amount: float, dryRun: bool} $options
 */
function lowFuelAsteroidsBuildCommand(string $script, string $username, array $options): string
{
    $parts = [
        escapeshellarg(PHP_BINARY),
        escapeshellarg($script),
        escapeshellarg('--username=' . $username),
        escapeshellarg('--amount=' . (string) $options['amount']),
    ];
    if ($options['databaseConfig'] !== null) {
        $parts[] = escapeshellarg('--database-config=' . $options['databaseConfig']);
    }
    if ($options['universePath'] !== null) {
        $parts[] = escapeshellarg('--universe-path=' . $options['universePath']);
    }
    if ($options['dryRun']) {
        $parts[] = '--dry-run';
    }

    return implode(' ', $parts);
}
