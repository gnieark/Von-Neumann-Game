from __future__ import annotations

from copy import deepcopy
from datetime import datetime, timezone
import unittest
from unittest.mock import patch

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.defense_etoile.errors import ApiContractError, ApiRequestError
from scripts.others_control.defense_etoile.models import CycleResult
from scripts.others_control.defense_etoile.refueling import FleetRefuelingCoordinator
from scripts.others_control.tests.support import FakeApi, auxiliary, detailed_scan, missile_item, movement, ship


def transfer(status: str = "queued") -> dict:
    return {
        "id": "remote-transfer", "type": "deuterium_transfer", "status": status,
        "actor": {"kind": "others_auxiliary", "id": "remote-auxiliary"},
        "endsAt": "2026-09-08T12:05:00+00:00",
    }


class RefuelingTests(unittest.TestCase):
    def setUp(self) -> None:
        self.mother = ship("mother", (0, 0, 0), ship_type="mothership", deuterium=100)
        self.logs: list[str] = []

    def reconcile(self, api: FakeApi) -> CycleResult:
        result = CycleResult()
        FleetRefuelingCoordinator(api, logger=self.logs.append).reconcile(
            self.mother, api.ships, api.active_actions, result,
        )
        return result

    def test_wave_fills_local_ships_and_uses_only_remaining_source_fuel(self) -> None:
        api = FakeApi(
            [self.mother, ship("a", (0, 0, 0), deuterium=70),
             ship("b", (0, 0, 0), deuterium=10), ship("c", (0, 0, 0))],
            auxiliaries={"mother": [auxiliary(f"aux-{i}") for i in range(3)]},
        )
        result = self.reconcile(api)
        self.assertEqual([("mother", "a", "aux-0", 30), ("mother", "b", "aux-1", 70)], api.deuterium_transfers)
        self.assertEqual(2, result.accepted_commands)
        self.assertEqual(2, len(result.event_dates))
        # A new coordinator (as after restart) must see the API's ongoing wave.
        self.reconcile(api)
        self.assertEqual(2, len(api.deuterium_transfers))

    def test_only_same_fleet_local_stationary_nonfull_ships_are_served(self) -> None:
        foreign = ship("foreign", (0, 0, 0))
        foreign["fleetId"] = "other-fleet"
        destroyed = ship("destroyed", (0, 0, 0), status="destroyed")
        destroyed["location"]["state"] = "destroyed"
        removed = ship("removed", (0, 0, 0), status="removed")
        removed["location"]["state"] = "removed"
        api = FakeApi(
            [self.mother, foreign, destroyed, removed,
             ship("neighbor", (1, 1, 0)), ship("transit", None, status="transit"),
             ship("departing", (0, 0, 0), movement=movement((1, 1, 0))),
             ship("full", (0, 0, 0), deuterium=100), ship("local", (0, 0, 0))],
            auxiliaries={"mother": [auxiliary(f"aux-{i}") for i in range(8)]},
        )
        self.reconcile(api)
        self.assertEqual([("mother", "local", "aux-0", 80)], api.deuterium_transfers)

    def test_uses_only_free_embarked_source_auxiliaries(self) -> None:
        api = FakeApi(
            [self.mother, ship("a", (0, 0, 0)), ship("b", (0, 0, 0))],
            auxiliaries={
                "mother": [auxiliary("busy", status="busy"),
                           auxiliary("deployed", location_type="deployed"),
                           auxiliary("action", action={"type": "others_craft"}),
                           auxiliary("free", status="available")],
                "a": [auxiliary("target-aux")],
            },
        )
        self.reconcile(api)
        self.assertEqual([("mother", "a", "free", 80)], api.deuterium_transfers)

    def test_empty_source_or_no_auxiliary_launches_nothing(self) -> None:
        for amount, auxiliaries in [(0, [auxiliary("free")]), (100, [])]:
            with self.subTest(amount=amount):
                self.mother["deuterium"]["amount"] = amount
                api = FakeApi([self.mother, ship("target", (0, 0, 0))], auxiliaries={"mother": auxiliaries})
                self.assertEqual(0, self.reconcile(api).accepted_commands)
                self.assertEqual([], api.deuterium_transfers)

    def test_waits_for_every_fleet_transfer_even_after_its_deadline(self) -> None:
        api = FakeApi(
            [self.mother, ship("a", (0, 0, 0))],
            auxiliaries={"mother": [auxiliary("free")]},
            active_actions=[transfer(), transfer("running")],
        )
        result = self.reconcile(api)
        self.assertEqual([], api.deuterium_transfers)
        self.assertEqual([datetime(2026, 9, 8, 12, 5, tzinfo=timezone.utc)] * 2, result.event_dates)
        api.active_actions.pop()
        self.reconcile(api)
        self.assertEqual([], api.deuterium_transfers)

    def test_terminal_transfers_and_other_action_types_do_not_block(self) -> None:
        api = FakeApi(
            [self.mother, ship("a", (0, 0, 0))],
            auxiliaries={"mother": [auxiliary("free")]},
            active_actions=[transfer("succeeded"), transfer("failed"),
                            {"type": "inventory_transfer", "status": "queued"}],
        )
        self.assertEqual(1, self.reconcile(api).accepted_commands)

    def test_small_deficits_preserve_four_decimal_precision(self) -> None:
        self.mother["deuterium"]["amount"] = 0.0003
        api = FakeApi(
            [self.mother, ship("a", (0, 0, 0), deuterium=99.9999),
             ship("b", (0, 0, 0), deuterium=99.9998)],
            auxiliaries={"mother": [auxiliary("aux-a"), auxiliary("aux-b")]},
        )
        self.reconcile(api)
        self.assertEqual([0.0001, 0.0002], [entry[3] for entry in api.deuterium_transfers])

    def test_stale_state_rejections_stop_the_wave_without_raising(self) -> None:
        for status in (404, 409, 422):
            with self.subTest(status=status):
                api = FakeApi(
                    [self.mother, ship("a", (0, 0, 0)), ship("b", (0, 0, 0))],
                    auxiliaries={"mother": [auxiliary("aux-a"), auxiliary("aux-b")]},
                )
                with patch.object(api, "start_deuterium_transfer", side_effect=ApiRequestError(status, "refused", "changed")) as command:
                    self.assertEqual(0, self.reconcile(api).accepted_commands)
                    self.assertEqual(1, command.call_count)

    def test_restarts_wait_then_recompute_from_fresh_fleet_data(self) -> None:
        api = FakeApi(
            [self.mother, ship("target", (0, 0, 0), deuterium=0)],
            auxiliaries={"mother": [auxiliary("free")]}, active_actions=[transfer()],
        )
        for mode in ("mothership_id", "fleet_id"):
            controller = DefenseEtoileAttente(api, **{mode: "mother" if mode == "mothership_id" else "fleet_test"}, logger=self.logs.append)
            with patch.object(controller.formation, "reconcile", side_effect=lambda m, s, r, **kw: r):
                controller.run_cycle()
        self.assertEqual([], api.deuterium_transfers)
        api.active_actions.clear()
        api.ships = deepcopy(api.ships)
        api.ships[0]["deuterium"]["amount"] = 8
        api.ships[1]["deuterium"]["amount"] = 95
        controller = DefenseEtoileAttente(api, fleet_id="fleet_test", logger=self.logs.append)
        with patch.object(controller.formation, "reconcile", side_effect=lambda m, s, r, **kw: r):
            controller.run_cycle()
        self.assertEqual([("mother", "target", "free", 5)], api.deuterium_transfers)
        self.assertEqual(3, api.fleet_calls)

    def test_waiting_does_not_suspend_missiles_logistics_or_formation(self) -> None:
        api = FakeApi(
            [self.mother, ship("target", (0, 0, 0))],
            auxiliaries={"mother": [auxiliary("aux-a"), auxiliary("aux-b"), auxiliary("aux-c")]},
            inventories={"mother": [missile_item(f"m-{i}") for i in range(11)]},
            resources={"mother": {"metals": 5, "ice": 0.5, "carbon_compounds": 1, "deuterium": 0.05}},
            scans={(0, 0, 0): detailed_scan(objects=[{"id": "planet", "type": "planet", "harvestable": True}])},
            active_actions=[transfer()],
        )
        DefenseEtoileAttente(api, fleet_id="fleet_test", logger=self.logs.append).run_cycle()
        self.assertEqual([], api.deuterium_transfers)
        self.assertEqual(1, len(api.inventory_transfers))
        self.assertEqual(1, len(api.craft_starts))
        self.assertEqual(1, len(api.harvest_starts))
        self.assertEqual(1, len(api.moves))

    def test_refueling_auxiliary_is_not_reused_by_crafting_or_harvest(self) -> None:
        api = FakeApi(
            [self.mother, ship("target", (0, 0, 0))],
            auxiliaries={"mother": [auxiliary("aux-a"), auxiliary("aux-b"), auxiliary("aux-c")]},
            resources={"mother": {"metals": 5, "ice": 0.5, "carbon_compounds": 1, "deuterium": 0.05}},
            scans={(0, 0, 0): detailed_scan(objects=[{"id": "planet", "type": "planet", "harvestable": True}])},
        )
        DefenseEtoileAttente(api, fleet_id="fleet_test", logger=self.logs.append).run_cycle()
        self.assertEqual([("mother", "target", "aux-a", 80)], api.deuterium_transfers)
        self.assertEqual([("mother", "others_auxiliary", "aux-b")], api.craft_starts)
        self.assertEqual([("mother", "planet", 1)], api.harvest_starts)

    def test_missing_active_actions_is_a_contract_error(self) -> None:
        api = FakeApi([self.mother])
        with patch.object(api, "get_fleet", return_value={"id": "fleet_test", "ships": api.ships}):
            with self.assertRaisesRegex(ApiContractError, "activeActions"):
                DefenseEtoileAttente(api, fleet_id="fleet_test", logger=self.logs.append).run_cycle()


if __name__ == "__main__":
    unittest.main()
