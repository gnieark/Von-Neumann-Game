from __future__ import annotations

import copy
import tempfile
import unittest
from pathlib import Path

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.defense_etoile.errors import ApiRequestError, ConfigurationError
from scripts.others_control.defense_etoile.geometry import NEIGHBOR_OFFSETS, coordinate_distance
from scripts.others_control.defense_etoile.models import CycleResult
from scripts.others_control.defense_etoile.relocation import FleetRelocationCoordinator, second_ring
from scripts.others_control.tests.support import FakeApi, auxiliary, detailed_scan, movement, sector, ship


CENTER = (0, 0, 0)
PLANET = {"id": "planet", "type": "planet", "harvestable": True}


class RelocationApi(FakeApi):
    """Les déplacements et pleins ne terminent que sur demande du test."""
    def get_fleet(self, fleet_id):
        return copy.deepcopy(super().get_fleet(fleet_id))

    def move_ship(self, item, target):
        action = super().move_ship(item, target)
        live = next(value for value in self.ships if value["id"] == item["id"])
        live.update(movement=movement(target), status="preparing")
        live["deuterium"]["amount"] -= 2
        return action

    def arrive(self, ship_id):
        live = next(value for value in self.ships if value["id"] == ship_id)
        live["sector"] = {"relative": live["movement"]["target"]}
        live.update(movement=None, status="inactive", location={"state": "in_sector"})

    def finish_refueling(self):
        source, target, actor, amount = self.deuterium_transfers[-1]
        for value in self.ships:
            if value["id"] == source:
                value["deuterium"]["amount"] -= amount
            if value["id"] == target:
                value["deuterium"]["amount"] += amount
        for action in self.active_actions:
            if action["type"] == "deuterium_transfer":
                action["status"] = "succeeded"
        for value in self.auxiliaries[source]:
            if value["id"] == actor:
                value.update(status="inactive", action=None)


