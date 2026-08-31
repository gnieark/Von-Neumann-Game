#!/usr/bin/env python3
"""Maintient une flotte Others en formation « défense étoile - attente »."""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable, Protocol
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode, urlsplit
from urllib.request import Request, urlopen


CONFIG_PATH = Path(__file__).with_name("config.json")
TIMEOUT_SECONDS = 10.0
IDLE_REFRESH_SECONDS = 300.0
MAX_MOVEMENT_DISTANCE = 10
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


class DefenseEtoileAttente:
    def __init__(
        self,
        api: OthersApi,
        *,
        mothership_id: str | None = None,
        fleet_id: str | None = None,
        logger: Callable[[str], None] = print,
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
        self.known_black_holes: set[Coordinates] = set()
        self.known_safe_sectors: set[Coordinates] = set()
        self.uncertain_sectors: set[Coordinates] = set()

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
                if self._is_movable(ship):
                    home_candidates.append(ship)
                continue
            if coordinates in neighbor_set:
                residents[coordinates].append(ship)
                continue
            recall_candidates.append((ship, coordinates))

        for coordinates, ships_in_sector in residents.items():
            if len(ships_in_sector) <= 1:
                continue
            ordered = sorted(
                ships_in_sector,
                key=lambda ship: (self._is_movable(ship), str(ship.get("id", ""))),
            )
            for surplus_ship in ordered[1:]:
                recall_candidates.append((surplus_ship, coordinates))

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
