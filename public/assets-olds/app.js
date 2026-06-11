const assetVersion = new URL(import.meta.url).searchParams.get('v') || 'dev';

const loadI18n = async () => {
    const url = document.body?.dataset.i18nUrl || '';
    if (!url) {
        return {};
    }

    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
        });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        return await response.json();
    } catch (error) {
        console.warn('Unable to load the i18n dictionary.', error);
        return {};
    }
};

globalThis.VNG_I18N = await loadI18n();

await import('./main.js?v=' + encodeURIComponent(assetVersion));
