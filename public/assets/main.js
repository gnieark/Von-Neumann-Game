import {
    bindApiKeyDialog,
    bindOAuthRememberLinks,
    createApiClient,
    initSwaggerUi,
} from './api.js';
import {createCraftingModule} from './crafting.js';
import {createInventoryModule} from './inventory.js';
import {createLabels} from './labels.js';
import {createMannyModule} from './manny.js';
import {createSectorModule} from './sector.js';
import {
    bindAccountMenu,
    bindJsonToggles,
    bindMetricDetails,
    bindPanelTabs,
    bindRefreshButtons,
} from './ui-accordion.js';
import {
    bindLanguageForm,
    coordinate,
    detailList,
    duration,
    metric,
    numberValue,
    pretty,
    readI18n,
    setText,
    storageCapacityValue,
    validRelativeCoordinates,
} from './utils.js';

const i18n = readI18n();
bindLanguageForm();
bindOAuthRememberLinks();
initSwaggerUi();

const body = document.body;
if (body && body.dataset.authenticated === '1') {
    const labels = createLabels(i18n);
    const {
        t,
    } = labels;
    const api = createApiClient({t});
    const alreadyMovingMessage = 'The probe is already moving between sectors.';
    const invalidCoordinateMessage = t('invalidCoordinates', 'Invalid relative coordinates: x + y + z must be even.');
    const state = {
        currentCraftingRecipes: [],
        currentInventory: null,
        currentMannyMineTargets: [],
        currentMannySalvageTargets: [],
        currentSectorObjects: [],
        probeAlreadyMoving: false,
    };

    const refreshers = {};
    let inventoryModule;
    let mannyModule;

    const sectorModule = createSectorModule({
        state,
        labels,
        onTargetsChanged: () => {
            mannyModule?.updateMannyTargetOptions();
            inventoryModule?.renderBookmarkAction();
        },
    });
    const craftingModule = createCraftingModule({state, labels});
    inventoryModule = createInventoryModule({
        state,
        labels,
        sector: sectorModule,
        onInventoryChanged: () => craftingModule.updateMannyCraftForms(),
    });
    mannyModule = createMannyModule({
        state,
        labels,
        sector: sectorModule,
        crafting: craftingModule,
        api,
        refreshers,
    });

    const formatDuration = (seconds) => duration(seconds, t);
    const postJson = (path, bodyValue = {}) => api(path, {
        method: 'POST',
        body: JSON.stringify(bodyValue),
    });
    const runApiOrder = async ({statusId, pendingText, request, onSuccess, onError}) => {
        setText(statusId, pendingText);
        try {
            const data = await request();
            await onSuccess(data);
        } catch (error) {
            if (onError) {
                onError(error);
            } else {
                setText(statusId, error.message);
            }
        }
    };

    function updateMoveButtonState(probe) {
        const button = document.getElementById('move-submit');
        if (!button) {
            return;
        }

        const movement = probe && probe.movement ? probe.movement : null;
        state.probeAlreadyMoving = Boolean(movement && ['preparing', 'accelerating', 'cruising', 'decelerating'].includes(movement.phase || movement.status));
        button.disabled = state.probeAlreadyMoving;
        button.title = state.probeAlreadyMoving ? alreadyMovingMessage : '';
        button.setAttribute('aria-disabled', state.probeAlreadyMoving ? 'true' : 'false');
    }

    async function loadProbe() {
        try {
            const data = await api('/api/probe');
            const probe = data.probe || {};
            updateMoveButtonState(probe);
            const systems = probe.systems || {};
            const nav = probe.navigation || {};
            const movement = probe.movement || null;
            const sector = probe.sector && probe.sector.relative ? probe.sector.relative : null;
            const sensorDetail = probe.sensorMode === 'degraded'
                ? t('sensorDegradedInfo', 'At relativistic speeds, external sensors cannot analyze the environment in detail.')
                : (probe.sensorMode === 'blind' ? t('sensorBlindInfo', 'At this relativistic speed, external sensors are blinded.') : null);
            const sectorDetail = !sector && movement ? detailList([
                {label: t('originSector', 'Origin sector'), value: coordinate(movement.origin)},
                {label: t('destinationSector', 'Arrival sector'), value: coordinate(movement.target)},
                {label: t('remainingTime', 'Remaining time'), value: formatDuration(Number(movement.secondsRemaining))},
            ]) : null;
            document.getElementById('probe-summary').innerHTML = [
                metric(t('status', 'Status'), probe.status),
                metric(t('sensors', 'Sensors'), probe.sensorMode, sensorDetail),
                metric(t('deuterium', 'Deuterium'), probe.fuel ? probe.fuel.deuterium + '%' : '-'),
                metric(t('sector', 'Sector'), sector ? coordinate(sector) : t('transit', 'Transit'), sectorDetail),
                metric(t('velocityC', 'Velocity c'), nav.velocityC),
                metric(t('heading', 'Heading'), nav.direction ? [nav.direction.x, nav.direction.y, nav.direction.z].join(':') : '-'),
            ].join('');
            bindMetricDetails();
            document.getElementById('systems-summary').innerHTML = [
                metric(t('integrity', 'Integrity'), numberValue(systems.integrityPercent, '%')),
                metric(t('energy', 'Energy'), systems.energyStored),
                metric(t('storageCapacity', 'Storage capacity'), storageCapacityValue(probe.inventory)),
                metric(t('internalClock', 'Internal clock'), systems.internalClockRate),
                metric(t('task', 'Task'), systems.currentTask || t('noTask', 'None')),
            ].join('');
            setText('probe-json', pretty(data));
            setText('inventory-json', pretty(probe.inventory || {}));
            inventoryModule.renderInventory(probe.inventory || {});
        } catch (error) {
            updateMoveButtonState(null);
            setText('probe-json', error.message);
            setText('inventory-json', error.message);
            inventoryModule.renderInventory(null);
        }
    }

    async function loadCraftingRecipes() {
        await craftingModule.loadCraftingRecipes(api);
    }

    async function loadMannies() {
        await mannyModule.loadMannies();
    }

    async function loadCurrentSector() {
        try {
            const data = await api('/api/probe/sector');
            sectorModule.syncSectorForm(data.sector);
            sectorModule.renderSectorObjects(data.sector);
            setText('sector-json', pretty(data));
        } catch (error) {
            sectorModule.renderSectorObjects(null, {syncMannyTargets: true});
            setText('sector-json', error.message);
        }
    }

    refreshers.loadProbe = loadProbe;
    refreshers.loadCurrentSector = loadCurrentSector;

    const closeAccountMenus = bindAccountMenu();
    bindApiKeyDialog({api, t, closeAccountMenus});
    bindPanelTabs();
    bindRefreshButtons({loadCurrentSector, loadMannies, loadProbe});
    bindJsonToggles();
    mannyModule.bindMannyEvents();

    document.getElementById('sector-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const query = new URLSearchParams({
            x: form.get('x'),
            y: form.get('y'),
            z: form.get('z'),
        });
        const target = {
            x: Number.parseInt(form.get('x'), 10),
            y: Number.parseInt(form.get('y'), 10),
            z: Number.parseInt(form.get('z'), 10),
        };
        if (!validRelativeCoordinates(target)) {
            setText('sector-json', invalidCoordinateMessage);
            return;
        }
        try {
            const data = await api('/api/sector?' + query.toString());
            sectorModule.syncSectorForm(data.sector);
            sectorModule.renderSectorObjects(data.sector);
            setText('sector-json', pretty(data));
        } catch (error) {
            sectorModule.renderSectorObjects(null, {syncMannyTargets: false});
            setText('sector-json', error.message);
        }
    });

    document.getElementById('jump-control')?.addEventListener('click', () => {
        if (state.probeAlreadyMoving) {
            setText('action-status', alreadyMovingMessage);
            setText('movement-json', '');
        }
    });

    document.getElementById('move-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (state.probeAlreadyMoving) {
            setText('action-status', alreadyMovingMessage);
            setText('movement-json', '');
            return;
        }
        const form = new FormData(event.currentTarget);
        const target = {
            x: Number.parseInt(form.get('x'), 10),
            y: Number.parseInt(form.get('y'), 10),
            z: Number.parseInt(form.get('z'), 10),
        };
        if (!validRelativeCoordinates(target)) {
            setText('action-status', invalidCoordinateMessage);
            setText('movement-json', '');
            return;
        }
        await runApiOrder({
            statusId: 'action-status',
            pendingText: t('orderSent', 'Order transmitted...'),
            request: () => postJson('/api/probe/move', {target}),
            onSuccess: (data) => {
                setText('action-status', t('movementAccepted', 'Movement accepted.'));
                setText('movement-json', pretty(data));
                loadProbe();
            },
            onError: (error) => {
                setText('action-status', error.message);
                setText('movement-json', '');
            },
        });
    });

    document.getElementById('actions-panel')?.addEventListener('submit', async (event) => {
        if (event.target.id !== 'bookmark-form') {
            return;
        }

        event.preventDefault();
        const form = new FormData(event.target);
        const itemId = String(form.get('itemId') || '');
        if (!itemId) {
            return;
        }
        await runApiOrder({
            statusId: 'action-status',
            pendingText: t('orderSent', 'Order transmitted...'),
            request: () => postJson('/api/probe/waypoint-bookmarks/' + encodeURIComponent(itemId) + '/deploy', {
                objectId: form.get('objectId'),
                name: form.get('name'),
            }),
            onSuccess: (data) => {
                setText('action-status', t('bookmarkAccepted', 'Waypoint bookmark placed.'));
                setText('movement-json', pretty(data));
                if (data.inventory) {
                    inventoryModule.renderInventory(data.inventory);
                    setText('inventory-json', pretty(data.inventory));
                }
                if (data.sector) {
                    sectorModule.renderSectorObjects(data.sector);
                    setText('sector-json', pretty({sector: data.sector}));
                }
            },
        });
    });

    document.getElementById('systems-panel')?.addEventListener('submit', async (event) => {
        if (!event.target.classList.contains('inventory-jettison-form')) {
            return;
        }

        event.preventDefault();
        const itemId = event.target.dataset.itemId;
        if (!itemId) {
            return;
        }

        const form = new FormData(event.target);
        const bodyValue = form.has('amount') ? {amount: Number.parseFloat(form.get('amount'))} : {};
        await runApiOrder({
            statusId: 'inventory-status',
            pendingText: t('orderSent', 'Order transmitted...'),
            request: () => postJson('/api/probe/inventory/' + encodeURIComponent(itemId) + '/jettison', bodyValue),
            onSuccess: (data) => {
                setText('inventory-status', t('jettisonAccepted', 'Inventory entry jettisoned into space.'));
                if (data.inventory) {
                    inventoryModule.renderInventory(data.inventory);
                    setText('inventory-json', pretty(data.inventory));
                }
                loadProbe();
                loadMannies();
            },
        });
    });

    if (document.querySelector('.console-grid')) {
        loadProbe();
        loadCurrentSector();
        loadCraftingRecipes();
        loadMannies();
    }
}
