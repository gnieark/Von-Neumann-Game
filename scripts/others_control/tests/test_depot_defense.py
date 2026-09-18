from __future__ import annotations

import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import Mock, patch

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.defense_etoile.errors import ApiRequestError
from scripts.others_control.tests.support import auxiliary, detailed_scan, missile_item, observed_manny, ship
from scripts.others_control.tests.test_central_defense import incoming_missile
from scripts.others_control.tests.test_relocation import RelocationApi


CENTER = (0, 0, 0)
DEPOT = (2, 0, 0)
PLANET = {"id": "planet", "type": "planet", "harvestable": True}


class DepotDefenseTests(unittest.TestCase):
    def setUp(self):
        self.now = datetime(2026, 9, 18, 12, tzinfo=timezone.utc)
        self.directory = Path(self.enterContext(tempfile.TemporaryDirectory()))
        self.mother = ship("mother", CENTER, ship_type="mothership", deuterium=100)
        self.guards = [ship(f"guard-{index}", DEPOT) for index in range(4)]
        self.api = RelocationApi(
            [self.mother, *self.guards],
            scans={CENTER: detailed_scan(objects=[PLANET]), DEPOT: detailed_scan()},
            inventories={guard["id"]: [missile_item(f"{guard['id']}-{i}") for i in range(2)]
                         for guard in self.guards},
        )
        self.api.known_depots = [DEPOT]
        self.controller = DefenseEtoileAttente(
            self.api, fleet_id="fleet_test", now=lambda: self.now,
            logger=lambda _: None, logistics_state_dir=self.directory,
        )
        self.controller.run_cycle()

    def alert(self, *, activity=True):
        self.api.autonomous_units["guard-0"] = [observed_manny("manny", "probe")]
        return (self.controller.run_activity_cycle() if activity else self.controller.run_cycle())

    def arrive(self):
        for item in self.api.ships:
            if item.get("movement") is not None:
                self.api.arrive(item["id"])
        self.api.autonomous_units.clear()
        self.controller.run_activity_cycle()

    def test_alert_mobilizes_mother_courier_scout_and_other_depot_guards(self):
        other = (4, 0, 0)
        self.api.known_depots.append(other)
        self.api.scans[other] = detailed_scan()
        self.api.ships.extend(ship(f"other-{i}", other) for i in range(4))
        self.controller.run_cycle()
        self.api.ships.extend([ship("courier", CENTER), ship("scout", (6, 0, 0))])
        self.api.scans[CENTER] = detailed_scan()
        self.controller.relocation.start_if_depleted(self.mother)
        self.controller.relocation.state["scoutId"] = "scout"
        with patch.object(self.controller.depots, "reserved_ships", return_value={"courier"}):
            self.alert(activity=False)
        self.assertEqual({("mother", DEPOT), ("courier", DEPOT), ("scout", DEPOT),
                          *((f"other-{i}", DEPOT) for i in range(4))}, set(self.api.moves))
        self.assertIsNone(self.controller.relocation.state)
        self.assertTrue(all(guard["movement"] is None for guard in self.guards))
        self.assertIsNone(self.controller.depot_defense.hold_until)

    def test_activity_alert_starts_movement_without_waiting_for_general_cycle(self):
        self.alert()
        self.assertEqual([("mother", DEPOT)], self.api.moves)
        self.assertEqual(["manny", "probe"], [target for _, _, target in self.api.missile_launches])
        self.controller.run_activity_cycle()
        self.assertEqual([("mother", DEPOT)], self.api.moves)
        self.assertEqual(2, len(self.api.missile_launches))

    def test_probe_alone_does_not_change_guard_engagement_triggers(self):
        self.api.scans[DEPOT] = detailed_scan(probes=[{"id": "probe"}])
        self.controller.run_activity_cycle()
        self.assertIsNone(self.controller.depot_defense.destination)
        self.assertEqual([], self.api.moves)

    def test_transit_busy_and_rejected_moves_are_retried(self):
        busy = ship("busy", CENTER, status="busy")
        travelling = ship("travelling", CENTER)
        self.api.ships.extend([busy, travelling, ship("rejected", CENTER)])
        self.api.move_ship(travelling, (4, 0, 0))
        self.api.move_errors["rejected"] = ApiRequestError(409, "action_conflict", "busy")
        self.alert()
        self.assertEqual([("travelling", (4, 0, 0)), ("mother", DEPOT)], self.api.moves)
        busy["status"] = "inactive"
        self.api.arrive("travelling")
        self.api.move_errors.clear()
        self.controller.run_activity_cycle()
        self.assertEqual({("busy", DEPOT), ("travelling", DEPOT), ("rejected", DEPOT)},
                         set(self.api.moves[2:]))

    def test_deployed_auxiliary_blocks_only_its_ship_until_it_returns(self):
        self.api.ships.append(ship("worker", CENTER))
        self.api.auxiliaries["worker"] = [auxiliary("aux", location_type="sector", status="busy")]
        self.alert()
        self.assertEqual([("mother", DEPOT)], self.api.moves)
        self.api.auxiliaries["worker"] = [auxiliary("aux")]
        self.controller.run_activity_cycle()
        self.assertEqual(("worker", DEPOT), self.api.moves[-1])

    def test_local_fuel_shortage_is_supplied_before_mother_leaves(self):
        self.api.ships.append(ship("empty", CENTER, deuterium=0))
        self.api.auxiliaries["mother"] = [auxiliary("assistant")]
        self.alert()
        self.assertEqual([], self.api.moves)
        self.assertEqual("empty", self.api.deuterium_transfers[0][1])
        self.api.finish_refueling()
        self.controller.run_activity_cycle()
        self.assertEqual({("empty", DEPOT), ("mother", DEPOT)}, set(self.api.moves))

    def test_distant_ships_follow_steps_and_timer_starts_after_last_arrival(self):
        self.api.ships.append(ship("distant", (28, 0, 0)))
        self.alert()
        self.assertIn(("distant", (18, 0, 0)), self.api.moves)
        self.api.arrive("mother")
        self.now += timedelta(hours=3)
        self.controller.run_activity_cycle()
        self.assertIsNone(self.controller.depot_defense.hold_until)
        self.api.arrive("distant")
        self.controller.run_activity_cycle()
        self.assertEqual(("distant", (8, 0, 0)), self.api.moves[-1])
        self.api.arrive("distant")
        self.controller.run_activity_cycle()
        self.assertEqual(("distant", DEPOT), self.api.moves[-1])
        self.api.arrive("distant")
        self.controller.run_activity_cycle()
        self.assertEqual(self.now + timedelta(hours=2), self.controller.depot_defense.hold_until)

    def test_no_redeployment_before_two_hours_even_if_sector_is_empty(self):
        self.alert()
        self.arrive()
        deadline = self.controller.depot_defense.hold_until
        self.controller.relocation.start_if_depleted = Mock(return_value=True)
        self.controller.depots.reconcile = Mock()
        self.now = deadline - timedelta(seconds=1)
        self.controller.run_cycle()
        self.controller.run_activity_cycle()
        self.assertEqual(DEPOT, self.controller.depot_defense.destination)
        self.assertEqual([("mother", DEPOT)], self.api.moves)
        self.controller.relocation.start_if_depleted.assert_not_called()
        self.controller.depots.reconcile.assert_not_called()

    def test_presence_blocks_after_two_hours_then_resource_search_resumes(self):
        self.alert()
        self.arrive()
        self.now = self.controller.depot_defense.hold_until
        self.api.scans[DEPOT] = detailed_scan(probes=[{"id": "probe"}])
        self.controller.run_cycle()
        self.assertEqual(DEPOT, self.controller.depot_defense.destination)
        self.assertTrue(self.controller.central_defense.at_war)
        self.api.scans[DEPOT] = detailed_scan()
        self.controller.run_activity_cycle()
        self.assertIsNone(self.controller.depot_defense.destination)
        self.controller.run_cycle()
        self.assertEqual("searching", self.controller.relocation.state["phase"])

    def test_central_defense_takes_over_without_duplicate_guard_interception(self):
        self.alert()
        self.api.arrive("mother")
        self.api.inventories["mother"] = [missile_item(f"mother-{i}") for i in range(8)]
        self.api.scans[DEPOT] = detailed_scan(probes=[{"id": "probe"}],
                                             objects=[incoming_missile("incoming")])
        self.api.autonomous_units["mother"] = [observed_manny("manny", "probe")]
        before = len(self.api.missile_launches)
        self.controller.run_activity_cycle()
        launches = self.api.missile_launches[before:]
        self.assertEqual(1, sum(target == "incoming" for _, _, target in launches))
        self.assertEqual(4, sum(target == "probe" for _, _, target in launches))
        self.assertEqual(1, len(self.api.laser_locks))
        self.assertTrue(self.controller.central_defense.at_war)
        self.controller.run_activity_cycle()
        self.assertEqual(before + len(launches), len(self.api.missile_launches))
        self.assertEqual([("mother", DEPOT)], self.api.moves)

    def test_central_defense_does_not_duplicate_rally_movement_orders(self):
        self.api.ships.append(ship("late", (3, 1, 0), status="busy"))
        self.alert()
        self.api.arrive("mother")
        self.api.ships[-1]["status"] = "inactive"
        self.api.scans[DEPOT] = detailed_scan(probes=[{"id": "probe"}])
        self.controller.run_activity_cycle()
        self.assertEqual(1, self.api.moves.count(("late", DEPOT)))

    def test_lost_ship_does_not_prevent_arrival(self):
        lost = ship("lost", CENTER, status="busy")
        self.api.ships.append(lost)
        self.alert()
        lost.update(status="destroyed", location={"state": "destroyed"})
        self.arrive()
        self.assertEqual(self.now + timedelta(hours=2), self.controller.depot_defense.hold_until)

    def test_alert_in_mother_sector_still_gathers_distant_fleet(self):
        self.mother["sector"] = self.guards[0]["sector"]
        self.api.ships.append(ship("remote", (6, 0, 0)))
        self.api.scans[DEPOT] = detailed_scan(objects=[incoming_missile("incoming")])
        self.controller.run_cycle()
        self.assertEqual([("remote", DEPOT)], self.api.moves)
        self.assertEqual(DEPOT, self.controller.depot_defense.destination)

    def test_each_guard_trigger_keeps_shots_and_mobilizes_without_retreat(self):
        cases = [
            (incoming_missile("incoming", "guard"), ["incoming", "probe"]),
            ({"id": "ejected", "type": "manny", "mannyUid": "manny"}, ["manny"]),
            ({"id": "asteroid", "type": "asteroid", "trajectory": {
                "id": "trajectory", "mode": "system_impact", "status": "accelerating",
                "targetObjectId": "guard", "targetSpeedC": 0.8,
            }}, ["asteroid", "probe"]),
            ({"id": "item", "type": "drifting_item", "quantity": 1}, ["probe"]),
            ({"id": "container", "type": "detached_container", "quantity": 1}, ["probe"]),
            ({"id": "planet", "type": "planet", "waypointBookmarks": [{"name": "Bonjour"}]}, ["probe"]),
        ]
        for event, targets in cases:
            with self.subTest(event=event["id"]):
                api = RelocationApi(
                    [ship("mother", CENTER, ship_type="mothership"), ship("guard", DEPOT)],
                    scans={CENTER: detailed_scan(objects=[PLANET]), DEPOT: detailed_scan()},
                    inventories={"guard": [missile_item(f"m{i}") for i in range(2)]},
                )
                api.known_depots = [DEPOT]
                controller = DefenseEtoileAttente(api, fleet_id="fleet_test", logger=lambda _: None)
                controller.run_cycle()
                api.scans[DEPOT] = detailed_scan(probes=[{"id": "probe"}], objects=[event])
                controller.run_activity_cycle()
                self.assertEqual(targets, [target for _, _, target in api.missile_launches])
                self.assertEqual([("mother", DEPOT)], api.moves)
                self.assertEqual("guarding", controller.guards.assignments["guard"]["stage"])

    def test_timer_is_not_persisted(self):
        self.alert()
        self.arrive()
        restarted = DefenseEtoileAttente(self.api, fleet_id="fleet_test", logger=lambda _: None,
                                        logistics_state_dir=self.directory, now=lambda: self.now)
        self.assertIsNone(restarted.depot_defense.destination)
        self.assertIsNone(restarted.depot_defense.hold_until)


if __name__ == "__main__":
    unittest.main()
