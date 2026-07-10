<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Domain\StorageContainer;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorCoordinates;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = userinfosParseArguments($argv);
    if ($options['help']) {
        echo userinfosUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: false);
    $gameplayConfig = $factory->gameplayConfig();
    $players = new PlayerRepository($pdo);
    $probes = new NeumannProbeRepository($pdo, $gameplayConfig);

    [$player, $probe, $ownedProbes] = userinfosResolveSubject($players, $probes, $options);
    if ($player === null) {
        throw new RuntimeException('Player not found.');
    }
    if ($probe === null) {
        throw new RuntimeException('Probe not found for player #' . $player->id . ' (' . $player->username . ').');
    }

    $report = userinfosBuildReport($pdo, $player, $probe, $ownedProbes, $gameplayConfig, $options['limit']);

    if ($options['json']) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }

    echo userinfosRenderReport($report);
    exit(0);
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . userinfosUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to collect user infos: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{
 *     subject: ?string,
 *     probeId: ?int,
 *     playerId: ?int,
 *     username: ?string,
 *     databaseConfig: ?string,
 *     limit: int,
 *     json: bool,
 *     help: bool
 * }
 */
function userinfosParseArguments(array $argv): array
{
    $options = [
        'subject' => null,
        'probeId' => null,
        'playerId' => null,
        'username' => null,
        'databaseConfig' => null,
        'limit' => 25,
        'json' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($argument === '--json') {
            $options['json'] = true;
            continue;
        }
        if (str_starts_with($argument, '--database-config=')) {
            $value = substr($argument, strlen('--database-config='));
            $options['databaseConfig'] = $value !== '' ? $value : null;
            continue;
        }
        if (str_starts_with($argument, '--limit=')) {
            $options['limit'] = userinfosPositiveInt(substr($argument, strlen('--limit=')), 'limit');
            continue;
        }
        if (str_starts_with($argument, '--probe-id=')) {
            $options['probeId'] = userinfosPositiveInt(substr($argument, strlen('--probe-id=')), 'probe id');
            continue;
        }
        if (str_starts_with($argument, '--player-id=')) {
            $options['playerId'] = userinfosPositiveInt(substr($argument, strlen('--player-id=')), 'player id');
            continue;
        }
        if (str_starts_with($argument, '--username=')) {
            $options['username'] = substr($argument, strlen('--username='));
            continue;
        }
        if ($options['subject'] === null) {
            $options['subject'] = $argument;
            continue;
        }

        throw new InvalidArgumentException("Unexpected argument: {$argument}");
    }

    if (
        !$options['help']
        && $options['subject'] === null
        && $options['probeId'] === null
        && $options['playerId'] === null
        && $options['username'] === null
    ) {
        throw new InvalidArgumentException('Missing subject.');
    }

    return $options;
}

function userinfosUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/userinfos.php <username>
  php scripts/userinfos.php <probe-id>
  php scripts/userinfos.php --probe-id=<probe-id>
  php scripts/userinfos.php --player-id=<player-id>
  php scripts/userinfos.php --username=<username>

Options:
  --database-config=<path>  Use another database config, useful for a prod copy.
  --limit=<n>              Number of history rows per section (default: 25).
  --json                   Emit the raw report as JSON.
  -h, --help               Show this help.

The report is read-only. Numeric positional subjects are treated as probe ids.

TEXT;
}

function userinfosPositiveInt(string $value, string $label): int
{
    if (preg_match('/\A\d+\z/', $value) !== 1 || (int) $value <= 0) {
        throw new InvalidArgumentException("Invalid {$label}.");
    }

    return (int) $value;
}

/**
 * @return array{0: ?\VonNeumannGame\Domain\Player, 1: ?\VonNeumannGame\Domain\NeumannProbe, 2: array<int, \VonNeumannGame\Domain\NeumannProbe>}
 */
function userinfosResolveSubject(PlayerRepository $players, NeumannProbeRepository $probes, array $options): array
{
    $probe = null;
    $player = null;
    $ownedProbes = [];

    if ($options['probeId'] !== null) {
        $probe = $probes->findById($options['probeId']);
        $player = $probe !== null ? $players->findById($probe->playerId) : null;
        $ownedProbes = $player !== null ? $probes->findAllByPlayerId($player->id) : [];
        return [$player, $probe, $ownedProbes];
    }

    if ($options['playerId'] !== null) {
        $player = $players->findById($options['playerId']);
        $ownedProbes = $player !== null ? $probes->findAllByPlayerId($player->id) : [];
        $probe = userinfosDefaultProbe($player, $ownedProbes);
        return [$player, $probe, $ownedProbes];
    }

    if ($options['username'] !== null) {
        $player = $players->findByUsername((string) $options['username']);
        $ownedProbes = $player !== null ? $probes->findAllByPlayerId($player->id) : [];
        $probe = userinfosDefaultProbe($player, $ownedProbes);
        return [$player, $probe, $ownedProbes];
    }

    $subject = (string) $options['subject'];
    if (str_starts_with($subject, 'probe:')) {
        $probe = $probes->findById(userinfosPositiveInt(substr($subject, 6), 'probe id'));
        $player = $probe !== null ? $players->findById($probe->playerId) : null;
        $ownedProbes = $player !== null ? $probes->findAllByPlayerId($player->id) : [];
        return [$player, $probe, $ownedProbes];
    }
    if (str_starts_with($subject, 'player:')) {
        $player = $players->findById(userinfosPositiveInt(substr($subject, 7), 'player id'));
        $ownedProbes = $player !== null ? $probes->findAllByPlayerId($player->id) : [];
        $probe = userinfosDefaultProbe($player, $ownedProbes);
        return [$player, $probe, $ownedProbes];
    }
    if (preg_match('/\A#?(\d+)\z/', $subject, $matches) === 1) {
        $probe = $probes->findById((int) $matches[1]);
        $player = $probe !== null ? $players->findById($probe->playerId) : null;
        $ownedProbes = $player !== null ? $probes->findAllByPlayerId($player->id) : [];
        return [$player, $probe, $ownedProbes];
    }

    $player = $players->findByUsername($subject);
    $ownedProbes = $player !== null ? $probes->findAllByPlayerId($player->id) : [];
    $probe = userinfosDefaultProbe($player, $ownedProbes);

    return [$player, $probe, $ownedProbes];
}

