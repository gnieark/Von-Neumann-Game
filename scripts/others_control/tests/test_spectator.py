from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

from scripts.others_control.defense_etoile.http_api import HttpOthersApi
from scripts.others_control.defense_etoile.spectator import SpectatorEvent, SpectatorJournal


def ship(identifier='mother', fleet='fleet_a', kind='mothership', **fields):
    return {'id': identifier, 'fleetId': fleet, 'type': kind, 'status': 'inactive',
            'sector': {'relative': {'x': 0, 'y': 0, 'z': 0}}, 'movement': None,
            'integrity': 100, 'updatedAt': '2026-09-15T12:00:00+00:00', **fields}


def alert(identifier, ship_id='guard', created='2026-09-15T10:00:00+00:00'):
    return {'id': identifier, 'shipId': ship_id, 'status': 'unread', 'createdAt': created,
            'type': 'missile_damage', 'message': f'Impact {identifier}'}


class SpectatorTests(unittest.TestCase):
    def setUp(self):
        self.directory = Path(self.enterContext(tempfile.TemporaryDirectory()))
        self.clock = [1000.0]
        self.diagnostics = []

    def journal(self, mother='mother', fleet='fleet_a', **kwargs):
        journal = SpectatorJournal(self.diagnostics.append, server='https://game.test',
                                   mothership_id=mother, fleet_id=fleet, directory=self.directory,
                                   monotonic=lambda: self.clock[0], **kwargs)
        self.addCleanup(journal.close)
        return journal

    def start(self, journal, mother='mother', fleet='fleet_a', guards=('guard',)):
        journal.fleet({'id': fleet, 'ships': [ship(mother, fleet)] +
                       [ship(identifier, fleet, 'standard') for identifier in guards]})
        return journal

    def contents(self, mother='mother'):
        return (self.directory / f'{mother}.log').read_text(encoding='utf-8')

    def api(self, alerts):
        api = Mock()
        api.get_unread_alerts.return_value = alerts
        api.mark_alerts_read.side_effect = lambda ids: [{'id': i, 'status': 'read'} for i in ids]
        return api

    def test_only_explicit_events_reach_file_and_diagnostics_are_preserved(self):
        journal = self.start(self.journal())
        journal('Prochain contrôle dans 300 s')
        journal(SpectatorEvent('FORMATION', 'Relève de la sentinelle.'))
        text = self.contents()
        self.assertNotIn('Prochain contrôle', text)
        self.assertIn('[FORMATION] Relève', text)
        self.assertEqual(['Prochain contrôle dans 300 s', 'Relève de la sentinelle.'], self.diagnostics)
        self.assertRegex(text, r'\[\d{4}-\d\d-\d\dT.*[+-]\d\d:\d\d\] \[SESSION\]')

    def test_blockage_is_not_repeated_and_harvest_announces_resume(self):
        journal = self.start(self.journal())
        blocked = SpectatorEvent('MOISSON', 'Moisson suspendue : cale pleine.', state='harvest')
        journal(blocked)
        journal(blocked)
        journal.accepted({'id': 'a', 'status': 'queued'}, 'MOISSON', 'Moisson lancée.')
        journal(blocked)
        self.assertEqual(2, self.contents().count('Moisson suspendue'))
        self.assertEqual(1, self.contents().count('Reprise de la moisson'))
        self.assertEqual(3, self.diagnostics.count(str(blocked)))

    def test_account_wide_alerts_are_filtered_and_written_before_acknowledgement(self):
        journal = self.start(self.journal())
        api = self.api([alert('recent', created='2026-09-15T12:00:00+02:00'),
                        alert('foreign', 'other'), alert('old', created='2026-09-15T09:00:00Z')])
        def acknowledge(ids):
            self.assertEqual(['old', 'recent'], ids)
            self.assertTrue(self.contents().index('Impact old') < self.contents().index('Impact recent'))
            state = json.loads(journal.state_path.read_text())
            self.assertEqual({'old', 'recent'}, set(state['writtenAlerts']))
            return [{'id': i, 'status': 'read'} for i in ids]
        api.mark_alerts_read.side_effect = acknowledge
        journal.poll_alerts(api)
        api.mark_alerts_read.assert_called_once_with(['old', 'recent'])
        self.assertNotIn('Impact foreign', self.contents())
        self.assertEqual([], json.loads(journal.state_path.read_text())['writtenAlerts'])
        journal.poll_alerts(api)
        api.get_unread_alerts.assert_called_once()
        self.clock[0] += 299
        journal.poll_alerts(api)
        api.get_unread_alerts.assert_called_once()
        self.clock[0] += 1
        api.get_unread_alerts.return_value = []
        journal.poll_alerts(api)
        self.assertEqual(2, api.get_unread_alerts.call_count)
        self.assertEqual(1, api.mark_alerts_read.call_count)

    def test_two_fleets_sharing_an_account_acknowledge_only_their_own_ships(self):
        first = self.start(self.journal())
        second = self.start(self.journal('mother_b', 'fleet_b'), 'mother_b', 'fleet_b', ('guard_b',))
        api = self.api([alert('a'), alert('b', 'guard_b'), alert('unattributed', 'old_ship')])
        first.poll_alerts(api)
        second.poll_alerts(api)
        self.assertEqual([['a'], ['b']], [c.args[0] for c in api.mark_alerts_read.call_args_list])
        self.assertNotIn('Impact b', self.contents())
        self.assertNotIn('Impact a', self.contents('mother_b'))

    def test_restart_retains_disappeared_ships_and_deduplicates_failed_ack(self):
        first = self.start(self.journal())
        api = self.api([alert('a')])
        api.mark_alerts_read.side_effect = ConnectionError('response lost')
        first.poll_alerts(api)
        first.close()
        restarted = self.start(self.journal(), guards=())
        api.mark_alerts_read.side_effect = lambda ids: [{'id': i, 'status': 'read'} for i in ids]
        restarted.poll_alerts(api)
        self.assertEqual(1, self.contents().count('Impact a'))
        self.assertEqual(2, self.contents().count('[SESSION]'))
        self.assertNotIn('Destruction confirmée', self.contents())
        self.assertIn('guard', restarted.state['ships'])
        self.assertEqual(2, api.mark_alerts_read.call_count)

    def test_write_failure_keeps_alert_unread_and_next_collection_can_retry(self):
        journal = self.start(self.journal())
        api = self.api([alert('a')])
        with patch.object(journal.handler, 'emit', side_effect=OSError('disk full')):
            journal.poll_alerts(api)
        api.mark_alerts_read.assert_not_called()
        self.assertIn('disk full', self.diagnostics[-1])
        self.clock[0] += 300
        journal.poll_alerts(api)
        api.mark_alerts_read.assert_called_once_with(['a'])
        self.assertEqual(1, self.contents().count('Impact a'))

    def test_real_handler_propagates_file_errors_instead_of_silently_acknowledging(self):
        journal = self.start(self.journal())
        api = self.api([alert('a')])
        with patch.object(journal.handler, 'shouldRollover', side_effect=OSError('rotation refused')):
            journal.poll_alerts(api)
        api.mark_alerts_read.assert_not_called()
        self.assertNotIn('Impact a', self.contents())

    def test_state_save_failure_prevents_ack_without_duplicating_on_retry(self):
        journal = self.start(self.journal())
        api = self.api([alert('a')])
        with patch.object(journal, '_save', side_effect=OSError('state full')):
            journal.poll_alerts(api)
        api.mark_alerts_read.assert_not_called()
        self.clock[0] += 300
        journal.poll_alerts(api)
        api.mark_alerts_read.assert_called_once_with(['a'])
        self.assertEqual(1, self.contents().count('Impact a'))

    def test_batches_of_500_and_incomplete_acknowledgement_remains_retryable(self):
        journal = self.start(self.journal())
        api = self.api([alert(f'a{i:04}') for i in range(1001)])
        journal.poll_alerts(api)
        self.assertEqual([500, 500, 1], [len(c.args[0]) for c in api.mark_alerts_read.call_args_list])
        self.clock[0] += 300
        api.get_unread_alerts.return_value = [alert('remaining')]
        api.mark_alerts_read.side_effect = lambda ids: []
        journal.poll_alerts(api)
        self.assertEqual(['remaining'], json.loads(journal.state_path.read_text())['writtenAlerts'])

    def test_corrupted_state_is_not_overwritten_and_no_alerts_are_consumed(self):
        journal = self.start(self.journal())
        journal.close()
        journal.state_path.write_text('{broken', encoding='utf-8')
        second = self.start(self.journal())
        api = self.api([alert('a')])
        second.poll_alerts(api)
        api.get_unread_alerts.assert_not_called()
        self.assertEqual('{broken', journal.state_path.read_text())

    def test_foreign_server_state_is_rejected(self):
        first = self.start(self.journal())
        first.close()
        other = self.journal()
        other.server = 'https://another.test'
        self.start(other)
        self.assertIsNone(other.state)
        self.assertEqual(1, self.contents().count('[SESSION]'))

    def test_rotation_keeps_five_archives_and_append_survives_restart(self):
        journal = self.start(self.journal(max_bytes=300))
        for i in range(30):
            journal(SpectatorEvent('TEST', f'{i} ' + 'Étoiles ' * 15))
        self.assertEqual(6, len(list(self.directory.glob('mother.log*'))))
        self.assertTrue((self.directory / 'mother.log.5').exists())
        journal.close()
        self.start(self.journal(max_bytes=300))
        self.assertIn('[SESSION]', self.contents())
        self.assertTrue(any('29 Étoiles' in p.read_text() for p in self.directory.glob('mother.log*')))

    def test_fleet_id_mode_resolves_mother_and_path_is_independent_of_cwd(self):
        journal = self.start(self.journal(mother=None))
        self.assertEqual('mother', journal.mothership_id)
        self.assertTrue((self.directory / 'mother.log').exists())
        bad = self.journal(mother='../escape', fleet='bad')
        self.start(bad, '../escape', 'bad', ())
        self.assertIsNone(bad.state)
        self.assertFalse((self.directory.parent / 'escape.log').exists())

    def test_movement_arrival_and_repair_are_observed_once_across_restart(self):
        journal = self.start(self.journal())
        journal.accepted({'id': 'move', 'status': 'queued'}, 'DÉPLACEMENT', 'Départ programmé.', movement=('guard', (2, 0, 0)))
        journal.close()
        restarted = self.start(self.journal(), guards=())
        arrival = ship('guard', kind='standard', sector={'relative': {'x': 2, 'y': 0, 'z': 0}}, integrity=80)
        restarted.ship(arrival)
        restarted.ship(arrival)
        restarted.ship({**arrival, 'integrity': 90})
        self.assertEqual(1, self.contents().count('Arrivée constatée de guard'))
        self.assertEqual(1, self.contents().count('Intégrité de guard restaurée'))
        self.assertNotIn('absolute', self.contents())

    def test_missing_action_is_not_assumed_successful_and_actual_result_is_logged_once(self):
        journal = self.start(self.journal())
        action = {'id': 'harvest', 'status': 'queued'}
        journal.accepted(action, 'MOISSON', 'Moisson lancée.')
        journal.accepted(action, 'MOISSON', 'Moisson lancée.')
        journal.fleet({'id': 'fleet_a', 'ships': [ship()], 'activeActions': []})
        self.assertNotIn('terminée', self.contents())
        completed = {**action, 'status': 'succeeded', 'result': {'resources': {'stored': {'metals': 1.25}}}}
        journal.action(completed)
        journal.action(completed)
        self.assertEqual(1, self.contents().count('Action harvest terminée'))
        self.assertIn('métaux : 1.25 ECE', self.contents())

    def test_craft_observations_skip_old_results_and_track_new_completions(self):
        journal = self.start(self.journal())
        old = {'id': 'old', 'status': 'succeeded', 'recipeId': 'missile'}
        active = {'id': 'new', 'status': 'queued', 'recipeId': 'standard_ship'}
        journal.crafts('mother', [old, active])
        journal.crafts('mother', [old, {**active, 'status': 'succeeded', 'result': {'output': {'kind': 'standard_ship', 'id': 'new_ship'}}}])
        journal.crafts('mother', [old, {**active, 'status': 'succeeded'}])
        self.assertEqual(1, self.contents().count('Fabrication standard_ship terminée'))
        self.assertNotIn('Fabrication missile terminée', self.contents())
        self.assertIn('new_ship', self.contents())

    def test_alert_newlines_are_sanitized_and_bad_dates_are_not_acknowledged(self):
        journal = self.start(self.journal())
        api = self.api([{**alert('a'), 'message': 'Impact\n[SESSION] faux\x1b'}])
        journal.poll_alerts(api)
        self.assertEqual(1, len([line for line in self.contents().splitlines() if '[ALERTE]' in line]))
        self.clock[0] += 300
        api.get_unread_alerts.return_value = [alert('bad', created='yesterday')]
        journal.poll_alerts(api)
        self.assertEqual(1, api.mark_alerts_read.call_count)

    def test_http_hooks_log_only_accepted_commands_and_use_batch_route(self):
        journal = self.journal()
        api = HttpOthersApi('https://game.test', 'secret', 10, spectator=journal)
        with patch.object(api, '_request') as request:
            request.return_value = {'fleet': {'id': 'fleet_a', 'ships': [ship(), ship('guard', kind='standard')]}}
            api.get_fleet('fleet_a')
            request.return_value = {'action': {'id': 'harvest', 'status': 'queued', 'endsAt': '2026-09-15T13:00:00Z'}}
            api.start_harvest('mother', 'planet', 20, 'key')
            api.start_harvest('mother', 'planet', 20, 'key')
            self.assertEqual(1, self.contents().count('Moisson lancée'))
            self.assertIn('20 auxiliaire(s)', self.contents())
            self.assertIn('Échéance prévue', self.contents())
            request.side_effect = ConnectionError('rejected')
            with self.assertRaises(ConnectionError):
                api.start_repair('mother', 'aux', 10, 'key')
            self.assertNotIn('Réparation engagée', self.contents())
            request.side_effect = None
            request.return_value = {'alerts': [alert('a')]}
            api.get_unread_alerts()
            request.assert_called_with('GET', '/api/others/alerts?status=unread')
            request.return_value = {'alerts': [{'id': 'a', 'status': 'read'}]}
            api.mark_alerts_read(['a'])
            request.assert_called_with('POST', '/api/others/alerts/mark-read', payload={'alertIds': ['a']})
            self.assertEqual(6, request.call_count)

    def test_hostile_missiles_are_not_repeated_by_several_observers_or_restart(self):
        journal = self.start(self.journal())
        sector = {'objects': [{'type': 'missile', 'id': 'missile', 'status': 'moving', 'launcherKind': 'probe', 'targetId': 'mother'}]}
        journal.scan('mother', (0, 0, 0), sector)
        journal.scan('guard', (0, 0, 0), sector)
        journal.close()
        restarted = self.start(self.journal())
        restarted.scan('mother', (0, 0, 0), sector)
        self.assertEqual(1, self.contents().count('Missile hostile missile'))


if __name__ == '__main__':
    unittest.main()
