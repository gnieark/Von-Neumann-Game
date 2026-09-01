from __future__ import annotations

import json
import unittest
from unittest.mock import Mock, patch

from scripts.others_control.defense_etoile.http_api import HttpOthersApi


class FakeResponse:
    def __init__(self, body: dict[str, object]) -> None:
        self.body = json.dumps(body).encode()

    def __enter__(self) -> FakeResponse:
        return self

    def __exit__(self, *args: object) -> None:
        return None

    def read(self) -> bytes:
        return self.body


class HttpApiTests(unittest.TestCase):
    @patch("scripts.others_control.defense_etoile.http_api.urlopen")
    def test_auxiliary_collection_follows_opaque_pagination(self, urlopen_mock: Mock) -> None:
        urlopen_mock.side_effect = [
            FakeResponse({"auxiliaries": [{"id": "aux-a"}], "nextCursor": "cursor-a"}),
            FakeResponse({"auxiliaries": [{"id": "aux-b"}]}),
        ]
        api = HttpOthersApi("http://127.0.0.1:8000", "token", 10)

        auxiliaries = api.get_auxiliaries("mother")

        self.assertEqual([{"id": "aux-a"}, {"id": "aux-b"}], auxiliaries)
        second_request = urlopen_mock.call_args_list[1].args[0]
        self.assertIn("cursor=cursor-a", second_request.full_url)

    @patch("scripts.others_control.defense_etoile.http_api.urlopen")
    def test_craft_and_harvest_build_canonical_requests(self, urlopen_mock: Mock) -> None:
        urlopen_mock.side_effect = [
            FakeResponse({"action": {"id": "craft-action"}}),
            FakeResponse({"action": {"id": "harvest-action"}}),
        ]
        api = HttpOthersApi("http://127.0.0.1:8000", "token", 10)

        api.start_craft("mother", "others_auxiliary", "aux-a", "craft-cycle")
        api.start_harvest("mother", "planet-a", 10, "harvest-cycle")

        craft_request = urlopen_mock.call_args_list[0].args[0]
        harvest_request = urlopen_mock.call_args_list[1].args[0]
        self.assertEqual(
            {"recipeId": "others_auxiliary", "assistantAuxiliaryId": "aux-a"},
            json.loads(craft_request.data),
        )
        self.assertTrue(
            craft_request.get_header("Idempotency-key").startswith("defense-craft-")
        )
        self.assertEqual(
            {"targetObjectId": "planet-a", "auxiliaryCount": 10},
            json.loads(harvest_request.data),
        )
        self.assertTrue(
            harvest_request.get_header("Idempotency-key").startswith("defense-harvest-")
        )

    @patch("scripts.others_control.defense_etoile.http_api.urlopen")
    def test_move_ship_builds_the_canonical_request(self, urlopen_mock: Mock) -> None:
        urlopen_mock.return_value = FakeResponse({"action": {"endsAt": None}})
        api = HttpOthersApi("http://127.0.0.1:8000", "token", 10)

        action = api.move_ship(
            {"id": "ship-a", "updatedAt": "2026-09-01T12:00:00+00:00"},
            (2, 0, 0),
        )

        request = urlopen_mock.call_args.args[0]
        self.assertEqual("POST", request.method)
        self.assertEqual(
            "http://127.0.0.1:8000/api/others/ships/ship-a/move",
            request.full_url,
        )
        self.assertEqual(
            {"target": {"x": 2, "y": 0, "z": 0}, "leaveAuxiliariesBehind": False},
            json.loads(request.data),
        )
        self.assertTrue(request.get_header("Idempotency-key").startswith("defense-etoile-"))
        self.assertEqual({"endsAt": None}, action)


if __name__ == "__main__":
    unittest.main()
