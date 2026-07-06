function createElem(type, attributes) {
    var elem = document.createElement(type);
    for (var i in attributes || {}) {
        elem.setAttribute(i, attributes[i]);
    }
    return elem;
}

function cookieValue(name) {
    return document.cookie
        .split("; ")
        .find((row) => row.startsWith(name + "="))
        ?.split("=")
        .slice(1)
        .join("=") || "";
}

function safeDecode(value) {
    try {
        return decodeURIComponent(value || "");
    } catch (error) {
        return value || "";
    }
}

function labels() {
    const isFrench = document.documentElement.lang === "fr";
    return {
        account: isFrench ? "Compte" : "Account",
        apiKeyCopyFallback: isFrench ? "Sélectionne la clef et copie-la manuellement." : "Select the key and copy it manually.",
        apiKeyCopied: isFrench ? "Clef API copiée." : "API key copied.",
        apiKeyGenerating: isFrench ? "Génération de la clef API..." : "Generating API key...",
        apiKeyHelp: isFrench
            ? "Cette clef peut être utilisée comme Bearer token sur les endpoints API. Elle est affichée une seule fois."
            : "This key can be used as a Bearer token on API endpoints. It is shown only once.",
        apiKeyReady: isFrench ? "Clef API prête. Elle est affichée une seule fois." : "API key ready. It is shown only once.",
        apiKeyTitle: isFrench ? "Clef d'API" : "API key",
        close: isFrench ? "Fermer" : "Close",
        copyApiKey: isFrench ? "Copier la clef" : "Copy key",
        logout: isFrench ? "Déconnexion" : "Log out",
        requestDenied: isFrench ? "Requête refusée" : "Request denied",
        retrieveApiKey: isFrench ? "Récupérer une clef d'API" : "Retrieve an API key",
    };
}

function sessionToken() {
    return safeDecode(cookieValue("vn_session"));
}

function bindOAuthRememberChoice() {
    const checkbox = document.getElementById("oauth-remember");
    const links = document.querySelectorAll("[data-oauth-url]");
    if (!checkbox || links.length === 0) {
        return;
    }

    const syncLinks = () => {
        links.forEach((link) => {
            const baseUrl = link.getAttribute("data-oauth-url") || link.getAttribute("href") || "";
            if (!baseUrl) {
                return;
            }

            const url = new URL(baseUrl, window.location.origin);
            if (checkbox.checked) {
                url.searchParams.set("remember", "1");
            } else {
                url.searchParams.delete("remember");
            }

            link.setAttribute("href", url.pathname + url.search + url.hash);
        });
    };

    checkbox.addEventListener("change", syncLinks);
    syncLinks();
}

async function apiJson(path, options) {
    const token = sessionToken();
    const response = await fetch(path, {
        ...options,
        headers: {
            "Authorization": token ? "Bearer " + token : "",
            "Content-Type": "application/json",
            ...(options && options.headers ? options.headers : {}),
        },
    });
    const text = await response.text();
    const data = text ? JSON.parse(text) : null;
    if (!response.ok) {
        const message = data && data.error && data.error.message
            ? data.error.message
            : labels().requestDenied;
        const error = new Error(message);
        if (data && data.error) {
            error.errorCode = data.error.code || "";
            error.details = data.error.details || {};
            error.responseBody = data;
        }
        throw error;
    }

    return data;
}

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll("\"", "&quot;")
        .replaceAll("'", "&#039;");
}

let i18nPromise = null;
let navigationWarningTimer = null;
const sectorAlertAcknowledgementsStorageKey = "vng:sector-alert-acknowledgements:v1";
let probeListPromise = null;

function loadI18n() {
    if (window.VNG_I18N && typeof window.VNG_I18N === "object") {
        return Promise.resolve(window.VNG_I18N);
    }
    if (i18nPromise) {
        return i18nPromise;
    }

    const preload = document.querySelector("link[rel=\"preload\"][as=\"fetch\"]");
    const url = preload?.getAttribute("href") || "/i18n?lang=" + encodeURIComponent(document.documentElement.lang || "en");
    i18nPromise = fetch(url, {"headers": {"Accept": "application/json"}})
        .then((response) => response.ok ? response.json() : {})
        .then((messages) => {
            window.VNG_I18N = messages && typeof messages === "object" ? messages : {};
            return window.VNG_I18N;
        })
        .catch(() => ({}));

    return i18nPromise;
}

function t(messages, key, fallback) {
    return messages && Object.prototype.hasOwnProperty.call(messages, key)
        ? messages[key]
        : fallback;
}

function probeSelectorUnreachableLabel() {
    const messages = window.VNG_I18N && typeof window.VNG_I18N === "object" ? window.VNG_I18N : {};
    const fallback = document.documentElement.lang === "fr" ? "inaccessible" : "unreachable";

    return t(messages, "probeSelectorUnreachable", fallback);
}

function formatText(template, values) {
    return Object.entries(values || {}).reduce(
        (text, [key, value]) => text.replaceAll("{" + key + "}", String(value)),
        String(template || "")
    );
}

function validRelativeCoordinates(target) {
    return target
        && Number.isFinite(Number(target.x))
        && Number.isFinite(Number(target.y))
        && Number.isFinite(Number(target.z))
        && (Number(target.x) + Number(target.y) + Number(target.z)) % 2 === 0;
}

