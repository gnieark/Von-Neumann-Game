"""Journal de bord spectateur : événements choisis, observations et alertes Others."""

from __future__ import annotations

import json
import logging
import os
import re
import time
from datetime import datetime
from functools import wraps
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Any, Callable

from .contracts import require_mapping, require_string
from .errors import ApiContractError, ApiRequestError
from .geometry import format_coordinates, parse_coordinates
from .ports import OthersApi
from .observation import observed_sector_objects

LOG_DIRECTORY = Path(__file__).resolve().parent.parent / "logs"
TERMINAL = {"succeeded": "terminée", "failed": "échouée", "canceled": "annulée"}
RESOURCE_LABELS = {"metals": "métaux", "ice": "glace", "carbon_compounds": "composés carbonés", "deuterium": "deutérium"}


class SpectatorEvent(str):
    """Une chaîne reste utilisable par les loggers de diagnostic et les tests."""

    def __new__(cls, category: str, message: str, *, state: str | None = None):
        instance = super().__new__(cls, message)
        instance.category = category
        instance.state = state
        return instance


def resolve_condition(logger: Callable[[str], None], category: str, key: str, message: str) -> None:
    if isinstance(logger, SpectatorJournal):
        logger.resolve(category, key, message)


def observed(method):
    """Une panne du journal ne doit pas faire rejouer une commande de jeu acceptée."""
    @wraps(method)
    def wrapped(self, *args, **kwargs):
        try:
            return method(self, *args, **kwargs)
        except (OSError, ValueError, TypeError, KeyError, ApiContractError) as error:
            self.warning(error)
            return None
    return wrapped


class CheckedRotatingFileHandler(RotatingFileHandler):
    def handleError(self, record: logging.LogRecord) -> None:
        # Le handler standard masque les erreurs : l'acquittement exige leur propagation.
        raise


