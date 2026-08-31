#!/usr/bin/env python3
"""Maintient une flotte Others en formation « défense étoile - attente »."""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import time
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Callable, Protocol
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode, urlsplit
from urllib.request import Request, urlopen


CONFIG_PATH = Path(__file__).with_name("config.json")
TIMEOUT_SECONDS = 10.0
IDLE_REFRESH_SECONDS = 300.0
MAX_MOVEMENT_DISTANCE = 10
LASER_ENGAGEMENT_SECONDS = 600
LASER_DEUTERIUM_THRESHOLD = 12.0
FLOATING_OBJECT_TYPES = {"drifting_item", "detached_container"}
EVENT_PRIORITY = {
    "hostile_missile": 0,
    "asteroid_trajectory": 1,
    "deployed_manny": 2,
    "ejected_manny": 3,
    "floating_object_change": 4,
    "waypoint_change": 5,
}
NEIGHBOR_OFFSETS = (
    (1, 1, 0),
    (1, -1, 0),
    (-1, 1, 0),
    (-1, -1, 0),
    (1, 0, 1),
    (1, 0, -1),
    (-1, 0, 1),
    (-1, 0, -1),
    (0, 1, 1),
    (0, 1, -1),
    (0, -1, 1),
    (0, -1, -1),
)

Coordinates = tuple[int, int, int]


class ConfigurationError(ValueError):
    """Configuration locale invalide."""


class ApiContractError(RuntimeError):
    """Réponse incompatible avec le contrat API canonique."""


class ApiRequestError(RuntimeError):
    """Erreur HTTP structurée renvoyée par l'API."""

    def __init__(
        self,
        status: int,
        code: str,
        message: str,
        *,
        details: dict[str, Any] | None = None,
        retry_after_seconds: float | None = None,
    ) -> None:
        super().__init__(f"HTTP {status} {code}: {message}")
        self.status = status
        self.code = code
        self.message = message
        self.details = details or {}
        self.retry_after_seconds = retry_after_seconds


class OthersApi(Protocol):
    def get_ship(self, ship_id: str) -> dict[str, Any]: ...

    def get_fleet(self, fleet_id: str) -> dict[str, Any]: ...

    def scan_sector(self, ship_id: str, coordinates: Coordinates) -> dict[str, Any]: ...

    def get_autonomous_units(self, ship_id: str) -> list[dict[str, Any]]: ...

    def get_inventory(self, ship_id: str) -> dict[str, Any]: ...

    def launch_missile(
        self,
        ship_id: str,
        missile_item_id: str,
        target_id: str,
        event_key: str,
    ) -> dict[str, Any]: ...

    def start_laser(
        self,
        ship_id: str,
        target_id: str,
        event_key: str,
    ) -> dict[str, Any]: ...

    def move_ship(
        self,
        ship: dict[str, Any],
        target: Coordinates,
    ) -> dict[str, Any]: ...


