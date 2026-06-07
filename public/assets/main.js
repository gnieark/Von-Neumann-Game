import {
    bindApiKeyDialog,
    bindOAuthRememberLinks,
    createApiClient,
    initSwaggerUi,
} from './api.js?v=20260604-system-bodies-v2';
import {createCraftingModule} from './crafting.js?v=20260606-printer-workshop';
import {createInventoryModule} from './inventory.js?v=20260606-circuit-recipes';
import {createLabels} from './labels.js?v=20260606-printer-workshop';
import {createMannyModule} from './manny.js?v=20260606-printer-workshop';
import {createSectorModule} from './sector.js?v=20260606-sector-units';
import {createInventoryActions} from './inventory-actions.js?v=20260607-split-main';
import {createMessageModule} from './messages.js?v=20260607-split-main';
import {
    bindAccountMenu,
    bindMetricDetails,
    bindPanelTabs,
    bindRefreshButtons,
    bindTutorialDialog,
} from './ui-accordion.js?v=20260606-messaging-ui';
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
} from './utils.js?v=20260606-i18n-external';

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
        probeStatusLabel,
        sensorModeLabel,
        t,
        taskLabel,
    } = labels;
    const api = createApiClient({t});
    const alreadyMovingMessage = t('probeAlreadyMoving', 'The probe is already moving between sectors.');
    const invalidCoordinateMessage = t('invalidCoordinates', 'Invalid relative coordinates: x + y + z must be even.');
    const state = {
        currentCraftingRecipes: [],
        currentInventory: null,
        currentMannyMineTargets: [],
        currentMannies: null,
        currentMannySalvageTargets: [],
        currentProbeSectorRelative: null,
        currentScannedSectorRelative: null,
        currentSectorProbes: [],
        currentMessageFolder: 'received',
        receivedMessages: [],
        receivedMessagePagination: null,
        sentMessages: [],
        sentMessagePagination: null,
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
            mannyModule?.updatePrinterCraftForms();
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
    const messageModule = createMessageModule({state, api, labels});
    const inventoryActions = createInventoryActions({state, api, labels, mannyModule});
    const {
        renderMessageRecipients,
        renderMessages,
        loadMessages,
        activateMessageFolder,
        markMessageRead,
    } = messageModule;
    const {
        storageRuleValues,
        renderStorageMoveForm,
        jettisonInventoryLine,
        storageMovePayloadFromForm,
    } = inventoryActions;

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

    function syncMessageTab() {
        const tab = document.getElementById('messages-tab');
        if (!tab) {
            return;
        }

        const messages = Array.isArray(state.receivedMessages) ? state.receivedMessages : [];
        const hasUnreadMessages = messages.some((message) => message && message.status === 'unread');
        tab.classList.toggle('alerts-pending', hasUnreadMessages);
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
                metric(t('status', 'Status'), probeStatusLabel(probe.status)),
                metric(t('sensors', 'Sensors'), sensorModeLabel(probe.sensorMode), sensorDetail),
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
                metric(t('task', 'Task'), systems.currentTask ? taskLabel(systems.currentTask) : t('noTask', 'None')),
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
        mannyModule?.updatePrinterCraftForms();
    }

    async function loadMannies() {
        await mannyModule.loadMannies();
        renderJumpChecklist();
    }

    async function loadCurrentSector() {
        try {
            const data = await api('/api/probe/sector');
            state.currentSectorProbes = Array.isArray(data.sector && data.sector.probes) ? data.sector.probes : [];
            renderMessageRecipients();
            sectorModule.syncSectorForm(data.sector);
            sectorModule.renderSectorObjects(data.sector);
            syncPrepareJumpButton(data.sector);
        } catch (error) {
            state.currentSectorProbes = [];
            renderMessageRecipients();
            sectorModule.renderSectorObjects(null, {syncMannyTargets: true});
            syncPrepareJumpButton(null);
            setText('sector-context', error.message);
        }
    }

    refreshers.loadProbe = loadProbe;
    refreshers.loadCurrentSector = loadCurrentSector;
    refreshers.loadMessages = loadMessages;

    bindApiKeyDialog({api, t, closeAccountMenus});
    bindPanelTabs();
    bindRefreshButtons({loadCurrentSector, loadMannies, loadMessages, loadProbe});
    mannyModule.bindMannyEvents();
    renderJumpChecklist();
    renderMessageRecipients();
    renderMessages();

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

    document.getElementById('messages-tab')?.addEventListener('click', () => {
        loadCurrentSector();
        loadMessages({folder: state.currentMessageFolder, silent: true});
    });

    document.querySelectorAll('[data-message-folder]').forEach((button) => {
        button.addEventListener('click', () => {
            activateMessageFolder(button.dataset.messageFolder || 'received');
        });
    });

    document.getElementById('messages-load-more')?.addEventListener('click', () => {
        const folder = state.currentMessageFolder;
        const messageList = folder === 'sent' ? state.sentMessages : state.receivedMessages;
        loadMessages({folder, offset: Array.isArray(messageList) ? messageList.length : 0, append: true});
    });

    document.getElementById('message-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const messageForm = event.currentTarget;
        const form = new FormData(messageForm);
        const recipientProbeId = Number.parseInt(String(form.get('recipientProbeId') || ''), 10);
        const bodyValue = String(form.get('body') || '').trim();
        if (!Number.isFinite(recipientProbeId) || recipientProbeId <= 0 || bodyValue === '') {
            setText('message-status', t('requestDenied', 'Request denied'));
            return;
        }

        await runApiOrder({
            statusId: 'message-status',
            pendingText: t('orderSent', 'Order transmitted...'),
            request: () => postJson('/api/probe/messages', {
                recipientProbeId,
                body: bodyValue,
            }),
            onSuccess: async () => {
                messageForm.reset();
                renderMessageRecipients();
                state.sentMessages = [];
                state.sentMessagePagination = null;
                setText('message-status', t('messageSent', 'Message transmitted.'));
                if (state.currentMessageFolder === 'sent') {
                    await loadMessages({folder: 'sent', silent: true});
                }
            },
            onError: (error) => {
                setText('message-status', error.message);
            },
        });
    });

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
                    priority: storageRuleValues(form, 'priority'),
                    exclusion: storageRuleValues(form, 'exclusion'),
                    strictExclusion: storageRuleValues(form, 'strictExclusion'),
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
        loadMessages({folder: 'received', silent: true});
    }
}
