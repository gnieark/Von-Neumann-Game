"""Orchestration d'un cycle de défense étoile."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Any, Callable

from .commands import CommandExecutor
from .contracts import optional_mapping, require_mapping, require_string
from .engagement import EngagementCoordinator
from .errors import ApiContractError, ConfigurationError
from .formation import FormationCoordinator
from .hazards import SectorKnowledge
from .models import CycleResult, DefensePolicy
from .observation import ScoutObserver
from .ports import OthersApi


class DefenseEtoileAttente:
    def __init__(
        self,
        api: OthersApi,
        *,
        mothership_id: str | None = None,
        fleet_id: str | None = None,
        logger: Callable[[str], None] = print,
        now: Callable[[], datetime] | None = None,
        policy: DefensePolicy | None = None,
    ) -> None:
        if (mothership_id is None) == (fleet_id is None):
            raise ConfigurationError(
                "Renseignez exactement un identifiant : vaisseau mère ou flotte."
            )
        selected_id = mothership_id if mothership_id is not None else fleet_id
        if not isinstance(selected_id, str) or not selected_id.strip():
            raise ConfigurationError("L'identifiant sélectionné doit être une chaîne non vide.")

        self.api = api
        self.mothership_id = mothership_id.strip() if mothership_id is not None else None
        self.fleet_id = fleet_id.strip() if fleet_id is not None else None
        self.log = logger
        self.now = now or (lambda: datetime.now(timezone.utc))
        self.policy = policy or DefensePolicy()

        commands = CommandExecutor(api, logger)
        engagement = EngagementCoordinator(
            ScoutObserver(api),
            commands,
            policy=self.policy,
            logger=logger,
            now=self.now,
        )
        self.formation = FormationCoordinator(
            commands,
            engagement,
            SectorKnowledge(api, logger),
            policy=self.policy,
            logger=logger,
        )

    def run_cycle(self) -> CycleResult:
        result = CycleResult()
        if self.mothership_id is not None:
            mothership = self.api.get_ship(self.mothership_id)
            self._validate_mothership(mothership, self.mothership_id)
            mothership_movement = optional_mapping(mothership.get("movement"), "ship.movement")
            if mothership_movement is not None:
                result.add_event_date(
                    mothership_movement.get("arrivalAt"),
                    "ship.movement.arrivalAt",
                )
                self.log(
                    "Le vaisseau mère est en mouvement : "
                    "formation suspendue jusqu'à son arrivée."
                )
                return result
            fleet_id = require_string(mothership.get("fleetId"), "ship.fleetId")
        else:
            fleet_id = require_string(self.fleet_id, "fleet_id")

        fleet = self.api.get_fleet(fleet_id)
        if require_string(fleet.get("id"), "fleet.id") != fleet_id:
            raise ApiContractError(
                "La flotte retournée ne correspond pas à l'identifiant demandé."
            )
        ships_value = fleet.get("ships")
        if not isinstance(ships_value, list):
            raise ApiContractError("fleet.ships doit être une liste.")
        ships = [require_mapping(ship, "fleet.ships[]") for ship in ships_value]
        fleet_mothership = self._select_mothership(ships, fleet_id)

        mothership_id = require_string(fleet_mothership.get("id"), "mothership.id")
        self._validate_mothership(fleet_mothership, mothership_id)
        movement = optional_mapping(fleet_mothership.get("movement"), "mothership.movement")
        if movement is not None:
            result.add_event_date(movement.get("arrivalAt"), "mothership.movement.arrivalAt")
            self.log("Le vaisseau mère vient d'engager un mouvement : cycle reporté.")
            return result

        return self.formation.reconcile(fleet_mothership, ships, result)

    def _select_mothership(
        self,
        ships: list[dict[str, Any]],
        fleet_id: str,
    ) -> dict[str, Any]:
        if self.mothership_id is not None:
            mothership = next(
                (ship for ship in ships if ship.get("id") == self.mothership_id),
                None,
            )
            if mothership is None:
                raise ApiContractError("Le vaisseau mère demandé est absent de sa flotte.")
            return mothership

        motherships = [ship for ship in ships if ship.get("type") == "mothership"]
        if not motherships:
            raise ConfigurationError(
                f"La flotte {fleet_id} ne contient aucun vaisseau mère actif."
            )
        if len(motherships) > 1:
            raise ApiContractError("fleet.ships contient plusieurs vaisseaux mères.")
        return motherships[0]

    @staticmethod
    def _validate_mothership(ship: dict[str, Any], expected_id: str) -> None:
        ship_id = require_string(ship.get("id"), "ship.id")
        if ship_id != expected_id:
            raise ApiContractError(
                "Le vaisseau retourné ne correspond pas à l'identifiant demandé."
            )
        if ship.get("type") != "mothership":
            raise ConfigurationError(f"{expected_id} n'est pas un vaisseau mère.")
