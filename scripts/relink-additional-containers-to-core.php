<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\StorageContainerRepository;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = relinkAdditionalContainersParseArguments($argv);
    if ($options['help']) {
        echo relinkAdditionalContainersUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: false);
    $gameplayConfig = $factory->gameplayConfig();
    $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
    $containers = new StorageContainerRepository($pdo, $gameplayConfig);

    $rows = relinkAdditionalContainersAffectedProbes($pdo);
    $moved = 0;
    $probesTouched = 0;

    $pdo->beginTransaction();
    foreach ($rows as $row) {
        $probe = $probes->findById((int) $row['probe_id']);
        if ($probe === null) {
            continue;
        }

        $core = $containers->ensureCoreContainer($probe);
        $stmt = $pdo->prepare(
            'UPDATE probe_items
             SET storage_container_id = :core_id, updated_at = :updated_at
             WHERE probe_id = :probe_id
               AND type = :type
               AND (storage_container_id IS NULL OR storage_container_id <> :core_id)'
        );
        $stmt->execute([
            'core_id' => $core->id,
            'updated_at' => gmdate('c'),
            'probe_id' => $probe->id,
            'type' => ProbeItem::TYPE_ADDITIONAL_CONTAINER,
        ]);
        $count = $stmt->rowCount();
        if ($count > 0) {
            $moved += $count;
            $probesTouched++;
        }
    }

    if ($options['dryRun']) {
        $pdo->rollBack();
        echo "Dry run: {$moved} additional container item(s) would be relinked to probe-core across {$probesTouched} probe(s).\n";
        exit(0);
    }

    $pdo->commit();
    echo "Relinked {$moved} additional container item(s) to probe-core across {$probesTouched} probe(s).\n";
    exit(0);
} catch (InvalidArgumentException | RuntimeException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n\n" . relinkAdditionalContainersUsage());
    exit(1);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Unable to relink additional containers: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{databaseConfig:?string,dryRun:bool,help:bool}
 */
function relinkAdditionalContainersParseArguments(array $argv): array
{
    $options = [
        'databaseConfig' => null,
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

        throw new InvalidArgumentException("Unexpected argument: {$argument}");
    }

    return $options;
}

/**
 * @return array<int, array{probe_id:int}>
 */
function relinkAdditionalContainersAffectedProbes(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT i.probe_id
         FROM probe_items i
         LEFT JOIN storage_containers c
           ON c.probe_id = i.probe_id
          AND c.uid = :core_uid
         WHERE i.type = :type
           AND (i.storage_container_id IS NULL OR c.id IS NULL OR i.storage_container_id <> c.id)
         ORDER BY i.probe_id ASC'
    );
    $stmt->execute([
        'core_uid' => 'probe-core',
        'type' => ProbeItem::TYPE_ADDITIONAL_CONTAINER,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function relinkAdditionalContainersUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/relink-additional-containers-to-core.php [--database-config=<path>] [--dry-run]

Moves onboard additional_container items back to the probe internal storage
container (probe-core). Detached containers and hidden asteroid containers are
not stored in probe_items and are not changed by this script.

Options:
  --database-config=<path>  Use another database config.
  --dry-run                 Show the number of affected rows and roll back.
  -h, --help                Show this help.

TEXT;
}
