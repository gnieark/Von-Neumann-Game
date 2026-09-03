from __future__ import annotations

import unittest

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.tests.support import FakeApi, missile_item, movement, ship


class StartupSummaryTests(unittest.TestCase):
    def test_logs_state_and_inventory_for_each_ship(self) -> None:
        api = FakeApi(
            [
                ship(
                    "mother",
                    (0, 0, 0),
                    ship_type="mothership",
                    status="low_orbit",
                    auxiliary_count=4,
                    deployed_auxiliary_count=3,
                ),
                ship(
                    "sentinel",
                    None,
                    status="transit",
                    movement=movement((2, 0, 0)),
                    auxiliary_count=2,
                ),
            ],
            inventories={
                "mother": [missile_item("missile-a"), missile_item("missile-b")],
                "sentinel": [missile_item("missile-c")],
            },
            resources={
                "mother": {
                    "metals": 20.5,
                    "ice": 2.0,
                    "carbon_compounds": 5.0,
                    "deuterium": 1.0,
                },
            },
        )
        messages: list[str] = []

        DefenseEtoileAttente(
            api,
            fleet_id="fleet_test",
            logger=messages.append,
        ).log_fleet_summary()

        self.assertEqual(
            [
                "État initial de la flotte fleet_test : 2 vaisseaux.",
                "- mother [mothership] — position relative : (0, 0, 0) ; "
                "état : low_orbit ; auxiliaires : 4 (dont 3 déployés) ; "
                "missiles : 2 ; mouvement : aucun.",
                "  Inventaire — occupation : 32.5 / 100000 ECE ; capacité réservée : "
                "0 ECE ; ressources : métaux : 20.5 ECE (dont 0 réservées), glace : "
                "2 ECE (dont 0 réservées), composés carbonés : 5 ECE (dont 0 "
                "réservées), deutérium : 1 ECE (dont 0 réservées) ; objets : missile "
                "× 2 (4 ECE).",
                "- sentinel [standard] — position relative : en transit entre deux "
                "secteurs ; état : transit ; auxiliaires : 2 (dont 0 déployés) ; "
                "missiles : 1 ; mouvement : en transit vers (2, 0, 0), arrivée prévue "
                "2099-01-01T00:00:00+00:00.",
                "  Inventaire — occupation : 2 / 100000 ECE ; capacité réservée : "
                "0 ECE ; ressources : métaux : 0 ECE (dont 0 réservées), glace : 0 "
                "ECE (dont 0 réservées), composés carbonés : 0 ECE (dont 0 réservées), "
                "deutérium : 0 ECE (dont 0 réservées) ; objets : missile × 1 (2 ECE).",
            ],
            messages,
        )
        self.assertEqual(0, api.ship_calls)
        self.assertEqual(1, api.fleet_calls)

    def test_mothership_identifier_resolves_and_validates_its_fleet(self) -> None:
        api = FakeApi([
            ship(
                "mother",
                (0, 0, 0),
                ship_type="mothership",
                auxiliary_count=1,
            )
        ])

        DefenseEtoileAttente(
            api,
            mothership_id="mother",
            logger=lambda _: None,
        ).log_fleet_summary()

        self.assertEqual(1, api.ship_calls)
        self.assertEqual(1, api.fleet_calls)

    def test_logs_an_empty_inventory(self) -> None:
        messages: list[str] = []

        DefenseEtoileAttente(
            FakeApi([ship("mother", (0, 0, 0), ship_type="mothership")]),
            fleet_id="fleet_test",
            logger=messages.append,
        ).log_fleet_summary()

        self.assertTrue(messages[-1].endswith("objets : aucun."))


if __name__ == "__main__":
    unittest.main()
