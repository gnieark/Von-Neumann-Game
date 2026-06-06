import {
    bindApiKeyDialog,
    bindOAuthRememberLinks,
    createApiClient,
    initSwaggerUi,
} from './api.js?v=20260604-system-bodies-v2';
import {createCraftingModule} from './crafting.js?v=20260604-system-bodies-v2';
import {createInventoryModule} from './inventory.js?v=20260605-bookmark-manny';
import {createLabels} from './labels.js?v=20260605-bookmark-manny';
import {createMannyModule} from './manny.js?v=20260605-bookmark-manny';
import {createSectorModule} from './sector.js?v=20260605-player-ui-alerts';
import {
    bindAccountMenu,
    bindMetricDetails,
    bindPanelTabs,
    bindRefreshButtons,
    bindTutorialDialog,
} from './ui-accordion.js?v=20260606-tutorial-chain';
import {
    bindLanguageForm,
    coordinate,
    detailList,
    duration,
    escapeHtml,
    formatText,
    metric,
    numberValue,
    readI18n,
    setText,
    storageCapacityValue,
    validRelativeCoordinates,
} from './utils.js?v=20260604-system-bodies-v2';

const i18n = readI18n();
bindLanguageForm();
bindOAuthRememberLinks();
initSwaggerUi();
const closeAccountMenus = bindAccountMenu();
bindTutorialDialog({closeAccountMenus});

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
        currentMannies: null,
        currentMannySalvageTargets: [],
        currentProbeSectorRelative: null,
        currentScannedSectorRelative: null,
        currentSectorObjects: [],
        probeAlreadyMoving: false,
        probeDeuteriumSufficient: false,
    };

    const refreshers = {};
    let inventoryModule;
    let mannyModule;

    const sectorModule = createSectorModule({
        state,
        labels,
        onTargetsChanged: () => {
            mannyModule?.updateMannyTargetOptions();
        },
        onAlertsChanged: syncAlertTab,
    });
    const craftingModule = createCraftingModule({state, labels});
    inventoryModule = createInventoryModule({
        state,
        labels,
        onInventoryChanged: () => {
            craftingModule.updateMannyCraftForms();
            mannyModule?.updateMannyBookmarkForms();
        },
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
    const patchJson = (path, bodyValue = {}) => api(path, {
        method: 'PATCH',
        body: JSON.stringify(bodyValue),
    });
    const splitStorageRuleValue = (value) => String(value || '')
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean);
    const currentStorageContainers = () => (
        Array.isArray(state.currentInventory && state.currentInventory.containers)
            ? state.currentInventory.containers
            : []
    );
    const storageContainerLabel = (container) => (
        container && (container.label || container.id)
            ? (container.label || container.id)
            : t('unknownContainer', 'Unknown container')
    );
    const splitLineIds = (value) => String(value || '')
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean);
    const availableStorageMoveMannies = (excludedIds = []) => {
        const excluded = new Set(excludedIds.map(String));
        return (Array.isArray(state.currentMannies) ? state.currentMannies : [])
            .filter((manny) => (
                manny
                && manny.id
                && !excluded.has(String(manny.id))
                && manny.currentTask === null
                && manny.location
                && manny.location.type === 'probe'
            ));
    };
    const closeInventoryLineForms = (exceptSlot = null) => {
        document.querySelectorAll('.inventory-line-form-slot').forEach((slot) => {
            if (slot !== exceptSlot) {
                slot.innerHTML = '';
            }
        });
    };
    const storageMovePayloadFromForm = (form) => {
        const formData = new FormData(form);
        const actorMannyId = String(formData.get('actorMannyId') || '');
        const toContainerId = String(formData.get('toContainerId') || '');
        const kind = form.dataset.lineKind || '';
        if (!actorMannyId || !toContainerId || !kind) {
            return null;
        }

        if (kind === 'resource') {
            const amount = Math.min(
                Number.parseFloat(form.dataset.maxAmount || '0'),
                Number.parseFloat(String(formData.get('quantity') || '0')),
            );
            if (!Number.isFinite(amount) || amount <= 0) {
                return null;
            }

            return {
                actorMannyId,
                kind,
                resourceType: form.dataset.resourceType || '',
                amount: Math.round(amount * 10000) / 10000,
                fromContainerId: form.dataset.sourceContainerId || '',
                toContainerId,
            };
        }

        const itemIds = splitLineIds(form.dataset.itemIds);
        const quantity = Math.min(itemIds.length, Math.max(1, Number.parseInt(String(formData.get('quantity') || '0'), 10)));
        const selectedIds = itemIds.slice(0, quantity);
        if (selectedIds.length === 0) {
            return null;
        }

        if (kind === 'manny') {
            return {
                actorMannyId,
                kind,
                targetMannyIds: selectedIds,
                quantity: selectedIds.length,
                toContainerId,
            };
        }

        return {
            actorMannyId,
            kind: 'item',
            itemIds: selectedIds,
            quantity: selectedIds.length,
            toContainerId,
        };
    };
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
    async function renderStorageMoveForm(line) {
        const slot = line.querySelector('.inventory-line-form-slot');
        if (!slot) {
            return;
        }
        if (slot.querySelector('.inventory-move-form')) {
            slot.innerHTML = '';
            return;
        }
        closeInventoryLineForms(slot);

        if (!Array.isArray(state.currentMannies)) {
            await mannyModule.loadMannies();
        }

        const kind = line.dataset.lineKind || '';
        const sourceContainerId = line.dataset.containerId || '';
        const itemIds = splitLineIds(line.dataset.itemIds);
        const excludedMannyIds = kind === 'manny' ? itemIds : [];
        const mannies = availableStorageMoveMannies(excludedMannyIds);
        const destinations = currentStorageContainers()
            .filter((container) => container && container.id && container.id !== sourceContainerId);
        const isResource = kind === 'resource';
        const maxQuantity = isResource
            ? Number.parseFloat(line.dataset.maxAmount || '0')
            : Number.parseInt(line.dataset.maxQuantity || '0', 10);
        const hasFormChoices = mannies.length > 0 && destinations.length > 0 && Number.isFinite(maxQuantity) && maxQuantity > 0;
        const quantityAttributes = isResource
            ? 'type="number" min="0.0001" max="' + escapeHtml(String(maxQuantity)) + '" step="0.0001" value="' + escapeHtml(String(maxQuantity)) + '"'
            : 'type="number" min="1" max="' + escapeHtml(String(maxQuantity)) + '" step="1" value="' + escapeHtml(String(maxQuantity)) + '"';
        const mannyOptions = mannies.map((manny) => (
            '<option value="' + escapeHtml(manny.id) + '">' + escapeHtml(manny.name || manny.id) + '</option>'
        )).join('');
        const destinationOptions = destinations.map((container) => (
            '<option value="' + escapeHtml(container.id) + '">' + escapeHtml(storageContainerLabel(container)) + '</option>'
        )).join('');
        const unavailableMessage = mannies.length === 0
            ? t('noAvailableManny', 'No available Manny.')
            : t('noDestinationContainer', 'No destination container available.');

        slot.innerHTML = '<form class="inventory-move-form"'
            + ' data-line-kind="' + escapeHtml(kind) + '"'
            + ' data-source-container-id="' + escapeHtml(sourceContainerId) + '"'
            + ' data-resource-type="' + escapeHtml(line.dataset.resourceType || '') + '"'
            + ' data-max-amount="' + escapeHtml(line.dataset.maxAmount || '') + '"'
            + ' data-item-ids="' + escapeHtml(itemIds.join(',')) + '">'
            + '<label>' + escapeHtml(t('moveQuantity', 'Quantity')) + '<input name="quantity" ' + quantityAttributes + ' required></label>'
            + '<label>' + escapeHtml(t('actorManny', 'Manny')) + '<select name="actorMannyId" required>' + mannyOptions + '</select></label>'
            + '<label>' + escapeHtml(t('destinationContainer', 'Destination container')) + '<select name="toContainerId" required>' + destinationOptions + '</select></label>'
            + '<button type="submit"' + (hasFormChoices ? '' : ' disabled aria-disabled="true"') + '>' + escapeHtml(t('moveStorageLine', 'Move')) + '</button>'
            + (hasFormChoices ? '' : '<p class="inventory-muted">' + escapeHtml(unavailableMessage) + '</p>')
            + '</form>';
    }

    async function jettisonInventoryLine(line) {
        const kind = line.dataset.lineKind || '';
        if (kind === 'resource') {
            return postJson('/api/probe/inventory/' + encodeURIComponent(line.dataset.stockId || line.dataset.resourceType || '' ) + '/jettison', {
                amount: Number.parseFloat(line.dataset.maxAmount || '0'),
                containerId: line.dataset.containerId || '',
            });
        }

        let latest = null;
        for (const itemId of splitLineIds(line.dataset.itemIds)) {
            latest = await postJson('/api/probe/inventory/' + encodeURIComponent(itemId) + '/jettison', {});
        }

        return latest || {};
    }
    const relativeCoordinates = (value) => {
        if (!value || !Number.isFinite(Number(value.x)) || !Number.isFinite(Number(value.y)) || !Number.isFinite(Number(value.z))) {
            return null;
        }

        return {
            x: Number(value.x),
            y: Number(value.y),
            z: Number(value.z),
        };
    };
    const sameRelativeCoordinates = (left, right) => {
        const a = relativeCoordinates(left);
        const b = relativeCoordinates(right);

        return a !== null && b !== null && a.x === b.x && a.y === b.y && a.z === b.z;
    };
    const manniesOutsideProbeInCurrentSector = (mannies) => (Array.isArray(mannies) ? mannies : [])
        .filter((manny) => (
            (manny.location && manny.location.type) !== 'probe'
            && sameRelativeCoordinates(manny.location && manny.location.sector && manny.location.sector.relative, state.currentProbeSectorRelative)
        ));

    function activatePanel(panelId) {
        document.querySelectorAll('.panel-tab').forEach((item) => item.classList.remove('active'));
        document.querySelectorAll('.data-panel').forEach((panel) => panel.classList.remove('active'));
        const tab = document.querySelector('.panel-tab[data-panel-target="' + panelId + '"]');
        tab?.classList.add('active');
        document.getElementById(panelId)?.classList.add('active');
    }

    function syncAlertTab(alerts) {
        const tab = document.getElementById('alerts-tab');
        if (!tab) {
            return;
        }

        const alertList = Array.isArray(alerts) ? alerts : [];
        const hasAlerts = alertList.length > 0;
        const hasPendingAlerts = alertList.some((alert) => !alert.acknowledged);
        tab.disabled = !hasAlerts;
        tab.setAttribute('aria-disabled', hasAlerts ? 'false' : 'true');
        tab.classList.toggle('alerts-pending', hasPendingAlerts);
    }

    const sectorScanTarget = (sector) => relativeCoordinates(sector && sector.relativeCoordinates);

    function syncPrepareJumpButton(sector) {
        const button = document.getElementById('prepare-jump-button');
        if (!button) {
            return;
        }

        const target = sectorScanTarget(sector);
        const distance = Number(sector && sector.distance);
        const isRemoteSector = target !== null && (
            Number.isFinite(distance)
                ? distance !== 0
                : !sameRelativeCoordinates(target, state.currentProbeSectorRelative)
        );
        state.currentScannedSectorRelative = isRemoteSector ? target : null;
        button.hidden = !isRemoteSector;
        button.disabled = !isRemoteSector;
        button.setAttribute('aria-disabled', isRemoteSector ? 'false' : 'true');
    }

    function fillMoveForm(target) {
        const form = document.getElementById('move-form');
        if (!form || !target) {
            return;
        }

        ['x', 'y', 'z'].forEach((field) => {
            if (form.elements[field]) {
                form.elements[field].value = target[field] ?? 0;
            }
        });
    }

    function prepareJumpFromScannedSector() {
        const target = state.currentScannedSectorRelative;
        if (!target) {
            return;
        }

        fillMoveForm(target);
        activatePanel('actions-panel');
    }

    const checklistValue = (ok) => (
        '<span class="checklist-value ' + (ok ? 'ok' : 'warn') + '">'
        + escapeHtml(ok ? t('checklistYes', 'Yes') : t('checklistNo', 'No'))
        + '</span>'
    );

    const allManniesAboard = () => (
        Array.isArray(state.currentMannies)
            ? state.currentMannies.every((manny) => (manny.location && manny.location.type) === 'probe')
            : false
    );

    function renderJumpChecklist() {
        const node = document.getElementById('jump-checklist');
        if (!node) {
            return;
        }

        node.innerHTML = '<h3>' + escapeHtml(t('jumpPreparationChecklist', 'Preparation')) + '</h3>'
            + '<ul>'
            + '<li><span>' + escapeHtml(t('deuteriumSufficient', 'Sufficient deuterium')) + '</span>' + checklistValue(state.probeDeuteriumSufficient) + '</li>'
            + '<li><span>' + escapeHtml(t('manniesAboard', 'Mannys aboard')) + '</span>' + checklistValue(allManniesAboard()) + '</li>'
            + '</ul>';
    }

    function applyMoveButtonState() {
        const button = document.getElementById('move-submit');
        if (!button) {
            return;
        }

        const blockedByFuel = !state.probeDeuteriumSufficient;
        button.disabled = state.probeAlreadyMoving || blockedByFuel;
        button.title = state.probeAlreadyMoving
            ? alreadyMovingMessage
            : (blockedByFuel ? t('insufficientFuelForJump', 'Insufficient deuterium to initiate a jump.') : '');
        button.setAttribute('aria-disabled', button.disabled ? 'true' : 'false');
    }

    async function confirmJumpWithMannies() {
        if (state.currentProbeSectorRelative === null) {
            await loadProbe();
        }
        const refreshedMannies = await mannyModule.loadMannies();
        const mannies = Array.isArray(refreshedMannies)
            ? refreshedMannies
            : (Array.isArray(state.currentMannies) ? state.currentMannies : []);
        const absentMannies = manniesOutsideProbeInCurrentSector(mannies);
        if (absentMannies.length === 0) {
            return true;
        }

        const names = absentMannies.map((manny) => manny.name || manny.id || t('mannyObject', 'Manny')).join(', ');
        return window.confirm(formatText(t('jumpWithAbsentManniesConfirm', 'Some Mannys are not aboard the probe: {names}. If you initiate the jump now, they will be left in this sector. Confirm jump?'), {
            names,
            count: absentMannies.length,
        }));
    }

    function updateMoveButtonState(probe) {
        const movement = probe && probe.movement ? probe.movement : null;
        state.probeAlreadyMoving = Boolean(movement && ['preparing', 'accelerating', 'cruising', 'decelerating'].includes(movement.phase || movement.status));
        state.probeDeuteriumSufficient = Number(probe && probe.fuel ? probe.fuel.deuterium : 0) > 0.0001;
        applyMoveButtonState();
        renderJumpChecklist();
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
            state.currentProbeSectorRelative = sector ? relativeCoordinates(sector) : null;
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
            inventoryModule.renderInventory(probe.inventory || {});
        } catch (error) {
            updateMoveButtonState(null);
            state.currentProbeSectorRelative = null;
            inventoryModule.renderInventory(null);
        }
    }

    async function loadCraftingRecipes() {
        await craftingModule.loadCraftingRecipes(api);
    }

    async function loadMannies() {
        await mannyModule.loadMannies();
        renderJumpChecklist();
    }

    async function loadCurrentSector() {
        try {
            const data = await api('/api/probe/sector');
            sectorModule.syncSectorForm(data.sector);
            sectorModule.renderSectorObjects(data.sector);
            syncPrepareJumpButton(data.sector);
        } catch (error) {
            sectorModule.renderSectorObjects(null, {syncMannyTargets: true});
            syncPrepareJumpButton(null);
            setText('sector-context', error.message);
        }
    }

    refreshers.loadProbe = loadProbe;
    refreshers.loadCurrentSector = loadCurrentSector;

    bindApiKeyDialog({api, t, closeAccountMenus});
    bindPanelTabs();
    bindRefreshButtons({loadCurrentSector, loadMannies, loadProbe});
    mannyModule.bindMannyEvents();
    renderJumpChecklist();

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
            syncPrepareJumpButton(null);
            setText('sector-context', invalidCoordinateMessage);
            return;
        }
        try {
            const data = await api('/api/sector?' + query.toString());
            sectorModule.syncSectorForm(data.sector);
            sectorModule.renderSectorObjects(data.sector);
            syncPrepareJumpButton(data.sector);
        } catch (error) {
            sectorModule.renderSectorObjects(null, {syncMannyTargets: false});
            syncPrepareJumpButton(null);
            setText('sector-context', error.message);
        }
    });

    document.getElementById('prepare-jump-button')?.addEventListener('click', prepareJumpFromScannedSector);

    document.getElementById('jump-control')?.addEventListener('click', () => {
        if (state.probeAlreadyMoving) {
            setText('action-status', alreadyMovingMessage);
            return;
        }
        if (!state.probeDeuteriumSufficient) {
            setText('action-status', t('insufficientFuelForJump', 'Insufficient deuterium to initiate a jump.'));
        }
    });

    document.getElementById('move-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (state.probeAlreadyMoving) {
            setText('action-status', alreadyMovingMessage);
            return;
        }
        if (!state.probeDeuteriumSufficient) {
            setText('action-status', t('insufficientFuelForJump', 'Insufficient deuterium to initiate a jump.'));
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
            return;
        }
        if (!await confirmJumpWithMannies()) {
            setText('action-status', t('movementCancelled', 'Movement cancelled.'));
            return;
        }
        await runApiOrder({
            statusId: 'action-status',
            pendingText: t('orderSent', 'Order transmitted...'),
            request: () => postJson('/api/probe/move', {target}),
            onSuccess: (data) => {
                setText('action-status', t('movementAccepted', 'Movement accepted.'));
                loadProbe();
            },
            onError: (error) => {
                setText('action-status', error.message);
            },
        });
    });

    const systemsPanel = document.getElementById('systems-panel');
    systemsPanel?.addEventListener('click', async (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const moveButton = event.target.closest('.inventory-line-move');
        if (moveButton && systemsPanel.contains(moveButton)) {
            const line = moveButton.closest('.inventory-container-line');
            if (line) {
                await renderStorageMoveForm(line);
            }
            return;
        }

        const jettisonButton = event.target.closest('.inventory-line-jettison');
        if (!jettisonButton || !systemsPanel.contains(jettisonButton)) {
            return;
        }

        const line = jettisonButton.closest('.inventory-container-line');
        if (!line || !window.confirm(t('confirmJettisonLine', 'Jettison this storage line into space?'))) {
            return;
        }

        await runApiOrder({
            statusId: 'inventory-status',
            pendingText: t('orderSent', 'Order transmitted...'),
            request: () => jettisonInventoryLine(line),
            onSuccess: (data) => {
                setText('inventory-status', t('jettisonAccepted', 'Inventory entry jettisoned into space.'));
                if (data.inventory) {
                    inventoryModule.renderInventory(data.inventory);
                }
                loadProbe();
                loadCurrentSector();
                loadMannies();
            },
        });
    });

    systemsPanel?.addEventListener('submit', async (event) => {
        if (event.target.classList.contains('storage-rules-form')) {
            event.preventDefault();
            const containerId = event.target.dataset.containerId;
            if (!containerId) {
                return;
            }
            const form = new FormData(event.target);
            await runApiOrder({
                statusId: 'inventory-status',
                pendingText: t('orderSent', 'Order transmitted...'),
                request: () => patchJson('/api/probe/storage-containers/' + encodeURIComponent(containerId) + '/rules', {
                    priority: splitStorageRuleValue(form.get('priority')),
                    exclusion: splitStorageRuleValue(form.get('exclusion')),
                    strictExclusion: splitStorageRuleValue(form.get('strictExclusion')),
                }),
                onSuccess: (data) => {
                    setText('inventory-status', t('storageRulesSaved', 'Storage rules saved.'));
                    if (data.inventory) {
                        inventoryModule.renderInventory(data.inventory);
                    }
                    loadProbe();
                },
            });
            return;
        }

        if (event.target.classList.contains('inventory-move-form')) {
            event.preventDefault();
            const payload = storageMovePayloadFromForm(event.target);
            if (!payload) {
                setText('inventory-status', t('invalidStorageMove', 'Invalid storage move order.'));
                return;
            }

            await runApiOrder({
                statusId: 'inventory-status',
                pendingText: t('orderSent', 'Order transmitted...'),
                request: () => postJson('/api/probe/storage-moves', payload),
                onSuccess: (data) => {
                    setText('inventory-status', t('storageMoveAccepted', 'Storage move assigned.'));
                    if (data.inventory) {
                        inventoryModule.renderInventory(data.inventory);
                    }
                    loadProbe();
                    loadMannies();
                },
            });
        }
    });

    if (document.querySelector('.console-grid')) {
        loadProbe();
        loadCurrentSector();
        loadCraftingRecipes();
        loadMannies();
    }
}
