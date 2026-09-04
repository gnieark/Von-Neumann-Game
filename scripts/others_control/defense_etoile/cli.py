"""Interface en ligne de commande et boucle d'exécution."""

from __future__ import annotations

import argparse
import sys
import time
from datetime import datetime
from pathlib import Path

from .config import (
    ACTIVITY_REFRESH_SECONDS,
    CONFIG_PATH,
    IDLE_REFRESH_SECONDS,
    TIMEOUT_SECONDS,
    load_config,
)
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
        help="Intervalle maximal entre deux contrôles généraux (défaut : 300)",
    )
    parser.add_argument(
        "--activity-refresh-seconds", type=float, default=ACTIVITY_REFRESH_SECONDS,
        help="Intervalle entre deux détections d'activité de sonde (défaut : 20)",
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
    if arguments.activity_refresh_seconds <= 0:
        print(
            "Erreur : --activity-refresh-seconds doit être strictement positif.",
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
    summary_pending = True
    next_general_at = 0.0
    next_activity_at = 0.0
    while True:
        now = time.monotonic()
        general_cycle = now >= next_general_at
        try:
            if summary_pending:
                controller.log_fleet_summary()
                summary_pending = False
            if general_cycle:
                result = controller.run_cycle()
            else:
                result = controller.run_activity_cycle()
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
            retry_delay = min(
                arguments.activity_refresh_seconds,
                arguments.idle_refresh_seconds,
                retry_delay * 2,
            )
            continue
        except (ApiContractError, ConfigurationError) as error:
            print(f"Erreur : {error}", file=sys.stderr)
            return 2
        except ConnectionError as error:
            timestamped_logger(f"{error}. Nouvel essai dans {retry_delay:.0f} s.")
            if arguments.once:
                return 1
            time.sleep(retry_delay)
            retry_delay = min(
                arguments.activity_refresh_seconds,
                arguments.idle_refresh_seconds,
                retry_delay * 2,
            )
            continue

        if arguments.once:
            return 0
        completed_at = time.monotonic()
        if general_cycle:
            next_general_at = completed_at + result.sleep_seconds(
                arguments.idle_refresh_seconds
            )
            next_activity_at = completed_at + arguments.activity_refresh_seconds
        else:
            next_activity_at = completed_at + arguments.activity_refresh_seconds
        delay = max(0.0, min(next_general_at, next_activity_at) - completed_at)
        timestamped_logger(
            f"Prochaine détection d'activité dans "
            f"{max(0.0, next_activity_at - completed_at):.0f} s ; "
            f"prochain contrôle général dans "
            f"{max(0.0, next_general_at - completed_at):.0f} s."
        )
        try:
            time.sleep(delay)
        except KeyboardInterrupt:
            timestamped_logger("Arrêt demandé.")
            return 0
