const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const {test} = require('node:test');
const {runInNewContext} = require('node:vm');

function loadPage(name, functions) {
    const source = readFileSync(join(__dirname, '../public/assets/', name + '.js'), 'utf8');
    const end = source.lastIndexOf('\n}');
    const window = {VNG: {t: (_i18n, _key, fallback) => fallback, escapeHtml: String, formatText: (text, values) => text.replace(/\{(\w+)\}/g, (_match, key) => values[key])}};
    const document = {body: {dataset: {authenticated: '0'}}, addEventListener() {}};
    runInNewContext(source.slice(0, end) + '\nwindow.testPage = {state, ' + functions.join(', ') + '};' + source.slice(end), {window, document});
    return window.testPage;
}

const objects = [
    {id: 'rock', type: 'asteroid', mannyMineable: true},
    {id: 'wreck', type: 'dormant_construct', mannyMineable: true},
    {id: 'empty-wreck', type: 'dormant_construct', mannyMineable: false},
    {id: 'mystery', type: 'dormant_construct'},
    {id: 'planet', type: 'planet', mannyMineable: true},
];

for (const name of ['mannies', 'inventories']) {
    test(name + ' hiding choices include exhausted wrecks and exclude other dormant structures', () => {
        const page = loadPage(name, ['containerHidingTargets']);
        page.state.currentSectorObjects = objects;
        assert.deepEqual(Array.from(page.containerHidingTargets('hidden_on_dormant_construct'), (object) => object.id), ['wreck', 'empty-wreck']);
        assert.deepEqual(Array.from(page.containerHidingTargets('hidden_on_asteroid'), (object) => object.id), ['rock']);
    });
}

test('dormant caches are hidden mining destinations on their own support, with correct labels', () => {
    const page = loadPage('mannies', ['detachedContainerTargetsFromObjects', 'miningStorageTargetsForTarget', 'miningStorageTargetLabel', 'miningTaskTargetContainerDetail']);
    const containers = page.detachedContainerTargetsFromObjects([
        {id: 'cache', type: 'detached_container', mode: 'hidden_on_dormant_construct', targetObjectId: 'wreck'},
        {id: 'drifting', type: 'detached_container', mode: 'drifting'},
    ]);
    assert.equal(containers[0].hidden, true);
    assert.equal(containers[0].source, 'dormant_construct');
    assert.deepEqual(Array.from(page.miningStorageTargetsForTarget({id: 'wreck'}, containers), (object) => object.id), ['cache', 'drifting']);
    assert.deepEqual(Array.from(page.miningStorageTargetsForTarget({id: 'rock'}, containers), (object) => object.id), ['drifting']);
    assert.match(page.miningStorageTargetLabel(containers[0]), /hidden on dormant construct/);
    assert.match(page.miningTaskTargetContainerDetail({targetContainer: {id: 'cache', mode: 'hidden_on_dormant_construct'}}), /hidden on dormant construct/);
});

test('inventory detachment updates its target options when switching hidden modes', () => {
    const page = loadPage('inventories', ['updateDetachStorageContainerForm']);
    page.state.currentSectorObjects = objects;
    const elements = {
        '.detach-storage-mode': {value: 'hidden_on_dormant_construct'},
        '.detach-asteroid-label': {},
        '.detach-asteroid-target': {value: 'rock'},
    };
    const form = {querySelector: (selector) => elements[selector] || null};
    page.updateDetachStorageContainerForm(form);
    assert.match(elements['.detach-asteroid-target'].innerHTML, /value="wreck"/);
    assert.match(elements['.detach-asteroid-target'].innerHTML, /value="empty-wreck"/);
    assert.doesNotMatch(elements['.detach-asteroid-target'].innerHTML, /value="rock"/);
    assert.equal(elements['.detach-asteroid-target'].disabled, false);
    assert.equal(elements['.detach-asteroid-label'].hidden, false);
    elements['.detach-storage-mode'].value = 'hidden_on_asteroid';
    page.updateDetachStorageContainerForm(form);
    assert.match(elements['.detach-asteroid-target'].innerHTML, /value="rock"/);
    assert.doesNotMatch(elements['.detach-asteroid-target'].innerHTML, /value="wreck"/);
});
