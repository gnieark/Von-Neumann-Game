from __future__ import annotations

import unittest
from unittest.mock import patch

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.defense_etoile.errors import ApiRequestError
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


def incoming_missile(missile_id: str, target_id: str = "mother") -> dict[str, str]:
    return {
        **moving_missile(missile_id, target_id),
        "launcherKind": "probe",
        "targetKind": "others_ship",
    }


class CentralDefenseTests(unittest.TestCase):
    def test_local_depot_guards_leave_missile_targets_to_central_defense(self) -> None:
        center = (0, 0, 0)
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"),
             *[ship(f"guard-{index}", center) for index in range(4)]],
            scans={center: detailed_scan(objects=[
                incoming_missile("incoming"), incoming_missile("ignored", "guard-0"),
            ])},
            inventories={
                f"guard-{index}": [missile_item(f"missile-{index}")]
                for index in range(4)
            },
        )
        api.known_depots = [center]
        controller = DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None)

        controller.run_cycle()
        controller.run_activity_cycle()

        self.assertEqual([("guard-0", "missile-0", "incoming")], api.missile_launches)

    def test_missiles_alone_trigger_one_interception_each_by_local_escorts(self) -> None:
        center = (0, 0, 0)
        scan = detailed_scan(objects=[
            incoming_missile("incoming-a"), incoming_missile("incoming-b"),
            incoming_missile("ignored", "escort-a"),
        ])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"),
             ship("escort-a", center), ship("escort-b", center),
             ship("remote", (2, 0, 0)), ship("travelling", center, status="transit")],
            scans={center: scan},
            inventories={
                ship_id: [missile_item(f"{ship_id}-{index}") for index in range(count)]
                for ship_id, count in (("mother", 8), ("escort-a", 2), ("escort-b", 2),
                                       ("remote", 8), ("travelling", 8))
            },
        )
        controller = DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None)

        result = controller.run_cycle()
        controller.run_activity_cycle()
        controller.run_cycle()

        self.assertTrue(controller.central_defense.at_war)
        self.assertEqual(2, result.accepted_commands)
        self.assertEqual([
            ("escort-a", "escort-a-0", "incoming-a"),
            ("escort-b", "escort-b-0", "incoming-b"),
        ], api.missile_launches)

        scan["objects"] = [incoming_missile("ignored", "escort-a")]
        controller.run_activity_cycle()
        self.assertFalse(controller.central_defense.at_war)
        self.assertEqual(set(), controller.central_defense.interception_target_ids)
        self.assertEqual(2, len(api.missile_launches))

    def test_interceptions_take_priority_over_probe_screen(self) -> None:
        center = (0, 0, 0)
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("escort", center)],
            scans={center: detailed_scan(
                probes=[{"id": 42}], objects=[incoming_missile("incoming")]
            )},
            inventories={"escort": [missile_item("first"), missile_item("second")]},
        )
        controller = DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None)

        controller.run_cycle()

        self.assertEqual([
            ("escort", "first", "incoming"), ("escort", "second", "42"),
        ], api.missile_launches)

    def test_visible_interceptor_prevents_another_attempt_even_after_disappearing(self) -> None:
        center = (0, 0, 0)
        scan = detailed_scan(objects=[incoming_missile("incoming"), {
            **moving_missile("interceptor", "incoming"), "targetKind": "missile",
        }])
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("escort", center)],
            scans={center: scan}, inventories={"escort": [missile_item("spare")]},
        )
        controller = DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None)

        controller.run_cycle()
        scan["objects"].pop()
        controller.run_activity_cycle()

        self.assertEqual([], api.missile_launches)

    def test_shortage_waits_for_escort_ammunition_without_using_mothership(self) -> None:
        center = (0, 0, 0)
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("escort", center)],
            scans={center: detailed_scan(objects=[incoming_missile("incoming")])},
            inventories={"mother": [missile_item("mother-reserve")]},
        )
        controller = DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None)

        controller.run_cycle()
        self.assertEqual([], api.missile_launches)
        api.inventories["escort"] = [missile_item("new-stock")]
        controller.run_activity_cycle()
        controller.run_activity_cycle()

        self.assertEqual([("escort", "new-stock", "incoming")], api.missile_launches)

    def test_rejected_interception_can_be_attempted_by_another_ship(self) -> None:
        center = (0, 0, 0)
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"),
             ship("escort-a", center), ship("escort-b", center)],
            scans={center: detailed_scan(objects=[incoming_missile("incoming")])},
            inventories={"escort-a": [missile_item("a")], "escort-b": [missile_item("b")]},
        )
        controller = DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None)
        launch = api.launch_missile

        def reject_first(ship_id, *args):
            if ship_id == "escort-a":
                raise ApiRequestError(409, "conflict", "Missile indisponible")
            return launch(ship_id, *args)

        with patch.object(api, "launch_missile", side_effect=reject_first):
            controller.run_cycle()
            controller.run_activity_cycle()

        self.assertEqual([("escort-b", "b", "incoming")], api.missile_launches)

    def test_reserved_local_ship_can_intercept_without_being_recalled(self) -> None:
        center = (0, 0, 0)
        api = FakeApi(
            [ship("mother", center, ship_type="mothership"), ship("shuttle", center)],
            scans={center: detailed_scan(objects=[incoming_missile("incoming")])},
            inventories={"shuttle": [missile_item("shuttle-missile")]},
        )
        controller = DefenseEtoileAttente(api, mothership_id="mother", logger=lambda _: None)

        with patch.object(controller.depots, "reserved_ships", return_value={"shuttle"}):
            controller.run_cycle()

        self.assertEqual([("shuttle", "shuttle-missile", "incoming")], api.missile_launches)
        self.assertEqual([], api.moves)

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

    def test_same_visible_missile_does_not_confirm_several_pending_launches(self) -> None:
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
        scan["objects"] = [moving_missile("launched-missile-1", "42")]

        controller.run_activity_cycle()
        controller.run_activity_cycle()

        self.assertEqual(4, len(api.missile_launches))

    def test_end_of_alert_allows_formation_to_resume_on_general_cycle(self) -> None:
        center = (0, 0, 0)
        scan = detailed_scan(probes=[{"id": 42, "status": "idle"}])
        api = FakeApi(
            [
                ship("mother", center, ship_type="mothership"),
                ship("home", center),
            ],
            scans={center: scan},
        )
        controller = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        )

        controller.run_cycle()
        self.assertEqual([], api.moves)

        scan["probes"] = []
        scan["objects"] = [{"id": "planet", "type": "planet", "harvestable": True}]
        controller.run_cycle()

        self.assertEqual([("home", NEIGHBOR_OFFSETS[0])], api.moves)

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

    def test_missing_laser_action_is_recreated_while_manny_remains(self) -> None:
        center = (0, 0, 0)
        api = FakeApi(
            [ship("mother", center, ship_type="mothership")],
            scans={center: detailed_scan()},
            autonomous_units={"mother": [observed_manny("manny-a", "42")]},
        )
        controller = DefenseEtoileAttente(
            api, mothership_id="mother", logger=lambda _: None
        )
        controller.run_cycle()
        api.actions.clear()

        controller.run_activity_cycle()

        self.assertEqual(
            [("mother", "manny-a"), ("mother", "manny-a")],
            api.laser_locks,
        )


if __name__ == "__main__":
    unittest.main()
