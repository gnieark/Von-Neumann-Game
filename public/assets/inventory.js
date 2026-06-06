import {
    escapeHtml,
    numberValue,
} from './utils.js?v=20260604-system-bodies-v2';

export const createInventoryModule = ({state, labels, onInventoryChanged = () => {}}) => {
    const {
        inventoryItemTypeLabel,
        locationTypeLabel,
        resourceTypeLabel,
        t,
        taskLabel,
    } = labels;

    const inventoryItemName = (item) => (
        item && item.type
            ? inventoryItemTypeLabel(item.type, item.name || item.type)
            : (item && (item.name || item.id)) || '-'
    );

    const inventoryEntryDetail = (entry) => {
        const details = [
            t('containerSpace', 'Space') + ' ' + numberValue(entry.containerSpace || 0),
        ];
        if (entry.location && entry.location.type) {
            details.push(t('location', 'Location') + ' ' + locationTypeLabel(entry.location.type));
        }
        if (entry.currentTask) {
            details.push(t('task', 'Task') + ' ' + taskLabel(entry.currentTask));
        }

        return details.join(' · ');
    };

    const containerLabel = (container) => (
        container && (container.label || container.id)
            ? (container.label || container.id)
            : t('unknownContainer', 'Unknown container')
    );

    const itemContainer = (item) => item && item.container ? item.container : null;
    const selectedContainerId = () => state.inventoryContainerFilter || 'all';
    const inventoryContainers = (inventory) => (
        Array.isArray(inventory && inventory.containers) ? inventory.containers : []
    );
    const itemMatchesContainerFilter = (item) => (
        selectedContainerId() === 'all'
        || (itemContainer(item) && itemContainer(item).id === selectedContainerId())
    );
    const stockPlacements = (stock) => {
        const placements = Array.isArray(stock && stock.containers) ? stock.containers : [];
        if (placements.length === 0 && Number(stock && stock.amount) > 0) {
            return [{
                container: stock.container || null,
                amount: Number(stock.amount),
                containerSpace: Number(stock.containerSpace || stock.amount),
            }];
        }

        return placements;
    };
    const filteredStockPlacements = (stock) => stockPlacements(stock)
        .filter((placement) => selectedContainerId() === 'all'
            || (placement.container && placement.container.id === selectedContainerId()));
    const isJettisonableItem = (item) => {
        if (item.type === 'manny') {
            return item.location
                && item.location.type === 'probe'
                && item.currentTask === null;
        }

        return [
            'waypoint_bookmark',
            'steel_bar',
            'steel_plate',
            'micro_conductor',
            'ceramic_insulator',
            'crystal_substrate',
            'dopant_matrix',
            'integrated_circuit',
        ].includes(item.type);
    };

    const isMovableItem = (item) => (
        item
        && item.type !== 'atomic_3d_printer'
        && item.container
        && item.container.id
        && !(item.metadata && item.metadata.movable === false)
    );

    const lineIcon = (name) => ({
        move: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path><path d="M5 5v14"></path></svg>',
        jettison: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 11 4 5"></path><path d="m5 10-1-5 5 1"></path><path d="M14 13l6 6"></path><path d="m19 14 1 5-5-1"></path><path d="m14 11 6-6"></path><path d="m19 10 1-5-5 1"></path><path d="M10 13l-6 6"></path><path d="m5 14-1 5 5-1"></path></svg>',
    }[name] || '');

    const lineIconButton = (className, label, icon, disabled = false, visibleLabel = label) => (
        '<button class="inventory-icon-button ' + className + '" type="button" title="' + escapeHtml(label) + '" aria-label="' + escapeHtml(label) + '"'
        + (disabled ? ' disabled aria-disabled="true"' : '')
        + '>' + lineIcon(icon) + '<span class="inventory-icon-label">' + escapeHtml(visibleLabel) + '</span></button>'
    );

    const lineActionFlags = (action, placement) => {
        if (!action) {
            return {canMove: false, canJettison: false};
        }
        const hasContainer = Boolean(placement.container && placement.container.id);
        if (action.kind === 'resource') {
            const amount = Number(placement.amount || 0);
            return {
                canMove: hasContainer && amount > 0,
                canJettison: hasContainer && amount > 0,
            };
        }

        const items = Array.isArray(placement.items) ? placement.items : [];
        return {
            canMove: hasContainer && items.length > 0 && items.every(isMovableItem),
            canJettison: items.length > 0 && items.every(isJettisonableItem),
        };
    };

    const lineActionAttributes = (action, placement) => {
        if (!action) {
            return '';
        }

        const attributes = [
            'data-line-kind="' + escapeHtml(action.kind) + '"',
            'data-container-id="' + escapeHtml((placement.container && placement.container.id) || '') + '"',
        ];
        if (action.kind === 'resource') {
            attributes.push('data-stock-id="' + escapeHtml(action.itemId || action.resourceType || '') + '"');
            attributes.push('data-resource-type="' + escapeHtml(action.resourceType || '') + '"');
            attributes.push('data-max-amount="' + escapeHtml(String(Number(placement.amount || 0))) + '"');
            return attributes.join(' ');
        }

        const itemIds = Array.isArray(placement.itemIds) ? placement.itemIds : [];
        attributes.push('data-item-type="' + escapeHtml(action.itemType || action.kind) + '"');
        attributes.push('data-item-ids="' + escapeHtml(itemIds.join(',')) + '"');
        attributes.push('data-max-quantity="' + escapeHtml(String(itemIds.length || Number(placement.quantity || 0))) + '"');

        return attributes.join(' ');
    };

    const renderLineActions = (action, placement) => {
        const flags = lineActionFlags(action, placement);
        return '<span class="inventory-line-controls">'
            + lineIconButton('inventory-line-move', t('moveStorageLine', 'Move'), 'move', !flags.canMove)
            + lineIconButton('inventory-line-jettison', t('jettisonLine', 'Jettison line'), 'jettison', !flags.canJettison, t('jettison', 'Jettison'))
            + '</span>'
            + '<div class="inventory-line-form-slot"></div>';
    };

    const placementLines = (placements, valueKey = 'amount', action = null) => (
        placements.length === 0
            ? '<p class="inventory-muted">' + escapeHtml(t('emptyContainerLine', 'No stock in this container.')) + '</p>'
            : '<ul class="inventory-container-lines">'
                + placements.map((placement) => (
                    '<li class="inventory-container-line" ' + lineActionAttributes(action, placement) + '>'
                    + '<span class="inventory-line-container">' + escapeHtml(containerLabel(placement.container)) + '</span>'
                    + '<b>' + escapeHtml(numberValue(placement[valueKey] || 0)) + '</b>'
                    + renderLineActions(action, placement)
                    + '</li>'
                )).join('')
                + '</ul>'
    );

    const defaultStorageRuleTypes = [
        'metals',
        'ice',
        'carbon_compounds',
        'manny',
        'waypoint_bookmark',
        'steel_bar',
        'steel_plate',
        'additional_container',
        'micro_conductor',
        'ceramic_insulator',
        'crystal_substrate',
        'dopant_matrix',
        'integrated_circuit',
    ];
    const resourceRuleTypes = ['metals', 'ice', 'carbon_compounds'];
    const storageRuleTypeLabel = (type, fallback = type) => {
        if (resourceRuleTypes.includes(type)) {
            return resourceTypeLabel(type);
        }
        if (type === 'manny') {
            return t('mannyObject', 'Manny');
        }

        return inventoryItemTypeLabel(type, fallback || type);
    };
    const addStorageRuleOption = (options, type, label = null) => {
        const value = String(type || '').trim();
        if (value === '' || options.has(value)) {
            return;
        }
        options.set(value, label || storageRuleTypeLabel(value));
    };
    const storageRuleOptions = (inventory, containers) => {
        const options = new Map();
        defaultStorageRuleTypes.forEach((type) => addStorageRuleOption(options, type));
        (Array.isArray(inventory && inventory.resourceStocks) ? inventory.resourceStocks : []).forEach((stock) => {
            addStorageRuleOption(options, stock.type, resourceTypeLabel(stock.type));
        });
        (Array.isArray(inventory && inventory.items) ? inventory.items : []).forEach((item) => {
            addStorageRuleOption(options, item.type, storageRuleTypeLabel(item.type, item.name || item.type));
        });
        containers.forEach((container) => {
            const rules = container.rules || {};
            ['priority', 'exclusion', 'strictExclusion'].forEach((name) => {
                (Array.isArray(rules[name]) ? rules[name] : []).forEach((type) => {
                    addStorageRuleOption(options, type);
                });
            });
        });

        return Array.from(options, ([value, label]) => ({value, label}));
    };

    const syncStorageRuleForm = (form, changedSelect = null) => {
        if (!form) {
            return;
        }
        const selects = Array.from(form.querySelectorAll('.storage-rule-select'));
        if (changedSelect) {
            const selectedValues = new Set(Array.from(changedSelect.selectedOptions).map((option) => option.value));
            selects
                .filter((select) => select !== changedSelect)
                .forEach((select) => {
                    Array.from(select.options).forEach((option) => {
                        if (selectedValues.has(option.value)) {
                            option.selected = false;
                        }
                    });
                });
            return;
        }

        const seen = new Set();
        selects.forEach((select) => {
            Array.from(select.options).forEach((option) => {
                if (!option.selected) {
                    return;
                }
                if (seen.has(option.value)) {
                    option.selected = false;
                    return;
                }
                seen.add(option.value);
            });
        });
    };

    const resourceStockDetail = (stock, amount, space) => [
        t('storedAmount', 'Amount') + ' ' + numberValue(amount),
        t('containerSpace', 'Space') + ' ' + numberValue(space),
    ].join(' · ');

    const renderStorageRules = (inventory) => {
        const node = document.getElementById('storage-rules-panel');
        if (!node) {
            return;
        }
        const containers = inventoryContainers(inventory);
        if (!inventory || containers.length === 0) {
            node.innerHTML = '';
            return;
        }

        const options = ['<option value="all">' + escapeHtml(t('allContainers', 'All containers')) + '</option>']
            .concat(containers.map((container) => (
                '<option value="' + escapeHtml(container.id) + '"' + (selectedContainerId() === container.id ? ' selected' : '') + '>'
                + escapeHtml(containerLabel(container))
                + '</option>'
            )));
        const ruleOptions = storageRuleOptions(inventory, containers);
        const ruleFields = (container) => {
            const rules = container.rules || {};
            const field = (name, label) => (
                '<label><span>' + escapeHtml(label) + '</span>'
                + '<select class="storage-rule-select" name="' + escapeHtml(name) + '" multiple size="' + escapeHtml(String(Math.min(Math.max(ruleOptions.length, 4), 8))) + '">'
                + ruleOptions.map((option) => (
                    '<option value="' + escapeHtml(option.value) + '"'
                    + ((Array.isArray(rules[name]) ? rules[name] : []).includes(option.value) ? ' selected' : '')
                    + '>' + escapeHtml(option.label) + '</option>'
                )).join('')
                + '</select>'
                + '</label>'
            );

            return field('priority', t('storagePriorityFilter', 'Priority'))
                + field('exclusion', t('storageExclusionFilter', 'Exclusion'))
                + field('strictExclusion', t('storageStrictExclusionFilter', 'Strict exclusion'));
        };

        node.innerHTML = '<details class="storage-rules">'
            + '<summary>' + escapeHtml(t('manageStorageRules', 'Manage storage rules by container')) + '</summary>'
            + '<p class="storage-rules-help">' + escapeHtml(t('storageRulesHelp', 'Priority routes matching new items to this container first unless it is full. Exclusion avoids this container unless no other non-strict container can accept the item. Strict exclusion prevents automatic placement into this container.')) + '</p>'
            + '<div class="storage-container-list">'
            + containers.map((container) => (
                '<form class="storage-rules-form" data-container-id="' + escapeHtml(container.id) + '">'
                + '<div><span>' + escapeHtml(containerLabel(container)) + '</span><b>'
                + escapeHtml(numberValue(container.usedCapacity || 0) + ' / ' + numberValue(container.capacity || 0))
                + '</b></div>'
                + '<div class="storage-rule-fields">' + ruleFields(container) + '</div>'
                + '<button type="submit">' + escapeHtml(t('saveStorageRules', 'Save rules')) + '</button>'
                + '</form>'
            )).join('')
            + '</div>'
            + '</details>'
            + '<div class="inventory-filter-row">'
            + '<label>' + escapeHtml(t('inventoryContainerFilter', 'Inventory view'))
            + '<select id="inventory-container-filter">' + options.join('') + '</select></label>'
            + '</div>';

        const select = node.querySelector('#inventory-container-filter');
        if (select) {
            select.addEventListener('change', () => {
                state.inventoryContainerFilter = select.value || 'all';
                renderInventory(state.currentInventory);
            });
        }
        node.querySelectorAll('.storage-rules-form').forEach((form) => {
            syncStorageRuleForm(form);
        });
        node.querySelectorAll('.storage-rule-select').forEach((select) => {
            select.addEventListener('change', () => {
                syncStorageRuleForm(select.closest('.storage-rules-form'), select);
            });
        });
    };

    const groupInventoryItems = (items) => {
        const groups = new Map();
        items.forEach((item) => {
            const key = [item.type || 'item', item.name || ''].join('::');
            if (!groups.has(key)) {
                groups.set(key, {
                    type: item.type || 'item',
                    name: item.name || item.type || 'item',
                    sample: item,
                    items: [],
                    placements: new Map(),
                    totalSpace: 0,
                });
            }
            const group = groups.get(key);
            group.items.push(item);
            group.totalSpace += Number(item.containerSpace || 0);
            const container = itemContainer(item);
            const containerId = container && container.id ? container.id : 'unknown';
            if (!group.placements.has(containerId)) {
                group.placements.set(containerId, {
                    container,
                    quantity: 0,
                    containerSpace: 0,
                    itemIds: [],
                    items: [],
                    names: [],
                });
            }
            const placement = group.placements.get(containerId);
            placement.quantity += 1;
            placement.containerSpace += Number(item.containerSpace || 0);
            placement.itemIds.push(item.id);
            placement.items.push(item);
            placement.names.push(item.name || item.id);
        });

        return Array.from(groups.values());
    };

    function renderInventory(inventory) {
        const node = document.getElementById('inventory-list');
        if (!node) {
            return;
        }
        state.currentInventory = inventory && typeof inventory === 'object' ? inventory : null;
        if (!state.inventoryContainerFilter) {
            state.inventoryContainerFilter = 'all';
        }
        onInventoryChanged();
        renderStorageRules(inventory);
        if (!inventory || typeof inventory !== 'object') {
            node.innerHTML = '';
            return;
        }

        const stockCards = (Array.isArray(inventory.resourceStocks) ? inventory.resourceStocks : [])
            .map((stock) => {
                const placements = filteredStockPlacements(stock);
                const amount = placements.reduce((total, placement) => total + Number(placement.amount || 0), 0);
                const space = placements.reduce((total, placement) => total + Number(placement.containerSpace || placement.amount || 0), 0);
                if (amount <= 0) {
                    return '';
                }

                return '<article class="inventory-card">'
                    + '<div><span>' + escapeHtml(t('inventoryStock', 'Stock')) + '</span><b>' + escapeHtml(resourceTypeLabel(stock.type)) + '</b></div>'
                    + '<p>' + escapeHtml(resourceStockDetail(stock, amount, space)) + '</p>'
                    + placementLines(placements, 'amount', {kind: 'resource', resourceType: stock.type, itemId: stock.id || stock.type})
                    + '</article>';
            })
            .filter(Boolean);

        const tankCards = (Array.isArray(inventory.externalTanks) ? inventory.externalTanks : [])
            .filter(() => selectedContainerId() === 'all')
            .filter((tank) => Number(tank.fillPercent) > 0)
            .map((tank) => (
                '<article class="inventory-card">'
                + '<div><span>' + escapeHtml(t('externalTank', 'External tank')) + '</span><b>' + escapeHtml(resourceTypeLabel(tank.type)) + '</b></div>'
                + '<p>' + escapeHtml(t('storedAmount', 'Amount') + ' ' + numberValue(tank.fillPercent, '%')) + '</p>'
                + '</article>'
            ));

        const filteredItems = (Array.isArray(inventory.items) ? inventory.items : []).filter(itemMatchesContainerFilter);
        const itemCards = groupInventoryItems(filteredItems).map((group) => {
            const placements = Array.from(group.placements.values()).map((placement) => ({
                container: placement.container,
                quantity: placement.quantity,
                containerSpace: placement.containerSpace,
                itemIds: placement.itemIds,
                items: placement.items,
            }));
            const kind = group.type === 'manny' ? 'manny' : 'item';

            return '<article class="inventory-card">'
                + '<div><span>' + escapeHtml(t('inventoryItem', 'Equipment')) + '</span><b>' + escapeHtml(inventoryItemName(group.sample)) + '</b></div>'
                + '<p>' + escapeHtml(t('quantity', 'Quantity') + ' ' + numberValue(group.items.length) + ' · ' + t('containerSpace', 'Space') + ' ' + numberValue(group.totalSpace)) + '</p>'
                + placementLines(placements, 'quantity', {kind, itemType: group.type})
                + (group.items.length === 1 ? '<p>' + escapeHtml(inventoryEntryDetail(group.sample)) + '</p>' : '')
                + '</article>';
        });

        node.innerHTML = stockCards.concat(tankCards, itemCards).join('');
    }

    return {
        inventoryItemName,
        renderInventory,
    };
};