function coordinate(value) {
    return value ? value.x + ":" + value.y + ":" + value.z : "-";
}

function numberValue(value, suffix) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return "-";
    }

    return number.toFixed(2).replace(/\.?0+$/, "") + (suffix || "");
}

function duration(seconds, translate) {
    const number = Number(seconds);
    const text = typeof translate === "function" ? translate : ((key, fallback) => fallback);
    if (!Number.isFinite(number) || number < 0) {
        return "-";
    }
    if (number < 60) {
        return Math.round(number) + " " + text("secondsShort", "s");
    }

    const hours = Math.floor(number / 3600);
    const minutes = Math.floor((number % 3600) / 60);
    const remainingSeconds = Math.round(number % 60);
    return [
        hours > 0 ? hours + " h" : "",
        minutes > 0 ? minutes + " min" : "",
        hours === 0 ? remainingSeconds + " " + text("secondsShort", "s") : "",
    ].filter(Boolean).join(" ");
}

function detailList(items) {
    return "<dl>" + items.map((item) => (
        "<div><dt>" + escapeHtml(item.label) + "</dt><dd>"
        + (Object.prototype.hasOwnProperty.call(item, "htmlValue") ? String(item.htmlValue ?? "") : escapeHtml(item.value))
        + "</dd></div>"
    )).join("") + "</dl>";
}

function metricHtml(metric) {
    const valueClass = metric.valueClass ? " class=\"" + escapeHtml(metric.valueClass) + "\"" : "";
    const valueId = metric.valueId ? " id=\"" + escapeHtml(metric.valueId) + "\"" : "";
    const extraAttributes = metric.valueAttributes ? " " + metric.valueAttributes : "";
    const metricName = metric.name ? " data-metric=\"" + escapeHtml(metric.name) + "\"" : "";
    const content = "<span>" + escapeHtml(metric.label) + "</span><b" + valueId + valueClass + extraAttributes + ">"
        + escapeHtml(String(metric.value ?? "-")) + "</b>";

    if (!metric.detail) {
        return "<div class=\"metric\"" + metricName + ">" + content + "</div>";
    }

    return "<button class=\"metric interactive-metric\" type=\"button\" aria-expanded=\"false\"" + metricName + ">"
        + content
        + "<span class=\"metric-detail-hidden\" role=\"status\">" + metric.detail + "</span>"
        + "</button>";
}

function setMetricDetailExpanded(metricNode, expanded) {
    const detailNode = metricNode.querySelector(".metric-detail, .metric-detail-hidden");
    if (!detailNode) {
        return;
    }

    detailNode.classList.toggle("metric-detail", expanded);
    detailNode.classList.toggle("metric-detail-hidden", !expanded);
    metricNode.setAttribute("aria-expanded", expanded ? "true" : "false");
}

function bindMetricDetails(root) {
    (root || document).querySelectorAll(".interactive-metric").forEach((metricNode) => {
        if (metricNode.dataset.metricDetailsBound === "1") {
            return;
        }
        metricNode.dataset.metricDetailsBound = "1";
        metricNode.addEventListener("click", () => {
            const expanded = metricNode.getAttribute("aria-expanded") === "true";
            document.querySelectorAll(".interactive-metric[aria-expanded=\"true\"]").forEach((openNode) => {
                if (openNode !== metricNode) {
                    setMetricDetailExpanded(openNode, false);
                }
            });
            setMetricDetailExpanded(metricNode, !expanded);
        });
    });
}

function renderMetrics(container, metrics) {
    if (!container) {
        return;
    }

    container.innerHTML = metrics.map(metricHtml).join("");
    bindMetricDetails(container);
}

function openDisclosureIds(root, selector) {
    return new Set(Array.from((root || document).querySelectorAll(selector || "[aria-expanded=\"true\"][aria-controls]"))
        .filter((node) => node.getAttribute("aria-expanded") === "true")
        .map((node) => node.getAttribute("aria-controls") || "")
        .filter(Boolean));
}

function restoreDisclosureIds(root, openIds, selector) {
    if (!openIds || openIds.size === 0) {
        return;
    }

    Array.from((root || document).querySelectorAll(selector || "[aria-controls]")).forEach((node) => {
        const targetId = node.getAttribute("aria-controls") || "";
        if (!openIds.has(targetId)) {
            return;
        }

        node.setAttribute("aria-expanded", "true");
        const panel = document.getElementById(targetId);
        if (panel) {
            panel.hidden = false;
        }
    });
}

function collectRefreshTimes(value, observedAt, times) {
    if (!value || typeof value !== "object") {
        return times;
    }

    Object.entries(value).forEach(([key, item]) => {
        const normalizedKey = key.toLowerCase();
        if (typeof item === "number" && (
            normalizedKey.endsWith("secondsremaining")
            || normalizedKey.endsWith("remainingseconds")
            || normalizedKey === "refreshafterseconds"
            || normalizedKey === "nextrefreshseconds"
        )) {
            times.push(observedAt + Math.max(0, item) * 1000);
            return;
        }

        if (typeof item === "string" && (
            normalizedKey.endsWith("endsat")
            || normalizedKey.endsWith("endat")
            || normalizedKey.endsWith("dueat")
            || normalizedKey.endsWith("runat")
            || normalizedKey.endsWith("arrivalat")
            || normalizedKey === "estimatedcompletionat"
            || normalizedKey === "taskestimatedendtime"
        )) {
            const timestamp = Date.parse(item);
            if (Number.isFinite(timestamp)) {
                times.push(timestamp);
            }
        }

        if (item && typeof item === "object") {
            collectRefreshTimes(item, observedAt, times);
        }
    });

    return times;
}

