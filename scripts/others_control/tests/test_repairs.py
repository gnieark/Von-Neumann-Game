from __future__ import annotations

from decimal import Decimal
import unittest
from unittest.mock import patch

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.defense_etoile.errors import ApiContractError, ApiRequestError
from scripts.others_control.defense_etoile.geometry import NEIGHBOR_OFFSETS
from scripts.others_control.defense_etoile.models import CycleResult
from scripts.others_control.defense_etoile.repairs import FleetRepairCoordinator
from scripts.others_control.tests.support import FakeApi, auxiliary, detailed_scan, missile_item, movement, ship


class RepairTests(unittest.TestCase):
    def setUp(self) -> None:
        self.center = (0, 0, 0)
        self.mother = ship("mother", self.center, ship_type="mothership")
        self.logs: list[str] = []

    def reconcile(self, api: FakeApi) -> CycleResult:
        result = CycleResult()
        FleetRepairCoordinator(api, logger=self.logs.append).reconcile(self.mother, api.ships, result)
        return result

    def test_mothership_and_remote_ship_repair_using_one_free_embarked_auxiliary(self) -> None:
        self.mother["integrity"] = 96
        guard = ship("guard", NEIGHBOR_OFFSETS[0], integrity=18)
        api = FakeApi(
            [self.mother, guard], resources={"mother": {"metals": .04}, "guard": {"metals": .02}},
            auxiliaries={"mother": [auxiliary("a-deployed", location_type="deployed"), auxiliary("b"), auxiliary("c")],
                         "guard": [auxiliary("d")]},
        )
        result = self.reconcile(api)
        self.assertEqual([("mother", "b", 4), ("guard", "d", 2)], api.repair_starts)
        self.assertEqual(2, result.accepted_commands)
        self.assertEqual(2, len(result.event_dates))
        # A fresh coordinator must reconstruct pending repairs from the API.
        again = self.reconcile(api)
        self.assertEqual(0, again.accepted_commands)
        self.assertEqual(2, len(again.event_dates))
        self.assertEqual(2, len(api.repair_starts))

    def test_delivers_only_missing_available_metals_then_repairs_after_delivery(self) -> None:
        damaged = ship("damaged", self.center, integrity=15)
        api = FakeApi(
            [self.mother, damaged], resources={"mother": {"metals": 1}, "damaged": {"metals": .04}},
            auxiliaries={"mother": [auxiliary("m1"), auxiliary("m2")], "damaged": [auxiliary("d1")]},
        )
        api.resource_reservations["damaged"] = {"metals": .02}
        self.reconcile(api)
        self.assertEqual([("mother", "damaged", "m1", "metals", .03)], api.resource_transfers)
        self.assertEqual([], api.repair_starts)
        self.assertEqual(0, self.reconcile(api).accepted_commands)
        self.assertEqual(1, len(api.resource_transfers))
        api.resources["damaged"]["metals"] += .03
        api.resources["mother"]["metals"] -= .03
        api.resource_reservations["mother"]["metals"] = 0
        api.auxiliaries["mother"][0].update(status="inactive", action=None)
        self.reconcile(api)
        self.assertEqual([("damaged", "d1", 5)], api.repair_starts)
        self.assertEqual(1, len(api.resource_transfers))

    def test_source_repairs_first_and_uses_another_auxiliary_for_delivery(self) -> None:
        self.mother["integrity"] = 90
        damaged = ship("damaged", self.center, integrity=18)
        api = FakeApi(
            [damaged, self.mother], resources={"mother": {"metals": .12}},
            auxiliaries={"mother": [auxiliary("m1"), auxiliary("m2")]},
        )
        self.reconcile(api)
        self.assertEqual([("mother", "m1", 10)], api.repair_starts)
        self.assertEqual([("mother", "damaged", "m2", "metals", .02)], api.resource_transfers)

    def test_source_stock_is_not_overcommitted_between_recipients(self) -> None:
        api = FakeApi(
            [self.mother, ship("a", self.center, integrity=17), ship("b", self.center, integrity=17)],
            resources={"mother": {"metals": .05}}, auxiliaries={"mother": [auxiliary("m1"), auxiliary("m2")]},
        )
        self.reconcile(api)
        self.assertEqual([("mother", "a", "m1", "metals", .03)], api.resource_transfers)

    def test_reserved_source_metals_and_target_capacity_are_respected(self) -> None:
        damaged = ship("damaged", self.center, integrity=18)
        api = FakeApi([self.mother, damaged], resources={"mother": {"metals": 1}}, auxiliaries={"mother": [auxiliary("m1")]})
        api.resource_reservations["mother"] = {"metals": 1}
        self.reconcile(api)
        self.assertEqual([], api.resource_transfers)
        api.resource_reservations.clear()
        original = api.get_inventory
        def no_capacity(ship_id: str) -> dict:
            inventory = original(ship_id)
            if ship_id == "damaged":
                inventory.update(capacityEce=1, usedEce=.99)
            return inventory
        with patch.object(api, "get_inventory", side_effect=no_capacity):
            self.reconcile(api)
        self.assertEqual([], api.resource_transfers)

    def test_inventory_reread_detects_a_delivery_completed_during_the_cycle(self) -> None:
        api = FakeApi([self.mother, ship("damaged", self.center, integrity=18)],
                      resources={"mother": {"metals": 1}}, auxiliaries={"mother": [auxiliary("m1")]})
        original = api.get_inventory
        reads = 0
        def arriving_delivery(ship_id: str) -> dict:
            nonlocal reads
            if ship_id == "damaged":
                reads += 1
                if reads == 2:
                    api.resources["damaged"] = {"metals": .02}
            return original(ship_id)
        with patch.object(api, "get_inventory", side_effect=arriving_delivery):
            self.reconcile(api)
        self.assertEqual([], api.resource_transfers)

    def test_repair_waits_for_full_stock_and_an_available_auxiliary(self) -> None:
        self.mother["integrity"] = 90
        api = FakeApi([self.mother], resources={"mother": {"metals": .09}}, auxiliaries={"mother": [auxiliary("m1")]})
        self.reconcile(api)
        self.assertEqual([], api.repair_starts)
        api.resources["mother"]["metals"] = .1
        api.auxiliaries["mother"][0]["status"] = "busy"
        self.reconcile(api)
        self.assertEqual([], api.repair_starts)

    def test_does_not_supply_remote_moving_destroyed_or_foreign_ships(self) -> None:
        remote = ship("remote", NEIGHBOR_OFFSETS[0], integrity=19)
        moving = ship("moving", self.center, integrity=19, movement=movement(NEIGHBOR_OFFSETS[0]))
        dead = ship("dead", self.center, integrity=0)
        dead["location"]["state"] = "destroyed"
        foreign = ship("foreign", self.center, integrity=19)
        foreign["fleetId"] = "foreign"
        api = FakeApi([self.mother, remote, moving, dead, foreign], resources={"mother": {"metals": 10}},
                      auxiliaries={"mother": [auxiliary("m1")]})
        self.reconcile(api)
        self.assertEqual([], api.resource_transfers)
        self.assertEqual([], api.repair_starts)

    def test_active_inventory_transfer_delays_supply_after_restart(self) -> None:
        action = {"id": "transfer", "type": "inventory_transfer", "status": "queued", "endsAt": "2099-01-01T00:00:00+00:00"}
        api = FakeApi([self.mother, ship("damaged", self.center, integrity=19)], resources={"mother": {"metals": 1}},
                      auxiliaries={"mother": [auxiliary("busy", status="busy", action=action), auxiliary("free")]})
        self.assertEqual(1, len(self.reconcile(api).event_dates))
        self.assertEqual([], api.resource_transfers)

    def test_server_conflicts_are_deferred_but_authentication_errors_propagate(self) -> None:
        self.mother["integrity"] = 99
        api = FakeApi([self.mother], resources={"mother": {"metals": 1}}, auxiliaries={"mother": [auxiliary("m1")]})
        for status in (404, 409, 422):
            with patch.object(api, "start_repair", side_effect=ApiRequestError(status, "conflict", "Changed")):
                self.assertEqual(0, self.reconcile(api).accepted_commands)
        with patch.object(api, "start_repair", side_effect=ApiRequestError(403, "forbidden", "Denied")):
            with self.assertRaises(ApiRequestError):
                self.reconcile(api)

    def test_configured_repair_cost_uses_server_rounding(self) -> None:
        coordinator = FleetRepairCoordinator(FakeApi([]), logger=self.logs.append, metals_per_point=.01235)
        self.assertEqual(Decimal("0.0247"), coordinator.cost(2))
        self.assertEqual(Decimal("0.0124"), coordinator.cost(1))

    def test_missing_integrity_is_an_invalid_contract(self) -> None:
        del self.mother["integrity"]
        with self.assertRaises(ApiContractError):
            self.reconcile(FakeApi([self.mother]))

    def test_repairs_run_during_central_defense_and_precede_production(self) -> None:
        self.mother["integrity"] = 99
        api = FakeApi([self.mother], scans={self.center: detailed_scan(probes=[{"id": 42, "status": "idle"}])},
                      resources={"mother": {"metals": 100}}, auxiliaries={"mother": [auxiliary("m1")]})
        DefenseEtoileAttente(api, mothership_id="mother", logger=self.logs.append).run_cycle()
        self.assertEqual([("mother", "m1", 1)], api.repair_starts)
        self.assertEqual([], api.craft_starts)

    def test_damaged_home_ship_waits_for_repair_before_deploying(self) -> None:
        damaged = ship("damaged", self.center, integrity=19)
        api = FakeApi([self.mother, damaged], resources={"damaged": {"metals": .01}},
                      auxiliaries={"damaged": [auxiliary("d1")]}, inventories={"damaged": [missile_item("missile")]})
        controller = DefenseEtoileAttente(api, mothership_id="mother", logger=self.logs.append)
        controller.run_cycle()
        self.assertEqual([], api.moves)
        damaged["integrity"] = 20
        api.auxiliaries["damaged"][0].update(status="inactive", action=None)
        controller.run_cycle()
        self.assertEqual([("damaged", NEIGHBOR_OFFSETS[0])], api.moves)


if __name__ == "__main__":
    unittest.main()
