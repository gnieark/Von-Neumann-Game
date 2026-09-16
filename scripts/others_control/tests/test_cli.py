from __future__ import annotations

import unittest
from contextlib import redirect_stderr
from io import StringIO
from unittest.mock import Mock, call, patch

from scripts.others_control.defense_etoile.cli import build_argument_parser, main, timestamped_logger
from scripts.others_control.defense_etoile.config import ApiConfiguration
from scripts.others_control.defense_etoile.models import CycleResult


class CliTests(unittest.TestCase):
    def test_alert_deadline_does_not_trigger_extra_activity_scans(self) -> None:
        clock = [0.0]
        poll_times = []
        activity_times = []
        with patch("scripts.others_control.defense_etoile.cli.load_config", return_value=ApiConfiguration("http://localhost", "token")), \
                patch("scripts.others_control.defense_etoile.cli.HttpOthersApi"), \
                patch("scripts.others_control.defense_etoile.cli.DefenseEtoileAttente") as controller_class, \
                patch("scripts.others_control.defense_etoile.cli.SpectatorJournal") as journal_class, \
                patch("scripts.others_control.defense_etoile.cli.time.monotonic", side_effect=lambda: clock[0]), \
                patch("scripts.others_control.defense_etoile.cli.time.sleep") as sleep:
            journal = journal_class.return_value
            journal.state = {}
            journal.next_alerts_at = 0.0
            def poll(api):
                if clock[0] >= journal.next_alerts_at:
                    poll_times.append(clock[0])
                    journal.next_alerts_at = clock[0] + 300
            journal.poll_alerts.side_effect = poll
            controller = controller_class.return_value
            controller.run_cycle.return_value = CycleResult()
            controller.run_activity_cycle.side_effect = lambda: activity_times.append(clock[0]) or CycleResult()
            def advance(delay):
                if clock[0] >= 400:
                    raise KeyboardInterrupt
                clock[0] += delay
            sleep.side_effect = advance
            self.assertEqual(0, main(["--fleet-id", "fleet_test", "--idle-refresh-seconds", "600", "--activity-refresh-seconds", "200"]))
            self.assertEqual([0.0, 300.0], poll_times)
            self.assertEqual([200.0, 400.0], activity_times)
            controller.run_cycle.assert_called_once()
            journal.close.assert_called_once()

    def test_repair_cost_option_rejects_negative_and_non_finite_values(self) -> None:
        for value in ("-1", "nan", "inf"):
            with self.subTest(value=value), redirect_stderr(StringIO()):
                self.assertEqual(2, main(["--fleet-id", "fleet_test", "--repair-metals-per-integrity-point", value]))

    def test_cli_accepts_exactly_one_identifier_kind(self) -> None:
        parser = build_argument_parser()

        mothership_arguments = parser.parse_args(["--mothership-id", "mother"])
        fleet_arguments = parser.parse_args(["--fleet-id", "fleet_test"])

        self.assertEqual("mother", mothership_arguments.mothership_id)
        self.assertIsNone(mothership_arguments.fleet_id)
        self.assertEqual("fleet_test", fleet_arguments.fleet_id)
        self.assertIsNone(fleet_arguments.mothership_id)
        self.assertEqual(20.0, fleet_arguments.activity_refresh_seconds)
        self.assertEqual(300.0, fleet_arguments.idle_refresh_seconds)
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

        exit_code = main(["--fleet-id", "fleet_test", "--once", "--repair-metals-per-integrity-point", "0.02"])

        self.assertEqual(0, exit_code)
        self.assertEqual(.02, controller_class.call_args.kwargs["repair_metals_per_point"])
        self.assertEqual(["summary", "cycle"], calls)
        self.assertEqual([call()], controller.log_fleet_summary.call_args_list)
        http_api.assert_called_once_with(
            "http://localhost", "token", 10.0, request_interval_seconds=1.0,
            logger=timestamped_logger,
            spectator=controller_class.call_args.kwargs["logger"],
        )

    @patch("scripts.others_control.defense_etoile.cli.time.sleep")
    @patch("scripts.others_control.defense_etoile.cli.time.monotonic")
    @patch("scripts.others_control.defense_etoile.cli.DefenseEtoileAttente")
    @patch("scripts.others_control.defense_etoile.cli.HttpOthersApi")
    @patch("scripts.others_control.defense_etoile.cli.load_config")
    def test_cli_runs_activity_cycles_every_twenty_seconds(
        self,
        load_config: Mock,
        http_api: Mock,
        controller_class: Mock,
        monotonic: Mock,
        sleep: Mock,
    ) -> None:
        load_config.return_value = ApiConfiguration("http://localhost", "token")
        controller = controller_class.return_value
        controller.run_cycle.return_value = CycleResult()
        controller.run_activity_cycle.return_value = CycleResult()
        clock = [0.0]
        monotonic.side_effect = lambda: clock[0]

        def advance_then_stop(delay: float) -> None:
            if sleep.call_count == 2:
                raise KeyboardInterrupt
            clock[0] += delay

        sleep.side_effect = advance_then_stop

        exit_code = main(["--fleet-id", "fleet_test"])

        self.assertEqual(0, exit_code)
        controller.run_cycle.assert_called_once_with()
        controller.run_activity_cycle.assert_called_once_with()
        self.assertEqual([call(20.0), call(20.0)], sleep.call_args_list)


if __name__ == "__main__":
    unittest.main()
