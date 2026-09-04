"""Configuration d'exécution du contrôleur."""

from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit

from .errors import ConfigurationError

CONFIG_PATH = Path(__file__).resolve().parent.parent / "config.json"
TIMEOUT_SECONDS = 10.0
IDLE_REFRESH_SECONDS = 300.0
ACTIVITY_REFRESH_SECONDS = 20.0


@dataclass(frozen=True)
class ApiConfiguration:
    base_url: str
    api_token: str


def load_config(path: Path) -> ApiConfiguration:
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
    return ApiConfiguration(base_url, api_token.strip())
