"""Adaptateur HTTP de l'API Others."""

from __future__ import annotations

import hashlib
import json
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen

from .contracts import parse_json_object, parse_retry_after, require_mapping, require_string
from .errors import ApiContractError, ApiRequestError
from .identifiers import command_idempotency_key
from .models import Coordinates


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
            {"shipId": ship_id, "x": coordinates[0], "y": coordinates[1], "z": coordinates[2]}
        )
        body = self._request("GET", f"/api/others/sector?{query}")
        return require_mapping(body.get("sector"), "sector")

    def get_autonomous_units(self, ship_id: str) -> list[dict[str, Any]]:
        units: list[dict[str, Any]] = []
        cursor: str | None = None
        while True:
            query: dict[str, int | str] = {"limit": 500}
            if cursor is not None:
                query["cursor"] = cursor
            body = self._request(
                "GET",
                f"/api/others/ships/{quote(ship_id, safe='')}"
                f"/sector/autonomous-units?{urlencode(query)}",
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
        body = self._request("GET", f"/api/others/ships/{quote(ship_id, safe='')}/inventory")
        return require_mapping(body.get("inventory"), "inventory")

    def get_auxiliaries(self, ship_id: str) -> list[dict[str, Any]]:
        auxiliaries: list[dict[str, Any]] = []
        cursor: str | None = None
        while True:
            query: dict[str, int | str] = {"limit": 500}
            if cursor is not None:
                query["cursor"] = cursor
            body = self._request(
                "GET",
                f"/api/others/ships/{quote(ship_id, safe='')}/auxiliaries?{urlencode(query)}",
            )
            page = body.get("auxiliaries")
            if not isinstance(page, list):
                raise ApiContractError("auxiliaries doit être une liste.")
            auxiliaries.extend(require_mapping(item, "auxiliaries[]") for item in page)
            next_cursor = body.get("nextCursor")
            if next_cursor is None:
                return auxiliaries
            cursor = require_string(next_cursor, "nextCursor")

    def get_crafting_recipes(self) -> list[dict[str, Any]]:
        body = self._request("GET", "/api/others/crafting/recipes")
        recipes = body.get("recipes")
        if not isinstance(recipes, list):
            raise ApiContractError("recipes doit être une liste.")
        return [require_mapping(recipe, "recipes[]") for recipe in recipes]

    def get_crafts(self, ship_id: str) -> list[dict[str, Any]]:
        body = self._request(
            "GET",
            f"/api/others/ships/{quote(ship_id, safe='')}/crafts",
        )
        crafts = body.get("crafts")
        if not isinstance(crafts, list):
            raise ApiContractError("crafts doit être une liste.")
        return [require_mapping(craft, "crafts[]") for craft in crafts]

    def start_craft(
        self,
        ship_id: str,
        recipe_id: str,
        assistant_auxiliary_id: str,
        operation_key: str,
    ) -> dict[str, Any]:
        body = self._request(
            "POST",
            f"/api/others/ships/{quote(ship_id, safe='')}/crafts",
            payload={
                "recipeId": recipe_id,
                "assistantAuxiliaryId": assistant_auxiliary_id,
            },
            idempotency_key=command_idempotency_key(
                "defense-craft",
                ship_id,
                recipe_id,
                assistant_auxiliary_id,
                operation_key,
            ),
        )
        return require_mapping(body.get("action"), "action")

    def start_harvest(
        self,
        ship_id: str,
        target_object_id: str,
        auxiliary_count: int,
        operation_key: str,
    ) -> dict[str, Any]:
        body = self._request(
            "POST",
            f"/api/others/ships/{quote(ship_id, safe='')}/harvest",
            payload={
                "targetObjectId": target_object_id,
                "auxiliaryCount": auxiliary_count,
            },
            idempotency_key=command_idempotency_key(
                "defense-harvest",
                ship_id,
                target_object_id,
                str(auxiliary_count),
                operation_key,
            ),
        )
        return require_mapping(body.get("action"), "action")

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

    def move_ship(self, ship: dict[str, Any], target: Coordinates) -> dict[str, Any]:
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

        request = Request(f"{self.base_url}{path}", data=data, headers=headers, method=method)
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