function nextRefreshDelay(payload, defaultDelayMs, minimumDelayMs, cushionMs) {
    const observedAt = Date.now();
    const defaultDelay = Number.isFinite(Number(defaultDelayMs)) ? Number(defaultDelayMs) : 15000;
    const minimumDelay = Number.isFinite(Number(minimumDelayMs)) ? Number(minimumDelayMs) : 500;
    const cushion = Number.isFinite(Number(cushionMs)) ? Number(cushionMs) : 500;
    const futureTimes = collectRefreshTimes(payload, observedAt, [])
        .filter((timestamp) => Number.isFinite(timestamp) && timestamp >= observedAt)
        .sort((a, b) => a - b);

    if (futureTimes.length === 0) {
        return defaultDelay;
    }

    return Math.max(minimumDelay, Math.min(defaultDelay, futureTimes[0] - observedAt + cushion));
}

function navLinkNodes(path) {
    return Array.from(document.querySelectorAll(".nav-panel a.panel-tab")).filter((node) => {
        const href = node.getAttribute("href") || "";
        const navLink = node.dataset.navLink || "";
        return href === path || navLink === path;
    });
}

function selectedProbeId() {
    const value = document.body.dataset.selectedProbeId || "";
    return /^\d+$/.test(value) ? value : "";
}

function probeApiPath(suffix) {
    const normalizedSuffix = suffix ? (String(suffix).startsWith("/") ? String(suffix) : "/" + String(suffix)) : "";
    const probeId = selectedProbeId();

    return probeId
        ? "/api/probe/" + encodeURIComponent(probeId) + normalizedSuffix
        : "/api/probe" + normalizedSuffix;
}

function loadProbeList() {
    if (probeListPromise) {
        return probeListPromise;
    }

    probeListPromise = apiJson("/api/probes", {"method": "GET"}).catch(() => ({"defaultProbeId": null, "probes": []}));

    return probeListPromise;
}

function resetProbeListCache() {
    probeListPromise = null;
}

function renderProbeSelector(select, data) {
    const probes = Array.isArray(data && data.probes) ? data.probes : [];
    const defaultProbeId = data && data.defaultProbeId ? String(data.defaultProbeId) : "";
    const currentProbeId = selectedProbeId() || defaultProbeId;

    syncProbeAwareNavigation(defaultProbeId);
    select.dataset.defaultProbeId = defaultProbeId;
    select.innerHTML = probes.map((probe) => {
        const id = String(probe && probe.id ? probe.id : "");
        const name = probe && probe.name ? probe.name : ("Probe #" + id);
        const suffix = String(id) === defaultProbeId ? " *" : "";
        const reachabilitySuffix = probe && probe.isReachable === false
            ? " (" + probeSelectorUnreachableLabel() + ")"
            : "";

        return "<option value=\"" + escapeHtml(id) + "\"" + (id === currentProbeId ? " selected" : "") + ">"
            + escapeHtml(name + suffix + reachabilitySuffix)
            + "</option>";
    }).join("");
}

async function refreshProbeSelector(data) {
    if (document.body.dataset.authenticated !== "1") {
        return data || {"defaultProbeId": null, "probes": []};
    }

    if (data && Array.isArray(data.probes)) {
        probeListPromise = Promise.resolve(data);
    } else {
        resetProbeListCache();
        data = await loadProbeList();
    }

    const select = document.getElementById("nav-probe-select");
    if (select) {
        renderProbeSelector(select, data);
    }

    return data;
}

function routeHrefForProbe(baseHref, probeId) {
    if (!probeId) {
        return routeBaseHref(baseHref);
    }
    const routeBase = routeBaseHref(baseHref);
    if (routeBase === "/") {
        return "/" + encodeURIComponent(String(probeId));
    }

    return routeBase.replace(/\/$/, "") + "/" + encodeURIComponent(String(probeId));
}

function routeBaseHref(href) {
    const normalized = (String(href || "/").replace(/\/$/, "") || "/");
    const probeAwareRoute = normalized.match(/^\/(sensors|inventories|mannies|movement|scut|messaging|alerts)(?:\/\d+)?$/);
    if (probeAwareRoute) {
        return "/" + probeAwareRoute[1];
    }
    if (/^\/\d+$/.test(normalized)) {
        return "/";
    }

    return normalized;
}

