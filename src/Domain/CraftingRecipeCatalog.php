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
    public const SOLAR_PANEL_MICRO_CONDUCTORS = 2;
    public const SOLAR_PANEL_CRYSTAL_SUBSTRATES = 1;
    public const SOLAR_PANEL_CERAMIC_INSULATORS = 1;
    public const SOLAR_PANEL_STEEL_PLATES = 1;
    public const SOLAR_PANEL_METALS_COST = 0.02;
    public const SOLAR_PANEL_CONTAINER_SPACE = 0.015;
    public const SOLAR_PANEL_CRAFTING_SECONDS = 1800;
    public const SCUT_RELAY_STEEL_PLATES = 32;
    public const SCUT_RELAY_STEEL_BARS = 28;
    public const SCUT_RELAY_BATTERY_PACKS = 6;
    public const SCUT_RELAY_SOLAR_PANELS = 4;
    public const SCUT_RELAY_INTEGRATED_CIRCUITS = 5;
    public const SCUT_RELAY_ELECTRIC_MOTORS = 4;
    public const SCUT_RELAY_LINEAR_ACTUATORS = 2;
    public const SCUT_RELAY_CONTAINER_SPACE = 0.12;
    public const SCUT_RELAY_CRAFTING_SECONDS = 172800;
    public const THERMAL_PROTECTION_SHELL_CERAMIC_INSULATORS = 8;
    public const THERMAL_PROTECTION_SHELL_STEEL_PLATES = 4;
    public const THERMAL_PROTECTION_SHELL_ORGANIC_COST = 0.06;
    public const THERMAL_PROTECTION_SHELL_DEUTERIUM_COST = 0.02;
    public const THERMAL_PROTECTION_SHELL_CONTAINER_SPACE = 0.035;
    public const THERMAL_PROTECTION_SHELL_CRAFTING_SECONDS = 2400;
    public const PARACHUTE_PACK_STEEL_BARS = 2;
    public const PARACHUTE_PACK_STEEL_PLATES = 2;
    public const PARACHUTE_PACK_ORGANIC_COST = 0.18;
    public const PARACHUTE_PACK_ICE_COST = 0.05;
    public const PARACHUTE_PACK_CONTAINER_SPACE = 0.045;
    public const PARACHUTE_PACK_CRAFTING_SECONDS = 1800;
    public const DESCENT_GUIDANCE_MODULE_INTEGRATED_CIRCUITS = 2;
    public const DESCENT_GUIDANCE_MODULE_BATTERY_PACKS = 1;
    public const DESCENT_GUIDANCE_MODULE_LINEAR_ACTUATORS = 2;
    public const DESCENT_GUIDANCE_MODULE_STEEL_PLATES = 2;
    public const DESCENT_GUIDANCE_MODULE_DEUTERIUM_COST = 0.03;
    public const DESCENT_GUIDANCE_MODULE_CONTAINER_SPACE = 0.035;
    public const DESCENT_GUIDANCE_MODULE_CRAFTING_SECONDS = 2400;
    public const ATMOSPHERIC_DROP_KIT_THERMAL_PROTECTION_SHELLS = 1;
    public const ATMOSPHERIC_DROP_KIT_PARACHUTE_PACKS = 1;
    public const ATMOSPHERIC_DROP_KIT_DESCENT_GUIDANCE_MODULES = 1;
    public const ATMOSPHERIC_DROP_KIT_STEEL_PLATES = 4;
    public const ATMOSPHERIC_DROP_KIT_STEEL_BARS = 2;
    public const ATMOSPHERIC_DROP_KIT_CONTAINER_SPACE = 0.08;
    public const ATMOSPHERIC_DROP_KIT_CRAFTING_SECONDS = 3600;
    public const MANNY_LINEAR_ACTUATORS = 6;
    public const MANNY_ELECTRIC_MOTORS = 12;
    public const MANNY_BATTERY_PACKS = 4;
    public const MANNY_INTEGRATED_CIRCUITS = 6;
    public const MANNY_STEEL_PLATES = 18;
    public const MANNY_STEEL_BARS = 12;
    public const MANNY_CONTAINER_SPACE = 0.05;
    public const MANNY_CARGO_CAPACITY = 0.05;
    public const MANNY_CRAFTING_SECONDS = 3600;
    private const DEFAULT_DESCRIPTIONS = [
        'waypoint_bookmark' => 'A transmitting beacon placed on an object such as an asteroid or planet, or set in orbit around a star or gas giant. Its message can be read by every Neumann probe present in the sector.',
        'steel_bar' => 'A rigid structural bar used in frames, rails, braces, and heavy mechanical assemblies.',
        'steel_plate' => 'A flat reinforced plate suited to hull patches, container walls, and broad mounting surfaces.',
        'additional_container' => 'A fold-out storage module that expands the probe cargo volume once assembled.',
        'micro_conductor' => 'A precision conductor etched at microscopic scale for high-density electronic paths.',
        'ceramic_insulator' => 'A heat-stable insulating part grown from volatile ice and carbon compounds.',
        'crystal_substrate' => 'A polished crystalline base that keeps printed circuits aligned and thermally stable.',
        'dopant_matrix' => 'A controlled impurity matrix that tunes semiconductor behavior during atomic printing.',
        'integrated_circuit' => 'A dense logic component combining conductors, insulators, substrate, and dopants.',
        'electric_motor' => 'A compact rotary actuator that converts stored electrical energy into mechanical motion.',
        'battery_pack' => 'A rechargeable power pack for mobile tools, actuators, and autonomous Manny systems.',
        'linear_actuator' => 'A precise push-pull mechanism used wherever a Manny needs controlled force.',
        'solar_panel' => 'A foldable photovoltaic panel with printed conductors, crystal substrate, and a reinforced mounting plate.',
        'scut_relay' => 'A long-range SCUT communication relay module with its own solar power array, buffer batteries, control circuits, and deployable structure.',
        'thermal_protection_shell' => 'A disposable ablative shell that protects a storage container during atmospheric entry.',
        'parachute_pack' => 'A folded braking canopy pack with compact deployment hardware for cargo descents.',
        'descent_guidance_module' => 'A small avionics and actuator package for limited steering during a cargo drop.',
        'atmospheric_drop_kit' => 'A disposable descent kit for dropping one storage container through an atmosphere with ablative shielding, parachute braking, and limited steering authority.',
        'manny' => 'A fully assembled maintenance unit able to repair, mine, carry cargo, and build new parts.',
    ];

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
            self::solarPanel($config),
            self::scutRelay($config),
            self::thermalProtectionShell($config),
            self::parachutePack($config),
            self::descentGuidanceModule($config),
            self::atmosphericDropKit($config),
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
            'description' => self::description($config, ProbeItem::TYPE_WAYPOINT_BOOKMARK),
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
            'description' => self::description($config, ProbeItem::TYPE_STEEL_BAR),
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
            'description' => self::description($config, ProbeItem::TYPE_STEEL_PLATE),
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
            'description' => self::description($config, ProbeItem::TYPE_ADDITIONAL_CONTAINER),
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
            'description' => self::description($config, ProbeItem::TYPE_MICRO_CONDUCTOR),
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
            'description' => self::description($config, ProbeItem::TYPE_CERAMIC_INSULATOR),
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
            'description' => self::description($config, ProbeItem::TYPE_CRYSTAL_SUBSTRATE),
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
            'description' => self::description($config, ProbeItem::TYPE_DOPANT_MATRIX),
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
            'description' => self::description($config, ProbeItem::TYPE_INTEGRATED_CIRCUIT),
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
            'description' => self::description($config, ProbeItem::TYPE_ELECTRIC_MOTOR),
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
            'description' => self::description($config, ProbeItem::TYPE_BATTERY_PACK),
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
            'description' => self::description($config, ProbeItem::TYPE_LINEAR_ACTUATOR),
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
    private static function solarPanel(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_SOLAR_PANEL,
            'name' => ProbeItem::SOLAR_PANEL_NAME,
            'description' => self::description($config, ProbeItem::TYPE_SOLAR_PANEL),
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                self::itemIngredient(ProbeItem::TYPE_MICRO_CONDUCTOR, Config::int($config, 'solar_panel.microConductorCount', self::SOLAR_PANEL_MICRO_CONDUCTORS)),
                self::itemIngredient(ProbeItem::TYPE_CRYSTAL_SUBSTRATE, Config::int($config, 'solar_panel.crystalSubstrateCount', self::SOLAR_PANEL_CRYSTAL_SUBSTRATES)),
                self::itemIngredient(ProbeItem::TYPE_CERAMIC_INSULATOR, Config::int($config, 'solar_panel.ceramicInsulatorCount', self::SOLAR_PANEL_CERAMIC_INSULATORS)),
                self::itemIngredient(ProbeItem::TYPE_STEEL_PLATE, Config::int($config, 'solar_panel.steelPlateCount', self::SOLAR_PANEL_STEEL_PLATES)),
                self::resourceIngredient(ResourceComposition::METALS, Config::float($config, 'solar_panel.metalsCost', self::SOLAR_PANEL_METALS_COST)),
            ],
            'durationSeconds' => Config::int($config, 'solar_panel.durationSeconds', self::SOLAR_PANEL_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_SOLAR_PANEL,
                ProbeItem::SOLAR_PANEL_NAME,
                Config::float($config, 'solar_panel.containerSpace', self::SOLAR_PANEL_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function scutRelay(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_SCUT_RELAY,
            'name' => ProbeItem::SCUT_RELAY_NAME,
            'description' => self::description($config, ProbeItem::TYPE_SCUT_RELAY),
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                self::itemIngredient(ProbeItem::TYPE_STEEL_PLATE, Config::int($config, 'scut_relay.steelPlateCount', self::SCUT_RELAY_STEEL_PLATES)),
                self::itemIngredient(ProbeItem::TYPE_STEEL_BAR, Config::int($config, 'scut_relay.steelBarCount', self::SCUT_RELAY_STEEL_BARS)),
                self::itemIngredient(ProbeItem::TYPE_BATTERY_PACK, Config::int($config, 'scut_relay.batteryPackCount', self::SCUT_RELAY_BATTERY_PACKS)),
                self::itemIngredient(ProbeItem::TYPE_SOLAR_PANEL, Config::int($config, 'scut_relay.solarPanelCount', self::SCUT_RELAY_SOLAR_PANELS)),
                self::itemIngredient(ProbeItem::TYPE_INTEGRATED_CIRCUIT, Config::int($config, 'scut_relay.integratedCircuitCount', self::SCUT_RELAY_INTEGRATED_CIRCUITS)),
                self::itemIngredient(ProbeItem::TYPE_ELECTRIC_MOTOR, Config::int($config, 'scut_relay.electricMotorCount', self::SCUT_RELAY_ELECTRIC_MOTORS)),
                self::itemIngredient(ProbeItem::TYPE_LINEAR_ACTUATOR, Config::int($config, 'scut_relay.linearActuatorCount', self::SCUT_RELAY_LINEAR_ACTUATORS)),
            ],
            'durationSeconds' => Config::int($config, 'scut_relay.durationSeconds', self::SCUT_RELAY_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_SCUT_RELAY,
                ProbeItem::SCUT_RELAY_NAME,
                Config::float($config, 'scut_relay.containerSpace', self::SCUT_RELAY_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function thermalProtectionShell(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_THERMAL_PROTECTION_SHELL,
            'name' => ProbeItem::THERMAL_PROTECTION_SHELL_NAME,
            'description' => self::description($config, ProbeItem::TYPE_THERMAL_PROTECTION_SHELL),
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                self::itemIngredient(ProbeItem::TYPE_CERAMIC_INSULATOR, Config::int($config, 'thermal_protection_shell.ceramicInsulatorCount', self::THERMAL_PROTECTION_SHELL_CERAMIC_INSULATORS)),
                self::itemIngredient(ProbeItem::TYPE_STEEL_PLATE, Config::int($config, 'thermal_protection_shell.steelPlateCount', self::THERMAL_PROTECTION_SHELL_STEEL_PLATES)),
                self::resourceIngredient(ResourceComposition::CARBON_COMPOUNDS, Config::float($config, 'thermal_protection_shell.organicCost', self::THERMAL_PROTECTION_SHELL_ORGANIC_COST)),
                self::resourceIngredient(ResourceComposition::DEUTERIUM, Config::float($config, 'thermal_protection_shell.deuteriumCost', self::THERMAL_PROTECTION_SHELL_DEUTERIUM_COST)),
            ],
            'durationSeconds' => Config::int($config, 'thermal_protection_shell.durationSeconds', self::THERMAL_PROTECTION_SHELL_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_THERMAL_PROTECTION_SHELL,
                ProbeItem::THERMAL_PROTECTION_SHELL_NAME,
                Config::float($config, 'thermal_protection_shell.containerSpace', self::THERMAL_PROTECTION_SHELL_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parachutePack(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_PARACHUTE_PACK,
            'name' => ProbeItem::PARACHUTE_PACK_NAME,
            'description' => self::description($config, ProbeItem::TYPE_PARACHUTE_PACK),
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                self::itemIngredient(ProbeItem::TYPE_STEEL_BAR, Config::int($config, 'parachute_pack.steelBarCount', self::PARACHUTE_PACK_STEEL_BARS)),
                self::itemIngredient(ProbeItem::TYPE_STEEL_PLATE, Config::int($config, 'parachute_pack.steelPlateCount', self::PARACHUTE_PACK_STEEL_PLATES)),
                self::resourceIngredient(ResourceComposition::CARBON_COMPOUNDS, Config::float($config, 'parachute_pack.organicCost', self::PARACHUTE_PACK_ORGANIC_COST)),
                self::resourceIngredient(ResourceComposition::ICE, Config::float($config, 'parachute_pack.iceCost', self::PARACHUTE_PACK_ICE_COST)),
            ],
            'durationSeconds' => Config::int($config, 'parachute_pack.durationSeconds', self::PARACHUTE_PACK_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_PARACHUTE_PACK,
                ProbeItem::PARACHUTE_PACK_NAME,
                Config::float($config, 'parachute_pack.containerSpace', self::PARACHUTE_PACK_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function descentGuidanceModule(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_DESCENT_GUIDANCE_MODULE,
            'name' => ProbeItem::DESCENT_GUIDANCE_MODULE_NAME,
            'description' => self::description($config, ProbeItem::TYPE_DESCENT_GUIDANCE_MODULE),
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                self::itemIngredient(ProbeItem::TYPE_INTEGRATED_CIRCUIT, Config::int($config, 'descent_guidance_module.integratedCircuitCount', self::DESCENT_GUIDANCE_MODULE_INTEGRATED_CIRCUITS)),
                self::itemIngredient(ProbeItem::TYPE_BATTERY_PACK, Config::int($config, 'descent_guidance_module.batteryPackCount', self::DESCENT_GUIDANCE_MODULE_BATTERY_PACKS)),
                self::itemIngredient(ProbeItem::TYPE_LINEAR_ACTUATOR, Config::int($config, 'descent_guidance_module.linearActuatorCount', self::DESCENT_GUIDANCE_MODULE_LINEAR_ACTUATORS)),
                self::itemIngredient(ProbeItem::TYPE_STEEL_PLATE, Config::int($config, 'descent_guidance_module.steelPlateCount', self::DESCENT_GUIDANCE_MODULE_STEEL_PLATES)),
                self::resourceIngredient(ResourceComposition::DEUTERIUM, Config::float($config, 'descent_guidance_module.deuteriumCost', self::DESCENT_GUIDANCE_MODULE_DEUTERIUM_COST)),
            ],
            'durationSeconds' => Config::int($config, 'descent_guidance_module.durationSeconds', self::DESCENT_GUIDANCE_MODULE_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_DESCENT_GUIDANCE_MODULE,
                ProbeItem::DESCENT_GUIDANCE_MODULE_NAME,
                Config::float($config, 'descent_guidance_module.containerSpace', self::DESCENT_GUIDANCE_MODULE_CONTAINER_SPACE),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function atmosphericDropKit(array $config): array
    {
        return [
            'id' => ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT,
            'name' => ProbeItem::ATMOSPHERIC_DROP_KIT_NAME,
            'description' => self::description($config, ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT),
            'craftableBy' => [self::FABRICATOR_MANNY],
            'ingredients' => [
                self::itemIngredient(ProbeItem::TYPE_THERMAL_PROTECTION_SHELL, Config::int($config, 'atmospheric_drop_kit.thermalProtectionShellCount', self::ATMOSPHERIC_DROP_KIT_THERMAL_PROTECTION_SHELLS)),
                self::itemIngredient(ProbeItem::TYPE_PARACHUTE_PACK, Config::int($config, 'atmospheric_drop_kit.parachutePackCount', self::ATMOSPHERIC_DROP_KIT_PARACHUTE_PACKS)),
                self::itemIngredient(ProbeItem::TYPE_DESCENT_GUIDANCE_MODULE, Config::int($config, 'atmospheric_drop_kit.descentGuidanceModuleCount', self::ATMOSPHERIC_DROP_KIT_DESCENT_GUIDANCE_MODULES)),
                self::itemIngredient(ProbeItem::TYPE_STEEL_PLATE, Config::int($config, 'atmospheric_drop_kit.steelPlateCount', self::ATMOSPHERIC_DROP_KIT_STEEL_PLATES)),
                self::itemIngredient(ProbeItem::TYPE_STEEL_BAR, Config::int($config, 'atmospheric_drop_kit.steelBarCount', self::ATMOSPHERIC_DROP_KIT_STEEL_BARS)),
            ],
            'durationSeconds' => Config::int($config, 'atmospheric_drop_kit.durationSeconds', self::ATMOSPHERIC_DROP_KIT_CRAFTING_SECONDS),
            'output' => self::itemOutput(
                ProbeItem::TYPE_ATMOSPHERIC_DROP_KIT,
                ProbeItem::ATMOSPHERIC_DROP_KIT_NAME,
                Config::float($config, 'atmospheric_drop_kit.containerSpace', self::ATMOSPHERIC_DROP_KIT_CONTAINER_SPACE),
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
            'description' => self::description($config, 'manny'),
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

    private static function description(array $config, string $id): string
    {
        $default = self::DEFAULT_DESCRIPTIONS[$id] ?? '';
        $value = Config::value($config, $id . '.description', $default);

        return is_string($value) ? $value : $default;
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