function userinfosDefaultProbe(?object $player, array $ownedProbes): ?object
{
    if ($player === null || $ownedProbes === []) {
        return null;
    }

    foreach ($ownedProbes as $probe) {
        if ($player->defaultProbeId !== null && $probe->id === $player->defaultProbeId) {
            return $probe;
        }
    }

    return $ownedProbes[0];
}

function userinfosBuildReport(PDO $pdo, object $player, object $probe, array $ownedProbes, array $gameplayConfig, int $limit): array
{
    $frame = new PlayerReferenceFrame($player->homeSector);
    $relative = $frame->globalToRelative($probe->currentSector);
    $home = userinfosCoordinatesArray($player->homeSector);
    $current = userinfosCoordinatesArray($probe->currentSector);
    $visitedCurrent = userinfosSingleRow(
        $pdo,
        'SELECT * FROM visited_sectors
         WHERE player_id = :player_id AND sector_x = :x AND sector_y = :y AND sector_z = :z',
        [
            'player_id' => $player->id,
            'x' => $probe->currentSector->getX(),
            'y' => $probe->currentSector->getY(),
            'z' => $probe->currentSector->getZ(),
        ],
    );
    $authMethods = userinfosRows(
        $pdo,
        'SELECT id, player_id, provider, provider_user_id, password_hash IS NOT NULL AS has_password_hash, created_at
         FROM player_auth_methods WHERE player_id = :player_id ORDER BY id ASC',
        ['player_id' => $player->id],
    );
    $sessions = userinfosRows(
        $pdo,
        'SELECT id, created_at, expires_at, last_used_at, revoked_at
         FROM sessions WHERE player_id = :player_id ORDER BY last_used_at DESC, id DESC LIMIT :limit',
        ['player_id' => $player->id, 'limit' => $limit],
        ['limit'],
    );
    $apiKeys = userinfosRows(
        $pdo,
        'SELECT id, label, last_four, created_at, last_used_at, revoked_at
         FROM api_keys WHERE player_id = :player_id ORDER BY id DESC LIMIT :limit',
        ['player_id' => $player->id, 'limit' => $limit],
        ['limit'],
    );
    $containers = userinfosRows(
        $pdo,
        'SELECT * FROM storage_containers WHERE probe_id = :probe_id ORDER BY sort_order ASC, id ASC',
        ['probe_id' => $probe->id],
    );
    $resources = userinfosRows(
        $pdo,
        'SELECT r.container_id, r.resource_type, r.amount, r.updated_at
         FROM storage_container_resources r
         INNER JOIN storage_containers c ON c.id = r.container_id
         WHERE c.probe_id = :probe_id
         ORDER BY c.sort_order ASC, c.id ASC, r.resource_type ASC',
        ['probe_id' => $probe->id],
    );
    $items = userinfosRows(
        $pdo,
        'SELECT * FROM probe_items WHERE probe_id = :probe_id ORDER BY created_at ASC, id ASC',
        ['probe_id' => $probe->id],
    );
    $mannies = userinfosRows(
        $pdo,
        'SELECT * FROM mannies WHERE probe_id = :probe_id ORDER BY name ASC, id ASC',
        ['probe_id' => $probe->id],
    );
    $visited = userinfosRows(
        $pdo,
        'SELECT * FROM visited_sectors
         WHERE player_id = :player_id
         ORDER BY last_visited_at DESC, id DESC
         LIMIT :limit',
        ['player_id' => $player->id, 'limit' => $limit],
        ['limit'],
    );
    $movements = userinfosRows(
        $pdo,
        'SELECT * FROM probe_movements
         WHERE probe_id = :probe_id
         ORDER BY id DESC
         LIMIT :limit',
        ['probe_id' => $probe->id, 'limit' => $limit],
        ['limit'],
    );
    $damageWarnings = userinfosRows(
        $pdo,
        'SELECT * FROM probe_damage_warnings
         WHERE probe_id = :probe_id
         ORDER BY created_at DESC, id DESC
         LIMIT :limit',
        ['probe_id' => $probe->id, 'limit' => $limit],
        ['limit'],
    );
    $messagesReceived = userinfosRows(
        $pdo,
        'SELECT id, sender_probe_id, recipient_probe_id, sector_x, sector_y, sector_z, status, read_at, created_at, updated_at,
                substr(body, 1, 160) AS body_preview
         FROM probe_messages
         WHERE recipient_probe_id = :probe_id
         ORDER BY created_at DESC, id DESC
         LIMIT :limit',
        ['probe_id' => $probe->id, 'limit' => $limit],
        ['limit'],
    );
    $messagesSent = userinfosRows(
        $pdo,
        'SELECT id, sender_probe_id, recipient_probe_id, sector_x, sector_y, sector_z, status, read_at, created_at, updated_at,
                substr(body, 1, 160) AS body_preview
         FROM probe_messages
         WHERE sender_probe_id = :probe_id
         ORDER BY created_at DESC, id DESC
         LIMIT :limit',
        ['probe_id' => $probe->id, 'limit' => $limit],
        ['limit'],
    );
    $movementIds = userinfosColumn(
        $pdo,
        'SELECT id FROM probe_movements WHERE probe_id = :probe_id',
        ['probe_id' => $probe->id],
    );
    $scheduledEvents = userinfosScheduledEvents($pdo, $probe->id, $movementIds, $limit);

    $inventory = userinfosInventoryReport($probe, $gameplayConfig, $containers, $resources, $items, $mannies);
    $visitedTotal = userinfosScalar(
        $pdo,
        'SELECT COUNT(*) FROM visited_sectors WHERE player_id = :player_id',
        ['player_id' => $player->id],
    );
    $movementTotal = userinfosScalar(
        $pdo,
        'SELECT COUNT(*) FROM probe_movements WHERE probe_id = :probe_id',
        ['probe_id' => $probe->id],
    );

    return [
        'generatedAt' => gmdate('c'),
        'limit' => $limit,
        'player' => [
            'id' => $player->id,
            'username' => $player->username,
            'displayName' => $player->displayName,
            'defaultProbeId' => $player->defaultProbeId,
            'probeCount' => count($ownedProbes),
            'homeSector' => ['absolute' => $home],
            'createdAt' => $player->createdAt,
            'updatedAt' => $player->updatedAt,
            'forumAdmin' => $player->forumAdmin,
            'forumModerator' => $player->forumModerator,
            'authMethods' => $authMethods,
            'sessions' => $sessions,
            'apiKeys' => $apiKeys,
        ],
        'focusedProbeId' => $probe->id,
        'probes' => array_map(
            static fn(object $ownedProbe): array => userinfosProbeOverview($pdo, $player, $ownedProbe, $frame, $gameplayConfig, $probe->id),
            $ownedProbes,
        ),
        'probe' => [
            'id' => $probe->id,
            'name' => $probe->name,
            'playerId' => $probe->playerId,
            'position' => [
                'absolute' => $current,
                'relativeToHome' => $relative,
                'homeAbsolute' => $home,
                'isAtRelativeOrigin' => $relative === ['x' => 0, 'y' => 0, 'z' => 0],
            ],
            'navigation' => [
                'status' => $probe->status->value,
                'velocityC' => $probe->velocityC,
                'accelerationCPerDay' => $probe->accelerationCPerDay,
                'direction' => ['x' => $probe->direction->x, 'y' => $probe->direction->y, 'z' => $probe->direction->z],
                'currentTask' => $probe->currentTask,
                'enteredCurrentSectorAt' => $probe->enteredCurrentSectorAt,
            ],
            'health' => [
                'integrityPercent' => $probe->integrityPercent,
                'energyStored' => $probe->energyStored,
                'internalClockRate' => $probe->internalClockRate,
            ],
            'legacyResourceTotals' => userinfosLegacyTotals($probe),
            'excludeFromStats' => $probe->excludeFromStats,
            'createdAt' => $probe->createdAt,
            'updatedAt' => $probe->updatedAt,
        ],
        'diagnostics' => userinfosDiagnostics(
            $probe,
            $visitedCurrent,
            $visitedTotal,
            $movementTotal,
            $inventory,
            $movements,
            $scheduledEvents,
        ),
        'inventory' => $inventory,
        'visitedSectors' => [
            'total' => $visitedTotal,
            'currentSectorRow' => $visitedCurrent,
            'latest' => array_map(
                static fn(array $row): array => userinfosDecorateSectorRow($row, $frame),
                $visited,
            ),
        ],
        'movements' => [
            'total' => $movementTotal,
            'latest' => array_map(
                static fn(array $row): array => userinfosDecorateMovementRow($row, $frame),
                $movements,
            ),
        ],
        'scheduledEvents' => $scheduledEvents,
        'damageWarnings' => $damageWarnings,
        'messages' => [
            'received' => array_map(static fn(array $row): array => userinfosDecorateMessageRow($row, $frame), $messagesReceived),
            'sent' => array_map(static fn(array $row): array => userinfosDecorateMessageRow($row, $frame), $messagesSent),
        ],
    ];
}

