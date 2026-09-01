from __future__ import annotations

import unittest

from scripts.others_control.defense_etoile.events import detect_engagement_events
from scripts.others_control.defense_etoile.models import EventKind, ScoutObservation


def observation(
    *,
    autonomous_units: dict[str, tuple[str, str]] | None = None,
    missiles: dict[str, str] | None = None,
    probe_ids: tuple[str, ...] = (),
) -> ScoutObservation:
    return ScoutObservation(
        coordinates=(0, 0, 0),
        autonomous_units=autonomous_units or {},
        ejected_mannies={},
        missiles=missiles or {},
        trajectories={},
        floating_objects={},
        waypoints={},
        probe_ids=probe_ids,
    )


class EventDetectionTests(unittest.TestCase):
    def test_unchanged_observation_produces_no_event(self) -> None:
        current = observation(
            autonomous_units={"manny-a": ("probe-a", "landed")},
            missiles={"missile-a": "signature"},
        )

        self.assertEqual([], detect_engagement_events(current, current))

    def test_detected_events_expose_typed_priorities(self) -> None:
        current = observation(
            autonomous_units={"manny-a": ("probe-a", "landed")},
            missiles={"missile-a": "signature"},
            probe_ids=("probe-a",),
        )

        events = detect_engagement_events(None, current)

        self.assertEqual(
            [EventKind.HOSTILE_MISSILE, EventKind.DEPLOYED_MANNY],
            [event.kind for event in sorted(events, key=lambda event: event.kind.priority)],
        )
        self.assertTrue(all(event.key for event in events))


if __name__ == "__main__":
    unittest.main()
