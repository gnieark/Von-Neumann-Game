#!/usr/bin/env python3

from __future__ import annotations

import unittest
import sys
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parent))

from defense_etoile_attente import (
    ApiRequestError,
    DefenseEtoileAttente,
    NEIGHBOR_OFFSETS,
    add_coordinates,
    coordinate_distance,
    movement_hop,
)


Coordinates = tuple[int, int, int]


def sector(coordinates: Coordinates) -> dict[str, Any]:
    return {"relative": {"x": coordinates[0], "y": coordinates[1], "z": coordinates[2]}}


def ship(
    ship_id: str,
    coordinates: Coordinates | None,
    *,
    ship_type: str = "standard",
    status: str = "inactive",
    movement: dict[str, Any] | None = None,
) -> dict[str, Any]:
    return {
        "id": ship_id,
        "fleetId": "fleet_test",
        "type": ship_type,
        "status": status,
        "location": {"state": "transit" if coordinates is None else "in_sector"},
        "sector": None if coordinates is None else sector(coordinates),
        "movement": movement,
        "updatedAt": "2026-08-30T10:00:00+00:00",
    }


def movement(target: Coordinates) -> dict[str, Any]:
    return {
        "phase": "transit",
        "target": {"x": target[0], "y": target[1], "z": target[2]},
        "arrivalAt": "2099-01-01T00:00:00+00:00",
    }


class FakeApi:
    def __init__(
        self,
        ships: list[dict[str, Any]],
        scans: dict[Coordinates, dict[str, Any] | Exception] | None = None,
    ) -> None:
        self.ships = ships
        self.scans = scans or {}
        self.scan_calls: list[Coordinates] = []
        self.moves: list[tuple[str, Coordinates]] = []
        self.fleet_calls = 0

    def get_ship(self, ship_id: str) -> dict[str, Any]:
        return next(item for item in self.ships if item["id"] == ship_id)

    def get_fleet(self, fleet_id: str) -> dict[str, Any]:
        self.fleet_calls += 1
        return {"id": fleet_id, "ships": self.ships}

    def scan_sector(self, ship_id: str, coordinates: Coordinates) -> dict[str, Any]:
        self.scan_calls.append(coordinates)
        response = self.scans.get(
            coordinates,
            {
                "knowledgeLevel": "neighbor_scan",
                "estimatedObjects": {"blackHoleProbability": 0.7},
            },
        )
        if isinstance(response, Exception):
            raise response
        return response

    def move_ship(self, item: dict[str, Any], target: Coordinates) -> dict[str, Any]:
        self.moves.append((item["id"], target))
        return {"endsAt": "2099-01-01T00:00:00+00:00"}


