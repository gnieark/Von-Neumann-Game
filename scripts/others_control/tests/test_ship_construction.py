from __future__ import annotations

from datetime import datetime, timedelta, timezone
from typing import Any
import unittest
from unittest.mock import patch

from scripts.others_control.defense_etoile.errors import ApiRequestError
from scripts.others_control.defense_etoile.logistics import MothershipLogistics
from scripts.others_control.defense_etoile.models import CycleResult
from scripts.others_control.tests.support import FakeApi, auxiliary, missile_item, ship


RESERVE = {"metals": 250.0, "ice": 25.0, "carbon_compounds": 60.0, "deuterium": 10.5}
NOW = datetime(2026, 9, 8, 12, tzinfo=timezone.utc)


class ConstructionApi(FakeApi):
    """Persist craft acceptance so subsequent cycles observe server-side state."""

    def start_craft(
        self, ship_id: str, recipe_id: str, assistant_auxiliary_id: str, operation_key: str,
    ) -> dict[str, Any]:
        super().start_craft(ship_id, recipe_id, assistant_auxiliary_id, operation_key)
        recipe = next(r for r in self.get_crafting_recipes() if r["id"] == recipe_id)
        for resource, amount in recipe["ingredients"].items():
            self.resources[ship_id][resource] -= amount
        action = {
            "id": f"craft-{len(self.craft_starts)}", "type": "others_craft",
            "status": "queued", "endsAt": (NOW + timedelta(seconds=recipe["durationSeconds"])).isoformat(),
        }
        self.crafts.setdefault(ship_id, []).append({**action, "recipeId": recipe_id})
        assistant = next(a for a in self.auxiliaries[ship_id] if a["id"] == assistant_auxiliary_id)
        assistant.update(status="busy", action=action)
        return action


