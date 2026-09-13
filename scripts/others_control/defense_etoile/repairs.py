"""Réparation des vaisseaux et approvisionnement local en métaux."""

from __future__ import annotations

from decimal import Decimal, ROUND_HALF_UP
from math import isfinite
from typing import Any, Callable
from uuid import uuid4

from .contracts import require_mapping, require_string
from .errors import ApiContractError, ApiRequestError, ConfigurationError
from .geometry import parse_coordinates
from .models import CycleResult
from .ports import OthersApi


def missing_integrity(ship: dict[str, Any]) -> int:
    values = []
    for field in ("integrity", "maxIntegrity"):
        value = ship.get(field)
        if isinstance(value, bool) or not isinstance(value, int) or value < 0:
            raise ApiContractError(f"ship {ship.get('id')}.{field} doit être un entier positif ou nul.")
        values.append(value)
    return max(0, values[1] - values[0])


def available_auxiliaries(auxiliaries: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return sorted(
        (aux for aux in auxiliaries if aux.get("locationType") == "embarked"
         and aux.get("status") in {"inactive", "available"} and aux.get("action") is None),
        key=lambda aux: require_string(aux.get("id"), "auxiliary.id"),
    )


def quantity(value: Any, context: str) -> Decimal:
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not isfinite(value) or value < 0:
        raise ApiContractError(f"{context} doit être un nombre fini positif ou nul.")
    return Decimal(str(value))


def available_metals(inventory: dict[str, Any]) -> Decimal:
    resources = require_mapping(inventory.get("resources"), "inventory.resources")
    metals = require_mapping(resources.get("metals"), "inventory.resources.metals")
    return max(Decimal(0), quantity(metals.get("amount"), "metals.amount")
               - quantity(metals.get("reserved"), "metals.reserved"))


class FleetRepairCoordinator:
    def __init__(
        self, api: OthersApi, *, logger: Callable[[str], None], metals_per_point: float = 0.01,
    ) -> None:
        if not isfinite(metals_per_point) or metals_per_point < 0:
            raise ConfigurationError("Le coût de réparation doit être fini et positif ou nul.")
        self.api = api
        self.log = logger
        self.metals_per_point = Decimal(str(metals_per_point))

    def cost(self, points: int) -> Decimal:
        return (points * self.metals_per_point).quantize(Decimal("0.0001"), rounding=ROUND_HALF_UP)

    def reconcile(
        self, mothership: dict[str, Any], ships: list[dict[str, Any]], result: CycleResult,
    ) -> None:
        mother_id = require_string(mothership.get("id"), "mothership.id")
        center = parse_coordinates(require_mapping(mothership.get("sector"), "mothership.sector").get("relative"), "mothership.sector.relative")
        recipients: list[tuple[str, Decimal]] = []
        mother_repair_pending = False
        operation_key = uuid4().hex
        for ship in sorted(ships, key=lambda value: (value.get("id") != mother_id, str(value.get("id", "")))):
            ship_id = require_string(ship.get("id"), "ship.id")
            if ship.get("fleetId") != mothership.get("fleetId"):
                continue
            location = require_mapping(ship.get("location"), "ship.location")
            if location.get("state") in {"removed", "destroyed"}:
                continue
            missing = missing_integrity(ship)
            if missing == 0:
                continue
            auxiliaries = self.api.get_auxiliaries(ship_id)
            repairs = [aux["action"] for aux in auxiliaries
                       if isinstance(aux.get("action"), dict)
                       and aux["action"].get("type") == "auxiliary_repair"
                       and aux["action"].get("status") in {"queued", "running"}]
            if repairs:
                for action in repairs:
                    result.add_event_date(action.get("endsAt"), "repair.endsAt")
                if ship_id == mother_id:
                    mother_repair_pending = True
                continue
            inventory = self.api.get_inventory(ship_id)
            deficit = self.cost(missing) - available_metals(inventory)
            if deficit <= 0:
                free = available_auxiliaries(auxiliaries)
                if not free:
                    self.log(f"Réparation de {ship_id} différée : aucun auxiliaire embarqué libre.")
                    continue
                auxiliary_id = require_string(free[0].get("id"), "auxiliary.id")
                try:
                    action = self.api.start_repair(ship_id, auxiliary_id, missing, operation_key)
                except ApiRequestError as error:
                    if error.status not in {404, 409, 422}:
                        raise
                    self.log(f"Réparation de {ship_id} différée : {error.code} ({error.message}).")
                    continue
                mother_repair_pending |= ship_id == mother_id
                result.accepted_commands += 1
                result.add_event_date(action.get("endsAt"), "repair.endsAt")
                self.log(f"Réparation de {ship_id} : {missing} point(s), auxiliaire {auxiliary_id}.")
            elif ship_id != mother_id and location.get("state") == "in_sector" and ship.get("movement") is None:
                sector = require_mapping(ship.get("sector"), "ship.sector")
                if parse_coordinates(sector.get("relative"), "ship.sector.relative") == center:
                    recipients.append((ship_id, self.cost(missing)))

        if not recipients:
            return
        # Re-read after repair acceptance: its auxiliary and metals are already spent.
        auxiliaries = self.api.get_auxiliaries(mother_id)
        transfers = [aux["action"] for aux in auxiliaries
                     if isinstance(aux.get("action"), dict)
                     and aux["action"].get("type") == "inventory_transfer"
                     and aux["action"].get("status") in {"queued", "running"}]
        if transfers:
            for action in transfers:
                result.add_event_date(action.get("endsAt"), "repair supply.endsAt")
            self.log("Livraison de métaux différée : un transfert d'inventaire est encore actif.")
            return
        available = available_metals(self.api.get_inventory(mother_id))
        if not mother_repair_pending:
            available = max(Decimal(0), available - self.cost(missing_integrity(mothership)))
        free = iter(available_auxiliaries(auxiliaries))
        for target_id, required_metals in recipients:
            # A previous delivery may have completed since the first inventory read.
            inventory = self.api.get_inventory(target_id)
            deficit = required_metals - available_metals(inventory)
            if deficit <= 0:
                continue
            capacity = quantity(inventory.get("capacityEce"), "inventory.capacityEce")
            used = quantity(inventory.get("usedEce"), "inventory.usedEce")
            reserved = quantity(inventory.get("reservedEce"), "inventory.reservedEce")
            if deficit > available or deficit > capacity - used - reserved:
                self.log(f"Livraison de métaux à {target_id} différée : stock ou capacité insuffisant.")
                continue
            auxiliary = next(free, None)
            if auxiliary is None:
                break
            auxiliary_id = require_string(auxiliary.get("id"), "auxiliary.id")
            try:
                action = self.api.start_inventory_resource_transfer(
                    mother_id, target_id, auxiliary_id, "metals", float(deficit), operation_key,
                )
            except ApiRequestError as error:
                if error.status not in {404, 409, 422}:
                    raise
                self.log(f"Livraison de métaux à {target_id} différée : {error.code} ({error.message}).")
                break
            available -= deficit
            result.accepted_commands += 1
            result.add_event_date(action.get("endsAt"), "repair supply.endsAt")
            self.log(f"Livraison de {deficit:g} ECE de métaux à {target_id} par {auxiliary_id}.")
