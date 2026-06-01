(function () {
    const i18nNode = document.getElementById('i18n-json');
    const i18n = i18nNode ? JSON.parse(i18nNode.textContent || '{}') : {};
    document.querySelector('.language-form select')?.addEventListener('change', (event) => {
        event.currentTarget.form?.submit();
    });
    const cookieValue = (name) => document.cookie
        .split('; ')
        .find((row) => row.startsWith(name + '='))
        ?.split('=')
        .slice(1)
        .join('=') || '';
    const syncOAuthRememberLinks = () => {
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
    document.getElementById('oauth-remember')?.addEventListener('change', syncOAuthRememberLinks);
    syncOAuthRememberLinks();

    if (document.getElementById('swagger-ui') && window.SwaggerUIBundle) {
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
    }

    const body = document.body;
    if (!body || body.dataset.authenticated !== '1') {
        return;
    }

    const token = decodeURIComponent(cookieValue('vn_session'));
    const headers = () => ({
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json',
    });

    const pretty = (value) => JSON.stringify(JSON.parse(JSON.stringify(value), (key, item) => (
        key === 'id' && typeof item === 'string' && item.startsWith('mny_') ? '[internal]' : item
    )), null, 2);
    const validRelativeCoordinates = (target) => (target.x + target.y + target.z) % 2 === 0;
    const t = (key, fallback) => i18n[key] || fallback;
    const invalidCoordinateMessage = t('invalidCoordinates', 'Invalid relative coordinates: x + y + z must be even.');
    const alreadyMovingMessage = 'The probe is already moving between sectors.';
    let probeAlreadyMoving = false;
    let currentMannyMineTargets = [];
    const miningResourceTypes = ['deuterium', 'metals', 'other'];
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
    const numberValue = (value, suffix) => Number.isFinite(Number(value)) ? Number(value).toFixed(2).replace(/\.?0+$/, '') + (suffix || '') : '-';
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
    const formatText = (template, values) => Object.entries(values).reduce(
        (text, [key, value]) => text.replaceAll('{' + key + '}', String(value)),
        template
    );
    const pluralWord = (count, singularKey, singularFallback, pluralKey, pluralFallback) => (
        Number(count) === 1 ? t(singularKey, singularFallback) : t(pluralKey, pluralFallback)
    );
    const numericCount = (value) => {
        const number = Number(value);
        return Number.isFinite(number) ? number : 0;
    };
    const sumCount = (items, key) => items.reduce((total, item) => total + numericCount(item[key]), 0);
    const resourceTypeLabel = (type) => ({
        deuterium: t('deuterium', 'Deuterium'),
        metals: t('metals', 'Metals'),
        other: t('otherResources', 'Other'),
    }[type] || type);
    const resourceTypeFromHint = (hint) => {
        const value = String(hint || '').toLowerCase();
        if (value.includes('water') || value.includes('ice') || value.includes('volatile') || value.includes('hydrogen')) {
            return 'deuterium';
        }
        if (value.includes('iron') || value.includes('nickel') || value.includes('metal') || value.includes('platinum') || value.includes('magnesium')) {
            return 'metals';
        }

        return 'other';
    };
    const resourceCompositionForTarget = (target) => {
        const composition = target && target.resourceComposition && typeof target.resourceComposition === 'object'
            ? target.resourceComposition
            : null;
        if (composition) {
            return miningResourceTypes.reduce((result, type) => {
                result[type] = Math.max(0, Number(composition[type]) || 0);
                return result;
            }, {});
        }

        const hints = Array.isArray(target && target.resources) ? target.resources : [];
        const counts = miningResourceTypes.reduce((result, type) => {
            result[type] = 0;
            return result;
        }, {});
        hints.forEach((hint) => {
            counts[resourceTypeFromHint(hint)] += 1;
        });
        const total = miningResourceTypes.reduce((sum, type) => sum + counts[type], 0) || 1;

        return miningResourceTypes.reduce((result, type) => {
            result[type] = counts[type] / total;
            return result;
        }, {});
    };
    const resourceTypesForTarget = (target) => {
        if (!target) {
            return [];
        }
        if (Array.isArray(target.resourceTypes) && target.resourceTypes.length > 0) {
            return miningResourceTypes.filter((type) => target.resourceTypes.includes(type));
        }
        const composition = resourceCompositionForTarget(target);

        return miningResourceTypes.filter((type) => Number(composition[type]) > 0);
    };
    const resourceCompositionLabel = (target) => {
        const composition = resourceCompositionForTarget(target);
        const parts = miningResourceTypes
            .filter((type) => Number(composition[type]) > 0)
            .map((type) => resourceTypeLabel(type) + ' ' + Math.round(Number(composition[type]) * 100) + '%');

        return parts.length > 0 ? parts.join(', ') : t('compositionUnknown', 'unknown composition');
    };
    const mineTargetLabel = (target) => {
        const name = target.name || target.id || '';
        const base = [target.type || 'object', name].filter(Boolean).join(' ');

        return base + ' (' + resourceCompositionLabel(target) + ')';
    };
    const taskLabel = (task) => ({
        repair: t('repair', 'Repair'),
        mining: t('mine', 'Mine'),
        returning: t('returning', 'Returning'),
    }[task] || task || t('noTask', 'None'));
    const selectedResourceLabels = (types) => {
        const resources = Array.isArray(types) ? types : (types ? [types] : []);

        return resources.map(resourceTypeLabel).join(', ');
    };
    const miningTargetDetails = (target) => {
        if (!target) {
            return t('unknownMiningTarget', 'unknown target');
        }

        const name = target.name || target.id || t('unknownMiningTarget', 'unknown target');
        const type = target.type || t('object', 'object');
        const details = [];
        if (target.composition) {
            details.push(t('composition', 'Composition') + ' ' + target.composition);
        }
        if (target.category) {
            details.push(t('category', 'Category') + ' ' + target.category);
        }
        if (target.sizeCategory) {
            details.push(t('size', 'Size') + ' ' + target.sizeCategory);
        }
        details.push(resourceCompositionLabel(target));

        return [type, name].filter(Boolean).join(' ') + ' (' + details.filter(Boolean).join(', ') + ')';
    };
    const miningTaskTarget = (payload) => {
        if (payload && payload.target) {
            return payload.target;
        }

        return currentMannyMineTargets.find((target) => target.id === (payload && payload.objectId)) || null;
    };
    const renderMannyTaskPanel = (manny) => {
        const payload = manny.task || {};
        const progress = numberValue(manny.taskProgressPercent, '%');
        if (manny.currentTask === 'repair') {
            return '<section class="manny-task-panel">'
                + '<h4>' + escapeHtml(t('repairInProgress', 'Repair in progress')) + '</h4>'
                + '<p>' + escapeHtml(formatText(t('repairTaskDetail', '{percent}% damage scheduled, {metals} metal containers committed.'), {
                    percent: numberValue(payload.damagePercent),
                    metals: numberValue(payload.metalsCost),
                })) + '</p>'
                + '<p>' + escapeHtml(t('taskProgress', 'Progress')) + ' ' + escapeHtml(progress) + '</p>'
                + '<button class="manny-recall-button" type="button">' + escapeHtml(t('cancelRepair', 'Cancel repairs')) + '</button>'
                + '</section>';
        }
        if (manny.currentTask === 'mining') {
            return '<section class="manny-task-panel">'
                + '<h4>' + escapeHtml(t('miningInProgress', 'Mining in progress')) + '</h4>'
                + '<p>' + escapeHtml(formatText(t('miningTaskDetail', '{resources} on {target}.'), {
                    resources: selectedResourceLabels(payload.resourceTypes || payload.resourceType),
                    target: miningTargetDetails(miningTaskTarget(payload)),
                })) + '</p>'
                + '<p>' + escapeHtml(t('taskProgress', 'Progress')) + ' ' + escapeHtml(progress) + '</p>'
                + '<button class="manny-recall-button" type="button">' + escapeHtml(t('recall', 'Recall')) + '</button>'
                + '</section>';
        }

        return '<section class="manny-task-panel">'
            + '<h4>' + escapeHtml(taskLabel(manny.currentTask)) + '</h4>'
            + '<p>' + escapeHtml(t('taskProgress', 'Progress')) + ' ' + escapeHtml(progress) + '</p>'
            + '</section>';
    };
    const renderMannyActionForms = () => (
        '<div class="manny-action-grid">'
        + '<section class="manny-action-section">'
        + '<h4>' + escapeHtml(t('repairActionTitle', 'Repair')) + '</h4>'
        + '<form class="manny-repair-form manny-form">'
        + '<label>' + escapeHtml(t('repairPercent', 'Damage to repair')) + '<input name="percent" type="number" min="1" max="100" step="1" value="1"></label>'
        + '<button type="submit">' + escapeHtml(t('repair', 'Repair')) + '</button>'
        + '</form>'
        + '</section>'
        + '<section class="manny-action-section">'
        + '<h4>' + escapeHtml(t('miningActionTitle', 'Mine')) + '</h4>'
        + '<form class="manny-mine-form manny-form">'
        + '<label>' + escapeHtml(t('mineTarget', 'Object')) + '<select class="manny-mine-target" name="objectId">' + mineTargetOptions('') + '</select></label>'
        + '<label>' + escapeHtml(t('resources', 'Resources')) + '<select class="manny-mine-resources" name="resources" multiple size="3">'
        + mineResourceOptions(currentMannyMineTargets[0] || null, [])
        + '</select></label>'
        + '<label>' + escapeHtml(t('targetAmount', 'Amount')) + '<input name="targetAmount" type="number" min="0.01" max="0.55" step="0.01" value="0.01"></label>'
        + '<button type="submit">' + escapeHtml(t('mine', 'Mine')) + '</button>'
        + '</form>'
        + '</section>'
        + '</div>'
    );
    const sectorContext = (sector) => {
        const distance = Number(sector && sector.distance);
        if (!Number.isFinite(distance)) {
            return t('sectorContextUnavailable', 'Displayed sector: unavailable.');
        }
        if (distance === 0) {
            return t('sectorContextCurrent', 'Displayed sector: current probe position.');
        }

        return formatText(t('sectorContextRemote', 'Displayed sector: sector {distance} {sectorStepWord} away.'), {
            distance,
            sectorStepWord: pluralWord(distance, 'sectorStepSingular', 'sector', 'sectorStepPlural', 'sectors'),
        });
    };
    const detailedSectorSummary = (objects) => {
        if (objects.length === 0) {
            return t('sectorSummaryEmpty', 'Empty sector.');
        }

        const blackHoles = objects.filter((object) => object.type === 'black_hole');
        if (blackHoles.length > 0) {
            const otherObjects = objects.length - blackHoles.length;
            if (otherObjects === 0) {
                if (blackHoles.length === 1) {
                    return t('sectorSummaryBlackHole', 'Hazardous sector: black hole detected.');
                }

                return formatText(t('sectorSummaryBlackHoles', 'Hazardous sector: {blackHoles} {blackHoleWord} detected.'), {
                    blackHoles: blackHoles.length,
                    blackHoleWord: pluralWord(blackHoles.length, 'blackHoleSingular', 'black hole', 'blackHolePlural', 'black holes'),
                });
            }

            return formatText(t('sectorSummaryBlackHoleWithObjects', 'Hazardous sector: {blackHoles} {blackHoleWord} and {objects} {otherObjectWord} present.'), {
                blackHoles: blackHoles.length,
                blackHoleWord: pluralWord(blackHoles.length, 'blackHoleSingular', 'black hole', 'blackHolePlural', 'black holes'),
                objects: otherObjects,
                otherObjectWord: pluralWord(otherObjects, 'otherObjectSingular', 'other object', 'otherObjectPlural', 'other objects'),
            });
        }

        const solarSystems = objects.filter((object) => object.type === 'solar_system');
        if (solarSystems.length > 0) {
            const planets = sumCount(solarSystems, 'planetCount');
            const orbitals = sumCount(solarSystems, 'orbitalBodyCount');
            const stars = Math.max(1, sumCount(solarSystems, 'starCount'));

            return formatText(t('sectorSummarySolarSystem', 'Solar system: {planets} {planetWord} among {orbitals} {orbitalObjectWord}, around {stars} {starWord}.'), {
                planets,
                planetWord: pluralWord(planets, 'planetSingular', 'planet', 'planetPlural', 'planets'),
                orbitals,
                orbitalObjectWord: pluralWord(orbitals, 'orbitalObjectSingular', 'orbital object', 'orbitalObjectPlural', 'orbital objects'),
                stars,
                starWord: pluralWord(stars, 'starSingular', 'star', 'starPlural', 'stars'),
            });
        }

        if (objects.length === 1) {
            return t('sectorSummarySingleObject', 'Occupied sector: 1 object detected.');
        }

        return formatText(t('sectorSummaryObjects', 'Occupied sector: {count} objects detected.'), {
            count: objects.length,
        });
    };
    const estimatedSectorSummary = (estimate) => {
        if (Number(estimate.blackHoleProbability || 0) >= 0.5) {
            return t('sectorSummaryBlackHoleLikely', 'Strong gravity signature: black hole likely.');
        }
        if (estimate.star) {
            return formatText(t('sectorSummaryNeighborStar', 'Probable stellar system: {min} to {max} planets estimated.'), {
                min: numericCount(estimate.planetCountMin),
                max: numericCount(estimate.planetCountMax),
            });
        }

        return t('sectorSummaryNoMajorNearby', 'No major nearby object estimated.');
    };
    const possibleSectorSummary = (sector) => {
        const signatures = Array.isArray(sector.possibleObjects) ? sector.possibleObjects : [];
        if (signatures.includes('strong_gravity_signature')) {
            return t('sectorSummaryGravitySignature', 'Strong gravity signature: black hole possible.');
        }
        if (signatures.includes('stellar_mass_detected')) {
            return t('sectorSummaryDistantStar', 'Distant stellar signature detected.');
        }
        if (signatures.includes('dust_cloud_possible')) {
            return t('sectorSummaryDustPossible', 'Possible dust cloud in the sector.');
        }

        return t('sectorSummaryNoMajorSignature', 'No major signature detected.');
    };
    const sectorSummary = (sector) => {
        if (!sector) {
            return t('sectorSummaryUnavailable', 'No sector analysis available.');
        }
        if (Array.isArray(sector.objects)) {
            return detailedSectorSummary(sector.objects);
        }
        if (sector.estimatedObjects && typeof sector.estimatedObjects === 'object') {
            return estimatedSectorSummary(sector.estimatedObjects);
        }
        if (Array.isArray(sector.possibleObjects)) {
            return possibleSectorSummary(sector);
        }

        return t('sectorSummaryLongRange', 'Long-range estimate: not enough detail for a reliable inventory.');
    };
    const syncSectorForm = (sector) => {
        const relative = sector && sector.relativeCoordinates;
        const form = document.getElementById('sector-form');
        if (!form || !relative) {
            return;
        }

        ['x', 'y', 'z'].forEach((field) => {
            if (form.elements[field]) {
                form.elements[field].value = relative[field] ?? 0;
            }
        });
    };
    const mineTargetsFromObjects = (objects) => objects.flatMap((object) => {
        const direct = object.mannyMineable ? [{
            id: object.id,
            type: object.type || 'object',
            name: object.name || object.id || '',
            resources: object.resources || [],
            resourceTypes: object.resourceTypes || [],
            resourceComposition: object.resourceComposition || {},
        }] : [];
        const nested = Array.isArray(object.minableTargets)
            ? object.minableTargets.map((target) => ({
                id: target.id,
                type: target.type || 'object',
                name: target.name || target.id || '',
                resources: target.resources || [],
                resourceTypes: target.resourceTypes || [],
                resourceComposition: target.resourceComposition || {},
            }))
            : [];

        return direct.concat(nested).filter((target) => target.id);
    });
    const renderSectorObjects = (sector, options = {}) => {
        const node = document.getElementById('sector-objects');
        if (!node) {
            return;
        }

        setText('sector-context', sectorContext(sector));
        setText('sector-summary', sectorSummary(sector));
        const objects = Array.isArray(sector && sector.objects) ? sector.objects : [];
        const distance = Number(sector && sector.distance);
        const syncMannyTargets = options.syncMannyTargets ?? (Boolean(sector) && Number.isFinite(distance) && distance === 0);
        if (syncMannyTargets) {
            currentMannyMineTargets = mineTargetsFromObjects(objects);
        }
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
        if (syncMannyTargets) {
            updateMannyTargetOptions();
        }
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

    function updateMoveButtonState(probe) {
        const button = document.getElementById('move-submit');
        if (!button) {
            return;
        }

        const movement = probe && probe.movement ? probe.movement : null;
        probeAlreadyMoving = Boolean(movement && ['preparing', 'accelerating', 'cruising', 'decelerating'].includes(movement.phase || movement.status));
        button.disabled = probeAlreadyMoving;
        button.title = probeAlreadyMoving ? alreadyMovingMessage : '';
        button.setAttribute('aria-disabled', probeAlreadyMoving ? 'true' : 'false');
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

    const closeAccountMenus = () => {
        document.querySelectorAll('.account-menu-button[aria-expanded="true"]').forEach((button) => {
            button.setAttribute('aria-expanded', 'false');
            button.closest('.account-menu')?.querySelector('.account-menu-panel')?.setAttribute('hidden', '');
        });
    };

    document.querySelectorAll('.account-menu-button').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const panel = button.closest('.account-menu')?.querySelector('.account-menu-panel');
            const willOpen = button.getAttribute('aria-expanded') !== 'true';
            closeAccountMenus();
            button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (panel) {
                panel.hidden = !willOpen;
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.account-menu')) {
            closeAccountMenus();
        }
    });

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
            if (dialog && typeof dialog.showModal === 'function' && !dialog.open) {
                dialog.showModal();
            } else if (dialog) {
                dialog.hidden = false;
            }
        } catch (error) {
            if (status) {
                status.textContent = error.message;
            }
            if (dialog && typeof dialog.showModal === 'function' && !dialog.open) {
                dialog.showModal();
            } else if (dialog) {
                dialog.hidden = false;
            }
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
                metric(t('damage', 'Damage'), numberValue(systems.damagePercent, '%')),
                metric(t('energy', 'Energy'), systems.energyStored),
                metric(t('internalClock', 'Internal clock'), systems.internalClockRate),
                metric(t('task', 'Task'), systems.currentTask || t('noTask', 'None')),
            ].join('');
            setText('probe-json', pretty(data));
            setText('inventory-json', pretty(probe.inventory || {}));
        } catch (error) {
            updateMoveButtonState(null);
            setText('probe-json', error.message);
            setText('inventory-json', error.message);
        }
    }

    const mannyLocation = (manny) => {
        const location = manny.location || {};
        if (location.type === 'probe') {
            return t('tabProbe', 'Probe');
        }
        return location.sector && location.sector.relative
            ? t('sector', 'Sector') + ' ' + coordinate(location.sector.relative)
            : t('sector', 'Sector');
    };

    const mannyCargo = (manny) => {
        const cargo = manny.cargo || {};
        return [
            'D ' + numberValue(cargo.deuterium),
            'M ' + numberValue(cargo.metals),
            'O ' + numberValue(cargo.other),
        ].join(' / ');
    };

    function mineTargetOptions(selected) {
        if (currentMannyMineTargets.length === 0) {
            return '<option value="">-</option>';
        }

        return currentMannyMineTargets.map((target) => (
            '<option value="' + escapeHtml(target.id) + '"' + (target.id === selected ? ' selected' : '') + '>'
            + escapeHtml(mineTargetLabel(target))
            + '</option>'
        )).join('');
    }

    function mineResourceOptions(target, selectedResources) {
        const available = resourceTypesForTarget(target);
        const selected = selectedResources.filter((type) => available.includes(type));
        const effectiveSelection = selected.length > 0 ? selected : available;

        return miningResourceTypes.map((type) => {
            const disabled = !available.includes(type);
            const isSelected = effectiveSelection.includes(type);

            return '<option value="' + escapeHtml(type) + '"'
                + (disabled ? ' disabled' : '')
                + (isSelected ? ' selected' : '')
                + '>' + escapeHtml(resourceTypeLabel(type)) + '</option>';
        }).join('');
    }

    function updateMannyResourceOptions(form) {
        if (!form) {
            return;
        }

        const targetSelect = form.querySelector('.manny-mine-target');
        const resourceSelect = form.querySelector('.manny-mine-resources');
        if (!targetSelect || !resourceSelect) {
            return;
        }

        const target = currentMannyMineTargets.find((item) => item.id === targetSelect.value) || null;
        const selectedResources = Array.from(resourceSelect.selectedOptions).map((option) => option.value);
        resourceSelect.innerHTML = mineResourceOptions(target, selectedResources);
    }

    function updateMannyTargetOptions() {
        document.querySelectorAll('.manny-mine-target').forEach((select) => {
            const selected = select.value;
            select.innerHTML = mineTargetOptions(selected);
            if (!currentMannyMineTargets.some((target) => target.id === select.value)) {
                select.value = currentMannyMineTargets[0] ? currentMannyMineTargets[0].id : '';
            }
            updateMannyResourceOptions(select.closest('.manny-mine-form'));
        });
    }

    function renderMannyList(mannies) {
        const node = document.getElementById('manny-list');
        if (!node) {
            return;
        }
        if (!Array.isArray(mannies) || mannies.length === 0) {
            node.innerHTML = '';
            return;
        }

        node.innerHTML = mannies.map((manny) => {
            const busy = manny.currentTask !== null;
            return '<article class="manny-card" data-manny-id="' + escapeHtml(manny.id) + '">'
                + '<div class="manny-card-head">'
                + '<div class="manny-title">'
                + '<b>' + escapeHtml(manny.name) + '</b>'
                + '<button class="manny-settings-button icon-button" type="button" aria-expanded="false" title="' + escapeHtml(t('mannySettings', 'Manny settings')) + '" aria-label="' + escapeHtml(t('mannySettings', 'Manny settings')) + '">&#9881;</button>'
                + '</div>'
                + '<span>' + escapeHtml(manny.currentTask || t('noTask', 'None')) + '</span>'
                + '</div>'
                + '<div class="manny-metrics">'
                + metric(t('location', 'Location'), mannyLocation(manny))
                + metric(t('cargo', 'Cargo'), mannyCargo(manny))
                + metric(t('task', 'Task'), busy ? numberValue(manny.taskProgressPercent, '%') : t('noTask', 'None'))
                + '</div>'
                + '<form class="manny-rename-form manny-form" hidden>'
                + '<label>' + escapeHtml(t('rename', 'Rename')) + '<input name="name" value="' + escapeHtml(manny.name) + '" maxlength="40"></label>'
                + '<button type="submit">' + escapeHtml(t('rename', 'Rename')) + '</button>'
                + '</form>'
                + (busy ? renderMannyTaskPanel(manny) : renderMannyActionForms())
                + '</article>';
        }).join('');
    }

    async function loadMannies() {
        try {
            const data = await api('/api/probe/mannies');
            renderMannyList(data.mannies || []);
            setText('manny-json', pretty(data));
        } catch (error) {
            renderMannyList([]);
            setText('manny-json', error.message);
        }
    }

    async function loadCurrentSector() {
        try {
            const data = await api('/api/probe/sector');
            syncSectorForm(data.sector);
            renderSectorObjects(data.sector);
            setText('sector-json', pretty(data));
        } catch (error) {
            renderSectorObjects(null, {syncMannyTargets: true});
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
            if (button.dataset.refresh === 'mannies') {
                loadMannies();
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
            syncSectorForm(data.sector);
            renderSectorObjects(data.sector);
            setText('sector-json', pretty(data));
        } catch (error) {
            renderSectorObjects(null, {syncMannyTargets: false});
            setText('sector-json', error.message);
        }
    });

    document.getElementById('jump-control')?.addEventListener('click', () => {
        if (probeAlreadyMoving) {
            setText('action-status', alreadyMovingMessage);
            setText('movement-json', '');
        }
    });

    document.getElementById('move-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (probeAlreadyMoving) {
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

    document.getElementById('manny-list')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const card = event.target.closest('.manny-card');
        const mannyId = card ? card.dataset.mannyId : null;
        if (!mannyId) {
            return;
        }
        const form = new FormData(event.target);
        setText('manny-status', t('orderSent', 'Order transmitted...'));
        try {
            if (event.target.classList.contains('manny-rename-form')) {
                await api('/api/probe/mannies/' + encodeURIComponent(mannyId), {
                    method: 'PATCH',
                    body: JSON.stringify({name: form.get('name')}),
                });
            } else if (event.target.classList.contains('manny-repair-form')) {
                await api('/api/probe/mannies/' + encodeURIComponent(mannyId) + '/repair', {
                    method: 'POST',
                    body: JSON.stringify({percent: Number.parseFloat(form.get('percent'))}),
                });
            } else if (event.target.classList.contains('manny-mine-form')) {
                const resourceSelect = event.target.querySelector('.manny-mine-resources');
                const resources = resourceSelect
                    ? Array.from(resourceSelect.selectedOptions).filter((option) => !option.disabled).map((option) => option.value)
                    : [];
                if (resources.length === 0) {
                    setText('manny-status', t('noMiningResourceSelected', 'Select at least one available resource.'));
                    return;
                }
                await api('/api/probe/mannies/' + encodeURIComponent(mannyId) + '/mine', {
                    method: 'POST',
                    body: JSON.stringify({
                        objectId: form.get('objectId'),
                        resources,
                        targetAmount: Number.parseFloat(form.get('targetAmount')),
                    }),
                });
            }
            setText('manny-status', t('mannyOrderAccepted', 'Manny order accepted.'));
            loadProbe();
            loadMannies();
        } catch (error) {
            setText('manny-status', error.message);
        }
    });

    document.getElementById('manny-list')?.addEventListener('change', (event) => {
        if (event.target.classList.contains('manny-mine-target')) {
            updateMannyResourceOptions(event.target.closest('.manny-mine-form'));
        }
    });

    document.getElementById('manny-list')?.addEventListener('click', async (event) => {
        const settingsButton = event.target.closest('.manny-settings-button');
        if (settingsButton) {
            const card = settingsButton.closest('.manny-card');
            const renameForm = card ? card.querySelector('.manny-rename-form') : null;
            if (!renameForm) {
                return;
            }

            const willOpen = renameForm.hidden;
            renameForm.hidden = !willOpen;
            settingsButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) {
                renameForm.querySelector('input[name="name"]')?.focus();
            }
            return;
        }

        const button = event.target.closest('.manny-recall-button');
        if (!button) {
            return;
        }
        const card = button.closest('.manny-card');
        const mannyId = card ? card.dataset.mannyId : null;
        if (!mannyId) {
            return;
        }
        setText('manny-status', t('orderSent', 'Order transmitted...'));
        try {
            await api('/api/probe/mannies/' + encodeURIComponent(mannyId) + '/recall', {
                method: 'POST',
                body: JSON.stringify({}),
            });
            setText('manny-status', t('mannyOrderAccepted', 'Manny order accepted.'));
            loadProbe();
            loadMannies();
        } catch (error) {
            setText('manny-status', error.message);
        }
    });

    if (document.querySelector('.console-grid')) {
        loadProbe();
        loadCurrentSector();
        loadMannies();
    }
})();
