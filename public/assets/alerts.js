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

    function damageWarningMessage(warning) {
        const sector = warning && warning.sector && warning.sector.relative
            ? window.VNG.coordinate(warning.sector.relative)
            : "-";
        const container = warning && warning.container
            ? (warning.container.label || warning.container.id || "-")
            : "-";
        const risk = warning && warning.risk ? window.VNG.numberValue(warning.risk.percent, "%") : "-";
        const when = warning && warning.phase === "deceleration_start"
            ? tr("damageWarningArrivalSector", "arrival sector")
            : tr("damageWarningOriginSector", "origin sector");

        return window.VNG.formatText(
            tr("damageWarningContainerBreakMessage", "Fragile storage warning: {container} may break loose during this jump near the {when} ({sector}). Risk: {risk}. This can happen from 5 additional containers onward."),
            {container, when, sector, risk}
        );
    }

    function persistentAlertMessage(alert) {
        if (alert && alert.type === "storage_container_break") {
            return damageWarningMessage(alert);
        }

        return alert && alert.message ? alert.message : tr("unknownAlert", "Unknown alert.");
    }

    function persistentAlertClassName(alert) {
        if (alert && alert.type === "storage_container_break") {
            return "sector-damage-alert";
        }
        if (alert && alert.type === "sector_object_detected") {
            return "sector-bookmark-alert";
        }
        if (alert && alert.type === "manny_report") {
            return "sector-manny-report-alert";
        }
        if (alert && alert.type === "intelligent_life") {
            return "sector-probe-alert";
        }

        return "sector-alert";
    }

    function alertMessageHtml(message) {
        return window.VNG.escapeHtml(message || "").replace(/\r?\n/g, "<br>");
    }

    function persistentAlerts(alerts) {
        return (Array.isArray(alerts) ? alerts : []).map((alert) => ({
            "kind": "persistent-alert",
            "type": alert.type || "alert",
            "id": alert.id,
            "className": persistentAlertClassName(alert),
            "message": persistentAlertMessage(alert),
            "acknowledged": alert.status !== "unread",
        }));
    }

    function alertPriority(alert) {
        if (alert && alert.kind === "persistent-alert" && alert.type === "manny_report" && !alert.acknowledged) {
            return 0;
        }
        if (alert && !alert.acknowledged) {
            return 1;
        }

        return 2;
    }

    function renderAlerts(sector, persistentAlertList) {
        currentSector = sector || {};
        const sectorAlerts = window.VNG.sectorAlerts(currentSector, i18n).map((alert) => ({
            ...alert,
            "kind": "sector-alert",
        }));
        const alerts = sectorAlerts.concat(persistentAlerts(persistentAlertList))
            .sort((left, right) => alertPriority(left) - alertPriority(right));
        currentAlerts = alerts;
        const list = document.getElementById("console-alerts-list");
        const empty = document.getElementById("console-alerts-empty");
        if (!list) {
            return;
        }

        const hasUnreadDamageWarning = alerts.some((alert) => alert.kind === "persistent-alert" && alert.type === "storage_container_break" && !alert.acknowledged);
        window.VNG.setNavigationWarning("/alerts", alerts.some((alert) => !alert.acknowledged), hasUnreadDamageWarning);
        if (empty) {
            empty.hidden = alerts.length > 0;
        }
        if (alerts.length === 0) {
            list.innerHTML = "";
            return;
        }

        list.innerHTML = alerts.map((alert, index) => (
            "<article class=\"sector-alert " + window.VNG.escapeHtml(alert.className) + (alert.acknowledged ? " acknowledged" : "") + "\" data-alert-index=\"" + String(index) + "\">"
                + "<span class=\"sector-alert-message\">" + alertMessageHtml(alert.message) + "</span>"
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
                window.VNG.apiJson(window.VNG.probeApiPath("/sector"), {"method": "GET"}).catch((error) => ({"sector": {}, "error": error})),
                window.VNG.apiJson(window.VNG.probeApiPath("/alerts"), {"method": "GET"}).catch(() => ({"alerts": []})),
            ]);
            if (sectorData.error && await window.VNG.renderUnreachableProbeTelemetry(sectorData.error, {"statusId": "console-alerts-empty", "panelId": "alerts-panel"})) {
                currentSector = {};
                currentAlerts = [];
                const list = document.getElementById("console-alerts-list");
                if (list) {
                    list.innerHTML = "";
                }
                scheduleRefresh({"sector": sectorData, "alerts": []});
                return;
            }
            window.VNG.setProbeUnreachablePanel?.("alerts-panel", false);
            renderAlerts(sectorData.sector || {}, alertData.alerts || []);
            if (sectorData.error && (alertData.alerts || []).length === 0) {
                const empty = document.getElementById("console-alerts-empty");
                if (!await window.VNG.renderUnreachableProbeTelemetry(sectorData.error, {"statusId": "console-alerts-empty"}) && empty) {
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
            window.VNG.setProbeUnreachablePanel?.("alerts-panel", false);
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
                window.VNG.apiJson(window.VNG.probeApiPath("/alerts/" + encodeURIComponent(String(alert.id))), {
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
