"""Répartition des missiles disponibles au sein de la flotte."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Callable

from .contracts import identifier_string, optional_mapping, require_mapping, require_string
from .errors import ApiContractError, ApiRequestError
from .geometry import parse_coordinates
from .logistics import ACTIVE_STATUSES
from .models import CycleResult
from .ports import OthersApi


@dataclass(frozen=True)
class ArmamentPolicy:
    mothership_reserve: int = 10
    ship_missile_cap: int = 3


@dataclass(frozen=True)
class ArmamentState:
    missile_counts: dict[str, int]
    total_missiles: int
    transfers_active: bool


class FleetArmamentCoordinator:
    def __init__(
        self,
        api: OthersApi,
        *,
        logger: Callable[[str], None],
        policy: ArmamentPolicy | None = None,
    ) -> None:
        self.api = api
        self.log = logger
        self.policy = policy or ArmamentPolicy()

    def reconcile(
        self,
        mothership: dict[str, Any],
        ships: list[dict[str, Any]],
        result: CycleResult,
    ) -> ArmamentState:
        mothership_id = require_string(mothership.get("id"), "mothership.id")
        center = self._ship_sector(mothership, "mothership")
        inventories: dict[str, dict[str, Any]] = {}
        missile_ids: dict[str, list[str]] = {}

        for ship in sorted(ships, key=lambda value: str(value.get("id", ""))):
            ship_id = require_string(ship.get("id"), "fleet.ships[].id")
            location = require_mapping(ship.get("location"), f"ship {ship_id}.location")
            if location.get("state") in {"removed", "destroyed"}:
                continue
            inventory = self.api.get_inventory(ship_id)
            inventories[ship_id] = inventory
            missile_ids[ship_id] = self._missile_ids(inventory, ship_id)

        if mothership_id not in inventories:
            raise ApiContractError("L'inventaire du vaisseau mère est indisponible.")

        auxiliaries = self.api.get_auxiliaries(mothership_id)
        active_transfers = self._active_transfers(auxiliaries)
        for action in active_transfers:
            result.add_event_date(action.get("endsAt"), "inventory transfer action.endsAt")

        counts = {ship_id: len(ids) for ship_id, ids in missile_ids.items()}
        state = ArmamentState(
            missile_counts=counts,
            total_missiles=sum(counts.values()),
            transfers_active=bool(active_transfers),
        )
        if active_transfers:
            self.log("Répartition des missiles en attente : un transfert est encore actif.")
            return state

        surplus = max(
            0,
            len(missile_ids[mothership_id]) - self.policy.mothership_reserve,
        )
        if surplus == 0:
            return state

        available_auxiliaries = self._available_auxiliaries(auxiliaries)
        if not available_auxiliaries:
            self.log("Répartition des missiles différée : aucun auxiliaire n'est disponible.")
            return state

        recipients = []
        for ship in ships:
            ship_id = require_string(ship.get("id"), "fleet.ships[].id")
            if ship_id == mothership_id or ship.get("type") == "mothership":
                continue
            if optional_mapping(ship.get("movement"), f"ship {ship_id}.movement") is not None:
                continue
            if self._ship_sector(ship, f"ship {ship_id}") != center:
                continue
            count = counts.get(ship_id)
            inventory = inventories.get(ship_id)
            if count is None or inventory is None or count >= self.policy.ship_missile_cap:
                continue
            if self._free_capacity(inventory, ship_id) < 2.0:
                continue
            recipients.append(ship)

        recipients.sort(
            key=lambda ship: (
                counts[require_string(ship.get("id"), "recipient.id")],
                require_string(ship.get("id"), "recipient.id"),
            )
        )

        transfer_count = min(surplus, len(recipients), len(available_auxiliaries))
        accepted = 0
        for ship, auxiliary, missile_id in zip(
            recipients[:transfer_count],
            available_auxiliaries[:transfer_count],
            missile_ids[mothership_id][:transfer_count],
        ):
            target_id = require_string(ship.get("id"), "recipient.id")
            auxiliary_id = require_string(auxiliary.get("id"), "auxiliaries[].id")
            operation_key = require_string(
                mothership.get("updatedAt"), "mothership.updatedAt"
            )
            try:
                action = self.api.start_inventory_item_transfer(
                    mothership_id,
                    target_id,
                    auxiliary_id,
                    [missile_id],
                    operation_key,
                )
            except ApiRequestError as error:
                if error.status in {404, 409, 422}:
                    self.log(
                        f"Transfert de missile différé vers {target_id} : "
                        f"{error.code} ({error.message})."
                    )
                    continue
                raise

            accepted += 1
            result.accepted_commands += 1
            result.add_event_date(action.get("endsAt"), f"inventory transfer to {target_id}.endsAt")
            self.log(
                f"Missile {missile_id} confié à {auxiliary_id} pour {target_id}."
            )

        if accepted == 0:
            return state
        return ArmamentState(
            missile_counts=counts,
            total_missiles=state.total_missiles,
            transfers_active=True,
        )

    @staticmethod
    def _missile_ids(inventory: dict[str, Any], ship_id: str) -> list[str]:
        items = inventory.get("items")
        if not isinstance(items, list):
            raise ApiContractError(f"inventory {ship_id}.items doit être une liste.")
        missiles = []
        for index, value in enumerate(items):
            item = require_mapping(value, f"inventory {ship_id}.items[{index}]")
            if item.get("type") == "missile":
                missiles.append(
                    identifier_string(
                        item.get("id"), f"inventory {ship_id}.items[{index}].id"
                    )
                )
        return sorted(missiles)

    @staticmethod
    def _free_capacity(inventory: dict[str, Any], ship_id: str) -> float:
        values = []
        for field in ("capacityEce", "usedEce", "reservedEce"):
            value = inventory.get(field)
            if isinstance(value, bool) or not isinstance(value, (int, float)) or value < 0:
                raise ApiContractError(
                    f"inventory {ship_id}.{field} doit être un nombre positif ou nul."
                )
            values.append(float(value))
        capacity, used, reserved = values
        return max(0.0, capacity - used - reserved)

    @staticmethod
    def _available_auxiliaries(auxiliaries: list[dict[str, Any]]) -> list[dict[str, Any]]:
        return sorted(
            (
                auxiliary
                for auxiliary in auxiliaries
                if auxiliary.get("locationType") == "embarked"
                and auxiliary.get("status") in {"inactive", "available"}
                and auxiliary.get("action") is None
            ),
            key=lambda auxiliary: str(auxiliary.get("id", "")),
        )

    @staticmethod
    def _active_transfers(auxiliaries: list[dict[str, Any]]) -> list[dict[str, Any]]:
        actions = []
        for auxiliary in auxiliaries:
            value = auxiliary.get("action")
            if value is None:
                continue
            action = require_mapping(value, "auxiliaries[].action")
            if (
                action.get("type") == "inventory_transfer"
                and action.get("status") in ACTIVE_STATUSES
            ):
                actions.append(action)
        return actions

    @staticmethod
    def _ship_sector(ship: dict[str, Any], context: str) -> tuple[int, int, int] | None:
        sector = ship.get("sector")
        if sector is None:
            return None
        sector_value = require_mapping(sector, f"{context}.sector")
        return parse_coordinates(sector_value.get("relative"), f"{context}.sector.relative")
