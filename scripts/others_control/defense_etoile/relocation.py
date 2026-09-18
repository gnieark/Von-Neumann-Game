"""Recherche persistante d'un système puis déménagement de toute la flotte."""

from __future__ import annotations

from .spectator import SpectatorEvent

import hashlib
import json
import os
from itertools import product
from pathlib import Path
from typing import Any, Callable

from .commands import CommandExecutor, deuterium_amount, is_movable
from .contracts import require_string
from .errors import ApiContractError, ApiRequestError, ConfigurationError
from .formation import ship_sector
from .geometry import NEIGHBOR_OFFSETS, add_coordinates, coordinate_distance, movement_hop, parse_coordinates
from .logistics import ACTIVE_STATUSES, MothershipLogistics, harvestable_planets
from .models import Coordinates, CycleResult
from .ports import OthersApi
from .refueling import FleetRefuelingCoordinator


def second_ring(center: Coordinates) -> tuple[Coordinates, ...]:
    """Couronne à distance 2 selon la métrique du jeu, avec parité FCC."""
    return tuple(add_coordinates(center, offset) for offset in product(range(-2, 3), repeat=3)
                 if sum(offset) % 2 == 0 and coordinate_distance((0, 0, 0), offset) == 2)


def coordinates_json(point: Coordinates) -> dict[str, int]:
    return dict(zip(("x", "y", "z"), point))


