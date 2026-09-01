"""Capture et normalisation des observations d'une sentinelle."""

from __future__ import annotations

from typing import Any

from .contracts import identifier_string, require_mapping, require_string
from .errors import ApiContractError
from .identifiers import stable_signature
from .models import Coordinates, ScoutObservation
from .ports import OthersApi

FLOATING_OBJECT_TYPES = {"drifting_item", "detached_container"}


class ScoutObserver:
    def __init__(self, api: OthersApi) -> None:
        self.api = api

    def observe(self, ship_id: str, coordinates: Coordinates) -> ScoutObservation:
        autonomous_units: dict[str, tuple[str, str]] = {}
        for unit in self.api.get_autonomous_units(ship_id):
            if unit.get("kind") != "manny":
                continue
            unit_id = identifier_string(unit.get("id"), "autonomousUnits[].id")
            carrier = require_mapping(unit.get("carrier"), f"autonomous unit {unit_id}.carrier")
            if carrier.get("kind") != "probe":
                raise ApiContractError(f"La Manny {unit_id} doit avoir une sonde porteuse.")
            carrier_id = identifier_string(
                carrier.get("id"), f"autonomous unit {unit_id}.carrier.id"
            )
            spatial_state = require_string(
                unit.get("spatialState"), f"autonomous unit {unit_id}.spatialState"
            )
            autonomous_units[unit_id] = (carrier_id, spatial_state)

        sector = self.api.scan_sector(ship_id, coordinates)
        probes_value = sector.get("probes", [])
        if not isinstance(probes_value, list):
            raise ApiContractError("sector.probes doit être une liste lorsqu'il est présent.")
        probe_ids = tuple(
            sorted(
                {
                    identifier_string(probe.get("id"), "sector.probes[].id")
                    for probe in probes_value
                    if isinstance(probe, dict) and probe.get("id") is not None
                }
            )
        )

        ejected_mannies: dict[str, str] = {}
        missiles: dict[str, str] = {}
        trajectories: dict[str, str] = {}
        floating_objects: dict[str, str] = {}
        waypoints: dict[str, str] = {}
        objects_value = sector.get("objects", [])
        if not isinstance(objects_value, list):
            raise ApiContractError("sector.objects doit être une liste lorsqu'il est présent.")
        for context, sector_object in observed_sector_objects(objects_value):
            object_type = sector_object.get("type")
            object_id = identifier_string(sector_object.get("id"), f"{context}.id")
            if object_type == "manny":
                manny_uid = identifier_string(
                    sector_object.get("mannyUid"), f"sector object {object_id}.mannyUid"
                )
                ejected_mannies[manny_uid] = stable_signature(sector_object)
            if object_type == "missile" and sector_object.get("launcherKind") == "probe":
                missiles[object_id] = stable_signature(
                    {
                        "targetKind": sector_object.get("targetKind"),
                        "targetId": sector_object.get("targetId"),
                        "launchedAt": sector_object.get("launchedAt"),
                        "impactAt": sector_object.get("impactAt"),
                    }
                )
            trajectory = sector_object.get("trajectory")
            if object_type == "asteroid" and isinstance(trajectory, dict):
                trajectories[object_id] = stable_signature(
                    {
                        "id": trajectory.get("id"),
                        "mode": trajectory.get("mode"),
                        "targetObjectId": trajectory.get("targetObjectId"),
                        "targetSpeedC": trajectory.get("targetSpeedC"),
                        "plannedRevolutions": trajectory.get("plannedRevolutions"),
                        "direction": trajectory.get("direction"),
                        "maximumSectorCrossings": trajectory.get("maximumSectorCrossings"),
                    }
                )
            if object_type in FLOATING_OBJECT_TYPES:
                floating_objects[object_id] = stable_signature(sector_object)
            if "waypointBookmarks" in sector_object:
                waypoints[object_id] = stable_signature(sector_object["waypointBookmarks"])

        return ScoutObservation(
            coordinates=coordinates,
            autonomous_units=autonomous_units,
            ejected_mannies=ejected_mannies,
            missiles=missiles,
            trajectories=trajectories,
            floating_objects=floating_objects,
            waypoints=waypoints,
            probe_ids=probe_ids,
        )


def observed_sector_objects(objects: list[Any]) -> list[tuple[str, dict[str, Any]]]:
    observed: list[tuple[str, dict[str, Any]]] = []
    for index, value in enumerate(objects):
        context = f"sector.objects[{index}]"
        sector_object = require_mapping(value, context)
        observed.append((context, sector_object))
        for collection_name in ("minableTargets", "bookmarkTargets"):
            if collection_name not in sector_object:
                continue
            targets = sector_object[collection_name]
            if not isinstance(targets, list):
                raise ApiContractError(f"{context}.{collection_name} doit être une liste.")
            for target_index, target in enumerate(targets):
                target_context = f"{context}.{collection_name}[{target_index}]"
                observed.append((target_context, require_mapping(target, target_context)))
    return observed