function currentRouteParts() {
    const path = window.location.pathname || "/";
    const movementWithProbeAndTarget = path.match(/^\/movement\/(\d+)\/(-?\d+)\/(-?\d+)\/(-?\d+)$/);
    if (movementWithProbeAndTarget) {
        return {
            "baseHref": "/movement",
            "coordinates": movementWithProbeAndTarget.slice(2, 5),
        };
    }
    const movementTargetOnly = path.match(/^\/movement\/(-?\d+)\/(-?\d+)\/(-?\d+)$/);
    if (movementTargetOnly) {
        return {
            "baseHref": "/movement",
            "coordinates": movementTargetOnly.slice(1, 4),
        };
    }
    if (/^\/movement\/\d+$/.test(path)) {
        return {
            "baseHref": "/movement",
            "coordinates": [],
        };
    }
    const withProbe = path.match(/^\/(sensors|inventories|mannies|scut|messaging|alerts)\/\d+$/);
    if (withProbe) {
        return {
            "baseHref": "/" + withProbe[1],
            "coordinates": [],
        };
    }
    if (/^\/\d+$/.test(path)) {
        return {
            "baseHref": "/",
            "coordinates": [],
        };
    }

    return {
        "baseHref": path || "/",
        "coordinates": [],
    };
}

function pageHrefForProbe(baseHref, probeId, coordinates) {
    const basePath = probeId ? routeHrefForProbe(baseHref, probeId) : (baseHref || "/");
    const suffix = Array.isArray(coordinates) && coordinates.length === 3
        ? "/" + coordinates.map((part) => encodeURIComponent(String(part))).join("/")
        : "";

    return (basePath.replace(/\/$/, "") + suffix) || "/";
}

function syncProbeAwareNavigation(defaultProbeId) {
    const selectedId = selectedProbeId();
    const nonDefaultProbeId = selectedId && String(selectedId) !== String(defaultProbeId || "") ? selectedId : "";

    document.querySelectorAll(".nav-panel a.panel-tab").forEach((node) => {
        const baseHref = routeBaseHref(node.dataset.navLink || node.getAttribute("href") || "/");
        node.dataset.navLink = baseHref;
        node.setAttribute("href", nonDefaultProbeId ? routeHrefForProbe(baseHref, nonDefaultProbeId) : baseHref);
    });
}

function probeUnreachableText(messages) {
    return t(
        messages,
        "probeOutOfScutRangeExplanation",
        "This probe is unreachable. It is too far away and outside the area covered by SCUT. Only its estimated coordinates are available."
    );
}

function setProbeUnreachablePanel(panelId, active) {
    const panel = panelId ? document.getElementById(panelId) : null;
    if (!panel) {
        return;
    }

    panel.classList.toggle("probe-unreachable-panel", Boolean(active));
    if (!active) {
        panel.querySelectorAll("[data-unreachable-status=\"1\"]").forEach((node) => {
            if (node.dataset.unreachableAddedSectorContext === "1") {
                node.classList.remove("sector-context");
            }
            delete node.dataset.unreachableStatus;
            delete node.dataset.unreachableAddedSectorContext;
        });
    }
}

async function renderUnreachableProbeTelemetry(error, options) {
    if (!error || error.errorCode !== "probe_not_in_same_sector" || !selectedProbeId()) {
        return false;
    }

    const messages = await loadI18n();
    const data = await apiJson(probeApiPath(""), {"method": "GET"}).catch(() => null);
    const probe = data && data.probe ? data.probe : null;
    if (!probe || probe.status !== "out_of_scut_range") {
        return false;
    }

    const statusNode = options && options.statusId ? document.getElementById(options.statusId) : null;
    if (statusNode) {
        statusNode.textContent = probeUnreachableText(messages);
        statusNode.hidden = false;
        if (!statusNode.classList.contains("sector-context")) {
            statusNode.dataset.unreachableAddedSectorContext = "1";
        }
        statusNode.classList.add("sector-context");
        statusNode.dataset.unreachableStatus = "1";
    }
    if (options && options.panelId) {
        setProbeUnreachablePanel(options.panelId, true);
    }
    const metricsNode = options && options.metricsId ? document.getElementById(options.metricsId) : null;
    if (metricsNode) {
        renderMetrics(metricsNode, [
            {
                "label": t(messages, "status", "Status"),
                "value": t(messages, "probeOutOfScutRangeStatus", "Outside SCUT"),
            },
            {
                "label": t(messages, "sector", "Sector"),
                "value": coordinate(probe.sector && probe.sector.relative),
            },
        ]);
    }

    return true;
}

async function bindProbeSelector() {
    if (document.body.dataset.authenticated !== "1") {
        return;
    }

    const select = document.getElementById("nav-probe-select");
    if (!select || select.dataset.probeSelectorBound === "1") {
        return;
    }
    select.dataset.probeSelectorBound = "1";

    const data = await loadProbeList();
    renderProbeSelector(select, data);

    select.addEventListener("change", () => {
        const nextProbeId = select.value || "";
        const defaultProbeId = select.dataset.defaultProbeId || "";
        const route = currentRouteParts();
        const explicitProbeId = nextProbeId && String(nextProbeId) !== String(defaultProbeId) ? nextProbeId : "";

        window.location.assign(pageHrefForProbe(route.baseHref, explicitProbeId, route.coordinates));
    });
}

function setNavigationWarning(path, active, warning) {
    navLinkNodes(path).forEach((node) => {
        node.classList.toggle("alerts-pending", Boolean(active));
        node.classList.toggle("alerts-warning", Boolean(warning));
    });
}

function setNavigationScutCoverage(active) {
    navLinkNodes("/scut").forEach((node) => {
        node.classList.toggle("scut-network-available", Boolean(active));
    });
}

