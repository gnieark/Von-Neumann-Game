const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const {test} = require('node:test');
const {runInNewContext} = require('node:vm');

async function alertsPage(persistentAlerts = []) {
    const elements = new Map();
    const element = (id) => {
        if (!elements.has(id)) {
            elements.set(id, {
                disabled: true, textContent: '', innerHTML: '', listeners: {},
                addEventListener(event, callback) { this.listeners[event] = callback; },
            });
        }
        return elements.get(id);
    };
    const state = {
        alerts: persistentAlerts, acknowledged: new Set(), calls: [], syncs: 0,
        fail: false, waitForMutation: null, signature: 'container-700',
        sector: {relativeCoordinates: {x: 0, y: 0, z: 0}},
    };
    let initialize;
    const document = {
        body: {dataset: {authenticated: '1'}},
        getElementById: element,
        querySelector: () => element('refresh'),
        addEventListener: (_, callback) => { initialize = callback; },
    };
    const window = {
        setTimeout: () => 1, clearTimeout() {},
        VNG: {
            loadI18n: async () => ({}), t: (_, key, fallback) => fallback,
            escapeHtml: String, probeApiPath: (suffix) => '/api/probe/700' + suffix,
            sectorAlerts: () => [{
                type: 'detached_container', signature: state.signature, message: 'Detached container',
                acknowledged: state.acknowledged.has(state.signature),
            }],
            acknowledgeSectorAlert(type, sector, signature) {
                assert.equal(type, 'detached_container');
                assert.equal(sector, state.sector);
                state.acknowledged.add(signature);
            },
            setNavigationWarning() {}, setProbeUnreachablePanel() {},
            renderUnreachableProbeTelemetry: async () => false,
            syncNavigationWarnings: async () => { state.syncs++; }, nextRefreshDelay: () => 15000,
            async apiJson(path, options) {
                state.calls.push([options.method, path]);
                if (options.method === 'GET') {
                    return path.endsWith('/sector') ? {sector: state.sector} : {alerts: state.alerts};
                }
                if (state.waitForMutation) await state.waitForMutation;
                if (state.fail) throw new Error('API failure');
                if (options.method === 'POST') state.alerts.forEach((alert) => { alert.status = 'read'; });
                if (options.method === 'DELETE') state.alerts = [];
                return {};
            },
        },
    };
    runInNewContext(readFileSync(join(__dirname, '../public/assets/alerts.js'), 'utf8'), {window, document});
    initialize();
    await new Promise(setImmediate);
    return {state, element, click: (id) => element(id).listeners.click()};
}

test('a detached-container warning alone enables mark-all-read and is acknowledged locally', async () => {
    const {state, element, click} = await alertsPage();
    assert.equal(element('alerts-mark-all-read').disabled, false);
    assert.equal(element('alerts-delete-all').disabled, true);
    await click('alerts-mark-all-read');
    assert(state.acknowledged.has('container-700'));
    assert.equal(element('alerts-mark-all-read').disabled, true);
    assert.equal(state.calls.filter(([method]) => method !== 'GET').length, 0);
    assert.equal(state.syncs, 1);
    assert(element('console-alerts-list').innerHTML.includes('Detached container'));
    state.signature = 'container-701';
    await click('refresh');
    assert.equal(element('alerts-mark-all-read').disabled, false, 'a newly detected container is not pre-acknowledged');
});

test('mixed alerts use the selected-probe API and acknowledge sector warnings after success', async () => {
    const {state, element, click} = await alertsPage([{id: 1, type: 'others_weapon', status: 'unread', message: 'Missile'}]);
    state.fail = true;
    await click('alerts-mark-all-read');
    assert.equal(state.acknowledged.size, 0);
    assert.equal(element('alerts-action-status').textContent, 'API failure');
    assert.equal(element('alerts-mark-all-read').disabled, false);
    state.fail = false;
    let release;
    state.waitForMutation = new Promise((resolve) => { release = resolve; });
    const pending = click('alerts-mark-all-read');
    assert.equal(element('alerts-mark-all-read').disabled, true);
    assert.equal(element('alerts-delete-all').disabled, true);
    await click('alerts-delete-all');
    assert.equal(state.calls.filter(([method]) => method === 'DELETE').length, 0);
    release();
    await pending;
    assert(state.calls.some(([method, path]) => method === 'POST' && path === '/api/probe/700/alerts/mark-all-read'));
    assert.equal(state.alerts[0].status, 'read');
    assert(state.acknowledged.has('container-700'));
    assert.equal(element('alerts-mark-all-read').disabled, true);
    assert.equal(element('alerts-delete-all').disabled, false);
    assert.equal(element('alerts-action-status').textContent, '');
});

test('bulk deletion removes persistent alerts while leaving sector warnings unacknowledged', async () => {
    const {state, element, click} = await alertsPage([{id: 1, type: 'others_weapon', status: 'unread', message: 'Missile'}]);
    await click('alerts-delete-all');
    assert(state.calls.some(([method, path]) => method === 'DELETE' && path === '/api/probe/700/alerts'));
    assert.equal(state.alerts.length, 0);
    assert.equal(state.acknowledged.size, 0);
    assert.equal(element('alerts-mark-all-read').disabled, false);
    assert.equal(element('alerts-delete-all').disabled, true);
    assert(element('console-alerts-list').innerHTML.includes('Detached container'));
});
