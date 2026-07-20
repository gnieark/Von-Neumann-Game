<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeItem
{
    public const TYPE_WAYPOINT_BOOKMARK = 'waypoint_bookmark';
    public const TYPE_STEEL_BAR = 'steel_bar';
    public const TYPE_STEEL_PLATE = 'steel_plate';
    public const TYPE_ADDITIONAL_CONTAINER = 'additional_container';
    public const TYPE_MICRO_CONDUCTOR = 'micro_conductor';
    public const TYPE_CERAMIC_INSULATOR = 'ceramic_insulator';
    public const TYPE_CRYSTAL_SUBSTRATE = 'crystal_substrate';
    public const TYPE_DOPANT_MATRIX = 'dopant_matrix';
    public const TYPE_INTEGRATED_CIRCUIT = 'integrated_circuit';
    public const TYPE_ELECTRIC_MOTOR = 'electric_motor';
    public const TYPE_BATTERY_PACK = 'battery_pack';
    public const TYPE_LINEAR_ACTUATOR = 'linear_actuator';
    public const TYPE_ATOMIC_PRINTER_PART = 'atomic_printer_part';
    public const TYPE_DEUTERIUM_ENGINE = 'deuterium_engine';
    public const TYPE_SOLAR_PANEL = 'solar_panel';
    public const TYPE_SCUT_RELAY = 'scut_relay';
    public const TYPE_SCUT_TRANSIT_BEACON = 'scut_transit_beacon';
    public const TYPE_THERMAL_PROTECTION_SHELL = 'thermal_protection_shell';
    public const TYPE_PARACHUTE_PACK = 'parachute_pack';
    public const TYPE_DESCENT_GUIDANCE_MODULE = 'descent_guidance_module';
    public const TYPE_ATMOSPHERIC_DROP_KIT = 'atmospheric_drop_kit';
    public const WAYPOINT_BOOKMARK_NAME = 'Waypoint bookmark';
    public const STEEL_BAR_NAME = 'Steel bar';
    public const STEEL_PLATE_NAME = 'Steel plate';
    public const ADDITIONAL_CONTAINER_NAME = 'Additional container';
    public const MICRO_CONDUCTOR_NAME = 'Micro-etched conductor';
    public const CERAMIC_INSULATOR_NAME = 'Ceramo-organic insulator';
    public const CRYSTAL_SUBSTRATE_NAME = 'Crystal substrate';
    public const DOPANT_MATRIX_NAME = 'Dopant matrix';
    public const INTEGRATED_CIRCUIT_NAME = 'Integrated circuit';
    public const ELECTRIC_MOTOR_NAME = 'Electric motor';
    public const BATTERY_PACK_NAME = 'Battery pack';
    public const LINEAR_ACTUATOR_NAME = 'Linear actuator';
    public const ATOMIC_PRINTER_PART_NAME = 'Atomic printer part';
    public const DEUTERIUM_ENGINE_NAME = 'Deuterium engine';
    public const SOLAR_PANEL_NAME = 'Solar panel';
    public const SCUT_RELAY_NAME = 'SCUT relay';
    public const SCUT_TRANSIT_BEACON_NAME = 'SCUT transit beacon';
    public const THERMAL_PROTECTION_SHELL_NAME = 'Thermal protection shell';
    public const PARACHUTE_PACK_NAME = 'Parachute pack';
    public const DESCENT_GUIDANCE_MODULE_NAME = 'Descent guidance module';
    public const ATMOSPHERIC_DROP_KIT_NAME = 'Atmospheric drop kit';

    /**
     * @return array<string, string>
     */
    public static function canonicalNames(): array
    {
        return [
            self::TYPE_WAYPOINT_BOOKMARK => self::WAYPOINT_BOOKMARK_NAME,
            self::TYPE_STEEL_BAR => self::STEEL_BAR_NAME,
            self::TYPE_STEEL_PLATE => self::STEEL_PLATE_NAME,
            self::TYPE_ADDITIONAL_CONTAINER => self::ADDITIONAL_CONTAINER_NAME,
            self::TYPE_MICRO_CONDUCTOR => self::MICRO_CONDUCTOR_NAME,
            self::TYPE_CERAMIC_INSULATOR => self::CERAMIC_INSULATOR_NAME,
            self::TYPE_CRYSTAL_SUBSTRATE => self::CRYSTAL_SUBSTRATE_NAME,
            self::TYPE_DOPANT_MATRIX => self::DOPANT_MATRIX_NAME,
            self::TYPE_INTEGRATED_CIRCUIT => self::INTEGRATED_CIRCUIT_NAME,
            self::TYPE_ELECTRIC_MOTOR => self::ELECTRIC_MOTOR_NAME,
            self::TYPE_BATTERY_PACK => self::BATTERY_PACK_NAME,
            self::TYPE_LINEAR_ACTUATOR => self::LINEAR_ACTUATOR_NAME,
            self::TYPE_ATOMIC_PRINTER_PART => self::ATOMIC_PRINTER_PART_NAME,
            self::TYPE_DEUTERIUM_ENGINE => self::DEUTERIUM_ENGINE_NAME,
            self::TYPE_SOLAR_PANEL => self::SOLAR_PANEL_NAME,
            self::TYPE_SCUT_RELAY => self::SCUT_RELAY_NAME,
            self::TYPE_SCUT_TRANSIT_BEACON => self::SCUT_TRANSIT_BEACON_NAME,
            self::TYPE_THERMAL_PROTECTION_SHELL => self::THERMAL_PROTECTION_SHELL_NAME,
            self::TYPE_PARACHUTE_PACK => self::PARACHUTE_PACK_NAME,
            self::TYPE_DESCENT_GUIDANCE_MODULE => self::DESCENT_GUIDANCE_MODULE_NAME,
            self::TYPE_ATMOSPHERIC_DROP_KIT => self::ATMOSPHERIC_DROP_KIT_NAME,
        ];
    }

    public static function canonicalNameForType(string $type): ?string
    {
        return self::canonicalNames()[$type] ?? null;
    }

    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly int $probeId,
        public ?int $storageContainerId,
        public readonly string $type,
        public string $name,
        public float $containerSpace,
        public array $metadata,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}

    public function inventoryItem(?array $container = null): ProbeInventoryItem
    {
        $metadata = $this->metadata;
        unset($metadata['restoredDetachedContainerSourceUid']);

        return new ProbeInventoryItem(
            $this->uid,
            $this->type,
            $this->name,
            $this->containerSpace,
            null,
            0.0,
            null,
            null,
            $metadata + [
                'createdAt' => $this->createdAt,
                'updatedAt' => $this->updatedAt,
                'movable' => true,
            ],
            $container,
        );
    }
}
