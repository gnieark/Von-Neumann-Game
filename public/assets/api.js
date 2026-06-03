export const cookieValue = (name) => document.cookie
    .split('; ')
    .find((row) => row.startsWith(name + '='))
    ?.split('=')
    .slice(1)
    .join('=') || '';

export const syncOAuthRememberLinks = () => {
    const remember = document.getElementById('oauth-remember');
    document.querySelectorAll('[data-oauth-url]').forEach((link) => {
        const url = new URL(link.dataset.oauthUrl || link.getAttribute('href') || '', window.location.origin);
        if (remember && remember.checked) {
            url.searchParams.set('remember', '1');
        } else {
            url.searchParams.delete('remember');
        }
        link.setAttribute('href', url.pathname + url.search);
    });
};

export const bindOAuthRememberLinks = () => {
    document.getElementById('oauth-remember')?.addEventListener('change', syncOAuthRememberLinks);
    syncOAuthRememberLinks();
};

export const initSwaggerUi = () => {
    if (!document.getElementById('swagger-ui') || !window.SwaggerUIBundle) {
        return;
    }

    window.SwaggerUIBundle({
        url: '/openapi.yaml',
        dom_id: '#swagger-ui',
        persistAuthorization: true,
        tryItOutEnabled: true,
        requestInterceptor: (request) => {
            const sessionToken = decodeURIComponent(cookieValue('vn_session'));
            request.headers = request.headers || {};
            if (sessionToken && !request.headers.Authorization) {
                request.headers.Authorization = 'Bearer ' + sessionToken;
            }

            return request;
        },
    });
};

export const createApiClient = ({t}) => {
    const token = decodeURIComponent(cookieValue('vn_session'));
    const headers = () => ({
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json',
    });

    return async (path, options) => {
        const response = await fetch(path, {
            ...options,
            headers: {...headers(), ...(options && options.headers ? options.headers : {})},
        });
        const data = await response.json();
        if (!response.ok) {
            const message = data.error && data.error.message ? data.error.message : t('requestDenied', 'Request denied');
            throw new Error(message);
        }

        return data;
    };
};

const showDialog = (dialog) => {
    if (dialog && typeof dialog.showModal === 'function' && !dialog.open) {
        dialog.showModal();
    } else if (dialog) {
        dialog.hidden = false;
    }
};

export const bindApiKeyDialog = ({api, t, closeAccountMenus}) => {
    document.querySelector('[data-api-key-action]')?.addEventListener('click', async () => {
        closeAccountMenus();
        const dialog = document.getElementById('api-key-dialog');
        const value = document.getElementById('api-key-value');
        const status = document.getElementById('api-key-status');
        if (status) {
            status.textContent = t('apiKeyGenerating', 'Generating API key...');
        }
        try {
            const data = await api('/api/me/api-key', {method: 'POST', body: JSON.stringify({})});
            if (value) {
                value.value = data.apiKey && data.apiKey.token ? data.apiKey.token : '';
                value.focus();
                value.select();
            }
            if (status) {
                status.textContent = t('apiKeyReady', 'API key ready. It is shown only once.');
            }
            showDialog(dialog);
        } catch (error) {
            if (status) {
                status.textContent = error.message;
            }
            showDialog(dialog);
        }
    });

    document.getElementById('copy-api-key')?.addEventListener('click', async () => {
        const value = document.getElementById('api-key-value');
        const status = document.getElementById('api-key-status');
        if (!value || !value.value) {
            return;
        }
        try {
            await navigator.clipboard.writeText(value.value);
            if (status) {
                status.textContent = t('apiKeyCopied', 'API key copied.');
            }
        } catch (error) {
            value.focus();
            value.select();
            if (status) {
                status.textContent = t('apiKeyCopyFallback', 'Select the key and copy it manually.');
            }
        }
    });
};
