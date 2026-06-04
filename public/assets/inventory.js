import {
    escapeHtml,
    numberValue,
} from './utils.js?v=20260604-system-bodies-v2';

export const createInventoryModule = ({state, labels, sector, onInventoryChanged = () => {}}) => {
    const {
        inventoryItemTypeLabel,
        objectTypeLabel,
        resourceTypeLabel,
        t,
        taskLabel,
    } = labels;

    const bookmarkItems = () => (
        Array.isArray(state.currentInventory && state.currentInventory.items)
            ? state.currentInventory.items.filter((item) => item.type === 'waypoint_bookmark')
            : []
    );

    const inventoryItemName = (item) => (
        item && item.type
            ? inventoryItemTypeLabel(item.type, item.name || item.type)
            : (item && (item.name || item.id)) || '-'
    );

    const bookmarkTargetLabel = (target) => (
        [objectTypeLabel(target.type || 'object'), target.name || target.id].filter(Boolean).join(' ')
    );

    const renderBookmarkAction = () => {
        const node = document.getElementById('bookmark-action');
        if (!node) {
            return;
        }

        const items = bookmarkItems();
        const targets = sector.bookmarkTargetsFromObjects(state.currentSectorObjects);
        if (items.length === 0) {
            node.innerHTML = '<section class="bookmark-action-panel">'
                + '<h3>' + escapeHtml(t('bookmarkActionTitle', 'Place a waypoint bookmark')) + '</h3>'
                + '<p>' + escapeHtml(t('noWaypointBookmark', 'No waypoint bookmark in inventory.')) + '</p>'
                + '</section>';
            return;
        }
        if (targets.length === 0) {
            node.innerHTML = '<section class="bookmark-action-panel">'
                + '<h3>' + escapeHtml(t('bookmarkActionTitle', 'Place a waypoint bookmark')) + '</h3>'
                + '<p>' + escapeHtml(t('noBookmarkTarget', 'No celestial target available in the current sector.')) + '</p>'
                + '</section>';
            return;
        }

        node.innerHTML = '<section class="bookmark-action-panel">'
            + '<h3>' + escapeHtml(t('bookmarkActionTitle', 'Place a waypoint bookmark')) + '</h3>'
            + '<form id="bookmark-form" class="bookmark-form">'
            + '<label>' + escapeHtml(t('bookmarkItem', 'Bookmark')) + '<select name="itemId">'
            + items.map((item) => '<option value="' + escapeHtml(item.id) + '">' + escapeHtml(inventoryItemName(item)) + '</option>').join('')
            + '</select></label>'
            + '<label>' + escapeHtml(t('bookmarkTarget', 'Target')) + '<select name="objectId">'
            + targets.map((target) => '<option value="' + escapeHtml(target.id) + '">' + escapeHtml(bookmarkTargetLabel(target)) + '</option>').join('')
            + '</select></label>'
            + '<label>' + escapeHtml(t('bookmarkName', 'Name')) + '<input name="name" maxlength="80" required></label>'
            + '<button type="submit">' + escapeHtml(t('deployBookmark', 'Place')) + '</button>'
            + '</form>'
            + '</section>';
    };

    const inventoryEntryDetail = (entry) => {
        const details = [
            t('containerSpace', 'Space') + ' ' + numberValue(entry.containerSpace || 0),
        ];
        if (entry.location && entry.location.type) {
            details.push(t('location', 'Location') + ' ' + entry.location.type);
        }
        if (entry.currentTask) {
            details.push(t('task', 'Task') + ' ' + taskLabel(entry.currentTask));
        }

        return details.join(' · ');
    };

    const resourceStockDetail = (stock) => [
        t('storedAmount', 'Amount') + ' ' + numberValue(stock.amount),
        t('containerSpace', 'Space') + ' ' + numberValue(stock.containerSpace),
    ].join(' · ');

    const isJettisonableItem = (item) => {
        if (item.type === 'manny') {
            return item.location
                && item.location.type === 'probe'
                && item.currentTask === null;
        }

        return ['waypoint_bookmark', 'steel_bar', 'steel_plate'].includes(item.type);
    };

    const renderJettisonForm = (itemId, amount, withAmount, disabled) => {
        if (disabled) {
            return '<span class="inventory-muted">' + escapeHtml(t('notJettisonable', 'Not jettisonable')) + '</span>';
        }

        return '<form class="inventory-jettison-form' + (withAmount ? '' : ' inventory-jettison-form-simple') + '" data-item-id="' + escapeHtml(itemId) + '">'
            + (withAmount
                ? '<label>' + escapeHtml(t('jettisonAmount', 'Amount to jettison'))
                    + '<input name="amount" type="number" min="0.0001" max="' + escapeHtml(String(amount || 0)) + '" step="0.0001" value="' + escapeHtml(String(amount || 0)) + '"></label>'
                : '')
            + '<button type="submit">' + escapeHtml(t('jettison', 'Jettison')) + '</button>'
            + '</form>';
    };

    function renderInventory(inventory) {
        const node = document.getElementById('inventory-list');
        if (!node) {
            return;
        }
        state.currentInventory = inventory && typeof inventory === 'object' ? inventory : null;
        renderBookmarkAction();
        onInventoryChanged();
        if (!inventory || typeof inventory !== 'object') {
            node.innerHTML = '';
            return;
        }

        const stockCards = (Array.isArray(inventory.resourceStocks) ? inventory.resourceStocks : [])
            .filter((stock) => Number(stock.amount) > 0)
            .map((stock) => (
                '<article class="inventory-card">'
                + '<div><span>' + escapeHtml(t('inventoryStock', 'Stock')) + '</span><b>' + escapeHtml(resourceTypeLabel(stock.type)) + '</b></div>'
                + '<p>' + escapeHtml(resourceStockDetail(stock)) + '</p>'
                + renderJettisonForm(stock.id || stock.type, Number(stock.amount), true, false)
                + '</article>'
            ));

        const tankCards = (Array.isArray(inventory.externalTanks) ? inventory.externalTanks : [])
            .filter((tank) => Number(tank.fillPercent) > 0)
            .map((tank) => (
                '<article class="inventory-card">'
                + '<div><span>' + escapeHtml(t('externalTank', 'External tank')) + '</span><b>' + escapeHtml(resourceTypeLabel(tank.type)) + '</b></div>'
                + '<p>' + escapeHtml(t('storedAmount', 'Amount') + ' ' + numberValue(tank.fillPercent, '%')) + '</p>'
                + renderJettisonForm(tank.id || tank.type, Number(tank.fillPercent), true, true)
                + '</article>'
            ));

        const itemCards = (Array.isArray(inventory.items) ? inventory.items : []).map((item) => (
            '<article class="inventory-card">'
                + '<div><span>' + escapeHtml(t('inventoryItem', 'Equipment')) + '</span><b>' + escapeHtml(inventoryItemName(item)) + '</b></div>'
                + '<p>' + escapeHtml(inventoryEntryDetail(item)) + '</p>'
                + renderJettisonForm(item.id, 0, false, !isJettisonableItem(item))
                + '</article>'
        ));

        node.innerHTML = stockCards.concat(tankCards, itemCards).join('');
    }

    return {
        inventoryItemName,
        renderBookmarkAction,
        renderInventory,
    };
};
