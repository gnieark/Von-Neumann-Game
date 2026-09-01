"""Validation des valeurs reçues du contrat HTTP canonique."""

from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any

from .errors import ApiContractError


def identifier_string(value: Any, context: str) -> str:
    if isinstance(value, bool) or not isinstance(value, (str, int)):
        raise ApiContractError(f"{context} doit être un identifiant chaîne ou entier.")
    result = str(value)
    if not result:
        raise ApiContractError(f"{context} doit être un identifiant non vide.")
    return result


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
