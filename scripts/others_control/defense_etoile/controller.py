"""Orchestration d'un cycle de défense étoile."""

from __future__ import annotations

from datetime import datetime, timezone
from math import isfinite
from typing import Any, Callable

from .commands import CommandExecutor
from .contracts import optional_mapping, require_mapping, require_string
from .engagement import EngagementCoordinator
from .errors import ApiContractError, ConfigurationError
from .formation import FormationCoordinator
from .geometry import format_coordinates, parse_coordinates
from .hazards import SectorKnowledge
from .logistics import LogisticsPolicy, MothershipLogistics
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
        logistics_policy: LogisticsPolicy | None = None,
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
        self.logistics = MothershipLogistics(
            api,
            logger=logger,
            now=self.now,
            policy=logistics_policy,
        )

    def log_fleet_summary(self) -> None:
        """Affiche une photographie de la flotte avant la première réconciliation."""
        if self.mothership_id is not None:
            mothership = self.api.get_ship(self.mothership_id)
            self._validate_mothership(mothership, self.mothership_id)
            fleet_id = require_string(mothership.get("fleetId"), "ship.fleetId")
        else:
            fleet_id = require_string(self.fleet_id, "fleet_id")

        ships = self._load_fleet_ships(fleet_id)
        fleet_mothership = self._select_mothership(ships, fleet_id)
        expected_mothership_id = self.mothership_id or require_string(
            fleet_mothership.get("id"), "mothership.id"
        )
        self._validate_mothership(
            fleet_mothership,
            expected_mothership_id,
        )

        ship_label = "vaisseau" if len(ships) == 1 else "vaisseaux"
        lines = [f"État initial de la flotte {fleet_id} : {len(ships)} {ship_label}."]
        for index, ship in enumerate(ships):
            context = f"fleet.ships[{index}]"
            ship_id = require_string(ship.get("id"), f"{context}.id")
            ship_type = require_string(ship.get("type"), f"{context}.type")
            status = require_string(ship.get("status"), f"{context}.status")
            auxiliary_count = self._require_count(
                ship.get("auxiliaryCount"), f"{context}.auxiliaryCount"
            )
            deployed_count = self._require_count(
                ship.get("deployedAuxiliaryCount"),
                f"{context}.deployedAuxiliaryCount",
            )
            if deployed_count > auxiliary_count:
                raise ApiContractError(
                    f"{context}.deployedAuxiliaryCount ne peut pas dépasser "
                    f"{context}.auxiliaryCount."
                )
            inventory = self.api.get_inventory(ship_id)
            missile_count = self._inventory_item_count(inventory, "missile")
            deployed_label = "déployé" if deployed_count == 1 else "déployés"
            lines.append(
                f"- {ship_id} [{ship_type}] — position relative : "
                f"{self._format_position(ship, context)} ; état : {status} ; "
                f"auxiliaires : {auxiliary_count} (dont {deployed_count} {deployed_label}) ; "
                f"missiles : {missile_count} ; mouvement : "
                f"{self._format_movement(ship, context)}."
            )
            lines.append(
                f"  Inventaire — {self._format_inventory(inventory, ship_id)}."
            )

        for line in lines:
            self.log(line)

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

        ships = self._load_fleet_ships(fleet_id)
        fleet_mothership = self._select_mothership(ships, fleet_id)

        mothership_id = require_string(fleet_mothership.get("id"), "mothership.id")
        self._validate_mothership(fleet_mothership, mothership_id)
        movement = optional_mapping(fleet_mothership.get("movement"), "mothership.movement")
        if movement is not None:
            result.add_event_date(movement.get("arrivalAt"), "mothership.movement.arrivalAt")
            self.log("Le vaisseau mère vient d'engager un mouvement : cycle reporté.")
            return result

        self.logistics.reconcile(fleet_mothership, result)
        return self.formation.reconcile(fleet_mothership, ships, result)

    def _load_fleet_ships(self, fleet_id: str) -> list[dict[str, Any]]:
        fleet = self.api.get_fleet(fleet_id)
        if require_string(fleet.get("id"), "fleet.id") != fleet_id:
            raise ApiContractError(
                "La flotte retournée ne correspond pas à l'identifiant demandé."
            )
        ships_value = fleet.get("ships")
        if not isinstance(ships_value, list):
            raise ApiContractError("fleet.ships doit être une liste.")
        return [
            require_mapping(ship, f"fleet.ships[{index}]")
            for index, ship in enumerate(ships_value)
        ]

    @staticmethod
    def _require_count(value: Any, context: str) -> int:
        if isinstance(value, bool) or not isinstance(value, int) or value < 0:
            raise ApiContractError(f"{context} doit être un entier positif ou nul.")
        return value

    @staticmethod
    def _require_non_negative_number(value: Any, context: str) -> float:
        if (
            isinstance(value, bool)
            or not isinstance(value, (int, float))
            or not isfinite(value)
            or value < 0
        ):
            raise ApiContractError(f"{context} doit être un nombre positif ou nul.")
        return float(value)

    @classmethod
    def _inventory_items(cls, inventory: dict[str, Any]) -> list[dict[str, Any]]:
        values = inventory.get("items")
        if not isinstance(values, list):
            raise ApiContractError("inventory.items doit être une liste.")
        return [
            require_mapping(value, f"inventory.items[{index}]")
            for index, value in enumerate(values)
        ]

    @classmethod
    def _inventory_item_count(cls, inventory: dict[str, Any], item_type: str) -> int:
        return sum(
            item.get("type") == item_type
            for item in cls._inventory_items(inventory)
        )

    @classmethod
    def _format_inventory(cls, inventory: dict[str, Any], ship_id: str) -> str:
        capacity = cls._require_non_negative_number(
            inventory.get("capacityEce"), f"inventory {ship_id}.capacityEce"
        )
        used = cls._require_non_negative_number(
            inventory.get("usedEce"), f"inventory {ship_id}.usedEce"
        )
        reserved = cls._require_non_negative_number(
            inventory.get("reservedEce"), f"inventory {ship_id}.reservedEce"
        )

        resources = require_mapping(
            inventory.get("resources"), f"inventory {ship_id}.resources"
        )
        resource_labels = {
            "metals": "métaux",
            "ice": "glace",
            "carbon_compounds": "composés carbonés",
            "deuterium": "deutérium",
        }
        resource_parts = []
        for resource_type, label in resource_labels.items():
            resource = require_mapping(
                resources.get(resource_type),
                f"inventory {ship_id}.resources.{resource_type}",
            )
            amount = cls._require_non_negative_number(
                resource.get("amount"),
                f"inventory {ship_id}.resources.{resource_type}.amount",
            )
            resource_reserved = cls._require_non_negative_number(
                resource.get("reserved"),
                f"inventory {ship_id}.resources.{resource_type}.reserved",
            )
            resource_parts.append(
                f"{label} : {cls._format_number(amount)} ECE "
                f"(dont {cls._format_number(resource_reserved)} réservées)"
            )

        item_totals: dict[str, tuple[int, float]] = {}
        for index, item in enumerate(cls._inventory_items(inventory)):
            item_type = require_string(item.get("type"), f"inventory.items[{index}].type")
            space = cls._require_non_negative_number(
                item.get("containerSpaceEce"),
                f"inventory.items[{index}].containerSpaceEce",
            )
            count, total_space = item_totals.get(item_type, (0, 0.0))
            item_totals[item_type] = (count + 1, total_space + space)

        item_parts = [
            f"{item_type} × {count} ({cls._format_number(total_space)} ECE)"
            for item_type, (count, total_space) in sorted(item_totals.items())
        ]
        items_text = ", ".join(item_parts) if item_parts else "aucun"
        return (
            f"occupation : {cls._format_number(used)} / "
            f"{cls._format_number(capacity)} ECE ; capacité réservée : "
            f"{cls._format_number(reserved)} ECE ; ressources : "
            f"{', '.join(resource_parts)} ; objets : {items_text}"
        )

    @staticmethod
    def _format_number(value: float) -> str:
        return f"{value:.4f}".rstrip("0").rstrip(".")

    @staticmethod
    def _format_position(ship: dict[str, Any], context: str) -> str:
        location = require_mapping(ship.get("location"), f"{context}.location")
        state = require_string(location.get("state"), f"{context}.location.state")
        if state == "in_sector":
            sector = require_mapping(ship.get("sector"), f"{context}.sector")
            return format_coordinates(
                parse_coordinates(sector.get("relative"), f"{context}.sector.relative")
            )
        labels = {
            "transit": "en transit entre deux secteurs",
            "removed": "vaisseau retiré",
            "destroyed": "vaisseau détruit",
        }
        if state not in labels:
            raise ApiContractError(f"{context}.location.state est inconnu : {state}.")
        return labels[state]

    @staticmethod
    def _format_movement(ship: dict[str, Any], context: str) -> str:
        movement = optional_mapping(ship.get("movement"), f"{context}.movement")
        if movement is None:
            return "aucun"
        phase = require_string(movement.get("phase"), f"{context}.movement.phase")
        phase_label = {
            "waiting_to_depart": "en attente du départ",
            "transit": "en transit",
        }.get(phase)
        if phase_label is None:
            raise ApiContractError(
                f"{context}.movement.phase est inconnue : {phase}."
            )
        target = format_coordinates(
            parse_coordinates(movement.get("target"), f"{context}.movement.target")
        )
        arrival_at = require_string(
            movement.get("arrivalAt"), f"{context}.movement.arrivalAt"
        )
        return f"{phase_label} vers {target}, arrivée prévue {arrival_at}"

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