function userinfosProbeOverview(PDO $pdo, object $player, object $probe, PlayerReferenceFrame $frame, array $gameplayConfig, int $focusedProbeId): array
{
    $relative = $frame->globalToRelative($probe->currentSector);
    $movementIds = userinfosColumn(
        $pdo,
        'SELECT id FROM probe_movements WHERE probe_id = :probe_id',
        ['probe_id' => $probe->id],
    );
    $pendingEvents = userinfosScheduledEvents($pdo, $probe->id, $movementIds, 1000);
    $pendingEvents = array_values(array_filter(
        $pendingEvents,
        static fn(array $event): bool => (string) $event['status'] === 'pending',
    ));

    return [
        'id' => $probe->id,
        'name' => $probe->name,
        'isDefault' => $player->defaultProbeId === $probe->id,
        'isFocused' => $probe->id === $focusedProbeId,
        'position' => [
            'absolute' => userinfosCoordinatesArray($probe->currentSector),
            'relativeToHome' => $relative,
        ],
        'navigation' => [
            'status' => $probe->status->value,
            'currentTask' => $probe->currentTask,
            'enteredCurrentSectorAt' => $probe->enteredCurrentSectorAt,
        ],
        'health' => [
            'integrityPercent' => $probe->integrityPercent,
            'energyStored' => $probe->energyStored,
            'internalClockRate' => $probe->internalClockRate,
        ],
        'legacyResourceTotals' => userinfosLegacyTotals($probe),
        'counts' => [
            'containers' => userinfosScalar($pdo, 'SELECT COUNT(*) FROM storage_containers WHERE probe_id = :probe_id', ['probe_id' => $probe->id]),
            'items' => userinfosScalar($pdo, 'SELECT COUNT(*) FROM probe_items WHERE probe_id = :probe_id', ['probe_id' => $probe->id]),
            'mannies' => userinfosScalar($pdo, 'SELECT COUNT(*) FROM mannies WHERE probe_id = :probe_id', ['probe_id' => $probe->id]),
            'movements' => userinfosScalar($pdo, 'SELECT COUNT(*) FROM probe_movements WHERE probe_id = :probe_id', ['probe_id' => $probe->id]),
            'activeMovements' => userinfosScalar(
                $pdo,
                "SELECT COUNT(*) FROM probe_movements WHERE probe_id = :probe_id AND status IN ('preparing', 'accelerating', 'cruising', 'decelerating')",
                ['probe_id' => $probe->id],
            ),
            'pendingScheduledEvents' => count($pendingEvents),
            'unreadWarnings' => userinfosScalar(
                $pdo,
                "SELECT COUNT(*) FROM probe_damage_warnings WHERE probe_id = :probe_id AND status = 'unread'",
                ['probe_id' => $probe->id],
            ),
            'receivedMessages' => userinfosScalar($pdo, 'SELECT COUNT(*) FROM probe_messages WHERE recipient_probe_id = :probe_id', ['probe_id' => $probe->id]),
            'sentMessages' => userinfosScalar($pdo, 'SELECT COUNT(*) FROM probe_messages WHERE sender_probe_id = :probe_id', ['probe_id' => $probe->id]),
        ],
        'excludeFromStats' => $probe->excludeFromStats,
        'createdAt' => $probe->createdAt,
        'updatedAt' => $probe->updatedAt,
    ];
}

