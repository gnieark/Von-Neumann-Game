"""Ravitaillement local par vagues, synchronisé sur les actions de la flotte."""

from __future__ import annotations

from decimal import Decimal
from math import isfinite
from typing import Any, Callable
from uuid import uuid4

from .contracts import require_mapping, require_string
from .errors import ApiContractError, ApiRequestError
from .geometry import parse_coordinates
from .models import CycleResult
from .ports import OthersApi


class FleetRefuelingCoordinator:
    def __init__(self, api: OthersApi, *, logger: Callable[[str], None]) -> None:
        self.api = api
        self.log = logger

    def reconcile(
        self,
        mothership: dict[str, Any],
        ships: list[dict[str, Any]],
        active_actions: list[dict[str, Any]],
        result: CycleResult,
    ) -> None:
        transfers = [
            action for action in active_actions
            if action.get("type") == "deuterium_transfer"
            and action.get("status") in {"queued", "running"}
        ]
        if transfers:
            for action in transfers:
                result.add_event_date(action.get("endsAt"), "deuterium transfer.endsAt")
            self.log(
                f"Ravitaillement en attente : {len(transfers)} transfert(s) "
                "de deutérium encore actif(s) dans la flotte."
            )
            return

        source_id = require_string(mothership.get("id"), "mothership.id")
        fleet_id = require_string(mothership.get("fleetId"), "mothership.fleetId")
        center = require_mapping(mothership.get("sector"), "mothership.sector")
        coordinates = parse_coordinates(center.get("relative"), "mothership.sector.relative")
        available = self._tank_units(mothership, "amount")
        if available <= 0:
            return

        recipients: list[tuple[str, int]] = []
        for ship in sorted(ships, key=lambda value: str(value.get("id", ""))):
            target_id = require_string(ship.get("id"), "fleet.ships[].id")
            if target_id == source_id or ship.get("fleetId") != fleet_id:
                continue
            location = require_mapping(ship.get("location"), f"ship {target_id}.location")
            if location.get("state") != "in_sector" or ship.get("movement") is not None:
                continue
            sector = require_mapping(ship.get("sector"), f"ship {target_id}.sector")
            if parse_coordinates(sector.get("relative"), f"ship {target_id}.sector.relative") != coordinates:
                continue
            deficit = self._tank_units(ship, "capacity") - self._tank_units(ship, "amount")
            if deficit > 0:
                recipients.append((target_id, deficit))
        if not recipients:
            return

        auxiliaries = sorted(
            (
                auxiliary for auxiliary in self.api.get_auxiliaries(source_id)
                if auxiliary.get("locationType") == "embarked"
                and auxiliary.get("status") in {"inactive", "available"}
                and auxiliary.get("action") is None
            ),
            key=lambda value: str(value.get("id", "")),
        )
        # One key per wave, kept unchanged by the HTTP adapter on retries.
        operation_key = uuid4().hex
        for (target_id, deficit), auxiliary in zip(recipients, auxiliaries):
            units = min(available, deficit)
            if units <= 0:
                break
            amount = units / 10000
            auxiliary_id = require_string(auxiliary.get("id"), "auxiliaries[].id")
            try:
                action = self.api.start_deuterium_transfer(
                    source_id, target_id, auxiliary_id, amount, operation_key,
                )
            except ApiRequestError as error:
                if error.status in {404, 409, 422}:
                    self.log(
                        f"Ravitaillement différé vers {target_id} : "
                        f"{error.code} ({error.message})."
                    )
                    # Recompute from fresh fleet state on the next cycle.
                    break
                raise
            available -= units
            result.accepted_commands += 1
            result.add_event_date(action.get("endsAt"), f"deuterium transfer to {target_id}.endsAt")
            self.log(
                f"Ravitaillement de {target_id} : {amount:g} points de deutérium "
                f"confiés à {auxiliary_id}."
            )

    @staticmethod
    def _tank_units(ship: dict[str, Any], field: str) -> int:
        context = f"ship {ship.get('id')}.deuterium"
        tank = require_mapping(ship.get("deuterium"), context)
        value = tank.get(field)
        if (
            isinstance(value, bool) or not isinstance(value, (int, float))
            or not isfinite(value) or value < 0
        ):
            raise ApiContractError(f"{context}.{field} doit être un nombre fini positif ou nul.")
        # Canonical tank precision: integer arithmetic prevents over-reservation.
        return int(Decimal(str(value)) * 10000)
