#!/usr/bin/env python3
"""Teste l'accès en lecture à quelques routes de l'API Others."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen


CONFIG_PATH = Path(__file__).with_name("config.json")
TIMEOUT_SECONDS = 10
ENDPOINTS = (
    ("Vue d'ensemble", "/api/others"),
    ("Flottes", "/api/others/fleets"),
    ("Alertes", "/api/others/alerts"),
    ("Recettes d'atelier", "/api/others/crafting/recipes"),
)


def load_config() -> tuple[str, str]:
    try:
        config: Any = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    except FileNotFoundError as error:
        raise ValueError(
            f"Configuration absente : copiez config.example.json vers {CONFIG_PATH.name}."
        ) from error
    except (OSError, json.JSONDecodeError) as error:
        raise ValueError(f"Impossible de lire {CONFIG_PATH}: {error}") from error

    if not isinstance(config, dict):
        raise ValueError(f"{CONFIG_PATH} doit contenir un objet JSON.")

    base_url = config.get("base_url")
    api_token = config.get("api_token")
    if not isinstance(base_url, str) or not base_url.strip():
        raise ValueError("La propriété base_url doit être une chaîne non vide.")
    if not isinstance(api_token, str) or not api_token.strip():
        raise ValueError("La propriété api_token doit être une chaîne non vide.")

    base_url = base_url.strip().rstrip("/")
    parsed_url = urlsplit(base_url)
    if parsed_url.scheme not in {"http", "https"} or not parsed_url.netloc:
        raise ValueError("La propriété base_url doit être une URL HTTP ou HTTPS valide.")

    return base_url, api_token.strip()


def check_endpoint(base_url: str, api_token: str, label: str, path: str) -> bool:
    request = Request(
        f"{base_url}{path}",
        headers={
            "Accept": "application/json",
            "Authorization": f"Bearer {api_token}",
        },
        method="GET",
    )

    try:
        with urlopen(request, timeout=TIMEOUT_SECONDS) as response:
            status = response.status
            response.read()
    except HTTPError as error:
        print(f"[ÉCHEC] {label}: HTTP {error.code} ({path})")
        return False
    except URLError as error:
        print(f"[ÉCHEC] {label}: connexion impossible ({error.reason})")
        return False
    except TimeoutError:
        print(f"[ÉCHEC] {label}: délai de {TIMEOUT_SECONDS} s dépassé")
        return False

    if 200 <= status < 300:
        print(f"[OK] {label}: HTTP {status} ({path})")
        return True

    print(f"[ÉCHEC] {label}: HTTP {status} inattendu ({path})")
    return False


def main() -> int:
    try:
        base_url, api_token = load_config()
    except ValueError as error:
        print(f"Erreur de configuration : {error}", file=sys.stderr)
        return 2

    print(f"Test de l'API Others sur {base_url}")
    results = [
        check_endpoint(base_url, api_token, label, path)
        for label, path in ENDPOINTS
    ]
    success = all(results)

    if success:
        print("Connexion et accès en lecture opérationnels.")
        return 0

    print("Au moins un contrôle a échoué.", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
