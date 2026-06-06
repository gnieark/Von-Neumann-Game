import {
    duration,
    escapeHtml,
    formatText,
    numberValue,
    numericCount,
    setText,
    sumCount,
} from './utils.js?v=20260604-system-bodies-v2';

export const miningResourceTypes = ['deuterium', 'metals', 'ice', 'carbon_compounds'];
export const mannyMiningAmountMax = 0.55;

const sectorAlertAcknowledgementsStorageKey = 'vng:sector-alert-acknowledgements:v1';

export const createSectorModule = ({state, labels, onTargetsChanged = () => {}, onAlertsChanged = () => {}}) => {
    const {
        asteroidCompositionLabel,
        dangerLevelLabel,
        mannyStateLabel,
        objectTypeLabel,
        observationSummaryLabel,
        planetCategoryLabel,
        pluralWord,
        resourceTypeLabel,
        sizeCategoryLabel,
        t,
    } = labels;

    const sectorDuration = (seconds) => duration(seconds, t);

    const resourceTypeFromHint = (hint) => {
        const value = String(hint || '').toLowerCase();
        if (value.includes('deuterium') || value.includes('hydrogen')) {
            return 'deuterium';
        }
        if (value.includes('iron') || value.includes('nickel') || value.includes('metal') || value.includes('platinum') || value.includes('magnesium') || value.includes('silicate')) {
            return 'metals';
        }
        if (value.includes('water') || value.includes('ice') || value.includes('volatile') || value.includes('ammonia')) {
            return 'ice';
        }
        if (value.includes('carbon') || value.includes('organic') || value.includes('hydrocarbon')) {
            return 'carbon_compounds';
        }

        return 'carbon_compounds';
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
        const base = [objectTypeLabel(target.type || 'object'), name].filter(Boolean).join(' ');

        return base + ' (' + resourceCompositionLabel(target) + ')';
    };

    const miningTargetDetails = (target) => {
        if (!target) {
            return t('unknownMiningTarget', 'unknown target');
        }

        const name = target.name || target.id || t('unknownMiningTarget', 'unknown target');
        const type = objectTypeLabel(target.type || 'object');
        const details = [];
        if (target.composition) {
            details.push(t('composition', 'Composition') + ' ' + asteroidCompositionLabel(target.composition));
        }
        if (target.category) {
            details.push(t('category', 'Category') + ' ' + planetCategoryLabel(target.category));
        }
        if (target.sizeCategory) {
            details.push(t('size', 'Size') + ' ' + sizeCategoryLabel(target.sizeCategory));
        }
        details.push(resourceCompositionLabel(target));

        return [type, name].filter(Boolean).join(' ') + ' (' + details.filter(Boolean).join(', ') + ')';
    };

    const salvageTargetLabel = (target) => {
        if (target && target.type === 'drifting_item') {
            const name = target.name || target.itemType || t('unknownObject', 'Unknown object');
            const quantity = Number(target.quantity);

            return objectTypeLabel('drifting_item') + ' ' + name
                + (Number.isFinite(quantity) && quantity > 0 ? ' x' + String(quantity) : '');
        }

        const type = objectTypeLabel(target && target.type ? target.type : 'object');
        const name = target && (target.name || target.id) ? (target.name || target.id) : t('unknownObject', 'Unknown object');
        const targetState = target && target.mannyState ? ' - ' + mannyStateLabel(target.mannyState) : '';

        return type + ' ' + name + targetState;
    };

    const sectorAlertRelativeCoordinates = (sector) => {
        const relative = sector && sector.relativeCoordinates;
        if (!relative || !Number.isFinite(Number(relative.x)) || !Number.isFinite(Number(relative.y)) || !Number.isFinite(Number(relative.z))) {
            return null;
        }

        return {
            x: Number(relative.x),
            y: Number(relative.y),
            z: Number(relative.z),
        };
    };

    const readSectorAlertAcknowledgements = () => {
        try {
            const stored = JSON.parse(localStorage.getItem(sectorAlertAcknowledgementsStorageKey) || '{}');
            return stored && typeof stored === 'object' && !Array.isArray(stored) ? stored : {};
        } catch (error) {
            return {};
        }
    };

    const writeSectorAlertAcknowledgements = (acknowledgements) => {
        try {
            localStorage.setItem(sectorAlertAcknowledgementsStorageKey, JSON.stringify(acknowledgements));
        } catch (error) {
            // Ignore storage errors; acknowledgement is a browser-side convenience only.
        }
    };

    const sectorAlertAcknowledgementKey = (type, sector, signature) => {
        const relative = sectorAlertRelativeCoordinates(sector);
        if (!relative || !signature) {
            return null;
        }

        return JSON.stringify([type, relative.x, relative.y, relative.z, signature]);
    };

    const isSectorAlertAcknowledged = (type, sector, signature) => {
        const key = sectorAlertAcknowledgementKey(type, sector, signature);
        return key !== null && Boolean(readSectorAlertAcknowledgements()[key]);
    };

    const acknowledgeSectorAlert = (type, sector, signature) => {
        const key = sectorAlertAcknowledgementKey(type, sector, signature);
        const relative = sectorAlertRelativeCoordinates(sector);
        if (key === null || relative === null) {
            return;
        }

        const acknowledgements = readSectorAlertAcknowledgements();
        acknowledgements[key] = {
            type,
            relativeCoordinates: relative,
            signature,
            acknowledgedAt: new Date().toISOString(),
        };
        writeSectorAlertAcknowledgements(acknowledgements);
    };

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

    const bookmarkedSectorObjects = (sector) => {
        const distance = Number(sector && sector.distance);
        if (!Number.isFinite(distance) || distance !== 0 || !Array.isArray(sector && sector.objects)) {
            return [];
        }

        const result = [];
        const seen = new Set();
        const collect = (object) => {
            if (!object || typeof object !== 'object') {
                return;
            }
            if (Array.isArray(object.waypointBookmarks) && object.waypointBookmarks.length > 0) {
                const label = [objectTypeLabel(object.type || 'object'), object.name || object.id].filter(Boolean).join(' ');
                const key = String(object.id || object.name || label);
                if (!seen.has(key)) {
                    seen.add(key);
                    result.push({key, label: label || key});
                }
            }
            ['bookmarkTargets', 'minableTargets'].forEach((childKey) => {
                if (Array.isArray(object[childKey])) {
                    object[childKey].forEach(collect);
                }
            });
        };
        sector.objects.forEach(collect);

        return result;
    };

    const sectorBookmarkAlert = (sector) => {
        const bookmarkedObjects = bookmarkedSectorObjects(sector);
        if (bookmarkedObjects.length === 0) {
            return null;
        }

        const message = formatText(t('sectorWaypointBookmarkAlert', 'Waypoint bookmark detected on object(s): {objects}'), {
            objects: bookmarkedObjects.map((object) => object.label).join(', '),
        });
        const signature = bookmarkedObjects.map((object) => object.key).sort().join('|');

        return {
            type: 'bookmark',
            className: 'sector-bookmark-alert',
            message,
            signature,
        };
    };

    const sectorProbeAlert = (sector) => {
        const probes = Array.isArray(sector && sector.probes) ? sector.probes : [];
        if (probes.length === 0) {
            return null;
        }

        const probeLabels = probes.map((probe) => formatText(t('sectorProbeAlertEntry', '{name} ({movement})'), {
            name: probe && probe.name ? probe.name : t('unknownProbe', 'Unknown probe'),
            movement: probe && probe.moving
                ? t('probeMovementActive', 'movement in progress')
                : t('probeMovementInactive', 'no movement in progress'),
        }));
        const message = formatText(t('sectorProbeAlert', 'Probe detected in sector: {probes}'), {
            probes: probeLabels.join(', '),
        });
        const signature = probes
            .map((probe) => [
                probe && probe.id ? probe.id : 'unknown',
                probe && probe.name ? probe.name : t('unknownProbe', 'Unknown probe'),
                probe && probe.moving ? 'moving' : 'idle',
            ].join(':'))
            .sort()
            .join('|');

        return {
            type: 'probe',
            className: 'sector-probe-alert',
            message,
            signature,
        };
    };

    const sectorAlerts = (sector) => [
        sectorBookmarkAlert(sector),
        sectorProbeAlert(sector),
    ].filter(Boolean).map((alert) => ({
        ...alert,
        acknowledged: isSectorAlertAcknowledged(alert.type, sector, alert.signature),
    }));

    const renderConsoleAlerts = (sector) => {
        const alerts = sectorAlerts(sector);
        onAlertsChanged(alerts);

        const list = document.getElementById('console-alerts-list');
        const empty = document.getElementById('console-alerts-empty');
        if (!list) {
            return;
        }

        if (empty) {
            empty.hidden = alerts.length > 0;
        }

        if (alerts.length === 0) {
            list.innerHTML = '';
            return;
        }

        list.innerHTML = alerts.map((alert, index) => (
            '<article class="sector-alert ' + escapeHtml(alert.className) + (alert.acknowledged ? ' acknowledged' : '') + '" data-alert-index="' + String(index) + '">'
                + '<span class="sector-alert-message">' + escapeHtml(alert.message) + '</span>'
                + '<button class="sector-alert-acknowledge" type="button"' + (alert.acknowledged ? ' disabled aria-disabled="true"' : ' aria-disabled="false"') + '>'
                + escapeHtml(alert.acknowledged ? t('acknowledgedAlert', 'Acknowledged') : t('acknowledgeAlert', 'Acknowledge'))
                + '</button>'
            + '</article>'
        )).join('');

        list.querySelectorAll('.sector-alert-acknowledge').forEach((button) => {
            button.addEventListener('click', () => {
                const alertNode = button.closest('.sector-alert');
                const alert = alerts[Number.parseInt(alertNode && alertNode.dataset.alertIndex || '-1', 10)];
                if (!alert) {
                    return;
                }

                acknowledgeSectorAlert(alert.type, sector, alert.signature);
                renderConsoleAlerts(sector);
            });
        });
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
            resourceAmounts: object.resourceAmounts || {},
        }] : [];
        const nested = Array.isArray(object.minableTargets)
            ? object.minableTargets.map((target) => ({
                id: target.id,
                type: target.type || 'object',
                name: target.name || target.id || '',
                resources: target.resources || [],
                resourceTypes: target.resourceTypes || [],
                resourceComposition: target.resourceComposition || {},
                resourceAmounts: target.resourceAmounts || {},
            }))
            : [];

        return direct.concat(nested).filter((target) => target.id);
    });

    const salvageTargetsFromObjects = (objects) => objects.filter((object) => (
        object && object.salvageable && object.id
    )).map((object) => ({
        id: object.id,
        type: object.type || 'object',
        name: object.name || object.id || '',
        mannyState: object.mannyState || null,
        itemType: object.itemType || null,
        quantity: object.quantity || null,
        containerSpace: object.containerSpace || null,
    }));

    const bookmarkTargetsFromObjects = (objects) => objects.flatMap((object) => {
        const direct = !['manny', 'drifting_item'].includes(object.type) ? [{
            id: object.id,
            type: object.type || 'object',
            name: object.name || object.id || '',
        }] : [];
        const nested = Array.isArray(object.bookmarkTargets)
            ? object.bookmarkTargets.map((target) => ({
                id: target.id,
                type: target.type || 'object',
                name: target.name || target.id || '',
            }))
            : [];

        return direct.concat(nested).filter((target) => target.id);
    });

    const systemBodyKey = (body) => String(body && (body.id || body.name || body.type) ? (body.id || body.name || body.type) : '');

    const solarSystemBodies = (system) => {
        const bodiesByKey = new Map();
        if (Array.isArray(system.bookmarkTargets)) {
            system.bookmarkTargets.forEach((body) => {
                const key = systemBodyKey(body);
                if (key) {
                    bodiesByKey.set(key, body);
                }
            });
        }
        if (Array.isArray(system.minableTargets)) {
            system.minableTargets.forEach((body) => {
                const key = systemBodyKey(body);
                if (key) {
                    bodiesByKey.set(key, {...(bodiesByKey.get(key) || {}), ...body});
                }
            });
        }

        return Array.from(bodiesByKey.values());
    };

    const hasResourceDetails = (body) => {
        if (Array.isArray(body.resourceTypes) && body.resourceTypes.length > 0) {
            return true;
        }
        if (Array.isArray(body.resources) && body.resources.length > 0) {
            return true;
        }
        const composition = body.resourceComposition && typeof body.resourceComposition === 'object'
            ? body.resourceComposition
            : null;

        return composition !== null && miningResourceTypes.some((type) => Number(composition[type]) > 0);
    };

    const systemBodyLabel = (body) => {
        const type = objectTypeLabel(body.type || 'object');
        const name = body.name || body.id || t('unknownObject', 'Unknown object');

        return type + ' - ' + name;
    };

    const systemBodyDetails = (body) => {
        const details = [];
        if (Number.isFinite(Number(body.mass))) {
            details.push({label: t('mass', 'Mass'), value: numberValue(body.mass)});
        }
        if (Number.isFinite(Number(body.radius))) {
            details.push({label: t('radius', 'Radius'), value: numberValue(body.radius)});
        }
        if (hasResourceDetails(body)) {
            const resourceTypes = resourceTypesForTarget(body);
            if (resourceTypes.length > 0) {
                details.push({label: t('resources', 'Resources'), value: resourceTypes.map(resourceTypeLabel).join(', ')});
            }
            details.push({label: t('composition', 'Composition'), value: resourceCompositionLabel(body)});
        }

        if (details.length === 0) {
            return '';
        }

        return '<dl class="sector-system-body-details">'
            + details.map((detail) => (
                '<div><dt>' + escapeHtml(detail.label) + '</dt><dd>' + escapeHtml(detail.value) + '</dd></div>'
            )).join('')
            + '</dl>';
    };

    const solarSystemDetails = (system, index) => {
        if (system.type !== 'solar_system') {
            return '';
        }

        const bodies = solarSystemBodies(system);
        if (bodies.length === 0) {
            return '';
        }

        const panelId = 'sector-system-bodies-' + String(index);
        const openLabel = formatText(t('showSolarSystemBodies', 'System bodies ({count})'), {count: bodies.length});
        return '<button class="sector-system-toggle" type="button" aria-expanded="false" aria-controls="' + escapeHtml(panelId) + '" data-open-label="' + escapeHtml(openLabel) + '" data-close-label="' + escapeHtml(t('hideSolarSystemBodies', 'Hide bodies')) + '">'
            + escapeHtml(openLabel)
            + '</button>'
            + '<div id="' + escapeHtml(panelId) + '" class="sector-system-body-panel" hidden>'
            + '<ul class="sector-system-body-list">'
            + bodies.map((body) => (
                '<li class="sector-system-body">'
                    + '<span class="sector-system-body-title">' + escapeHtml(systemBodyLabel(body)) + '</span>'
                    + systemBodyDetails(body)
                + '</li>'
            )).join('')
            + '</ul>'
            + '</div>';
    };

    const bindSolarSystemToggles = (root) => {
        root.querySelectorAll('.sector-system-toggle').forEach((button) => {
            button.addEventListener('click', () => {
                const panel = document.getElementById(button.getAttribute('aria-controls') || '');
                if (!panel) {
                    return;
                }

                const willOpen = button.getAttribute('aria-expanded') !== 'true';
                button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                button.textContent = willOpen
                    ? (button.dataset.closeLabel || t('hideSolarSystemBodies', 'Hide bodies'))
                    : (button.dataset.openLabel || t('showSolarSystemBodies', 'System bodies'));
                panel.hidden = !willOpen;
            });
        });
    };

    const renderSectorObjects = (sector, options = {}) => {
        const node = document.getElementById('sector-objects');
        if (!node) {
            return;
        }

        setText('sector-context', sectorContext(sector));
        setText('sector-summary', sectorSummary(sector));
        renderConsoleAlerts(sector);
        const objects = Array.isArray(sector && sector.objects) ? sector.objects : [];
        const distance = Number(sector && sector.distance);
        const syncMannyTargets = options.syncMannyTargets ?? (Boolean(sector) && Number.isFinite(distance) && distance === 0);
        if (syncMannyTargets) {
            state.currentMannyMineTargets = mineTargetsFromObjects(objects);
            state.currentMannySalvageTargets = salvageTargetsFromObjects(objects);
            state.currentSectorObjects = objects;
        }
        node.innerHTML = objects.map((object, index) => {
            const danger = object.dangerLevel || 'unknown';
            const classes = ['sector-object', danger === 'extreme' ? 'sector-object-warning' : ''].filter(Boolean).join(' ');
            const countdown = object.noReturnCountdown && Number.isFinite(Number(object.noReturnCountdown.secondsRemaining))
                ? '<p class="sector-object-countdown">'
                    + escapeHtml(formatText(t('blackHoleNoReturnCountdown', 'Point of no return in {duration}'), {
                        duration: sectorDuration(Number(object.noReturnCountdown.secondsRemaining)),
                    }))
                    + '</p>'
                : '';
            const mannyDetail = object.type === 'manny'
                ? '<p>' + escapeHtml(t('mannyState', 'State') + ' ' + mannyStateLabel(object.mannyState)) + '</p>'
                : '';
            const driftingItemDetail = object.type === 'drifting_item'
                ? '<p>' + escapeHtml(t('quantity', 'Quantity') + ' ' + String(object.quantity || 0)) + '</p>'
                : '';
            return '<article class="' + classes + '">'
                + '<div class="sector-object-heading"><span>' + escapeHtml(objectTypeLabel(object.type || 'unknown')) + '</span><b>' + escapeHtml(dangerLevelLabel(danger)) + '</b></div>'
                + '<p>' + escapeHtml(observationSummaryLabel(object.summary || '')) + '</p>'
                + mannyDetail
                + driftingItemDetail
                + solarSystemDetails(object, index)
                + countdown
                + '</article>';
        }).join('');
        bindSolarSystemToggles(node);
        if (syncMannyTargets) {
            onTargetsChanged();
        }
    };

    return {
        bookmarkTargetsFromObjects,
        mineTargetLabel,
        miningTargetDetails,
        renderSectorObjects,
        resourceCompositionForTarget,
        resourceTypesForTarget,
        salvageTargetLabel,
        syncSectorForm,
    };
};
