from __future__ import annotations

import unittest

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.tests.support import FakeApi, missile_item, movement, ship


class StartupSummaryTests(unittest.TestCase):
    def test_logs_position_auxiliaries_missiles_and_movement_for_each_ship(self) -> None:
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
                "- sentinel [standard] — position relative : en transit entre deux "
                "secteurs ; état : transit ; auxiliaires : 2 (dont 0 déployés) ; "
                "missiles : 1 ; mouvement : en transit vers (2, 0, 0), arrivée prévue "
                "2099-01-01T00:00:00+00:00.",
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


if __name__ == "__main__":
    unittest.main()
