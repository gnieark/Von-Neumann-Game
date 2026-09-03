"""Fixtures et doublure API partagées par les tests."""

from __future__ import annotations

from typing import Any

from scripts.others_control.defense_etoile.models import Coordinates


def sector(coordinates: Coordinates) -> dict[str, Any]:
    return {"relative": {"x": coordinates[0], "y": coordinates[1], "z": coordinates[2]}}


def ship(
    ship_id: str,
    coordinates: Coordinates | None,
    *,
    ship_type: str = "standard",
    status: str = "inactive",
    movement: dict[str, Any] | None = None,
    deuterium: float = 20.0,
    auxiliary_count: int = 0,
    deployed_auxiliary_count: int = 0,
) -> dict[str, Any]:
    return {
        "id": ship_id,
        "fleetId": "fleet_test",
        "type": ship_type,
        "status": status,
        "location": {"state": "transit" if coordinates is None else "in_sector"},
        "sector": None if coordinates is None else sector(coordinates),
        "movement": movement,
        "auxiliaryCount": auxiliary_count,
        "deployedAuxiliaryCount": deployed_auxiliary_count,
        "deuterium": {"amount": deuterium, "capacity": 100.0},
        "updatedAt": "2026-08-30T10:00:00+00:00",
    }


def movement(target: Coordinates) -> dict[str, Any]:
    return {
        "phase": "transit",
        "target": {"x": target[0], "y": target[1], "z": target[2]},
        "arrivalAt": "2099-01-01T00:00:00+00:00",
    }


