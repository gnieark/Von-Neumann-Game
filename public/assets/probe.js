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
    let currentProbeId = "";
    let logbookProbeId = "";
    let logbookLoading = false;
    let logbookState = {
        "page": null,
        "offset": 0,
        "total": 0,
        "mode": "",
        "notice": "",
    };

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

    function resolveLogbookProbeId() {
        const selected = typeof window.VNG.selectedProbeId === "function" ? window.VNG.selectedProbeId() : "";

        return currentProbeId || selected || "";
    }

    function logbookApiPath(suffix) {
        const probeId = resolveLogbookProbeId();
        if (!probeId) {
            return "";
        }

        const normalizedSuffix = suffix ? (String(suffix).startsWith("/") ? String(suffix) : "/" + String(suffix)) : "";

        return "/api/probe/" + encodeURIComponent(String(probeId)) + normalizedSuffix;
    }

    function logbookContentHtml(content) {
        const escaped = window.VNG.escapeHtml(content || "");

        return escaped.replace(/\r\n|\r|\n/g, "<br>");
    }

    function logbookCurrentPosition() {
        if (logbookState.total <= 0) {
            return "";
        }

        return window.VNG.numberValue(logbookState.offset + 1) + "/" + window.VNG.numberValue(logbookState.total);
    }

    function setLogbookStatus(message, isError) {
        const node = document.getElementById("probe-logbook-status");
        if (!node) {
            return;
        }
        node.hidden = !message;
        node.textContent = message || "";
        node.classList.toggle("probe-logbook-status-error", isError === true);
    }

    function setLogbookForm(mode) {
        const form = document.getElementById("probe-logbook-form");
        if (!form) {
            return;
        }

        logbookState.mode = mode || "";
        if (!mode) {
            form.hidden = true;
            form.reset();
            return;
        }

        const page = logbookState.page || {};
        const titleInput = form.querySelector("input[name=\"title\"]");
        const contentInput = form.querySelector("textarea[name=\"content\"]");
        form.hidden = false;
        form.dataset.mode = mode;
        if (titleInput) {
            titleInput.value = mode === "edit" ? String(page.title || "") : "";
            titleInput.focus();
        }
        if (contentInput) {
            contentInput.value = mode === "edit" ? String(page.content || "") : "";
        }
    }

    function renderLogbook() {
        const panel = document.getElementById("probe-logbook");
        if (!panel) {
            return;
        }

        const page = logbookState.page;
        const hasPage = page && page.id;
        const titleNode = document.getElementById("probe-logbook-title");
        const contentNode = document.getElementById("probe-logbook-content");
        const previousButton = document.getElementById("probe-logbook-prev");
        const nextButton = document.getElementById("probe-logbook-next");
        const addButton = document.getElementById("probe-logbook-add");
        const editButton = document.getElementById("probe-logbook-edit");
        const deleteButton = document.getElementById("probe-logbook-delete");

        if (previousButton) {
            previousButton.disabled = logbookLoading || logbookState.offset <= 0;
        }
        if (nextButton) {
            nextButton.disabled = logbookLoading || logbookState.total <= 0 || logbookState.offset >= logbookState.total - 1;
        }
        if (addButton) {
            addButton.disabled = logbookLoading || !resolveLogbookProbeId();
        }
        if (editButton) {
            editButton.disabled = logbookLoading || !hasPage;
        }
        if (deleteButton) {
            deleteButton.disabled = logbookLoading || !hasPage;
        }

        if (titleNode) {
            titleNode.textContent = hasPage
                ? String(page.title || translate("logbookUntitledPage", "Untitled log"))
                : translate("logbookEmptyTitle", "Journal de bord");
        }
        if (contentNode) {
            if (logbookLoading && !hasPage) {
                contentNode.textContent = translate("logbookLoading", "Chargement du journal...");
            } else if (hasPage) {
                const position = logbookCurrentPosition();
                contentNode.innerHTML = (position ? "<p class=\"probe-logbook-position\">" + window.VNG.escapeHtml(position) + "</p>" : "")
                    + "<p class=\"probe-logbook-page-content\">" + logbookContentHtml(page.content || "") + "</p>";
            } else {
                contentNode.textContent = translate("logbookEmptyContent", "Aucune note consignée pour cette sonde.");
            }
        }

        setLogbookStatus(logbookState.notice, false);
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

    function renderProbeUnreachableContext(probe) {
        const node = document.getElementById("sector-context");
        if (!node) {
            return;
        }
        const unreachable = probe && probe.status === "out_of_scut_range";
        node.hidden = !unreachable;
        node.textContent = unreachable
            ? translate(
                "probeOutOfScutRangeExplanation",
                "This probe is unreachable. It is too far away and outside the area covered by SCUT. Only its estimated coordinates are available."
            )
            : "";
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
        currentProbeId = probe && probe.id ? String(probe.id) : currentProbeId;
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
        renderProbeUnreachableContext(probe);
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

    async function fetchLogbookPageAt(offset) {
        const listPath = logbookApiPath("/logbook-pages");
        if (!listPath) {
            logbookState.page = null;
            logbookState.total = 0;
            logbookState.offset = 0;
            logbookState.notice = translate("logbookUnavailable", "Journal de bord indisponible.");
            renderLogbook();
            return;
        }

        logbookLoading = true;
        renderLogbook();

        try {
            const list = await window.VNG.apiJson(listPath + "?limit=1&offset=" + encodeURIComponent(String(Math.max(0, offset))), {"method": "GET"});
            const pagination = list && list.pagination ? list.pagination : {};
            const total = Number(pagination.total || 0);
            const safeTotal = Number.isFinite(total) ? Math.max(0, total) : 0;
            const safeOffset = safeTotal > 0 ? Math.max(0, Math.min(Math.max(0, offset), safeTotal - 1)) : 0;
            const summary = Array.isArray(list && list.pages) ? list.pages[0] : null;

            logbookState.total = safeTotal;
            logbookState.offset = safeOffset;
            logbookState.notice = "";

            if (safeTotal > 0 && safeOffset !== Number(pagination.offset || 0)) {
                await fetchLogbookPageAt(safeOffset);
                return;
            }

            if (safeTotal === 0 || !summary || !summary.id) {
                logbookState.page = null;
                logbookProbeId = resolveLogbookProbeId();
                return;
            }

            const detail = await window.VNG.apiJson(logbookApiPath("/logbook-page/" + encodeURIComponent(String(summary.id))), {"method": "GET"});
            logbookState.page = detail && detail.page ? detail.page : null;
            logbookProbeId = resolveLogbookProbeId();
        } catch (error) {
            logbookState.page = null;
            logbookState.total = 0;
            logbookState.offset = 0;
            logbookState.notice = error && error.message ? error.message : translate("requestDenied", "Request denied");
            setLogbookForm("");
        } finally {
            logbookLoading = false;
            renderLogbook();
        }
    }

    async function loadLatestLogbookPage() {
        const listPath = logbookApiPath("/logbook-pages");
        if (!listPath) {
            renderLogbook();
            return;
        }

        logbookLoading = true;
        renderLogbook();
        try {
            const list = await window.VNG.apiJson(listPath + "?limit=1&offset=0", {"method": "GET"});
            const total = Number(list && list.pagination ? list.pagination.total : 0);
            const safeTotal = Number.isFinite(total) ? Math.max(0, total) : 0;
            if (safeTotal <= 0) {
                logbookState.page = null;
                logbookState.total = 0;
                logbookState.offset = 0;
                logbookState.notice = "";
                logbookProbeId = resolveLogbookProbeId();
                return;
            }
            await fetchLogbookPageAt(safeTotal - 1);
        } catch (error) {
            logbookState.page = null;
            logbookState.total = 0;
            logbookState.offset = 0;
            logbookState.notice = error && error.message ? error.message : translate("requestDenied", "Request denied");
            logbookProbeId = resolveLogbookProbeId();
        } finally {
            logbookLoading = false;
            renderLogbook();
        }
    }

    function ensureLogbookLoadedForCurrentProbe() {
        const probeId = resolveLogbookProbeId();
        if (!probeId || logbookLoading || logbookProbeId === probeId) {
            return;
        }

        setLogbookForm("");
        logbookState.page = null;
        logbookState.offset = 0;
        logbookState.total = 0;
        logbookState.notice = "";
        renderLogbook();
        loadLatestLogbookPage();
    }

    async function submitLogbookForm(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const mode = form.dataset.mode || logbookState.mode || "create";
        const title = form.querySelector("input[name=\"title\"]")?.value || "";
        const content = form.querySelector("textarea[name=\"content\"]")?.value || "";
        const page = logbookState.page || {};
        const endpoint = mode === "edit" && page.id
            ? logbookApiPath("/logbook-page/" + encodeURIComponent(String(page.id)))
            : logbookApiPath("/logbook-page");
        const submit = form.querySelector("button[type=\"submit\"]");

        if (!endpoint) {
            return;
        }
        if (submit) {
            submit.disabled = true;
        }

        try {
            await window.VNG.apiJson(endpoint, {
                "method": mode === "edit" ? "PATCH" : "POST",
                "body": JSON.stringify({"title": title, "content": content}),
            });
            setLogbookForm("");
            const successNotice = mode === "edit"
                ? translate("logbookUpdated", "Page mise à jour.")
                : translate("logbookCreated", "Page ajoutée.");
            if (mode === "edit") {
                await fetchLogbookPageAt(logbookState.offset);
            } else {
                await loadLatestLogbookPage();
            }
            logbookState.notice = successNotice;
            renderLogbook();
        } catch (error) {
            logbookState.notice = error && error.message ? error.message : translate("requestDenied", "Request denied");
            renderLogbook();
        } finally {
            if (submit) {
                submit.disabled = false;
            }
        }
    }

    async function deleteCurrentLogbookPage() {
        const page = logbookState.page || {};
        if (!page.id || !window.confirm(translate("logbookDeleteConfirm", "Supprimer cette page du journal ?"))) {
            return;
        }

        const endpoint = logbookApiPath("/logbook-page/" + encodeURIComponent(String(page.id)));
        if (!endpoint) {
            return;
        }

        try {
            await window.VNG.apiJson(endpoint, {"method": "DELETE"});
            setLogbookForm("");
            const successNotice = translate("logbookDeleted", "Page supprimée.");
            if (logbookState.total <= 1) {
                logbookState.page = null;
                logbookState.total = 0;
                logbookState.offset = 0;
                logbookState.notice = successNotice;
                renderLogbook();
                return;
            }
            await fetchLogbookPageAt(Math.max(0, Math.min(logbookState.offset, logbookState.total - 2)));
            logbookState.notice = successNotice;
            renderLogbook();
        } catch (error) {
            logbookState.notice = error && error.message ? error.message : translate("requestDenied", "Request denied");
            renderLogbook();
        }
    }

    function renderProbeError(error) {
        renderTerminalAlert(null);
        renderProbeIdentity(null);
        renderProbeUnreachableContext(null);
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
            ensureLogbookLoadedForCurrentProbe();
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

    function bindLogbook() {
        const panel = document.getElementById("probe-logbook");
        if (!panel || panel.dataset.logbookBound === "1") {
            return;
        }
        panel.dataset.logbookBound = "1";

        document.getElementById("probe-logbook-prev")?.addEventListener("click", () => {
            setLogbookForm("");
            fetchLogbookPageAt(Math.max(0, logbookState.offset - 1));
        });
        document.getElementById("probe-logbook-next")?.addEventListener("click", () => {
            setLogbookForm("");
            fetchLogbookPageAt(Math.min(logbookState.total - 1, logbookState.offset + 1));
        });
        document.getElementById("probe-logbook-add")?.addEventListener("click", () => {
            setLogbookForm("create");
        });
        document.getElementById("probe-logbook-edit")?.addEventListener("click", () => {
            if (logbookState.page && logbookState.page.id) {
                setLogbookForm("edit");
            }
        });
        document.getElementById("probe-logbook-delete")?.addEventListener("click", () => {
            deleteCurrentLogbookPage();
        });
        document.getElementById("probe-logbook-cancel")?.addEventListener("click", () => {
            setLogbookForm("");
        });
        document.getElementById("probe-logbook-form")?.addEventListener("submit", submitLogbookForm);
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
            bindLogbook();
            renderLogbook();
            loadProbe();
        });
    });
}());
