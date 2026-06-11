(function () {
    const DEFAULT_REFRESH_MS = 15000;
    const MIN_REFRESH_MS = 750;
    const REFRESH_CUSHION_MS = 500;

    let i18n = {};
    let refreshTimer = null;
    let loadInProgress = false;
    let currentSector = null;

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

    function renderAlerts(sector) {
        currentSector = sector || {};
        const alerts = window.VNG.sectorAlerts(currentSector, i18n);
        const list = document.getElementById("console-alerts-list");
        const empty = document.getElementById("console-alerts-empty");
        if (!list) {
            return;
        }

        window.VNG.setNavigationWarning("/alerts", alerts.some((alert) => !alert.acknowledged));
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
            const data = await window.VNG.apiJson("/api/probe/sector", {"method": "GET"});
            renderAlerts(data.sector || {});
            scheduleRefresh(data);
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
            const alerts = window.VNG.sectorAlerts(currentSector || {}, i18n);
            const alert = alerts[Number.parseInt(alertNode && alertNode.dataset.alertIndex || "-1", 10)];
            if (!alert) {
                return;
            }

            window.VNG.acknowledgeSectorAlert(alert.type, currentSector || {}, alert.signature);
            renderAlerts(currentSector || {});
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