class RelocationTests(unittest.TestCase):
    def setUp(self):
        self.directory = Path(self.enterContext(tempfile.TemporaryDirectory()))
        self.logs = []
        self.mother = ship("mother", CENTER, ship_type="mothership", deuterium=100)
        self.scout = ship("scout", CENTER)
        self.api = RelocationApi([self.mother, self.scout], scans={CENTER: detailed_scan()},
                                 auxiliaries={"mother": [auxiliary("assistant")]})
        self.worker = self.restart()

    def restart(self):
        worker = FleetRelocationCoordinator(self.api, logger=self.logs.append, state_dir=self.directory)
        worker.load("fleet_test")
        return worker

    def cycle(self, *, reserved=None, depot_busy=False):
        self.worker.start_if_depleted(self.mother)
        result = CycleResult()
        self.worker.reconcile(self.mother, copy.deepcopy(self.api.ships), list(self.api.active_actions),
                              reserved or set(), depot_busy, result)
        return result

    def test_current_resources_or_uncertain_scan_do_not_trigger_relocation(self):
        for scan in (detailed_scan(objects=[PLANET]), {"knowledgeLevel": "neighbor_scan"},
                     ApiRequestError(400, "insufficient_scan_data", "scan unavailable")):
            with self.subTest(scan=scan):
                self.api.scans[CENTER] = scan
                self.assertFalse(self.worker.start_if_depleted(self.mother))
                self.assertIsNone(self.worker.state)

    def test_first_occupied_harvestable_neighbor_wins_in_formation_order(self):
        first, second = NEIGHBOR_OFFSETS[:2]
        self.api.ships.extend([ship("z-guard", first), ship("a-guard", second)])
        self.api.scans[first] = detailed_scan(objects=[{"type": "solar_system", "minableTargets": [PLANET]}])
        self.api.scans[second] = detailed_scan(objects=[PLANET])
        self.cycle()
        self.assertEqual(dict(zip(("x", "y", "z"), first)), self.worker.state["destination"])
        self.assertEqual({("mother", first), ("scout", first), ("a-guard", first)}, set(self.api.moves))
        self.assertNotIn(second, self.api.scan_calls)

    def test_unoccupied_neighbor_is_not_a_candidate(self):
        self.api.scans[NEIGHBOR_OFFSETS[0]] = detailed_scan(objects=[PLANET])
        self.cycle()
        self.assertEqual([("scout", second_ring(CENTER)[0])], self.api.moves)
        self.assertNotIn(NEIGHBOR_OFFSETS[0], self.api.scan_calls)

    def test_local_ship_is_preferred_to_a_sentinel(self):
        point = NEIGHBOR_OFFSETS[0]
        self.api.ships.append(ship("a-guard", point))
        self.api.scans[point] = detailed_scan()
        self.cycle()
        self.assertEqual("scout", self.worker.state["scoutId"])
        self.assertEqual([("scout", second_ring(CENTER)[0])], self.api.moves)

    def test_sentinel_is_used_when_no_local_ship_is_available(self):
        point = NEIGHBOR_OFFSETS[0]
        self.scout["status"] = "busy"
        self.api.ships.append(ship("guard", point))
        self.api.scans[point] = detailed_scan()
        self.cycle()
        self.assertEqual([("guard", second_ring(CENTER)[0])], self.api.moves)

    def test_second_ring_matches_game_distance_and_fcc_parity(self):
        center = (10, -3, 1)
        ring = second_ring(center)
        self.assertEqual(50, len(set(ring)))
        self.assertTrue(all(coordinate_distance(center, point) == 2 and sum(point) % 2 == 0 for point in ring))
        self.assertIn((12, -1, 3), ring)

    def test_exploration_waits_for_arrival_and_resumes_next_sector_after_restart(self):
        first, second = second_ring(CENTER)[:2]
        self.cycle()
        self.worker = self.restart()
        self.cycle()
        self.assertEqual([("scout", first)], self.api.moves)
        self.api.arrive("scout")
        self.api.scans[first] = detailed_scan()
        self.cycle()
        self.assertEqual(("scout", second), self.api.moves[-1])
        self.worker = self.restart()
        self.assertEqual([dict(zip(("x", "y", "z"), first))], self.worker.state["visited"])

    def test_less_than_four_returns_waits_for_full_tank_then_resumes_search(self):
        first, second = second_ring(CENTER)[:2]
        self.scout["deuterium"] = {"amount": 4, "capacity": 10}
        self.cycle()
        self.api.arrive("scout")
        self.api.scans[first] = detailed_scan()
        self.cycle()
        self.assertEqual(("scout", CENTER), self.api.moves[-1])
        self.worker = self.restart()
        self.api.arrive("scout")
        self.cycle()
        self.assertEqual([("mother", "scout", "assistant", 10.0)], self.api.deuterium_transfers)
        self.worker = self.restart()
        self.cycle()
        self.assertEqual(1, len(self.api.deuterium_transfers))
        self.assertEqual(2, len(self.api.moves))
        self.api.finish_refueling()
        self.cycle()
        self.assertEqual(("scout", second), self.api.moves[-1])

    def test_exactly_four_points_allows_the_next_search_move(self):
        self.scout["deuterium"]["amount"] = 6
        self.cycle()
        self.api.arrive("scout")
        self.api.scans[second_ring(CENTER)[0]] = detailed_scan()
        self.cycle()
        self.assertEqual(("scout", second_ring(CENTER)[1]), self.api.moves[-1])

    def test_low_fuel_returns_even_without_detailed_scan(self):
        self.scout["deuterium"]["amount"] = 4
        self.cycle()
        self.api.arrive("scout")
        self.cycle()
        self.assertEqual(("scout", CENTER), self.api.moves[-1])
        self.assertEqual([], self.worker.state["visited"])

    def test_unknown_local_scan_is_not_recorded_as_empty(self):
        self.cycle()
        self.api.arrive("scout")
        self.cycle()
        self.assertEqual(1, len(self.api.moves))
        self.assertEqual([], self.worker.state["visited"])

    def test_busy_or_reserved_scout_is_not_assigned(self):
        self.cycle(reserved={"scout"})
        self.assertEqual([], self.api.moves)
        self.assertIsNone(self.worker.state["scoutId"])

    def test_finding_a_second_ring_planet_moves_the_fleet_and_finishes_only_on_arrival(self):
        target = second_ring(CENTER)[0]
        self.cycle()
        self.api.arrive("scout")
        self.api.scans[target] = detailed_scan(objects=[PLANET])
        self.cycle()
        self.assertEqual(("mother", target), self.api.moves[-1])
        self.worker = self.restart()
        self.cycle()
        self.assertEqual("travelling", self.worker.state["phase"])
        self.api.arrive("mother")
        self.cycle()
        self.assertIsNone(self.worker.state)
        self.assertIsNone(self.restart().state)

    def test_pending_auxiliary_work_and_depot_mission_delay_departure(self):
        target = NEIGHBOR_OFFSETS[0]
        self.scout["sector"] = sector(target)
        self.api.scans[target] = detailed_scan(objects=[PLANET])
        self.cycle(reserved={"scout"}, depot_busy=True)
        self.assertEqual("preparing", self.worker.state["phase"])
        self.assertEqual([], self.api.moves)
        self.api.auxiliaries["mother"] = [auxiliary("worker", status="busy", action={
            "type": "harvest", "status": "running", "endsAt": "2099-01-01T00:00:00+00:00"})]
        self.assertTrue(self.cycle().event_dates)
        self.assertEqual([], self.api.moves)
        self.api.auxiliaries["mother"] = [auxiliary("worker")]
        self.cycle()
        self.assertEqual([("mother", target)], self.api.moves)

    def test_empty_second_ring_returns_scout_and_does_not_repeat_exploration(self):
        self.worker.start_if_depleted(self.mother)
        self.worker.state["scoutId"] = "scout"
        self.worker.state["visited"] = [dict(zip(("x", "y", "z"), point)) for point in second_ring(CENTER)]
        self.worker._save()
        self.worker = self.restart()
        self.cycle()
        self.cycle()
        self.assertEqual([], self.api.moves)
        self.assertEqual([], self.api.deuterium_transfers)

    def test_invalid_persistent_state_is_not_silently_discarded(self):
        self.worker.start_if_depleted(self.mother)
        self.worker.path.write_text('{"phase": "unknown"}')
        with self.assertRaises(ConfigurationError):
            self.restart()

    def test_refueling_for_relocation_preserves_mothership_departure_fuel(self):
        target = NEIGHBOR_OFFSETS[0]
        self.api.ships.append(ship("guard", target))
        self.api.scans[target] = detailed_scan(objects=[PLANET])
        self.scout["deuterium"]["amount"] = 0
        self.mother["deuterium"]["amount"] = 5
        self.cycle()
        self.assertEqual([("mother", "scout", "assistant", 3.0)], self.api.deuterium_transfers)
        self.assertEqual([], self.api.moves)
        self.api.finish_refueling()
        self.cycle()
        self.assertEqual({("scout", target), ("mother", target)}, set(self.api.moves))

    def test_controller_excludes_explorer_from_formation_and_activity_recall(self):
        controller = DefenseEtoileAttente(self.api, mothership_id="mother", logger=self.logs.append,
                                         logistics_state_dir=self.directory)
        controller.run_cycle()
        self.api.arrive("scout")
        first = second_ring(CENTER)[0]
        self.api.scans[first] = detailed_scan()
        controller.run_activity_cycle()
        self.assertEqual([("scout", first)], self.api.moves)
        controller.run_cycle()
        self.assertEqual(("scout", second_ring(CENTER)[1]), self.api.moves[-1])
        self.assertFalse(controller.formation.activity_guards)

    def test_missing_explorer_is_replaced_without_forgetting_visited_sectors(self):
        first, second = second_ring(CENTER)[:2]
        self.cycle()
        self.api.arrive("scout")
        self.api.scans[first] = detailed_scan()
        self.cycle()
        self.api.ships.remove(self.scout)
        self.api.ships.append(ship("replacement", CENTER))
        self.worker = self.restart()
        self.cycle()
        self.assertEqual(("replacement", second), self.api.moves[-1])
        self.assertEqual("replacement", self.worker.state["scoutId"])

    def test_controller_retries_stragglers_while_mother_is_in_transit_after_restart(self):
        target = NEIGHBOR_OFFSETS[0]
        self.api.ships.append(ship("guard", target))
        self.api.scans[target] = detailed_scan(objects=[PLANET])
        self.api.move_errors["scout"] = ApiRequestError(409, "action_conflict", "retry")
        def controller():
            return DefenseEtoileAttente(self.api, mothership_id="mother", logger=self.logs.append,
                                       logistics_state_dir=self.directory)
        first = controller()
        first.run_cycle()
        self.assertEqual([("mother", target)], self.api.moves)
        self.api.move_errors.clear()
        resumed = controller()
        resumed.run_cycle()
        self.assertEqual(("scout", target), self.api.moves[-1])
        self.api.arrive("mother")
        resumed.run_cycle()
        self.assertEqual(2, len(self.api.moves))
        self.assertIsNotNone(resumed.relocation.state)
        self.api.arrive("scout")
        resumed.run_cycle()
        self.assertIsNone(resumed.relocation.state)
        resumed.run_cycle()
        self.assertTrue(any(point != target for _, point in self.api.moves[2:]))
        self.assertEqual([], self.api.craft_starts)


if __name__ == "__main__":
    unittest.main()
