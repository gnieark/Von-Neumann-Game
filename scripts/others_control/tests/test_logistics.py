from __future__ import annotations

import unittest
from datetime import datetime, timedelta, timezone

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.defense_etoile.logistics import MothershipLogistics
from scripts.others_control.defense_etoile.models import CycleResult
from scripts.others_control.tests.support import (
    FakeApi,
    auxiliary,
    detailed_scan,
    missile_item,
    ship,
)


def harvestable_scan(*planet_ids: str) -> dict[str, object]:
    return detailed_scan(
        objects=[
            {
                "id": planet_id,
                "type": "planet",
                "harvestable": True,
                "intelligentLife": False,
            }
            for planet_id in planet_ids
        ]
    )


def resource_stock(
    metals: float,
    ice: float,
    carbon_compounds: float,
    deuterium: float,
) -> dict[str, float]:
    return {
        "metals": metals,
        "ice": ice,
        "carbon_compounds": carbon_compounds,
        "deuterium": deuterium,
    }


class LogisticsTests(unittest.TestCase):
    def setUp(self) -> None:
        self.center = (0, 0, 0)
        self.mothership = ship("mother", self.center, ship_type="mothership")
        self.now_value = datetime(2026, 9, 1, 12, 0, tzinfo=timezone.utc)
        self.logs: list[str] = []

    def controller(self, api: FakeApi) -> MothershipLogistics:
        return MothershipLogistics(
            api,
            logger=self.logs.append,
            now=lambda: self.now_value,
        )

    def reconcile(
        self,
        api: FakeApi,
        result: CycleResult,
        logistics: MothershipLogistics | None = None,
    ) -> None:
        missile_stock = sum(
            item.get("type") == "missile"
            for items in api.inventories.values()
            for item in items
        )
        (logistics or self.controller(api)).reconcile(
            self.mothership,
            result,
            fleet_missile_stock=missile_stock,
            missile_transfers_active=False,
        )

    def test_single_auxiliary_harvests_when_an_auxiliary_craft_is_not_affordable(self) -> None:
        api = FakeApi(
            [self.mothership],
            scans={self.center: harvestable_scan("planet-a")},
            auxiliaries={"mother": [auxiliary("aux-a")]},
        )

        result = CycleResult()
        self.reconcile(api, result)

        self.assertEqual([], api.craft_starts)
        self.assertEqual([("mother", "planet-a", 1)], api.harvest_starts)
        self.assertEqual(1, result.accepted_commands)
        self.assertIn(self.now_value + timedelta(hours=1), result.event_dates)

    def test_single_auxiliary_crafts_before_starting_another_harvest(self) -> None:
        api = FakeApi(
            [self.mothership],
            scans={self.center: harvestable_scan("planet-a")},
            auxiliaries={"mother": [auxiliary("aux-a")]},
            resources={"mother": resource_stock(5.0, 0.5, 1.0, 0.05)},
        )

        self.reconcile(api, CycleResult())

        self.assertEqual([("mother", "others_auxiliary", "aux-a")], api.craft_starts)
        self.assertEqual([], api.harvest_starts)

    def test_craft_assistant_is_reserved_before_ten_remaining_auxiliaries_harvest(self) -> None:
        api = FakeApi(
            [self.mothership],
            scans={self.center: harvestable_scan("planet-a")},
            auxiliaries={
                "mother": [auxiliary(f"aux-{index:02d}") for index in range(12)]
            },
            resources={"mother": resource_stock(5.0, 0.5, 1.0, 0.05)},
        )

        self.reconcile(api, CycleResult())

        self.assertEqual(
            [("mother", "others_auxiliary", "aux-00")],
            api.craft_starts,
        )
        self.assertEqual([("mother", "planet-a", 10)], api.harvest_starts)

    def test_auxiliary_target_precedes_missile_target(self) -> None:
        api = FakeApi(
            [self.mothership],
            auxiliaries={"mother": [auxiliary("aux-a"), auxiliary("aux-b")]},
            inventories={"mother": [missile_item(f"missile-{index}") for index in range(59)]},
            resources={"mother": resource_stock(20.0, 2.0, 5.0, 1.0)},
        )

        self.reconcile(api, CycleResult())

        self.assertEqual(
            [
                ("mother", "others_auxiliary", "aux-a"),
                ("mother", "others_auxiliary", "aux-b"),
            ],
            api.craft_starts,
        )
        self.assertNotIn("missile", [recipe for _, recipe, _ in api.craft_starts])

    def test_missiles_are_crafted_after_twenty_projected_auxiliaries(self) -> None:
        api = FakeApi(
            [self.mothership],
            scans={self.center: harvestable_scan("planet-a")},
            auxiliaries={
                "mother": [auxiliary(f"aux-{index:02d}") for index in range(20)]
            },
            inventories={"mother": [missile_item(f"missile-{index}") for index in range(50)]},
            resources={"mother": resource_stock(200.0, 20.0, 50.0, 10.0)},
        )

        self.reconcile(api, CycleResult())

        self.assertEqual(10, len(api.craft_starts))
        self.assertEqual({"missile"}, {recipe for _, recipe, _ in api.craft_starts})
        self.assertEqual([("mother", "planet-a", 10)], api.harvest_starts)

    def test_pending_crafts_count_toward_production_targets(self) -> None:
        active_craft = {
            "id": "craft-a",
            "recipeId": "others_auxiliary",
            "status": "queued",
            "endsAt": "2026-09-01T13:00:00+00:00",
        }
        api = FakeApi(
            [self.mothership],
            auxiliaries={
                "mother": [auxiliary(f"aux-{index:02d}") for index in range(19)]
            },
            inventories={"mother": [missile_item(f"missile-{index}") for index in range(60)]},
            resources={"mother": resource_stock(250.0, 25.0, 60.0, 10.5)},
            crafts={"mother": [active_craft]},
        )

        result = CycleResult()
        self.reconcile(api, result)

        self.assertEqual([], api.craft_starts)
        self.assertEqual([], api.harvest_starts)
        self.assertEqual(1, len(result.event_dates))

    def test_missile_target_counts_the_whole_fleet(self) -> None:
        guard = ship("guard", self.center)
        api = FakeApi(
            [self.mothership, guard],
            auxiliaries={
                "mother": [auxiliary(f"aux-{index:02d}") for index in range(20)]
            },
            inventories={
                "mother": [missile_item(f"mother-{index}") for index in range(10)],
                "guard": [missile_item(f"guard-{index}") for index in range(50)],
            },
            resources={"mother": resource_stock(250.0, 25.0, 60.0, 10.5)},
        )

        self.reconcile(api, CycleResult())

        self.assertEqual([], api.craft_starts)

    def test_active_inventory_transfer_suspends_only_missile_crafting(self) -> None:
        transfer = {
            "id": "transfer-active",
            "type": "inventory_transfer",
            "status": "queued",
            "endsAt": "2026-09-01T12:00:10+00:00",
        }
        api = FakeApi(
            [self.mothership],
            auxiliaries={
                "mother": [
                    auxiliary("aux-busy", status="busy", action=transfer),
                    *[auxiliary(f"aux-{index:02d}") for index in range(18)],
                ]
            },
            inventories={
                "mother": [missile_item(f"missile-{index}") for index in range(59)]
            },
            resources={"mother": resource_stock(5.0, 0.5, 1.0, 0.05)},
        )

        self.controller(api).reconcile(
            self.mothership,
            CycleResult(),
            fleet_missile_stock=59,
            missile_transfers_active=True,
        )

        self.assertEqual(
            [("mother", "others_auxiliary", "aux-00")], api.craft_starts
        )
        self.assertNotIn("missile", [recipe for _, recipe, _ in api.craft_starts])

    def test_complete_targets_keep_raw_resources_for_ten_auxiliaries_and_missiles(self) -> None:
        api = FakeApi(
            [self.mothership],
            auxiliaries={
                "mother": [auxiliary(f"aux-{index:02d}") for index in range(20)]
            },
            inventories={"mother": [missile_item(f"missile-{index}") for index in range(60)]},
            resources={"mother": resource_stock(250.0, 25.0, 60.0, 10.5)},
        )

        self.reconcile(api, CycleResult())

        self.assertEqual([], api.craft_starts)
        self.assertEqual([], api.harvest_starts)
        self.assertIn("réserve de reconstruction complètes", self.logs[-1])

    def test_harvest_actions_are_relaunched_inside_one_hour_windows(self) -> None:
        api = FakeApi(
            [self.mothership],
            scans={self.center: harvestable_scan("planet-a")},
            auxiliaries={"mother": [auxiliary("aux-a")]},
        )
        logistics = self.controller(api)

        first = CycleResult()
        self.reconcile(api, first, logistics)
        self.now_value += timedelta(minutes=10)
        second = CycleResult()
        self.reconcile(api, second, logistics)
        self.now_value += timedelta(minutes=51)
        third = CycleResult()
        self.reconcile(api, third, logistics)

        self.assertEqual(3, len(api.harvest_starts))
        self.assertEqual(2, self.logs.count("Nouveau cycle de moisson d'une heure."))
        self.assertIn(datetime(2026, 9, 1, 13, 0, tzinfo=timezone.utc), first.event_dates)
        self.assertIn(datetime(2026, 9, 1, 14, 1, tzinfo=timezone.utc), third.event_dates)

    def test_existing_harvest_is_observed_without_starting_another_one(self) -> None:
        harvest_action = {
            "id": "action-harvest",
            "type": "planet_harvest",
            "status": "queued",
            "endsAt": "2026-09-01T12:10:00+00:00",
        }
        api = FakeApi(
            [self.mothership],
            auxiliaries={
                "mother": [
                    auxiliary(
                        "aux-a",
                        status="busy",
                        location_type="deployed",
                        action=harvest_action,
                    )
                ]
            },
        )

        result = CycleResult()
        self.reconcile(api, result)

        self.assertEqual([], api.harvest_starts)
        self.assertEqual(2, len(result.event_dates))

    def test_logistics_runs_in_parallel_with_sentinel_deployment(self) -> None:
        sentinel = ship("sentinel", self.center)
        api = FakeApi(
            [self.mothership, sentinel],
            scans={self.center: harvestable_scan("planet-a")},
            auxiliaries={"mother": [auxiliary("aux-a")]},
        )

        result = DefenseEtoileAttente(
            api,
            mothership_id="mother",
            logger=self.logs.append,
            now=lambda: self.now_value,
        ).run_cycle()

        self.assertEqual([("mother", "planet-a", 1)], api.harvest_starts)
        self.assertEqual(1, len(api.moves))
        self.assertEqual("sentinel", api.moves[0][0])
        self.assertEqual(2, result.accepted_commands)

    def test_missile_distribution_reserves_its_auxiliary_before_logistics(self) -> None:
        recipient = ship("recipient", self.center)
        api = FakeApi(
            [self.mothership, recipient],
            auxiliaries={"mother": [auxiliary("aux-a"), auxiliary("aux-b")]},
            inventories={
                "mother": [missile_item(f"missile-{index}") for index in range(12)]
            },
            resources={"mother": resource_stock(5.0, 0.5, 1.0, 0.05)},
        )

        DefenseEtoileAttente(
            api,
            mothership_id="mother",
            logger=self.logs.append,
            now=lambda: self.now_value,
        ).run_cycle()

        self.assertEqual(
            [("mother", "recipient", "aux-a", ("missile-0",))],
            api.inventory_transfers,
        )
        self.assertEqual(
            [("mother", "others_auxiliary", "aux-b")], api.craft_starts
        )


if __name__ == "__main__":
    unittest.main()
