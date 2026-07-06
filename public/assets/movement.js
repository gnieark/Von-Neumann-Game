(function () {
    const DEFAULT_REFRESH_MS = 15000;
    const MIN_REFRESH_MS = 750;
    const REFRESH_CUSHION_MS = 500;

    const state = {
        currentMannies: [],
        currentProbeSectorRelative: null,
        hasExplicitRouteTarget: /^\/movement(?:\/\d+)?\/-?\d+\/-?\d+\/-?\d+$/.test(window.location.pathname),
        probeAlreadyMoving: false,
        probeDeuteriumSufficient: false,
        syncedDefaultTarget: false,
        userEditedTarget: false,
    };

    let i18n = {};
    let refreshTimer = null;
    let loadInProgress = false;

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

    function setText(id, value) {
        const node = document.getElementById(id);
        if (node) {
            node.textContent = value;
        }
    }

    function relativeCoordinates(value) {
        if (!value || !Number.isFinite(Number(value.x)) || !Number.isFinite(Number(value.y)) || !Number.isFinite(Number(value.z))) {
            return null;
        }

        return {
            "x": Number(value.x),
            "y": Number(value.y),
            "z": Number(value.z),
        };
    }

    function sameRelativeCoordinates(left, right) {
        const a = relativeCoordinates(left);
        const b = relativeCoordinates(right);

        return a !== null && b !== null && a.x === b.x && a.y === b.y && a.z === b.z;
    }

    function moveFormTarget() {
        const form = document.getElementById("move-form");
        if (!form) {
            return null;
        }

        return {
            "x": Number.parseInt(form.elements.x?.value, 10),
            "y": Number.parseInt(form.elements.y?.value, 10),
            "z": Number.parseInt(form.elements.z?.value, 10),
        };
    }

    function syncMoveFormTarget(target) {
        const form = document.getElementById("move-form");
        const relative = relativeCoordinates(target);
        if (!form || relative === null) {
            return;
        }

        ["x", "y", "z"].forEach((field) => {
            if (form.elements[field]) {
                form.elements[field].value = String(relative[field]);
            }
        });
    }

    function syncDefaultTargetFromCurrentSector() {
        if (state.hasExplicitRouteTarget || state.syncedDefaultTarget || state.userEditedTarget || state.currentProbeSectorRelative === null) {
            return;
        }

        syncMoveFormTarget(state.currentProbeSectorRelative);
        state.syncedDefaultTarget = true;
    }

    function movementDistance(target) {
        const destination = relativeCoordinates(target);
        const origin = relativeCoordinates(state.currentProbeSectorRelative);
        if (destination === null || origin === null) {
            return null;
        }

        return Math.max(
            Math.abs(destination.x - origin.x),
            Math.abs(destination.y - origin.y),
            Math.abs(destination.z - origin.z)
        );
    }

    function destructionWarningConfig() {
        const form = document.getElementById("move-form");
        const warningDistance = Number.parseInt(form?.dataset.destructionWarningDistance || "3", 10);
        const riskPercent = Number.parseFloat(form?.dataset.destructionWarningRiskPercent || "5");

        return {
            "distance": Number.isFinite(warningDistance) && warningDistance > 0 ? warningDistance : 3,
            "riskPercent": Number.isFinite(riskPercent) && riskPercent >= 0 ? riskPercent : 5,
        };
    }

    function formatRiskPercent(value) {
        return new Intl.NumberFormat(document.documentElement.lang || undefined, {
            "maximumFractionDigits": 2,
        }).format(value);
    }

    function renderMovementRiskWarning() {
        const node = document.getElementById("movement-risk-warning");
        if (!node) {
            return;
        }

        const target = moveFormTarget();
        const distance = movementDistance(target);
        const config = destructionWarningConfig();
        if (!window.VNG.validRelativeCoordinates(target) || distance === null || distance < config.distance) {
            node.hidden = true;
            node.textContent = "";
            return;
        }

        node.textContent = distance === config.distance
            ? window.VNG.formatText(
                tr("movementDestructionRiskKnown", "From {distance} sectors away, celestial objects that may cross the probe trajectory can no longer be reliably anticipated. For this route, the probe destruction risk is {riskPercent}%."),
                {"distance": config.distance, "riskPercent": formatRiskPercent(config.riskPercent)}
            )
            : window.VNG.formatText(
                tr("movementDestructionRiskElevated", "From {distance} sectors away, celestial objects that may cross the probe trajectory can no longer be reliably anticipated. For this route, the probe destruction risk becomes non-negligible."),
                {"distance": config.distance}
            );
        node.hidden = false;
    }

    function checklistValue(ok) {
        return "<span class=\"checklist-value " + (ok === true ? "ok" : "warn") + "\">"
            + window.VNG.escapeHtml(ok === null ? "-" : (ok ? tr("checklistYes", "Yes") : tr("checklistNo", "No")))
            + "</span>";
    }

    function allManniesAboard() {
        return Array.isArray(state.currentMannies)
            ? state.currentMannies.every((manny) => (manny.location && manny.location.type) === "probe")
            : null;
    }

    function renderJumpChecklist() {
        const node = document.getElementById("jump-checklist");
        if (!node) {
            return;
        }

        node.innerHTML = "<h3>" + window.VNG.escapeHtml(tr("jumpPreparationChecklist", "Preparation")) + "</h3>"
            + "<ul>"
            + "<li><span>" + window.VNG.escapeHtml(tr("deuteriumSufficient", "Sufficient deuterium")) + "</span>" + checklistValue(state.probeDeuteriumSufficient) + "</li>"
            + "<li><span>" + window.VNG.escapeHtml(tr("manniesAboard", "Mannys aboard")) + "</span>" + checklistValue(allManniesAboard()) + "</li>"
            + "</ul>";
    }

    function applyMoveButtonState() {
        const button = document.getElementById("move-submit");
        const control = document.getElementById("jump-control");
        if (!button) {
            return;
        }

        const target = moveFormTarget();
        const targetInvalid = !window.VNG.validRelativeCoordinates(target);
        const targetIsCurrent = !targetInvalid && sameRelativeCoordinates(target, state.currentProbeSectorRelative);
        const blockedByFuel = !state.probeDeuteriumSufficient;
        const invalidMessage = tr("invalidCoordinates", "Invalid relative coordinates: x + y + z must be even.");
        const alreadyMovingMessage = tr("probeAlreadyMoving", "The probe is already moving between sectors.");
        const fuelMessage = tr("insufficientFuelForJump", "Insufficient deuterium to initiate a jump.");
        const currentSectorMessage = tr("currentSectorDestination", "Choose a different sector to initiate a jump.");

        button.disabled = state.probeAlreadyMoving || blockedByFuel || targetInvalid || targetIsCurrent;
        button.title = state.probeAlreadyMoving
            ? alreadyMovingMessage
            : (blockedByFuel ? fuelMessage : (targetInvalid ? invalidMessage : (targetIsCurrent ? currentSectorMessage : "")));
        button.setAttribute("aria-disabled", button.disabled ? "true" : "false");
        if (control) {
            control.classList.toggle("disabled", button.disabled);
            control.title = button.title;
            control.setAttribute("aria-disabled", button.disabled ? "true" : "false");
        }
        renderMovementRiskWarning();
    }

    function manniesOutsideProbeInCurrentSector(mannies) {
        return (Array.isArray(mannies) ? mannies : []).filter((manny) => {
            const location = manny && manny.location ? manny.location : {};
            if (location.type === "probe") {
                return false;
            }

            return sameRelativeCoordinates(location.sector && location.sector.relative, state.currentProbeSectorRelative);
        });
    }

    async function loadProbe() {
        const data = await window.VNG.apiJson(window.VNG.probeApiPath(""), {"method": "GET"});
        const probe = data.probe || {};
        const movement = probe.movement || null;
        const sector = probe.sector && probe.sector.relative ? probe.sector.relative : null;

        state.currentProbeSectorRelative = sector ? relativeCoordinates(sector) : null;
        state.probeAlreadyMoving = Boolean(movement && ["preparing", "accelerating", "cruising", "decelerating"].includes(movement.phase || movement.status));
        state.probeDeuteriumSufficient = Number(probe && probe.fuel ? probe.fuel.deuterium : 0) > 0.0001;
        syncDefaultTargetFromCurrentSector();

        return data;
    }

    async function loadMannies() {
        const data = await window.VNG.apiJson(window.VNG.probeApiPath("/mannies"), {"method": "GET"});
        state.currentMannies = Array.isArray(data.mannies) ? data.mannies : [];

        return data;
    }

    function clearRefreshTimer() {
        if (refreshTimer !== null) {
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
        }
    }

    function scheduleRefresh(data) {
        clearRefreshTimer();
        refreshTimer = window.setTimeout(refreshMovementState, window.VNG.nextRefreshDelay(
            data,
            DEFAULT_REFRESH_MS,
            MIN_REFRESH_MS,
            REFRESH_CUSHION_MS
        ));
    }

    async function refreshMovementState() {
        if (loadInProgress) {
            return;
        }

        loadInProgress = true;
        clearRefreshTimer();

        try {
            window.VNG.setProbeUnreachablePanel?.("actions-panel", false);
            const [probeData, mannyData] = await Promise.all([loadProbe(), loadMannies()]);
            renderJumpChecklist();
            applyMoveButtonState();
            scheduleRefresh({"probe": probeData.probe, "mannies": mannyData.mannies});
        } catch (error) {
            if (!await window.VNG.renderUnreachableProbeTelemetry(error, {"statusId": "action-status", "panelId": "actions-panel"})) {
                window.VNG.setProbeUnreachablePanel?.("actions-panel", false);
                setText("action-status", error.message || tr("requestDenied", "Request denied"));
            }
            renderJumpChecklist();
            applyMoveButtonState();
            scheduleRefresh(null);
        } finally {
            loadInProgress = false;
        }
    }

    async function confirmJumpWithMannies() {
        await loadMannies();
        const absentMannies = manniesOutsideProbeInCurrentSector(state.currentMannies);
        if (absentMannies.length === 0) {
            return true;
        }

        const names = absentMannies.map((manny) => manny.name || manny.id || tr("mannyObject", "Manny")).join(", ");
        return window.confirm(window.VNG.formatText(
            tr("jumpWithAbsentManniesConfirm", "Some Mannys are not aboard the probe: {names}. If you initiate the jump now, they will be left in this sector. Confirm jump?"),
            {"names": names, "count": absentMannies.length}
        ));
    }

    async function submitMovement(event) {
        event.preventDefault();
        const alreadyMovingMessage = tr("probeAlreadyMoving", "The probe is already moving between sectors.");
        const target = moveFormTarget();

        if (state.probeAlreadyMoving) {
            setText("action-status", alreadyMovingMessage);
            return;
        }
        if (!state.probeDeuteriumSufficient) {
            setText("action-status", tr("insufficientFuelForJump", "Insufficient deuterium to initiate a jump."));
            return;
        }
        if (!window.VNG.validRelativeCoordinates(target)) {
            setText("action-status", tr("invalidCoordinates", "Invalid relative coordinates: x + y + z must be even."));
            applyMoveButtonState();
            return;
        }
        if (sameRelativeCoordinates(target, state.currentProbeSectorRelative)) {
            setText("action-status", tr("currentSectorDestination", "Choose a different sector to initiate a jump."));
            applyMoveButtonState();
            return;
        }
        if (!await confirmJumpWithMannies()) {
            setText("action-status", tr("movementCancelled", "Movement cancelled."));
            return;
        }

        setText("action-status", tr("orderSent", "Order transmitted..."));

        try {
            await window.VNG.apiJson(window.VNG.probeApiPath("/move"), {
                "method": "POST",
                "body": JSON.stringify({"target": target}),
            });
            setText("action-status", tr("movementAccepted", "Movement accepted."));
            await refreshMovementState();
        } catch (error) {
            setText("action-status", error.message || tr("requestDenied", "Request denied"));
        }
    }

    function bindPage() {
        document.getElementById("move-form")?.addEventListener("submit", submitMovement);
        document.getElementById("move-form")?.addEventListener("input", () => {
            state.userEditedTarget = true;
            applyMoveButtonState();
        });
        document.getElementById("jump-control")?.addEventListener("click", () => {
            if (state.probeAlreadyMoving) {
                setText("action-status", tr("probeAlreadyMoving", "The probe is already moving between sectors."));
                return;
            }
            if (!state.probeDeuteriumSufficient) {
                setText("action-status", tr("insufficientFuelForJump", "Insufficient deuterium to initiate a jump."));
                return;
            }
            if (!window.VNG.validRelativeCoordinates(moveFormTarget())) {
                setText("action-status", tr("invalidCoordinates", "Invalid relative coordinates: x + y + z must be even."));
                return;
            }
            if (sameRelativeCoordinates(moveFormTarget(), state.currentProbeSectorRelative)) {
                setText("action-status", tr("currentSectorDestination", "Choose a different sector to initiate a jump."));
            }
        });
        document.querySelector("[data-refresh=\"movement\"]")?.addEventListener("click", refreshMovementState);
    }

    document.addEventListener("DOMContentLoaded", () => {
        if (document.body.dataset.authenticated !== "1" || !document.getElementById("move-form")) {
            return;
        }

        withVng(async () => {
            i18n = await window.VNG.loadI18n();
            bindPage();
            applyMoveButtonState();
            refreshMovementState();
        });
    });
}());
