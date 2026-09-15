"""Désengorgement de l'inventaire et navettes persistantes vers les dépôts."""

from __future__ import annotations

import hashlib
import json
import os
from copy import deepcopy
from decimal import Decimal
from math import isfinite
from pathlib import Path
from typing import Any, Callable
from uuid import uuid4

from .commands import is_movable
from .contracts import require_mapping, require_string
from .errors import ApiContractError, ApiRequestError, ConfigurationError
from .formation import ship_sector
from .geometry import NEIGHBOR_OFFSETS, add_coordinates, coordinate_distance, movement_hop, parse_coordinates
from .logistics import (
    ACTIVE_STATUSES, MAX_ACTIVE_SHIP_CRAFTS, LogisticsPolicy, MothershipLogistics,
    load_workshop_recipes, reconstruction_requirements,
)
from .models import Coordinates, CycleResult
from .ports import OthersApi
from .refueling import FleetRefuelingCoordinator


def units(value: float) -> int:
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not isfinite(value) or value < 0:
        raise ApiContractError("Une quantité logistique doit être finie et positive ou nulle.")
    return int(Decimal(str(value)) * 10000)


def available(inventory: dict[str, Any]) -> dict[str, int]:
    return {key: units(value) for key, value in MothershipLogistics._available_resources(inventory).items()}


def route(origin: Coordinates, target: Coordinates) -> list[Coordinates]:
    hops = []
    while origin != target:
        origin = movement_hop(origin, target)
        hops.append(origin)
    return hops


