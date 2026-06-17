(function () {
    const DEFAULT_REFRESH_MS = 15000;
    const MIN_REFRESH_MS = 750;
    const REFRESH_CUSHION_MS = 500;

    let i18n = {};
    let refreshTimer = null;
    let loadInProgress = false;
    let currentSector = null;
    let currentAlerts = [];

    function withVng(callback) {
        if (window.VNG) {
            callback(window.VNG);
            return;
        }

        window.addEventListener("VNGReady", () => callback(window.VNG), {"once": true});
    }

    function tr(key, fallback) {
        return window.VNG.t(i18n, key, fallback);
    }

    function persistentAlertMessage(alert) {
        if (alert && alert.type === "intelligent_life") {
            const sector = alert.sector && alert.sector.relative
                ? window.VNG.coordinate(alert.sector.relative)
                : "-";
            const planet = alert.planet
                ? (alert.planet.name || alert.planet.id || tr("planetSingular", "planet"))
                : tr("planetSingular", "planet");

            return window.VNG.formatText(
                tr("intelligentLifeAlertMessage", "Intelligent life detected: technological signatures from {planet} in sector {sector}."),
                {planet, sector}
            );
        }

        const sector = alert && alert.sector && alert.sector.relative
            ? window.VNG.coordinate(alert.sector.relative)
            : "-";
        const container = alert && alert.container
            ? (alert.container.label || alert.container.id || "-")
            : "-";
        const risk = alert && alert.risk ? window.VNG.numberValue(alert.risk.percent, "%") : "-";
        const when = alert && alert.phase === "deceleration_start"
            ? tr("damageWarningArrivalSector", "arrival sector")
            : tr("damageWarningOriginSector", "origin sector");

        return window.VNG.formatText(
            tr("damageWarningContainerBreakMessage", "Fragile storage warning: {container} may break loose during this jump near the {when} ({sector}). Risk: {risk}. This can happen from 5 additional containers onward."),
            {container, when, sector, risk}
        );
    }

    function persistentAlerts(alerts) {
        return (Array.isArray(alerts) ? alerts : []).map((alert) => ({
            "kind": "persistent-alert",
            "id": alert.id,
            "type": alert.type,
            "className": alert.type === "intelligent_life" ? "sector-intelligent-life-alert" : "sector-damage-alert",
            "message": persistentAlertMessage(alert),
            "acknowledged": alert.status !== "unread",
        }));
    }

    function renderAlerts(sector, persistentAlertData) {
        currentSector = sector || {};
        const sectorAlerts = window.VNG.sectorAlerts(currentSector, i18n).map((alert) => ({
            ...alert,
            "kind": "sector-alert",
        }));
        const alerts = sectorAlerts.concat(persistentAlerts(persistentAlertData));
        currentAlerts = alerts;
        const list = document.getElementById("console-alerts-list");
        const empty = document.getElementById("console-alerts-empty");
        if (!list) {
            return;
        }

        const hasUnreadPersistentAlert = alerts.some((alert) => alert.kind === "persistent-alert" && !alert.acknowledged);
        window.VNG.setNavigationWarning("/alerts", alerts.some((alert) => !alert.acknowledged), hasUnreadPersistentAlert);
        if (empty) {
            empty.hidden = alerts.length > 0;
        }
        if (alerts.length === 0) {
            list.innerHTML = "";
            return;
        }

        list.innerHTML = alerts.map((alert, index) => (
            "<article class=\"sector-alert " + window.VNG.escapeHtml(alert.className) + (alert.acknowledged ? " acknowledged" : "") + "\" data-alert-index=\"" + String(index) + "\">"
                + "<span class=\"sector-alert-message\">" + window.VNG.escapeHtml(alert.message) + "</span>"
                + "<button class=\"sector-alert-acknowledge\" type=\"button\"" + (alert.acknowledged ? " disabled aria-disabled=\"true\"" : " aria-disabled=\"false\"") + ">"
                + window.VNG.escapeHtml(alert.acknowledged ? tr("acknowledgedAlert", "Acknowledged") : tr("acknowledgeAlert", "Acknowledge"))
                + "</button>"
            + "</article>"
        )).join("");
    }

    function scheduleRefresh(payload) {
        if (refreshTimer !== null) {
            window.clearTimeout(refreshTimer);
        }
        refreshTimer = window.setTimeout(refreshAlertsPage, window.VNG.nextRefreshDelay(
            payload || {},
            DEFAULT_REFRESH_MS,
            MIN_REFRESH_MS,
            REFRESH_CUSHION_MS
        ));
    }

    async function refreshAlertsPage() {
        if (loadInProgress) {
            return;
        }
        loadInProgress = true;
        if (refreshTimer !== null) {
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
        }

        try {
            const [sectorData, alertData] = await Promise.all([
                window.VNG.apiJson("/api/probe/sector", {"method": "GET"}).catch((error) => ({"sector": {}, "error": error})),
                window.VNG.apiJson("/api/probe/alerts", {"method": "GET"}).catch(() => ({"alerts": []})),
            ]);
            renderAlerts(sectorData.sector || {}, alertData.alerts || []);
            if (sectorData.error && (alertData.alerts || []).length === 0) {
                const empty = document.getElementById("console-alerts-empty");
                if (empty) {
                    empty.hidden = false;
                    empty.textContent = sectorData.error.message || tr("sectorContextUnavailable", "Displayed sector: observation unavailable.");
                }
            }
            scheduleRefresh({"sector": sectorData, "alerts": alertData.alerts || []});
        } catch (error) {
            const list = document.getElementById("console-alerts-list");
            const empty = document.getElementById("console-alerts-empty");
            if (list) {
                list.innerHTML = "";
            }
            if (empty) {
                empty.hidden = false;
                empty.textContent = error.message || tr("sectorContextUnavailable", "Displayed sector: observation unavailable.");
            }
            scheduleRefresh({});
        } finally {
            loadInProgress = false;
        }
    }

    function bindEvents() {
        document.querySelector("[data-refresh=\"alerts\"]")?.addEventListener("click", refreshAlertsPage);
        document.getElementById("console-alerts-list")?.addEventListener("click", (event) => {
            const button = event.target.closest(".sector-alert-acknowledge");
            if (!button) {
                return;
            }
            const alertNode = button.closest(".sector-alert");
            const alert = currentAlerts[Number.parseInt(alertNode && alertNode.dataset.alertIndex || "-1", 10)];
            if (!alert) {
                return;
            }

            if (alert.kind === "persistent-alert") {
                button.disabled = true;
                button.setAttribute("aria-disabled", "true");
                window.VNG.apiJson("/api/probe/alerts/" + encodeURIComponent(String(alert.id)), {
                    "method": "PATCH",
                    "body": JSON.stringify({}),
                }).then(refreshAlertsPage).then(window.VNG.syncNavigationWarnings).catch(() => {
                    button.disabled = false;
                    button.setAttribute("aria-disabled", "false");
                });
                return;
            }

            window.VNG.acknowledgeSectorAlert(alert.type, currentSector || {}, alert.signature);
            refreshAlertsPage();
            window.VNG.syncNavigationWarnings();
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        if (document.body.dataset.authenticated !== "1" || !document.getElementById("console-alerts-list")) {
            return;
        }

        withVng(async () => {
            i18n = await window.VNG.loadI18n();
            bindEvents();
            refreshAlertsPage();
        });
    });
})();