function sectorAlertStorageKey(type, sector, signature) {
    const relative = sector && sector.relativeCoordinates ? sector.relativeCoordinates : null;
    return [
        type,
        relative ? coordinate(relative) : "unknown",
        signature || "",
    ].join("|");
}

function readSectorAlertAcknowledgements() {
    try {
        const value = window.localStorage.getItem(sectorAlertAcknowledgementsStorageKey);
        const parsed = value ? JSON.parse(value) : [];
        return new Set(Array.isArray(parsed) ? parsed : []);
    } catch (error) {
        return new Set();
    }
}

function writeSectorAlertAcknowledgements(items) {
    try {
        window.localStorage.setItem(sectorAlertAcknowledgementsStorageKey, JSON.stringify(Array.from(items)));
    } catch (error) {
        // Local storage can be unavailable in private contexts; alerts still render.
    }
}

function isSectorAlertAcknowledged(type, sector, signature) {
    return readSectorAlertAcknowledgements().has(sectorAlertStorageKey(type, sector, signature));
}

function acknowledgeSectorAlert(type, sector, signature) {
    const acknowledgements = readSectorAlertAcknowledgements();
    acknowledgements.add(sectorAlertStorageKey(type, sector, signature));
    writeSectorAlertAcknowledgements(acknowledgements);
}

function alertObjectTypeLabel(messages, type) {
    return {
        "asteroid": t(messages, "asteroidObject", "Asteroid"),
        "planet": t(messages, "planetObject", "Planet"),
        "star": t(messages, "starObject", "Star"),
        "solar_system": t(messages, "solarSystemObject", "Solar system"),
        "black_hole": t(messages, "blackHoleObject", "Black hole"),
        "dust_cloud": t(messages, "dustCloudObject", "Dust cloud"),
        "object": t(messages, "object", "Object"),
    }[type] || type || t(messages, "object", "Object");
}

function bookmarkedSectorObjects(sector, messages) {
    if (!sector || !Array.isArray(sector.objects)) {
        return [];
    }

    const result = [];
    const seen = new Set();
    const collect = (object) => {
        if (!object || typeof object !== "object") {
            return;
        }
        if (Array.isArray(object.waypointBookmarks) && object.waypointBookmarks.length > 0) {
            const label = [alertObjectTypeLabel(messages, object.type || "object"), object.name || object.id].filter(Boolean).join(" ");
            const key = String(object.id || object.name || label);
            if (!seen.has(key)) {
                seen.add(key);
                result.push({
                    key,
                    "label": label || key,
                    "bookmarks": object.waypointBookmarks,
                });
            }
        }
        ["bookmarkTargets", "minableTargets"].forEach((childKey) => {
            if (Array.isArray(object[childKey])) {
                object[childKey].forEach(collect);
            }
        });
    };
    sector.objects.forEach(collect);

    return result;
}

function sectorAlerts(sector, messages) {
    const alerts = [];
    const bookmarkedObjects = bookmarkedSectorObjects(sector, messages);
    if (bookmarkedObjects.length > 0) {
        const signature = bookmarkedObjects.map((object) => object.key).sort().join("|");
        alerts.push({
            "type": "bookmark",
            "className": "sector-bookmark-alert",
            "message": formatText(t(messages, "sectorWaypointBookmarkAlert", "Waypoint bookmark detected on object(s): {objects}"), {
                "objects": bookmarkedObjects.map((object) => object.label).join(", "),
            }),
            signature,
        });
    }

    const probes = Array.isArray(sector && sector.probes) ? sector.probes : [];
    if (probes.length > 0) {
        const probeLabels = probes.map((probe) => formatText(t(messages, "sectorProbeAlertEntry", "{name} ({movement})"), {
            "name": probe && probe.name ? probe.name : t(messages, "unknownProbe", "Unknown probe"),
            "movement": probe && probe.moving
                ? t(messages, "probeMovementActive", "movement in progress")
                : t(messages, "probeMovementInactive", "no movement in progress"),
        }));
        const signature = probes.map((probe) => [
            probe && probe.id ? probe.id : "unknown",
            probe && probe.name ? probe.name : t(messages, "unknownProbe", "Unknown probe"),
            probe && probe.moving ? "moving" : "idle",
        ].join(":")).sort().join("|");
        alerts.push({
            "type": "probe",
            "className": "sector-probe-alert",
            "message": formatText(t(messages, "sectorProbeAlert", "Probe detected in sector: {probes}"), {
                "probes": probeLabels.join(", "),
            }),
            signature,
        });
    }

    const containers = (Array.isArray(sector && sector.objects) ? sector.objects : [])
        .filter((object) => object && object.type === "detached_container" && object.id);
    if (containers.length > 0) {
        const signature = containers.map((container) => [container.id, container.name || "", container.mode || ""].join(":")).sort().join("|");
        alerts.push({
            "type": "detached_container",
            "className": "sector-detached-container-alert",
            "message": formatText(t(messages, "sectorDetachedContainerAlert", "Detached container detected in sector: {containers}"), {
                "containers": containers.map((container) => container.name || container.id).join(", "),
            }),
            signature,
        });
    }

    return alerts.map((alert) => ({
        ...alert,
        "acknowledged": isSectorAlertAcknowledged(alert.type, sector, alert.signature),
    }));
}