function userinfosInventoryReport(object $probe, array $gameplayConfig, array $containers, array $resources, array $items, array $mannies): array
{
    $containerById = [];
    foreach ($containers as $container) {
        $containerById[(int) $container['id']] = $container;
    }

    $resourcesByContainer = [];
    foreach ($resources as $resource) {
        $containerId = (int) $resource['container_id'];
        $resourcesByContainer[$containerId] ??= [];
        $resourcesByContainer[$containerId][(string) $resource['resource_type']] = round((float) $resource['amount'], 4);
    }

    $itemsByContainer = [];
    $itemsWithoutKnownContainer = [];
    foreach ($items as $item) {
        $containerId = $item['storage_container_id'] !== null ? (int) $item['storage_container_id'] : null;
        if ($containerId !== null && isset($containerById[$containerId])) {
            $itemsByContainer[$containerId][] = $item;
            continue;
        }
        $itemsWithoutKnownContainer[] = $item;
    }

    $manniesByContainer = [];
    $manniesWithoutKnownContainer = [];
    foreach ($mannies as $manny) {
        $containerId = $manny['storage_container_id'] !== null ? (int) $manny['storage_container_id'] : null;
        if ($manny['location_type'] === Manny::LOCATION_PROBE && $containerId !== null && isset($containerById[$containerId])) {
            $manniesByContainer[$containerId][] = $manny;
            continue;
        }
        if ($manny['location_type'] === Manny::LOCATION_PROBE) {
            $manniesWithoutKnownContainer[] = $manny;
        }
    }

    $containersReport = [];
    foreach ($containers as $container) {
        $containerId = (int) $container['id'];
        $containerResources = $resourcesByContainer[$containerId] ?? [];
        $containerItems = $itemsByContainer[$containerId] ?? [];
        $containerMannies = $manniesByContainer[$containerId] ?? [];
        $usedCapacity = array_sum($containerResources)
            + array_reduce($containerItems, static fn(float $total, array $item): float => $total + (float) $item['container_space'], 0.0)
            + (count($containerMannies) * userinfosMannyContainerSpace($gameplayConfig));
        if ($container['uid'] === StorageContainer::CORE_UID) {
            $usedCapacity += userinfosAtomicPrinterSpace($gameplayConfig);
        }

        $containersReport[] = [
            'id' => $containerId,
            'uid' => $container['uid'],
            'kind' => $container['kind'],
            'label' => $container['label'],
            'sortOrder' => (int) $container['sort_order'],
            'capacity' => round((float) $container['capacity'], 4),
            'usedCapacityEstimate' => round($usedCapacity, 4),
            'freeCapacityEstimate' => round((float) $container['capacity'] - $usedCapacity, 4),
            'resources' => $containerResources,
            'items' => array_map('userinfosItemSummary', $containerItems),
            'mannies' => array_map('userinfosMannySummary', $containerMannies),
            'rules' => [
                'priority' => userinfosJsonList($container['priority_filter_json'] ?? '[]'),
                'exclusion' => userinfosJsonList($container['exclusion_filter_json'] ?? '[]'),
                'strictExclusion' => userinfosJsonList($container['strict_exclusion_filter_json'] ?? '[]'),
            ],
            'createdAt' => $container['created_at'],
            'updatedAt' => $container['updated_at'],
        ];
    }

    $resourceTotalsByContainers = array_fill_keys(ResourceComposition::TYPES, 0.0);
    foreach ($resources as $resource) {
        $type = (string) $resource['resource_type'];
        $resourceTotalsByContainers[$type] = round((float) ($resourceTotalsByContainers[$type] ?? 0.0) + (float) $resource['amount'], 4);
    }

    return [
        'legacyResourceTotals' => userinfosLegacyTotals($probe),
        'resourceTotalsByContainers' => $resourceTotalsByContainers,
        'containers' => $containersReport,
        'rawItems' => array_map('userinfosItemSummary', $items),
        'mannies' => array_map('userinfosMannySummary', $mannies),
        'suspicions' => [
            'itemsWithoutKnownContainer' => array_map('userinfosItemSummary', $itemsWithoutKnownContainer),
            'onboardManniesWithoutKnownContainer' => array_map('userinfosMannySummary', $manniesWithoutKnownContainer),
            'legacyVsContainerResourceDiffs' => userinfosResourceDiffs(userinfosLegacyTotals($probe), $resourceTotalsByContainers),
        ],
    ];
}

