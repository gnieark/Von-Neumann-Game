from __future__ import annotations

import copy
import json
import tempfile
import unittest
from pathlib import Path

from scripts.others_control.defense_etoile.controller import DefenseEtoileAttente
from scripts.others_control.defense_etoile.depot_logistics import DepotLogistics, route
from scripts.others_control.defense_etoile.errors import ApiRequestError, ConfigurationError
from scripts.others_control.defense_etoile.models import CycleResult
from scripts.others_control.tests.support import FakeApi, auxiliary, detailed_scan, ship, sector


class DepotApi(FakeApi):
    """Les commandes réservent immédiatement, le worker simulé termine explicitement."""
    def __init__(self):
        super().__init__([ship('mother', (0, 0, 0), ship_type='mothership', deuterium=100),
                          ship('courier', (0, 0, 0)), ship('second', (0, 0, 0))],
                         auxiliaries={key: [auxiliary('aux-' + key)] for key in ('mother', 'courier', 'second')},
                         resources={'mother': {'metals': 40, 'ice': 20, 'carbon_compounds': 20, 'deuterium': 20}})
        self.capacities = {'mother': 100, 'courier': 20, 'second': 20}
        self.known = []
        self.depots = {}
        self.requests = []
        self.operations = {}
        self.effects = {}
        self.lose_response = False
        self.reject = None

    def get_inventory(self, ship_id):
        value = super().get_inventory(ship_id)
        value['capacityEce'] = self.capacities[ship_id]
        value['reservedEce'] = sum(args[4] for kind, args in self.effects.values()
                                   if kind == 'load' and args[1] == ship_id)
        return value

    def get_known_depots(self, fleet_id):
        return [{'relativeCoordinates': dict(zip(('x', 'y', 'z'), point))} for point in self.known]

    def add_depot(self, point, identifier='depot'):
        self.known.append(point)
        self.depots[identifier] = point
        self.scans[point] = detailed_scan(objects=[{'id': identifier, 'type': 'dormant_construct'}])

    def get_depot_inventory(self, depot_id):
        if depot_id not in self.depots:
            raise ApiRequestError(404, 'target_not_found', 'not a depot')
        return {'resources': [], 'items': []}

    def issue(self, kind, args, key):
        if key in self.operations:
            return self.actions[self.operations[key]]
        if self.reject:
            raise self.reject
        identifier = 'action-' + str(len(self.requests))
        action = {'id': identifier, 'type': {'build': 'build_germination_depot', 'deposit': 'depot_deposit',
                  'load': 'inventory_transfer', 'fuel': 'deuterium_transfer', 'move': 'movement'}[kind],
                  'status': 'queued', 'endsAt': '2099-01-01T00:00:00+00:00'}
        self.requests.append((kind, copy.deepcopy(args), key))
        self.operations[key] = identifier
        self.effects[identifier] = (kind, copy.deepcopy(args))
        self.actions[identifier] = action
        self.active_actions.append(action)
        if kind != 'move':
            actor_id = args[2] if kind in {'load', 'fuel'} else args[1]
            actor = next(item for item in self.auxiliaries[args[0]] if item['id'] == actor_id)
            actor.update(status='busy', action=action)
        if kind == 'load':
            self.resource_reservations.setdefault(args[0], {})[args[3]] = args[4]
        if kind == 'deposit':
            self.resource_reservations[args[0]] = dict(args[3])
        if kind == 'move':
            live = self.get_ship(args[0]['id'])
            live.update(status='preparing', movement={'target': dict(zip(('x', 'y', 'z'), args[1])),
                                                     'arrivalAt': action['endsAt'], 'phase': 'transit'})
            self.moves.append((live['id'], tuple(args[1])))
        if self.lose_response:
            self.lose_response = False
            raise ConnectionError('response lost after commit')
        return action

    def start_germination_depot(self, ship_id, aux_id, key):
        return self.issue('build', [ship_id, aux_id], key)

    def start_depot_deposit(self, ship_id, aux_id, depot_id, resources, key):
        return self.issue('deposit', [ship_id, aux_id, depot_id, resources], key)

    def start_inventory_resource_transfer(self, source, target, aux_id, resource, amount, key):
        return self.issue('load', [source, target, aux_id, resource, amount], key)

    def start_deuterium_transfer(self, source, target, aux_id, amount, key):
        return self.issue('fuel', [source, target, aux_id, amount], key)

    def move_ship(self, item, target):
        return self.issue('move', [copy.deepcopy(item), target], item['id'] + item['updatedAt'] + str(tuple(target)))

    def finish(self, identifier=None, status='succeeded'):
        if identifier is None:
            identifier = next(iter(self.effects))
        kind, args = self.effects.pop(identifier)
        action = self.actions[identifier]
        action['status'] = status
        for assistants in self.auxiliaries.values():
            for assistant in assistants:
                if assistant.get('action') is action:
                    assistant.update(status='inactive', action=None)
        if kind == 'load':
            self.resource_reservations[args[0]][args[3]] = 0
            if status == 'succeeded':
                self.resources[args[0]][args[3]] -= args[4]
                stock = self.resources.setdefault(args[1], {})
                stock[args[3]] = stock.get(args[3], 0) + args[4]
        if kind == 'deposit':
            self.resource_reservations[args[0]] = {}
            if status == 'succeeded':
                for key, amount in args[3].items():
                    self.resources[args[0]][key] -= amount
        if kind == 'build' and status == 'succeeded':
            self.resources[args[0]]['metals'] -= 2
            self.add_depot((0, 0, 0))
        if kind == 'fuel' and status == 'succeeded':
            self.get_ship(args[0])['deuterium']['amount'] -= args[3]
            self.get_ship(args[1])['deuterium']['amount'] += args[3]
        if kind == 'move':
            item = self.get_ship(args[0]['id'])
            item.update(status='inactive', movement=None, updatedAt=identifier)
            if status == 'succeeded':
                item['sector'] = sector(tuple(args[1]))
        return action