def detailed_scan(
    *,
    probes: list[dict[str, Any]] | None = None,
    objects: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    return {
        "knowledgeLevel": "detailed",
        "probes": probes or [],
        "objects": objects or [],
    }


def missile_item(item_id: str) -> dict[str, Any]:
    return {"id": item_id, "type": "missile", "containerSpaceEce": 2.0}


def auxiliary(
    auxiliary_id: str,
    *,
    status: str = "inactive",
    location_type: str = "embarked",
    action: dict[str, Any] | None = None,
) -> dict[str, Any]:
    return {
        "id": auxiliary_id,
        "status": status,
        "locationType": location_type,
        "spatialState": "drifting",
        "capacityEce": 2.0,
        "cargo": {
            "deuterium": 0.0,
            "metals": 0.0,
            "ice": 0.0,
            "carbon_compounds": 0.0,
        },
        "action": action,
    }


def observed_manny(
    manny_id: str,
    carrier_id: str,
    spatial_state: str = "landed_on_sector_object",
) -> dict[str, Any]:
    return {
        "id": manny_id,
        "kind": "manny",
        "carrier": {"id": carrier_id, "kind": "probe"},
        "spatialState": spatial_state,
    }


class FakeApi:
    def __init__(
        self,
        ships: list[dict[str, Any]],
        scans: dict[Coordinates, dict[str, Any] | Exception] | None = None,
        autonomous_units: dict[str, list[dict[str, Any]]] | None = None,
        inventories: dict[str, list[dict[str, Any]]] | None = None,
        auxiliaries: dict[str, list[dict[str, Any]]] | None = None,
        resources: dict[str, dict[str, float]] | None = None,
        crafts: dict[str, list[dict[str, Any]]] | None = None,
    ) -> None:
        self.ships = ships
        self.scans = scans or {}
        self.scan_calls: list[Coordinates] = []
        self.moves: list[tuple[str, Coordinates]] = []
        self.autonomous_units = autonomous_units or {}
        self.inventories = inventories or {}
        self.auxiliaries = auxiliaries or {}
        self.resources = resources or {}
        self.crafts = crafts or {}
        self.missile_launches: list[tuple[str, str, str]] = []
        self.laser_locks: list[tuple[str, str]] = []
        self.craft_starts: list[tuple[str, str, str]] = []
        self.harvest_starts: list[tuple[str, str, int]] = []
        self.ship_calls = 0
        self.fleet_calls = 0

    def get_ship(self, ship_id: str) -> dict[str, Any]:
        self.ship_calls += 1
        return next(item for item in self.ships if item["id"] == ship_id)

    def get_fleet(self, fleet_id: str) -> dict[str, Any]:
        self.fleet_calls += 1
        return {"id": fleet_id, "ships": self.ships}

    def scan_sector(self, ship_id: str, coordinates: Coordinates) -> dict[str, Any]:
        self.scan_calls.append(coordinates)
        response = self.scans.get(
            coordinates,
            {
                "knowledgeLevel": "neighbor_scan",
                "estimatedObjects": {"blackHoleProbability": 0.7},
            },
        )
        if isinstance(response, Exception):
            raise response
        return response

    def get_autonomous_units(self, ship_id: str) -> list[dict[str, Any]]:
        return self.autonomous_units.get(ship_id, [])

    def get_inventory(self, ship_id: str) -> dict[str, Any]:
        amounts = self.resources.get(ship_id, {})
        resources = {
            resource_type: {
                "amount": float(amounts.get(resource_type, 0.0)),
                "reserved": 0.0,
            }
            for resource_type in ("metals", "ice", "carbon_compounds", "deuterium")
        }
        items = self.inventories.get(ship_id, [])
        used = sum(resource["amount"] for resource in resources.values()) + sum(
            float(item.get("containerSpaceEce", 0.0)) for item in items
        )
        return {
            "capacityEce": 100000.0,
            "usedEce": used,
            "reservedEce": 0.0,
            "resources": resources,
            "items": items,
        }

    def get_auxiliaries(self, ship_id: str) -> list[dict[str, Any]]:
        return self.auxiliaries.get(ship_id, [])

    def get_crafting_recipes(self) -> list[dict[str, Any]]:
        return [
            {
                "id": "others_auxiliary",
                "durationSeconds": 3600,
                "ingredients": {
                    "metals": 5.0,
                    "ice": 0.5,
                    "carbon_compounds": 1.0,
                    "deuterium": 0.05,
                },
                "output": {"kind": "others_auxiliary", "quantity": 1},
            },
            {
                "id": "missile",
                "durationSeconds": 1800,
                "ingredients": {
                    "metals": 20.0,
                    "ice": 2.0,
                    "carbon_compounds": 5.0,
                    "deuterium": 1.0,
                },
                "output": {"kind": "missile", "quantity": 1, "containerSpaceEce": 2.0},
            },
        ]

    def get_crafts(self, ship_id: str) -> list[dict[str, Any]]:
        return self.crafts.get(ship_id, [])

    def start_craft(
        self,
        ship_id: str,
        recipe_id: str,
        assistant_auxiliary_id: str,
        operation_key: str,
    ) -> dict[str, Any]:
        self.craft_starts.append((ship_id, recipe_id, assistant_auxiliary_id))
        return {"endsAt": "2099-01-01T00:00:00+00:00"}

    def start_harvest(
        self,
        ship_id: str,
        target_object_id: str,
        auxiliary_count: int,
        operation_key: str,
    ) -> dict[str, Any]:
        self.harvest_starts.append((ship_id, target_object_id, auxiliary_count))
        return {"endsAt": "2099-01-01T00:00:00+00:00"}

    def launch_missile(
        self,
        ship_id: str,
        missile_item_id: str,
        target_id: str,
        event_key: str,
    ) -> dict[str, Any]:
        self.missile_launches.append((ship_id, missile_item_id, target_id))
        self.inventories[ship_id] = [
            item
            for item in self.inventories.get(ship_id, [])
            if item.get("id") != missile_item_id
        ]
        return {"endsAt": "2099-01-01T00:00:00+00:00"}

    def start_laser(
        self,
        ship_id: str,
        target_id: str,
        event_key: str,
    ) -> dict[str, Any]:
        self.laser_locks.append((ship_id, target_id))
        return {"endsAt": None}

    def move_ship(self, item: dict[str, Any], target: Coordinates) -> dict[str, Any]:
        self.moves.append((item["id"], target))
        return {"endsAt": "2099-01-01T00:00:00+00:00"}
