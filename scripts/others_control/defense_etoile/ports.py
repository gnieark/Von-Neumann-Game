"""Ports requis par la logique de défense."""

from __future__ import annotations

from typing import Any, Protocol

from .models import Coordinates


class OthersApi(Protocol):
    def get_ship(self, ship_id: str) -> dict[str, Any]: ...

    def get_fleet(self, fleet_id: str) -> dict[str, Any]: ...

    def scan_sector(self, ship_id: str, coordinates: Coordinates) -> dict[str, Any]: ...

    def get_autonomous_units(self, ship_id: str) -> list[dict[str, Any]]: ...

    def get_inventory(self, ship_id: str) -> dict[str, Any]: ...

    def launch_missile(
        self,
        ship_id: str,
        missile_item_id: str,
        target_id: str,
        event_key: str,
    ) -> dict[str, Any]: ...

    def start_laser(
        self,
        ship_id: str,
        target_id: str,
        event_key: str,
    ) -> dict[str, Any]: ...

    def move_ship(
        self,
        ship: dict[str, Any],
        target: Coordinates,
    ) -> dict[str, Any]: ...