class DepotLogisticsTests(unittest.TestCase):
    def test_drain_only_does_not_create_new_work_for_a_full_mothership(self):
        self.assertFalse(self.worker.reconcile(self.api.ships[0], self.api.ships,
                                              CycleResult(), allow_new=False))
        self.assertEqual([], self.api.requests)

    def test_drain_only_completes_the_existing_courier_mission(self):
        self.api.add_depot((20, 0, 0))
        self.cycle()
        self.assertEqual({"courier"}, self.worker.reserved_ships("fleet_test"))
        for _ in range(30):
            if self.api.effects:
                self.api.finish()
            self.worker.reconcile(self.api.ships[0], self.api.ships, CycleResult(), allow_new=False)
            if not self.worker.reserved_ships("fleet_test"):
                break
        self.assertEqual(set(), self.worker.reserved_ships("fleet_test"))
        self.assertEqual(("courier", (0, 0, 0)), self.api.moves[-1])
        self.assertTrue(all(ship_id == "courier" for ship_id, _ in self.api.moves))

    def setUp(self):
        self.api = DepotApi()
        self.logs = []
        self.directory = self.enterContext(tempfile.TemporaryDirectory())
        self.worker = self.restart()

    def restart(self):
        return DepotLogistics(self.api, logger=self.logs.append, state_dir=Path(self.directory))

    def cycle(self, worker=None):
        result = CycleResult()
        blocked = (worker or self.worker).reconcile(self.api.ships[0], self.api.ships, result)
        return blocked, result

    def test_full_without_known_depot_builds_once_and_waits_for_completion(self):
        self.cycle()
        self.cycle(self.restart())
        self.assertEqual(['build'], [r[0] for r in self.api.requests])
        self.api.finish()
        self.cycle()
        self.cycle()
        self.assertEqual(1, len(self.api.requests))

    def test_only_actual_fullness_triggers_overflow(self):
        self.api.resources['mother']['metals'] = 39.99
        self.assertFalse(self.cycle()[0])
        self.assertEqual([], self.api.requests)

    def test_local_depot_receives_half_of_every_unreserved_resource(self):
        self.api.add_depot((0, 0, 0))
        self.api.resource_reservations['mother'] = {'metals': 10}
        self.cycle()
        kind, args, _ = self.api.requests[0]
        self.assertEqual('deposit', kind)
        self.assertEqual({'metals': 15, 'ice': 10, 'carbon_compounds': 10, 'deuterium': 10}, args[3])
        self.assertEqual(100, self.api.ships[0]['deuterium']['amount'])
        self.cycle(self.restart())
        self.assertEqual(1, len(self.api.requests))

    def test_unknown_existing_local_depot_is_reused(self):
        self.api.add_depot((0, 0, 0))
        self.api.known = []
        self.cycle()
        self.assertEqual('deposit', self.api.requests[0][0])

    def test_dormant_construct_is_not_mistaken_for_depot(self):
        self.api.scans[(0, 0, 0)] = detailed_scan(objects=[{'type': 'dormant_construct', 'id': 'ancient'}])
        self.cycle()
        self.assertEqual('build', self.api.requests[0][0])

    def test_reserved_or_unavailable_auxiliary_prevents_build(self):
        self.api.auxiliaries['mother'][0]['status'] = 'busy'
        self.cycle()
        self.assertEqual([], self.api.requests)

    def test_existing_build_is_observed_without_a_local_journal(self):
        action = {'id': 'external', 'type': 'build_germination_depot', 'status': 'queued', 'endsAt': '2099-01-01T00:00:00+00:00'}
        self.api.auxiliaries['mother'].append(auxiliary('external', status='busy', action=action))
        _, result = self.cycle()
        self.assertEqual([], self.api.requests)
        self.assertTrue(result.event_dates)

    def test_remote_round_trip_loads_every_resource_unloads_and_returns(self):
        self.api.add_depot((12, 0, 0))
        self.cycle()
        self.assertEqual('load', self.api.requests[0][0])
        self.assertEqual(8, self.api.requests[0][1][4])
        for _ in range(30):
            if self.api.effects:
                self.api.finish()
            self.worker = self.restart()
            self.cycle()
            if not self.worker.reserved_ships('fleet_test'):
                break
        self.assertFalse(self.worker.reserved_ships('fleet_test'))
        loads = [args for kind, args, _ in self.api.requests if kind == 'load']
        self.assertEqual({'metals', 'ice', 'carbon_compounds', 'deuterium'}, {args[3] for args in loads})
        self.assertEqual(20, sum(args[4] for args in loads))
        self.assertEqual([('courier', (10, 0, 0)), ('courier', (12, 0, 0)),
                          ('courier', (2, 0, 0)), ('courier', (0, 0, 0))], self.api.moves)
        self.assertEqual(0, sum(self.api.resources['courier'].values()))

    def test_loading_waits_for_worker_before_departure(self):
        self.api.add_depot((2, 0, 0))
        self.cycle()
        self.cycle()
        self.assertEqual([], self.api.moves)
        self.assertEqual(1, len(self.api.requests))

    def test_second_ship_can_leave_while_first_is_away(self):
        self.api.add_depot((2, 0, 0))
        self.cycle()
        for _ in range(4):
            self.api.finish()
            self.cycle()
        self.assertEqual('move', self.api.requests[-1][0])
        self.api.resources['mother'] = {'metals': 40, 'ice': 20, 'carbon_compounds': 20, 'deuterium': 20}
        self.cycle()
        self.assertEqual({'courier', 'second'}, self.worker.reserved_ships('fleet_test'))
        self.assertEqual('second', self.api.requests[-1][1][1])

    def test_response_lost_after_acceptance_replays_identical_command_after_restart(self):
        self.api.add_depot((2, 0, 0))
        self.api.lose_response = True
        with self.assertRaises(ConnectionError):
            self.cycle()
        self.cycle(self.restart())
        self.assertEqual(1, len(self.api.requests))
        self.assertEqual(8, self.api.resource_reservations['mother']['metals'])

    def test_failed_loading_retries_from_current_stock(self):
        self.api.add_depot((2, 0, 0))
        self.cycle()
        self.api.finish(status='failed')
        self.cycle()
        self.assertEqual(['load', 'load'], [r[0] for r in self.api.requests])
        self.assertNotEqual(self.api.requests[0][2], self.api.requests[1][2])

    def test_rejected_command_leaves_no_phantom_action(self):
        self.api.reject = ApiRequestError(409, 'busy', 'busy')
        self.cycle()
        self.api.reject = None
        self.cycle()
        self.cycle()
        self.assertEqual(['build'], [r[0] for r in self.api.requests])

    def test_courier_is_refueled_before_loading_for_entire_round_trip(self):
        self.api.add_depot((22, 0, 0))
        self.api.ships[1]['deuterium']['amount'] = 1
        self.cycle()
        self.assertEqual('fuel', self.api.requests[0][0])
        self.assertEqual(11, self.api.requests[0][1][3])
        self.api.finish()
        self.cycle()
        self.assertEqual('load', self.api.requests[-1][0])

    def test_no_ship_without_auxiliary_is_selected(self):
        self.api.add_depot((2, 0, 0))
        self.api.auxiliaries['courier'] = []
        self.api.auxiliaries['second'] = []
        self.cycle()
        self.assertEqual([], self.api.requests)
        self.assertFalse(self.worker.reserved_ships('fleet_test'))

    def test_dead_ship_is_released(self):
        self.api.add_depot((2, 0, 0))
        self.cycle()
        self.api.ships[1]['status'] = 'destroyed'
        self.cycle()
        self.assertNotIn('courier', self.worker.reserved_ships('fleet_test'))

    def test_malformed_state_fails_without_forgetting_couriers(self):
        self.worker.reserved_ships('fleet_test')
        self.worker.path.write_text('{"unexpected": true}')
        with self.assertRaises(ConfigurationError):
            self.restart().reserved_ships('fleet_test')

    def test_defense_does_not_deploy_or_recall_logistics_ship(self):
        self.api.add_depot((2, 0, 0))
        self.cycle()
        controller = DefenseEtoileAttente(self.api, mothership_id='mother', logger=self.logs.append,
                                         logistics_state_dir=Path(self.directory))
        controller.run_cycle()
        self.assertNotIn('courier', [identifier for identifier, _ in self.api.moves])
        self.assertIn('courier', controller.depots.reserved_ships('fleet_test'))
        self.assertEqual([], self.api.craft_starts)
        self.assertEqual([], self.api.harvest_starts)

    def test_routes_respect_fcc_and_range(self):
        for destination in [(45, -13, 2), (21, 21, 0), (0, 0, 2)]:
            previous = (0, 0, 0)
            for point in route(previous, destination):
                self.assertEqual(0, sum(point) % 2)
                self.assertLessEqual(max(abs(a-b) for a,b in zip(previous, point)), 10)
                previous = point
            self.assertEqual(destination, previous)

    def test_activity_defense_does_not_recall_a_courier_at_its_depot(self):
        self.api.add_depot((2, 0, 0))
        self.cycle()
        controller = DefenseEtoileAttente(self.api, mothership_id='mother', logger=self.logs.append,
                                         logistics_state_dir=Path(self.directory))
        controller.run_cycle()
        self.api.finish()
        self.api.ships[1]['sector'] = sector((2, 0, 0))
        self.api.scans[(0, 0, 0)] = detailed_scan(probes=[{'id': 'probe'}])
        self.api.moves.clear()
        controller.run_activity_cycle()
        self.assertNotIn('courier', [identifier for identifier, _ in self.api.moves])

    def test_lost_move_response_is_resumed_without_second_departure(self):
        self.api.add_depot((2, 0, 0))
        self.cycle()
        for _ in range(3):
            self.api.finish()
            self.cycle()
        self.api.finish()
        self.api.lose_response = True
        with self.assertRaises(ConnectionError):
            self.cycle()
        self.cycle(self.restart())
        self.assertEqual([('courier', (2, 0, 0))], self.api.moves)

    def test_deposit_waits_for_completion_before_return(self):
        self.api.add_depot((2, 0, 0))
        self.cycle()
        for _ in range(5):
            self.api.finish()
            self.cycle()
        self.assertEqual('deposit', self.api.requests[-1][0])
        self.cycle(self.restart())
        self.assertEqual([('courier', (2, 0, 0))], self.api.moves)

    def test_inventory_items_alone_do_not_produce_empty_deposit(self):
        self.api.add_depot((0, 0, 0))
        self.api.resources['mother'] = {}
        self.api.inventories['mother'] = [{'id': 'object', 'type': 'part', 'containerSpaceEce': 100}]
        self.cycle()
        self.assertEqual([], self.api.requests)

    def test_nearest_depot_is_chosen(self):
        self.api.add_depot((12, 0, 0), 'far')
        self.api.add_depot((2, 0, 0), 'near')
        self.cycle()
        self.assertEqual({'x': 2, 'y': 0, 'z': 0}, self.worker.state['missions']['courier']['destination'])


if __name__ == '__main__':
    unittest.main()
