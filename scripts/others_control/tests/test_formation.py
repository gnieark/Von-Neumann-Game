from __future__ import annotations

import unittest

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.defense_etoile.errors import (
    ApiContractError,
    ApiRequestError,
    ConfigurationError,
)
from scripts.others_control.defense_etoile.geometry import NEIGHBOR_OFFSETS, add_coordinates
from scripts.others_control.tests.support import (
    FakeApi,
    detailed_scan,
    missile_item,
    movement,
    observed_manny,
    ship,
)


class FormationTests(unittest.TestCase):
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

        result = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        ).run_cycle()

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

        DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None).run_cycle()

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

        DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None).run_cycle()

        self.assertEqual([("home", target)], api.moves)

    def test_uncertain_empty_neighbors_are_not_scanned_again_without_arrival(self) -> None:
        api = FakeApi([ship("mother", (0, 0, 0), ship_type="mothership")])
        controller = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        )

        controller.run_cycle()
        controller.run_cycle()

        self.assertEqual(12, len(api.scan_calls))

    def test_mothership_movement_suspends_the_formation(self) -> None:
        api = FakeApi([
            ship(
                "mother",
                (0, 0, 0),
                ship_type="mothership",
                status="preparing",
                movement=movement((2, 0, 0)),
            )
        ])

        result = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        ).run_cycle()

        self.assertEqual(0, api.fleet_calls)
        self.assertEqual([], api.moves)
        self.assertEqual(1, len(result.event_dates))

    def test_fleet_id_resolves_mothership_without_ship_request(self) -> None:
        center = (0, 0, 0)
        target = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("home", center)]
        )

        DefenseEtoileAttente(api, fleet_id="fleet_test", logger=lambda _: None).run_cycle()

        self.assertEqual(0, api.ship_calls)
        self.assertEqual(1, api.fleet_calls)
        self.assertEqual([("home", target)], api.moves)

    def test_fleet_id_requires_exactly_one_mothership(self) -> None:
        without_mothership = FakeApi([ship("standard", (0, 0, 0))])
        with self.assertRaises(ConfigurationError):
            DefenseEtoileAttente(
                without_mothership, fleet_id="fleet_test", logger=lambda _: None
            ).run_cycle()

        duplicate_motherships = FakeApi([
            ship("mother-a", (0, 0, 0), ship_type="mothership"),
            ship("mother-b", (0, 0, 0), ship_type="mothership"),
        ])
        with self.assertRaises(ApiContractError):
            DefenseEtoileAttente(
                duplicate_motherships, fleet_id="fleet_test", logger=lambda _: None
            ).run_cycle()

    def test_empty_guard_is_replaced_by_an_armed_local_ship(self) -> None:
        center = (0, 0, 0)
        target = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        mother = ship("mother", center, ship_type="mothership")
        guard = ship("guard-empty", target)
        replacement = ship("replacement", center)
        api = FakeApi(
            [mother, guard, replacement],
            inventories={"replacement": [missile_item("missile-a")]},
        )

        result = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        ).run_cycle()

        self.assertEqual(
            [("replacement", target), ("guard-empty", center)], api.moves
        )
        self.assertEqual(2, result.accepted_commands)

    def test_tactically_committed_empty_guard_is_not_replaced(self) -> None:
        center = (0, 0, 0)
        target = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        mother = ship("mother", center, ship_type="mothership")
        guard = ship("guard-empty", target, deuterium=20.0)
        replacement = ship("replacement", center)
        api = FakeApi(
            [mother, guard, replacement],
            scans={target: detailed_scan(probes=[{"id": 42, "status": "idle"}])},
            autonomous_units={"guard-empty": [observed_manny("manny-a", "42")]},
            inventories={"replacement": [missile_item("missile-a")]},
        )

        DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        ).run_cycle()

        self.assertNotIn(("replacement", target), api.moves)
        self.assertNotIn(("guard-empty", center), api.moves)
        self.assertEqual([("guard-empty", "manny-a")], api.laser_locks)

    def test_failed_replacement_departure_does_not_recall_the_empty_guard(self) -> None:
        center = (0, 0, 0)
        target = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        mother = ship("mother", center, ship_type="mothership")
        guard = ship("guard-empty", target)
        replacement = ship("replacement", center)
        api = FakeApi(
            [mother, guard, replacement],
            inventories={"replacement": [missile_item("missile-a")]},
            move_errors={
                "replacement": ApiRequestError(409, "others_ship_busy", "Busy")
            },
        )

        DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        ).run_cycle()

        self.assertEqual([], api.moves)

    def test_failed_empty_guard_recall_keeps_the_armed_replacement_inbound(self) -> None:
        center = (0, 0, 0)
        target = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        mother = ship("mother", center, ship_type="mothership")
        guard = ship("guard-empty", target)
        replacement = ship("replacement", center)
        api = FakeApi(
            [mother, guard, replacement],
            inventories={"replacement": [missile_item("missile-a")]},
            move_errors={
                "guard-empty": ApiRequestError(409, "others_ship_busy", "Busy")
            },
        )

        DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        ).run_cycle()

        self.assertEqual([("replacement", target)], api.moves)

    def test_armed_ship_keeps_the_guard_role_after_a_partial_replacement(self) -> None:
        center = (0, 0, 0)
        target = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        mother = ship("mother", center, ship_type="mothership")
        empty = ship("empty", target)
        armed = ship("armed", target)
        api = FakeApi(
            [mother, empty, armed],
            inventories={"armed": [missile_item("missile-a")]},
        )

        DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        ).run_cycle()

        self.assertEqual([("empty", center)], api.moves)


if __name__ == "__main__":
    unittest.main()
