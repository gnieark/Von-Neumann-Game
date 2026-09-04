"""Types métier partagés par le contrôleur."""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime, timezone
from enum import StrEnum
from typing import Any

from .contracts import parse_api_datetime

Coordinates = tuple[int, int, int]


class EventKind(StrEnum):
    HOSTILE_MISSILE = "hostile_missile"
    ASTEROID_TRAJECTORY = "asteroid_trajectory"
    DEPLOYED_MANNY = "deployed_manny"
    EJECTED_MANNY = "ejected_manny"
    FLOATING_OBJECT_CHANGE = "floating_object_change"
    WAYPOINT_CHANGE = "waypoint_change"

    @property
    def priority(self) -> int:
        return {
            EventKind.HOSTILE_MISSILE: 0,
            EventKind.ASTEROID_TRAJECTORY: 1,
            EventKind.DEPLOYED_MANNY: 2,
            EventKind.EJECTED_MANNY: 3,
            EventKind.FLOATING_OBJECT_CHANGE: 4,
            EventKind.WAYPOINT_CHANGE: 5,
        }[self]


@dataclass(frozen=True)
class DefensePolicy:
    max_movement_distance: int = 10
    laser_engagement_seconds: int = 600
    laser_deuterium_threshold: float = 12.0


@dataclass
class CycleResult:
    accepted_commands: int = 0
    event_dates: list[datetime] = field(default_factory=list)

    def add_event_date(self, value: Any, context: str) -> None:
        if value is None:
            return
        self.event_dates.append(parse_api_datetime(value, context))

    def sleep_seconds(self, idle_refresh_seconds: float) -> float:
        now = datetime.now(timezone.utc)
        future_delays = [
            max(1.0, (event_date - now).total_seconds() + 1.0)
            for event_date in self.event_dates
            if event_date > now
        ]
        if not future_delays:
            return idle_refresh_seconds
        return min(idle_refresh_seconds, min(future_delays))


@dataclass(frozen=True)
class EngagementEvent:
    kind: EventKind
    key: str
    primary_target_id: str | None = None
    probe_target_id: str | None = None


@dataclass
class ScoutObservation:
    coordinates: Coordinates
    autonomous_units: dict[str, tuple[str, str]]
    ejected_mannies: dict[str, str]
    missiles: dict[str, str]
    trajectories: dict[str, str]
    floating_objects: dict[str, str]
    waypoints: dict[str, str]
    probe_ids: tuple[str, ...]


@dataclass
class ScoutState:
    observation: ScoutObservation | None = None
    pending_events: list[EngagementEvent] = field(default_factory=list)
    return_required: bool = False
    laser_return_due: datetime | None = None


@dataclass(frozen=True)
class EngagementResult:
    engaged: bool
    remains_on_station: bool
