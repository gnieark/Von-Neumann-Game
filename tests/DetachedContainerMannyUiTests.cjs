const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const {test} = require('node:test');
const {runInNewContext} = require('node:vm');

function loadPage() {
    const source = readFileSync(join(__dirname, '../public/assets/mannies.js'), 'utf8');
    const end = source.lastIndexOf('\n}');
    const calls = [];
    const window = {VNG: {
        t: (_i18n, _key, fallback) => fallback,
        escapeHtml: (value) => String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'),
        probeApiPath: (path) => '/api/probe' + path,
        apiJson: (path, options) => { calls.push({path, ...options}); },
    }};
    const document = {body: {dataset: {authenticated: '0'}}, addEventListener() {}};
    runInNewContext(source.slice(0, end) + '\nwindow.testPage = {state, abandonedContainerMannyTargets, renderRecoverContainerMannyForm, submitMannyForm};' + source.slice(end), {window, document});
    return {...window.testPage, calls};
}

test('individual recovery only offers occupants revealed by inspection', () => {
    const page = loadPage();
    page.state.currentSectorObjects = [
        {id: 'uninspected', type: 'detached_container'},
        {id: 'inspected', type: 'detached_container', name: 'Container', abandonedMannies: [{id: 'manny-1', name: '<Manny>', state: 'abandoned'}]},
        {id: 'drifting', type: 'manny', mannyState: 'abandoned'},
    ];
    const targets = page.abandonedContainerMannyTargets();
    assert.equal(targets.length, 1);
    assert.equal(targets[0].objectId, 'inspected');
    assert.equal(targets[0].mannyId, 'manny-1');
    const html = page.renderRecoverContainerMannyForm();
    assert.match(html, /&lt;Manny>/);
    assert.match(html, /\[&quot;inspected&quot;,&quot;manny-1&quot;\]/);
    assert.doesNotMatch(html, / disabled/);
    page.state.currentSectorObjects = [];
    assert.match(page.renderRecoverContainerMannyForm(), / disabled/);
});

test('individual recovery submits both the container and occupant identifiers', async () => {
    const page = loadPage();
    const form = {classList: {contains: (name) => name === 'manny-recover-container-manny-form'}};
    await page.submitMannyForm(form, 'actor', new Map([['target', JSON.stringify(['container', 'occupant'])]]));
    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].path, '/api/probe/mannies/actor/recover-storage-container');
    assert.equal(page.calls[0].method, 'POST');
    assert.deepEqual(JSON.parse(page.calls[0].body), {objectId: 'container', mannyId: 'occupant'});
});
