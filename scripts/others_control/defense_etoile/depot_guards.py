"""Affectations persistantes de quatre gardiens par secteur de dépôt connu."""

from __future__ import annotations

from .spectator import SpectatorEvent

import hashlib
import json
import os
from pathlib import Path
from typing import Any, Callable

from .commands import CommandExecutor, deuterium_amount, is_movable
from .contracts import require_string
from .engagement import EngagementCoordinator
from .errors import ApiContractError, ConfigurationError
from .formation import ship_sector
from .geometry import movement_hop, parse_coordinates
from .logistics import MothershipLogistics
from .models import Coordinates, CycleResult
from .ports import OthersApi
from .repairs import missing_integrity


GUARDS_PER_SECTOR = 4


class DepotGuardCoordinator:
    def __init__(self, api: OthersApi, commands: CommandExecutor, engagement: EngagementCoordinator,
                 *, logger: Callable[[str], None], state_dir: Path | None = None,
                 fuel_per_hop: float = 2.0) -> None:
        self.api = api
        self.commands = commands
        self.engagement = engagement
        self.log = logger
        self.state_dir = state_dir
        self.fuel_per_hop = fuel_per_hop
        self.fleet_id: str | None = None
        self.path: Path | None = None
        self.assignments: dict[str, dict[str, Any]] = {}
        self.known_sectors: set[Coordinates] = set()
        self.claimed_ships: set[str] = set()
        self.activity_guards: dict[str, dict[str, Any]] = {}
        self.center: Coordinates | None = None
        self.mothership_stationary = False
        self.threatened_sectors: set[Coordinates] = set()

    def load(self, fleet_id: str) -> None:
        if self.fleet_id == fleet_id:
            return
        self.fleet_id = fleet_id
        self.assignments = {}
        if self.state_dir is None:
            return
        self.path = self.state_dir / (hashlib.sha256(fleet_id.encode()).hexdigest() + '.guards.json')
        try:
            if not self.path.exists():
                return
            assignments = json.loads(self.path.read_text(encoding="utf-8"))
            if not isinstance(assignments, dict):
                raise ValueError("affectations invalides")
            for ship_id, assignment in assignments.items():
                require_string(ship_id, "guardian ship.id")
                if not isinstance(assignment, dict) or set(assignment) != {"destination", "stage", "replaces"}:
                    raise ValueError("affectation invalide")
                parse_coordinates(assignment["destination"], "guardian.destination")
                if assignment["stage"] not in {"outbound", "guarding", "returning"}:
                    raise ValueError("étape de garde invalide")
                if assignment["replaces"] is not None:
                    require_string(assignment["replaces"], "guardian.replaces")
            self.assignments = assignments
        except (OSError, ValueError, TypeError, ApiContractError) as error:
            raise ConfigurationError(f"Journal des gardiens illisible : {self.path}: {error}") from error

    def _save(self) -> None:
        if self.path is None:
            return
        try:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            temporary = self.path.with_suffix('.tmp')
            with temporary.open('w', encoding='utf-8') as stream:
                json.dump(self.assignments, stream, ensure_ascii=False, allow_nan=False)
                stream.flush()
                os.fsync(stream.fileno())
            temporary.replace(self.path)
        except OSError as error:
            raise ConfigurationError(f"Impossible de sauvegarder les gardiens : {error}") from error

    def reconcile(self, mother: dict[str, Any], ships: list[dict[str, Any]], result: CycleResult,
                  *, excluded: set[str], allow_assignments: bool = True) -> set[str]:
        self.load(require_string(mother.get("fleetId"), "mothership.fleetId"))
        self.activity_guards.clear()
        self.mothership_stationary = mother.get("movement") is None
        self.center = (parse_coordinates(mother["movement"]["target"], "mothership.movement.target")
                       if mother.get("movement") is not None else ship_sector(mother))
        self.known_sectors = {parse_coordinates(entry.get("relativeCoordinates"), "knownDepots[].relativeCoordinates")
                              for entry in self.api.get_known_depots(self.fleet_id)}
        by_id = {ship["id"]: ship for ship in ships if ship.get("type") == "standard"
                 and ship.get("location", {}).get("state") not in {"destroyed", "removed"}}
        self.claimed_ships = set(self.assignments)
        for ship_id, assignment in list(self.assignments.items()):
            if ship_id not in by_id:
                del self.assignments[ship_id]
                self.engagement.clear_completed_return(ship_id)
                self._save()
            elif parse_coordinates(assignment["destination"], "guardian.destination") not in self.known_sectors:
                assignment["stage"] = "returning"
                self._save()

        # Détecter une attaque avant toute relève ou reprise de trajet.
        for ship_id, assignment in self.assignments.items():
            ship = by_id[ship_id]
            if (ship_id not in excluded and assignment["stage"] != "returning"
                    and ship.get("movement") is None
                    and ship_sector(ship) == parse_coordinates(assignment["destination"], "guardian.destination")):
                assignment["stage"] = "guarding"
                self.activity_guards[ship_id] = ship
        engaged = self._observe(result)
        if self.threatened_sectors:
            self._save()
            return set(self.claimed_ships)

        # Un retour ou un déplacement engagé garde son affectation jusqu'à l'arrivée.
        for ship_id, assignment in list(self.assignments.items()):
            ship = by_id[ship_id]
            if ship_id in excluded:
                continue
            if ship.get("movement") is not None:
                result.add_event_date(ship["movement"].get("arrivalAt"), "guardian movement.arrivalAt")
                continue
            destination = parse_coordinates(assignment["destination"], "guardian.destination")
            position = ship_sector(ship)
            if assignment["stage"] == "returning":
                if position == self.center:
                    del self.assignments[ship_id]
                    self.engagement.clear_completed_return(ship_id)
                    self._save()
                elif self.center is not None:
                    self._move(ship, self.center, result)
                continue
            if position != destination:
                assignment["stage"] = "outbound"
                self._save()
                self._move(ship, destination, result)
                continue
            assignment["stage"] = "guarding"
            self._save()
            self.activity_guards[ship_id] = ship

        # Une relève n'autorise le retour de l'ancien gardien qu'après l'arrivée.
        for ship_id, assignment in list(self.assignments.items()):
            replaced_id = assignment["replaces"]
            if replaced_id is None or assignment["stage"] != "guarding":
                continue
            incumbent = self.assignments.get(replaced_id)
            if incumbent is not None and incumbent["stage"] != "returning":
                if replaced_id in engaged or not self._ready(by_id[replaced_id]):
                    continue
                self._return(replaced_id)
                if self.center is not None:
                    self._move(by_id[replaced_id], self.center, result)
            assignment["replaces"] = None
            self._save()

        if allow_assignments and self.center is not None and self.known_sectors:
            self._assign(by_id, excluded, engaged, result)
        self.claimed_ships.update(self.assignments)
        return set(self.claimed_ships)

    def _observe(self, result: CycleResult, *, ship_ids: set[str] | None = None) -> set[str]:
        engaged = set()
        if self.center is None:
            return engaged
        for ship_id, ship in list(self.activity_guards.items()):
            if ship_ids is not None and ship_id not in ship_ids:
                continue
            assignment = self.assignments.get(ship_id)
            if assignment is None or assignment["stage"] != "guarding":
                continue
            destination = parse_coordinates(assignment["destination"], "guardian.destination")
            outcome = self.engagement.reconcile(
                ship, destination, self.center, result,
                intercept_missiles=not (self.mothership_stationary and destination == self.center),
                allow_retreat=False,
            )
            if outcome.engaged:
                engaged.add(ship_id)
                self.threatened_sectors.add(destination)
            observation = self.engagement.scout_states[ship_id].observation
            if observation is not None and observation.missiles:
                # L'interception locale peut être déléguée au centre sans perdre l'alerte.
                self.threatened_sectors.add(destination)
        return engaged

    def observe_during_mobilization(self, mother: dict[str, Any], ships: list[dict[str, Any]],
                                   result: CycleResult, *, central_sector: Coordinates | None) -> None:
        """Surveille les gardiens encore à leur poste sans ordonner de relève ni de trajet."""
        self.center = (parse_coordinates(mother["movement"]["target"], "mothership.movement.target")
                       if mother.get("movement") is not None else ship_sector(mother))
        self.mothership_stationary = mother.get("movement") is None
        self.activity_guards = {}
        for ship in ships:
            assignment = self.assignments.get(ship["id"])
            if assignment is None or assignment["stage"] == "returning" or ship.get("movement") is not None:
                continue
            destination = parse_coordinates(assignment["destination"], "guardian.destination")
            if ship_sector(ship) == destination and destination != central_sector:
                assignment["stage"] = "guarding"
                self.activity_guards[ship["id"]] = ship
        self._observe(result)

    def reconcile_activity(self, result: CycleResult) -> None:
        self._observe(result)

    def _return(self, ship_id: str) -> None:
        self.assignments[ship_id]["stage"] = "returning"
        self.activity_guards.pop(ship_id, None)
        self._save()

    def _slots(self, destination: Coordinates) -> list[str]:
        active = {ship_id: assignment for ship_id, assignment in self.assignments.items()
                  if assignment["stage"] != "returning"
                  and parse_coordinates(assignment["destination"], "guardian.destination") == destination}
        return [ship_id for ship_id, assignment in active.items() if assignment["replaces"] not in active]

    def _assign(self, ships: dict[str, dict[str, Any]], excluded: set[str], engaged: set[str],
                result: CycleResult) -> None:
        counts: dict[str, int] = {}

        def missiles(ship_id: str) -> int:
            if ship_id not in counts:
                counts[ship_id] = len(self.commands.available_missiles(ship_id))
            return counts[ship_id]

        available = [ship for ship_id, ship in ships.items()
                     if ship_id not in self.claimed_ships and ship_id not in excluded
                     and ship_id not in self.assignments
                     and ship_sector(ship) in self.known_sectors | {self.center} and self._ready(ship)
                     and missing_integrity(ship) == 0]
        available.sort(key=lambda ship: (-missiles(ship["id"]), ship["id"]))
        for destination in sorted(self.known_sectors):
            newly_stationed = set()
            # Les résidents sont prioritaires ; les renforts viennent du vaisseau mère.
            candidates = sorted(available, key=lambda ship: (ship_sector(ship) != destination,
                                                             -missiles(ship["id"]), ship["id"]))
            for ship in candidates:
                if len(self._slots(destination)) >= GUARDS_PER_SECTOR:
                    break
                if ship_sector(ship) not in {self.center, destination}:
                    continue
                if not self._can_reach_and_return(ship, destination):
                    continue
                self._attach(ship, destination, None, result)
                if ship_sector(ship) == destination:
                    newly_stationed.add(ship["id"])
                available.remove(ship)

            engaged.update(self._observe(result, ship_ids=newly_stationed))
            if self.threatened_sectors:
                return

            covered = {assignment["replaces"] for assignment in self.assignments.values()
                       if assignment["stage"] != "returning"}
            incumbents = sorted(self._slots(destination), key=lambda ship_id: (missiles(ship_id), ship_id))
            for incumbent_id in incumbents:
                incumbent = self.assignments[incumbent_id]
                if (incumbent["stage"] != "guarding" or incumbent_id in covered
                        or incumbent_id in engaged or not self._ready(ships[incumbent_id])):
                    continue
                replacement = next((ship for ship in available if ship_sector(ship) in {self.center, destination}
                                    and missiles(ship["id"]) > missiles(incumbent_id)
                                    and self._can_reach_and_return(ship, destination)), None)
                if replacement is None:
                    continue
                self.log(SpectatorEvent("FORMATION", f"Relève du gardien {incumbent_id} par {replacement['id']} : "
                         f"{missiles(replacement['id'])} missiles contre {missiles(incumbent_id)}."))
                self._attach(replacement, destination, incumbent_id, result)
                available.remove(replacement)

    def _attach(self, ship: dict[str, Any], destination: Coordinates, replaces: str | None,
                result: CycleResult) -> None:
        ship_id = ship["id"]
        self.assignments[ship_id] = {"destination": dict(zip(("x", "y", "z"), destination)),
                                     "stage": "guarding" if ship_sector(ship) == destination else "outbound",
                                     "replaces": replaces}
        self.claimed_ships.add(ship_id)
        self._save()
        self.log(SpectatorEvent("FORMATION", f"Gardien {ship_id} affecté au secteur de dépôt relatif {destination}."))
        if ship_sector(ship) == destination:
            self.activity_guards[ship_id] = ship
        else:
            self._move(ship, destination, result)

    def _ready(self, ship: dict[str, Any]) -> bool:
        if not is_movable(ship) or ship_sector(ship) is None:
            return False
        state = self.engagement.scout_states.get(ship["id"])
        if state is not None and (state.return_required or state.pending_events):
            return False
        auxiliaries = self.api.get_auxiliaries(ship["id"])
        if len(MothershipLogistics._available_auxiliaries(auxiliaries)) != len(auxiliaries):
            return False
        inventory = self.api.get_inventory(ship["id"])
        return inventory["reservedEce"] == 0 and all(
            resource["reserved"] == 0 for resource in inventory["resources"].values())

    def _can_reach_and_return(self, ship: dict[str, Any], destination: Coordinates) -> bool:
        origin = ship_sector(ship)
        if origin == destination:
            return True
        steps = 0
        for start, end in ((origin, destination), (destination, self.center)):
            while start != end:
                start = movement_hop(start, end)
                steps += 1
        return deuterium_amount(ship) >= steps * self.fuel_per_hop

    def _move(self, ship: dict[str, Any], target: Coordinates, result: CycleResult) -> bool:
        origin = ship_sector(ship)
        if origin is None or origin == target or not self._ready(ship):
            return False
        if deuterium_amount(ship) < self.fuel_per_hop:
            self.log(SpectatorEvent("RAVITAILLEMENT", f"Gardien {ship['id']} en attente de carburant pour son déplacement.", state=f"fuel:{ship['id']}"))
            return False
        return self.commands.move(ship, movement_hop(origin, target), result)
