"""Mémoire des dangers confirmés autour de la formation."""

from __future__ import annotations

from typing import Callable

from .errors import ApiContractError, ApiRequestError
from .geometry import format_coordinates
from .models import Coordinates
from .ports import OthersApi


class SectorKnowledge:
    def __init__(self, api: OthersApi, logger: Callable[[str], None]) -> None:
        self.api = api
        self.log = logger
        self.known_black_holes: set[Coordinates] = set()
        self.known_safe_sectors: set[Coordinates] = set()
        self.uncertain_sectors: set[Coordinates] = set()

    def certainly_has_black_hole(
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
            self.log(
                f"Trou noir confirmé dans {format_coordinates(coordinates)} : secteur exclu."
            )
        else:
            self.known_safe_sectors.add(coordinates)
        return has_black_hole