class ShipConstructionTests(unittest.TestCase):
    def make_api(self, affordable: int = 4) -> ConstructionApi:
        api = ConstructionApi(
            [ship("mother", (0, 0, 0), ship_type="mothership")],
            auxiliaries={"mother": [auxiliary(f"aux-{i:02d}") for i in range(20)]},
            inventories={"mother": [missile_item(f"missile-{i}") for i in range(60)]},
        )
        recipe = next(r for r in api.get_crafting_recipes() if r["id"] == "standard_ship")
        api.resources["mother"] = {
            resource: reserve + affordable * recipe["ingredients"][resource]
            for resource, reserve in RESERVE.items()
        }
        return api

    def reconcile(self, api: ConstructionApi) -> CycleResult:
        # New instance on every call exercises restart recovery via GET crafts.
        result = CycleResult()
        MothershipLogistics(api, logger=lambda _: None, now=lambda: NOW).reconcile(
            api.ships[0], result,
            fleet_missile_stock=len(api.inventories["mother"]),
            missile_transfers_active=False,
        )
        return result

    def add_craft(self, api: ConstructionApi, status: str, recipe: str = "standard_ship") -> None:
        crafts = api.crafts.setdefault("mother", [])
        action = {"id": f"existing-{len(crafts)}", "recipeId": recipe,
                  "type": "others_craft", "status": status,
                  "endsAt": (NOW + timedelta(days=7)).isoformat()}
        if status in {"queued", "running"}:
            api.auxiliaries["mother"][len(crafts)].update(status="busy", action=action)
        crafts.append(action)

    def test_starts_up_to_three_constructions_and_waits_across_restarts(self) -> None:
        api = self.make_api()
        result = self.reconcile(api)
        self.assertEqual([("mother", "standard_ship", f"aux-{i:02d}") for i in range(3)], api.craft_starts)
        self.assertEqual(3, result.accepted_commands)
        self.assertEqual([NOW + timedelta(days=7)] * 3, result.event_dates)
        self.assertEqual(0, self.reconcile(api).accepted_commands)
        self.assertEqual(3, len(api.craft_starts))

    def test_counts_queued_and_running_crafts_from_api(self) -> None:
        api = self.make_api()
        self.add_craft(api, "queued")
        self.add_craft(api, "running")
        self.assertEqual(1, self.reconcile(api).accepted_commands)
        self.assertEqual([("mother", "standard_ship", "aux-02")], api.craft_starts)

    def test_does_not_add_to_an_existing_over_limit_queue(self) -> None:
        api = self.make_api()
        for _ in range(4):
            self.add_craft(api, "queued")
        self.assertEqual(0, self.reconcile(api).accepted_commands)

    def test_terminal_and_other_recipes_do_not_occupy_ship_slots(self) -> None:
        api = self.make_api()
        for status in ("succeeded", "failed", "canceled"):
            self.add_craft(api, status)
        self.add_craft(api, "queued", "missile")
        self.assertEqual(3, self.reconcile(api).accepted_commands)

    def test_freed_slot_is_replaced_on_next_cycle(self) -> None:
        api = self.make_api()
        self.reconcile(api)
        api.crafts["mother"][0]["status"] = "succeeded"
        api.auxiliaries["mother"][0].update(status="inactive", action=None)
        self.assertEqual(1, self.reconcile(api).accepted_commands)
        self.assertEqual(4, len(api.craft_starts))
        self.assertEqual(3, sum(c["status"] == "queued" for c in api.crafts["mother"]))

    def test_resource_budget_preserves_the_full_reconstruction_reserve(self) -> None:
        for affordable in (0, 1, 2, 3):
            with self.subTest(affordable=affordable):
                api = self.make_api(affordable)
                self.assertEqual(affordable, self.reconcile(api).accepted_commands)
                self.assertEqual(RESERVE, api.resources["mother"])

    def test_each_ingredient_must_cover_recipe_plus_reserve(self) -> None:
        for resource in RESERVE:
            with self.subTest(resource=resource):
                api = self.make_api(1)
                api.resources["mother"][resource] -= 0.0001
                self.assertEqual(0, self.reconcile(api).accepted_commands)

    def test_reserved_inventory_resources_are_not_available_for_construction(self) -> None:
        api = self.make_api(1)
        inventory = api.get_inventory("mother")
        inventory["resources"]["metals"]["reserved"] = 1
        with patch.object(api, "get_inventory", return_value=inventory):
            self.assertEqual(0, self.reconcile(api).accepted_commands)

    def test_only_free_embarked_auxiliaries_are_used(self) -> None:
        api = self.make_api()
        for assistant in api.auxiliaries["mother"]:
            assistant["status"] = "busy"
        api.auxiliaries["mother"][0].update(status="available", locationType="deployed")
        api.auxiliaries["mother"][1].update(status="inactive", action={"type": "deuterium_transfer"})
        api.auxiliaries["mother"][2].update(status="available")
        self.assertEqual(1, self.reconcile(api).accepted_commands)
        self.assertEqual([("mother", "standard_ship", "aux-02")], api.craft_starts)

    def test_no_construction_if_production_targets_are_not_met(self) -> None:
        api = self.make_api()
        api.inventories["mother"].pop()
        for assistant in api.auxiliaries["mother"][1:]:
            assistant["status"] = "busy"
        self.reconcile(api)
        self.assertEqual([("mother", "missile", "aux-00")], api.craft_starts)

    def test_three_ship_crafts_do_not_block_replacement_missile_production(self) -> None:
        api = self.make_api()
        for _ in range(3):
            self.add_craft(api, "queued")
        api.inventories["mother"].pop()
        self.reconcile(api)
        self.assertEqual([("mother", "missile", "aux-03")], api.craft_starts)

    def test_rejection_defers_remaining_launches_and_rechecks_api_next_cycle(self) -> None:
        api = self.make_api()
        with patch.object(api, "start_craft", side_effect=ApiRequestError(409, "others_auxiliary_busy", "busy")) as command:
            self.assertEqual(0, self.reconcile(api).accepted_commands)
            self.assertEqual(1, command.call_count)
        self.assertEqual(3, self.reconcile(api).accepted_commands)


if __name__ == "__main__":
    unittest.main()