class HttpOthersApi:
    def __init__(self, base_url: str, api_token: str, timeout_seconds: float) -> None:
        self.base_url = base_url.rstrip("/")
        self.api_token = api_token
        self.timeout_seconds = timeout_seconds

    def get_ship(self, ship_id: str) -> dict[str, Any]:
        body = self._request("GET", f"/api/others/ships/{quote(ship_id, safe='')}")
        return require_mapping(body.get("ship"), "ship")

    def get_fleet(self, fleet_id: str) -> dict[str, Any]:
        body = self._request("GET", f"/api/others/fleets/{quote(fleet_id, safe='')}")
        return require_mapping(body.get("fleet"), "fleet")

    def scan_sector(self, ship_id: str, coordinates: Coordinates) -> dict[str, Any]:
        query = urlencode(
            {
                "shipId": ship_id,
                "x": coordinates[0],
                "y": coordinates[1],
                "z": coordinates[2],
            }
        )
        body = self._request("GET", f"/api/others/sector?{query}")
        return require_mapping(body.get("sector"), "sector")

    def get_autonomous_units(self, ship_id: str) -> list[dict[str, Any]]:
        units: list[dict[str, Any]] = []
        cursor: str | None = None
        while True:
            query = {"limit": 500}
            if cursor is not None:
                query["cursor"] = cursor
            body = self._request(
                "GET",
                f"/api/others/ships/{quote(ship_id, safe='')}/sector/autonomous-units?{urlencode(query)}",
            )
            page = body.get("autonomousUnits")
            if not isinstance(page, list):
                raise ApiContractError("autonomousUnits doit être une liste.")
            units.extend(require_mapping(unit, "autonomousUnits[]") for unit in page)
            next_cursor = body.get("nextCursor")
            if next_cursor is None:
                return units
            cursor = require_string(next_cursor, "nextCursor")

    def get_inventory(self, ship_id: str) -> dict[str, Any]:
        body = self._request(
            "GET",
            f"/api/others/ships/{quote(ship_id, safe='')}/inventory",
        )
        return require_mapping(body.get("inventory"), "inventory")

    def launch_missile(
        self,
        ship_id: str,
        missile_item_id: str,
        target_id: str,
        event_key: str,
    ) -> dict[str, Any]:
        body = self._request(
            "POST",
            f"/api/others/ships/{quote(ship_id, safe='')}/missiles",
            payload={"missileItemId": missile_item_id, "targetId": target_id},
            idempotency_key=command_idempotency_key(
                "defense-missile", ship_id, missile_item_id, target_id, event_key
            ),
        )
        return require_mapping(body.get("action"), "action")

    def start_laser(
        self,
        ship_id: str,
        target_id: str,
        event_key: str,
    ) -> dict[str, Any]:
        body = self._request(
            "POST",
            f"/api/others/ships/{quote(ship_id, safe='')}/weapons/laser",
            payload={"targetId": target_id},
            idempotency_key=command_idempotency_key(
                "defense-laser", ship_id, target_id, event_key
            ),
        )
        return require_mapping(body.get("action"), "action")

    def move_ship(
        self,
        ship: dict[str, Any],
        target: Coordinates,
    ) -> dict[str, Any]:
        ship_id = require_string(ship.get("id"), "ship.id")
        updated_at = require_string(ship.get("updatedAt"), f"ship {ship_id}.updatedAt")
        fingerprint = hashlib.sha256(
            f"{ship_id}|{updated_at}|{target[0]}:{target[1]}:{target[2]}".encode()
        ).hexdigest()
        body = self._request(
            "POST",
            f"/api/others/ships/{quote(ship_id, safe='')}/move",
            payload={
                "target": {"x": target[0], "y": target[1], "z": target[2]},
                "leaveAuxiliariesBehind": False,
            },
            idempotency_key=f"defense-etoile-{fingerprint}",
        )
        return require_mapping(body.get("action"), "action")

    def _request(
        self,
        method: str,
        path: str,
        *,
        payload: dict[str, Any] | None = None,
        idempotency_key: str | None = None,
    ) -> dict[str, Any]:
        headers = {
            "Accept": "application/json",
            "Authorization": f"Bearer {self.api_token}",
            "User-Agent": "von-neumann-others-control/defense-etoile-attente",
        }
        data = None
        if payload is not None:
            headers["Content-Type"] = "application/json"
            data = json.dumps(payload, separators=(",", ":")).encode()
        if idempotency_key is not None:
            headers["Idempotency-Key"] = idempotency_key

        request = Request(
            f"{self.base_url}{path}",
            data=data,
            headers=headers,
            method=method,
        )
        try:
            with urlopen(request, timeout=self.timeout_seconds) as response:
                raw_body = response.read()
        except HTTPError as error:
            raw_body = error.read()
            parsed = parse_json_object(raw_body, allow_empty=True)
            api_error = parsed.get("error") if isinstance(parsed, dict) else None
            error_data = api_error if isinstance(api_error, dict) else {}
            retry_after = parse_retry_after(error.headers.get("Retry-After"))
            details = error_data.get("details")
            if retry_after is None and isinstance(details, dict):
                value = details.get("retryAfterSeconds")
                if isinstance(value, (int, float)) and not isinstance(value, bool):
                    retry_after = max(0.0, float(value))
            raise ApiRequestError(
                error.code,
                str(error_data.get("code", "http_error")),
                str(error_data.get("message", error.reason)),
                details=details if isinstance(details, dict) else None,
                retry_after_seconds=retry_after,
            ) from error
        except (URLError, TimeoutError) as error:
            reason = getattr(error, "reason", error)
            raise ConnectionError(f"Connexion à l'API impossible : {reason}") from error

        return parse_json_object(raw_body)


@dataclass
class CycleResult:
    accepted_commands: int = 0
    event_dates: list[datetime] = field(default_factory=list)

    def add_event_date(self, value: Any, context: str) -> None:
        if value is None:
            return
        self.event_dates.append(parse_api_datetime(value, context))

    def sleep_seconds(self, idle_refresh_seconds: float) -> float:
        now = datetime.now(timezone.utc)
        future_delays = [
            max(1.0, (event_date - now).total_seconds() + 1.0)
            for event_date in self.event_dates
            if event_date > now
        ]
        if not future_delays:
            return idle_refresh_seconds
        return min(idle_refresh_seconds, min(future_delays))


@dataclass(frozen=True)
class EngagementEvent:
    kind: str
    key: str
    primary_target_id: str | None = None
    probe_target_id: str | None = None


@dataclass
class ScoutObservation:
    coordinates: Coordinates
    autonomous_units: dict[str, tuple[str, str]]
    ejected_mannies: dict[str, str]
    missiles: dict[str, str]
    trajectories: dict[str, str]
    floating_objects: dict[str, str]
    waypoints: dict[str, str]
    probe_ids: tuple[str, ...]


@dataclass
class ScoutState:
    observation: ScoutObservation | None = None
    pending_events: list[EngagementEvent] = field(default_factory=list)
    return_required: bool = False
    laser_return_due: datetime | None = None