async function syncNavigationWarnings() {
    if (document.body.dataset.authenticated !== "1") {
        return;
    }

    const messages = await loadI18n();
    const [messageData, sectorData, alertData] = await Promise.all([
        apiJson(probeApiPath("/messages") + "?limit=50&offset=0", {"method": "GET"}).catch(() => null),
        apiJson(probeApiPath("/sector"), {"method": "GET"}).catch(() => null),
        apiJson(probeApiPath("/alerts"), {"method": "GET"}).catch(() => null),
    ]);

    const persistentAlerts = Array.isArray(alertData && alertData.alerts) ? alertData.alerts : [];
    const unreadPersistentAlerts = persistentAlerts.some((alert) => alert && alert.status === "unread");
    const unreadDamageWarnings = persistentAlerts.some((alert) => (
        alert && alert.type === "storage_container_break" && alert.status === "unread"
    ));
    if (messageData) {
        const receivedMessages = Array.isArray(messageData.messages) ? messageData.messages : [];
        setNavigationWarning("/messaging", receivedMessages.some((message) => message && message.status === "unread"));
    }
    if (sectorData) {
        const alerts = sectorAlerts(sectorData.sector || {}, messages);
        setNavigationWarning("/alerts", alerts.some((alert) => !alert.acknowledged) || unreadPersistentAlerts, unreadDamageWarnings);
        setNavigationScutCoverage(Array.isArray(sectorData.sector && sectorData.sector.scutNetworks) && sectorData.sector.scutNetworks.length > 0);
    } else {
        setNavigationWarning("/alerts", unreadPersistentAlerts, unreadDamageWarnings);
        setNavigationScutCoverage(false);
    }
}

function startNavigationWarningSync() {
    if (document.body.dataset.authenticated !== "1" || navigationWarningTimer !== null) {
        return;
    }

    syncNavigationWarnings();
    navigationWarningTimer = window.setInterval(syncNavigationWarnings, 15000);
}

window.VNG = {
    ...(window.VNG || {}),
    acknowledgeSectorAlert,
    apiJson,
    bindMetricDetails,
    coordinate,
    detailList,
    duration,
    escapeHtml,
    formatText,
    labels,
    loadI18n,
    loadProbeList,
    metricHtml,
    nextRefreshDelay,
    numberValue,
    openDisclosureIds,
    probeSelectorUnreachableLabel,
    probeApiPath,
    refreshProbeSelector,
    renderUnreachableProbeTelemetry,
    renderMetrics,
    resetProbeListCache,
    restoreDisclosureIds,
    sectorAlerts,
    selectedProbeId,
    setProbeUnreachablePanel,
    setNavigationScutCoverage,
    setNavigationWarning,
    startNavigationWarningSync,
    syncNavigationWarnings,
    t,
    validRelativeCoordinates,
};
window.dispatchEvent(new CustomEvent("VNGReady"));

function showDialog(dialog) {
    if (!dialog) {
        return;
    }
    dialog.hidden = false;
    if (typeof dialog.showModal === "function" && !dialog.open) {
        dialog.showModal();
    }
}

function closeDialog(dialog) {
    if (!dialog) {
        return;
    }
    if (typeof dialog.close === "function" && dialog.open) {
        dialog.close();
    }
    dialog.hidden = true;
}

