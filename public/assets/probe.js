(function () {
    const DEFAULT_REFRESH_MS = 15000;
    const MIN_REFRESH_MS = 750;
    const REFRESH_CUSHION_MS = 500;

    let i18n = {};
    let refreshTimer = null;
    let movementTickTimer = null;
    let loadInProgress = false;
    let probeImprovements = null;
    let probeImprovementsLoadPromise = null;
    let probeList = null;
    let probeNotice = null;

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

    function loadProbeImprovementsOnce() {
        if (probeImprovementsLoadPromise === null) {
            probeImprovementsLoadPromise = window.VNG.apiJson(window.VNG.probeApiPath("/probe-improvements-available"), {"method": "GET"})
                .then((data) => {
                    probeImprovements = Array.isArray(data && data.improvements)
                        ? data.improvements
                        : [];
                })
                .catch(() => {
                    probeImprovements = [];
                });
        }

        return probeImprovementsLoadPromise;
    }

    function installedProbeImprovements() {
        return (Array.isArray(probeImprovements) ? probeImprovements : [])
            .filter((improvement) => improvement && improvement.done === true);
    }

    function probeImprovementName(improvement) {
        return String(improvement && (improvement.name || improvement.id) || "");
    }

    function probeImprovementSummary() {
        const installed = installedProbeImprovements();
        if (installed.length === 0) {
            return translate("noInstalledProbeImprovement", "None");
        }

        const visibleNames = installed.slice(0, 2)
            .map(probeImprovementName)
            .filter(Boolean);
        const extraCount = installed.length - visibleNames.length;
        const suffix = extraCount > 0 ? " +" + window.VNG.numberValue(extraCount) : "";

        return (visibleNames.join(", ") || window.VNG.numberValue(installed.length)) + suffix;
    }

    function probeImprovementDetail() {
        const installed = installedProbeImprovements();
        if (installed.length <= 2) {
            return null;
        }

        return window.VNG.detailList(installed.map((improvement) => ({
            "label": probeImprovementName(improvement),
            "value": String(improvement && improvement.description || improvement && improvement.id || ""),
        })));
    }

    function listedProbes() {
        return Array.isArray(probeList && probeList.probes) ? probeList.probes : [];
    }

    function probeSummary(probeId) {
        const id = String(probeId || "");

        return listedProbes().find((probe) => String(probe && probe.id || "") === id) || null;
    }

    function explicitProbePatchPath(probeId) {
        return "/api/probe/" + encodeURIComponent(String(probeId || ""));
    }

    function probeTypeLabel(isDefault) {
        return isDefault
            ? translate("probeTypeDefault", "Probe")
            : translate("probeTypeDrone", "drone");
    }

    function currentProbeState(probe) {
        const summary = probeSummary(probe && probe.id);
        const selectedProbeId = typeof window.VNG.selectedProbeId === "function" ? window.VNG.selectedProbeId() : "";
        const isDefault = summary
            ? summary.isDefault === true
            : (!selectedProbeId && probe && probe.status !== "out_of_scut_range");
        const isReachable = summary
            ? summary.isReachable !== false
            : (probe && probe.status !== "out_of_scut_range");

        return {isDefault, isReachable};
    }

    function reachableDrones(currentProbeId) {
        return listedProbes().filter((probe) => (
            probe
            && String(probe.id || "") !== String(currentProbeId || "")
            && probe.isDefault !== true
            && probe.isReachable !== false
        ));
    }

    function selectedDroneOptions(drones, selectedId) {
        return "<option value=\"\">" + window.VNG.escapeHtml(translate("selectDroneProbe", "Select a drone")) + "</option>"
            + drones.map((probe) => {
                const id = String(probe && probe.id || "");
                const name = probe && probe.name ? probe.name : ("Probe #" + id);

                return "<option value=\"" + window.VNG.escapeHtml(id) + "\"" + (id === String(selectedId || "") ? " selected" : "") + ">"
                    + window.VNG.escapeHtml(name)
                    + "</option>";
            }).join("");
    }

    function renderProbeIdentity(data) {
        const container = document.getElementById("probe-identity");
        if (!container) {
            return;
        }

        const probe = data && data.probe ? data.probe : null;
        if (!probe || !probe.id) {
            container.innerHTML = "";
            return;
        }

        const previousRenameOpen = container.querySelector(".probe-settings-button")?.getAttribute("aria-expanded") === "true";
        const previousRenameValue = container.querySelector(".probe-rename-form input[name=\"name\"]")?.value;
        const previousInstanceOpen = container.querySelector(".probe-instance-accordion-trigger")?.getAttribute("aria-expanded") === "true";
        const previousDroneId = container.querySelector(".probe-instance-form select[name=\"probeId\"]")?.value || "";
        const state = currentProbeState(probe);
        const drones = reachableDrones(probe.id);
        const renameOpen = previousRenameOpen && state.isReachable;
        const instanceOpen = previousInstanceOpen && state.isDefault;
        const renameValue = previousRenameValue !== undefined ? previousRenameValue : (probe.name || "");
        const selectedDroneId = drones.some((drone) => String(drone.id || "") === String(previousDroneId)) ? previousDroneId : "";
        const noticeHtml = probeNotice
            ? "<p class=\"probe-identity-notice probe-identity-notice-" + window.VNG.escapeHtml(probeNotice.type || "info") + "\">"
                + window.VNG.escapeHtml(probeNotice.text || "")
                + "</p>"
            : "";
        const instancePanelId = "probe-instance-panel-" + String(probe.id).replace(/[^a-zA-Z0-9_-]/g, "-");

        container.innerHTML = "<section class=\"probe-identity-block\">"
            + "<div class=\"probe-identity-header\">"
            + "<b class=\"probe-identity-name\">" + window.VNG.escapeHtml(probe.name || ("Probe #" + probe.id)) + "</b>"
            + "<button class=\"probe-settings-button manny-settings-button icon-button\" type=\"button\" aria-expanded=\"" + (renameOpen ? "true" : "false") + "\""
                + (state.isReachable ? "" : " disabled")
                + " title=\"" + window.VNG.escapeHtml(translate("probeSettings", "Probe settings")) + "\""
                + " aria-label=\"" + window.VNG.escapeHtml(translate("probeSettings", "Probe settings")) + "\">&#9881;</button>"
            + "</div>"
            + "<form class=\"probe-rename-form probe-inline-form\" data-probe-id=\"" + window.VNG.escapeHtml(String(probe.id)) + "\"" + (renameOpen ? "" : " hidden") + ">"
            + "<label>" + window.VNG.escapeHtml(translate("rename", "Rename")) + "<input name=\"name\" value=\"" + window.VNG.escapeHtml(renameValue) + "\" maxlength=\"40\"></label>"
            + "<button type=\"submit\">" + window.VNG.escapeHtml(translate("rename", "Rename")) + "</button>"
            + "</form>"
            + "<p class=\"probe-type-line\"><span>" + window.VNG.escapeHtml(translate("probeType", "Type")) + "</span><b>" + window.VNG.escapeHtml(probeTypeLabel(state.isDefault)) + "</b></p>"
            + noticeHtml
            + (state.isDefault ? "<section class=\"probe-instance-accordion\">"
                + "<button class=\"probe-instance-accordion-trigger manny-action-accordion-trigger\" type=\"button\" aria-expanded=\"" + (instanceOpen ? "true" : "false") + "\" aria-controls=\"" + window.VNG.escapeHtml(instancePanelId) + "\">"
                + window.VNG.escapeHtml(translate("probeInstanceSwitch", "Instance switch"))
                + "</button>"
                + "<div id=\"" + window.VNG.escapeHtml(instancePanelId) + "\" class=\"probe-instance-accordion-panel manny-action-accordion-panel\"" + (instanceOpen ? "" : " hidden") + ">"
                + "<form class=\"probe-instance-form\" data-current-probe-id=\"" + window.VNG.escapeHtml(String(probe.id)) + "\">"
                + "<p>" + window.VNG.escapeHtml(translate("probeInstanceSwitchExplanation", "Réinstancier l’opérateur vers un drone")) + "</p>"
                + "<label><span>" + window.VNG.escapeHtml(translate("probeTypeDrone", "drone")) + "</span>"
                + "<select name=\"probeId\">" + selectedDroneOptions(drones, selectedDroneId) + "</select></label>"
                + "<button type=\"submit\"" + (selectedDroneId ? "" : " disabled") + ">" + window.VNG.escapeHtml(translate("probeInstanceSwitchValidate", "Transfer")) + "</button>"
                + "</form>"
                + "</div>"
                + "</section>" : "")
            + "</section>";
    }

    async function patchProbe(probeId, payload) {
        return window.VNG.apiJson(explicitProbePatchPath(probeId), {
            "method": "PATCH",
            "body": JSON.stringify(payload),
        });
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
        renderProbeIdentity(data);
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
                "name": "probe-improvements",
                "label": translate("installedProbeImprovements", "Installed upgrades"),
                "value": probeImprovementSummary(),
                "valueId": "probe-metric-improvements",
                "detail": probeImprovementDetail(),
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
        renderProbeIdentity(null);
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
                "name": "probe-improvements",
                "label": translate("installedProbeImprovements", "Installed upgrades"),
                "value": "-",
                "valueId": "probe-metric-improvements",
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
            const [data, listed] = await Promise.all([
                window.VNG.apiJson(window.VNG.probeApiPath(""), {"method": "GET"}),
                window.VNG.loadProbeList ? window.VNG.loadProbeList() : Promise.resolve(null),
            ]);
            probeList = listed;
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

    function bindProbeIdentity() {
        const container = document.getElementById("probe-identity");
        if (!container || container.dataset.probeIdentityBound === "1") {
            return;
        }
        container.dataset.probeIdentityBound = "1";

        container.addEventListener("click", (event) => {
            const settingsButton = event.target.closest(".probe-settings-button");
            if (settingsButton) {
                const form = container.querySelector(".probe-rename-form");
                if (!form) {
                    return;
                }
                const willOpen = form.hidden;
                form.hidden = !willOpen;
                settingsButton.setAttribute("aria-expanded", willOpen ? "true" : "false");
                if (willOpen) {
                    form.querySelector("input[name=\"name\"]")?.focus();
                }
                return;
            }

            const accordionButton = event.target.closest(".probe-instance-accordion-trigger");
            if (accordionButton) {
                const targetId = accordionButton.getAttribute("aria-controls") || "";
                const panel = targetId ? document.getElementById(targetId) : null;
                const willOpen = accordionButton.getAttribute("aria-expanded") !== "true";
                accordionButton.setAttribute("aria-expanded", willOpen ? "true" : "false");
                if (panel) {
                    panel.hidden = !willOpen;
                }
            }
        });

        container.addEventListener("change", (event) => {
            if (!event.target.matches(".probe-instance-form select[name=\"probeId\"]")) {
                return;
            }
            const form = event.target.closest(".probe-instance-form");
            const submit = form ? form.querySelector("button[type=\"submit\"]") : null;
            if (submit) {
                submit.disabled = !event.target.value;
            }
        });

        container.addEventListener("submit", async (event) => {
            const renameForm = event.target.closest(".probe-rename-form");
            const instanceForm = event.target.closest(".probe-instance-form");
            if (!renameForm && !instanceForm) {
                return;
            }

            event.preventDefault();
            const form = renameForm || instanceForm;
            const submit = form.querySelector("button[type=\"submit\"]");
            if (submit) {
                submit.disabled = true;
            }

            try {
                let response;
                if (renameForm) {
                    response = await patchProbe(renameForm.dataset.probeId, {
                        "name": renameForm.querySelector("input[name=\"name\"]")?.value || "",
                    });
                    probeNotice = {"type": "success", "text": translate("probeRenamed", "Probe renamed.")};
                } else {
                    const targetProbeId = instanceForm.querySelector("select[name=\"probeId\"]")?.value || "";
                    if (!targetProbeId) {
                        return;
                    }
                    response = await patchProbe(targetProbeId, {"isDefault": true});
                    probeNotice = {
                        "type": "success",
                        "text": translate("probeInstanceSwitchSuccess", "Votre instance cognitive a été transférée vers la sonde sélectionnée. L’ancienne sonde passe en mode drone."),
                    };
                }

                probeList = await (window.VNG.refreshProbeSelector ? window.VNG.refreshProbeSelector(response) : Promise.resolve(response));
                await loadProbe();
            } catch (error) {
                probeNotice = {"type": "error", "text": error && error.message ? error.message : translate("requestDenied", "Request denied")};
                await loadProbe();
            } finally {
                if (submit) {
                    submit.disabled = false;
                }
            }
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        if (document.body.dataset.authenticated !== "1" || !document.getElementById("probe-summary")) {
            return;
        }

        withVng(async () => {
            i18n = await window.VNG.loadI18n();
            await loadProbeImprovementsOnce();
            bindRefreshButton();
            bindProbeIdentity();
            loadProbe();
        });
    });
}());
