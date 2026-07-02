(function () {
    const DEFAULT_REFRESH_MS = 15000;
    const MIN_REFRESH_MS = 750;
    const REFRESH_CUSHION_MS = 500;

    let i18n = {};
    let refreshTimer = null;
    let movementTickTimer = null;
    let loadInProgress = false;

    function withVng(callback) {
        if (window.VNG) {
            callback(window.VNG);
            return;
        }

        window.addEventListener("VNGReady", () => callback(window.VNG), {"once": true});
    }

    function translate(key, fallback) {
        return window.VNG.t(i18n, key, fallback);
    }

    function probeStatusLabel(status) {
        return {
            "idle": translate("probeStatusIdle", "Idle"),
            "preparing": translate("probeStatusPreparing", "Preparing"),
            "accelerating": translate("probeStatusAccelerating", "Accelerating"),
            "cruising": translate("probeStatusCruising", "Cruising"),
            "decelerating": translate("probeStatusDecelerating", "Decelerating"),
            "arrived": translate("probeStatusArrived", "Arrived"),
            "dead": translate("probeStatusDead", "Out of service"),
            "destroyed": translate("probeStatusDestroyed", "Destroyed"),
            "trapped_by_black_hole": translate("probeStatusTrappedByBlackHole", "Trapped by a black hole"),
        }[status] || status || "-";
    }

    function sensorModeLabel(mode) {
        return {
            "normal": translate("sensorModeNormal", "Normal"),
            "degraded": translate("sensorModeDegraded", "Degraded"),
            "blind": translate("sensorModeBlind", "Blind"),
        }[mode] || mode || "-";
    }

    function formatDuration(seconds) {
        return window.VNG.duration(seconds, translate);
    }

    function clearRefreshTimer() {
        if (refreshTimer !== null) {
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
        }
    }

    function clearMovementTimer() {
        if (movementTickTimer !== null) {
            window.clearTimeout(movementTickTimer);
            movementTickTimer = null;
        }
    }

    function scheduleRefresh(delayMs) {
        clearRefreshTimer();
        refreshTimer = window.setTimeout(loadProbe, delayMs);
    }

    function movementRemainingHtml(movement, observedAt) {
        const secondsRemaining = Number(movement && movement.secondsRemaining);
        if (!Number.isFinite(secondsRemaining)) {
            return window.VNG.escapeHtml(formatDuration(secondsRemaining));
        }

        const endAt = observedAt + Math.max(0, secondsRemaining) * 1000;
        return "<span class=\"probe-movement-remaining-value\" data-movement-end-at=\""
            + window.VNG.escapeHtml(String(endAt)) + "\">"
            + window.VNG.escapeHtml(formatDuration(secondsRemaining))
            + "</span>";
    }

    function updateLiveMovementRemainingValues() {
        const nodes = Array.from(document.querySelectorAll("#probe-summary .probe-movement-remaining-value[data-movement-end-at]"));
        const now = Date.now();
        let hasPendingMovement = false;

        nodes.forEach((node) => {
            const endAt = Number(node.dataset.movementEndAt);
            if (!Number.isFinite(endAt)) {
                return;
            }

            const remainingSeconds = Math.max(0, Math.ceil((endAt - now) / 1000));
            node.textContent = formatDuration(remainingSeconds);
            if (remainingSeconds > 0 && now < endAt) {
                hasPendingMovement = true;
            }
        });

        movementTickTimer = hasPendingMovement
            ? window.setTimeout(updateLiveMovementRemainingValues, 1000)
            : null;
    }

    function scheduleLiveMovementUpdates() {
        clearMovementTimer();
        const hasMovementCounter = document.querySelector("#probe-summary .probe-movement-remaining-value[data-movement-end-at]");
        if (!hasMovementCounter) {
            return;
        }

        updateLiveMovementRemainingValues();
    }

    function deuteriumMaxHint(probe) {
        const maxDeuterium = Number(probe && probe.fuel ? probe.fuel.maxDeuterium : NaN);
        if (!Number.isFinite(maxDeuterium) || maxDeuterium <= 100) {
            return "";
        }

        return "max " + window.VNG.numberValue(maxDeuterium);
    }

    function renderDeuteriumMaxHint(probe) {
        const hint = deuteriumMaxHint(probe);
        if (hint === "") {
            return;
        }

        const metric = document.querySelector("#probe-summary [data-metric=\"deuterium\"]");
        if (!metric) {
            return;
        }

        const node = document.createElement("small");
        node.className = "metric-secondary";
        node.textContent = hint;
        metric.appendChild(node);
    }

    function renderTerminalAlert(probe) {
        const node = document.getElementById("probe-terminal-alert");
        if (!node) {
            return;
        }

        const alert = probe && probe.alert ? probe.alert : null;
        if (!alert) {
            node.hidden = true;
            node.innerHTML = "";
            return;
        }

        const action = alert.action || {};
        const endpoint = action.endpoint || "";
        const method = action.method || "POST";
        const title = probe.status === "trapped_by_black_hole"
            ? translate("probeTerminalAlertBlackHoleTitle", alert.title || "No-return threshold crossed")
            : (probe.status === "dead"
                ? translate("probeTerminalAlertDeadTitle", alert.title || "Probe destroyed")
                : (alert.title || translate("probeTerminalAlertTitle", "Probe terminal state")));
        const message = probe.status === "trapped_by_black_hole"
            ? translate("probeTerminalAlertBlackHoleMessage", alert.message || probe.message || "")
            : (probe.status === "dead"
                ? translate("probeTerminalAlertDeadMessage", alert.message || probe.message || "")
                : (alert.message || probe.message || ""));
        node.hidden = false;
        node.innerHTML = "<div class=\"probe-terminal-alert-copy\">"
            + "<h3>" + window.VNG.escapeHtml(title) + "</h3>"
            + "<p>" + window.VNG.escapeHtml(message) + "</p>"
            + "</div>"
            + "<button class=\"probe-terminal-alert-action\" type=\"button\" data-endpoint=\"" + window.VNG.escapeHtml(endpoint) + "\" data-method=\"" + window.VNG.escapeHtml(method) + "\">"
            + window.VNG.escapeHtml(translate("reassignMindSnapshot", action.label || "Restore your mind into a new probe"))
            + "</button>";

        const button = node.querySelector(".probe-terminal-alert-action");
        button?.addEventListener("click", reassignMindSnapshot);
    }

    function renderProbeMetrics(data) {
        const probe = data && data.probe ? data.probe : {};
        const nav = probe.navigation || {};
        const movement = probe.movement || null;
        const sector = probe.sector && probe.sector.relative ? probe.sector.relative : null;
        const observedAt = Date.now();
        const sensorDetail = probe.sensorMode === "degraded"
            ? window.VNG.escapeHtml(translate("sensorDegradedInfo", "At relativistic speeds, external sensors cannot analyze the environment in detail."))
            : (probe.sensorMode === "blind"
                ? window.VNG.escapeHtml(translate("sensorBlindInfo", "At this relativistic speed, external sensors are blinded."))
                : null);
        const sectorDetail = !sector && movement ? window.VNG.detailList([
            {"label": translate("originSector", "Origin sector"), "value": window.VNG.coordinate(movement.origin)},
            {"label": translate("destinationSector", "Arrival sector"), "value": window.VNG.coordinate(movement.target)},
            {"label": translate("remainingTime", "Remaining time"), "htmlValue": movementRemainingHtml(movement, observedAt)},
        ]) : null;

        renderTerminalAlert(probe);
        window.VNG.renderMetrics(document.getElementById("probe-summary"), [
            {
                "name": "status",
                "label": translate("status", "Status"),
                "value": probeStatusLabel(probe.status),
                "valueId": "probe-metric-status",
            },
            {
                "name": "sensors",
                "label": translate("sensors", "Sensors"),
                "value": sensorModeLabel(probe.sensorMode),
                "valueId": "probe-metric-sensors",
                "detail": sensorDetail,
            },
            {
                "name": "deuterium",
                "label": translate("deuterium", "Deuterium"),
                "value": probe.fuel ? window.VNG.numberValue(probe.fuel.deuterium, "%") : "-",
                "valueId": "probe-metric-deuterium",
            },
            {
                "name": "sector",
                "label": translate("sector", "Sector"),
                "value": sector ? window.VNG.coordinate(sector) : translate("transit", "Transit"),
                "valueId": "probe-metric-sector",
                "detail": sectorDetail,
            },
            {
                "name": "velocity",
                "label": translate("velocityC", "Velocity c"),
                "value": window.VNG.numberValue(nav.velocityC),
                "valueId": "probe-metric-velocity",
            },
            {
                "name": "heading",
                "label": translate("heading", "Heading"),
                "value": nav.direction ? window.VNG.coordinate(nav.direction) : "-",
                "valueId": "probe-metric-heading",
            },
        ]);
        renderDeuteriumMaxHint(probe);

        scheduleLiveMovementUpdates();
    }

    function renderProbeError(error) {
        renderTerminalAlert(null);
        window.VNG.renderMetrics(document.getElementById("probe-summary"), [
            {
                "name": "status",
                "label": translate("status", "Status"),
                "value": error && error.message ? error.message : translate("requestDenied", "Request denied"),
                "valueId": "probe-metric-status",
            },
            {
                "name": "sensors",
                "label": translate("sensors", "Sensors"),
                "value": "-",
                "valueId": "probe-metric-sensors",
            },
            {
                "name": "deuterium",
                "label": translate("deuterium", "Deuterium"),
                "value": "-",
                "valueId": "probe-metric-deuterium",
            },
            {
                "name": "sector",
                "label": translate("sector", "Sector"),
                "value": "-",
                "valueId": "probe-metric-sector",
            },
            {
                "name": "velocity",
                "label": translate("velocityC", "Velocity c"),
                "value": "-",
                "valueId": "probe-metric-velocity",
            },
            {
                "name": "heading",
                "label": translate("heading", "Heading"),
                "value": "-",
                "valueId": "probe-metric-heading",
            },
        ]);
    }

    async function reassignMindSnapshot(event) {
        const button = event.currentTarget;
        const endpoint = button.dataset.endpoint || "/api/probe/mind-snapshot/reassign";
        const method = button.dataset.method || "POST";
        button.disabled = true;
        button.textContent = translate("mindSnapshotReassigning", "Reassigning...");

        try {
            await window.VNG.apiJson(endpoint, {
                "method": method,
                "body": JSON.stringify({}),
            });
            button.textContent = translate("mindSnapshotReassigned", "Snapshot reassigned");
            await loadProbe();
        } catch (error) {
            button.disabled = false;
            button.textContent = error && error.message ? error.message : translate("requestDenied", "Request denied");
        }
    }

    async function loadProbe() {
        if (loadInProgress) {
            return;
        }

        loadInProgress = true;
        clearRefreshTimer();
        clearMovementTimer();

        try {
            const data = await window.VNG.apiJson("/api/probe", {"method": "GET"});
            renderProbeMetrics(data);
            scheduleRefresh(window.VNG.nextRefreshDelay(data, DEFAULT_REFRESH_MS, MIN_REFRESH_MS, REFRESH_CUSHION_MS));
        } catch (error) {
            renderProbeError(error);
            scheduleRefresh(DEFAULT_REFRESH_MS);
        } finally {
            loadInProgress = false;
        }
    }

    function bindRefreshButton() {
        document.querySelector("[data-refresh=\"probe\"]")?.addEventListener("click", () => {
            loadProbe();
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        if (document.body.dataset.authenticated !== "1" || !document.getElementById("probe-summary")) {
            return;
        }

        withVng(async () => {
            i18n = await window.VNG.loadI18n();
            bindRefreshButton();
            loadProbe();
        });
    });
}());
