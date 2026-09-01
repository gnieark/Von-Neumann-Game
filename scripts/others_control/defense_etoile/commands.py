"""Exécution uniforme des commandes tactiques Others."""

from __future__ import annotations

from typing import Any, Callable

from .contracts import identifier_string, require_mapping, require_string
from .errors import ApiContractError, ApiRequestError
from .models import Coordinates, CycleResult
from .ports import OthersApi


class CommandExecutor:
    def __init__(self, api: OthersApi, logger: Callable[[str], None]) -> None:
        self.api = api
        self.log = logger

    def available_missiles(self, ship_id: str) -> list[str]:
        inventory = self.api.get_inventory(ship_id)
        items = inventory.get("items")
        if not isinstance(items, list):
            raise ApiContractError("inventory.items doit être une liste.")
        missiles = []
        for index, value in enumerate(items):
            item = require_mapping(value, f"inventory.items[{index}]")
            if item.get("type") == "missile":
                missiles.append(identifier_string(item.get("id"), f"inventory.items[{index}].id"))
        return sorted(missiles)

    def launch_missile(
        self,
        ship_id: str,
        missile_id: str,
        target_id: str,
        event_key: str,
        result: CycleResult,
    ) -> bool:
        try:
            action = self.api.launch_missile(ship_id, missile_id, target_id, event_key)
        except ApiRequestError as error:
            if error.status in {404, 409, 422}:
                self.log(
                    f"Tir de {ship_id} ignoré vers {target_id} : "
                    f"{error.code} ({error.message})."
                )
                return False
            raise
        result.accepted_commands += 1
        result.add_event_date(action.get("endsAt"), f"missile action for {ship_id}.endsAt")
        self.log(f"Missile {missile_id} de {ship_id} lancé vers {target_id}.")
        return True

    def start_laser(
        self,
        ship_id: str,
        target_id: str,
        event_key: str,
        result: CycleResult,
    ) -> bool:
        try:
            action = self.api.start_laser(ship_id, target_id, event_key)
        except ApiRequestError as error:
            if error.status in {404, 409, 422}:
                self.log(
                    f"Laser de {ship_id} ignoré vers {target_id} : "
                    f"{error.code} ({error.message})."
                )
                return False
            raise
        result.accepted_commands += 1
        result.add_event_date(action.get("endsAt"), f"laser action for {ship_id}.endsAt")
        return True

    def move(
        self,
        ship: dict[str, Any],
        target: Coordinates,
        result: CycleResult,
    ) -> bool:
        ship_id = require_string(ship.get("id"), "move ship.id")
        try:
            action = self.api.move_ship(ship, target)
        except ApiRequestError as error:
            if error.status in {409, 422}:
                self.log(f"Commande ignorée pour {ship_id} : {error.code} ({error.message}).")
                return False
            raise
        result.accepted_commands += 1
        result.add_event_date(action.get("endsAt"), f"move action for {ship_id}.endsAt")
        return True


def deuterium_amount(ship: dict[str, Any]) -> float:
    ship_id = ship.get("id", "?")
    deuterium = require_mapping(ship.get("deuterium"), f"ship {ship_id}.deuterium")
    amount = deuterium.get("amount")
    if isinstance(amount, bool) or not isinstance(amount, (int, float)):
        raise ApiContractError(f"ship {ship_id}.deuterium.amount doit être numérique.")
    return float(amount)


def is_movable(ship: dict[str, Any]) -> bool:
    return ship.get("movement") is None and ship.get("status") in {"inactive", "available"}
