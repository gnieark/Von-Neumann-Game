<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\StorageContainerRepository;
use VonNeumannGame\Service\ProbeStorageService;
use VonNeumannGame\Domain\ResourceComposition;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(addInventoryItemRun($argv));
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . addInventoryItemUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to add inventory item: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 */
function addInventoryItemRun(array $argv): int
{
    $options = addInventoryItemParseArguments($argv);
    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $gameplayConfig = $factory->gameplayConfig();
    $craftingConfig = is_array($gameplayConfig['crafting'] ?? null) ? $gameplayConfig['crafting'] : [];

    if ($options['help']) {
        echo addInventoryItemUsage();

        return 0;
    }
    if ($options['list']) {
        echo addInventoryItemSupportedList($craftingConfig);

        return 0;
    }

    $objectName = $options['object'] ?? throw new InvalidArgumentException('Missing object name.');
    $quantity = $options['quantity'] ?? throw new InvalidArgumentException('Missing quantity.');
    $probeId = $options['probeId'] ?? null;
    $playerId = $options['playerId'] ?? null;
    $othersShipId = $options['othersShipId'] ?? null;
    if ($probeId === null && $playerId === null && $othersShipId === null) {
        throw new InvalidArgumentException('Missing target: use --probe-id, --player-id or --others-ship-id.');
    }
    // Allow mineable resources (metals, ice, carbon_compounds, deuterium)
    $isResource = false;
    try {
        $resourceTypes = ResourceComposition::normalizeSelection($objectName);
        $resourceType = $resourceTypes[0] ?? null;
        if ($resourceType !== null) {
            $isResource = true;
            $definition = ['id' => $resourceType, 'name' => $resourceType];
            $type = $resourceType;
        }
    } catch (InvalidArgumentException) {
        $isResource = false;
    }

    if (!$isResource) {
        $definition = addInventoryItemResolveDefinition($objectName, $craftingConfig);
        $output = is_array($definition['output'] ?? null) ? $definition['output'] : [];
        $type = (string) ($output['type'] ?? $definition['id'] ?? '');
        if ($type === '') {
            throw new RuntimeException('Resolved object has no output type.');
        }
    }

    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: true);
    $players = new PlayerRepository($pdo);
    $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
    $mannies = new MannyRepository($pdo, $gameplayConfig);
    $items = new ProbeItemRepository($pdo);
    $containers = new StorageContainerRepository($pdo, $gameplayConfig);
    $storage = new ProbeStorageService($containers, $items, $mannies, $probes, $gameplayConfig);

    if ($othersShipId !== null) {
        $others = new OthersRepository($pdo);
        $ship = $others->findShipByPublicId($othersShipId)
            ?? throw new RuntimeException("Others ship {$othersShipId} not found.");
        if ($ship['destroyed_at'] !== null || $ship['status'] === 'removed') {
            throw new RuntimeException("Others ship {$othersShipId} is not active.");
        }
        if ($type === 'manny') {
            throw new InvalidArgumentException('Mannies can only be added to probe inventories.');
        }

        $createdItemUids = [];
        $addedResource = null;
        $pdo->beginTransaction();
        try {
            $itemSpace = $isResource
                ? 0.0
                : ($type === 'missile'
                    ? 2.0
                    : round(max(0.0, (float) ($output['containerSpace'] ?? 0.0)), 4));
            $requiredSpace = $isResource ? (float) $quantity : $itemSpace * $quantity;
            $usedSpace = $others->inventoryUsage((int) $ship['id']);
            if ($usedSpace + (float) $ship['inventory_reserved'] + $requiredSpace > (float) $ship['inventory_capacity'] + 0.00001) {
                throw new RuntimeException('Insufficient Others ship inventory capacity.');
            }

            $now = gmdate('c');
            if ($isResource) {
                $amount = (float) $quantity;
                $update = $pdo->prepare(
                    'UPDATE others_inventory_resources '
                    . 'SET amount = amount + CAST(:amount AS DECIMAL(20,4)), updated_at = :updated_at '
                    . 'WHERE ship_id = :ship_id AND resource_type = :resource_type'
                );
                $update->execute([
                    'amount' => $amount,
                    'updated_at' => $now,
                    'ship_id' => (int) $ship['id'],
                    'resource_type' => $type,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException("Others inventory resource {$type} not found.");
                }
                $addedResource = $amount;
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO others_inventory_items '
                    . '(public_id, ship_id, type, container_space, reserved_action_id, created_at, updated_at) '
                    . 'VALUES (:public_id, :ship_id, :type, :container_space, NULL, :created_at, :updated_at)'
                );
                for ($index = 0; $index < $quantity; $index++) {
                    $publicId = OthersRepository::publicId('item');
                    $insert->execute([
                        'public_id' => $publicId,
                        'ship_id' => (int) $ship['id'],
                        'type' => $type,
                        'container_space' => $itemSpace,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $createdItemUids[] = $publicId;
                }
            }

            if ($options['dryRun']) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $displayQuantity = $isResource && $addedResource !== null ? $addedResource : $quantity;
        echo ($options['dryRun'] ? '[dry-run] Would add' : 'Added')
            . " {$displayQuantity} x " . (string) ($definition['name'] ?? $type)
            . " ({$type}) to Others ship {$ship['public_id']} for player #{$ship['player_id']}.\n";
        if ($createdItemUids !== []) {
            echo '- item uids: ' . implode(', ', $createdItemUids) . "\n";
        }
        if ($options['dryRun']) {
            echo "No data was written.\n";
        }

        return 0;
    }

    if ($probeId !== null) {
        $probe = $probes->findById($probeId)
            ?? throw new RuntimeException("Probe #{$probeId} not found.");
        $player = $players->findById($probe->playerId)
            ?? throw new RuntimeException("Probe #{$probe->id} owner player #{$probe->playerId} not found.");
    } else {
        $player = $players->findById($playerId)
            ?? throw new RuntimeException("Player #{$playerId} not found.");
        $probe = $probes->findByPlayerId($player->id)
            ?? throw new RuntimeException("Player #{$player->id} has no default probe.");
    }

    $createdItemUids = [];
    $createdMannyUids = [];
    $addedResource = null;
    $pdo->beginTransaction();
    try {
        if ($type === 'manny') {
            for ($index = 0; $index < $quantity; $index++) {
                $manny = $mannies->createForProbe($probe->id, addInventoryItemNextMannyName($mannies->findByProbeId($probe->id)));
                if (!$storage->placeMannyOnProbe($probe, $manny)) {
                    throw new RuntimeException('Insufficient probe cargo capacity for this Manny.');
                }
                $manny->taskPayload = [
                    'debugAddedBy' => 'scripts/add-inventory-item.php',
                    'debugAddedAt' => gmdate('c'),
                ];
                $mannies->save($manny);
                $createdMannyUids[] = $manny->uid;
            }
        } elseif ($isResource) {
            // Add resource amounts (quantity is used as amount)
            $amount = (float) $quantity;
            $accepted = $storage->addResource($probe, $type, $amount);
            $addedResource = $accepted;
            if ($accepted <= 0.0) {
                throw new RuntimeException('Insufficient probe cargo capacity for this resource.');
            }
        } else {
            $metadata = [
                'debugAddedBy' => 'scripts/add-inventory-item.php',
                'debugAddedAt' => gmdate('c'),
            ];
            $capacityBonus = round(max(0.0, (float) ($output['capacityBonus'] ?? 0.0)), 4);
            if ($capacityBonus > 0.0) {
                $metadata['capacityBonus'] = $capacityBonus;
                $metadata['capacityBonusUnit'] = ProbeInventory::CAPACITY_UNIT;
            }

            for ($index = 0; $index < $quantity; $index++) {
                $item = $storage->addItem(
                    $probe,
                    $type,
                    (string) ($output['name'] ?? $definition['name'] ?? $type),
                    round(max(0.0, (float) ($output['containerSpace'] ?? 0.0)), 4),
                    $metadata,
                );
                $createdItemUids[] = $item->uid;
            }
        }

        if ($options['dryRun']) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($isResource) {
        $displayAmount = $addedResource !== null ? $addedResource : $quantity;
        echo ($options['dryRun'] ? '[dry-run] Would add' : 'Added')
            . " {$displayAmount} x " . (string) ($definition['name'] ?? $type)
            . " ({$type}) to player #{$player->id} ({$player->username}), probe #{$probe->id}.\n";
    } else {
        echo ($options['dryRun'] ? '[dry-run] Would add' : 'Added')
            . " {$quantity} x " . (string) ($definition['name'] ?? $type)
            . " ({$type}) to player #{$player->id} ({$player->username}), probe #{$probe->id}.\n";
    }
    if ($createdItemUids !== []) {
        echo '- item uids: ' . implode(', ', $createdItemUids) . "\n";
    }
    if ($createdMannyUids !== []) {
        echo '- manny uids: ' . implode(', ', $createdMannyUids) . "\n";
    }
    if ($options['dryRun']) {
        echo "No data was written.\n";
    }

    return 0;
}

/**
 * @param array<int, string> $argv
 * @return array{object:?string, quantity:?int, probeId:?int, playerId:?int, othersShipId:?string, databaseConfig:?string, dryRun:bool, list:bool, help:bool}
 */
function addInventoryItemParseArguments(array $argv): array
{
    $options = [
        'object' => null,
        'quantity' => null,
        'probeId' => null,
        'playerId' => null,
        'othersShipId' => null,
        'databaseConfig' => null,
        'dryRun' => false,
        'list' => false,
        'help' => false,
    ];
    $positionals = [];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }
        if ($argument === '--list') {
            $options['list'] = true;
            continue;
        }
        if (str_starts_with($argument, '--database-config=')) {
            $value = substr($argument, strlen('--database-config='));
            $options['databaseConfig'] = $value !== '' ? $value : null;
            continue;
        }
        if (str_starts_with($argument, '--object=')) {
            $options['object'] = addInventoryItemNonEmpty(substr($argument, strlen('--object=')), 'object');
            continue;
        }
        if (str_starts_with($argument, '--quantity=')) {
            $options['quantity'] = addInventoryItemPositiveInt(substr($argument, strlen('--quantity=')), 'quantity');
            continue;
        }
        if (str_starts_with($argument, '--probe-id=')) {
            $options['probeId'] = addInventoryItemPositiveInt(substr($argument, strlen('--probe-id=')), 'probe-id');
            continue;
        }
        if (str_starts_with($argument, '--player-id=')) {
            $options['playerId'] = addInventoryItemPositiveInt(substr($argument, strlen('--player-id=')), 'player-id');
            continue;
        }
        if (str_starts_with($argument, '--others-ship-id=')) {
            $options['othersShipId'] = addInventoryItemNonEmpty(substr($argument, strlen('--others-ship-id=')), 'others-ship-id');
            continue;
        }
        if (str_starts_with($argument, '--')) {
            throw new InvalidArgumentException("Unexpected option: {$argument}");
        }

        $positionals[] = $argument;
    }

    if ($positionals !== []) {
        $options['object'] ??= addInventoryItemNonEmpty((string) array_shift($positionals), 'object');
    }
    if ($positionals !== []) {
        $options['quantity'] ??= addInventoryItemPositiveInt((string) array_shift($positionals), 'quantity');
    }
    if ($positionals !== []) {
        $options['probeId'] ??= addInventoryItemPositiveInt((string) array_shift($positionals), 'probe-id');
    }
    if ($positionals !== []) {
        throw new InvalidArgumentException('Too many positional arguments.');
    }
    $targetCount = count(array_filter([
        $options['probeId'],
        $options['playerId'],
        $options['othersShipId'],
    ], static fn(int|string|null $value): bool => $value !== null));
    if ($targetCount > 1) {
        throw new InvalidArgumentException('Use only one of --probe-id, --player-id or --others-ship-id.');
    }

    return $options;
}

function addInventoryItemUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/add-inventory-item.php <object> <quantity> <probe-id>
  php scripts/add-inventory-item.php --object=<object> --quantity=<n> --probe-id=<id>
  php scripts/add-inventory-item.php --object=<object> --quantity=<n> --others-ship-id=<public-id>

Examples:
  php scripts/add-inventory-item.php steel_bar 3 42
  php scripts/add-inventory-item.php "Atmospheric drop kit" 1 --probe-id=42
  php scripts/add-inventory-item.php manny 1 42
  php scripts/add-inventory-item.php missile 10 --others-ship-id=ship_0123456789abcdefabcd

Options:
  --database-config=<path>  Use another database config.
  --dry-run                 Validate the operation and roll it back.
  --player-id=<id>          Legacy shortcut: target this player's default probe.
  --others-ship-id=<id>     Target an Others ship by its public id.
  --list                    List supported object names and ids.
  -h, --help                Show this help.

Manny creates real Manny entities and is only available for probes.
additional_container creates its paired storage container only for probes.
An Others missile occupies its canonical 2 ECE inventory space.

TEXT;
}

