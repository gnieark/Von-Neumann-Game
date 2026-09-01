"""Géométrie FCC utilisée par la formation."""

from __future__ import annotations

from typing import Any

from .errors import ApiContractError
from .models import Coordinates

NEIGHBOR_OFFSETS: tuple[Coordinates, ...] = (
    (1, 1, 0),
    (1, -1, 0),
    (-1, 1, 0),
    (-1, -1, 0),
    (1, 0, 1),
    (1, 0, -1),
    (-1, 0, 1),
    (-1, 0, -1),
    (0, 1, 1),
    (0, 1, -1),
    (0, -1, 1),
    (0, -1, -1),
)


def parse_coordinates(value: Any, context: str) -> Coordinates:
    if not isinstance(value, dict):
        raise ApiContractError(f"{context} doit être un objet de coordonnées.")
    coordinates = []
    for axis in ("x", "y", "z"):
        component = value.get(axis)
        if not isinstance(component, int) or isinstance(component, bool):
            raise ApiContractError(f"{context}.{axis} doit être un entier.")
        coordinates.append(component)
    result = (coordinates[0], coordinates[1], coordinates[2])
    if sum(result) % 2 != 0:
        raise ApiContractError(f"{context} ne respecte pas la parité FCC.")
    return result


def add_coordinates(a: Coordinates, b: Coordinates) -> Coordinates:
    return (a[0] + b[0], a[1] + b[1], a[2] + b[2])


def coordinate_distance(a: Coordinates, b: Coordinates) -> int:
    return max(abs(a[index] - b[index]) for index in range(3))


def movement_hop(
    origin: Coordinates,
    destination: Coordinates,
    max_distance: int = 10,
) -> Coordinates:
    if coordinate_distance(origin, destination) <= max_distance:
        return destination
    delta = tuple(destination[index] - origin[index] for index in range(3))
    step = [max(-max_distance, min(max_distance, value)) for value in delta]
    if sum(step) % 2 != 0:
        adjustable = max(
            (index for index, component in enumerate(step) if component != 0),
            key=lambda index: abs(step[index]),
        )
        step[adjustable] -= 1 if step[adjustable] > 0 else -1
    target = (origin[0] + step[0], origin[1] + step[1], origin[2] + step[2])
    if coordinate_distance(target, destination) == 1:
        remaining = tuple(destination[index] - target[index] for index in range(3))
        for index, component in enumerate(remaining):
            if component != 0:
                step[index] -= 1 if component > 0 else -1
        target = (origin[0] + step[0], origin[1] + step[1], origin[2] + step[2])
    if sum(target) % 2 != 0 or coordinate_distance(origin, target) > max_distance:
        raise RuntimeError("Impossible de calculer une étape de rappel FCC canonique.")
    if coordinate_distance(target, destination) >= coordinate_distance(origin, destination):
        raise RuntimeError(
            "L'étape de rappel calculée ne rapproche pas le vaisseau de sa destination."
        )
    return target


def format_coordinates(coordinates: Coordinates) -> str:
    return f"({coordinates[0]}, {coordinates[1]}, {coordinates[2]})"
