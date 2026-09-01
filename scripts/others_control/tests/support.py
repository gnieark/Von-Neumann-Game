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
) -> dict[str, Any]:
    return {
        "id": ship_id,
        "fleetId": "fleet_test",
        "type": ship_type,
        "status": status,
        "location": {"state": "transit" if coordinates is None else "in_sector"},
        "sector": None if coordinates is None else sector(coordinates),
        "movement": movement,
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
    ) -> None:
        self.ships = ships
        self.scans = scans or {}
        self.scan_calls: list[Coordinates] = []
        self.moves: list[tuple[str, Coordinates]] = []
        self.autonomous_units = autonomous_units or {}
        self.inventories = inventories or {}
        self.missile_launches: list[tuple[str, str, str]] = []
        self.laser_locks: list[tuple[str, str]] = []
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
        return {"items": self.inventories.get(ship_id, [])}

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
