"""Réconciliation des observations et engagements d'une sentinelle."""

from __future__ import annotations

from datetime import datetime, timedelta
from typing import Any, Callable

from .commands import CommandExecutor, deuterium_amount, is_movable
from .contracts import require_string
from .events import detect_engagement_events
from .models import (
    Coordinates,
    CycleResult,
    DefensePolicy,
    EngagementEvent,
    EngagementResult,
    EventKind,
    ScoutState,
)
from .observation import ScoutObserver


class EngagementCoordinator:
    def __init__(
        self,
        observer: ScoutObserver,
        commands: CommandExecutor,
        *,
        policy: DefensePolicy,
        logger: Callable[[str], None],
        now: Callable[[], datetime],
    ) -> None:
        self.observer = observer
        self.commands = commands
        self.policy = policy
        self.log = logger
        self.now = now
        self.scout_states: dict[str, ScoutState] = {}

    def clear_completed_return(self, ship_id: str) -> None:
        state = self.scout_states.get(ship_id)
        if state is None:
            return
        state.observation = None
        state.pending_events.clear()
        state.return_required = False
        state.laser_return_due = None

    def reconcile(
        self,
        ship: dict[str, Any],
        coordinates: Coordinates,
        center: Coordinates,
        result: CycleResult,
    ) -> EngagementResult:
        ship_id = require_string(ship.get("id"), "guard ship.id")
        state = self.scout_states.setdefault(ship_id, ScoutState())
        if state.observation is not None and state.observation.coordinates != coordinates:
            state.observation = None
            state.pending_events.clear()
            state.return_required = False
            state.laser_return_due = None

        if state.return_required:
            if state.laser_return_due is not None and self.now() < state.laser_return_due:
                result.event_dates.append(state.laser_return_due)
                return EngagementResult(engaged=True, remains_on_station=True)
            if not is_movable(ship):
                self.log(f"Retour tactique différé pour {ship_id} : vaisseau occupé.")
                return EngagementResult(engaged=True, remains_on_station=True)
            if self.commands.move(ship, center, result):
                self.log(f"Retour tactique de {ship_id} vers le vaisseau mère engagé.")
                self.clear_completed_return(ship_id)
                return EngagementResult(engaged=True, remains_on_station=False)
            return EngagementResult(engaged=True, remains_on_station=True)

        observation = self.observer.observe(ship_id, coordinates)
        new_events = detect_engagement_events(state.observation, observation)
        state.observation = observation
        pending_keys = {event.key for event in state.pending_events}
        state.pending_events.extend(event for event in new_events if event.key not in pending_keys)
        state.pending_events.sort(key=lambda event: (event.kind.priority, event.key))
        engaged = bool(state.pending_events)

        while state.pending_events and not state.return_required:
            event = state.pending_events.pop(0)
            self._execute_event(ship, event, state, result)

        if not state.return_required:
            return EngagementResult(engaged=engaged, remains_on_station=True)
        if state.laser_return_due is not None:
            result.event_dates.append(state.laser_return_due)
            return EngagementResult(engaged=True, remains_on_station=True)
        if not is_movable(ship):
            self.log(f"Retour tactique différé pour {ship_id} : vaisseau occupé.")
            return EngagementResult(engaged=True, remains_on_station=True)
        if self.commands.move(ship, center, result):
            self.log(f"Retour tactique de {ship_id} vers le vaisseau mère engagé.")
            self.clear_completed_return(ship_id)
            return EngagementResult(engaged=True, remains_on_station=False)
        return EngagementResult(engaged=True, remains_on_station=True)

    def _execute_event(
        self,
        ship: dict[str, Any],
        event: EngagementEvent,
        state: ScoutState,
        result: CycleResult,
    ) -> None:
        ship_id = require_string(ship.get("id"), "engagement ship.id")
        missiles = self.commands.available_missiles(ship_id)
        if event.kind is EventKind.DEPLOYED_MANNY:
            has_missiles = bool(missiles)
            self._fire_at_targets(ship_id, missiles, event, result)
            if not has_missiles and deuterium_amount(ship) > self.policy.laser_deuterium_threshold:
                if event.primary_target_id is not None and self.commands.start_laser(
                    ship_id, event.primary_target_id, event.key, result
                ):
                    state.laser_return_due = self.now() + timedelta(
                        seconds=self.policy.laser_engagement_seconds + 1
                    )
                    self.log(
                        f"Laser de {ship_id} verrouillé sur la Manny {event.primary_target_id} "
                        "pour dix minutes."
                    )
            state.return_required = True
            return

        if event.kind is EventKind.HOSTILE_MISSILE:
            self._fire_at_targets(ship_id, missiles, event, result)
            state.return_required = True
            return
        if event.kind is EventKind.EJECTED_MANNY:
            self._fire_at_targets(ship_id, missiles, event, result, include_probe=False)
            return
        if event.kind is EventKind.ASTEROID_TRAJECTORY:
            self._fire_at_targets(ship_id, missiles, event, result)
            return
        if event.kind in {EventKind.FLOATING_OBJECT_CHANGE, EventKind.WAYPOINT_CHANGE}:
            self._fire_at_targets(ship_id, missiles, event, result, include_primary=False)
            state.return_required = True
            return
        raise RuntimeError(f"Type d'engagement inconnu : {event.kind}.")

    def _fire_at_targets(
        self,
        ship_id: str,
        missiles: list[str],
        event: EngagementEvent,
        result: CycleResult,
        *,
        include_primary: bool = True,
        include_probe: bool = True,
    ) -> int:
        targets = []
        if include_primary and event.primary_target_id is not None:
            targets.append(event.primary_target_id)
        if include_probe and event.probe_target_id is not None:
            targets.append(event.probe_target_id)
        fired = 0
        for target_id in targets:
            if not missiles:
                break
            missile_id = missiles[0]
            if not self.commands.launch_missile(
                ship_id, missile_id, target_id, event.key, result
            ):
                continue
            missiles.pop(0)
            fired += 1
        return fired
