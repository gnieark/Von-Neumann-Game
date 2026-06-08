import {escapeHtml} from './utils.js?v=20260606-i18n-external';

export const createInventoryActions = ({state, api, labels, mannyModule}) => {
    const {t} = labels;

    const splitStorageRuleValue = (value) => String(value || '')
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean);

    const storageRuleValues = (form, name) => (
        form.getAll(name).flatMap((value) => splitStorageRuleValue(value))
    );

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

    const availableOrderMannies = () => (
        availableStorageMoveMannies()
    );

    const detachableStorageContainers = () => (
        currentStorageContainers()
            .filter((container) => (
                container
                && container.id
                && container.id !== 'probe-core'
                && (container.kind === undefined || container.kind === 'container' || String(container.id).startsWith('container-'))
            ))
    );

    const storageContainerMatchesItemIds = (container, itemIds) => {
        const id = String(container && container.id ? container.id : '');
        if (!id || itemIds.length === 0) {
            return false;
        }

        return itemIds.some((itemId) => id === 'container-' + String(itemId));
    };

    const asteroidTargets = () => {
        const targets = [];
        const seen = new Set();
        const collect = (object) => {
            if (!object || typeof object !== 'object') {
                return;
            }
            if (object.type === 'asteroid' && object.id && !seen.has(object.id)) {
                seen.add(object.id);
                targets.push(object);
            }
            ['bookmarkTargets', 'minableTargets'].forEach((key) => {
                if (Array.isArray(object[key])) {
                    object[key].forEach(collect);
                }
            });
        };
        (Array.isArray(state.currentSectorObjects) ? state.currentSectorObjects : []).forEach(collect);

        return targets;
    };

    const asteroidLabel = (target) => (
        [t('asteroidObject', 'Asteroid'), target && (target.name || target.id)].filter(Boolean).join(' ')
    );

    const closeInventoryLineForms = (exceptSlot = null) => {
        document.querySelectorAll('.inventory-line-form-slot').forEach((slot) => {
            if (slot !== exceptSlot) {
                slot.innerHTML = '';
            }
        });
    };

    const postJson = async (path, bodyValue = {}) => api(path, {
        method: 'POST',
        body: JSON.stringify(bodyValue),
    });

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

    async function renderDetachStorageContainerForm(line) {
        const slot = line.querySelector('.inventory-line-form-slot');
        if (!slot) {
            return;
        }
        if (slot.querySelector('.inventory-detach-container-form')) {
            slot.innerHTML = '';
            return;
        }
        closeInventoryLineForms(slot);

        if (!Array.isArray(state.currentMannies)) {
            await mannyModule.loadMannies();
        }

        const itemIds = splitLineIds(line.dataset.itemIds);
        const mannies = availableOrderMannies();
        const containers = detachableStorageContainers();
        const preferredContainer = containers.find((container) => storageContainerMatchesItemIds(container, itemIds)) || containers[0] || null;
        const asteroids = asteroidTargets();
        const hasFormChoices = mannies.length > 0 && containers.length > 0;
        const mannyOptions = mannies.map((manny) => (
            '<option value="' + escapeHtml(manny.id) + '">' + escapeHtml(manny.name || manny.id) + '</option>'
        )).join('');
        const containerOptions = containers.map((container) => (
            '<option value="' + escapeHtml(container.id) + '"' + (preferredContainer && preferredContainer.id === container.id ? ' selected' : '') + '>'
            + escapeHtml(storageContainerLabel(container))
            + '</option>'
        )).join('');
        const asteroidOptions = asteroids.length === 0
            ? '<option value="">-</option>'
            : asteroids.map((target) => (
                '<option value="' + escapeHtml(target.id) + '">' + escapeHtml(asteroidLabel(target)) + '</option>'
            )).join('');
        const unavailableMessage = mannies.length === 0
            ? t('noAvailableManny', 'No available Manny.')
            : t('noDetachableContainer', 'No additional container can be detached.');

        slot.innerHTML = '<form class="inventory-detach-container-form">'
            + '<label>' + escapeHtml(t('storageContainer', 'Container')) + '<select name="containerId" required>' + containerOptions + '</select></label>'
            + '<label>' + escapeHtml(t('actorManny', 'Manny')) + '<select name="actorMannyId" required>' + mannyOptions + '</select></label>'
            + '<label>' + escapeHtml(t('detachStorageMode', 'Mode')) + '<select class="detach-storage-mode" name="mode" required>'
            + '<option value="drifting">' + escapeHtml(t('detachModeDrifting', 'Leave drifting')) + '</option>'
            + '<option value="hidden_on_asteroid">' + escapeHtml(t('detachModeHiddenOnAsteroid', 'Hide on an asteroid')) + '</option>'
            + '</select></label>'
            + '<label class="detach-asteroid-label" hidden>' + escapeHtml(t('asteroidObject', 'Asteroid')) + '<select class="detach-asteroid-target" name="objectId">' + asteroidOptions + '</select></label>'
            + '<button class="detach-storage-button" type="submit"' + (hasFormChoices ? '' : ' disabled aria-disabled="true"') + '>' + escapeHtml(t('detachStorageContainerShort', 'Detach')) + '</button>'
            + (hasFormChoices ? '' : '<p class="inventory-muted">' + escapeHtml(unavailableMessage) + '</p>')
            + '</form>';
        updateDetachStorageContainerForm(slot.querySelector('.inventory-detach-container-form'));
    }

    function updateDetachStorageContainerForm(form) {
        if (!form) {
            return;
        }
        const mode = form.querySelector('.detach-storage-mode');
        const asteroidLabelNode = form.querySelector('.detach-asteroid-label');
        const asteroid = form.querySelector('.detach-asteroid-target');
        const button = form.querySelector('.detach-storage-button');
        const hiddenMode = mode && mode.value === 'hidden_on_asteroid';
        if (asteroidLabelNode) {
            asteroidLabelNode.hidden = !hiddenMode;
        }
        if (asteroid) {
            asteroid.required = Boolean(hiddenMode);
            asteroid.disabled = !hiddenMode;
        }
        if (button) {
            const formData = new FormData(form);
            const coherent = Boolean(formData.get('containerId'))
                && Boolean(formData.get('actorMannyId'))
                && Boolean(formData.get('mode'))
                && (!hiddenMode || Boolean(asteroid && asteroid.value));
            button.disabled = !coherent;
            button.setAttribute('aria-disabled', coherent ? 'false' : 'true');
        }
    }

    const detachStorageContainerPayloadFromForm = (form) => {
        updateDetachStorageContainerForm(form);
        const formData = new FormData(form);
        const actorMannyId = String(formData.get('actorMannyId') || '');
        const containerId = String(formData.get('containerId') || '');
        const mode = String(formData.get('mode') || '');
        const objectId = String(formData.get('objectId') || '');
        if (!actorMannyId || !containerId || !['drifting', 'hidden_on_asteroid'].includes(mode)) {
            return null;
        }
        if (mode === 'hidden_on_asteroid' && !objectId) {
            return null;
        }

        return {
            mannyId: actorMannyId,
            payload: {
                containerId,
                mode,
                ...(mode === 'hidden_on_asteroid' ? {objectId} : {}),
            },
        };
    };

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

    return {
        storageRuleValues,
        renderStorageMoveForm,
        renderDetachStorageContainerForm,
        updateDetachStorageContainerForm,
        jettisonInventoryLine,
        detachStorageContainerPayloadFromForm,
        storageMovePayloadFromForm,
    };
};
