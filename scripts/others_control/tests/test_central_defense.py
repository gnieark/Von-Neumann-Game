from __future__ import annotations

import unittest

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.defense_etoile.geometry import NEIGHBOR_OFFSETS, add_coordinates
from scripts.others_control.tests.support import (
    FakeApi,
    detailed_scan,
    missile_item,
    observed_manny,
    ship,
)


def moving_missile(missile_id: str, target_id: str) -> dict[str, str]:
    return {
        "id": missile_id,
        "type": "missile",
        "status": "moving",
        "launcherKind": "others_ship",
        "targetKind": "probe",
        "targetId": target_id,
        "launchedAt": "2026-09-04T12:00:00+00:00",
        "impactAt": "2026-09-04T12:30:00+00:00",
    }


class CentralDefenseTests(unittest.TestCase):
    def test_probe_presence_recalls_sentinels_and_distributes_four_missiles(self) -> None:
        center = (0, 0, 0)
        guard_sector = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        api = FakeApi(
            [
                ship("mother", center, ship_type="mothership"),
                ship("home", center),
                ship("guard", guard_sector),
            ],
            scans={center: detailed_scan(probes=[{"id": 42, "status": "idle"}])},
            inventories={
                "mother": [missile_item(f"mother-{index}") for index in range(3)],
                "home": [missile_item(f"home-{index}") for index in range(2)],
                "guard": [missile_item("guard-0")],
            },
        )
        controller = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        )

        result = controller.run_cycle()

        self.assertEqual([("guard", center)], api.moves)
        self.assertEqual(4, len(api.missile_launches))
        self.assertEqual({"42"}, {target for _, _, target in api.missile_launches})
        self.assertEqual(
            {"mother": 2, "home": 2},
            {
                ship_id: sum(launch[0] == ship_id for launch in api.missile_launches)
                for ship_id in ("mother", "home")
            },
        )
        self.assertEqual(5, result.accepted_commands)

        api.inventory_calls.clear()
        controller.run_activity_cycle()
        self.assertEqual(4, len(api.missile_launches))
        self.assertEqual([("guard", center)], api.moves)
        self.assertEqual([], api.inventory_calls)

    def test_disappeared_missile_is_replaced(self) -> None:
        center = (0, 0, 0)
        scan = detailed_scan(probes=[{"id": 42, "status": "idle"}])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership")],
            scans={center: scan},
            inventories={
                "mother": [missile_item(f"item-{index}") for index in range(5)]
            },
        )
        controller = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        )
        controller.run_cycle()
        launched_ids = [f"launched-missile-{index}" for index in range(1, 5)]
        scan["objects"] = [moving_missile(missile_id, "42") for missile_id in launched_ids]

        controller.run_activity_cycle()
        self.assertEqual(4, len(api.missile_launches))

        scan["objects"] = scan["objects"][:-1]
        controller.run_activity_cycle()

        self.assertEqual(5, len(api.missile_launches))
        self.assertEqual(("mother", "item-4", "42"), api.missile_launches[-1])

    def test_four_missiles_are_maintained_for_each_probe(self) -> None:
        center = (0, 0, 0)
        api = FakeApi(
            [ship("mother", center, ship_type="mothership")],
            scans={
                center: detailed_scan(probes=[
                    {"id": 42, "status": "idle"},
                    {"id": 43, "status": "idle"},
                ])
            },
            inventories={
                "mother": [missile_item(f"item-{index}") for index in range(8)]
            },
        )

        DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        ).run_cycle()

        self.assertEqual(
            {"42": 4, "43": 4},
            {
                target_id: sum(launch[2] == target_id for launch in api.missile_launches)
                for target_id in ("42", "43")
            },
        )

    def test_each_detected_manny_receives_a_distinct_laser_ship(self) -> None:
        center = (0, 0, 0)
        api = FakeApi(
            [
                ship("mother", center, ship_type="mothership"),
                ship("defender", center),
            ],
            scans={center: detailed_scan()},
            autonomous_units={
                "mother": [
                    observed_manny("manny-a", "42"),
                    observed_manny("manny-b", "43"),
                ]
            },
        )
        controller = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        )

        controller.run_cycle()
        controller.run_activity_cycle()

        self.assertEqual(
            {("defender", "manny-a"), ("mother", "manny-b")},
            set(api.laser_locks),
        )
        self.assertEqual(2, len(api.laser_locks))


if __name__ == "__main__":
    unittest.main()