function userinfosDiagnostics(object $probe, ?array $visitedCurrent, int $visitedTotal, int $movementTotal, array $inventory, array $movements, array $scheduledEvents): array
{
    $activeMovements = array_values(array_filter(
        $movements,
        static fn(array $movement): bool => in_array((string) $movement['status'], ['preparing', 'accelerating', 'cruising', 'decelerating'], true),
    ));
    $pendingEvents = array_values(array_filter(
        $scheduledEvents,
        static fn(array $event): bool => (string) $event['status'] === 'pending',
    ));
    $flags = [];
    if ($probe->currentSector->equals(SectorCoordinates::origin())) {
        $flags[] = 'probe_absolute_position_is_0:0:0';
    }
    if ($visitedCurrent === null) {
        $flags[] = 'current_sector_not_marked_visited';
    }
    if ($inventory['suspicions']['legacyVsContainerResourceDiffs'] !== []) {
        $flags[] = 'probe_resource_totals_differ_from_container_rows';
    }
    if ($inventory['suspicions']['itemsWithoutKnownContainer'] !== []) {
        $flags[] = 'items_without_known_container';
    }
    if ($inventory['suspicions']['onboardManniesWithoutKnownContainer'] !== []) {
        $flags[] = 'onboard_mannies_without_known_container';
    }

    return [
        'flags' => $flags,
        'visitedSectorCount' => $visitedTotal,
        'movementCount' => $movementTotal,
        'activeMovementCount' => count($activeMovements),
        'pendingScheduledEventCount' => count($pendingEvents),
        'containerCount' => count($inventory['containers']),
        'itemCount' => count($inventory['rawItems']),
        'mannyCount' => count($inventory['mannies']),
    ];
}

function userinfosScheduledEvents(PDO $pdo, int $probeId, array $movementIds, int $limit): array
{
    $params = ['probe_id' => $probeId, 'limit' => $limit];
    $movementPlaceholders = [];
    foreach ($movementIds as $index => $movementId) {
        $key = 'movement_id_' . $index;
        $movementPlaceholders[] = ':' . $key;
        $params[$key] = (int) $movementId;
    }
    $movementSql = $movementPlaceholders === []
        ? '0 = 1'
        : 'entity_type = \'probe_movement\' AND entity_id IN (' . implode(', ', $movementPlaceholders) . ')';

    return userinfosRows(
        $pdo,
        "SELECT * FROM scheduled_events
         WHERE (entity_type = 'probe' AND entity_id = :probe_id)
            OR ({$movementSql})
         ORDER BY status = 'pending' DESC, run_at DESC, id DESC
         LIMIT :limit",
        $params,
        ['limit'],
    );
}

function userinfosDecorateSectorRow(array $row, PlayerReferenceFrame $frame): array
{
    $coordinates = new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);

    return $row + [
        'absolute' => userinfosCoordinatesArray($coordinates),
        'relativeToHome' => $frame->globalToRelative($coordinates),
    ];
}

function userinfosDecorateMovementRow(array $row, PlayerReferenceFrame $frame): array
{
    $origin = new SectorCoordinates((int) $row['origin_x'], (int) $row['origin_y'], (int) $row['origin_z']);
    $target = new SectorCoordinates((int) $row['target_x'], (int) $row['target_y'], (int) $row['target_z']);

    return $row + [
        'origin' => [
            'absolute' => userinfosCoordinatesArray($origin),
            'relativeToHome' => $frame->globalToRelative($origin),
        ],
        'target' => [
            'absolute' => userinfosCoordinatesArray($target),
            'relativeToHome' => $frame->globalToRelative($target),
        ],
    ];
}

function userinfosDecorateMessageRow(array $row, PlayerReferenceFrame $frame): array
{
    $sector = new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);

    return $row + [
        'sector' => [
            'absolute' => userinfosCoordinatesArray($sector),
            'relativeToHome' => $frame->globalToRelative($sector),
        ],
    ];
}

