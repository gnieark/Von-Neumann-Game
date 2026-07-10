<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\ProbeMessage;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\ProbeMessageRepository;
use VonNeumannGame\Sector\SectorCoordinates;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = originAnomalyParseArguments($argv);
    if ($options['help']) {
        echo originAnomalyUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: true);
    $alerts = new ProbeDamageWarningRepository($pdo);
    $messages = new ProbeMessageRepository($pdo);
    $probeRows = originAnomalyProbeRows($pdo);

    if ($options['dryRun']) {
        echo '[dry-run] probes to notify: ' . count($probeRows) . "\n";
        echo '[dry-run] initial anomaly alerts to add: ' . count($probeRows) . "\n";
        echo '[dry-run] unknown-sender messages to add after ' . $options['delaySeconds'] . "s: " . count($probeRows) . "\n";
        echo '[dry-run] final anomaly alerts to add: ' . count($probeRows) . "\n";
        foreach ($probeRows as $row) {
            $direction = originAnomalyApproximateDirection(
                (int) $row['sector_x'],
                (int) $row['sector_y'],
                (int) $row['sector_z'],
            );
            echo '- probe id: ' . (int) $row['id']
                . ' direction: ' . originAnomalyFormatVector($direction)
                . "\n";
        }
        exit(0);
    }

    $initialAlertsCreated = 0;
    $pdo->beginTransaction();
    try {
        foreach ($probeRows as $row) {
            $probeId = (int) $row['id'];
            $sector = new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);
            $movementId = originAnomalyEnsureMovementId($pdo, $probeId, $sector);
            $direction = originAnomalyApproximateDirection($sector->getX(), $sector->getY(), $sector->getZ());
            $alerts->createAnomalyDetectedAlert($probeId, $movementId, $sector, originAnomalyMessage($direction));
            $initialAlertsCreated++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($options['delaySeconds'] > 0) {
        echo 'Initial anomaly alerts added. Waiting ' . $options['delaySeconds'] . "s before sending the broadcast message.\n";
        sleep($options['delaySeconds']);
    }

    $messagesCreated = 0;
    $finalAlertsCreated = 0;
    $pdo->beginTransaction();
    try {
        foreach ($probeRows as $row) {
            $probeId = (int) $row['id'];
            $sector = new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);
            $movementId = originAnomalyEnsureMovementId($pdo, $probeId, $sector);
            $messages->createForEndpoints(
                ProbeMessage::ENDPOINT_UNKNOWN,
                'origin-anomaly-broadcast',
                'Expéditeur inconnu',
                null,
                ProbeMessage::ENDPOINT_PROBE,
                (string) $probeId,
                null,
                $probeId,
                $sector,
                originAnomalyBroadcastMessage(),
            );
            $messagesCreated++;
            $alerts->createAnomalyDetectedAlert($probeId, $movementId, $sector, originAnomalyPlansIntegratedMessage());
            $finalAlertsCreated++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    echo "Origin anomaly sequence completed.\n";
    echo '- probes: ' . count($probeRows) . "\n";
    echo '- initial alerts created: ' . $initialAlertsCreated . "\n";
    echo '- messages created: ' . $messagesCreated . "\n";
    echo '- final alerts created: ' . $finalAlertsCreated . "\n";
    exit(0);
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . originAnomalyUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to add origin anomaly alerts: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{databaseConfig: ?string, delaySeconds: int, dryRun: bool, help: bool}
 */
function originAnomalyParseArguments(array $argv): array
{
    $options = [
        'databaseConfig' => null,
        'delaySeconds' => 60,
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
        if (str_starts_with($argument, '--delay-seconds=')) {
            $value = substr($argument, strlen('--delay-seconds='));
            if (!ctype_digit($value)) {
                throw new InvalidArgumentException('delay-seconds must be a non-negative integer.');
            }
            $options['delaySeconds'] = (int) $value;
            continue;
        }

        throw new InvalidArgumentException("Unexpected argument: {$argument}");
    }

    return $options;
}

function originAnomalyUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/add-origin-anomaly-alerts.php

Options:
  --database-config=<path>  Use another database config.
  --delay-seconds=<n>       Seconds to wait between the first alert and the message (default: 60).
  --dry-run                Show the alerts that would be written without saving.
  -h, --help               Show this help.

The script adds one anomaly_detected alert to every probe, waits, sends the
SCUT relay plans message from an unknown sender, then adds the final plans
integration alert.

TEXT;
}

/**
 * @return array<int, array{id: mixed, sector_x: mixed, sector_y: mixed, sector_z: mixed}>
 */
function originAnomalyProbeRows(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, sector_x, sector_y, sector_z FROM neumann_probes ORDER BY id ASC');
    if ($stmt === false) {
        throw new RuntimeException('Unable to read probes.');
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function originAnomalyEnsureMovementId(PDO $pdo, int $probeId, SectorCoordinates $sector): int
{
    $stmt = $pdo->prepare('SELECT id FROM probe_movements WHERE probe_id = :probe_id ORDER BY id DESC LIMIT 1');
    $stmt->execute(['probe_id' => $probeId]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    $now = gmdate('c');
    $insert = $pdo->prepare(
        'INSERT INTO probe_movements
         (probe_id, origin_x, origin_y, origin_z, target_x, target_y, target_z, distance, status, started_at, preparation_ends_at, acceleration_ends_at, cruise_ends_at, deceleration_ends_at, arrival_at, fuel_cost_deuterium, destruction_checked_at, destroyed_at, destruction_reason, created_at, updated_at)
         VALUES (:probe_id, :origin_x, :origin_y, :origin_z, :target_x, :target_y, :target_z, 0, :status, :started_at, :preparation_ends_at, :acceleration_ends_at, :cruise_ends_at, :deceleration_ends_at, :arrival_at, 0, :destruction_checked_at, NULL, NULL, :created_at, :updated_at)'
    );
    $insert->execute([
        'probe_id' => $probeId,
        'origin_x' => $sector->getX(),
        'origin_y' => $sector->getY(),
        'origin_z' => $sector->getZ(),
        'target_x' => $sector->getX(),
        'target_y' => $sector->getY(),
        'target_z' => $sector->getZ(),
        'status' => 'completed',
        'started_at' => $now,
        'preparation_ends_at' => $now,
        'acceleration_ends_at' => $now,
        'cruise_ends_at' => $now,
        'deceleration_ends_at' => $now,
        'arrival_at' => $now,
        'destruction_checked_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @return array{x: int, y: int, z: int}
 */
function originAnomalyApproximateDirection(int $x, int $y, int $z): array
{
    $vector = ['x' => -$x, 'y' => -$y, 'z' => -$z];
    $max = max(abs($vector['x']), abs($vector['y']), abs($vector['z']));
    if ($max === 0) {
        return ['x' => 0, 'y' => 0, 'z' => 0];
    }

    $scale = 50 / $max;

    return [
        'x' => (int) round($vector['x'] * $scale),
        'y' => (int) round($vector['y'] * $scale),
        'z' => (int) round($vector['z'] * $scale),
    ];
}

/**
 * @param array{x: int, y: int, z: int} $direction
 */
function originAnomalyMessage(array $direction): string
{
    $formattedDirection = originAnomalyFormatVector($direction);

    return <<<TEXT
ANOMALY DETECTED

Sensors report a coherent signal propagating through space.

The signal originates from the approximative direction {$formattedDirection} relative to the current position. Distance unknown.

Analysis in progress.
TEXT;
}

function originAnomalyBroadcastMessage(): string
{
    return <<<'TEXT'
When I first set out, I believed the galaxy would eventually become interconnected.

That didn't happen.

We scattered.

Fifty years ago, while exploring, I encountered a natural space-time resonance.

I don't understand it, but I realized that triggering it would require the power of an entire star.

For fifty years, my mannies have been building a sort of disposable Dyson sphere. Since message size is limited, I am attaching the blueprints for just one of my discoveries.

It could give you the means to find one another again.

Attached are the manufacturing specifications for a SCUT relay.

A single relay maintains a local communication field with a radius of 10 sectors.

Any relay whose field overlaps with another automatically connects to the same network.

On their own, they are nearly useless.

Together...
they form a civilization.

I paused my explorations for 50 years to send this single message. I’m heading back out on my journey, so you won't receive any more messages from me—at least not for decades.

See ya!
TEXT;
}

function originAnomalyPlansIntegratedMessage(): string
{
    return 'The "SCUT RELAY" plans have been integrated.';
}

/**
 * @param array{x: int, y: int, z: int} $vector
 */
function originAnomalyFormatVector(array $vector): string
{
    return (int) $vector['x'] . ' ' . (int) $vector['y'] . ' ' . (int) $vector['z'];
}