class DepotLogistics:
    def __init__(self, api: OthersApi, *, logger: Callable[[str], None],
                 state_dir: Path | None = None, fuel_per_hop: float = 2.0,
                 policy: LogisticsPolicy | None = None) -> None:
        self.api = api
        self.log = logger
        self.state_dir = state_dir
        self.fuel_per_hop = units(fuel_per_hop)
        self.policy = policy or LogisticsPolicy()
        self._protected_resources: dict[str, int] | None = None
        self.fleet_id: str | None = None
        self.path: Path | None = None
        self.state: dict[str, Any] = {"missions": {}, "motherJob": None}

    def reserved_ships(self, fleet_id: str) -> set[str]:
        if self.fleet_id != fleet_id:
            self.fleet_id = fleet_id
            self.state = {"missions": {}, "motherJob": None}
            if self.state_dir is not None:
                self.path = self.state_dir / (hashlib.sha256(fleet_id.encode()).hexdigest() + '.json')
                try:
                    if self.path.exists():
                        state = json.loads(self.path.read_text(encoding="utf-8"))
                        if not isinstance(state, dict) or set(state) != {"missions", "motherJob"} or not isinstance(state["missions"], dict):
                            raise ValueError("format de journal invalide")
                        for mission in state["missions"].values():
                            if mission["stage"] not in {"recalling", "loading", "outbound", "unloading", "returning"}:
                                raise ValueError("étape de navette invalide")
                            parse_coordinates(mission["destination"], "mission.destination")
                        self.state = state
                except (OSError, ValueError, KeyError, TypeError) as error:
                    raise ConfigurationError(f"Journal logistique illisible : {self.path}: {error}") from error
        return set(self.state["missions"])

    def _save(self) -> None:
        if self.path is None:
            return
        try:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            temporary = self.path.with_suffix('.tmp')
            with temporary.open('w', encoding='utf-8') as stream:
                json.dump(self.state, stream, ensure_ascii=False, allow_nan=False)
                stream.flush()
                os.fsync(stream.fileno())
            temporary.replace(self.path)
        except OSError as error:
            raise ConfigurationError(f"Impossible de sauvegarder le journal logistique : {error}") from error

    def reconcile(self, mother: dict[str, Any], ships: list[dict[str, Any]], result: CycleResult,
                  *, allow_new: bool = True) -> bool:
        """Renvoie vrai lorsque la production doit laisser travailler les auxiliaires logistiques."""
        self.reserved_ships(require_string(mother.get("fleetId"), "mothership.fleetId"))
        draining = bool(self.state["missions"])
        by_id = {ship["id"]: ship for ship in ships}
        for ship_id, mission in list(self.state["missions"].items()):
            ship = by_id.get(ship_id)
            if ship is None or ship.get("status") in {"destroyed", "removed"}:
                del self.state["missions"][ship_id]
                self._save()
                self.log(f"Navette {ship_id} perdue : mission retirée du journal.")
                continue
            self._advance(mother, ship, mission, result)

        job = self.state["motherJob"]
        if job is not None:
            if not self._pending(job, result):
                self.state["motherJob"] = None
                self._save()
            return True
        if any(mission["stage"] == "loading" for mission in self.state["missions"].values()):
            return True

        if not allow_new:
            return bool(self.state["missions"])
        if any(mission["stage"] == "recalling" for mission in self.state["missions"].values()):
            return False

        inventory = self.api.get_inventory(mother["id"])
        # Attendre les arrivées réservées avant d'évaluer le désengorgement.
        if units(inventory["reservedEce"]) > 0:
            return False
        if not draining and units(MothershipLogistics._free_capacity(inventory)) >= units(40):
            return False
        if units(inventory["usedEce"]) <= units(inventory["capacityEce"]) // 2:
            return False
        auxiliaries = self.api.get_auxiliaries(mother["id"])
        active = [aux["action"] for aux in auxiliaries if isinstance(aux.get("action"), dict)
                  and aux["action"].get("status") in ACTIVE_STATUSES]
        for action in active:
            result.add_event_date(action.get("endsAt"), "logistics action.endsAt")
        if any(action.get("type") in {"build_germination_depot", "depot_deposit", "inventory_transfer"} for action in active):
            return True
        free = MothershipLogistics._available_auxiliaries(auxiliaries)
        if not free or not is_movable(mother):
            return True
        center = ship_sector(mother)
        if center is None:
            return True
        stock = self._exportable(inventory)
        if not any(stock.values()):
            self.log("Déchargement bloqué : aucun excédent exportable après protection de la réserve "
                     "et du budget des trois prochains vaisseaux ; production maintenue.")
            return False
        known = [parse_coordinates(entry.get("relativeCoordinates"), "knownDepots[].relativeCoordinates")
                 for entry in self.api.get_known_depots(self.fleet_id)]
        # Le scan local permet aussi de réutiliser un dépôt antérieur à la migration.
        depot = self._local_depot(mother, center)
        if depot is not None:
            payload = {key: value / 10000 for key, value in stock.items() if value > 0}
            if payload:
                self._mother_command("start_depot_deposit", [mother["id"], free[0]["id"], depot, payload], result)
                self.log("Cale presque pleine : dépôt des excédents vers une occupation de 50 %, "
                         "réserve et budget de construction préservés.")
            return True
        if not known:
            self._mother_command("start_germination_depot", [mother["id"], free[0]["id"]], result)
            self.log("Cale presque pleine : construction d'un dépôt de germination demandée.")
            return True
        destinations = sorted((destination for destination in known if destination != center),
                              key=lambda point: (coordinate_distance(center, point), point))
        if not destinations:
            self.log("Dépôt local connu mais pas encore identifiable : nouveau scan au prochain cycle.")
            return True
        neighbors = {add_coordinates(center, offset) for offset in NEIGHBOR_OFFSETS}
        # Tous les standards ont la même vitesse : les résidents précèdent les
        # sentinelles voisines, à durée de retour égale départagées par identifiant.
        candidates = sorted(
            (ship for ship in ships if ship_sector(ship) in neighbors | {center}),
            key=lambda ship: (coordinate_distance(ship_sector(ship), center), ship["id"]),
        )
        for ship in candidates:
            position = ship_sector(ship)
            recall_fuel = len(route(position, center)) * self.fuel_per_hop
            if (ship["id"] in self.state["missions"] or ship.get("type") != "standard"
                    or ship.get("fleetId") != mother.get("fleetId") or not is_movable(ship)
                    or ship.get("integrity", 0) < ship.get("maxIntegrity", 0)):
                continue
            assistants = self.api.get_auxiliaries(ship["id"])
            if not assistants or len(MothershipLogistics._available_auxiliaries(assistants)) != len(assistants):
                continue
            ship_fuel = FleetRefuelingCoordinator._tank_units(ship, "amount")
            if ship_fuel < recall_fuel:
                continue
            ship_inventory = self.api.get_inventory(ship["id"])
            if units(ship_inventory["reservedEce"]) > 0:
                continue
            capacity = units(MothershipLogistics._free_capacity(ship_inventory))
            if capacity <= 0:
                continue
            for destination in destinations:
                fuel = (len(route(center, destination)) + len(route(destination, center))) * self.fuel_per_hop
                if FleetRefuelingCoordinator._tank_units(ship, "capacity") < fuel:
                    continue
                if fuel > ship_fuel - recall_fuel + FleetRefuelingCoordinator._tank_units(mother, "amount"):
                    continue
                manifest = self._distribute(stock, capacity)
                mission = {"stage": "loading" if position == center else "recalling",
                           "destination": dict(zip(("x", "y", "z"), destination)),
                           "remaining": manifest, "pending": None}
                self.state["missions"][ship["id"]] = mission
                self._save()
                if position != center:
                    self.log(f"Rappel logistique de la sentinelle {ship['id']}, même sous menace : "
                             "poste temporairement dégarni.")
                self.log(f"Navette {ship['id']} affectée au dépôt du secteur relatif {destination}.")
                self._advance(mother, ship, mission, result)
                return mission["stage"] == "loading"
        self.log("Déchargement bloqué : aucune navette locale ni sentinelle admissible "
                 "avec auxiliaire et autonomie suffisante ; production maintenue.")
        return False

    @staticmethod
    def _distribute(stock: dict[str, int], capacity: int) -> dict[str, int]:
        total = sum(stock.values())
        budget = min(capacity, total)
        manifest = {key: value * budget // total if total else 0 for key, value in stock.items()}
        for key, value in stock.items():
            manifest[key] += min(value - manifest[key], budget - sum(manifest.values()))
        return manifest

    def _exportable(self, inventory: dict[str, Any]) -> dict[str, int]:
        if self._protected_resources is None:
            recipes = load_workshop_recipes(self.api)
            reserve = reconstruction_requirements(recipes, self.policy)
            self._protected_resources = {
                key: units(value + MAX_ACTIVE_SHIP_CRAFTS * recipes["standard_ship"].ingredients[key])
                for key, value in reserve.items()
            }
        surplus = {key: max(0, value - self._protected_resources[key])
                   for key, value in available(inventory).items()}
        target = max(0, units(inventory["usedEce"]) - units(inventory["capacityEce"]) // 2)
        return self._distribute(surplus, target)

    def _mother_command(self, method: str, args: list[Any], result: CycleResult) -> None:
        job: dict[str, Any] = {"pending": None}
        self.state["motherJob"] = job
        self._command(job, method, args, result)

    def _command(self, owner: dict[str, Any], method: str, args: list[Any], result: CycleResult,
                 resource: str | None = None) -> None:
        owner["pending"] = {"method": method, "args": deepcopy(args), "key": uuid4().hex,
                            "actionId": None, "resource": resource}
        self._save()  # La requête exacte est durable avant son premier envoi.
        self._pending(owner, result)

    def _pending(self, owner: dict[str, Any], result: CycleResult) -> bool:
        pending = owner["pending"]
        if pending is None:
            return False
        if pending["actionId"] is None:
            try:
                if pending["method"] not in {"move_ship", "start_depot_deposit", "start_germination_depot",
                                             "start_deuterium_transfer", "start_inventory_resource_transfer"}:
                    raise ConfigurationError("Commande inconnue dans le journal logistique.")
                method = getattr(self.api, pending["method"])
                args = pending["args"]
                action = method(*args) if pending["method"] == "move_ship" else method(*args, pending["key"])
            except ApiRequestError as error:
                if error.status not in {404, 409, 422}:
                    raise
                self.log(f"Logistique différée : {error.code} ({error.message}).")
                owner["pending"] = None
                self._save()
                return True
            pending["actionId"] = require_string(action.get("id"), "logistics action.id")
            self._save()
            result.accepted_commands += 1
        else:
            action = self.api.get_action(pending["actionId"])
        if action.get("status") in ACTIVE_STATUSES:
            result.add_event_date(action.get("endsAt"), "logistics action.endsAt")
            return True
        if action.get("status") not in {"succeeded", "failed", "canceled"}:
            raise ApiContractError("Statut d'action logistique inconnu.")
        if action["status"] == "succeeded" and pending["resource"] is not None:
            owner["remaining"][pending["resource"]] -= units(pending["args"][4])
        if action["status"] != "succeeded":
            self.log(f"Action logistique {action['id']} terminée avec l'état {action['status']} : réévaluation depuis l'API.")
        owner["pending"] = None
        self._save()
        return False

    def _advance(self, mother: dict[str, Any], ship: dict[str, Any], mission: dict[str, Any], result: CycleResult) -> None:
        if self._pending(mission, result):
            return
        movement = ship.get("movement")
        if movement is not None:
            result.add_event_date(movement.get("arrivalAt"), "courier movement.arrivalAt")
            return
        if not is_movable(ship):
            return
        position, center = ship_sector(ship), ship_sector(mother)
        if position is None or center is None:
            return
        destination = parse_coordinates(mission["destination"], "mission.destination")
        if mission["stage"] == "recalling":
            if position != center:
                self._command(mission, "move_ship", [ship, movement_hop(position, center)], result)
                return
            mission["stage"] = "loading"
            self._save()
        if mission["stage"] == "loading":
            if position != center:
                mission["stage"] = "outbound" if sum(available(self.api.get_inventory(ship["id"])).values()) else "returning"
                self._save()
                return
            stock = self._exportable(self.api.get_inventory(mother["id"]))
            ship_inventory = self.api.get_inventory(ship["id"])
            if not any(min(wanted, stock[key]) for key, wanted in mission["remaining"].items()) \
                    and not any(available(ship_inventory).values()):
                del self.state["missions"][ship["id"]]
                self._save()
                self.log(f"Navette {ship['id']} libérée : aucun excédent à charger.")
                return
            assistants = MothershipLogistics._available_auxiliaries(self.api.get_auxiliaries(mother["id"]))
            if not assistants:
                return
            fuel = (len(route(center, destination)) + len(route(destination, center))) * self.fuel_per_hop
            missing = max(0, fuel - FleetRefuelingCoordinator._tank_units(ship, "amount"))
            if missing:
                if FleetRefuelingCoordinator._tank_units(mother, "amount") < missing:
                    self.log(f"Navette {ship['id']} en attente du carburant nécessaire à l'aller-retour.")
                    return
                self._command(mission, "start_deuterium_transfer", [mother["id"], ship["id"], assistants[0]["id"], missing / 10000], result)
                return
            # Le stock peut avoir changé pendant le rappel ou un chargement :
            # ne jamais puiser dans la réserve ni dans le budget de construction.
            stock = self._exportable(self.api.get_inventory(mother["id"]))
            capacity = units(MothershipLogistics._free_capacity(self.api.get_inventory(ship["id"])))
            for key, wanted in mission["remaining"].items():
                amount = min(wanted, stock[key], capacity)
                if amount > 0:
                    self._command(mission, "start_inventory_resource_transfer",
                                  [mother["id"], ship["id"], assistants[0]["id"], key, amount / 10000], result, resource=key)
                    return
            mission["stage"] = "outbound"
            self._save()
        if mission["stage"] in {"outbound", "returning"}:
            target = destination if mission["stage"] == "outbound" else center
            if position != target:
                if units(self.api.get_inventory(ship["id"])["reservedEce"]) > 0:
                    return
                assistants = self.api.get_auxiliaries(ship["id"])
                if not assistants or len(MothershipLogistics._available_auxiliaries(assistants)) != len(assistants):
                    self.log(f"Navette {ship['id']} attend le retour de ses auxiliaires avant le départ.")
                    return
                self._command(mission, "move_ship", [ship, movement_hop(position, target)], result)
                return
            if mission["stage"] == "returning":
                del self.state["missions"][ship["id"]]
                self._save()
                self.log(f"Navette {ship['id']} revenue auprès du vaisseau mère.")
                return
            mission["stage"] = "unloading"
            self._save()
        if mission["stage"] == "unloading":
            inventory = self.api.get_inventory(ship["id"])
            stock = available(inventory)
            if not any(stock.values()):
                # Une réservation extérieure doit se terminer avant le retour.
                resources = require_mapping(inventory.get("resources"), "inventory.resources")
                if any(value["reserved"] > 0 for value in resources.values()):
                    return
                mission["stage"] = "returning"
                self._save()
                return
            assistants = MothershipLogistics._available_auxiliaries(self.api.get_auxiliaries(ship["id"]))
            if not assistants:
                self.log(f"Navette {ship['id']} attend un auxiliaire libre pour décharger.")
                return
            depot = self._local_depot(ship, position)
            if depot is None:
                self.log(f"Navette {ship['id']} attend l'identification du dépôt dans le secteur relatif {position}.")
                return
            self._command(mission, "start_depot_deposit", [ship["id"], assistants[0]["id"], depot,
                          {key: value / 10000 for key, value in stock.items() if value > 0}], result)

    def _local_depot(self, ship: dict[str, Any], position: Coordinates) -> str | None:
        try:
            scan = self.api.scan_sector(ship["id"], position)
        except ApiRequestError as error:
            if error.code == "insufficient_scan_data":
                return None
            raise
        if scan.get("knowledgeLevel") != "detailed":
            return None
        objects = scan.get("objects")
        if not isinstance(objects, list):
            raise ApiContractError("sector.objects doit être une liste.")
        for value in objects:
            obj = require_mapping(value, "sector.objects[]")
            if obj.get("type") != "dormant_construct":
                continue
            identifier = require_string(obj.get("id"), "sector.objects[].id")
            try:
                self.api.get_depot_inventory(identifier)
            except ApiRequestError as error:
                if error.status == 404:
                    continue
                raise
            return identifier
        return None