function userinfosItemSummary(array $item): array
{
    return [
        'id' => (int) $item['id'],
        'uid' => $item['uid'],
        'type' => $item['type'],
        'name' => $item['name'],
        'storageContainerId' => $item['storage_container_id'] !== null ? (int) $item['storage_container_id'] : null,
        'containerSpace' => round((float) $item['container_space'], 4),
        'metadata' => userinfosJsonObject($item['metadata_json'] ?? '{}'),
        'createdAt' => $item['created_at'],
        'updatedAt' => $item['updated_at'],
    ];
}

function userinfosMannySummary(array $manny): array
{
    $sector = $manny['sector_x'] === null || $manny['sector_y'] === null || $manny['sector_z'] === null
        ? null
        : ['x' => (int) $manny['sector_x'], 'y' => (int) $manny['sector_y'], 'z' => (int) $manny['sector_z']];

    return [
        'id' => (int) $manny['id'],
        'uid' => $manny['uid'],
        'probeId' => $manny['probe_id'] !== null ? (int) $manny['probe_id'] : null,
        'storageContainerId' => $manny['storage_container_id'] !== null ? (int) $manny['storage_container_id'] : null,
        'name' => $manny['name'],
        'locationType' => $manny['location_type'],
        'sector' => $sector,
        'currentTask' => $manny['current_task'],
        'taskStartedAt' => $manny['task_started_at'],
        'taskEndsAt' => $manny['task_ends_at'],
        'taskPayload' => userinfosJsonObject($manny['task_payload_json'] ?? '{}'),
        'cargo' => [
            'deuterium' => round((float) ($manny['cargo_deuterium'] ?? 0.0), 4),
            'metals' => round((float) ($manny['cargo_metals'] ?? 0.0), 4),
            'ice' => round((float) ($manny['cargo_ice'] ?? 0.0), 4),
            'organicCompounds' => round((float) ($manny['cargo_organic_compounds'] ?? 0.0), 4),
        ],
        'createdAt' => $manny['created_at'],
        'updatedAt' => $manny['updated_at'],
    ];
}

