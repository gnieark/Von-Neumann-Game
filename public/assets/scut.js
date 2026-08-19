(function () {
    const DEFAULT_REFRESH_MS = 15000;

    const state = {
        "networks": [],
        "selectedNetworkId": "",
        "network": null,
        "blueprints": [],
        "ownedProbeIds": [],
        "sharePending": false,
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

    function setStatus(value) {
        const node = document.getElementById("scut-status");
        if (node) {
            node.textContent = value || "";
        }
    }

    function setBlueprintShareStatus(value) {
        const node = document.getElementById("scut-blueprint-share-status");
        if (node) {
            node.textContent = value || "";
        }
    }

    function formatNetworkAge(createdAt) {
        if (!createdAt) {
            return "-";
        }

        const timestamp = Date.parse(createdAt);
        if (!Number.isFinite(timestamp)) {
            return "-";
        }

        const days = Math.max(0, Math.floor((Date.now() - timestamp) / 86400000));
        const key = days === 1 ? "scutNetworkAgeDay" : "scutNetworkAgeDays";
        const fallback = days === 1 ? "{days} day" : "{days} days";
        return window.VNG.formatText(tr(key, fallback), {"days": String(days)});
    }

    function formatDate(value) {
        if (!value) {
            return "-";
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
            "dateStyle": "short",
            "timeStyle": "short",
        }).format(date);
    }

    function metricValue(value) {
        return Number.isFinite(Number(value)) ? String(Number(value)) : "0";
    }

    function renderNetworkChoice() {
        const wrapper = document.getElementById("scut-network-choice");
        const select = document.getElementById("scut-network-select");
        if (!wrapper || !select) {
            return;
        }

        const networks = Array.isArray(state.networks) ? state.networks : [];
        wrapper.hidden = networks.length <= 1;
        select.innerHTML = networks.map((network) => (
            "<option value=\"" + window.VNG.escapeHtml(String(network.id)) + "\">"
                + window.VNG.escapeHtml(network.name || ("SCUT #" + String(network.id)))
            + "</option>"
        )).join("");
        if (state.selectedNetworkId) {
            select.value = state.selectedNetworkId;
        }
    }

    function renderSummary() {
        const network = state.network || null;
        const name = network && network.name ? network.name : "-";
        const age = network ? formatNetworkAge(network.createdAt) : "-";
        window.VNG.renderMetrics(document.getElementById("scut-summary"), [
            {
                "label": tr("scutNetwork", "Network"),
                "value": name + " - " + age,
            },
            {
                "label": tr("scutRelayCount", "Relays"),
                "value": metricValue(network && network.relayCount),
            },
            {
                "label": tr("scutProbeCount", "Detected probes"),
                "value": metricValue(network && Array.isArray(network.probes) ? network.probes.length : 0),
            },
        ]);
    }

    function knownBlueprints() {
        return Array.isArray(state.blueprints)
            ? state.blueprints.filter((blueprint) => blueprint && blueprint.id && blueprint.available === true)
            : [];
    }

    function blueprintShareRecipients() {
        const ownedProbeIds = new Set((Array.isArray(state.ownedProbeIds) ? state.ownedProbeIds : []).map(String));
        const probes = Array.isArray(state.network && state.network.probes) ? state.network.probes : [];

        return probes.filter((probe) => probe && probe.id && !ownedProbeIds.has(String(probe.id)));
    }

    function renderBlueprintShareForm() {
        const blueprintSelect = document.getElementById("scut-blueprint-share-blueprint");
        const recipientSelect = document.getElementById("scut-blueprint-share-recipient");
        const submit = document.getElementById("scut-blueprint-share-submit");
        const availability = document.getElementById("scut-blueprint-share-availability");
        if (!blueprintSelect || !recipientSelect || !submit) {
            return;
        }

        const previousBlueprint = blueprintSelect.value;
        const previousRecipient = recipientSelect.value;
        const blueprints = knownBlueprints();
        const recipients = blueprintShareRecipients();

        blueprintSelect.innerHTML = blueprints.length > 0
            ? blueprints.map((blueprint) => (
                "<option value=\"" + window.VNG.escapeHtml(String(blueprint.id)) + "\">"
                    + window.VNG.escapeHtml(blueprint.name || String(blueprint.id))
                + "</option>"
            )).join("")
            : "<option value=\"\">" + window.VNG.escapeHtml(tr("scutBlueprintShareNoBlueprints", "No blueprint is available to share.")) + "</option>";
        if (previousBlueprint && blueprints.some((blueprint) => String(blueprint.id) === previousBlueprint)) {
            blueprintSelect.value = previousBlueprint;
        }

        recipientSelect.innerHTML = recipients.length > 0
            ? recipients.map((probe) => (
                "<option value=\"" + window.VNG.escapeHtml(String(probe.id)) + "\">"
                    + window.VNG.escapeHtml((probe.name || tr("unknownProbe", "Unknown probe")) + " — ID " + String(probe.id))
                + "</option>"
            )).join("")
            : "<option value=\"\">" + window.VNG.escapeHtml(tr("scutBlueprintShareNoRecipients", "No other player's probe is reachable through this network.")) + "</option>";
        if (previousRecipient && recipients.some((probe) => String(probe.id) === previousRecipient)) {
            recipientSelect.value = previousRecipient;
        }

        const ready = Boolean(state.network) && blueprints.length > 0 && recipients.length > 0 && !state.sharePending;
        blueprintSelect.disabled = !state.network || blueprints.length === 0 || state.sharePending;
        recipientSelect.disabled = !state.network || recipients.length === 0 || state.sharePending;
        submit.disabled = !ready;
        submit.setAttribute("aria-disabled", ready ? "false" : "true");
        submit.textContent = state.sharePending
            ? tr("scutBlueprintShareSending", "Sharing blueprint...")
            : tr("scutBlueprintShareSubmit", "Share blueprint");

        if (availability) {
            if (!state.network) {
                availability.textContent = tr("scutBlueprintShareRequiresCoverage", "The selected probe must be covered by an active SCUT network.");
            } else if (blueprints.length === 0) {
                availability.textContent = tr("scutBlueprintShareNoBlueprints", "No blueprint is available to share.");
            } else if (recipients.length === 0) {
                availability.textContent = tr("scutBlueprintShareNoRecipients", "No other player's probe is reachable through this network.");
            } else {
                availability.textContent = tr("scutBlueprintShareReady", "The blueprint will become available to every probe owned by the recipient player.");
            }
        }
    }

    function renderProbes() {
        const list = document.getElementById("scut-probes-list");
        const empty = document.getElementById("scut-probes-empty");
        const probes = Array.isArray(state.network && state.network.probes) ? state.network.probes : [];
        if (empty) {
            empty.hidden = probes.length > 0;
        }
        if (!list) {
            return;
        }

        list.innerHTML = probes.map((probe) => {
            const sector = probe && probe.sector && probe.sector.relative ? probe.sector.relative : null;
            return "<article class=\"scut-entry\">"
                + "<div class=\"scut-entry-title\">" + window.VNG.escapeHtml(probe.name || tr("unknownProbe", "Unknown probe")) + "</div>"
                + "<div class=\"scut-entry-meta\">"
                    + "<span>ID " + window.VNG.escapeHtml(probe.id || "-") + "</span>"
                    + "<span>" + window.VNG.escapeHtml(tr("messageSector", "Sector")) + " " + window.VNG.escapeHtml(window.VNG.coordinate(sector)) + "</span>"
                + "</div>"
            + "</article>";
        }).join("");
    }

    function renderRelays() {
        const list = document.getElementById("scut-relays-list");
        const empty = document.getElementById("scut-relays-empty");
        const relays = Array.isArray(state.network && state.network.relays) ? state.network.relays : [];
        if (empty) {
            empty.hidden = relays.length > 0;
        }
        if (!list) {
            return;
        }

        list.innerHTML = relays.map((relay) => {
            const sector = relay && relay.sector && relay.sector.relative ? relay.sector.relative : null;
            return "<article class=\"scut-entry\">"
                + "<div class=\"scut-entry-title\">" + window.VNG.escapeHtml(relay.name || tr("scutRelayObject", "SCUT relay")) + "</div>"
                + "<div class=\"scut-entry-meta\">"
                    + "<span>ID " + window.VNG.escapeHtml(relay.id || "-") + "</span>"
                    + "<span>" + window.VNG.escapeHtml(tr("scutRelativeCoordinates", "Relative coordinates")) + " " + window.VNG.escapeHtml(window.VNG.coordinate(sector)) + "</span>"
                    + "<span>" + window.VNG.escapeHtml(tr("status", "Status")) + " " + window.VNG.escapeHtml(relay.status || "-") + "</span>"
                    + "<span>" + window.VNG.escapeHtml(tr("scutTransitBeaconStatus", "Transit beacon")) + " " + window.VNG.escapeHtml(relay.isTransitBeacon === true ? tr("scutTransitBeaconEquipped", "equipped") : tr("scutTransitBeaconMissing", "not installed")) + "</span>"
                    + "<span>" + window.VNG.escapeHtml(tr("scutRelayActivatedAt", "Activated")) + " " + window.VNG.escapeHtml(formatDate(relay.activatedAt)) + "</span>"
                + "</div>"
            + "</article>";
        }).join("");
    }

    function renderPage() {
        renderNetworkChoice();
        renderSummary();
        renderBlueprintShareForm();
        renderProbes();
        renderRelays();
    }

    async function loadNetworkDetail(networkId) {
        const data = await window.VNG.apiJson(window.VNG.probeApiPath("/scut-network/" + encodeURIComponent(networkId)), {"method": "GET"});
        state.network = data && data.network ? data.network : null;
    }

    async function loadScutPage() {
        if (loadInProgress) {
            return;
        }
        loadInProgress = true;
        window.clearTimeout(refreshTimer);
        refreshTimer = null;

        try {
            window.VNG.setProbeUnreachablePanel?.("scut-panel", false);
            const [sectorData, blueprintData, ownedProbeData] = await Promise.all([
                window.VNG.apiJson(window.VNG.probeApiPath("/sector"), {"method": "GET"}),
                window.VNG.apiJson(window.VNG.probeApiPath("/probe-improvements-available"), {"method": "GET"}),
                window.VNG.loadProbeList(),
            ]);
            state.blueprints = Array.isArray(blueprintData && blueprintData.improvements) ? blueprintData.improvements : [];
            state.ownedProbeIds = Array.isArray(ownedProbeData && ownedProbeData.probes)
                ? ownedProbeData.probes.map((probe) => probe && probe.id).filter(Boolean)
                : [];
            const networks = Array.isArray(sectorData && sectorData.sector && sectorData.sector.scutNetworks)
                ? sectorData.sector.scutNetworks
                : [];
            state.networks = networks;
            window.VNG.setNavigationScutCoverage(networks.length > 0);
            if (networks.length === 0) {
                state.selectedNetworkId = "";
                state.network = null;
                renderPage();
                setStatus(tr("scutNoCoverage", "No SCUT network covers this sector."));
                return;
            }

            const selected = networks.find((network) => String(network.id) === String(state.selectedNetworkId)) || networks[0];
            state.selectedNetworkId = String(selected.id);
            await loadNetworkDetail(state.selectedNetworkId);
            renderPage();
            setStatus("");
        } catch (error) {
            state.networks = [];
            state.network = null;
            renderPage();
            if (!await window.VNG.renderUnreachableProbeTelemetry(error, {"statusId": "scut-status", "panelId": "scut-panel"})) {
                window.VNG.setProbeUnreachablePanel?.("scut-panel", false);
                setStatus(error.message || tr("requestDenied", "Request denied"));
            }
        } finally {
            loadInProgress = false;
            refreshTimer = window.setTimeout(loadScutPage, DEFAULT_REFRESH_MS);
        }
    }

    function bindEvents() {
        document.querySelector("[data-refresh=\"scut\"]")?.addEventListener("click", () => loadScutPage());
        document.getElementById("scut-network-select")?.addEventListener("change", (event) => {
            state.selectedNetworkId = event.target.value || "";
            loadScutPage();
        });
        document.getElementById("scut-blueprint-share-form")?.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (state.sharePending) {
                return;
            }

            const form = new FormData(event.currentTarget);
            const improvementId = String(form.get("blueprint") || "");
            const recipientProbeId = Number.parseInt(String(form.get("recipientProbeId") || ""), 10);
            if (!improvementId || !Number.isInteger(recipientProbeId) || recipientProbeId <= 0) {
                setBlueprintShareStatus(tr("requestDenied", "Request denied"));
                return;
            }

            state.sharePending = true;
            setBlueprintShareStatus(tr("scutBlueprintShareSending", "Sharing blueprint..."));
            renderBlueprintShareForm();
            try {
                const data = await window.VNG.apiJson(
                    window.VNG.probeApiPath("/probe-improvement-blueprints/" + encodeURIComponent(improvementId) + "/share"),
                    {
                        "method": "POST",
                        "body": JSON.stringify({"recipientProbeId": recipientProbeId}),
                    },
                );
                const values = {
                    "blueprint": data && data.blueprint && data.blueprint.name ? data.blueprint.name : improvementId,
                    "probe": data && data.recipientProbe && data.recipientProbe.name ? data.recipientProbe.name : String(recipientProbeId),
                };
                setBlueprintShareStatus(window.VNG.formatText(
                    data && data.alreadyKnown === true
                        ? tr("scutBlueprintShareAlreadyKnown", "{probe}'s player already knew {blueprint}; the notification remains available.")
                        : tr("scutBlueprintShareSuccess", "{blueprint} was shared with {probe}. The recipient player has been notified."),
                    values,
                ));
            } catch (error) {
                setBlueprintShareStatus(error.message || tr("requestDenied", "Request denied"));
            } finally {
                state.sharePending = false;
                renderBlueprintShareForm();
            }
        });
    }

    withVng(async () => {
        i18n = await window.VNG.loadI18n();
        bindEvents();
        renderPage();
        loadScutPage();
    });
})();
