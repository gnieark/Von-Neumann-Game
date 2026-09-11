#!/usr/bin/env python3
"""Lance la construction d'un dépôt de germination Others."""

from __future__ import annotations

import argparse
import sys
import uuid
from math import isfinite
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit

if __package__ in {None, ""}:
    sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.others_control.defense_etoile.contracts import require_string
from scripts.others_control.defense_etoile.errors import ApiContractError, ApiRequestError
from scripts.others_control.defense_etoile.http_api import HttpOthersApi


DEFAULT_BASE_URL = "http://127.0.0.1:8000"
DEFAULT_TIMEOUT_SECONDS = 10.0


def base_url(value: str) -> str:
    normalized = value.strip().rstrip("/")
    parsed = urlsplit(normalized)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise argparse.ArgumentTypeError("l'URL de base doit être une URL HTTP ou HTTPS valide")
    return normalized


def build_argument_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=(
            "Construit un dépôt Others dans le secteur courant d'un vaisseau mère "
            "avec son premier auxiliaire embarqué libre."
        ),
    )
    parser.add_argument("--token", required=True, help="Token Bearer de l'API Others")
    parser.add_argument(
        "--mothership-id", required=True,
        help="Identifiant public du vaisseau mère",
    )
    parser.add_argument(
        "--base-url", type=base_url, default=DEFAULT_BASE_URL,
        help=f"URL de base de l'API (défaut : {DEFAULT_BASE_URL})",
    )
    parser.add_argument(
        "--timeout-seconds", type=float, default=DEFAULT_TIMEOUT_SECONDS,
        help="Délai maximal d'une requête HTTP (défaut : 10)",
    )
    return parser


def first_available_auxiliary(auxiliaries: list[dict[str, Any]]) -> dict[str, Any] | None:
    eligible = [
        auxiliary
        for auxiliary in auxiliaries
        if auxiliary.get("locationType") == "embarked"
        and auxiliary.get("status") in {"inactive", "available"}
        and auxiliary.get("action") is None
        and isinstance(auxiliary.get("id"), str)
        and bool(auxiliary["id"])
    ]
    return min(eligible, key=lambda auxiliary: auxiliary["id"], default=None)


def main(argv: list[str] | None = None) -> int:
    arguments = build_argument_parser().parse_args(argv)
    token = arguments.token.strip()
    mothership_id = arguments.mothership_id.strip()
    if not token:
        print("Erreur : --token doit être non vide.", file=sys.stderr)
        return 2
    if not mothership_id:
        print("Erreur : --mothership-id doit être non vide.", file=sys.stderr)
        return 2
    if not isfinite(arguments.timeout_seconds) or arguments.timeout_seconds <= 0:
        print("Erreur : --timeout-seconds doit être fini et strictement positif.", file=sys.stderr)
        return 2

    api = HttpOthersApi(arguments.base_url, token, arguments.timeout_seconds)
    try:
        ship = api.get_ship(mothership_id)
        if ship.get("type") != "mothership":
            print(
                f"Erreur : {mothership_id} n'est pas un vaisseau mère Others.",
                file=sys.stderr,
            )
            return 1
        auxiliary = first_available_auxiliary(api.get_auxiliaries(mothership_id))
        if auxiliary is None:
            print(
                f"Erreur : aucun auxiliaire embarqué libre sur {mothership_id}.",
                file=sys.stderr,
            )
            return 1
        auxiliary_id = require_string(auxiliary.get("id"), "auxiliary.id")
        action = api.start_germination_depot(
            mothership_id, auxiliary_id, uuid.uuid4().hex,
        )
        action_id = require_string(action.get("id"), "action.id")
    except (ApiRequestError, ApiContractError, ConnectionError) as error:
        print(f"Erreur : {error}", file=sys.stderr)
        return 1

    print(f"Construction du dépôt acceptée avec l'auxiliaire {auxiliary_id}.")
    print(f"Action : {action_id}")
    if isinstance(action.get("endsAt"), str) and action["endsAt"]:
        print(f"Fin prévue : {action['endsAt']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
