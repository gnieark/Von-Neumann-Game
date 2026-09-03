from __future__ import annotations

import unittest
from contextlib import redirect_stderr
from io import StringIO
from unittest.mock import Mock, call, patch

from scripts.others_control.defense_etoile.cli import build_argument_parser, main
from scripts.others_control.defense_etoile.config import ApiConfiguration
from scripts.others_control.defense_etoile.models import CycleResult


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

    @patch("scripts.others_control.defense_etoile.cli.DefenseEtoileAttente")
    @patch("scripts.others_control.defense_etoile.cli.HttpOthersApi")
    @patch("scripts.others_control.defense_etoile.cli.load_config")
    def test_cli_logs_summary_before_its_first_cycle(
        self,
        load_config: Mock,
        http_api: Mock,
        controller_class: Mock,
    ) -> None:
        load_config.return_value = ApiConfiguration("http://localhost", "token")
        calls: list[str] = []
        controller = controller_class.return_value
        controller.log_fleet_summary.side_effect = lambda: calls.append("summary")
        controller.run_cycle.side_effect = lambda: (
            calls.append("cycle") or CycleResult()
        )

        exit_code = main(["--fleet-id", "fleet_test", "--once"])

        self.assertEqual(0, exit_code)
        self.assertEqual(["summary", "cycle"], calls)
        self.assertEqual([call()], controller.log_fleet_summary.call_args_list)
        http_api.assert_called_once_with("http://localhost", "token", 10.0)


if __name__ == "__main__":
    unittest.main()