function userinfosRenderReport(array $report): string
{
    $out = [];
    $out[] = 'Von Neumann probe debug report';
    $out[] = 'Generated at: ' . $report['generatedAt'];
    $out[] = '';
    $out[] = 'Player';
    $out[] = '  #' . $report['player']['id'] . ' username=' . $report['player']['username']
        . ' displayName=' . userinfosValue($report['player']['displayName']);
    $out[] = '  defaultProbeId=' . userinfosValue($report['player']['defaultProbeId'])
        . ' probeCount=' . $report['player']['probeCount']
        . ' focusedProbeId=' . userinfosValue($report['focusedProbeId']);
    $out[] = '  home absolute: ' . userinfosFormatCoordinates($report['player']['homeSector']['absolute']);
    $out[] = '  created: ' . $report['player']['createdAt'] . ' updated: ' . $report['player']['updatedAt'];
    $out[] = '  auth methods: ' . count($report['player']['authMethods'])
        . ' sessions listed: ' . count($report['player']['sessions'])
        . ' api keys listed: ' . count($report['player']['apiKeys']);
    foreach ($report['player']['authMethods'] as $auth) {
        $out[] = '    - #' . $auth['id'] . ' provider=' . $auth['provider']
            . ' providerUserId=' . userinfosValue($auth['provider_user_id'])
            . ' passwordHash=' . (((int) $auth['has_password_hash']) === 1 ? 'yes' : 'no')
            . ' created=' . $auth['created_at'];
    }
    $out[] = '';
    $out[] = 'Probes (' . count($report['probes']) . ')';
    foreach ($report['probes'] as $probeOverview) {
        $markers = [];
        if ($probeOverview['isDefault']) {
            $markers[] = 'default';
        }
        if ($probeOverview['isFocused']) {
            $markers[] = 'focus';
        }
        $markerText = $markers === [] ? '-' : implode(',', $markers);
        $out[] = '  - #' . $probeOverview['id'] . ' name=' . $probeOverview['name']
            . ' markers=' . $markerText
            . ' status=' . $probeOverview['navigation']['status']
            . ' task=' . userinfosValue($probeOverview['navigation']['currentTask'])
            . ' abs=' . userinfosFormatCoordinates($probeOverview['position']['absolute'])
            . ' rel=' . userinfosFormatCoordinates($probeOverview['position']['relativeToHome']);
        $out[] = '    containers=' . $probeOverview['counts']['containers']
            . ' items=' . $probeOverview['counts']['items']
            . ' mannies=' . $probeOverview['counts']['mannies']
            . ' movements=' . $probeOverview['counts']['movements']
            . ' activeMovements=' . $probeOverview['counts']['activeMovements']
            . ' pendingEvents=' . $probeOverview['counts']['pendingScheduledEvents']
            . ' unreadWarnings=' . $probeOverview['counts']['unreadWarnings']
            . ' messages=' . $probeOverview['counts']['receivedMessages'] . '/' . $probeOverview['counts']['sentMessages'];
        $out[] = '    legacy totals: ' . userinfosFormatResourceMap($probeOverview['legacyResourceTotals']);
    }
    $out[] = '';
    $out[] = 'Focused probe';
    $out[] = '  #' . $report['probe']['id'] . ' name=' . $report['probe']['name'] . ' playerId=' . $report['probe']['playerId'];
    $out[] = '  absolute: ' . userinfosFormatCoordinates($report['probe']['position']['absolute']);
    $out[] = '  relative: ' . userinfosFormatCoordinates($report['probe']['position']['relativeToHome']) . ' from player home';
    $out[] = '  status=' . $report['probe']['navigation']['status']
        . ' task=' . userinfosValue($report['probe']['navigation']['currentTask'])
        . ' enteredCurrentSectorAt=' . $report['probe']['navigation']['enteredCurrentSectorAt'];
    $out[] = '  velocityC=' . $report['probe']['navigation']['velocityC']
        . ' accelerationCPerDay=' . $report['probe']['navigation']['accelerationCPerDay']
        . ' direction=' . userinfosFormatCoordinates($report['probe']['navigation']['direction']);
    $out[] = '  integrity=' . $report['probe']['health']['integrityPercent'] . '%'
        . ' energy=' . $report['probe']['health']['energyStored']
        . ' clockRate=' . $report['probe']['health']['internalClockRate'];
    $out[] = '  legacy totals: ' . userinfosFormatResourceMap($report['probe']['legacyResourceTotals']);
    $out[] = '  created: ' . $report['probe']['createdAt'] . ' updated: ' . $report['probe']['updatedAt'];
    $out[] = '';
    $out[] = 'Diagnostics';
    $flags = $report['diagnostics']['flags'];
    $out[] = '  flags: ' . ($flags === [] ? 'none' : implode(', ', $flags));
    $out[] = '  visitedSectors=' . $report['diagnostics']['visitedSectorCount']
        . ' movements=' . $report['diagnostics']['movementCount']
        . ' activeMovements=' . $report['diagnostics']['activeMovementCount']
        . ' pendingEvents=' . $report['diagnostics']['pendingScheduledEventCount'];
    $out[] = '  containers=' . $report['diagnostics']['containerCount']
        . ' rawItems=' . $report['diagnostics']['itemCount']
        . ' mannies=' . $report['diagnostics']['mannyCount'];
    $out[] = '';
    $out[] = 'Inventory';
    $out[] = '  container resource totals: ' . userinfosFormatResourceMap($report['inventory']['resourceTotalsByContainers']);
    foreach ($report['inventory']['containers'] as $container) {
        $out[] = '  - container #' . $container['id'] . ' uid=' . $container['uid']
            . ' label=' . $container['label'] . ' kind=' . $container['kind']
            . ' capacity=' . $container['capacity'] . ' usedEstimate=' . $container['usedCapacityEstimate']
            . ' freeEstimate=' . $container['freeCapacityEstimate'];
        $out[] = '    resources: ' . userinfosFormatResourceMap($container['resources']);
        foreach ($container['items'] as $item) {
            $out[] = '    item #' . $item['id'] . ' ' . $item['uid'] . ' type=' . $item['type']
                . ' name=' . $item['name'] . ' space=' . $item['containerSpace'];
        }
        foreach ($container['mannies'] as $manny) {
            $out[] = '    manny #' . $manny['id'] . ' ' . $manny['uid'] . ' name=' . $manny['name']
                . ' task=' . userinfosValue($manny['currentTask']);
        }
    }
    if ($report['inventory']['suspicions']['legacyVsContainerResourceDiffs'] !== []) {
        $out[] = '  resource diffs: ' . userinfosFormatResourceMap($report['inventory']['suspicions']['legacyVsContainerResourceDiffs']);
    }
    if ($report['inventory']['suspicions']['itemsWithoutKnownContainer'] !== []) {
        $out[] = '  items without known container: ' . count($report['inventory']['suspicions']['itemsWithoutKnownContainer']);
    }
    if ($report['inventory']['suspicions']['onboardManniesWithoutKnownContainer'] !== []) {
        $out[] = '  onboard Mannys without known container: ' . count($report['inventory']['suspicions']['onboardManniesWithoutKnownContainer']);
    }
    $out[] = '';
    $out[] = 'Mannies';
    foreach ($report['inventory']['mannies'] as $manny) {
        $sector = $manny['sector'] === null ? '-' : userinfosFormatCoordinates($manny['sector']);
        $out[] = '  - #' . $manny['id'] . ' ' . $manny['uid'] . ' name=' . $manny['name']
            . ' location=' . $manny['locationType'] . ' sector=' . $sector
            . ' container=' . userinfosValue($manny['storageContainerId'])
            . ' task=' . userinfosValue($manny['currentTask'])
            . ' cargo=' . userinfosFormatResourceMap($manny['cargo']);
    }
    $out[] = '';
    $out[] = 'Visited sectors (latest ' . $report['limit'] . ' / total ' . $report['visitedSectors']['total'] . ')';
    foreach ($report['visitedSectors']['latest'] as $sector) {
        $out[] = '  - #' . $sector['id'] . ' abs=' . userinfosFormatCoordinates($sector['absolute'])
            . ' rel=' . userinfosFormatCoordinates($sector['relativeToHome'])
            . ' visits=' . $sector['visit_count']
            . ' first=' . $sector['first_visited_at']
            . ' last=' . $sector['last_visited_at'];
    }
    $out[] = '';
    $out[] = 'Movements (latest ' . $report['limit'] . ' / total ' . $report['movements']['total'] . ')';
    foreach ($report['movements']['latest'] as $movement) {
        $out[] = '  - #' . $movement['id'] . ' status=' . $movement['status']
            . ' origin=' . userinfosFormatCoordinates($movement['origin']['absolute'])
            . ' target=' . userinfosFormatCoordinates($movement['target']['absolute'])
            . ' targetRel=' . userinfosFormatCoordinates($movement['target']['relativeToHome'])
            . ' distance=' . $movement['distance']
            . ' fuel=' . $movement['fuel_cost_deuterium']
            . ' started=' . $movement['started_at']
            . ' arrival=' . $movement['arrival_at'];
        if ($movement['destroyed_at'] !== null || $movement['destruction_reason'] !== null) {
            $out[] = '    destruction checked=' . userinfosValue($movement['destruction_checked_at'])
                . ' destroyed=' . userinfosValue($movement['destroyed_at'])
                . ' reason=' . userinfosValue($movement['destruction_reason']);
        }
    }
    $out[] = '';
    $out[] = 'Scheduled events';
    foreach ($report['scheduledEvents'] as $event) {
        $out[] = '  - #' . $event['id'] . ' type=' . $event['type']
            . ' entity=' . $event['entity_type'] . '#' . $event['entity_id']
            . ' status=' . $event['status'] . ' runAt=' . $event['run_at']
            . ' attempts=' . $event['attempts']
            . ' lastError=' . userinfosValue($event['last_error']);
    }
    $out[] = '';
    $out[] = 'Damage warnings';
    foreach ($report['damageWarnings'] as $warning) {
        $out[] = '  - #' . $warning['id'] . ' type=' . $warning['type']
            . ' status=' . $warning['status'] . ' phase=' . $warning['phase']
            . ' scheduled=' . $warning['scheduled_at']
            . ' risk=' . $warning['risk_percent']
            . ' message=' . $warning['message'];
    }
    $out[] = '';
    $out[] = 'Messages';
    $out[] = '  received: ' . count($report['messages']['received']);
    foreach ($report['messages']['received'] as $message) {
        $out[] = '    - #' . $message['id'] . ' from=' . $message['sender_probe_id']
            . ' sectorRel=' . userinfosFormatCoordinates($message['sector']['relativeToHome'])
            . ' status=' . $message['status'] . ' created=' . $message['created_at']
            . ' body=' . userinfosValue($message['body_preview']);
    }
    $out[] = '  sent: ' . count($report['messages']['sent']);
    foreach ($report['messages']['sent'] as $message) {
        $out[] = '    - #' . $message['id'] . ' to=' . $message['recipient_probe_id']
            . ' sectorRel=' . userinfosFormatCoordinates($message['sector']['relativeToHome'])
            . ' status=' . $message['status'] . ' created=' . $message['created_at']
            . ' body=' . userinfosValue($message['body_preview']);
    }

    return implode("\n", $out) . "\n";
}

