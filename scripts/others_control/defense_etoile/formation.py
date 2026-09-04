"""Réconciliation de la formation autour du vaisseau mère."""

from __future__ import annotations

from typing import Any, Callable

from .commands import CommandExecutor, is_movable
from .contracts import optional_mapping, require_mapping, require_string
from .engagement import EngagementCoordinator
from .errors import ApiContractError
from .geometry import (
    NEIGHBOR_OFFSETS,
    add_coordinates,
    format_coordinates,
    movement_hop,
    parse_coordinates,
)
from .hazards import SectorKnowledge
from .models import Coordinates, CycleResult, DefensePolicy


class FormationCoordinator:
    def __init__(
        self,
        commands: CommandExecutor,
        engagement: EngagementCoordinator,
        hazards: SectorKnowledge,
        *,
        policy: DefensePolicy,
        logger: Callable[[str], None],
    ) -> None:
        self.commands = commands
        self.engagement = engagement
        self.hazards = hazards
        self.policy = policy
        self.log = logger
        self.activity_guards: dict[Coordinates, dict[str, Any]] = {}
        self.activity_center: Coordinates | None = None

    def clear_activity_watch(self) -> None:
        self.activity_guards = {}
        self.activity_center = None

    def reconcile(
        self,
        mothership: dict[str, Any],
        ships: list[dict[str, Any]],
        result: CycleResult,
        *,
        missile_counts: dict[str, int],
    ) -> CycleResult:
        self.clear_activity_watch()
        mothership_id = require_string(mothership.get("id"), "mothership.id")
        center = ship_sector(mothership)
        if center is None:
            raise ApiContractError("Le vaisseau mère n'a pas de secteur courant exploitable.")
        self.activity_center = center
        neighbors = tuple(add_coordinates(center, offset) for offset in NEIGHBOR_OFFSETS)
        neighbor_set = set(neighbors)

        residents: dict[Coordinates, list[dict[str, Any]]] = {
            coordinates: [] for coordinates in neighbors
        }
        inbound_neighbors: set[Coordinates] = set()
        home_candidates: list[dict[str, Any]] = []
        recall_candidates: list[tuple[dict[str, Any], Coordinates]] = []

        for ship in ships:
            ship_id = require_string(ship.get("id"), "fleet.ships[].id")
            if ship_id == mothership_id or ship.get("type") == "mothership":
                continue
            location = require_mapping(ship.get("location"), f"ship {ship_id}.location")
            if location.get("state") in {"removed", "destroyed"}:
                continue

            movement = optional_mapping(ship.get("movement"), f"ship {ship_id}.movement")
            if movement is not None:
                target = parse_coordinates(
                    movement.get("target"), f"ship {ship_id}.movement.target"
                )
                if target in neighbor_set:
                    inbound_neighbors.add(target)
                result.add_event_date(
                    movement.get("arrivalAt"), f"ship {ship_id}.movement.arrivalAt"
                )
                continue

            coordinates = ship_sector(ship)
            if coordinates is None:
                continue
            if coordinates == center:
                self.engagement.clear_completed_return(ship_id)
                if is_movable(ship):
                    home_candidates.append(ship)
                continue
            if coordinates in neighbor_set:
                residents[coordinates].append(ship)
                continue
            recall_candidates.append((ship, coordinates))

        guards: dict[Coordinates, dict[str, Any]] = {}
        for coordinates, ships_in_sector in residents.items():
            if not ships_in_sector:
                continue
            ordered = sorted(
                ships_in_sector,
                key=lambda ship: (
                    is_movable(ship),
                    -missile_counts.get(str(ship.get("id", "")), 0),
                    str(ship.get("id", "")),
                ),
            )
            guards[coordinates] = ordered[0]
            for surplus_ship in ordered[1:]:
                recall_candidates.append((surplus_ship, coordinates))

        tactically_committed: set[str] = set()
        for coordinates, guard in guards.items():
            engagement = self.engagement.reconcile(guard, coordinates, center, result)
            if engagement.engaged:
                tactically_committed.add(
                    require_string(guard.get("id"), "guard ship.id")
                )
            if engagement.remains_on_station:
                self.activity_guards[coordinates] = guard

        known_black_holes = {
            coordinates
            for coordinates in neighbors
            if self.hazards.certainly_has_black_hole(
                coordinates,
                mothership_id=mothership_id,
                has_stationed_ship=bool(residents[coordinates]),
            )
        }
        occupied_neighbors = {
            coordinates for coordinates, sector_ships in residents.items() if sector_ships
        }
        missing_neighbors = [
            coordinates
            for coordinates in neighbors
            if coordinates not in known_black_holes
            and coordinates not in occupied_neighbors
            and coordinates not in inbound_neighbors
        ]

        claimed_home_ships: set[str] = set()
        armed_home_candidates = sorted(
            (
                ship
                for ship in home_candidates
                if missile_counts.get(str(ship.get("id", "")), 0) > 0
            ),
            key=lambda ship: (
                -missile_counts.get(str(ship.get("id", "")), 0),
                str(ship.get("id", "")),
            ),
        )
        for coordinates in neighbors:
            guard = guards.get(coordinates)
            if guard is None or coordinates in inbound_neighbors:
                continue
            guard_id = require_string(guard.get("id"), "guard ship.id")
            if (
                missile_counts.get(guard_id, 0) != 0
                or guard_id in tactically_committed
                or not is_movable(guard)
            ):
                continue
            replacement = next(
                (
                    candidate
                    for candidate in armed_home_candidates
                    if require_string(candidate.get("id"), "replacement.id")
                    not in claimed_home_ships
                ),
                None,
            )
            if replacement is None:
                break
            replacement_id = require_string(replacement.get("id"), "replacement.id")
            self.log(
                f"Relève de {guard_id} par {replacement_id} : "
                "départ prioritaire du vaisseau armé."
            )
            if not self.commands.move(replacement, coordinates, result):
                continue
            claimed_home_ships.add(replacement_id)
            if self.commands.move(guard, center, result):
                self.activity_guards.pop(coordinates, None)
                self.log(
                    f"Relève engagée : {guard_id} retourne auprès du vaisseau mère."
                )
            else:
                self.log(
                    f"Retour de {guard_id} différé ; {replacement_id} maintient la relève."
                )

        recall_candidates.sort(key=lambda item: str(item[0].get("id", "")))
        for ship, origin in recall_candidates:
            ship_id = require_string(ship.get("id"), "recall ship.id")
            if not is_movable(ship):
                self.log(
                    f"Rappel différé pour {ship_id} : "
                    f"vaisseau occupé ({ship.get('status')})."
                )
                continue
            target = movement_hop(origin, center, self.policy.max_movement_distance)
            if target != center:
                self.log(
                    f"Rappel par étape de {ship_id} vers {format_coordinates(target)} "
                    f"(destination finale {format_coordinates(center)})."
                )
            else:
                self.log(f"Rappel de {ship_id} vers le secteur du vaisseau mère.")
            self.commands.move(ship, target, result)

        available_home_candidates = [
            ship
            for ship in home_candidates
            if require_string(ship.get("id"), "deployment ship.id")
            not in claimed_home_ships
        ]
        available_home_candidates.sort(key=lambda ship: str(ship.get("id", "")))
        for ship, target in zip(available_home_candidates, missing_neighbors):
            ship_id = require_string(ship.get("id"), "deployment ship.id")
            self.log(f"Déploiement de {ship_id} vers {format_coordinates(target)}.")
            self.commands.move(ship, target, result)

        self.log(
            "Cycle terminé : "
            f"{len(occupied_neighbors)} voisin(s) occupé(s), "
            f"{len(inbound_neighbors)} réservé(s) par un mouvement, "
            f"{len(known_black_holes)} trou(s) noir(s) certain(s), "
            f"{result.accepted_commands} commande(s) acceptée(s)."
        )
        return result

    def reconcile_activity(self, result: CycleResult) -> CycleResult:
        """Actualise uniquement les observations des sentinelles en poste."""
        if not self.activity_guards:
            return result
        if self.activity_center is None:
            raise ApiContractError("Le centre tactique de la formation n'est pas disponible.")

        for coordinates, guard in list(self.activity_guards.items()):
            engagement = self.engagement.reconcile(
                guard, coordinates, self.activity_center, result
            )
            if not engagement.remains_on_station:
                self.activity_guards.pop(coordinates, None)
        return result


def ship_sector(ship: dict[str, Any]) -> Coordinates | None:
    sector = ship.get("sector")
    if sector is None:
        return None
    sector_data = require_mapping(sector, "ship.sector")
    return parse_coordinates(sector_data.get("relative"), "ship.sector.relative")