class SpectatorJournal:
    def __init__(self, diagnostic: Callable[[str], None], *, server: str,
                 mothership_id: str | None = None, fleet_id: str | None = None,
                 directory: Path = LOG_DIRECTORY, max_bytes: int = 10 * 1024 * 1024,
                 backups: int = 5, monotonic: Callable[[], float] = time.monotonic) -> None:
        self.diagnostic = diagnostic
        self.server = server
        self.mothership_id = mothership_id
        self.fleet_id = fleet_id
        self.directory = directory
        self.max_bytes = max_bytes
        self.backups = backups
        self.monotonic = monotonic
        self.next_alerts_at = 0.0
        self.handler: CheckedRotatingFileHandler | None = None
        self.state: dict[str, Any] | None = None
        self.states: dict[str, str] = {}
        self._warning_at = float('-inf')
        self._last_saved: str | None = None

    def warning(self, error: Exception) -> None:
        if self.monotonic() >= self._warning_at:
            self.diagnostic(f"Journal spectateur indisponible : {error}. Les alertes non enregistrées restent non lues.")
            self._warning_at = self.monotonic() + 300

    def __call__(self, message: str) -> None:
        self.diagnostic(message)
        if isinstance(message, SpectatorEvent):
            self.event(message)

    @observed
    def event(self, message: SpectatorEvent) -> None:
        if self.state is None:
            return
        if message.state is not None and self.states.get(message.state) == str(message):
            return
        self._line(message.category, str(message))
        if message.state is not None:
            self.states[message.state] = str(message)

    @observed
    def resolve(self, category: str, key: str, message: str) -> None:
        if key in self.states:
            self._line(category, message)
            del self.states[key]

    @property
    def state_path(self) -> Path:
        return self.directory / ".state" / f"{self.mothership_id}.json"

    def _open(self, mother_id: str, fleet_id: str, count: int) -> None:
        if self.state is not None:
            return
        if re.fullmatch(r"[A-Za-z0-9_-]+", mother_id) is None:
            raise ApiContractError("Identifiant de vaisseau mère impropre à un nom de fichier.")
        self.mothership_id = mother_id
        self.fleet_id = fleet_id
        self.state_path.parent.mkdir(parents=True, exist_ok=True)
        if self.state_path.exists():
            state = json.loads(self.state_path.read_text(encoding="utf-8"))
            if (not isinstance(state, dict) or state.get("version") != 1
                    or state.get("server") != self.server or state.get("fleetId") != fleet_id
                    or state.get("mothershipId") != mother_id
                    or any(not isinstance(state.get(key), dict) for key in ("ships", "actions", "crafts"))
                    or not isinstance(state.get("threats"), list)
                    or not isinstance(state.get("writtenAlerts"), list)
                    or any(not isinstance(value, str) for value in state["writtenAlerts"])):
                raise ValueError(f"État spectateur invalide ou lié à une autre flotte : {self.state_path}")
            if any(not isinstance(ship, dict) for ship in state["ships"].values()):
                raise ValueError("Historique des vaisseaux invalide.")
        else:
            state = {"version": 1, "server": self.server, "fleetId": fleet_id, "mothershipId": mother_id,
                     "ships": {}, "actions": {}, "crafts": {}, "writtenAlerts": [], "threats": []}
        self.handler = CheckedRotatingFileHandler(
            self.directory / f"{mother_id}.log", maxBytes=self.max_bytes,
            backupCount=self.backups, encoding="utf-8", delay=True,
        )
        self.handler.setFormatter(logging.Formatter("%(message)s"))
        self._line("SESSION", f"Démarrage du contrôleur — flotte {fleet_id}, vaisseau mère {mother_id}, {count} vaisseau(x).")
        self.state = state
        self._save()

    def _line(self, category: str, message: str) -> None:
        if self.handler is None:
            raise OSError("Fichier spectateur non ouvert.")
        # Une alerte reste une seule ligne, même si son texte contient des retours ou contrôles.
        clean = " ".join(''.join(c if c.isprintable() else ' ' for c in str(message)).split())
        now = datetime.now().astimezone().isoformat(timespec="seconds")
        self.handler.emit(logging.LogRecord("spectator", logging.INFO, "", 0, f"[{now}] [{category}] {clean}", (), None))
        self.handler.flush()
        if self.handler.stream is not None:
            os.fsync(self.handler.stream.fileno())

    def _save(self) -> None:
        serialized = json.dumps(self.state, ensure_ascii=False)
        if serialized == self._last_saved:
            return
        temporary = self.state_path.with_suffix(".tmp")
        with temporary.open("w", encoding="utf-8") as stream:
            stream.write(serialized)
            stream.flush()
            os.fsync(stream.fileno())
        temporary.replace(self.state_path)
        self._last_saved = serialized

    @staticmethod
    def _position(ship: dict[str, Any]) -> str | None:
        sector = ship.get("sector")
        if isinstance(sector, dict) and sector.get("relative") is not None:
            return format_coordinates(parse_coordinates(sector["relative"], "ship.sector.relative"))
        return None

    @observed
    def fleet(self, fleet: dict[str, Any]) -> None:
        fleet_id = require_string(fleet.get("id"), "fleet.id")
        if self.fleet_id is not None and self.fleet_id != fleet_id:
            return
        ships = fleet.get("ships")
        if not isinstance(ships, list):
            raise ApiContractError("fleet.ships doit être une liste.")
        mothers = [ship for ship in ships if isinstance(ship, dict) and ship.get("type") == "mothership"
                   and (self.mothership_id is None or ship.get("id") == self.mothership_id)]
        if len(mothers) != 1:
            return
        initial = self.state is None
        self._open(require_string(mothers[0].get("id"), "mothership.id"), fleet_id, len(ships))
        for ship in ships:
            self._ship(require_mapping(ship, "fleet.ships[]"), initial=initial)
        self._save()

    @observed
    def ship(self, ship: dict[str, Any]) -> None:
        if self.state is None or ship.get("fleetId") != self.fleet_id:
            return
        self._ship(ship)
        self._save()

    def _ship(self, ship: dict[str, Any], *, initial: bool = False) -> None:
        ship_id = require_string(ship.get("id"), "ship.id")
        if ship.get("fleetId") != self.fleet_id:
            return
        previous = self.state["ships"].get(ship_id)
        position = self._position(ship)
        movement = ship.get("movement")
        phase = movement.get("phase") if isinstance(movement, dict) else None
        target = (format_coordinates(parse_coordinates(movement["target"], "movement.target"))
                  if isinstance(movement, dict) else None)
        status = ship.get("status")
        if previous is None and not initial:
            self._line("FLOTTE", f"Nouveau vaisseau observé : {ship_id} ({ship.get('type')}).")
        if previous is not None:
            if status == "destroyed" and previous.get("status") != "destroyed":
                self._line("FLOTTE", f"Destruction confirmée du vaisseau {ship_id}.")
            elif phase == "transit" and (previous.get("phase") != phase or previous.get("target") != target):
                self._line("DÉPLACEMENT", f"Départ constaté de {ship_id} vers le secteur relatif {target}.")
            elif movement is None and position is not None:
                if previous.get("target") == position:
                    self._line("DÉPLACEMENT", f"Arrivée constatée de {ship_id} dans le secteur relatif {position}.")
                elif previous.get("position") is not None and previous["position"] != position:
                    self._line("DÉPLACEMENT", f"Changement de secteur constaté pour {ship_id} : {previous['position']} → {position} (relatif).")
                if previous.get("integrity") is not None and isinstance(ship.get("integrity"), (int, float)) and ship["integrity"] > previous["integrity"]:
                    self._line("RÉPARATION", f"Intégrité de {ship_id} restaurée : {previous['integrity']} → {ship['integrity']} points.")
        self.state["ships"][ship_id] = {"position": position, "phase": phase, "target": target,
                                          "status": status, "integrity": ship.get("integrity")}

    @observed
    def accepted(self, action: dict[str, Any], category: str, message: str,
                 *, movement: tuple[str, tuple[int, int, int]] | None = None) -> None:
        if self.state is None:
            return
        action_id = require_string(action.get("id"), "action.id")
        if action_id in self.state["actions"]:
            return
        if category == "MOISSON":
            self.resolve(category, "harvest", "Reprise de la moisson.")
        deadline = f" Échéance prévue : {action['endsAt']}." if action.get("endsAt") else ""
        self._line(category, f"{message} Action {action_id}.{deadline}")
        self.state["actions"][action_id] = {"category": category, "message": message, "status": action.get("status")}
        if movement is not None and movement[0] in self.state["ships"]:
            self.resolve("RAVITAILLEMENT", f"fuel:{movement[0]}", f"{movement[0]} dispose du carburant nécessaire au déplacement engagé.")
            self.state["ships"][movement[0]]["target"] = format_coordinates(movement[1])
        # Les opérations anciennes ne doivent pas faire croître le suivi indéfiniment.
        self.state["actions"] = dict(list(self.state["actions"].items())[-5000:])
        self._save()

    @observed
    def action(self, action: dict[str, Any]) -> None:
        if self.state is None:
            return
        action_id = action.get("id")
        known = self.state["actions"].get(action_id)
        status = action.get("status")
        if known is None or status not in TERMINAL or known["status"] in TERMINAL:
            return
        self._line(known["category"], f"Action {action_id} {TERMINAL[status]} : {known['message']} {self._result(action)}")
        known["status"] = status
        self._save()

    @staticmethod
    def _result(value: dict[str, Any]) -> str:
        result = value.get("result")
        if not isinstance(result, dict):
            return ""
        parts = []
        resources = result.get("resources", {})
        if isinstance(resources, dict):
            # La moisson distingue extraction et quantités effectivement stockées.
            resources = resources.get("stored", resources)
            for name, label in RESOURCE_LABELS.items():
                amount = resources.get(name)
                if isinstance(amount, (int, float)):
                    parts.append(f"{label} : {amount:g} ECE")
        output = result.get("output")
        if isinstance(output, dict):
            parts.append(f"Production : {output.get('kind', '')} {output.get('id', '')}")
        if isinstance(result.get("amount"), (int, float)):
            parts.append(f"Quantité transférée : {result['amount']:g}")
        if isinstance(result.get("integrityPercent"), (int, float)):
            parts.append(f"{result['integrityPercent']} points réparés")
        if isinstance(result.get("depotId"), str):
            parts.append(f"Dépôt : {result['depotId']}")
        return "; ".join(parts)

    @observed
    def crafts(self, ship_id: str, crafts: list[dict[str, Any]]) -> None:
        if self.state is None or ship_id not in self.state["ships"]:
            return
        known = self.state["crafts"].setdefault(ship_id, {})
        for craft in crafts:
            craft = require_mapping(craft, "craft")
            craft_id = require_string(craft.get("id"), "craft.id")
            status = craft.get("status")
            if craft_id in known and known[craft_id] not in TERMINAL and status in TERMINAL:
                action_id = craft.get("actionId")
                action = self.state["actions"].get(action_id)
                if action is None or action["status"] not in TERMINAL:
                    self._line("PRODUCTION", f"Fabrication {craft.get('recipeId')} {TERMINAL[status]} à bord de {ship_id} ({craft_id}). {self._result(craft)}")
                    if action is not None:
                        action["status"] = status
            if status in TERMINAL:
                known.pop(craft_id, None)
            else:
                known[craft_id] = status
        self._save()

    @observed
    def scan(self, ship_id: str, coordinates: tuple[int, int, int], sector: dict[str, Any]) -> None:
        if self.state is None or ship_id not in self.state["ships"]:
            return
        seen = set(self.state["threats"])
        for _, obj in observed_sector_objects(sector.get("objects", [])):
            if obj.get("type") != "missile" or obj.get("status") != "moving":
                continue
            if obj.get("launcherKind") != "probe" and obj.get("targetId") not in self.state["ships"]:
                continue
            identifier = require_string(obj.get("id"), "missile.id")
            if identifier not in seen:
                self._line("COMBAT", f"Missile hostile {identifier} détecté par {ship_id}, secteur relatif {format_coordinates(coordinates)}, cible {obj.get('targetId')}.")
                self.state["threats"].append(identifier)
                seen.add(identifier)
        self.state["threats"] = self.state["threats"][-5000:]
        self._save()

    def poll_alerts(self, api: OthersApi) -> None:
        if self.state is None or self.monotonic() < self.next_alerts_at:
            return
        self.next_alerts_at = self.monotonic() + 300
        try:
            alerts = api.get_unread_alerts()
            # Valider le lot avant toute écriture/acquittement, y compris les dates d'origine.
            selected = []
            for alert in alerts:
                if alert.get("shipId") not in self.state["ships"]:
                    continue
                if alert.get("status") != "unread":
                    raise ApiContractError("La collecte a renvoyé une alerte déjà lue.")
                for field in ("id", "shipId", "createdAt", "message", "type"):
                    require_string(alert.get(field), f"alert.{field}")
                date = datetime.fromisoformat(alert["createdAt"].replace("Z", "+00:00"))
                if date.tzinfo is None:
                    raise ApiContractError("alert.createdAt doit inclure le fuseau.")
                selected.append((date, alert))
            selected.sort(key=lambda pair: (pair[0], pair[1]["id"]))
            written = set(self.state["writtenAlerts"])
            for offset in range(0, len(selected), 500):
                batch = selected[offset:offset + 500]
                identifiers = []
                for _, alert in batch:
                    if alert["id"] not in written:
                        self._line("ALERTE", f"{alert['createdAt']} — {alert['shipId']} — {alert['type']} — {alert['message']} ({alert['id']})")
                        written.add(alert["id"])
                        self.state["writtenAlerts"] = sorted(written)
                    if alert["id"] not in identifiers:
                        identifiers.append(alert["id"])
                self._save()  # Écriture durable AVANT l'acquittement, même après un échec précédent.
                confirmed = api.mark_alerts_read(identifiers)
                if {a.get("id") for a in confirmed if a.get("status") == "read"} != set(identifiers):
                    raise ApiContractError("Acquittement des alertes incomplet.")
            # Une réponse perdue peut laisser des identifiants déjà lus dans le suivi.
            self.state["writtenAlerts"] = []
            self._save()
        except (OSError, ValueError, ApiContractError, ApiRequestError, ConnectionError) as error:
            self.warning(error)
        finally:
            self.next_alerts_at = self.monotonic() + 300

    def close(self) -> None:
        if self.handler is not None:
            self.handler.close()