class FleetRelocationCoordinator:
    def __init__(self, api: OthersApi, *, logger: Callable[[str], None],
                 state_dir: Path | None = None, fuel_per_hop: float = 2.0) -> None:
        self.api = api
        self.log = logger
        self.commands = CommandExecutor(api, logger)
        self.refueling = FleetRefuelingCoordinator(api, logger=logger)
        self.state_dir = state_dir
        self.fuel_per_hop = fuel_per_hop
        self.fleet_id: str | None = None
        self.path: Path | None = None
        self.state: dict[str, Any] | None = None

    def load(self, fleet_id: str) -> None:
        if self.fleet_id == fleet_id:
            return
        self.fleet_id = fleet_id
        self.state = None
        if self.state_dir is None:
            return
        self.path = self.state_dir / (hashlib.sha256(fleet_id.encode()).hexdigest() + '.relocation.json')
        try:
            if not self.path.exists():
                return
            state = json.loads(self.path.read_text(encoding="utf-8"))
            if state is not None:
                if not isinstance(state, dict) or set(state) != {
                    "phase", "origin", "destination", "scoutId", "visited", "waypoint", "returning",
                }:
                    raise ValueError("format de journal invalide")
                if state["phase"] not in {"searching", "preparing", "travelling"}:
                    raise ValueError("phase de déménagement invalide")
                parse_coordinates(state["origin"], "relocation.origin")
                for field in ("destination", "waypoint"):
                    if state[field] is not None:
                        parse_coordinates(state[field], f"relocation.{field}")
                if state["phase"] != "searching" and state["destination"] is None:
                    raise ValueError("destination manquante")
                if state["scoutId"] is not None:
                    require_string(state["scoutId"], "relocation.scoutId")
                if not isinstance(state["visited"], list) or not isinstance(state["returning"], bool):
                    raise ValueError("progression de recherche invalide")
                for point in state["visited"]:
                    parse_coordinates(point, "relocation.visited[]")
            self.state = state
        except (OSError, ValueError, TypeError, ApiContractError) as error:
            raise ConfigurationError(f"Journal de déménagement illisible : {self.path}: {error}") from error

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
            raise ConfigurationError(f"Impossible de sauvegarder le déménagement : {error}") from error

    def start_if_depleted(self, mother: dict[str, Any]) -> bool:
        if self.state is not None:
            return True
        center = ship_sector(mother)
        if center is None or mother.get("movement") is not None:
            return False
        scan = self._scan(mother["id"], center)
        # L'absence de données détaillées n'est pas une preuve d'épuisement.
        if scan is None or harvestable_planets(scan):
            return False
        self.state = {"phase": "searching", "origin": coordinates_json(center),
                      "destination": None, "scoutId": None, "visited": [],
                      "waypoint": None, "returning": False}
        self._save()
        self.log(SpectatorEvent("EXPLORATION", "Secteur épuisé : recherche d'un nouveau système pour la flotte.", state="relocation"))
        return True

    def cancel(self) -> None:
        """Abandonne la recherche ou le déménagement au profit d'une mobilisation."""
        self.state = None
        self._save()

    def reconcile(self, mother: dict[str, Any], ships: list[dict[str, Any]],
                  active_actions: list[dict[str, Any]], reserved: set[str],
                  depot_busy: bool, result: CycleResult, *,
                  stationary_ships: list[dict[str, Any]] | None = None) -> None:
        if self.state is None:
            return
        ships = [ship for ship in ships if ship.get("location", {}).get("state") not in {"destroyed", "removed"}]
        for ship in ships:
            if ship.get("movement") is not None:
                result.add_event_date(ship["movement"].get("arrivalAt"), "relocation movement.arrivalAt")
        for action in active_actions:
            if action.get("status") in ACTIVE_STATUSES:
                result.add_event_date(action.get("endsAt"), "relocation action.endsAt")
        if self.state["phase"] == "searching":
            observers = stationary_ships or []
            self._search(mother, ships + observers, active_actions,
                         reserved | {ship["id"] for ship in observers}, result)
        if self.state["phase"] != "searching":
            self._relocate(mother, ships, active_actions, reserved, depot_busy, result)

    def _scan(self, ship_id: str, point: Coordinates) -> dict[str, Any] | None:
        try:
            scan = self.api.scan_sector(ship_id, point)
        except ApiRequestError as error:
            if error.code == "insufficient_scan_data":
                return None
            raise
        return scan if scan.get("knowledgeLevel") == "detailed" else None

    def _choose(self, point: Coordinates) -> None:
        self.state["destination"] = coordinates_json(point)
        self.state["phase"] = "preparing"
        self._save()
        self.log(SpectatorEvent("DÉMÉNAGEMENT", f"Déménagement : destination relative choisie {point} ; préparation de la flotte.", state="relocation"))

    def _search(self, mother: dict[str, Any], ships: list[dict[str, Any]],
                actions: list[dict[str, Any]], reserved: set[str], result: CycleResult) -> None:
        center = parse_coordinates(self.state["origin"], "relocation.origin")
        neighbors = tuple(add_coordinates(center, offset) for offset in NEIGHBOR_OFFSETS)
        uncertain = False
        for point in neighbors:
            residents = [ship for ship in ships if ship.get("type") == "standard"
                         and ship.get("movement") is None and ship_sector(ship) == point]
            if not residents:
                continue
            scan = self._scan(mother["id"], point)
            if scan is None:
                uncertain = True
            elif harvestable_planets(scan):
                self._choose(point)
                return
        if uncertain:
            self.log("Recherche en attente d'un scan détaillé des voisins occupés.")
            return

        scout = next((ship for ship in ships if ship["id"] == self.state["scoutId"]), None)
        if scout is None:
            candidates = [ship for ship in ships if ship.get("type") == "standard"
                          and ship["id"] not in reserved and is_movable(ship)
                          and ship_sector(ship) in (center, *neighbors)]
            candidates.sort(key=lambda ship: (ship_sector(ship) != center, ship["id"]))
            scout = next((ship for ship in candidates if self._ready(ship, result)), None)
            if scout is None:
                self.log(SpectatorEvent("EXPLORATION", "Recherche en attente d'un vaisseau local ou d'une sentinelle disponible.", state="relocation"))
                return
            self.state["scoutId"] = scout["id"]
            self.state["returning"] = False
            self._save()
            self.log(SpectatorEvent("EXPLORATION", f"Recherche confiée à {scout['id']}."))
        if scout.get("movement") is not None or not self._ready(scout, result):
            return
        position = ship_sector(scout)
        if position is None:
            return
        ring = second_ring(center)
        visited = {parse_coordinates(point, "relocation.visited[]") for point in self.state["visited"]}
        if position in ring and position not in visited:
            scan = self._scan(scout["id"], position)
            if scan is None and deuterium_amount(scout) >= max(4.0, 2 * self.fuel_per_hop):
                self.log(f"Éclaireur {scout['id']} en attente du scan détaillé local.")
                return
            if scan is not None and harvestable_planets(scan):
                self._choose(position)
                return
            if scan is not None:
                visited.add(position)
                self.state["visited"].append(coordinates_json(position))
                self.state["waypoint"] = None
                self._save()

        if deuterium_amount(scout) < max(4.0, 2 * self.fuel_per_hop) or len(visited) == len(ring):
            self.state["returning"] = True
            self._save()
        if self.state["returning"]:
            if position != center:
                self.log(SpectatorEvent("EXPLORATION", f"Recherche en pause : retour de {scout['id']} auprès du vaisseau mère."))
                self.move_towards(scout, center, result)
                return
            if len(visited) == len(ring):
                self.log(SpectatorEvent("EXPLORATION", "Recherche en attente : aucun système moissonnable dans la couronne à distance 2.", state="relocation"))
                return
            if any(action.get("type") == "deuterium_transfer" and action.get("status") in ACTIVE_STATUSES for action in actions):
                return
            if FleetRefuelingCoordinator._tank_units(scout, "amount") < FleetRefuelingCoordinator._tank_units(scout, "capacity"):
                self.refueling.reconcile(mother, [scout], actions, result,
                                         reserve_deuterium=self.fuel_per_hop)
                return
            if deuterium_amount(scout) < max(4.0, 2 * self.fuel_per_hop):
                self.log(SpectatorEvent("EXPLORATION", "Recherche en attente : autonomie insuffisante pour un aller-retour.", state="relocation"))
                return
            self.state["returning"] = False
            self._save()
        target = (parse_coordinates(self.state["waypoint"], "relocation.waypoint")
                  if self.state["waypoint"] is not None else next(point for point in ring if point not in visited))
        # Sauver l'intention avant la commande : un redémarrage conserve le même cap.
        self.state["waypoint"] = coordinates_json(target)
        self._save()
        self.move_towards(scout, target, result)

    def _ready(self, ship: dict[str, Any], result: CycleResult) -> bool:
        if not is_movable(ship) or ship_sector(ship) is None:
            return False
        auxiliaries = self.api.get_auxiliaries(ship["id"])
        for auxiliary in auxiliaries:
            action = auxiliary.get("action")
            if isinstance(action, dict):
                result.add_event_date(action.get("endsAt"), "relocation auxiliary.action.endsAt")
        if len(MothershipLogistics._available_auxiliaries(auxiliaries)) != len(auxiliaries):
            return False
        inventory = self.api.get_inventory(ship["id"])
        return inventory["reservedEce"] == 0 and all(
            resource["reserved"] == 0 for resource in inventory["resources"].values())

    def fuel_needed(self, origin: Coordinates, target: Coordinates) -> float:
        count = 0
        while origin != target:
            origin = movement_hop(origin, target)
            count += 1
        return count * self.fuel_per_hop

    def move_towards(self, ship: dict[str, Any], target: Coordinates, result: CycleResult) -> bool:
        origin = ship_sector(ship)
        if origin is None or origin == target or not self._ready(ship, result):
            return False
        if deuterium_amount(ship) < self.fuel_per_hop:
            self.log(SpectatorEvent("RAVITAILLEMENT", f"Déplacement de {ship['id']} en attente de carburant.", state=f"fuel:{ship['id']}"))
            return False
        return self.commands.move(ship, movement_hop(origin, target), result)

    def _relocate(self, mother: dict[str, Any], ships: list[dict[str, Any]],
                  actions: list[dict[str, Any]], reserved: set[str], depot_busy: bool,
                  result: CycleResult) -> None:
        destination = parse_coordinates(self.state["destination"], "relocation.destination")
        if all(ship.get("movement") is None and ship_sector(ship) == destination for ship in ships):
            self.state = None
            self._save()
            self.log(SpectatorEvent("DÉMÉNAGEMENT", "Toute la flotte a rejoint le nouveau système : reprise de la défense en étoile.", state="relocation"))
            return
        if self.state["phase"] == "preparing":
            center = ship_sector(mother)
            if mother.get("movement") is not None or center is None:
                return
            waiting = depot_busy or bool(reserved)
            recipients = []
            for ship in ships:
                if ship["id"] in reserved:
                    continue
                origin = ship_sector(ship)
                if not self._ready(ship, result) or origin is None:
                    waiting = True
                    continue
                if deuterium_amount(ship) < self.fuel_needed(origin, destination):
                    waiting = True
                    if ship["id"] == mother["id"]:
                        self.log(SpectatorEvent("RAVITAILLEMENT", "Déménagement en attente de carburant pour le vaisseau mère.", state=f"fuel:{mother['id']}"))
                    elif origin == center:
                        recipients.append(ship)
                    else:
                        self.move_towards(ship, center, result)
            if recipients:
                self.refueling.reconcile(mother, recipients, actions, result,
                                         reserve_deuterium=self.fuel_needed(center, destination))
            if any(action.get("status") in ACTIVE_STATUSES and action.get("type") in {
                "deuterium_transfer", "inventory_transfer",
            } for action in actions):
                waiting = True
            if waiting:
                return
            self.state["phase"] = "travelling"
            self._save()
        for ship in sorted(ships, key=lambda ship: (ship["id"] == mother["id"], ship["id"])):
            if ship.get("movement") is None and ship_sector(ship) != destination:
                self.move_towards(ship, destination, result)
