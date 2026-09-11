from __future__ import annotations

import json
import unittest
from contextlib import redirect_stderr, redirect_stdout
from io import StringIO
from unittest.mock import Mock, patch

from scripts.others_control.build_germination_depot import (
    DEFAULT_BASE_URL,
    build_argument_parser,
    first_available_auxiliary,
    main,
)
from scripts.others_control.defense_etoile.http_api import HttpOthersApi
from scripts.others_control.tests.support import auxiliary


class FakeResponse:
    def __init__(self, body: dict[str, object]) -> None:
        self.body = json.dumps(body).encode()

    def __enter__(self) -> FakeResponse:
        return self

    def __exit__(self, *args: object) -> None:
        return None

    def read(self) -> bytes:
        return self.body


class BuildGerminationDepotTests(unittest.TestCase):
    def test_cli_requires_token_and_mothership_and_accepts_base_url(self) -> None:
        parser = build_argument_parser()
        arguments = parser.parse_args([
            "--token", "secret",
            "--mothership-id", "mother",
            "--base-url", "https://game.example/",
        ])

        self.assertEqual("secret", arguments.token)
        self.assertEqual("mother", arguments.mothership_id)
        self.assertEqual("https://game.example", arguments.base_url)
        defaults = parser.parse_args(["--token", "secret", "--mothership-id", "mother"])
        self.assertEqual(DEFAULT_BASE_URL, defaults.base_url)

    def test_selects_first_idle_embarked_auxiliary_by_public_id(self) -> None:
        auxiliaries = [
            auxiliary("aux-z"),
            auxiliary("aux-b", status="busy", action={"id": "action-b"}),
            auxiliary("aux-a"),
            auxiliary("aux-deployed", location_type="deployed"),
        ]

        selected = first_available_auxiliary(auxiliaries)
        self.assertIsNotNone(selected)
        self.assertEqual("aux-a", selected["id"] if selected else None)

    @patch("scripts.others_control.build_germination_depot.uuid.uuid4")
    @patch("scripts.others_control.build_germination_depot.HttpOthersApi")
    def test_main_starts_construction_with_first_available_auxiliary(
        self, api_class: Mock, uuid4: Mock,
    ) -> None:
        uuid4.return_value.hex = "operation-a"
        api = api_class.return_value
        api.get_ship.return_value = {"id": "mother", "type": "mothership"}
        api.get_auxiliaries.return_value = [auxiliary("aux-z"), auxiliary("aux-a")]
        api.start_germination_depot.return_value = {
            "id": "action-a", "endsAt": "2099-01-01T00:00:00+00:00",
        }

        output = StringIO()
        with redirect_stdout(output):
            result = main([
                "--token", "secret",
                "--mothership-id", "mother",
                "--base-url", "https://game.example",
            ])

        self.assertEqual(0, result)
        api_class.assert_called_once_with("https://game.example", "secret", 10.0)
        api.get_ship.assert_called_once_with("mother")
        api.get_auxiliaries.assert_called_once_with("mother")
        api.start_germination_depot.assert_called_once_with(
            "mother", "aux-a", "operation-a",
        )
        self.assertIn("Action : action-a", output.getvalue())

    @patch("scripts.others_control.build_germination_depot.HttpOthersApi")
    def test_main_refuses_when_no_auxiliary_is_available(self, api_class: Mock) -> None:
        api = api_class.return_value
        api.get_ship.return_value = {"id": "mother", "type": "mothership"}
        api.get_auxiliaries.return_value = [auxiliary("aux-a", status="busy")]

        with redirect_stderr(StringIO()):
            result = main(["--token", "secret", "--mothership-id", "mother"])

        self.assertEqual(1, result)
        api.start_germination_depot.assert_not_called()

    @patch("scripts.others_control.defense_etoile.http_api.urlopen")
    def test_http_request_uses_empty_body_and_idempotency_key(self, send: Mock) -> None:
        send.return_value = FakeResponse({"action": {"id": "action-a"}})
        api = HttpOthersApi(
            "http://127.0.0.1:8000", "secret", 10,
            request_interval_seconds=0.001,
        )

        action = api.start_germination_depot("mother/id", "aux/id", "operation-a")

        request = send.call_args.args[0]
        self.assertEqual("POST", request.method)
        self.assertEqual(
            "http://127.0.0.1:8000/api/others/ships/mother%2Fid/auxiliaries/aux%2Fid/build-germination-depot",
            request.full_url,
        )
        self.assertEqual({}, json.loads(request.data))
        self.assertTrue(
            request.get_header("Idempotency-key").startswith("build-germination-depot-")
        )
        self.assertEqual({"id": "action-a"}, action)


if __name__ == "__main__":
    unittest.main()