class DefenseEtoileAttente:
    def __init__(
        self,
        api: OthersApi,
        *,
        mothership_id: str | None = None,
        fleet_id: str | None = None,
        logger: Callable[[str], None] = print,
        now: Callable[[], datetime] | None = None,
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
        self.known_black_holes: set[Coordinates] = set()
        self.known_safe_sectors: set[Coordinates] = set()
        self.uncertain_sectors: set[Coordinates] = set()
        self.scout_states: dict[str, ScoutState] = {}

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
                    "Le vaisseau mère est en mouvement : formation suspendue jusqu'à son arrivée."
                )
                return result
            fleet_id = require_string(mothership.get("fleetId"), "ship.fleetId")
        else:
            fleet_id = require_string(self.fleet_id, "fleet_id")

        fleet = self.api.get_fleet(fleet_id)
        if require_string(fleet.get("id"), "fleet.id") != fleet_id:
            raise ApiContractError("La flotte retournée ne correspond pas à l'identifiant demandé.")
        ships_value = fleet.get("ships")
        if not isinstance(ships_value, list):
            raise ApiContractError("fleet.ships doit être une liste.")
        ships = [require_mapping(ship, "fleet.ships[]") for ship in ships_value]
        if self.mothership_id is not None:
            fleet_mothership = next(
                (ship for ship in ships if ship.get("id") == self.mothership_id),
                None,
            )
            if fleet_mothership is None:
                raise ApiContractError("Le vaisseau mère demandé est absent de sa flotte.")
        else:
            motherships = [ship for ship in ships if ship.get("type") == "mothership"]
            if not motherships:
                raise ConfigurationError(
                    f"La flotte {fleet_id} ne contient aucun vaisseau mère actif."
                )
            if len(motherships) > 1:
                raise ApiContractError("fleet.ships contient plusieurs vaisseaux mères.")
            fleet_mothership = motherships[0]

        mothership_id = require_string(fleet_mothership.get("id"), "mothership.id")
        self._validate_mothership(fleet_mothership, mothership_id)
        fleet_movement = optional_mapping(fleet_mothership.get("movement"), "mothership.movement")
        if fleet_movement is not None:
            result.add_event_date(fleet_movement.get("arrivalAt"), "mothership.movement.arrivalAt")
            self.log("Le vaisseau mère vient d'engager un mouvement : cycle reporté.")
            return result

        center = ship_sector(fleet_mothership)
        if center is None:
            raise ApiContractError("Le vaisseau mère n'a pas de secteur courant exploitable.")
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
            if ship_id == mothership_id:
                continue
            if ship.get("type") == "mothership":
                continue
            location = require_mapping(ship.get("location"), f"ship {ship_id}.location")
            if location.get("state") in {"removed", "destroyed"}:
                continue

            movement = optional_mapping(ship.get("movement"), f"ship {ship_id}.movement")
            if movement is not None:
                target = parse_coordinates(movement.get("target"), f"ship {ship_id}.movement.target")
                if target in neighbor_set:
                    inbound_neighbors.add(target)
                result.add_event_date(movement.get("arrivalAt"), f"ship {ship_id}.movement.arrivalAt")
                continue

            coordinates = ship_sector(ship)
            if coordinates is None:
                continue
            if coordinates == center:
                self._clear_completed_return(ship_id)
                if self._is_movable(ship):
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
                key=lambda ship: (self._is_movable(ship), str(ship.get("id", ""))),
            )
            guards[coordinates] = ordered[0]
            for surplus_ship in ordered[1:]:
                recall_candidates.append((surplus_ship, coordinates))

        for coordinates, guard in guards.items():
            self._reconcile_scout_engagement(guard, coordinates, center, result)

        known_black_holes = {
            coordinates
            for coordinates in neighbors
            if self._sector_certainly_has_black_hole(
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

        recall_candidates.sort(key=lambda item: str(item[0].get("id", "")))
        for ship, origin in recall_candidates:
            ship_id = require_string(ship.get("id"), "recall ship.id")
            if not self._is_movable(ship):
                self.log(f"Rappel différé pour {ship_id} : vaisseau occupé ({ship.get('status')}).")
                continue
            target = movement_hop(origin, center)
            if target != center:
                self.log(
                    f"Rappel par étape de {ship_id} vers {format_coordinates(target)} "
                    f"(destination finale {format_coordinates(center)})."
                )
            else:
                self.log(f"Rappel de {ship_id} vers le secteur du vaisseau mère.")
            if self._move(ship, target, result):
                continue

        home_candidates.sort(key=lambda ship: str(ship.get("id", "")))
        for ship, target in zip(home_candidates, missing_neighbors):
            ship_id = require_string(ship.get("id"), "deployment ship.id")
            self.log(f"Déploiement de {ship_id} vers {format_coordinates(target)}.")
            self._move(ship, target, result)

        self.log(
            "Cycle terminé : "
            f"{len(occupied_neighbors)} voisin(s) occupé(s), "
            f"{len(inbound_neighbors)} réservé(s) par un mouvement, "
            f"{len(known_black_holes)} trou(s) noir(s) certain(s), "
            f"{result.accepted_commands} commande(s) acceptée(s)."
        )
        return result

    def _validate_mothership(self, ship: dict[str, Any], expected_id: str) -> None:
        ship_id = require_string(ship.get("id"), "ship.id")
        if ship_id != expected_id:
            raise ApiContractError("Le vaisseau retourné ne correspond pas à l'identifiant demandé.")
        if ship.get("type") != "mothership":
            raise ConfigurationError(f"{expected_id} n'est pas un vaisseau mère.")

    def _clear_completed_return(self, ship_id: str) -> None:
        state = self.scout_states.get(ship_id)
        if state is None:
            return
        state.observation = None
        state.pending_events.clear()
        state.return_required = False
        state.laser_return_due = None

    def _reconcile_scout_engagement(
        self,
        ship: dict[str, Any],
        coordinates: Coordinates,
        center: Coordinates,
        result: CycleResult,
    ) -> None:
        ship_id = require_string(ship.get("id"), "guard ship.id")
        state = self.scout_states.setdefault(ship_id, ScoutState())
        if state.observation is not None and state.observation.coordinates != coordinates:
            state.observation = None
            state.pending_events.clear()
            state.return_required = False
            state.laser_return_due = None

        if state.return_required:
            if state.laser_return_due is not None and self.now() < state.laser_return_due:
                result.event_dates.append(state.laser_return_due)
                return
            if not self._is_movable(ship):
                self.log(f"Retour tactique différé pour {ship_id} : vaisseau occupé.")
                return
            if self._move(ship, center, result):
                self.log(f"Retour tactique de {ship_id} vers le vaisseau mère engagé.")
                state.observation = None
                state.pending_events.clear()
                state.return_required = False
                state.laser_return_due = None
            return

        observation = self._observe_scout(ship_id, coordinates)
        new_events = self._detect_engagement_events(state.observation, observation)
        state.observation = observation
        pending_keys = {event.key for event in state.pending_events}
        state.pending_events.extend(event for event in new_events if event.key not in pending_keys)
        state.pending_events.sort(key=lambda event: (EVENT_PRIORITY[event.kind], event.key))

        while state.pending_events and not state.return_required:
            event = state.pending_events.pop(0)
            self._execute_engagement_event(ship, event, state, result)

        if not state.return_required:
            return
        if state.laser_return_due is not None:
            result.event_dates.append(state.laser_return_due)
            return
        if not self._is_movable(ship):
            self.log(f"Retour tactique différé pour {ship_id} : vaisseau occupé.")
            return
        if self._move(ship, center, result):
            self.log(f"Retour tactique de {ship_id} vers le vaisseau mère engagé.")
            state.observation = None
            state.pending_events.clear()
            state.return_required = False

    def _observe_scout(
        self,
        ship_id: str,
        coordinates: Coordinates,
    ) -> ScoutObservation:
        autonomous_units: dict[str, tuple[str, str]] = {}
        for unit in self.api.get_autonomous_units(ship_id):
            if unit.get("kind") != "manny":
                continue
            unit_id = identifier_string(unit.get("id"), "autonomousUnits[].id")
            carrier = require_mapping(unit.get("carrier"), f"autonomous unit {unit_id}.carrier")
            if carrier.get("kind") != "probe":
                raise ApiContractError(f"La Manny {unit_id} doit avoir une sonde porteuse.")
            carrier_id = identifier_string(
                carrier.get("id"), f"autonomous unit {unit_id}.carrier.id"
            )
            spatial_state = require_string(
                unit.get("spatialState"), f"autonomous unit {unit_id}.spatialState"
            )
            autonomous_units[unit_id] = (carrier_id, spatial_state)

        sector = self.api.scan_sector(ship_id, coordinates)
        probes_value = sector.get("probes", [])
        if not isinstance(probes_value, list):
            raise ApiContractError("sector.probes doit être une liste lorsqu'il est présent.")
        probe_ids = tuple(
            sorted(
                {
                    identifier_string(probe.get("id"), "sector.probes[].id")
                    for probe in probes_value
                    if isinstance(probe, dict) and probe.get("id") is not None
                }
            )
        )

        ejected_mannies: dict[str, str] = {}
        missiles: dict[str, str] = {}
        trajectories: dict[str, str] = {}
        floating_objects: dict[str, str] = {}
        waypoints: dict[str, str] = {}
        objects_value = sector.get("objects", [])
        if not isinstance(objects_value, list):
            raise ApiContractError("sector.objects doit être une liste lorsqu'il est présent.")
        for context, sector_object in observed_sector_objects(objects_value):
            object_type = sector_object.get("type")
            object_id = identifier_string(
                sector_object.get("id"), f"{context}.id"
            )
            if object_type == "manny":
                manny_uid = identifier_string(
                    sector_object.get("mannyUid"), f"sector object {object_id}.mannyUid"
                )
                ejected_mannies[manny_uid] = stable_signature(sector_object)
            if object_type == "missile" and sector_object.get("launcherKind") == "probe":
                missiles[object_id] = stable_signature(
                    {
                        "targetKind": sector_object.get("targetKind"),
                        "targetId": sector_object.get("targetId"),
                        "launchedAt": sector_object.get("launchedAt"),
                        "impactAt": sector_object.get("impactAt"),
                    }
                )
            trajectory = sector_object.get("trajectory")
            if object_type == "asteroid" and isinstance(trajectory, dict):
                trajectories[object_id] = stable_signature(
                    {
                        "id": trajectory.get("id"),
                        "mode": trajectory.get("mode"),
                        "targetObjectId": trajectory.get("targetObjectId"),
                        "targetSpeedC": trajectory.get("targetSpeedC"),
                        "plannedRevolutions": trajectory.get("plannedRevolutions"),
                        "direction": trajectory.get("direction"),
                        "maximumSectorCrossings": trajectory.get("maximumSectorCrossings"),
                    }
                )
            if object_type in FLOATING_OBJECT_TYPES:
                floating_objects[object_id] = stable_signature(sector_object)
            if "waypointBookmarks" in sector_object:
                waypoints[object_id] = stable_signature(sector_object["waypointBookmarks"])

        return ScoutObservation(
            coordinates=coordinates,
            autonomous_units=autonomous_units,
            ejected_mannies=ejected_mannies,
            missiles=missiles,
            trajectories=trajectories,
            floating_objects=floating_objects,
            waypoints=waypoints,
            probe_ids=probe_ids,
        )

    def _detect_engagement_events(
        self,
        previous: ScoutObservation | None,
        current: ScoutObservation,
    ) -> list[EngagementEvent]:
        events: list[EngagementEvent] = []
        default_probe = current.probe_ids[0] if current.probe_ids else None

        previous_units = previous.autonomous_units if previous is not None else {}
        for manny_id, (carrier_id, spatial_state) in current.autonomous_units.items():
            if previous is not None and previous_units.get(manny_id) == (carrier_id, spatial_state):
                continue
            events.append(
                EngagementEvent(
                    "deployed_manny",
                    event_key("deployed_manny", manny_id, carrier_id, spatial_state),
                    primary_target_id=manny_id,
                    probe_target_id=carrier_id,
                )
            )

        previous_missiles = previous.missiles if previous is not None else {}
        for missile_id, signature in current.missiles.items():
            if previous is not None and previous_missiles.get(missile_id) == signature:
                continue
            events.append(
                EngagementEvent(
                    "hostile_missile",
                    event_key("hostile_missile", missile_id, signature),
                    primary_target_id=missile_id,
                    probe_target_id=default_probe,
                )
            )

        previous_trajectories = previous.trajectories if previous is not None else {}
        for asteroid_id, signature in current.trajectories.items():
            if previous is not None and previous_trajectories.get(asteroid_id) == signature:
                continue
            events.append(
                EngagementEvent(
                    "asteroid_trajectory",
                    event_key("asteroid_trajectory", asteroid_id, signature),
                    primary_target_id=asteroid_id,
                    probe_target_id=default_probe,
                )
            )

        previous_ejected = previous.ejected_mannies if previous is not None else {}
        for manny_id, signature in current.ejected_mannies.items():
            if previous is not None and manny_id in previous_ejected:
                continue
            events.append(
                EngagementEvent(
                    "ejected_manny",
                    event_key("ejected_manny", manny_id, signature),
                    primary_target_id=manny_id,
                )
            )

        if previous is not None:
            changed_floating_ids = sorted(
                object_id
                for object_id in set(previous.floating_objects) | set(current.floating_objects)
                if previous.floating_objects.get(object_id)
                != current.floating_objects.get(object_id)
            )
            for object_id in changed_floating_ids:
                events.append(
                    EngagementEvent(
                        "floating_object_change",
                        event_key(
                            "floating_object_change",
                            object_id,
                            current.floating_objects.get(object_id, "removed"),
                        ),
                        probe_target_id=default_probe,
                    )
                )

            changed_waypoint_ids = sorted(
                object_id
                for object_id in set(previous.waypoints) | set(current.waypoints)
                if previous.waypoints.get(object_id) != current.waypoints.get(object_id)
            )
            for object_id in changed_waypoint_ids:
                events.append(
                    EngagementEvent(
                        "waypoint_change",
                        event_key(
                            "waypoint_change",
                            object_id,
                            current.waypoints.get(object_id, "removed"),
                        ),
                        probe_target_id=default_probe,
                    )
                )
        return events

    def _execute_engagement_event(
        self,
        ship: dict[str, Any],
        event: EngagementEvent,
        state: ScoutState,
        result: CycleResult,
    ) -> None:
        ship_id = require_string(ship.get("id"), "engagement ship.id")
        missiles = self._available_missiles(ship_id)
        if event.kind == "deployed_manny":
            has_missiles = bool(missiles)
            self._fire_at_targets(ship_id, missiles, event, result)
            if not has_missiles and deuterium_amount(ship) > LASER_DEUTERIUM_THRESHOLD:
                if event.primary_target_id is not None and self._start_laser(
                    ship_id, event.primary_target_id, event.key, result
                ):
                    state.laser_return_due = self.now() + timedelta(
                        seconds=LASER_ENGAGEMENT_SECONDS + 1
                    )
                    self.log(
                        f"Laser de {ship_id} verrouillé sur la Manny {event.primary_target_id} "
                        "pour dix minutes."
                    )
            state.return_required = True
            return

        if event.kind == "hostile_missile":
            self._fire_at_targets(ship_id, missiles, event, result)
            state.return_required = True
            return

        if event.kind == "ejected_manny":
            self._fire_at_targets(ship_id, missiles, event, result, include_probe=False)
            return

        if event.kind == "asteroid_trajectory":
            self._fire_at_targets(ship_id, missiles, event, result)
            return

        if event.kind in {"floating_object_change", "waypoint_change"}:
            self._fire_at_targets(ship_id, missiles, event, result, include_primary=False)
            state.return_required = True
            return
        raise RuntimeError(f"Type d'engagement inconnu : {event.kind}.")

    def _available_missiles(self, ship_id: str) -> list[str]:
        inventory = self.api.get_inventory(ship_id)
        items = inventory.get("items")
        if not isinstance(items, list):
            raise ApiContractError("inventory.items doit être une liste.")
        missiles = []
        for index, value in enumerate(items):
            item = require_mapping(value, f"inventory.items[{index}]")
            if item.get("type") == "missile":
                missiles.append(identifier_string(item.get("id"), f"inventory.items[{index}].id"))
        return sorted(missiles)

    def _fire_at_targets(
        self,
        ship_id: str,
        missiles: list[str],
        event: EngagementEvent,
        result: CycleResult,
        *,
        include_primary: bool = True,
        include_probe: bool = True,
    ) -> int:
        targets = []
        if include_primary and event.primary_target_id is not None:
            targets.append(event.primary_target_id)
        if include_probe and event.probe_target_id is not None:
            targets.append(event.probe_target_id)
        fired = 0
        for target_id in targets:
            if not missiles:
                break
            missile_id = missiles[0]
            if not self._launch_missile(
                ship_id, missile_id, target_id, event.key, result
            ):
                continue
            missiles.pop(0)
            fired += 1
        return fired

    def _launch_missile(
        self,
        ship_id: str,
        missile_id: str,
        target_id: str,
        event_key_value: str,
        result: CycleResult,
    ) -> bool:
        try:
            action = self.api.launch_missile(
                ship_id, missile_id, target_id, event_key_value
            )
        except ApiRequestError as error:
            if error.status in {404, 409, 422}:
                self.log(
                    f"Tir de {ship_id} ignoré vers {target_id} : "
                    f"{error.code} ({error.message})."
                )
                return False
            raise
        result.accepted_commands += 1
        result.add_event_date(action.get("endsAt"), f"missile action for {ship_id}.endsAt")
        self.log(f"Missile {missile_id} de {ship_id} lancé vers {target_id}.")
        return True

    def _start_laser(
        self,
        ship_id: str,
        target_id: str,
        event_key_value: str,
        result: CycleResult,
    ) -> bool:
        try:
            action = self.api.start_laser(ship_id, target_id, event_key_value)
        except ApiRequestError as error:
            if error.status in {404, 409, 422}:
                self.log(
                    f"Laser de {ship_id} ignoré vers {target_id} : "
                    f"{error.code} ({error.message})."
                )
                return False
            raise
        result.accepted_commands += 1
        result.add_event_date(action.get("endsAt"), f"laser action for {ship_id}.endsAt")
        return True

    def _sector_certainly_has_black_hole(
        self,
        coordinates: Coordinates,
        *,
        mothership_id: str,
        has_stationed_ship: bool,
    ) -> bool:
        if coordinates in self.known_black_holes:
            return True
        if coordinates in self.known_safe_sectors:
            return False
        if coordinates in self.uncertain_sectors and not has_stationed_ship:
            return False

        try:
            sector = self.api.scan_sector(mothership_id, coordinates)
        except ApiRequestError as error:
            if error.code == "insufficient_scan_data":
                self.uncertain_sectors.add(coordinates)
                self.log(
                    f"Scan incertain pour {format_coordinates(coordinates)} : "
                    "le secteur reste admissible."
                )
                return False
            raise

        knowledge_level = sector.get("knowledgeLevel")
        if knowledge_level != "detailed":
            self.uncertain_sectors.add(coordinates)
            self.log(
                f"Scan {knowledge_level!s} pour {format_coordinates(coordinates)} : "
                "aucun trou noir n'est considéré comme certain."
            )
            return False

        objects = sector.get("objects")
        if not isinstance(objects, list):
            raise ApiContractError("Un scan détaillé doit exposer sector.objects.")
        has_black_hole = any(
            isinstance(sector_object, dict) and sector_object.get("type") == "black_hole"
            for sector_object in objects
        )
        self.uncertain_sectors.discard(coordinates)
        if has_black_hole:
            self.known_black_holes.add(coordinates)
            self.log(f"Trou noir confirmé dans {format_coordinates(coordinates)} : secteur exclu.")
        else:
            self.known_safe_sectors.add(coordinates)
        return has_black_hole

    def _move(
        self,
        ship: dict[str, Any],
        target: Coordinates,
        result: CycleResult,
    ) -> bool:
        ship_id = require_string(ship.get("id"), "move ship.id")
        try:
            action = self.api.move_ship(ship, target)
        except ApiRequestError as error:
            if error.status in {409, 422}:
                self.log(f"Commande ignorée pour {ship_id} : {error.code} ({error.message}).")
                return False
            raise
        result.accepted_commands += 1
        result.add_event_date(action.get("endsAt"), f"move action for {ship_id}.endsAt")
        return True

    @staticmethod
    def _is_movable(ship: dict[str, Any]) -> bool:
        return ship.get("movement") is None and ship.get("status") in {"inactive", "available"}


def identifier_string(value: Any, context: str) -> str:
    if isinstance(value, bool) or not isinstance(value, (str, int)):
        raise ApiContractError(f"{context} doit être un identifiant chaîne ou entier.")
    result = str(value)
    if not result:
        raise ApiContractError(f"{context} doit être un identifiant non vide.")
    return result


def stable_signature(value: Any) -> str:
    return hashlib.sha256(
        json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()


def event_key(kind: str, *parts: str) -> str:
    return command_idempotency_key(kind, *parts)


def command_idempotency_key(prefix: str, *parts: str) -> str:
    digest = hashlib.sha256("\0".join(parts).encode()).hexdigest()
    return f"{prefix}-{digest}"


def deuterium_amount(ship: dict[str, Any]) -> float:
    ship_id = ship.get("id", "?")
    deuterium = require_mapping(ship.get("deuterium"), f"ship {ship_id}.deuterium")
    amount = deuterium.get("amount")
    if isinstance(amount, bool) or not isinstance(amount, (int, float)):
        raise ApiContractError(f"ship {ship_id}.deuterium.amount doit être numérique.")
    return float(amount)


def observed_sector_objects(
    objects: list[Any],
) -> list[tuple[str, dict[str, Any]]]:
    observed: list[tuple[str, dict[str, Any]]] = []
    for index, value in enumerate(objects):
        context = f"sector.objects[{index}]"
        sector_object = require_mapping(value, context)
        observed.append((context, sector_object))
        for collection_name in ("minableTargets", "bookmarkTargets"):
            if collection_name not in sector_object:
                continue
            targets = sector_object[collection_name]
            if not isinstance(targets, list):
                raise ApiContractError(f"{context}.{collection_name} doit être une liste.")
            for target_index, target in enumerate(targets):
                target_context = f"{context}.{collection_name}[{target_index}]"
                observed.append((target_context, require_mapping(target, target_context)))
    return observed


def load_config(path: Path) -> tuple[str, str]:
    try:
        config: Any = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as error:
        raise ConfigurationError(
            f"Configuration absente : copiez config.example.json vers {path.name}."
        ) from error
    except (OSError, json.JSONDecodeError) as error:
        raise ConfigurationError(f"Impossible de lire {path}: {error}") from error

    if not isinstance(config, dict):
        raise ConfigurationError(f"{path} doit contenir un objet JSON.")
    base_url = config.get("base_url")
    api_token = config.get("api_token")
    if not isinstance(base_url, str) or not base_url.strip():
        raise ConfigurationError("La propriété base_url doit être une chaîne non vide.")
    if not isinstance(api_token, str) or not api_token.strip():
        raise ConfigurationError("La propriété api_token doit être une chaîne non vide.")
    base_url = base_url.strip().rstrip("/")
    parsed_url = urlsplit(base_url)
    if parsed_url.scheme not in {"http", "https"} or not parsed_url.netloc:
        raise ConfigurationError("La propriété base_url doit être une URL HTTP ou HTTPS valide.")
    return base_url, api_token.strip()


def parse_coordinates(value: Any, context: str) -> Coordinates:
    if not isinstance(value, dict):
        raise ApiContractError(f"{context} doit être un objet de coordonnées.")
    coordinates = []
    for axis in ("x", "y", "z"):
        component = value.get(axis)
        if not isinstance(component, int) or isinstance(component, bool):
            raise ApiContractError(f"{context}.{axis} doit être un entier.")
        coordinates.append(component)
    result = (coordinates[0], coordinates[1], coordinates[2])
    if sum(result) % 2 != 0:
        raise ApiContractError(f"{context} ne respecte pas la parité FCC.")
    return result


def ship_sector(ship: dict[str, Any]) -> Coordinates | None:
    sector = ship.get("sector")
    if sector is None:
        return None
    sector_data = require_mapping(sector, "ship.sector")
    return parse_coordinates(sector_data.get("relative"), "ship.sector.relative")


def add_coordinates(a: Coordinates, b: Coordinates) -> Coordinates:
    return (a[0] + b[0], a[1] + b[1], a[2] + b[2])


def coordinate_distance(a: Coordinates, b: Coordinates) -> int:
    return max(abs(a[index] - b[index]) for index in range(3))


def movement_hop(origin: Coordinates, destination: Coordinates) -> Coordinates:
    if coordinate_distance(origin, destination) <= MAX_MOVEMENT_DISTANCE:
        return destination
    delta = tuple(destination[index] - origin[index] for index in range(3))
    step = [max(-MAX_MOVEMENT_DISTANCE, min(MAX_MOVEMENT_DISTANCE, value)) for value in delta]
    if sum(step) % 2 != 0:
        adjustable = max(
            (index for index, component in enumerate(step) if component != 0),
            key=lambda index: abs(step[index]),
        )
        step[adjustable] -= 1 if step[adjustable] > 0 else -1
    target = (origin[0] + step[0], origin[1] + step[1], origin[2] + step[2])
    if coordinate_distance(target, destination) == 1:
        remaining = tuple(destination[index] - target[index] for index in range(3))
        for index, component in enumerate(remaining):
            if component != 0:
                step[index] -= 1 if component > 0 else -1
        target = (origin[0] + step[0], origin[1] + step[1], origin[2] + step[2])
    if sum(target) % 2 != 0 or coordinate_distance(origin, target) > MAX_MOVEMENT_DISTANCE:
        raise RuntimeError("Impossible de calculer une étape de rappel FCC canonique.")
    if coordinate_distance(target, destination) >= coordinate_distance(origin, destination):
        raise RuntimeError("L'étape de rappel calculée ne rapproche pas le vaisseau de sa destination.")
    return target


def require_mapping(value: Any, context: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ApiContractError(f"{context} doit être un objet JSON.")
    return value


def optional_mapping(value: Any, context: str) -> dict[str, Any] | None:
    if value is None:
        return None
    return require_mapping(value, context)


def require_string(value: Any, context: str) -> str:
    if not isinstance(value, str) or not value:
        raise ApiContractError(f"{context} doit être une chaîne non vide.")
    return value


def parse_api_datetime(value: Any, context: str) -> datetime:
    text = require_string(value, context)
    try:
        parsed = datetime.fromisoformat(text.replace("Z", "+00:00"))
    except ValueError as error:
        raise ApiContractError(f"{context} doit être une date ISO 8601.") from error
    if parsed.tzinfo is None:
        raise ApiContractError(f"{context} doit comporter un fuseau horaire.")
    return parsed.astimezone(timezone.utc)


def parse_json_object(raw_body: bytes, *, allow_empty: bool = False) -> dict[str, Any]:
    if allow_empty and not raw_body:
        return {}
    try:
        decoded = json.loads(raw_body.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        if allow_empty:
            return {}
        raise ApiContractError("L'API n'a pas renvoyé un objet JSON valide.") from error
    if not isinstance(decoded, dict):
        raise ApiContractError("La réponse racine de l'API doit être un objet JSON.")
    return decoded


def parse_retry_after(value: str | None) -> float | None:
    if value is None:
        return None
    try:
        return max(0.0, float(value))
    except ValueError:
        return None


def format_coordinates(coordinates: Coordinates) -> str:
    return f"({coordinates[0]}, {coordinates[1]}, {coordinates[2]})"


def timestamped_logger(message: str) -> None:
    now = datetime.now().astimezone().isoformat(timespec="seconds")
    print(f"[{now}] {message}", flush=True)


def build_argument_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Maintient douze sentinelles autour d'un vaisseau mère Others.",
    )
    identifier = parser.add_mutually_exclusive_group(required=True)
    identifier.add_argument(
        "--mothership-id",
        help="Identifiant public du vaisseau mère",
    )
    identifier.add_argument(
        "--fleet-id",
        help="Identifiant public de la flotte",
    )
    parser.add_argument(
        "--config",
        type=Path,
        default=CONFIG_PATH,
        help=f"Fichier de configuration JSON (défaut : {CONFIG_PATH})",
    )
    parser.add_argument(
        "--once",
        action="store_true",
        help="Exécute un seul cycle de réconciliation puis quitte",
    )
    parser.add_argument(
        "--idle-refresh-seconds",
        type=float,
        default=IDLE_REFRESH_SECONDS,
        help="Intervalle maximal entre deux contrôles (défaut : 300)",
    )
    parser.add_argument(
        "--timeout-seconds",
        type=float,
        default=TIMEOUT_SECONDS,
        help="Délai maximal d'une requête HTTP (défaut : 10)",
    )
    return parser


def main(argv: list[str] | None = None) -> int:
    arguments = build_argument_parser().parse_args(argv)
    if arguments.idle_refresh_seconds < 30:
        print("Erreur : --idle-refresh-seconds doit être supérieur ou égal à 30.", file=sys.stderr)
        return 2
    if arguments.timeout_seconds <= 0:
        print("Erreur : --timeout-seconds doit être strictement positif.", file=sys.stderr)
        return 2

    try:
        base_url, api_token = load_config(arguments.config)
        controller = DefenseEtoileAttente(
            HttpOthersApi(base_url, api_token, arguments.timeout_seconds),
            mothership_id=arguments.mothership_id,
            fleet_id=arguments.fleet_id,
            logger=timestamped_logger,
        )
    except ConfigurationError as error:
        print(f"Erreur de configuration : {error}", file=sys.stderr)
        return 2

    retry_delay = 5.0
    while True:
        try:
            result = controller.run_cycle()
            retry_delay = 5.0
        except ApiRequestError as error:
            if error.status in {401, 403, 404}:
                print(f"Erreur API définitive : {error}", file=sys.stderr)
                return 1
            delay = error.retry_after_seconds or retry_delay
            timestamped_logger(f"Erreur API temporaire : {error}. Nouvel essai dans {delay:.0f} s.")
            if arguments.once:
                return 1
            time.sleep(delay)
            retry_delay = min(arguments.idle_refresh_seconds, retry_delay * 2)
            continue
        except (ApiContractError, ConfigurationError) as error:
            print(f"Erreur : {error}", file=sys.stderr)
            return 2
        except ConnectionError as error:
            timestamped_logger(f"{error}. Nouvel essai dans {retry_delay:.0f} s.")
            if arguments.once:
                return 1
            time.sleep(retry_delay)
            retry_delay = min(arguments.idle_refresh_seconds, retry_delay * 2)
            continue

        if arguments.once:
            return 0
        delay = result.sleep_seconds(arguments.idle_refresh_seconds)
        timestamped_logger(f"Prochain contrôle dans {delay:.0f} s.")
        try:
            time.sleep(delay)
        except KeyboardInterrupt:
            timestamped_logger("Arrêt demandé.")
            return 0


if __name__ == "__main__":
    raise SystemExit(main())
