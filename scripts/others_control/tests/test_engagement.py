from __future__ import annotations

import unittest
from datetime import datetime, timedelta, timezone

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.defense_etoile.geometry import NEIGHBOR_OFFSETS, add_coordinates
from scripts.others_control.tests.support import (
    FakeApi,
    detailed_scan,
    missile_item,
    observed_manny,
    ship,
)


class EngagementTests(unittest.TestCase):
    def test_deployed_manny_uses_two_missiles_then_returns(self) -> None:
        center = (0, 0, 0)
        guard_sector = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("guard", guard_sector)],
            scans={guard_sector: detailed_scan(probes=[{"id": 42, "status": "idle"}])},
            autonomous_units={"guard": [observed_manny("manny-a", "42")]},
            inventories={"guard": [missile_item("missile-a"), missile_item("missile-b")]},
        )

        result = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        ).run_cycle()

        self.assertEqual(
            [("guard", "missile-a", "manny-a"), ("guard", "missile-b", "42")],
            api.missile_launches,
        )
        self.assertEqual([("guard", center)], api.moves)
        self.assertEqual(3, result.accepted_commands)

    def test_one_missile_prioritizes_hostile_missile_then_returns(self) -> None:
        center = (0, 0, 0)
        guard_sector = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("guard", guard_sector)],
            scans={
                guard_sector: detailed_scan(
                    probes=[{"id": 42, "status": "idle"}],
                    objects=[{
                        "id": "projectile-a",
                        "type": "missile",
                        "launcherKind": "probe",
                        "targetKind": "others_ship",
                        "targetId": "guard",
                        "launchedAt": "2026-08-31T10:00:00+00:00",
                        "impactAt": "2026-08-31T10:30:00+00:00",
                    }],
                )
            },
            inventories={"guard": [missile_item("only-missile")]},
        )

        DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None).run_cycle()

        self.assertEqual([("guard", "only-missile", "projectile-a")], api.missile_launches)
        self.assertEqual([("guard", center)], api.moves)

    def test_manny_without_missile_uses_ten_minute_laser_before_return(self) -> None:
        center = (0, 0, 0)
        guard_sector = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        clock = [datetime(2026, 8, 31, 12, 0, tzinfo=timezone.utc)]
        api = FakeApi(
            [
                ship("mother", center, ship_type="mothership"),
                ship("guard", guard_sector, deuterium=12.01),
            ],
            scans={guard_sector: detailed_scan(probes=[{"id": 42, "status": "idle"}])},
            autonomous_units={"guard": [observed_manny("manny-a", "42")]},
        )
        controller = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None, now=lambda: clock[0]
        )

        first = controller.run_cycle()
        self.assertEqual([("guard", "manny-a")], api.laser_locks)
        self.assertEqual([], api.moves)
        self.assertIn(clock[0] + timedelta(seconds=601), first.event_dates)

        clock[0] += timedelta(seconds=600)
        controller.run_cycle()
        self.assertEqual([], api.moves)

        clock[0] += timedelta(seconds=1)
        controller.run_cycle()
        self.assertEqual([("guard", center)], api.moves)

    def test_ejected_manny_is_targeted_without_return(self) -> None:
        center = (0, 0, 0)
        guard_sector = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("guard", guard_sector)],
            scans={
                guard_sector: detailed_scan(objects=[{
                    "id": "sector-manny-a",
                    "type": "manny",
                    "mannyUid": "manny-a",
                    "mannyState": "ejected",
                }])
            },
            inventories={"guard": [missile_item("missile-a")]},
        )

        DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None).run_cycle()

        self.assertEqual([("guard", "missile-a", "manny-a")], api.missile_launches)
        self.assertEqual([], api.moves)

    def test_floating_item_or_detached_container_targets_probe_then_returns(self) -> None:
        for object_type in ("drifting_item", "detached_container"):
            with self.subTest(object_type=object_type):
                center = (0, 0, 0)
                guard_sector = add_coordinates(center, NEIGHBOR_OFFSETS[0])
                scan = detailed_scan(probes=[{"id": 42, "status": "idle"}])
                api = FakeApi(
                    [ship("mother", center, ship_type="mothership"), ship("guard", guard_sector)],
                    scans={guard_sector: scan},
                    inventories={"guard": [missile_item("missile-a")]},
                )
                controller = DefenseEtoileAttente(
                    api, mothership_id="mother", logger=lambda _: None
                )

                controller.run_cycle()
                scan["objects"] = [{
                    "id": f"floating-{object_type}",
                    "type": object_type,
                    "quantity": 1,
                }]
                controller.run_cycle()

                self.assertEqual([("guard", "missile-a", "42")], api.missile_launches)
                self.assertEqual([("guard", center)], api.moves)

    def test_motorized_asteroid_trajectory_uses_two_missiles_and_stays(self) -> None:
        center = (0, 0, 0)
        guard_sector = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("guard", guard_sector)],
            scans={
                guard_sector: detailed_scan(
                    probes=[{"id": 42, "status": "idle"}],
                    objects=[{
                        "id": "solar-system-a",
                        "type": "solar_system",
                        "minableTargets": [{
                            "id": "asteroid-a",
                            "type": "asteroid",
                            "trajectory": {
                                "id": "trajectory-a",
                                "mode": "system_impact",
                                "status": "accelerating",
                                "targetObjectId": "guard",
                                "targetSpeedC": 0.8,
                                "currentSpeedC": 0.1,
                            },
                        }],
                    }],
                )
            },
            inventories={"guard": [missile_item("missile-a"), missile_item("missile-b")]},
        )
        controller = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        )

        controller.run_cycle()
        self.assertEqual(
            [("guard", "missile-a", "asteroid-a"), ("guard", "missile-b", "42")],
            api.missile_launches,
        )
        self.assertEqual([], api.moves)

        trajectory = api.scans[guard_sector]["objects"][0]["minableTargets"][0]["trajectory"]
        trajectory["currentSpeedC"] = 0.2
        api.inventories["guard"] = [missile_item("missile-c"), missile_item("missile-d")]
        controller.run_cycle()
        self.assertEqual(2, len(api.missile_launches))

        trajectory["targetSpeedC"] = 0.9
        controller.run_cycle()
        self.assertEqual(
            [("guard", "missile-c", "asteroid-a"), ("guard", "missile-d", "42")],
            api.missile_launches[2:],
        )
        self.assertEqual([], api.moves)

    def test_recovered_floating_object_disappearance_targets_probe(self) -> None:
        center = (0, 0, 0)
        guard_sector = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        scan = detailed_scan(
            probes=[{"id": 42, "status": "idle"}],
            objects=[{
                "id": "drifting-item-a",
                "type": "drifting_item",
                "itemType": "battery_pack",
                "quantity": 1,
            }],
        )
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("guard", guard_sector)],
            scans={guard_sector: scan},
            inventories={"guard": [missile_item("missile-a")]},
        )
        controller = DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None)

        controller.run_cycle()
        scan["objects"] = []
        controller.run_cycle()

        self.assertEqual([("guard", "missile-a", "42")], api.missile_launches)
        self.assertEqual([("guard", center)], api.moves)

    def test_waypoint_change_targets_probe_then_returns(self) -> None:
        center = (0, 0, 0)
        guard_sector = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        observed_object = {"id": "planet-a", "type": "planet"}
        scan = detailed_scan(probes=[{"id": 42, "status": "idle"}], objects=[observed_object])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("guard", guard_sector)],
            scans={guard_sector: scan},
            inventories={"guard": [missile_item("missile-a")]},
        )
        controller = DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None)

        controller.run_cycle()
        observed_object["waypointBookmarks"] = [
            {"name": "Un graffiti spatial", "playerId": 7}
        ]
        controller.run_cycle()

        self.assertEqual([("guard", "missile-a", "42")], api.missile_launches)
        self.assertEqual([("guard", center)], api.moves)

    def test_own_missile_does_not_trigger_an_engagement(self) -> None:
        center = (0, 0, 0)
        guard_sector = add_coordinates(center, NEIGHBOR_OFFSETS[0])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("guard", guard_sector)],
            scans={
                guard_sector: detailed_scan(
                    probes=[{"id": 42, "status": "idle"}],
                    objects=[{
                        "id": "own-projectile",
                        "type": "missile",
                        "launcherKind": "others_ship",
                        "targetKind": "probe",
                        "targetId": "42",
                        "launchedAt": "2026-08-31T10:00:00+00:00",
                        "impactAt": "2026-08-31T10:30:00+00:00",
                    }],
                )
            },
            inventories={"guard": [missile_item("missile-a")]},
        )

        DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None).run_cycle()

        self.assertEqual([], api.missile_launches)
        self.assertEqual([], api.moves)


if __name__ == "__main__":
    unittest.main()