function bindTutorialDialog(closeAccountMenus) {
    const previewDialog = document.getElementById("tutorial-image-preview-dialog");
    const previewImage = document.getElementById("tutorial-image-preview");
    const closePreview = () => {
        if (!previewDialog) {
            return;
        }
        closeDialog(previewDialog);
        previewImage?.removeAttribute("src");
    };

    document.querySelectorAll("[data-tutorial-image-preview]").forEach((button) => {
        if (button.dataset.tutorialImagePreviewBound === "1") {
            return;
        }
        button.dataset.tutorialImagePreviewBound = "1";
        button.addEventListener("click", () => {
            if (!previewDialog || !previewImage) {
                return;
            }
            const image = button.querySelector("img");
            previewImage.src = button.dataset.tutorialImagePreview || image?.src || "";
            previewImage.alt = image?.alt || "";
            showDialog(previewDialog);
        });
    });

    const previewClose = previewDialog?.querySelector("[data-tutorial-image-preview-close]");
    if (previewClose && previewClose.dataset.tutorialPreviewCloseBound !== "1") {
        previewClose.dataset.tutorialPreviewCloseBound = "1";
        previewClose.addEventListener("click", closePreview);
    }
    if (previewDialog && previewDialog.dataset.tutorialPreviewDialogBound !== "1") {
        previewDialog.dataset.tutorialPreviewDialogBound = "1";
        previewDialog.addEventListener("click", (event) => {
            if (event.target === previewDialog) {
                closePreview();
            }
        });
        previewDialog.addEventListener("close", () => {
            previewDialog.hidden = true;
        });
    }

    const tutorialControllers = new Map();

    document.querySelectorAll(".tutorial-dialog").forEach((dialog) => {
        const steps = dialog ? Array.from(dialog.querySelectorAll("[data-tutorial-step]")) : [];
        const progress = dialog?.querySelector("[data-tutorial-progress]");
        const nextButton = dialog?.querySelector("[data-tutorial-next]");
        const closeButton = dialog?.querySelector("[data-tutorial-close-final]");
        let currentStep = 0;

        if (!dialog || steps.length === 0 || !nextButton || !closeButton) {
            return;
        }

        const renderStep = () => {
            steps.forEach((step, index) => {
                step.hidden = index !== currentStep;
            });
            if (progress) {
                progress.textContent = String(currentStep + 1) + " / " + String(steps.length);
            }
            nextButton.hidden = currentStep >= steps.length - 1;
            closeButton.hidden = currentStep < steps.length - 1;
        };

        const closeTutorial = () => closeDialog(dialog);
        const openTutorial = () => {
            currentStep = 0;
            renderStep();
            showDialog(dialog);
        };

        if (nextButton.dataset.tutorialNextBound !== "1") {
            nextButton.dataset.tutorialNextBound = "1";
            nextButton.addEventListener("click", () => {
                if (currentStep < steps.length - 1) {
                    currentStep += 1;
                    renderStep();
                }
            });
        }

        dialog.querySelectorAll("[data-tutorial-close]").forEach((button) => {
            if (button.dataset.tutorialCloseBound === "1") {
                return;
            }
            button.dataset.tutorialCloseBound = "1";
            button.addEventListener("click", closeTutorial);
        });

        if (dialog.dataset.tutorialDialogBound !== "1") {
            dialog.dataset.tutorialDialogBound = "1";
            dialog.addEventListener("close", () => {
                dialog.hidden = true;
            });
        }

        tutorialControllers.set(dialog.id, {
            "close": closeTutorial,
            "open": openTutorial,
        });
    });

    const openTutorialById = (targetId) => {
        const controller = tutorialControllers.get(targetId);
        if (!controller) {
            return false;
        }

        closeAccountMenus?.();
        tutorialControllers.forEach((candidate) => {
            if (candidate !== controller) {
                candidate.close();
            }
        });
        controller.open();
        return true;
    };

    document.querySelectorAll("[data-tutorial-target]").forEach((trigger) => {
        if (trigger.dataset.tutorialTargetBound === "1") {
            return;
        }
        trigger.dataset.tutorialTargetBound = "1";
        trigger.addEventListener("click", (event) => {
            if (openTutorialById(trigger.dataset.tutorialTarget || "")) {
                event.preventDefault();
            }
        });
    });

    const tutorialAliases = {
        "context": "tutorial-context-dialog",
        "contexte": "tutorial-context-dialog",
        "move": "tutorial-move-dialog",
        "deplacement": "tutorial-move-dialog",
        "mannies": "tutorial-mannies-dialog",
        "automate": "tutorial-automate-dialog",
        "automatiser": "tutorial-automate-dialog",
    };
    const params = new URLSearchParams(window.location.search);
    const requestedTutorial = params.get("tutorial") || "";
    if (requestedTutorial !== "" && document.body.dataset.tutorialQueryHandled !== "1") {
        document.body.dataset.tutorialQueryHandled = "1";
        const targetId = tutorialAliases[requestedTutorial] || requestedTutorial;
        if (openTutorialById(targetId) && window.history?.replaceState) {
            params.delete("tutorial");
            const nextSearch = params.toString();
            window.history.replaceState(
                {},
                "",
                window.location.pathname + (nextSearch ? "?" + nextSearch : "") + window.location.hash
            );
        }
    }
}

function bindAccountMenus() {
    const closeAccountMenus = () => {
        document.querySelectorAll(".account-menu-button[aria-expanded=\"true\"]").forEach((button) => {
            button.setAttribute("aria-expanded", "false");
            const panel = button.closest(".account-menu")?.querySelector(".account-menu-panel");
            if (panel) {
                panel.hidden = true;
            }
        });
    };

    document.querySelectorAll(".account-menu-button").forEach((button) => {
        if (button.dataset.accountMenuBound === "1") {
            return;
        }
        button.dataset.accountMenuBound = "1";
        button.addEventListener("click", (event) => {
            event.stopPropagation();
            const panel = button.closest(".account-menu")?.querySelector(".account-menu-panel");
            const willOpen = button.getAttribute("aria-expanded") !== "true";
            closeAccountMenus();
            button.setAttribute("aria-expanded", willOpen ? "true" : "false");
            if (panel) {
                panel.hidden = !willOpen;
            }
        });
    });

    if (document.body.dataset.accountMenuDocumentBound !== "1") {
        document.body.dataset.accountMenuDocumentBound = "1";
        document.addEventListener("click", (event) => {
            if (!event.target.closest(".account-menu")) {
                closeAccountMenus();
            }
        });
    }

    return closeAccountMenus;
}

