<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Config\Config;

final class CraftingRecipeCatalog
{
    public const FABRICATOR_MANNY = 'manny';
    public const FABRICATOR_ATOMIC_PRINTER = 'atomic_3d_printer';
    public const WAYPOINT_BOOKMARK_METALS_COST = 0.01;
    public const WAYPOINT_BOOKMARK_CONTAINER_SPACE = 0.01;
    public const WAYPOINT_BOOKMARK_CRAFTING_SECONDS = 600;
    public const STEEL_BAR_METALS_COST = 0.02;
    public const STEEL_BAR_CONTAINER_SPACE = 0.01;
    public const STEEL_BAR_CRAFTING_SECONDS = 300;
    public const STEEL_PLATE_METALS_COST = 0.02;
    public const STEEL_PLATE_CONTAINER_SPACE = 0.01;
    public const STEEL_PLATE_CRAFTING_SECONDS = 300;
    public const ADDITIONAL_CONTAINER_STEEL_PLATES = 12;
    public const ADDITIONAL_CONTAINER_STEEL_BARS = 15;
    public const ADDITIONAL_CONTAINER_CRAFTING_SECONDS = 180;
    public const ADDITIONAL_CONTAINER_CAPACITY_BONUS = 1.0;
    public const ADDITIONAL_CONTAINER_CONTAINER_SPACE = 0.0;
    public const MICRO_CONDUCTOR_METALS_COST = 0.04;
    public const MICRO_CONDUCTOR_DEUTERIUM_COST = 0.01;
    public const MICRO_CONDUCTOR_CONTAINER_SPACE = 0.005;
    public const MICRO_CONDUCTOR_CRAFTING_SECONDS = 600;
    public const CERAMIC_INSULATOR_ICE_COST = 0.03;
    public const CERAMIC_INSULATOR_ORGANIC_COST = 0.04;
    public const CERAMIC_INSULATOR_DEUTERIUM_COST = 0.01;
    public const CERAMIC_INSULATOR_CONTAINER_SPACE = 0.005;
    public const CERAMIC_INSULATOR_CRAFTING_SECONDS = 600;
    public const CRYSTAL_SUBSTRATE_METALS_COST = 0.08;
    public const CRYSTAL_SUBSTRATE_ICE_COST = 0.03;
    public const CRYSTAL_SUBSTRATE_DEUTERIUM_COST = 0.02;
    public const CRYSTAL_SUBSTRATE_CONTAINER_SPACE = 0.005;
    public const CRYSTAL_SUBSTRATE_CRAFTING_SECONDS = 900;
    public const DOPANT_MATRIX_METALS_COST = 0.04;
    public const DOPANT_MATRIX_ORGANIC_COST = 0.03;
    public const DOPANT_MATRIX_DEUTERIUM_COST = 0.02;
    public const DOPANT_MATRIX_CONTAINER_SPACE = 0.002;
    public const DOPANT_MATRIX_CRAFTING_SECONDS = 900;
    public const INTEGRATED_CIRCUIT_MICRO_CONDUCTORS = 2;
    public const INTEGRATED_CIRCUIT_CERAMIC_INSULATORS = 2;
    public const INTEGRATED_CIRCUIT_CRYSTAL_SUBSTRATES = 1;
    public const INTEGRATED_CIRCUIT_DOPANT_MATRICES = 1;
    public const INTEGRATED_CIRCUIT_DEUTERIUM_COST = 0.05;
    public const INTEGRATED_CIRCUIT_CONTAINER_SPACE = 0.001;
    public const INTEGRATED_CIRCUIT_CRAFTING_SECONDS = 1200;
    public const ELECTRIC_MOTOR_STEEL_BARS = 2;
    public const ELECTRIC_MOTOR_STEEL_PLATES = 1;
    public const ELECTRIC_MOTOR_METALS_COST = 0.02;
    public const ELECTRIC_MOTOR_CONTAINER_SPACE = 0.006;
    public const ELECTRIC_MOTOR_CRAFTING_SECONDS = 900;
    public const BATTERY_PACK_METALS_COST = 0.03;
    public const BATTERY_PACK_ICE_COST = 0.02;
    public const BATTERY_PACK_ORGANIC_COST = 0.06;
    public const BATTERY_PACK_DEUTERIUM_COST = 0.02;
    public const BATTERY_PACK_CONTAINER_SPACE = 0.008;
    public const BATTERY_PACK_CRAFTING_SECONDS = 1200;
    public const LINEAR_ACTUATOR_STEEL_PLATES = 2;
    public const LINEAR_ACTUATOR_STEEL_BARS = 1;
    public const LINEAR_ACTUATOR_ELECTRIC_MOTORS = 1;
    public const LINEAR_ACTUATOR_METALS_COST = 0.01;
    public const LINEAR_ACTUATOR_CONTAINER_SPACE = 0.01;
    public const LINEAR_ACTUATOR_CRAFTING_SECONDS = 1200;
    public const MANNY_LINEAR_ACTUATORS = 6;
    public const MANNY_ELECTRIC_MOTORS = 12;
    public const MANNY_BATTERY_PACKS = 4;
    public const MANNY_INTEGRATED_CIRCUITS = 6;
    public const MANNY_STEEL_PLATES = 18;
    public const MANNY_STEEL_BARS = 12;
    public const MANNY_CONTAINER_SPACE = 0.05;
    public const MANNY_CARGO_CAPACITY = 0.05;
    public const MANNY_CRAFTING_SECONDS = 3600;

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(array $config = []): array
    {
        return [
            self::waypointBookmark($config),
            self::steelBar($config),
            self::steelPlate($config),
            self::additionalContainer($config),
            self::microConductor($config),
            self::ceramicInsulator($config),
            self::crystalSubstrate($config),
            self::dopantMatrix($config),
            self::integratedCircuit($config),
            self::electricMotor($config),
            self::batteryPack($config),
            self::linearActuator($config),
            self::manny($config),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id, array $config = []): ?array
    {
        $id = self::normalizeId($id);
        foreach (self::all($config) as $recipe) {
            if ($recipe['id'] === $id) {
                return $recipe;
            }
        }

        return null;
    }

    public static function normalizeId(string $id): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($id)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function waypointBookmark(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_WAYPOINT_BOOKMARK,
            'name' => ProbeItem::WAYPOINT_BOOKMARK_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                [
                    'type' => ResourceComposition::METALS,
                    'quantity' => Config::float($config, 'waypoint_bookmark.metalsCost', self::WAYPOINT_BOOKMARK_METALS_COST),
                    'unit' => ProbeInventory::CAPACITY_UNIT,
                    'kind' => 'resource',
                ],
            ],
            'durationSeconds' => Config::int($config, 'waypoint_bookmark.durationSeconds', self::WAYPOINT_BOOKMARK_CRAFTING_SECONDS),
            'output' => [
                'type' => ProbeItem::TYPE_WAYPOINT_BOOKMARK,
                'name' => ProbeItem::WAYPOINT_BOOKMARK_NAME,
                'containerSpace' => Config::float($config, 'waypoint_bookmark.containerSpace', self::WAYPOINT_BOOKMARK_CONTAINER_SPACE),
                'containerSpaceUnit' => ProbeInventory::CAPACITY_UNIT,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function steelBar(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_STEEL_BAR,
            'name' => ProbeItem::STEEL_BAR_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                [
                    'type' => ResourceComposition::METALS,
                    'quantity' => Config::float($config, 'steel_bar.metalsCost', self::STEEL_BAR_METALS_COST),
                    'unit' => ProbeInventory::CAPACITY_UNIT,
                    'kind' => 'resource',
                ],
            ],
            'durationSeconds' => Config::int($config, 'steel_bar.durationSeconds', self::STEEL_BAR_CRAFTING_SECONDS),
            'output' => [
                'type' => ProbeItem::TYPE_STEEL_BAR,
                'name' => ProbeItem::STEEL_BAR_NAME,
                'containerSpace' => Config::float($config, 'steel_bar.containerSpace', self::STEEL_BAR_CONTAINER_SPACE),
                'containerSpaceUnit' => ProbeInventory::CAPACITY_UNIT,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function steelPlate(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_STEEL_PLATE,
            'name' => ProbeItem::STEEL_PLATE_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                [
                    'type' => ResourceComposition::METALS,
                    'quantity' => Config::float($config, 'steel_plate.metalsCost', self::STEEL_PLATE_METALS_COST),
                    'unit' => ProbeInventory::CAPACITY_UNIT,
                    'kind' => 'resource',
                ],
            ],
            'durationSeconds' => Config::int($config, 'steel_plate.durationSeconds', self::STEEL_PLATE_CRAFTING_SECONDS),
            'output' => [
                'type' => ProbeItem::TYPE_STEEL_PLATE,
                'name' => ProbeItem::STEEL_PLATE_NAME,
                'containerSpace' => Config::float($config, 'steel_plate.containerSpace', self::STEEL_PLATE_CONTAINER_SPACE),
                'containerSpaceUnit' => ProbeInventory::CAPACITY_UNIT,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function additionalContainer(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_ADDITIONAL_CONTAINER,
            'name' => ProbeItem::ADDITIONAL_CONTAINER_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                [
                    'type' => ProbeItem::TYPE_STEEL_PLATE,
                    'quantity' => Config::int($config, 'additional_container.steelPlateCount', self::ADDITIONAL_CONTAINER_STEEL_PLATES),
                    'unit' => 'item',
                    'kind' => 'item',
                ],
                [
                    'type' => ProbeItem::TYPE_STEEL_BAR,
                    'quantity' => Config::int($config, 'additional_container.steelBarCount', self::ADDITIONAL_CONTAINER_STEEL_BARS),
                    'unit' => 'item',
                    'kind' => 'item',
                ],
            ],
            'durationSeconds' => Config::int($config, 'additional_container.durationSeconds', self::ADDITIONAL_CONTAINER_CRAFTING_SECONDS),
            'output' => [
                'type' => ProbeItem::TYPE_ADDITIONAL_CONTAINER,
                'name' => ProbeItem::ADDITIONAL_CONTAINER_NAME,
                'containerSpace' => Config::float($config, 'additional_container.containerSpace', self::ADDITIONAL_CONTAINER_CONTAINER_SPACE),
                'containerSpaceUnit' => ProbeInventory::CAPACITY_UNIT,
                'capacityBonus' => Config::float($config, 'additional_container.capacityBonus', self::ADDITIONAL_CONTAINER_CAPACITY_BONUS),
                'capacityBonusUnit' => ProbeInventory::CAPACITY_UNIT,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function microConductor(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_MICRO_CONDUCTOR,
            'name' => ProbeItem::MICRO_CONDUCTOR_NAME,
            'craftableBy' => [self::FABRICATOR_ATOMIC_PRINTER],
            'ingredients' => [
                self::resourceIngredient(ResourceComposition::METALS, Config::float($config, 'micro_conductor.metalsCost', self::MICRO_CONDUCTOR_METALS_COST)),
                self::resourceIngredient(ResourceComposition::DEUTERIUM, Config::float($config, 'micro_conductor.deuteriumCost', self::MICRO_CONDUCTOR_DEUTERIUM_COST)),
            ],
            'durationSeconds' => Config::int($config, 'micro_conductor.durationSeconds', self::MICRO_CONDUCTOR_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_MICRO_CONDUCTOR,
                ProbeItem::MICRO_CONDUCTOR_NAME,
                Config::float($config, 'micro_conductor.containerSpace', self::MICRO_CONDUCTOR_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ceramicInsulator(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_CERAMIC_INSULATOR,
            'name' => ProbeItem::CERAMIC_INSULATOR_NAME,
            'craftableBy' => [self::FABRICATOR_ATOMIC_PRINTER],
            'ingredients' => [
                self::resourceIngredient(ResourceComposition::ICE, Config::float($config, 'ceramic_insulator.iceCost', self::CERAMIC_INSULATOR_ICE_COST)),
                self::resourceIngredient(ResourceComposition::CARBON_COMPOUNDS, Config::float($config, 'ceramic_insulator.organicCost', self::CERAMIC_INSULATOR_ORGANIC_COST)),
                self::resourceIngredient(ResourceComposition::DEUTERIUM, Config::float($config, 'ceramic_insulator.deuteriumCost', self::CERAMIC_INSULATOR_DEUTERIUM_COST)),
            ],
            'durationSeconds' => Config::int($config, 'ceramic_insulator.durationSeconds', self::CERAMIC_INSULATOR_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_CERAMIC_INSULATOR,
                ProbeItem::CERAMIC_INSULATOR_NAME,
                Config::float($config, 'ceramic_insulator.containerSpace', self::CERAMIC_INSULATOR_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function crystalSubstrate(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_CRYSTAL_SUBSTRATE,
            'name' => ProbeItem::CRYSTAL_SUBSTRATE_NAME,
            'craftableBy' => [self::FABRICATOR_ATOMIC_PRINTER],
            'ingredients' => [
                self::resourceIngredient(ResourceComposition::METALS, Config::float($config, 'crystal_substrate.metalsCost', self::CRYSTAL_SUBSTRATE_METALS_COST)),
                self::resourceIngredient(ResourceComposition::ICE, Config::float($config, 'crystal_substrate.iceCost', self::CRYSTAL_SUBSTRATE_ICE_COST)),
                self::resourceIngredient(ResourceComposition::DEUTERIUM, Config::float($config, 'crystal_substrate.deuteriumCost', self::CRYSTAL_SUBSTRATE_DEUTERIUM_COST)),
            ],
            'durationSeconds' => Config::int($config, 'crystal_substrate.durationSeconds', self::CRYSTAL_SUBSTRATE_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_CRYSTAL_SUBSTRATE,
                ProbeItem::CRYSTAL_SUBSTRATE_NAME,
                Config::float($config, 'crystal_substrate.containerSpace', self::CRYSTAL_SUBSTRATE_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function dopantMatrix(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_DOPANT_MATRIX,
            'name' => ProbeItem::DOPANT_MATRIX_NAME,
            'craftableBy' => [self::FABRICATOR_ATOMIC_PRINTER],
            'ingredients' => [
                self::resourceIngredient(ResourceComposition::METALS, Config::float($config, 'dopant_matrix.metalsCost', self::DOPANT_MATRIX_METALS_COST)),
                self::resourceIngredient(ResourceComposition::CARBON_COMPOUNDS, Config::float($config, 'dopant_matrix.organicCost', self::DOPANT_MATRIX_ORGANIC_COST)),
                self::resourceIngredient(ResourceComposition::DEUTERIUM, Config::float($config, 'dopant_matrix.deuteriumCost', self::DOPANT_MATRIX_DEUTERIUM_COST)),
            ],
            'durationSeconds' => Config::int($config, 'dopant_matrix.durationSeconds', self::DOPANT_MATRIX_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_DOPANT_MATRIX,
                ProbeItem::DOPANT_MATRIX_NAME,
                Config::float($config, 'dopant_matrix.containerSpace', self::DOPANT_MATRIX_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function integratedCircuit(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_INTEGRATED_CIRCUIT,
            'name' => ProbeItem::INTEGRATED_CIRCUIT_NAME,
            'craftableBy' => [self::FABRICATOR_ATOMIC_PRINTER],
            'ingredients' => [
                self::itemIngredient(ProbeItem::TYPE_MICRO_CONDUCTOR, Config::int($config, 'integrated_circuit.microConductorCount', self::INTEGRATED_CIRCUIT_MICRO_CONDUCTORS)),
                self::itemIngredient(ProbeItem::TYPE_CERAMIC_INSULATOR, Config::int($config, 'integrated_circuit.ceramicInsulatorCount', self::INTEGRATED_CIRCUIT_CERAMIC_INSULATORS)),
                self::itemIngredient(ProbeItem::TYPE_CRYSTAL_SUBSTRATE, Config::int($config, 'integrated_circuit.crystalSubstrateCount', self::INTEGRATED_CIRCUIT_CRYSTAL_SUBSTRATES)),
                self::itemIngredient(ProbeItem::TYPE_DOPANT_MATRIX, Config::int($config, 'integrated_circuit.dopantMatrixCount', self::INTEGRATED_CIRCUIT_DOPANT_MATRICES)),
                self::resourceIngredient(ResourceComposition::DEUTERIUM, Config::float($config, 'integrated_circuit.deuteriumCost', self::INTEGRATED_CIRCUIT_DEUTERIUM_COST)),
            ],
            'durationSeconds' => Config::int($config, 'integrated_circuit.durationSeconds', self::INTEGRATED_CIRCUIT_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_INTEGRATED_CIRCUIT,
                ProbeItem::INTEGRATED_CIRCUIT_NAME,
                Config::float($config, 'integrated_circuit.containerSpace', self::INTEGRATED_CIRCUIT_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function electricMotor(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_ELECTRIC_MOTOR,
            'name' => ProbeItem::ELECTRIC_MOTOR_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                self::itemIngredient(ProbeItem::TYPE_STEEL_BAR, Config::int($config, 'electric_motor.steelBarCount', self::ELECTRIC_MOTOR_STEEL_BARS)),
                self::itemIngredient(ProbeItem::TYPE_STEEL_PLATE, Config::int($config, 'electric_motor.steelPlateCount', self::ELECTRIC_MOTOR_STEEL_PLATES)),
                self::resourceIngredient(ResourceComposition::METALS, Config::float($config, 'electric_motor.metalsCost', self::ELECTRIC_MOTOR_METALS_COST)),
            ],
            'durationSeconds' => Config::int($config, 'electric_motor.durationSeconds', self::ELECTRIC_MOTOR_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_ELECTRIC_MOTOR,
                ProbeItem::ELECTRIC_MOTOR_NAME,
                Config::float($config, 'electric_motor.containerSpace', self::ELECTRIC_MOTOR_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function batteryPack(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_BATTERY_PACK,
            'name' => ProbeItem::BATTERY_PACK_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                self::resourceIngredient(ResourceComposition::METALS, Config::float($config, 'battery_pack.metalsCost', self::BATTERY_PACK_METALS_COST)),
                self::resourceIngredient(ResourceComposition::ICE, Config::float($config, 'battery_pack.iceCost', self::BATTERY_PACK_ICE_COST)),
                self::resourceIngredient(ResourceComposition::CARBON_COMPOUNDS, Config::float($config, 'battery_pack.organicCost', self::BATTERY_PACK_ORGANIC_COST)),
                self::resourceIngredient(ResourceComposition::DEUTERIUM, Config::float($config, 'battery_pack.deuteriumCost', self::BATTERY_PACK_DEUTERIUM_COST)),
            ],
            'durationSeconds' => Config::int($config, 'battery_pack.durationSeconds', self::BATTERY_PACK_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_BATTERY_PACK,
                ProbeItem::BATTERY_PACK_NAME,
                Config::float($config, 'battery_pack.containerSpace', self::BATTERY_PACK_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function linearActuator(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_LINEAR_ACTUATOR,
            'name' => ProbeItem::LINEAR_ACTUATOR_NAME,
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                self::itemIngredient(ProbeItem::TYPE_STEEL_PLATE, Config::int($config, 'linear_actuator.steelPlateCount', self::LINEAR_ACTUATOR_STEEL_PLATES)),
                self::itemIngredient(ProbeItem::TYPE_STEEL_BAR, Config::int($config, 'linear_actuator.steelBarCount', self::LINEAR_ACTUATOR_STEEL_BARS)),
                self::itemIngredient(ProbeItem::TYPE_ELECTRIC_MOTOR, Config::int($config, 'linear_actuator.electricMotorCount', self::LINEAR_ACTUATOR_ELECTRIC_MOTORS)),
                self::resourceIngredient(ResourceComposition::METALS, Config::float($config, 'linear_actuator.metalsCost', self::LINEAR_ACTUATOR_METALS_COST)),
            ],
            'durationSeconds' => Config::int($config, 'linear_actuator.durationSeconds', self::LINEAR_ACTUATOR_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_LINEAR_ACTUATOR,
                ProbeItem::LINEAR_ACTUATOR_NAME,
                Config::float($config, 'linear_actuator.containerSpace', self::LINEAR_ACTUATOR_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function manny(array $config): array
    {
        return [
            'id' => 'manny',
            'name' => 'Manny',
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                self::itemIngredient(ProbeItem::TYPE_LINEAR_ACTUATOR, Config::int($config, 'manny.linearActuatorCount', self::MANNY_LINEAR_ACTUATORS)),
                self::itemIngredient(ProbeItem::TYPE_ELECTRIC_MOTOR, Config::int($config, 'manny.electricMotorCount', self::MANNY_ELECTRIC_MOTORS)),
                self::itemIngredient(ProbeItem::TYPE_BATTERY_PACK, Config::int($config, 'manny.batteryPackCount', self::MANNY_BATTERY_PACKS)),
                self::itemIngredient(ProbeItem::TYPE_INTEGRATED_CIRCUIT, Config::int($config, 'manny.integratedCircuitCount', self::MANNY_INTEGRATED_CIRCUITS)),
                self::itemIngredient(ProbeItem::TYPE_STEEL_PLATE, Config::int($config, 'manny.steelPlateCount', self::MANNY_STEEL_PLATES)),
                self::itemIngredient(ProbeItem::TYPE_STEEL_BAR, Config::int($config, 'manny.steelBarCount', self::MANNY_STEEL_BARS)),
            ],
            'durationSeconds' => Config::int($config, 'manny.durationSeconds', self::MANNY_CRAFTING_SECONDS),
            'output' => [
                'type' => 'manny',
                'name' => 'Manny',
                'containerSpace' => Config::float($config, 'manny.containerSpace', self::MANNY_CONTAINER_SPACE),
                'containerSpaceUnit' => ProbeInventory::CAPACITY_UNIT,
                'cargoCapacity' => Config::float($config, 'manny.cargoCapacity', self::MANNY_CARGO_CAPACITY),
                'cargoCapacityUnit' => ProbeInventory::CAPACITY_UNIT,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function resourceIngredient(string $type, float $quantity): array
    {
        return [
            'type' => $type,
            'quantity' => $quantity,
            'unit' => ProbeInventory::CAPACITY_UNIT,
            'kind' => 'resource',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function itemIngredient(string $type, int $quantity): array
    {
        return [
            'type' => $type,
            'quantity' => $quantity,
            'unit' => 'item',
            'kind' => 'item',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function itemOutput(string $type, string $name, float $containerSpace): array
    {
        return [
            'type' => $type,
            'name' => $name,
            'containerSpace' => $containerSpace,
            'containerSpaceUnit' => ProbeInventory::CAPACITY_UNIT,
        ];
    }
}
