from __future__ import annotations

import json
from io import BytesIO
from urllib.error import HTTPError
import unittest
from unittest.mock import Mock, patch

from scripts.others_control.defense_etoile.http_api import HttpOthersApi
from scripts.others_control.defense_etoile.errors import ApiRequestError


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
    def setUp(self) -> None:
        clock = [100.0]
        def advance(delay: float) -> None:
            clock[0] += delay
        self.sleep = self.enterContext(patch(
            "scripts.others_control.defense_etoile.http_api.time.sleep",
            side_effect=advance,
        ))
        self.enterContext(patch(
            "scripts.others_control.defense_etoile.http_api.time.monotonic",
            side_effect=lambda: clock[0],
        ))

    @staticmethod
    def rate_limit(retry_after: str | None = None) -> HTTPError:
        return HTTPError(
            "http://localhost", 429, "Too many requests",
            {} if retry_after is None else {"Retry-After": retry_after},
            BytesIO(b'{"error":{"code":"rate_limit_exceeded"}}'),
        )

    @patch("scripts.others_control.defense_etoile.http_api.urlopen")
    def test_429_resumes_failed_page_without_restarting_collection(self, send: Mock) -> None:
        send.side_effect = [
            FakeResponse({"auxiliaries": [{"id": "a"}], "nextCursor": "next"}),
            self.rate_limit("12"),
            self.rate_limit("2"),
            FakeResponse({"auxiliaries": [{"id": "b"}]}),
        ]
        api = HttpOthersApi("http://localhost", "token", 10, logger=lambda _: None)
        self.assertEqual([{"id": "a"}, {"id": "b"}], api.get_auxiliaries("mother"))
        requests = [c.args[0] for c in send.call_args_list]
        self.assertIs(requests[1], requests[2])
        self.assertIs(requests[2], requests[3])
        self.assertIn("cursor=next", requests[3].full_url)
        self.assertEqual([1.0, 12.0, 2.0], [c.args[0] for c in self.sleep.call_args_list])

    @patch("scripts.others_control.defense_etoile.http_api.urlopen")
    def test_429_retries_identical_command_with_backoff(self, send: Mock) -> None:
        send.side_effect = [self.rate_limit(), self.rate_limit(),
                            FakeResponse({"action": {"id": "done"}})]
        api = HttpOthersApi("http://localhost", "token", 10, logger=lambda _: None)
        self.assertEqual({"id": "done"}, api.start_harvest("mother", "planet", 10, "cycle"))
        requests = [c.args[0] for c in send.call_args_list]
        self.assertTrue(all(r is requests[0] for r in requests))
        self.assertIsNotNone(requests[0].get_header("Idempotency-key"))
        self.assertEqual([5.0, 10.0], [c.args[0] for c in self.sleep.call_args_list])

    @patch("scripts.others_control.defense_etoile.http_api.urlopen")
    def test_other_http_errors_are_not_replayed(self, send: Mock) -> None:
        send.side_effect = HTTPError("http://localhost", 503, "Unavailable", {}, BytesIO(b""))
        api = HttpOthersApi("http://localhost", "token", 10)
        with self.assertRaises(ApiRequestError):
            api.get_inventory("mother")
        self.assertEqual(1, send.call_count)

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
    def test_get_action_encodes_its_identifier(self, urlopen_mock: Mock) -> None:
        urlopen_mock.return_value = FakeResponse({"action": {"id": "action/a"}})
        api = HttpOthersApi("http://127.0.0.1:8000", "token", 10)

        action = api.get_action("action/a")

        self.assertEqual({"id": "action/a"}, action)
        self.assertEqual(
            "http://127.0.0.1:8000/api/others/actions/action%2Fa",
            urlopen_mock.call_args.args[0].full_url,
        )

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

    @patch("scripts.others_control.defense_etoile.http_api.urlopen")
    def test_deuterium_transfer_retries_the_canonical_request(self, send: Mock) -> None:
        send.side_effect = [self.rate_limit("2"), FakeResponse({"action": {"id": "fuel-action"}})]
        api = HttpOthersApi("http://localhost", "token", 10, logger=lambda _: None)
        action = api.start_deuterium_transfer("mother/id", "target", "aux/id", 32.5, "wave")
        request = send.call_args_list[0].args[0]
        self.assertEqual("POST", request.method)
        self.assertEqual("http://localhost/api/others/ships/mother%2Fid/auxiliaries/aux%2Fid/transfer-deuterium", request.full_url)
        self.assertEqual({"targetShipId": "target", "amount": 32.5}, json.loads(request.data))
        self.assertTrue(request.get_header("Idempotency-key").startswith("defense-deuterium-transfer-"))
        self.assertIs(request, send.call_args_list[1].args[0])
        self.assertEqual({"id": "fuel-action"}, action)

    @patch("scripts.others_control.defense_etoile.http_api.urlopen")
    def test_inventory_transfer_builds_the_canonical_request(
        self, urlopen_mock: Mock
    ) -> None:
        urlopen_mock.return_value = FakeResponse(
            {"transfer": {"id": "transfer-a"}, "action": {"endsAt": None}}
        )
        api = HttpOthersApi("http://127.0.0.1:8000", "token", 10)

        action = api.start_inventory_item_transfer(
            "mother", "ship-a", "aux-a", ["missile-a"], "revision-a"
        )

        request = urlopen_mock.call_args.args[0]
        self.assertEqual(
            "http://127.0.0.1:8000/api/others/ships/mother/inventory-transfers",
            request.full_url,
        )
        self.assertEqual(
            {
                "actorAuxiliaryId": "aux-a",
                "targetShipId": "ship-a",
                "kind": "item",
                "itemIds": ["missile-a"],
            },
            json.loads(request.data),
        )
        self.assertTrue(
            request.get_header("Idempotency-key").startswith(
                "defense-inventory-transfer-"
            )
        )
        self.assertEqual({"endsAt": None}, action)


if __name__ == "__main__":
    unittest.main()
