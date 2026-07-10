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
    $deletedEmptyOrphans = 0;
    $skippedNonEmptyOrphans = 0;

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
    foreach (relinkAdditionalContainersOrphanContainers($pdo) as $orphan) {
        if (
            (float) $orphan['resource_total'] > 0.0
            || (int) $orphan['item_count'] > 0
            || (int) $orphan['manny_count'] > 0
        ) {
            $skippedNonEmptyOrphans++;
            continue;
        }

        $delete = $pdo->prepare('DELETE FROM storage_containers WHERE id = :id');
        $delete->execute(['id' => (int) $orphan['id']]);
        $deletedEmptyOrphans += $delete->rowCount();
    }

    if ($options['dryRun']) {
        $pdo->rollBack();
        echo "Dry run: {$moved} additional container item(s) would be relinked to probe-core across {$probesTouched} probe(s); {$deletedEmptyOrphans} empty orphan container(s) would be removed; {$skippedNonEmptyOrphans} non-empty orphan container(s) would be left for manual review.\n";
        exit(0);
    }

    $pdo->commit();
    echo "Relinked {$moved} additional container item(s) to probe-core across {$probesTouched} probe(s); removed {$deletedEmptyOrphans} empty orphan container(s); left {$skippedNonEmptyOrphans} non-empty orphan container(s) for manual review.\n";
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

/**
 * @return array<int, array{id:int, probe_id:int, uid:string, label:string, resource_total:float, item_count:int, manny_count:int}>
 */
function relinkAdditionalContainersOrphanContainers(PDO $pdo): array
{
    $containers = $pdo->query(
        'SELECT id, probe_id, uid, label
         FROM storage_containers
         WHERE kind = \'container\'
           AND uid LIKE \'container-%\'
         ORDER BY probe_id ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $itemsByProbeUid = [];
    $items = $pdo->query(
        'SELECT probe_id, uid
         FROM probe_items
         WHERE type = \'additional_container\''
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $itemsByProbeUid[(int) $item['probe_id']][(string) $item['uid']] = true;
    }

    $orphans = [];
    foreach ($containers as $container) {
        $uid = (string) $container['uid'];
        $probeId = (int) $container['probe_id'];
        $backingUid = substr($uid, strlen('container-'));
        if (isset($itemsByProbeUid[$probeId][$backingUid])) {
            continue;
        }

        $containerId = (int) $container['id'];
        $resources = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM storage_container_resources WHERE container_id = :id');
        $resources->execute(['id' => $containerId]);
        $items = $pdo->prepare('SELECT COUNT(*) FROM probe_items WHERE storage_container_id = :id');
        $items->execute(['id' => $containerId]);
        $mannies = $pdo->prepare('SELECT COUNT(*) FROM mannies WHERE storage_container_id = :id');
        $mannies->execute(['id' => $containerId]);

        $orphans[] = [
            'id' => $containerId,
            'probe_id' => $probeId,
            'uid' => $uid,
            'label' => (string) $container['label'],
            'resource_total' => (float) $resources->fetchColumn(),
            'item_count' => (int) $items->fetchColumn(),
            'manny_count' => (int) $mannies->fetchColumn(),
        ];
    }

    return $orphans;
}

function relinkAdditionalContainersUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/relink-additional-containers-to-core.php [--database-config=<path>] [--dry-run]

Moves onboard additional_container items back to the probe internal storage
container (probe-core). Detached containers and hidden asteroid containers are
not stored in probe_items and are not changed by this script. Empty onboard
storage containers whose backing additional_container item is missing are also
removed; non-empty orphan containers are reported and left untouched.

Options:
  --database-config=<path>  Use another database config.
  --dry-run                 Show the number of affected rows and roll back.
  -h, --help                Show this help.

TEXT;
}
