"""Interface en ligne de commande et boucle d'exécution."""

from __future__ import annotations

import argparse
import sys
import time
from datetime import datetime
from pathlib import Path

from .config import CONFIG_PATH, IDLE_REFRESH_SECONDS, TIMEOUT_SECONDS, load_config
from .controller import DefenseEtoileAttente
from .errors import ApiContractError, ApiRequestError, ConfigurationError
from .http_api import HttpOthersApi


def timestamped_logger(message: str) -> None:
    now = datetime.now().astimezone().isoformat(timespec="seconds")
    print(f"[{now}] {message}", flush=True)


def build_argument_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=(
            "Maintient douze sentinelles et la logistique autour d'un "
            "vaisseau mère Others."
        ),
    )
    identifier = parser.add_mutually_exclusive_group(required=True)
    identifier.add_argument("--mothership-id", help="Identifiant public du vaisseau mère")
    identifier.add_argument("--fleet-id", help="Identifiant public de la flotte")
    parser.add_argument(
        "--config", type=Path, default=CONFIG_PATH,
        help=f"Fichier de configuration JSON (défaut : {CONFIG_PATH})",
    )
    parser.add_argument(
        "--once", action="store_true",
        help="Exécute un seul cycle de réconciliation puis quitte",
    )
    parser.add_argument(
        "--idle-refresh-seconds", type=float, default=IDLE_REFRESH_SECONDS,
        help="Intervalle maximal entre deux contrôles (défaut : 300)",
    )
    parser.add_argument(
        "--timeout-seconds", type=float, default=TIMEOUT_SECONDS,
        help="Délai maximal d'une requête HTTP (défaut : 10)",
    )
    return parser


def main(argv: list[str] | None = None) -> int:
    arguments = build_argument_parser().parse_args(argv)
    if arguments.idle_refresh_seconds < 30:
        print(
            "Erreur : --idle-refresh-seconds doit être supérieur ou égal à 30.",
            file=sys.stderr,
        )
        return 2
    if arguments.timeout_seconds <= 0:
        print("Erreur : --timeout-seconds doit être strictement positif.", file=sys.stderr)
        return 2

    try:
        configuration = load_config(arguments.config)
        controller = DefenseEtoileAttente(
            HttpOthersApi(
                configuration.base_url,
                configuration.api_token,
                arguments.timeout_seconds,
            ),
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