function ensureApiKeyDialog(copyLabel) {
    let dialog = document.getElementById("api-key-dialog");
    if (dialog) {
        return dialog;
    }

    const text = labels();
    dialog = createElem("dialog", {
        "id": "api-key-dialog",
        "class": "api-key-dialog",
        "hidden": "",
    });

    const closeButton = createElem("button", {
        "class": "dialog-close icon-button",
        "type": "button",
        "aria-label": text.close,
    });
    closeButton.textContent = "×";
    closeButton.addEventListener("click", () => closeDialog(dialog));

    const eyebrow = createElem("p", {"class": "eyebrow"});
    eyebrow.textContent = "API";
    const title = createElem("h2");
    title.textContent = text.apiKeyTitle;
    const help = createElem("p");
    help.textContent = text.apiKeyHelp;
    const value = createElem("textarea", {
        "id": "api-key-value",
        "readonly": "",
        "rows": "3",
    });
    const actions = createElem("div", {"class": "api-key-actions"});
    const copy = createElem("button", {
        "id": "copy-api-key",
        "type": "button",
    });
    copy.textContent = copyLabel || text.copyApiKey;
    const close = createElem("button", {"type": "button"});
    close.textContent = text.close;
    close.addEventListener("click", () => closeDialog(dialog));
    const status = createElem("p", {
        "id": "api-key-status",
        "class": "action-status",
    });

    actions.appendChild(copy);
    actions.appendChild(close);
    dialog.appendChild(closeButton);
    dialog.appendChild(eyebrow);
    dialog.appendChild(title);
    dialog.appendChild(help);
    dialog.appendChild(value);
    dialog.appendChild(actions);
    dialog.appendChild(status);
    document.body.appendChild(dialog);

    copy.addEventListener("click", async () => {
        if (!value.value) {
            return;
        }
        try {
            await navigator.clipboard.writeText(value.value);
            status.textContent = text.apiKeyCopied;
        } catch (error) {
            value.focus();
            value.select();
            status.textContent = text.apiKeyCopyFallback;
        }
    });

    dialog.addEventListener("close", () => {
        dialog.hidden = true;
    });

    return dialog;
}

function createSessionBar() {
    const text = labels();
    const sessionbar = createElem("div", {"class": "sessionbar"});
    const accountmenu = createElem("div", {"class": "account-menu"});
    const accountmenubouton = createElem("button", {
        "class": "account-menu-button",
        "type": "button",
        "aria-expanded": "false",
    });
    const spanOperator = createElem("span", {"class": "operator"});
    spanOperator.textContent = text.account;
    const spanFleche = createElem("span", {"aria-hidden": "true"});
    spanFleche.textContent = "▾";
    const panel = createElem("div", {
        "class": "account-menu-panel",
        "hidden": "",
    });
    const apiKeyButton = createElem("button", {
        "class": "account-menu-item",
        "data-api-key-action": "",
        "type": "button",
    });
    apiKeyButton.textContent = text.retrieveApiKey;
    const logoutForm = createElem("form", {
        "class": "logout-form",
        "method": "post",
        "action": "/logout",
    });
    const logoutButton = createElem("button", {"type": "submit"});
    logoutButton.textContent = text.logout;

    accountmenubouton.appendChild(spanOperator);
    accountmenubouton.appendChild(spanFleche);
    panel.appendChild(apiKeyButton);
    accountmenu.appendChild(accountmenubouton);
    accountmenu.appendChild(panel);
    logoutForm.appendChild(logoutButton);
    sessionbar.appendChild(accountmenu);
    sessionbar.appendChild(logoutForm);

    return {
        apiKeyButton,
        sessionbar,
        spanOperator,
    };
}

function bindApiKeyButton(button, closeAccountMenus) {
    button.addEventListener("click", async () => {
        const text = labels();
        closeAccountMenus();
        const dialog = ensureApiKeyDialog(text.copyApiKey);
        const value = document.getElementById("api-key-value");
        const status = document.getElementById("api-key-status");

        if (value) {
            value.value = "";
        }
        if (status) {
            status.textContent = text.apiKeyGenerating;
        }
        showDialog(dialog);

        try {
            const data = await apiJson("/api/me/api-key", {
                "method": "POST",
                "body": JSON.stringify({}),
            });
            if (value) {
                value.value = data.apiKey && data.apiKey.token ? data.apiKey.token : "";
                value.focus();
                value.select();
            }
            if (status) {
                status.textContent = text.apiKeyReady;
            }
        } catch (error) {
            if (status) {
                status.textContent = error.message;
            }
        }
    });
}

async function loadCurrentPlayer(spanOperator) {
    try {
        const data = await apiJson("/api/me", {"method": "GET"});
        const player = data && data.player ? data.player : {};
        spanOperator.textContent = player.displayName || player.username || labels().account;
    } catch (error) {
        spanOperator.textContent = labels().account;
    }
}

document.addEventListener("DOMContentLoaded", () => {
    bindOAuthRememberChoice();

    if (document.body.dataset.authenticated !== "1") {
        const closeAccountMenus = bindAccountMenus();
        bindTutorialDialog(closeAccountMenus);
        return;
    }

    const header = document.querySelector("header.topbar");
    if (!header || header.querySelector(".sessionbar")) {
        const closeAccountMenus = bindAccountMenus();
        bindTutorialDialog(closeAccountMenus);
        bindProbeSelector();
        return;
    }

    const {apiKeyButton, sessionbar, spanOperator} = createSessionBar();
    header.appendChild(sessionbar);
    const closeAccountMenus = bindAccountMenus();
    bindTutorialDialog(closeAccountMenus);
    bindApiKeyButton(apiKeyButton, closeAccountMenus);
    bindProbeSelector();
    loadCurrentPlayer(spanOperator);
    startNavigationWarningSync();
});
