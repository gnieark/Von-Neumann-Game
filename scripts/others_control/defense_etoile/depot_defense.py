"""Mobilisation de toute la flotte et maintien temporaire au dépôt attaqué."""

from __future__ import annotations

from datetime import datetime, timedelta
from typing import Any, Callable

from .central_defense import CentralDefenseCoordinator
from .commands import deuterium_amount
from .depot_guards import DepotGuardCoordinator
from .formation import ship_sector
from .logistics import ACTIVE_STATUSES
from .models import Coordinates, CycleResult
from .relocation import FleetRelocationCoordinator
from .spectator import SpectatorEvent


class DepotDefenseCoordinator:
    def __init__(self, guards: DepotGuardCoordinator, central: CentralDefenseCoordinator,
                 relocation: FleetRelocationCoordinator, *, now: Callable[[], datetime],
                 logger: Callable[[str], None]) -> None:
        self.guards = guards
        self.central = central
        self.relocation = relocation
        self.now = now
        self.log = logger
        # Intentionnellement en mémoire : aucune reprise de l'alerte après redémarrage.
        self.destination: Coordinates | None = None
        self.hold_until: datetime | None = None

    def start_if_alerted(self) -> bool:
        if self.destination is None and self.guards.threatened_sectors:
            self.destination = min(self.guards.threatened_sectors)
            self.hold_until = None
            self.relocation.cancel()
            self.central.clear_context()
            self.log(SpectatorEvent(
                "COMBAT", f"Dépôt attaqué en {self.destination} : les gardiens tiennent leur poste, "
                "mobilisation de toute la flotte.", state="depot-alert",
            ))
        self.guards.threatened_sectors.clear()
        return self.destination is not None

    def reconcile(self, mother: dict[str, Any], ships: list[dict[str, Any]],
                  actions: list[dict[str, Any]], result: CycleResult) -> None:
        destination = self.destination
        if destination is None:
            return
        ships = [ship for ship in ships if ship.get("status") not in {"destroyed", "removed"}
                 and ship.get("location", {}).get("state") not in {"destroyed", "removed"}]
        for action in actions:
            if action.get("status") in ACTIVE_STATUSES:
                result.add_event_date(action.get("endsAt"), "mobilization action.endsAt")

        mother_present = mother.get("movement") is None and ship_sector(mother) == destination
        self.guards.observe_during_mobilization(
            mother, ships, result, central_sector=destination if mother_present else None,
        )
        # Une mobilisation en cours garde sa destination, même si un autre dépôt réagit.
        self.guards.threatened_sectors.clear()
        if mother_present:
            self.central.configure(mother, destination)
            self.central.excluded_ship_ids = set()
            self.central.reconcile(result, ships=ships, recall_sentinels=False)
        else:
            self.central.clear_context()

        missing = [ship for ship in ships
                   if ship.get("movement") is not None or ship_sector(ship) != destination]
        if missing:
            self._gather(mother, missing, actions, result)
            return

        if self.hold_until is None:
            self.hold_until = self.now() + timedelta(hours=2)
            self.log(SpectatorEvent(
                "COMBAT", "Toute la flotte a rejoint le dépôt : maintien sur place pendant au moins "
                "deux heures et tant qu'une sonde est présente.", state="depot-alert",
            ))
        if self.now() < self.hold_until:
            result.event_dates.append(self.hold_until)
        elif not self.central.at_war:
            self.destination = None
            self.hold_until = None
            self.log(SpectatorEvent(
                "COMBAT", "Maintien au dépôt terminé : reprise des missions de la flotte.", state="depot-alert",
            ))

    def _gather(self, mother: dict[str, Any], missing: list[dict[str, Any]],
                actions: list[dict[str, Any]], result: CycleResult) -> None:
        destination = self.destination
        center = ship_sector(mother)
        recipients = []
        if mother.get("movement") is None and center is not None:
            recipients = [ship for ship in missing if ship["id"] != mother["id"]
                          and ship.get("movement") is None and ship_sector(ship) == center
                          and deuterium_amount(ship) < self.relocation.fuel_needed(center, destination)]
            if recipients:
                self.relocation.refueling.reconcile(
                    mother, recipients, actions, result,
                    reserve_deuterium=self.relocation.fuel_needed(center, destination),
                )
        for ship in sorted(missing, key=lambda value: (value["id"] == mother["id"], value["id"])):
            if ship.get("movement") is not None:
                result.add_event_date(ship["movement"].get("arrivalAt"), "mobilization movement.arrivalAt")
            elif ship not in recipients and not (ship["id"] == mother["id"] and recipients):
                self.relocation.move_towards(ship, destination, result)
