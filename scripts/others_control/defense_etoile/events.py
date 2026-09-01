"""Détection pure des changements tactiques entre deux observations."""

from __future__ import annotations

from .identifiers import event_key
from .models import EngagementEvent, EventKind, ScoutObservation


def detect_engagement_events(
    previous: ScoutObservation | None,
    current: ScoutObservation,
) -> list[EngagementEvent]:
    events: list[EngagementEvent] = []
    default_probe = current.probe_ids[0] if current.probe_ids else None

    previous_units = previous.autonomous_units if previous is not None else {}
    for manny_id, (carrier_id, spatial_state) in current.autonomous_units.items():
        if previous is not None and previous_units.get(manny_id) == (carrier_id, spatial_state):
            continue
        events.append(
            EngagementEvent(
                EventKind.DEPLOYED_MANNY,
                event_key(EventKind.DEPLOYED_MANNY, manny_id, carrier_id, spatial_state),
                primary_target_id=manny_id,
                probe_target_id=carrier_id,
            )
        )

    previous_missiles = previous.missiles if previous is not None else {}
    for missile_id, signature in current.missiles.items():
        if previous is not None and previous_missiles.get(missile_id) == signature:
            continue
        events.append(
            EngagementEvent(
                EventKind.HOSTILE_MISSILE,
                event_key(EventKind.HOSTILE_MISSILE, missile_id, signature),
                primary_target_id=missile_id,
                probe_target_id=default_probe,
            )
        )

    previous_trajectories = previous.trajectories if previous is not None else {}
    for asteroid_id, signature in current.trajectories.items():
        if previous is not None and previous_trajectories.get(asteroid_id) == signature:
            continue
        events.append(
            EngagementEvent(
                EventKind.ASTEROID_TRAJECTORY,
                event_key(EventKind.ASTEROID_TRAJECTORY, asteroid_id, signature),
                primary_target_id=asteroid_id,
                probe_target_id=default_probe,
            )
        )

    previous_ejected = previous.ejected_mannies if previous is not None else {}
    for manny_id, signature in current.ejected_mannies.items():
        if previous is not None and manny_id in previous_ejected:
            continue
        events.append(
            EngagementEvent(
                EventKind.EJECTED_MANNY,
                event_key(EventKind.EJECTED_MANNY, manny_id, signature),
                primary_target_id=manny_id,
            )
        )

    if previous is not None:
        changed_floating_ids = sorted(
            object_id
            for object_id in set(previous.floating_objects) | set(current.floating_objects)
            if previous.floating_objects.get(object_id) != current.floating_objects.get(object_id)
        )
        for object_id in changed_floating_ids:
            events.append(
                EngagementEvent(
                    EventKind.FLOATING_OBJECT_CHANGE,
                    event_key(
                        EventKind.FLOATING_OBJECT_CHANGE,
                        object_id,
                        current.floating_objects.get(object_id, "removed"),
                    ),
                    probe_target_id=default_probe,
                )
            )

        changed_waypoint_ids = sorted(
            object_id
            for object_id in set(previous.waypoints) | set(current.waypoints)
            if previous.waypoints.get(object_id) != current.waypoints.get(object_id)
        )
        for object_id in changed_waypoint_ids:
            events.append(
                EngagementEvent(
                    EventKind.WAYPOINT_CHANGE,
                    event_key(
                        EventKind.WAYPOINT_CHANGE,
                        object_id,
                        current.waypoints.get(object_id, "removed"),
                    ),
                    probe_target_id=default_probe,
                )
            )
    return events
