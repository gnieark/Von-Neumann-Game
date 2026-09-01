from __future__ import annotations

import unittest
from contextlib import redirect_stderr
from io import StringIO

from scripts.others_control.defense_etoile.cli import build_argument_parser


class CliTests(unittest.TestCase):
    def test_cli_accepts_exactly_one_identifier_kind(self) -> None:
        parser = build_argument_parser()

        mothership_arguments = parser.parse_args(["--mothership-id", "mother"])
        fleet_arguments = parser.parse_args(["--fleet-id", "fleet_test"])

        self.assertEqual("mother", mothership_arguments.mothership_id)
        self.assertIsNone(mothership_arguments.fleet_id)
        self.assertEqual("fleet_test", fleet_arguments.fleet_id)
        self.assertIsNone(fleet_arguments.mothership_id)
        with redirect_stderr(StringIO()):
            with self.assertRaises(SystemExit):
                parser.parse_args([])
            with self.assertRaises(SystemExit):
                parser.parse_args(["--mothership-id", "mother", "--fleet-id", "fleet_test"])


if __name__ == "__main__":
    unittest.main()