class DefenseEtoileAttenteTests(unittest.TestCase):
    def test_reconciles_occupancy_transit_black_holes_surplus_and_recall(self) -> None:
        center = (0, 0, 0)
        neighbors = [add_coordinates(center, offset) for offset in NEIGHBOR_OFFSETS]
        fleet_ships = [
            ship("mother", center, ship_type="mothership"),
            ship("resident-a", neighbors[0]),
            ship("resident-b-1", neighbors[1]),
            ship("resident-b-2", neighbors[1]),
            ship("inbound", None, status="transit", movement=movement(neighbors[2])),
            ship("home-1", center),
            ship("home-2", center),
            ship("home-3", center),
            ship("stray", (20, 0, 0)),
        ]
        api = FakeApi(
            fleet_ships,
            scans={
                neighbors[3]: {
                    "knowledgeLevel": "detailed",
                    "objects": [{"type": "black_hole"}],
                }
            },
        )
        controller = DefenseEtoileAttente(api, "mother", logger=lambda _: None)

        result = controller.run_cycle()

        moves = dict(api.moves)
        self.assertEqual(center, moves["resident-b-2"])
        self.assertEqual((10, 0, 0), moves["stray"])
        self.assertEqual(neighbors[4], moves["home-1"])
        self.assertEqual(neighbors[5], moves["home-2"])
        self.assertEqual(neighbors[6], moves["home-3"])
        self.assertNotIn("resident-a", moves)
        self.assertNotIn("resident-b-1", moves)
        self.assertNotIn("inbound", moves)
        self.assertNotIn(neighbors[3], moves.values())
        self.assertEqual(5, result.accepted_commands)

    def test_uncertain_black_hole_scan_does_not_exclude_a_sector(self) -> None:
        center = (0, 0, 0)
        target = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("home", center)],
            scans={
                target: {
                    "knowledgeLevel": "neighbor_scan",
                    "estimatedObjects": {"blackHoleProbability": 1.0},
                }
            },
        )

        DefenseEtoileAttente(api, "mother", logger=lambda _: None).run_cycle()

        self.assertEqual([("home", target)], api.moves)

    def test_insufficient_scan_data_does_not_exclude_a_sector(self) -> None:
        center = (0, 0, 0)
        target = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("home", center)],
            scans={
                target: ApiRequestError(
                    400,
                    "insufficient_scan_data",
                    "Not enough data",
                    retry_after_seconds=120,
                )
            },
        )

        DefenseEtoileAttente(api, "mother", logger=lambda _: None).run_cycle()

        self.assertEqual([("home", target)], api.moves)

    def test_uncertain_empty_neighbors_are_not_scanned_again_without_arrival(self) -> None:
        api = FakeApi([ship("mother", (0, 0, 0), ship_type="mothership")])
        controller = DefenseEtoileAttente(api, "mother", logger=lambda _: None)

        controller.run_cycle()
        controller.run_cycle()

        self.assertEqual(12, len(api.scan_calls))

    def test_mothership_movement_suspends_the_formation(self) -> None:
        api = FakeApi(
            [
                ship(
                    "mother",
                    (0, 0, 0),
                    ship_type="mothership",
                    status="preparing",
                    movement=movement((2, 0, 0)),
                )
            ]
        )

        result = DefenseEtoileAttente(api, "mother", logger=lambda _: None).run_cycle()

        self.assertEqual(0, api.fleet_calls)
        self.assertEqual([], api.moves)
        self.assertEqual(1, len(result.event_dates))

    def test_long_recall_hops_are_canonical_and_converge(self) -> None:
        current = (101, -35, 20)
        destination = (1, 1, 0)
        previous_distance = coordinate_distance(current, destination)

        for _ in range(20):
            next_coordinates = movement_hop(current, destination)
            self.assertEqual(0, sum(next_coordinates) % 2)
            self.assertLessEqual(coordinate_distance(current, next_coordinates), 10)
            distance = coordinate_distance(next_coordinates, destination)
            self.assertLess(distance, previous_distance)
            current = next_coordinates
            previous_distance = distance
            if current == destination:
                break

        self.assertEqual(destination, current)

    def test_intermediate_recall_hop_does_not_stop_in_the_defense_ring(self) -> None:
        destination = (0, 0, 0)
        intermediate = movement_hop((11, 11, 0), destination)

        self.assertGreater(coordinate_distance(intermediate, destination), 1)

    def test_recall_hop_invariants_on_a_coordinate_sample(self) -> None:
        origin = (0, 0, 0)
        for x in range(-25, 26):
            for y in range(-25, 26):
                for z in range(-25, 26):
                    destination = (x, y, z)
                    original_distance = coordinate_distance(origin, destination)
                    if sum(destination) % 2 != 0 or original_distance <= 10:
                        continue
                    intermediate = movement_hop(origin, destination)
                    self.assertEqual(0, sum(intermediate) % 2)
                    self.assertLessEqual(coordinate_distance(origin, intermediate), 10)
                    self.assertLess(
                        coordinate_distance(intermediate, destination),
                        original_distance,
                    )
                    self.assertGreater(coordinate_distance(intermediate, destination), 1)


if __name__ == "__main__":
    unittest.main()