/**
 * @param array<string, mixed> $craftingConfig
 */
function addInventoryItemSupportedList(array $craftingConfig): string
{
    $lines = ["Supported objects:"];
    foreach (CraftingRecipeCatalog::all($craftingConfig) as $definition) {
        $output = is_array($definition['output'] ?? null) ? $definition['output'] : [];
        $lines[] = '- ' . (string) ($definition['id'] ?? '?')
            . ' / ' . (string) ($definition['name'] ?? '?')
            . ' => ' . (string) ($output['type'] ?? '?');
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param array<string, mixed> $craftingConfig
 * @return array<string, mixed>
 */
function addInventoryItemResolveDefinition(string $objectName, array $craftingConfig): array
{
    $needle = CraftingRecipeCatalog::normalizeId($objectName);
    foreach (CraftingRecipeCatalog::all($craftingConfig) as $definition) {
        $output = is_array($definition['output'] ?? null) ? $definition['output'] : [];
        $aliases = [
            (string) ($definition['id'] ?? ''),
            (string) ($definition['name'] ?? ''),
            (string) ($output['type'] ?? ''),
            (string) ($output['name'] ?? ''),
        ];
        foreach ($aliases as $alias) {
            if ($alias !== '' && CraftingRecipeCatalog::normalizeId($alias) === $needle) {
                return $definition;
            }
        }
    }

    throw new InvalidArgumentException("Unknown object: {$objectName}. Run with --list to see supported objects.");
}

/**
 * @param array<VonNeumannGame\Domain\Manny> $existing
 */
function addInventoryItemNextMannyName(array $existing): string
{
    $names = array_fill_keys(array_map(static fn($manny): string => strtolower($manny->name), $existing), true);
    for ($index = 1; $index <= 9999; $index++) {
        $name = 'manny-debug-' . $index;
        if (!isset($names[$name])) {
            return $name;
        }
    }

    return 'manny-debug-' . bin2hex(random_bytes(4));
}

function addInventoryItemNonEmpty(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException("{$label} cannot be empty.");
    }

    return $value;
}

function addInventoryItemPositiveInt(string $value, string $label): int
{
    if (!preg_match('/^[1-9][0-9]*$/', trim($value))) {
        throw new InvalidArgumentException("{$label} must be a positive integer.");
    }

    return (int) $value;
}
