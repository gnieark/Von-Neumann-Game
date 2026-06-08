export const readI18n = () => {
    if (globalThis.VNG_I18N && typeof globalThis.VNG_I18N === 'object') {
        return globalThis.VNG_I18N;
    }

    const i18nNode = document.getElementById('i18n-json');
    return i18nNode ? JSON.parse(i18nNode.textContent || '{}') : {};
};

export const bindLanguageForm = () => {
    document.querySelector('.language-form select')?.addEventListener('change', (event) => {
        event.currentTarget.form?.submit();
    });
};

export const pretty = (value) => JSON.stringify(JSON.parse(JSON.stringify(value), (key, item) => (
    key === 'id' && typeof item === 'string' && item.startsWith('mny_') ? '[internal]' : item
)), null, 2);

export const validRelativeCoordinates = (target) => (target.x + target.y + target.z) % 2 === 0;

export const setText = (id, value) => {
    const node = document.getElementById(id);
    if (node) {
        node.textContent = value;
    }
};

export const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

export const coordinate = (value) => value ? value.x + ':' + value.y + ':' + value.z : '-';

export const numberValue = (value, suffix) => (
    Number.isFinite(Number(value))
        ? Number(value).toFixed(2).replace(/\.?0+$/, '') + (suffix || '')
        : '-'
);

export const capacityUnitLabel = (unit) => ({
    earth_container_equivalent: 'ECE',
}[unit] || String(unit || 'ECE'));

export const storageCapacityValue = (inventory) => {
    const used = Number(inventory && inventory.usedCapacity);
    const capacity = Number(inventory && inventory.capacity);
    if (!Number.isFinite(used) || !Number.isFinite(capacity)) {
        return '-';
    }

    return numberValue(used) + ' / ' + numberValue(capacity) + ' ' + capacityUnitLabel(inventory.capacityUnit);
};

export const duration = (seconds, t = (key, fallback) => fallback) => {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '-';
    }
    if (seconds < 60) {
        return Math.round(seconds) + ' ' + t('secondsShort', 's');
    }

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = Math.round(seconds % 60);
    return [
        hours > 0 ? hours + ' h' : '',
        minutes > 0 ? minutes + ' min' : '',
        hours === 0 ? remainingSeconds + ' ' + t('secondsShort', 's') : '',
    ].filter(Boolean).join(' ');
};

export const detailList = (items) => (
    '<dl>' + items.map((item) => (
        '<div><dt>' + escapeHtml(item.label) + '</dt><dd>'
        + (Object.prototype.hasOwnProperty.call(item, 'htmlValue') ? String(item.htmlValue ?? '') : escapeHtml(item.value))
        + '</dd></div>'
    )).join('') + '</dl>'
);

export const formatText = (template, values) => Object.entries(values).reduce(
    (text, [key, value]) => text.replaceAll('{' + key + '}', String(value)),
    template
);

export const numericCount = (value) => {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
};

export const sumCount = (items, key) => items.reduce((total, item) => total + numericCount(item[key]), 0);

export const metric = (label, value, detail, valueClass, valueAttributes) => {
    const classAttribute = valueClass ? ' class="' + escapeHtml(valueClass) + '"' : '';
    const extraAttributes = valueAttributes ? ' ' + valueAttributes : '';
    const content = '<span>' + escapeHtml(label) + '</span><b' + classAttribute + extraAttributes + '>' + escapeHtml(String(value ?? '-')) + '</b>';
    if (!detail) {
        return '<div class="metric">' + content + '</div>';
    }

    return '<button class="metric interactive-metric" type="button" aria-expanded="false">'
        + content
        + '<span class="metric-detail" role="status">' + detail + '</span>'
        + '</button>';
};
