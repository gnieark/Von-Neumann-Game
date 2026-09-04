from __future__ import annotations

import unittest

from scripts.others_control.defense_etoile.armament import FleetArmamentCoordinator
from scripts.others_control.defense_etoile.geometry import NEIGHBOR_OFFSETS, add_coordinates
from scripts.others_control.defense_etoile.models import CycleResult
from scripts.others_control.tests.support import FakeApi, auxiliary, missile_item, ship


class ArmamentTests(unittest.TestCase):
    def setUp(self) -> None:
        self.center = (0, 0, 0)
        self.mothership = ship("mother", self.center, ship_type="mothership")
        self.logs: list[str] = []

    def test_surplus_is_given_to_the_least_armed_local_ships(self) -> None:
        ships = [
            self.mothership,
            ship("empty", self.center),
            ship("one", self.center),
            ship("two", self.center),
            ship("full", self.center),
        ]
        api = FakeApi(
            ships,
            inventories={
                "mother": [missile_item(f"mother-{index:02d}") for index in range(14)],
                "one": [missile_item("one-1")],
                "two": [missile_item("two-1"), missile_item("two-2")],
                "full": [missile_item(f"full-{index}") for index in range(3)],
            },
            auxiliaries={
                "mother": [auxiliary(f"aux-{index}") for index in range(4)]
            },
        )

        result = CycleResult()
        state = FleetArmamentCoordinator(
            api, logger=self.logs.append
        ).reconcile(self.mothership, ships, result)

        self.assertEqual(
            [
                ("mother", "empty", "aux-0", ("mother-00",)),
                ("mother", "one", "aux-1", ("mother-01",)),
                ("mother", "two", "aux-2", ("mother-02",)),
            ],
            api.inventory_transfers,
        )
        self.assertEqual(20, state.total_missiles)
        self.assertTrue(state.transfers_active)
        self.assertEqual(3, result.accepted_commands)

    def test_reserve_of_ten_missiles_is_never_distributed(self) -> None:
        recipient = ship("recipient", self.center)
        api = FakeApi(
            [self.mothership, recipient],
            inventories={
                "mother": [missile_item(f"missile-{index}") for index in range(10)]
            },
            auxiliaries={"mother": [auxiliary("aux-a")]},
        )

        FleetArmamentCoordinator(api, logger=lambda _: None).reconcile(
            self.mothership, [self.mothership, recipient], CycleResult()
        )

        self.assertEqual([], api.inventory_transfers)

    def test_neighboring_ships_are_not_direct_transfer_targets(self) -> None:
        local = ship("local", self.center)
        neighbor = ship("neighbor", add_coordinates(self.center, NEIGHBOR_OFFSETS[0]))
        api = FakeApi(
            [self.mothership, local, neighbor],
            inventories={
                "mother": [missile_item(f"missile-{index}") for index in range(12)]
            },
            auxiliaries={"mother": [auxiliary("aux-a"), auxiliary("aux-b")]},
        )

        FleetArmamentCoordinator(api, logger=lambda _: None).reconcile(
            self.mothership, [self.mothership, local, neighbor], CycleResult()
        )

        self.assertEqual(["local"], [transfer[1] for transfer in api.inventory_transfers])

    def test_active_transfer_prevents_a_second_distribution_wave(self) -> None:
        action = {
            "id": "transfer-active",
            "type": "inventory_transfer",
            "status": "queued",
            "endsAt": "2099-01-01T00:00:00+00:00",
        }
        recipient = ship("recipient", self.center)
        api = FakeApi(
            [self.mothership, recipient],
            inventories={
                "mother": [missile_item(f"missile-{index}") for index in range(11)]
            },
            auxiliaries={
                "mother": [
                    auxiliary("aux-busy", status="busy", action=action),
                    auxiliary("aux-free"),
                ]
            },
        )

        result = CycleResult()
        state = FleetArmamentCoordinator(api, logger=lambda _: None).reconcile(
            self.mothership, [self.mothership, recipient], result
        )

        self.assertEqual([], api.inventory_transfers)
        self.assertTrue(state.transfers_active)
        self.assertEqual(1, len(result.event_dates))


if __name__ == "__main__":
    unittest.main()
