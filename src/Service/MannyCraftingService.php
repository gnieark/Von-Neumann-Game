<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeItemRepository;

final class MannyCraftingService
{
    private const PROBE_ASSEMBLY_COMPONENTS = [
        ProbeItem::TYPE_DEUTERIUM_ENGINE => 1,
        ProbeItem::TYPE_SCUT_RELAY => 1,
        ProbeItem::TYPE_ELECTRIC_MOTOR => 5,
        ProbeItem::TYPE_ATOMIC_PRINTER_PART => 2,
        ProbeItem::TYPE_SOLAR_PANEL => 4,
    ];

    public function __construct(
        private readonly MannyRepository $mannies,
        private readonly NeumannProbeRepository $probes,
        private readonly ProbeItemRepository $items,
        private readonly ProbeStorageService $storage,
        private readonly array $config = [],
    ) {
    }

    /**
     * @param array<string, mixed> $recipeDefinition
     * @return array<string, mixed>
     */
    public function craftingPlan(NeumannProbe $probe, array $recipeDefinition): array
    {
        $output = $recipeDefinition['output'] ?? null;
        if (!is_array($output) || !isset($output['type'])) {
            throw new MannyActionException(400, 'invalid_recipe', 'Unknown crafting recipe.');
        }

        $resourceCosts = [];
        $itemsToConsume = [];
        $consumedItems = [];
        $itemsByType = $this->probeItemsByType($probe);
        $durationSeconds = $this->resolveCraftingRecipe(
            $recipeDefinition,
            $itemsByType,
            $itemsToConsume,
            $consumedItems,
            $resourceCosts,
        );

        $this->ensureResourceCostsAvailable($probe, $resourceCosts);

        return [
            'durationSeconds' => $durationSeconds,
            'resourceCosts' => $resourceCosts,
            'itemsToConsume' => $itemsToConsume,
            'consumedItems' => $consumedItems,
            'output' => $output,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function probeImprovementPlan(NeumannProbe $probe, array $definition): array
    {
        $resourceCosts = [];
        $itemsToConsume = [];
        $consumedItems = [];
        $itemsByType = $this->probeItemsByType($probe);
        $ingredients = is_array($definition['ingredients'] ?? null) ? $definition['ingredients'] : [];
        foreach ($ingredients as $ingredient) {
            if (!is_array($ingredient)) {
                continue;
            }
            $type = (string) ($ingredient['type'] ?? '');
            if ($type === '') {
                continue;
            }

            $quantity = max(0.0, (float) ($ingredient['quantity'] ?? 0));
            if ($this->craftingIngredientKind($ingredient) !== 'item') {
                $this->addResourceCost($resourceCosts, $this->normalizeCraftResourceType($type), $quantity);
                continue;
            }

            $requiredCount = (int) ceil($quantity);
            $availableItems = $itemsByType[$type] ?? [];
            if (count($availableItems) < $requiredCount) {
                throw new MannyActionException(422, 'insufficient_improvement_ingredients', 'Insufficient probe inventory for this improvement.');
            }
            for ($index = 0; $index < $requiredCount; $index++) {
                $item = array_shift($availableItems);
                if (!$item instanceof ProbeItem) {
                    continue;
                }
                $itemsToConsume[] = $item;
                $consumedItems[] = $this->consumedItemPayload($item);
            }
            $itemsByType[$type] = $availableItems;
        }

        $this->ensureResourceCostsAvailable($probe, $resourceCosts);

        return [
            'durationSeconds' => max(1, (int) ($definition['durationSeconds'] ?? 1)),
            'resourceCosts' => $resourceCosts,
            'itemsToConsume' => $itemsToConsume,
            'consumedItems' => $consumedItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function probeAssemblyPlan(NeumannProbe $probe): array
    {
        $itemsByType = $this->probeItemsByType($probe);
        $itemsToConsume = [];
        $consumedItems = [];
        foreach (self::PROBE_ASSEMBLY_COMPONENTS as $type => $requiredCount) {
            $availableItems = $itemsByType[$type] ?? [];
            if (count($availableItems) < $requiredCount) {
                throw new MannyActionException(422, 'insufficient_probe_assembly_components', 'Insufficient probe inventory to assemble a new probe.');
            }
            for ($index = 0; $index < $requiredCount; $index++) {
                $item = array_shift($availableItems);
                if (!$item instanceof ProbeItem) {
                    continue;
                }
                $itemsToConsume[] = $item;
                $consumedItems[] = $this->consumedItemPayload($item);
            }
            $itemsByType[$type] = $availableItems;
        }

        return [
            'itemsToConsume' => $itemsToConsume,
            'consumedItems' => $consumedItems,
        ];
    }

    /**
     * @return list<array{type:string,name:string,quantity:int,unit:string}>
     */
    public function probeAssemblyComponentRequirements(): array
    {
        $requirements = [];
        foreach (self::PROBE_ASSEMBLY_COMPONENTS as $type => $quantity) {
            $requirements[] = [
                'type' => $type,
                'name' => $this->itemDisplayName($type),
                'quantity' => $quantity,
                'unit' => 'item',
            ];
        }

        return $requirements;
    }

    /**
     * @param array<string, mixed> $recipeDefinition
     */
    public function recipeCraftableBy(array $recipeDefinition, string $fabricator): bool
    {
        $craftableBy = $recipeDefinition['craftableBy'] ?? [];
        if (!is_array($craftableBy)) {
            return false;
        }

        return in_array($fabricator, $craftableBy, true);
    }

    public function newCraftingRunId(): string
    {
        return 'craft_' . bin2hex(random_bytes(12));
    }

    /**
     * @return array<string, mixed>
     */
    public function consumedItemPayload(ProbeItem $item): array
    {
        return [
            'type' => $item->type,
            'name' => $item->name,
            'containerSpace' => $item->containerSpace,
            'metadata' => $item->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $craftingPlan
     */
    public function consumeCraftingPlan(NeumannProbe $probe, array $craftingPlan): void
    {
        $resourceCosts = is_array($craftingPlan['resourceCosts'] ?? null) ? $craftingPlan['resourceCosts'] : [];
        foreach ($resourceCosts as $type => $quantity) {
            $this->subtractResourceFromProbe($probe, (string) $type, (float) $quantity);
        }

        $itemsToConsume = is_array($craftingPlan['itemsToConsume'] ?? null) ? $craftingPlan['itemsToConsume'] : [];
        foreach ($itemsToConsume as $item) {
            if ($item instanceof ProbeItem) {
                $this->items->delete($item);
            }
        }
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function consumeProbeImprovementPlan(NeumannProbe $probe, array $plan): void
    {
        $this->consumeCraftingPlan($probe, $plan);
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function consumeProbeAssemblyPlan(NeumannProbe $probe, array $plan): void
    {
        $this->consumeCraftingPlan($probe, $plan);
    }

    /**
     * @param array<string, float> $resourceCosts
     */
    public function cargoSpaceFreedByResourceCosts(array $resourceCosts): float
    {
        $freed = 0.0;
        foreach ($resourceCosts as $type => $quantity) {
            if ($type !== ResourceComposition::DEUTERIUM) {
                $freed += max(0.0, (float) $quantity);
            }
        }

        return round($freed, 4);
    }

    /**
     * @param array<array<string, mixed>> $consumedItems
     */
    public function cargoSpaceFreedByConsumedItems(array $consumedItems): float
    {
        return round(array_reduce(
            $consumedItems,
            static fn(float $total, array $item): float => $total + max(0.0, (float) ($item['containerSpace'] ?? 0.0)),
            0.0,
        ), 4);
    }

    public function createCraftingOutput(NeumannProbe $probe, Manny $manny, \DateTimeImmutable $now): void
    {
        $output = $manny->taskPayload['output'] ?? null;
        if (!is_array($output)) {
            $recipeDefinition = CraftingRecipeCatalog::find((string) ($manny->taskPayload['recipe'] ?? ''), $this->craftingConfig());
            $output = is_array($recipeDefinition) && is_array($recipeDefinition['output'] ?? null)
                ? $recipeDefinition['output']
                : null;
        }
        if (!is_array($output)) {
            return;
        }

        $type = (string) ($output['type'] ?? '');
        if ($type === '') {
            return;
        }
        if ($type === 'manny') {
            $this->createCraftedManny($probe, $manny, $now);
            return;
        }

        $outputUid = $this->craftingOutputUid($manny, $type, 'itm_craft_');
        if ($this->items->findByUidForProbe($probe->id, $outputUid) !== null) {
            return;
        }

        $metadata = [
            'recipe' => (string) ($manny->taskPayload['recipe'] ?? $type),
            'craftingRunId' => $this->craftingRunId($manny, $type),
            'craftedByMannyId' => $manny->uid,
            'craftedByMannyName' => $manny->name,
            'craftedAt' => $now->format('c'),
        ];
        if (isset($manny->taskPayload['fabricator']) && is_string($manny->taskPayload['fabricator'])) {
            $metadata['fabricator'] = $manny->taskPayload['fabricator'];
        }
        $capacityBonus = round(max(0.0, (float) ($output['capacityBonus'] ?? 0.0)), 4);
        if ($capacityBonus > 0.0) {
            $metadata['capacityBonus'] = $capacityBonus;
            $metadata['capacityBonusUnit'] = ProbeInventory::CAPACITY_UNIT;
        }

        try {
            $this->storage->addItem(
                $probe,
                $type,
                (string) ($output['name'] ?? $type),
                round(max(0.0, (float) ($output['containerSpace'] ?? 0.0)), 4),
                $metadata,
                $outputUid,
            );
        } catch (\RuntimeException $e) {
            if ($this->items->findByUidForProbe($probe->id, $outputUid) !== null) {
                return;
            }

            throw $e;
        }
    }

    public function refundCraftingCommitment(NeumannProbe $probe, Manny $manny): void
    {
        $consumedItems = is_array($manny->taskPayload['consumedItems'] ?? null) ? $manny->taskPayload['consumedItems'] : [];
        foreach ($consumedItems as $item) {
            if (is_array($item)) {
                $this->restoreConsumedItem($probe, $item);
            }
        }

        $resourceCosts = is_array($manny->taskPayload['resourceCosts'] ?? null) ? $manny->taskPayload['resourceCosts'] : [];
        if ($resourceCosts === [] && (float) ($manny->taskPayload['metalsCost'] ?? 0.0) > 0.0) {
            $resourceCosts = [ResourceComposition::METALS => (float) $manny->taskPayload['metalsCost']];
        }

        $refundedResources = false;
        foreach ($resourceCosts as $type => $quantity) {
            if ((float) $quantity <= 0.0) {
                continue;
            }

            $this->transferResourceToProbe($probe, (string) $type, (float) $quantity);
            $refundedResources = true;
        }
        if ($refundedResources) {
            $this->probes->save($probe);
        }
    }

    public function refundProbeImprovementCommitment(NeumannProbe $probe, Manny $manny): void
    {
        $this->refundCraftingCommitment($probe, $manny);
    }

    /**
     * @param array<string, mixed> $item
     */
    public function restoreConsumedItem(NeumannProbe $probe, array $item): void
    {
        $type = (string) ($item['type'] ?? '');
        if ($type === '') {
            return;
        }

        $metadata = $item['metadata'] ?? [];
        $this->storage->addItem(
            $probe,
            $type,
            (string) ($item['name'] ?? $type),
            round(max(0.0, (float) ($item['containerSpace'] ?? 0.0)), 4),
            is_array($metadata) ? $metadata : [],
        );
    }

    /**
     * @param array<string, mixed> $recipeDefinition
     * @param array<string, array<ProbeItem>> $itemsByType
     * @param array<ProbeItem> $itemsToConsume
     * @param array<array<string, mixed>> $consumedItems
     * @param array<string, float> $resourceCosts
     * @param array<string> $path
     */
    private function resolveCraftingRecipe(
        array $recipeDefinition,
        array &$itemsByType,
        array &$itemsToConsume,
        array &$consumedItems,
        array &$resourceCosts,
        array $path = [],
    ): int {
        $recipeId = (string) ($recipeDefinition['id'] ?? ($recipeDefinition['output']['type'] ?? ''));
        if ($recipeId === '') {
            throw new MannyActionException(400, 'invalid_recipe', 'Unknown crafting recipe.');
        }
        if (in_array($recipeId, $path, true)) {
            throw new MannyActionException(422, 'invalid_recipe', 'Recursive crafting recipe cycle detected.');
        }

        $durationSeconds = max(0, (int) ($recipeDefinition['durationSeconds'] ?? 0));
        $ingredients = is_array($recipeDefinition['ingredients'] ?? null) ? $recipeDefinition['ingredients'] : [];
        foreach ($ingredients as $ingredient) {
            if (!is_array($ingredient)) {
                continue;
            }
            $type = (string) ($ingredient['type'] ?? '');
            if ($type === '') {
                continue;
            }

            if ($this->craftingIngredientKind($ingredient) !== 'item') {
                $this->addResourceCost(
                    $resourceCosts,
                    $this->normalizeCraftResourceType($type),
                    max(0.0, (float) ($ingredient['quantity'] ?? 0)),
                );
                continue;
            }

            $requiredCount = (int) ceil(max(0.0, (float) ($ingredient['quantity'] ?? 0)));
            $availableItems = $itemsByType[$type] ?? [];
            $consumedCount = min($requiredCount, count($availableItems));
            for ($index = 0; $index < $consumedCount; $index++) {
                $item = array_shift($availableItems);
                if (!$item instanceof ProbeItem) {
                    continue;
                }
                $itemsToConsume[] = $item;
                $consumedItems[] = $this->consumedItemPayload($item);
            }
            $itemsByType[$type] = $availableItems;

            $missingCount = $requiredCount - $consumedCount;
            if ($missingCount <= 0) {
                continue;
            }

            $componentRecipe = CraftingRecipeCatalog::find($type, $this->craftingConfig());
            if (
                $componentRecipe === null
                || (
                    !$this->recipeCraftableBy($componentRecipe, CraftingRecipeCatalog::FABRICATOR_MANNY)
                    && !$this->recipeCraftableBy($componentRecipe, CraftingRecipeCatalog::FABRICATOR_ATOMIC_PRINTER)
                )
            ) {
                throw new MannyActionException(422, 'insufficient_crafting_ingredients', 'Insufficient crafting ingredients for this recipe.');
            }

            for ($index = 0; $index < $missingCount; $index++) {
                $durationSeconds += $this->resolveCraftingRecipe(
                    $componentRecipe,
                    $itemsByType,
                    $itemsToConsume,
                    $consumedItems,
                    $resourceCosts,
                    [...$path, $recipeId],
                );
            }
        }

        return $durationSeconds;
    }

    /**
     * @param array<string, mixed> $ingredient
     */
    private function craftingIngredientKind(array $ingredient): string
    {
        if (isset($ingredient['kind'])) {
            return (string) $ingredient['kind'];
        }

        return ($ingredient['unit'] ?? null) === 'item' ? 'item' : 'resource';
    }

    /**
     * @return array<string, array<ProbeItem>>
     */
    private function probeItemsByType(NeumannProbe $probe): array
    {
        $itemsByType = [];
        foreach ($this->items->findByProbeId($probe->id) as $item) {
            $itemsByType[$item->type] ??= [];
            $itemsByType[$item->type][] = $item;
        }

        return $itemsByType;
    }

    /**
     * @param array<string, float> $resourceCosts
     */
    private function addResourceCost(array &$resourceCosts, string $type, float $quantity): void
    {
        $quantity = round(max(0.0, $quantity), 4);
        if ($quantity <= 0.0) {
            return;
        }

        $resourceCosts[$type] = round((float) ($resourceCosts[$type] ?? 0.0) + $quantity, 4);
    }

    private function normalizeCraftResourceType(string $type): string
    {
        try {
            return ResourceComposition::normalizeSelection($type)[0];
        } catch (\InvalidArgumentException) {
            return $type;
        }
    }

    /**
     * @param array<string, float> $resourceCosts
     */
    private function ensureResourceCostsAvailable(NeumannProbe $probe, array $resourceCosts): void
    {
        foreach ($resourceCosts as $type => $quantity) {
            if ($this->storage->resourceStock($probe, $type) + 0.00001 < $quantity) {
                throw new MannyActionException(422, 'insufficient_' . $type, 'Insufficient resources in probe inventory for this recipe.');
            }
        }
    }

    private function subtractResourceFromProbe(NeumannProbe $probe, string $type, float $quantity): void
    {
        $quantity = round(max(0.0, $quantity), 4);
        if ($quantity <= 0.0) {
            return;
        }

        $this->storage->consumeResource($probe, $type, $quantity);
    }

    private function createCraftedManny(NeumannProbe $probe, Manny $craftingManny, \DateTimeImmutable $now): void
    {
        $uid = $this->craftingOutputUid($craftingManny, 'manny', 'mny_craft_');
        $existing = $this->mannies->findByUid($uid);
        if ($existing !== null) {
            $this->ensureCraftedMannyStored($probe, $existing);
            return;
        }

        $name = $this->nextCraftedMannyName($probe);
        try {
            $newManny = $this->mannies->createForProbe($probe->id, $name, uid: $uid);
        } catch (\RuntimeException $e) {
            if ($this->mannies->findByUid($uid) !== null) {
                return;
            }

            throw $e;
        }
        if (!$this->storage->placeMannyOnProbe($probe, $newManny)) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for the crafted Manny.');
        }
        $newManny->taskPayload = [
            'craftedFromRecipe' => (string) ($craftingManny->taskPayload['recipe'] ?? 'manny'),
            'craftingRunId' => $this->craftingRunId($craftingManny, 'manny'),
            'craftedByMannyId' => $craftingManny->uid,
            'craftedByMannyName' => $craftingManny->name,
            'craftedAt' => $now->format('c'),
        ];
        $this->mannies->save($newManny);
    }

    private function ensureCraftedMannyStored(NeumannProbe $probe, Manny $manny): void
    {
        if ($manny->probeId !== $probe->id || !$manny->isOnProbe()) {
            throw new \RuntimeException('Crafted Manny uid already exists for another location.');
        }
        if ($manny->storageContainerId !== null) {
            return;
        }
        if (!$this->storage->placeMannyOnProbe($probe, $manny)) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for the crafted Manny.');
        }

        $this->mannies->save($manny);
    }

    private function craftingRunId(Manny $manny, string $outputType): string
    {
        $runId = $manny->taskPayload['craftingRunId'] ?? null;
        if (is_string($runId) && $runId !== '') {
            return $runId;
        }

        return hash('sha256', implode('|', [
            $manny->uid,
            (string) $manny->taskStartedAt,
            (string) ($manny->taskPayload['recipe'] ?? $outputType),
            $outputType,
            (string) ($manny->taskPayload['fabricator'] ?? CraftingRecipeCatalog::FABRICATOR_MANNY),
        ]));
    }

    private function craftingOutputUid(Manny $manny, string $outputType, string $prefix): string
    {
        return $prefix . substr(hash('sha256', $this->craftingRunId($manny, $outputType) . '|' . $outputType), 0, 24);
    }

    private function nextCraftedMannyName(NeumannProbe $probe): string
    {
        $names = array_fill_keys(array_map(
            static fn(Manny $manny): string => strtolower($manny->name),
            $this->mannies->findByProbeId($probe->id),
        ), true);

        for ($index = 1; $index <= 9999; $index++) {
            $name = 'manny-' . $index;
            if (!isset($names[strtolower($name)])) {
                return $name;
            }
        }

        return 'manny-' . bin2hex(random_bytes(4));
    }

    private function transferResourceToProbe(NeumannProbe $probe, string $resourceType, float $amount): float
    {
        $amount = round(max(0.0, $amount), 4);
        if ($amount <= 0.0) {
            return 0.0;
        }

        return $this->storage->addResource($probe, $resourceType, $amount);
    }

    private function itemDisplayName(string $type, ?string $fallback = null): string
    {
        return match ($type) {
            ProbeItem::TYPE_WAYPOINT_BOOKMARK => ProbeItem::WAYPOINT_BOOKMARK_NAME,
            ProbeItem::TYPE_STEEL_BAR => ProbeItem::STEEL_BAR_NAME,
            ProbeItem::TYPE_STEEL_PLATE => ProbeItem::STEEL_PLATE_NAME,
            ProbeItem::TYPE_ADDITIONAL_CONTAINER => ProbeItem::ADDITIONAL_CONTAINER_NAME,
            ProbeItem::TYPE_MICRO_CONDUCTOR => ProbeItem::MICRO_CONDUCTOR_NAME,
            ProbeItem::TYPE_CERAMIC_INSULATOR => ProbeItem::CERAMIC_INSULATOR_NAME,
            ProbeItem::TYPE_CRYSTAL_SUBSTRATE => ProbeItem::CRYSTAL_SUBSTRATE_NAME,
            ProbeItem::TYPE_DOPANT_MATRIX => ProbeItem::DOPANT_MATRIX_NAME,
            ProbeItem::TYPE_INTEGRATED_CIRCUIT => ProbeItem::INTEGRATED_CIRCUIT_NAME,
            ProbeItem::TYPE_ELECTRIC_MOTOR => ProbeItem::ELECTRIC_MOTOR_NAME,
            ProbeItem::TYPE_BATTERY_PACK => ProbeItem::BATTERY_PACK_NAME,
            ProbeItem::TYPE_LINEAR_ACTUATOR => ProbeItem::LINEAR_ACTUATOR_NAME,
            ProbeItem::TYPE_ATOMIC_PRINTER_PART => ProbeItem::ATOMIC_PRINTER_PART_NAME,
            ProbeItem::TYPE_DEUTERIUM_ENGINE => ProbeItem::DEUTERIUM_ENGINE_NAME,
            ProbeItem::TYPE_SOLAR_PANEL => ProbeItem::SOLAR_PANEL_NAME,
            ProbeItem::TYPE_SCUT_RELAY => ProbeItem::SCUT_RELAY_NAME,
            ProbeItem::TYPE_SCUT_TRANSIT_BEACON => ProbeItem::SCUT_TRANSIT_BEACON_NAME,
            ProbeItem::TYPE_THERMAL_PROTECTION_SHELL => ProbeItem::THERMAL_PROTECTION_SHELL_NAME,
            ProbeItem::TYPE_PARACHUTE_PACK => ProbeItem::PARACHUTE_PACK_NAME,
            ProbeItem::TYPE_DESCENT_GUIDANCE_MODULE => ProbeItem::DESCENT_GUIDANCE_MODULE_NAME,
            ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT => ProbeItem::ATMOSPHERIC_DROP_KIT_NAME,
            default => $fallback !== null && trim($fallback) !== '' ? $fallback : $type,
        };
    }

    private function craftingConfig(): array
    {
        return Config::getArray($this->config, 'crafting');
    }
}
