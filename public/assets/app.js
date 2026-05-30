(function () {
    const i18nNode = document.getElementById('i18n-json');
    const i18n = i18nNode ? JSON.parse(i18nNode.textContent || '{}') : {};
    document.querySelector('.language-form select')?.addEventListener('change', (event) => {
        event.currentTarget.form?.submit();
    });
    const body = document.body;
    if (!body || body.dataset.authenticated !== '1') {
        return;
    }

    const cookieValue = (name) => document.cookie
        .split('; ')
        .find((row) => row.startsWith(name + '='))
        ?.split('=')
        .slice(1)
        .join('=') || '';

    const token = decodeURIComponent(cookieValue('vn_session'));
    const headers = () => ({
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json',
    });

    const pretty = (value) => JSON.stringify(value, null, 2);
    const validRelativeCoordinates = (target) => (target.x + target.y + target.z) % 2 === 0;
    const t = (key, fallback) => i18n[key] || fallback;
    const invalidCoordinateMessage = t('invalidCoordinates', 'Invalid relative coordinates: x + y + z must be even.');
    const setText = (id, value) => {
        const node = document.getElementById(id);
        if (node) {
            node.textContent = value;
        }
    };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const coordinate = (value) => value ? value.x + ':' + value.y + ':' + value.z : '-';
    const duration = (seconds) => {
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

    const detailList = (items) => (
        '<dl>' + items.map((item) => (
            '<div><dt>' + escapeHtml(item.label) + '</dt><dd>' + escapeHtml(item.value) + '</dd></div>'
        )).join('') + '</dl>'
    );
    const renderSectorObjects = (sector) => {
        const node = document.getElementById('sector-objects');
        if (!node) {
            return;
        }

        const objects = Array.isArray(sector && sector.objects) ? sector.objects : [];
        node.innerHTML = objects.map((object) => {
            const danger = object.dangerLevel || 'unknown';
            const classes = ['sector-object', danger === 'extreme' ? 'sector-object-warning' : ''].filter(Boolean).join(' ');
            const countdown = object.noReturnCountdown && Number.isFinite(Number(object.noReturnCountdown.secondsRemaining))
                ? '<p class="sector-object-countdown">Point de non-retour dans '
                    + escapeHtml(duration(Number(object.noReturnCountdown.secondsRemaining)))
                    + '</p>'
                : '';
            return '<article class="' + classes + '">'
                + '<div><span>' + escapeHtml(object.type || 'unknown') + '</span><b>' + escapeHtml(danger) + '</b></div>'
                + '<p>' + escapeHtml(object.summary || '') + '</p>'
                + countdown
                + '</article>';
        }).join('');
    };

    const metric = (label, value, detail) => {
        const content = '<span>' + escapeHtml(label) + '</span><b>' + escapeHtml(String(value ?? '-')) + '</b>';
        if (!detail) {
            return '<div class="metric">' + content + '</div>';
        }

        return '<button class="metric interactive-metric" type="button" aria-expanded="false">'
            + content
            + '<span class="metric-detail" role="status">' + detail + '</span>'
            + '</button>';
    };

    function bindMetricDetails() {
        document.querySelectorAll('.interactive-metric').forEach((metricNode) => {
            metricNode.addEventListener('click', () => {
                const expanded = metricNode.getAttribute('aria-expanded') === 'true';
                document.querySelectorAll('.interactive-metric[aria-expanded="true"]').forEach((openNode) => {
                    if (openNode !== metricNode) {
                        openNode.setAttribute('aria-expanded', 'false');
                    }
                });
                metricNode.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            });
        });
    }

    async function api(path, options) {
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
    }

    async function loadProbe() {
        try {
            const data = await api('/api/probe');
            const probe = data.probe || {};
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
                {label: t('remainingTime', 'Remaining time'), value: duration(Number(movement.secondsRemaining))},
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
                metric(t('integrity', 'Integrity'), systems.integrityPercent ? systems.integrityPercent + '%' : '-'),
                metric(t('energy', 'Energy'), systems.energyStored),
                metric(t('internalClock', 'Internal clock'), systems.internalClockRate),
                metric(t('task', 'Task'), systems.currentTask || t('noTask', 'None')),
            ].join('');
            setText('probe-json', pretty(data));
            setText('inventory-json', pretty(probe.inventory || {}));
        } catch (error) {
            setText('probe-json', error.message);
            setText('inventory-json', error.message);
        }
    }

    async function loadCurrentSector() {
        try {
            const data = await api('/api/probe/sector');
            renderSectorObjects(data.sector);
            setText('sector-json', pretty(data));
        } catch (error) {
            renderSectorObjects(null);
            setText('sector-json', error.message);
        }
    }

    document.querySelectorAll('.panel-tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.panel-tab').forEach((item) => item.classList.remove('active'));
            document.querySelectorAll('.data-panel').forEach((panel) => panel.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.panelTarget)?.classList.add('active');
        });
    });

    document.querySelectorAll('[data-refresh]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.dataset.refresh === 'sector') {
                loadCurrentSector();
                return;
            }
            loadProbe();
        });
    });

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
            renderSectorObjects(data.sector);
            setText('sector-json', pretty(data));
        } catch (error) {
            renderSectorObjects(null);
            setText('sector-json', error.message);
        }
    });

    document.getElementById('move-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
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
        setText('action-status', t('orderSent', 'Order transmitted...'));
        try {
            const data = await api('/api/probe/move', {
                method: 'POST',
                body: JSON.stringify({target}),
            });
            setText('action-status', t('movementAccepted', 'Movement accepted.'));
            setText('movement-json', pretty(data));
            loadProbe();
        } catch (error) {
            setText('action-status', error.message);
            setText('movement-json', '');
        }
    });

    loadProbe();
    loadCurrentSector();
})();
