from __future__ import annotations

import copy
import tempfile
import unittest
from unittest.mock import Mock
from datetime import datetime, timedelta, timezone
from pathlib import Path

from scripts.others_control.defense_etoile.commands import CommandExecutor
from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.defense_etoile.depot_guards import DepotGuardCoordinator
from scripts.others_control.defense_etoile.engagement import EngagementCoordinator
from scripts.others_control.defense_etoile.errors import ApiRequestError, ConfigurationError
from scripts.others_control.defense_etoile.models import CycleResult, DefensePolicy
from scripts.others_control.defense_etoile.observation import ScoutObserver
from scripts.others_control.tests.support import auxiliary, detailed_scan, missile_item, observed_manny, sector, ship
from scripts.others_control.tests.test_relocation import RelocationApi


CENTER = (0, 0, 0)
DEPOT = (2, 0, 0)
PLANET = {"id": "planet", "type": "planet", "harvestable": True}


class DepotGuardTests(unittest.TestCase):
    def setUp(self):
        self.directory = Path(self.enterContext(tempfile.TemporaryDirectory()))
        self.logs = []
        self.now = datetime(2026, 9, 14, 12, tzinfo=timezone.utc)
        self.mother = ship("mother", CENTER, ship_type="mothership", deuterium=100)
        self.api = RelocationApi([self.mother], scans={CENTER: detailed_scan(objects=[PLANET]), DEPOT: detailed_scan()})
        self.api.known_depots = [DEPOT]
        self.worker = self.restart()

    def restart(self):
        commands = CommandExecutor(self.api, self.logs.append)
        engagement = EngagementCoordinator(ScoutObserver(self.api), commands, policy=DefensePolicy(),
                                            logger=self.logs.append, now=lambda: self.now)
        worker = DepotGuardCoordinator(self.api, commands, engagement, logger=self.logs.append,
                                       state_dir=self.directory)
        worker.load("fleet_test")
        return worker

    def add_ships(self, count, *, position=CENTER, missiles=1, prefix="guard"):
        values = [ship(f"{prefix}-{index}", position) for index in range(count)]
        self.api.ships.extend(values)
        for value in values:
            self.api.inventories[value["id"]] = [missile_item(f"{value['id']}-m{index}") for index in range(missiles)]
        return values

    def cycle(self, *, excluded=None, allow_assignments=True):
        result = CycleResult()
        self.worker.reconcile(self.mother, copy.deepcopy(self.api.ships), result, excluded=excluded or set(),
                              allow_assignments=allow_assignments)
        return result

    def controller(self):
        return DefenseEtoileAttente(self.api, mothership_id="mother", logger=self.logs.append,
                                   logistics_state_dir=self.directory, now=lambda: self.now)

    def test_four_guards_per_distinct_sector_and_one_assignment_per_ship(self):
        second = (4, 0, 0)
        self.api.known_depots = [DEPOT, DEPOT, second]
        self.add_ships(9)
        self.cycle()
        self.assertEqual(8, len(self.worker.assignments))
        self.assertEqual(4, len(self.worker._slots(DEPOT)))
        self.assertEqual(4, len(self.worker._slots(second)))
        self.assertEqual(8, len(self.api.moves))
        self.assertNotIn("mother", self.worker.assignments)

    def test_inbound_guards_keep_their_slots_after_restart(self):
        self.add_ships(5)
        self.cycle()
        first = set(self.worker.assignments)
        self.worker = self.restart()
        self.cycle()
        self.assertEqual(first, set(self.worker.assignments))
        self.assertEqual(4, len(self.api.moves))

    def test_shortage_and_reserved_couriers_do_not_create_phantom_guards(self):
        self.add_ships(3)
        self.cycle(excluded={"guard-0"})
        self.assertEqual({"guard-1", "guard-2"}, set(self.worker.assignments))
        self.assertEqual(2, len(self.api.moves))

    def test_residents_are_kept_and_no_fifth_guard_is_assigned(self):
        self.add_ships(5, position=DEPOT)
        self.cycle()
        self.assertEqual(4, len(self.worker.assignments))
        self.assertEqual([], self.api.moves)

    def test_only_a_strictly_better_armed_ship_can_relieve_a_guard(self):
        self.add_ships(4, position=DEPOT, missiles=2)
        self.add_ships(1, missiles=2, prefix="equal")
        self.cycle()
        self.assertEqual([], self.api.moves)
        self.add_ships(1, missiles=3, prefix="better")
        self.cycle()
        self.assertEqual([("better-0", DEPOT)], self.api.moves)
        self.assertEqual("guard-0", self.worker.assignments["better-0"]["replaces"])
        self.assertEqual("guarding", self.worker.assignments["guard-0"]["stage"])
        self.worker = self.restart()
        self.cycle()
        self.assertEqual(1, len(self.api.moves))
        self.api.arrive("better-0")
        self.cycle()
        self.assertEqual(("guard-0", CENTER), self.api.moves[-1])
        self.assertEqual("returning", self.worker.assignments["guard-0"]["stage"])
        self.assertEqual(4, len(self.worker._slots(DEPOT)))
        self.api.arrive("guard-0")
        self.cycle()
        self.assertNotIn("guard-0", self.worker.assignments)
        self.assertIn("guard-0", self.worker.claimed_ships)

    def test_failed_relief_departure_keeps_incumbent_and_retries_same_assignment(self):
        self.add_ships(4, position=DEPOT)
        self.cycle()
        self.add_ships(1, missiles=3, prefix="better")
        self.api.move_errors["better-0"] = ApiRequestError(409, "action_conflict", "busy")
        self.cycle()
        self.assertEqual([], self.api.moves)
        self.assertEqual("guarding", self.worker.assignments["guard-0"]["stage"])
        self.worker = self.restart()
        self.api.move_errors.clear()
        self.cycle()
        self.assertEqual([("better-0", DEPOT)], self.api.moves)
        self.assertEqual(5, len(self.worker.assignments))

    def test_all_four_guards_fire_on_manny_without_retreat(self):
        guards = self.add_ships(4, position=DEPOT, missiles=2)
        self.api.scans[DEPOT] = detailed_scan(probes=[{"id": "probe"}])
        self.api.autonomous_units = {guard["id"]: [observed_manny("manny", "probe")] for guard in guards}
        self.cycle()
        self.assertEqual(8, len(self.api.missile_launches))
        for guard in guards:
            self.assertEqual(["manny", "probe"], [target for actor, _, target in self.api.missile_launches if actor == guard["id"]])
            self.assertEqual("guarding", self.worker.assignments[guard["id"]]["stage"])
        self.assertEqual([], self.api.moves)
        self.assertEqual({DEPOT}, self.worker.threatened_sectors)

    def test_activity_polling_keeps_guards_on_station_without_duplicate_shots(self):
        guards = self.add_ships(4, position=DEPOT, missiles=2)
        self.cycle()
        self.api.scans[DEPOT] = detailed_scan(probes=[{"id": "probe"}])
        self.api.autonomous_units = {guard["id"]: [observed_manny("manny", "probe")] for guard in guards}
        result = CycleResult()
        self.worker.reconcile_activity(result)
        self.worker.reconcile_activity(result)
        self.assertEqual(8, len(self.api.missile_launches))
        self.assertEqual([], self.api.moves)
        self.assertEqual(4, len(self.worker.activity_guards))

    def test_laser_engagement_never_causes_retreat(self):
        guards = self.add_ships(4, position=DEPOT, missiles=0)
        self.cycle()
        self.add_ships(1, missiles=3, prefix="better")
        self.api.autonomous_units = {guard["id"]: [observed_manny("manny", "probe")] for guard in guards}
        self.cycle()
        self.assertEqual(4, len(self.api.laser_locks))
        self.assertEqual([], self.api.moves)
        self.now += timedelta(seconds=600)
        self.worker.reconcile_activity(CycleResult())
        self.assertEqual([], self.api.moves)
        self.now += timedelta(seconds=1)
        self.worker.reconcile_activity(CycleResult())
        self.assertEqual([], self.api.moves)
        self.assertEqual(4, len(self.api.laser_locks))

    def test_distant_deployment_uses_steps_and_guard_stays_when_attacked(self):
        distant = (26, 0, 0)
        self.api.known_depots = [distant]
        guards = self.add_ships(1, missiles=2)
        self.cycle()
        self.assertEqual([("guard-0", (10, 0, 0))], self.api.moves)
        for expected in ((20, 0, 0), distant):
            self.api.arrive("guard-0")
            self.worker = self.restart()
            self.cycle()
            self.assertEqual(("guard-0", expected), self.api.moves[-1])
        self.api.arrive("guard-0")
        self.api.scans[distant] = detailed_scan(probes=[{"id": "probe"}])
        self.api.autonomous_units[guards[0]["id"]] = [observed_manny("manny", "probe")]
        self.cycle()
        self.assertEqual(3, len(self.api.moves))
        self.assertEqual("guarding", self.worker.assignments["guard-0"]["stage"])
        self.assertEqual({distant}, self.worker.threatened_sectors)

    def test_insufficient_round_trip_fuel_prevents_distant_assignment(self):
        self.api.known_depots = [(26, 0, 0)]
        guards = self.add_ships(1)
        guards[0]["deuterium"]["amount"] = 6
        self.cycle()
        self.assertEqual({}, self.worker.assignments)
        self.assertEqual([], self.api.moves)

    def test_lost_guard_is_replaced_and_does_not_hold_a_slot(self):
        guards = self.add_ships(4, position=DEPOT)
        self.cycle()
        self.api.ships.remove(guards[0])
        self.add_ships(1, prefix="replacement")
        self.worker = self.restart()
        self.cycle()
        self.assertNotIn("guard-0", self.worker.assignments)
        self.assertEqual([("replacement-0", DEPOT)], self.api.moves)
        self.assertEqual(4, len(self.worker._slots(DEPOT)))

    def test_guard_at_mothership_sector_stays_on_station(self):
        self.api.known_depots = [CENTER]
        self.add_ships(4)
        self.api.autonomous_units = {f"guard-{index}": [observed_manny("manny", "probe")] for index in range(4)}
        self.cycle()
        self.assertEqual(4, len(self.api.missile_launches))
        self.assertEqual([], self.api.moves)
        self.assertTrue(all(value["stage"] == "guarding" for value in self.worker.assignments.values()))

    def test_controller_does_not_add_a_fifth_sentinel_to_a_neighbor_depot(self):
        neighbor = (1, 1, 0)
        self.api.known_depots = [neighbor]
        self.api.scans[neighbor] = detailed_scan()
        self.add_ships(5)
        controller = self.controller()
        controller.run_cycle()
        self.assertEqual(4, sum(target == neighbor for _, target in self.api.moves))
        self.assertEqual(4, len(controller.guards.assignments))
        self.assertEqual(5, len(self.api.moves))

    def test_central_alert_does_not_recall_depot_guards_but_their_watch_continues(self):
        guards = self.add_ships(4, position=DEPOT, missiles=2)
        controller = self.controller()
        controller.run_cycle()
        self.api.scans[CENTER] = detailed_scan(probes=[{"id": "central-probe"}], objects=[PLANET])
        controller.run_activity_cycle()
        self.assertEqual([], self.api.moves)
        self.api.autonomous_units = {guard["id"]: [observed_manny("manny", "probe")] for guard in guards}
        controller.run_activity_cycle()
        self.assertEqual(8, len(self.api.missile_launches))
        self.assertEqual([("mother", DEPOT)], self.api.moves)

    def test_guards_stay_at_depot_during_relocation_and_do_not_block_completion(self):
        target = (1, 1, 0)
        guards = self.add_ships(4, position=DEPOT)
        self.add_ships(1, position=target, prefix="scout")
        self.api.scans[target] = detailed_scan(objects=[PLANET])
        controller = self.controller()
        controller.run_cycle()
        self.api.scans[CENTER] = detailed_scan()
        controller.run_cycle()
        self.assertEqual([("mother", target)], self.api.moves)
        self.assertEqual("travelling", controller.relocation.state["phase"])
        self.api.arrive("mother")
        controller = self.controller()
        controller.run_cycle()
        self.assertIsNone(controller.relocation.state)
        self.assertTrue(all(guard["sector"] == sector(DEPOT) for guard in guards))
        self.assertEqual(4, len(controller.guards.assignments))

    def test_moving_mothership_does_not_suspend_guard_activity_after_restart(self):
        guards = self.add_ships(4, position=DEPOT, missiles=2)
        self.controller().run_cycle()
        target = (4, 0, 0)
        self.api.move_ship(self.mother, target)
        self.api.moves.clear()
        self.api.autonomous_units = {guard["id"]: [observed_manny("manny", "probe")] for guard in guards}
        resumed = self.controller()
        resumed.run_cycle()
        self.assertEqual(8, len(self.api.missile_launches))
        self.assertEqual([], self.api.moves)
        self.assertEqual(DEPOT, resumed.depot_defense.destination)
        self.api.arrive("mother")
        resumed.run_activity_cycle()
        self.assertEqual([("mother", DEPOT)], self.api.moves)

    def test_invalid_guard_journal_fails_without_losing_assignments(self):
        self.add_ships(4)
        self.cycle()
        self.worker.path.write_text('{"guard-0": {"stage": "unknown"}}')
        with self.assertRaises(ConfigurationError):
            self.restart()

    def test_guarded_neighbor_remains_a_relocation_candidate_without_moving_its_guards(self):
        neighbor = (1, 1, 0)
        self.api.known_depots = [neighbor]
        self.api.scans[neighbor] = detailed_scan(objects=[PLANET])
        guards = self.add_ships(4, position=neighbor)
        controller = self.controller()
        controller.run_cycle()
        self.api.scans[CENTER] = detailed_scan()
        controller.run_cycle()
        self.assertEqual([("mother", neighbor)], self.api.moves)
        self.assertTrue(all(guard["movement"] is None for guard in guards))

    def test_local_guards_receive_maintenance_and_remote_missiles_count_toward_fleet_stock(self):
        self.api.known_depots = [CENTER, DEPOT]
        self.add_ships(4, position=CENTER, missiles=0, prefix="local")
        self.add_ships(4, position=DEPOT, missiles=2, prefix="remote")
        self.api.auxiliaries["mother"] = [auxiliary("assistant")]
        self.api.inventories["mother"] = [missile_item(f"stock-{index}") for index in range(11)]
        controller = self.controller()
        controller.logistics.reconcile = Mock()
        controller.run_cycle()
        self.assertEqual("local-0", self.api.inventory_transfers[0][1])
        self.assertEqual(19, controller.logistics.reconcile.call_args.kwargs["fleet_missile_stock"])
        self.assertEqual([], self.api.moves)

    def test_lost_relief_ship_keeps_old_guard_and_allows_another_replacement(self):
        self.add_ships(4, position=DEPOT)
        self.cycle()
        first = self.add_ships(1, missiles=3, prefix="first")[0]
        self.cycle()
        self.api.ships.remove(first)
        self.add_ships(1, missiles=3, prefix="second")
        self.worker = self.restart()
        self.cycle()
        self.assertEqual("guarding", self.worker.assignments["guard-0"]["stage"])
        self.assertEqual("guard-0", self.worker.assignments["second-0"]["replaces"])
        self.assertEqual(("second-0", DEPOT), self.api.moves[-1])


if __name__ == "__main__":
    unittest.main()
