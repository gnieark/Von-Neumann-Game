"""Défense de guerre du secteur occupé par le vaisseau mère."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timedelta
from typing import Any, Callable

from .commands import CommandExecutor, deuterium_amount, is_movable
from .contracts import require_mapping, require_string
from .errors import ApiContractError, ApiRequestError
from .geometry import NEIGHBOR_OFFSETS, add_coordinates, parse_coordinates
from .models import Coordinates, CycleResult, DefensePolicy, ScoutObservation
from .observation import ScoutObserver
from .ports import OthersApi


@dataclass(frozen=True)
class PendingMissile:
    target_id: str
    expires_at: datetime


@dataclass(frozen=True)
class LaserAssignment:
    ship_id: str
    action_id: str


class CentralDefenseCoordinator:
    def __init__(
        self,
        api: OthersApi,
        observer: ScoutObserver,
        commands: CommandExecutor,
        *,
        policy: DefensePolicy,
        logger: Callable[[str], None],
        now: Callable[[], datetime],
    ) -> None:
        self.api = api
        self.observer = observer
        self.commands = commands
        self.policy = policy
        self.log = logger
        self.now = now
        self.mothership_id: str | None = None
        self.fleet_id: str | None = None
        self.center: Coordinates | None = None
        self.at_war = False
        self.pending_missiles: dict[str, PendingMissile] = {}
        self.visible_missile_ids: set[str] = set()
        self.laser_assignments: dict[str, LaserAssignment] = {}
        self.recalled_sentinel_ids: set[str] = set()

    def clear_context(self) -> None:
        self.mothership_id = None
        self.fleet_id = None
        self.center = None
        self.at_war = False
        self.pending_missiles.clear()
        self.visible_missile_ids.clear()
        self.laser_assignments.clear()
        self.recalled_sentinel_ids.clear()

    def configure(self, mothership: dict[str, Any], center: Coordinates) -> None:
        mothership_id = require_string(mothership.get("id"), "mothership.id")
        fleet_id = require_string(mothership.get("fleetId"), "mothership.fleetId")
        if (
            self.mothership_id is not None
            and (
                self.mothership_id != mothership_id
                or self.fleet_id != fleet_id
                or self.center != center
            )
        ):
            self.clear_context()
        self.mothership_id = mothership_id
        self.fleet_id = fleet_id
        self.center = center

    def reconcile(
        self,
        result: CycleResult,
        *,
        ships: list[dict[str, Any]] | None = None,
    ) -> bool:
        if self.mothership_id is None or self.fleet_id is None or self.center is None:
            return False

        observation = self.observer.observe(self.mothership_id, self.center)
        manny_ids = set(observation.autonomous_units) | set(observation.ejected_mannies)
        if not observation.probe_ids and not manny_ids:
            if self.at_war:
                self.log("Fin d'alerte dans le secteur du vaisseau mère.")
            self.at_war = False
            self.pending_missiles.clear()
            self.visible_missile_ids.clear()
            self.laser_assignments.clear()
            self.recalled_sentinel_ids.clear()
            return False

        if ships is None:
            ships = self._load_fleet_ships()
        if not self.at_war:
            self.log(
                "ALERTE CENTRALE : activité de sonde détectée ; rappel général "
                "des sentinelles et engagement de guerre."
            )
        self.at_war = True

        self._recall_sentinels(ships, result)
        present_ships = self._present_ships(ships)
        self._maintain_missile_screen(observation, present_ships, result)
        self._maintain_laser_assignments(manny_ids, present_ships, result)
        return True

    def _load_fleet_ships(self) -> list[dict[str, Any]]:
        fleet = self.api.get_fleet(require_string(self.fleet_id, "fleet_id"))
        if require_string(fleet.get("id"), "fleet.id") != self.fleet_id:
            raise ApiContractError(
                "La flotte retournée ne correspond pas à la défense centrale."
            )
        values = fleet.get("ships")
        if not isinstance(values, list):
            raise ApiContractError("fleet.ships doit être une liste.")
        return [
            require_mapping(ship, f"fleet.ships[{index}]")
            for index, ship in enumerate(values)
        ]

    def _recall_sentinels(
        self,
        ships: list[dict[str, Any]],
        result: CycleResult,
    ) -> None:
        center = self._required_center()
        neighbor_sectors = {
            add_coordinates(center, offset) for offset in NEIGHBOR_OFFSETS
        }
        for ship in sorted(ships, key=lambda value: str(value.get("id", ""))):
            ship_id = require_string(ship.get("id"), "fleet.ships[].id")
            if ship_id == self.mothership_id or ship.get("type") == "mothership":
                continue
            if ship_id in self.recalled_sentinel_ids:
                continue
            if ship.get("movement") is not None or not is_movable(ship):
                continue
            coordinates = self._ship_sector(ship)
            if coordinates not in neighbor_sectors:
                continue
            if self.commands.move(ship, center, result):
                self.recalled_sentinel_ids.add(ship_id)
                self.log(f"Rappel de guerre de la sentinelle {ship_id} engagé.")

    def _present_ships(self, ships: list[dict[str, Any]]) -> list[dict[str, Any]]:
        center = self._required_center()
        return sorted(
            (
                ship
                for ship in ships
                if self._ship_sector(ship) == center
                and require_mapping(
                    ship.get("location"), f"ship {ship.get('id', '?')}.location"
                ).get("state") == "in_sector"
                and ship.get("status") not in {"transit", "removed", "destroyed"}
            ),
            key=lambda ship: str(ship.get("id", "")),
        )

    def _maintain_missile_screen(
        self,
        observation: ScoutObservation,
        ships: list[dict[str, Any]],
        result: CycleResult,
    ) -> None:
        probe_ids = set(observation.probe_ids)
        visible_ids = {
            missile_id
            for missile_ids in observation.missiles_targeting_probes.values()
            for missile_id in missile_ids
        }
        now = self.now()
        self.pending_missiles = {
            action_id: pending
            for action_id, pending in self.pending_missiles.items()
            if pending.target_id in probe_ids
            and pending.expires_at > now
        }
        newly_visible_ids = visible_ids - self.visible_missile_ids
        for probe_id, missile_ids in observation.missiles_targeting_probes.items():
            confirmations = sum(missile_id in newly_visible_ids for missile_id in missile_ids)
            pending_action_ids = sorted(
                action_id
                for action_id, pending in self.pending_missiles.items()
                if pending.target_id == probe_id
            )
            for action_id in pending_action_ids[:confirmations]:
                self.pending_missiles.pop(action_id, None)
        self.visible_missile_ids = visible_ids
        if not probe_ids:
            return

        missing = {
            probe_id: max(
                0,
                self.policy.central_missiles_per_probe
                - len(observation.missiles_targeting_probes.get(probe_id, ()))
                - sum(
                    pending.target_id == probe_id
                    for pending in self.pending_missiles.values()
                ),
            )
            for probe_id in sorted(probe_ids)
        }
        if not any(count > 0 for count in missing.values()):
            return

        ammunition: dict[str, list[str]] = {}
        for ship in ships:
            ship_id = require_string(ship.get("id"), "central ship.id")
            ammunition[ship_id] = self.commands.available_missiles(ship_id)

        while any(count > 0 for count in missing.values()):
            launched_in_round = False
            for probe_id in sorted(missing):
                if missing[probe_id] <= 0:
                    continue
                launchers = [ship_id for ship_id, missiles in ammunition.items() if missiles]
                if not launchers:
                    break
                launcher_id = min(
                    launchers,
                    key=lambda ship_id: (-len(ammunition[ship_id]), ship_id),
                )
                missile_item_id = ammunition[launcher_id].pop(0)
                action = self.commands.launch_missile(
                    launcher_id,
                    missile_item_id,
                    probe_id,
                    f"central-war:{probe_id}",
                    result,
                )
                if action is None:
                    continue
                action_id = require_string(action.get("id"), "missile action.id")
                self.pending_missiles[action_id] = PendingMissile(
                    probe_id,
                    now + timedelta(seconds=self.policy.central_pending_missile_seconds),
                )
                missing[probe_id] -= 1
                launched_in_round = True
            if not launched_in_round:
                break

        shortages = {probe_id: count for probe_id, count in missing.items() if count > 0}
        if shortages:
            details = ", ".join(
                f"sonde {probe_id}: {count}" for probe_id, count in shortages.items()
            )
            self.log(f"Écran de missiles incomplet, munitions manquantes — {details}.")

    def _maintain_laser_assignments(
        self,
        manny_ids: set[str],
        ships: list[dict[str, Any]],
        result: CycleResult,
    ) -> None:
        ships_by_id = {
            require_string(ship.get("id"), "central ship.id"): ship for ship in ships
        }
        for manny_id, assignment in list(self.laser_assignments.items()):
            if manny_id not in manny_ids or assignment.ship_id not in ships_by_id:
                self.laser_assignments.pop(manny_id, None)
                continue
            try:
                action = self.api.get_action(assignment.action_id)
            except ApiRequestError as error:
                if error.status == 404:
                    self.laser_assignments.pop(manny_id, None)
                    continue
                raise
            status = require_string(action.get("status"), "laser action.status")
            if status not in {"queued", "running"}:
                self.laser_assignments.pop(manny_id, None)

        used_ship_ids = {
            assignment.ship_id for assignment in self.laser_assignments.values()
        }
        for manny_id in sorted(manny_ids - set(self.laser_assignments)):
            candidates = [
                ship
                for ship_id, ship in ships_by_id.items()
                if ship_id not in used_ship_ids
                and ship.get("movement") is None
                and deuterium_amount(ship) > self.policy.laser_deuterium_threshold
            ]
            if not candidates:
                self.log(f"Aucun vaisseau disponible pour verrouiller la Manny {manny_id}.")
                continue
            ship = min(
                candidates,
                key=lambda value: (
                    -deuterium_amount(value),
                    str(value.get("id", "")),
                ),
            )
            ship_id = require_string(ship.get("id"), "laser ship.id")
            used_ship_ids.add(ship_id)
            action = self.commands.start_laser(
                ship_id,
                manny_id,
                f"central-war:{manny_id}:{self.now().isoformat()}",
                result,
            )
            if action is None:
                continue
            action_id = require_string(action.get("id"), "laser action.id")
            self.laser_assignments[manny_id] = LaserAssignment(ship_id, action_id)
            self.log(f"{ship_id} affecté à la destruction laser de la Manny {manny_id}.")

    def _required_center(self) -> Coordinates:
        if self.center is None:
            raise ApiContractError("Le centre tactique de la défense n'est pas disponible.")
        return self.center

    @staticmethod
    def _ship_sector(ship: dict[str, Any]) -> Coordinates | None:
        sector = ship.get("sector")
        if sector is None:
            return None
        mapping = require_mapping(sector, f"ship {ship.get('id', '?')}.sector")
        return parse_coordinates(
            mapping.get("relative"), f"ship {ship.get('id', '?')}.sector.relative"
        )