function userinfosRows(PDO $pdo, string $sql, array $params = [], array $integerParams = []): array
{
    $stmt = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $parameter = ':' . ltrim((string) $name, ':');
        $stmt->bindValue($parameter, $value, in_array((string) $name, $integerParams, true) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    return $stmt->fetchAll();
}

function userinfosSingleRow(PDO $pdo, string $sql, array $params = []): ?array
{
    $rows = userinfosRows($pdo, $sql, $params);

    return $rows[0] ?? null;
}

function userinfosScalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function userinfosColumn(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function userinfosCoordinatesArray(SectorCoordinates $coordinates): array
{
    return ['x' => $coordinates->getX(), 'y' => $coordinates->getY(), 'z' => $coordinates->getZ()];
}

function userinfosLegacyTotals(object $probe): array
{
    return [
        ResourceComposition::DEUTERIUM => round((float) $probe->deuteriumStock, 4),
        ResourceComposition::METALS => round((float) $probe->metalsStock, 4),
        ResourceComposition::ICE => round((float) $probe->iceStock, 4),
        ResourceComposition::CARBON_COMPOUNDS => round((float) $probe->organicCompoundsStock, 4),
    ];
}

function userinfosResourceDiffs(array $legacyTotals, array $containerTotals): array
{
    $diffs = [];
    foreach ([ResourceComposition::METALS, ResourceComposition::ICE, ResourceComposition::CARBON_COMPOUNDS] as $type) {
        $diff = round((float) ($legacyTotals[$type] ?? 0.0) - (float) ($containerTotals[$type] ?? 0.0), 4);
        if (abs($diff) > 0.0001) {
            $diffs[$type] = $diff;
        }
    }

    return $diffs;
}

function userinfosJsonList(mixed $json): array
{
    $decoded = json_decode((string) $json, true);

    return is_array($decoded) ? array_values($decoded) : [];
}

function userinfosJsonObject(mixed $json): array
{
    $decoded = json_decode((string) $json, true);

    return is_array($decoded) ? $decoded : [];
}

function userinfosAtomicPrinterSpace(array $gameplayConfig): float
{
    return max(0.0, Config::float($gameplayConfig, 'probe.atomicPrinterContainerSpace', 0.3));
}

function userinfosMannyContainerSpace(array $gameplayConfig): float
{
    return max(0.0, Config::float($gameplayConfig, 'manny.containerSpace', 0.05));
}

function userinfosFormatCoordinates(array $coordinates): string
{
    return (string) ($coordinates['x'] ?? 0) . ':' . (string) ($coordinates['y'] ?? 0) . ':' . (string) ($coordinates['z'] ?? 0);
}

function userinfosFormatResourceMap(array $resources): string
{
    if ($resources === []) {
        return 'none';
    }

    $parts = [];
    foreach ($resources as $type => $amount) {
        $parts[] = $type . '=' . (string) $amount;
    }

    return implode(', ', $parts);
}

function userinfosValue(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    return (string) $value;
}
