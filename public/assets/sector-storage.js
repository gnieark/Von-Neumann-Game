(function () {
    const PAGE_SIZE = 100;
    const state = {
        targets: [],
        selectedId: "",
        resources: [],
        items: [],
        nextCursor: "",
        probeId: null,
        mannies: [],
        onboardContainers: [],
        requestSequence: 0,
    };
    let i18n = {};

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

    function escaped(value) {
        return window.VNG.escapeHtml(value);
    }

    function setStatus(message) {
        const node = document.getElementById("sector-storage-status");
        if (node) node.textContent = message || "";
    }

    function explicitProbeApiPath(suffix) {
        const probeId = Number(state.probeId);
        if (!Number.isInteger(probeId) || probeId <= 0) {
            throw new Error(tr("unknownProbe", "Unknown probe"));
        }
        const normalizedSuffix = String(suffix || "").startsWith("/") ? String(suffix || "") : "/" + String(suffix || "");
        return "/api/probe/" + encodeURIComponent(String(probeId)) + normalizedSuffix;
    }

    function resourceLabel(type) {
        return {
            "deuterium": tr("deuterium", "Deuterium"),
            "metals": tr("metals", "Metals"),
            "ice": tr("ice", "Ice"),
            "carbon_compounds": tr("carbonCompounds", "Organic compounds"),
            "organic_compounds": tr("carbonCompounds", "Organic compounds"),
        }[type] || type || "-";
    }

    function itemTypeLabel(type) {
        return {
            "atomic_3d_printer": tr("atomicPrinter", "Atomic printer"),
            "waypoint_bookmark": tr("waypointBookmark", "Waypoint bookmark"),
            "steel_bar": tr("steelBar", "Steel bar"),
            "steel_plate": tr("steelPlate", "Steel plate"),
            "additional_container": tr("additionalContainer", "Additional container"),
            "micro_conductor": tr("microConductor", "Micro-etched conductor"),
            "ceramic_insulator": tr("ceramicInsulator", "Ceramo-organic insulator"),
            "crystal_substrate": tr("crystalSubstrate", "Crystal substrate"),
            "dopant_matrix": tr("dopantMatrix", "Dopant matrix"),
            "integrated_circuit": tr("integratedCircuit", "Integrated circuit"),
            "electric_motor": tr("electricMotor", "Electric motor"),
            "battery_pack": tr("batteryPack", "Battery pack"),
            "linear_actuator": tr("linearActuator", "Linear actuator"),
            "atomic_printer_part": tr("atomicPrinterPart", "Atomic printer part"),
            "deuterium_engine": tr("deuteriumEngine", "Deuterium engine"),
            "solar_panel": tr("solarPanel", "Solar panel"),
            "scut_relay": tr("scutRelay", "SCUT relay"),
            "scut_transit_beacon": tr("scutTransitBeacon", "SCUT transit beacon"),
            "missile": tr("missile", "Missile"),
            "manny": tr("mannyObject", "Manny"),
        }[type] || type || "-";
    }

    function storageKindLabel(target) {
        if (target.type === "detached_container" && target.mode === "hidden_on_asteroid") {
            return tr("sectorStorageHidden", "Hidden on an asteroid");
        }
        if (target.type === "detached_container") {
            return tr("sectorStorageDrifting", "Drifting container");
        }
        return tr("sectorStorageOthersOpen", "Open Others storage");
    }

    function storageLabel(target) {
        const name = target.name || target.id;
        return name + " — " + storageKindLabel(target);
    }

    function accessibleStorageTargets(objects) {
        return (Array.isArray(objects) ? objects : [])
            .filter((object) => object && object.id && (
                object.inventoryAccessible === true
                || (object.type === "detached_container" && ["drifting", "hidden_on_asteroid"].includes(object.mode))
            ))
            .sort((left, right) => storageLabel(left).localeCompare(storageLabel(right)));
    }

    function renderTargetOptions() {
        const select = document.getElementById("sector-storage-select");
        if (!select) return;
        if (state.targets.length === 0) {
            select.innerHTML = '<option value="">' + escaped(tr("sectorStorageNone", "No accessible storage in this sector.")) + "</option>";
            select.disabled = true;
            return;
        }
        select.innerHTML = state.targets.map((target) => (
            '<option value="' + escaped(target.id) + '"' + (target.id === state.selectedId ? " selected" : "") + ">"
            + escaped(storageLabel(target))
            + "</option>"
        )).join("");
        select.disabled = false;
    }

    function idleMannies() {
        return state.mannies.filter((manny) => (
            manny
            && manny.id
            && manny.currentTask === null
            && manny.canReceiveOrders !== false
            && manny.location
            && manny.location.type === "probe"
        ));
    }

    function onboardContainerLabel(container) {
        if (container && (container.id === "probe-core" || container.kind === "probe")) {
            return tr("probeCoreContainer", "Probe");
        }
        return container && (container.label || container.id)
            ? (container.label || container.id)
            : tr("unknownContainer", "Unknown container");
    }

    function retrieveIcon() {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11"></path><path d="m7.5 10 4.5 4.5 4.5-4.5"></path><path d="M5 19h14"></path></svg>';
    }

    function retrieveButton(kind, id, available, disabled) {
        const isResource = kind === "resources";
        const label = isResource
            ? tr("sectorStorageRetrieveResource", "Retrieve this resource")
            : tr("sectorStorageRetrieveItem", "Retrieve this item");
        return '<button class="inventory-icon-button sector-storage-retrieve-button" type="button"'
            + ' data-retrieve-kind="' + escaped(kind) + '" data-retrieve-id="' + escaped(id) + '"'
            + (isResource ? ' data-retrieve-available="' + escaped(available) + '"' : "")
            + (disabled ? ' disabled aria-disabled="true"' : ' aria-expanded="false"')
            + ' title="' + escaped(label) + '" aria-label="' + escaped(label) + '">' + retrieveIcon() + "</button>";
    }

    function retrievalUnavailable(kind, available) {
        return idleMannies().length === 0
            || (kind !== "deuterium" && state.onboardContainers.length === 0)
            || !(available > 0);
    }

    function resourceRows() {
        if (state.resources.length === 0) {
            return '<p class="sector-storage-empty">' + escaped(tr("sectorStorageNoResources", "No stored resources.")) + "</p>";
        }
        return '<div class="sector-storage-lines">' + state.resources.map((resource) => {
            const amount = Math.max(0, Number(resource.amount) || 0);
            const reserved = Math.max(0, Number(resource.reservedAmount) || 0);
            const available = Math.max(0, Number(resource.availableAmount ?? (amount - reserved)) || 0);
            return '<div class="sector-storage-line">'
                + retrieveButton("resources", resource.type || "", available, retrievalUnavailable(resource.type, available))
                + '<div class="sector-storage-line-name"><strong>' + escaped(resourceLabel(resource.type)) + '</strong><small>' + escaped(resource.type || "") + "</small></div>"
                + '<dl><div><dt>' + escaped(tr("storedAmount", "Amount")) + '</dt><dd>' + escaped(window.VNG.numberValue(amount)) + ' ECE</dd></div>'
                + '<div><dt>' + escaped(tr("sectorStorageAvailable", "Available")) + '</dt><dd>' + escaped(window.VNG.numberValue(available)) + ' ECE</dd></div>'
                + (reserved > 0 ? '<div><dt>' + escaped(tr("sectorStorageReserved", "Reserved")) + '</dt><dd>' + escaped(window.VNG.numberValue(reserved)) + ' ECE</dd></div>' : "")
                + '</dl><div class="sector-storage-retrieve-slot"></div></div>';
        }).join("") + "</div>";
    }

    function itemRows() {
        if (state.items.length === 0) {
            return '<p class="sector-storage-empty">' + escaped(tr("sectorStorageNoItems", "No stored items.")) + "</p>";
        }
        return '<div class="sector-storage-lines">' + state.items.map((item) => (
            '<div class="sector-storage-line">'
                + retrieveButton("items", item.id || "", item.available === false ? 0 : 1, retrievalUnavailable("items", item.available === false ? 0 : 1))
                + '<div class="sector-storage-line-name"><strong>' + escaped(item.name || itemTypeLabel(item.type)) + '</strong><small>' + escaped(itemTypeLabel(item.type)) + "</small></div>"
                + '<dl><div><dt>' + escaped(tr("containerSpace", "Space")) + '</dt><dd>' + escaped(window.VNG.numberValue(item.containerSpace)) + ' ECE</dd></div>'
                + '<div><dt>' + escaped(tr("status", "Status")) + '</dt><dd>' + escaped(item.available === false ? tr("sectorStorageReserved", "Reserved") : tr("sectorStorageAvailable", "Available")) + "</dd></div></dl>"
                + '<div class="sector-storage-retrieve-slot"></div></div>'
        )).join("") + "</div>";
    }

    function mannyOptions() {
        return idleMannies().map((manny) => (
            '<option value="' + escaped(manny.id) + '">' + escaped(manny.name || manny.id) + "</option>"
        )).join("");
    }

    function containerOptions() {
        return state.onboardContainers.map((container) => (
            '<option value="' + escaped(container.id) + '">' + escaped(onboardContainerLabel(container)) + "</option>"
        )).join("");
    }

    function retrievalForm(button) {
        const kind = button.dataset.retrieveKind;
        const id = button.dataset.retrieveId || "";
        const available = Math.max(0, Number(button.dataset.retrieveAvailable) || 0);
        const deuterium = kind === "resources" && id === "deuterium";
        return '<form class="sector-storage-retrieve-form" data-retrieve-kind="' + escaped(kind) + '" data-retrieve-id="' + escaped(id) + '"'
            + (kind === "resources" ? ' data-retrieve-available="' + escaped(available) + '"' : "") + '>'
            + '<label>' + escaped(tr("actorManny", "Manny")) + '<select name="mannyId" required>' + mannyOptions() + "</select></label>"
            + (!deuterium ? '<label>' + escaped(tr("onboardContainer", "Onboard container")) + '<select name="containerId" required>' + containerOptions() + "</select></label>" : "")
            + (kind === "resources" ? '<label>' + escaped(tr("quantity", "Quantity")) + '<span class="sector-storage-quantity"><input name="amount" type="number" min="0.0001" max="' + escaped(available) + '" step="0.0001" required><span>ECE</span></span></label>' : "")
            + '<div class="sector-storage-retrieve-actions"><button type="submit">' + escaped(deuterium ? tr("startExternalDeuteriumTransfer", "Refuel tank") : tr("sectorStorageRetrieve", "Retrieve")) + '</button>'
            + '<button class="sector-storage-retrieve-cancel" type="button">' + escaped(tr("cancel", "Cancel")) + "</button></div>"
            + (deuterium ? '<p>' + escaped(tr("externalDeuteriumDuration", "Duration: five minutes outbound and five minutes returning.")) + "</p>" : "")
            + "</form>";
    }

    function closeRetrievalForms() {
        document.querySelectorAll(".sector-storage-retrieve-form").forEach((form) => form.remove());
        document.querySelectorAll(".sector-storage-retrieve-button[aria-expanded]").forEach((button) => button.setAttribute("aria-expanded", "false"));
    }

    function toggleRetrievalForm(button) {
        const wasOpen = button.getAttribute("aria-expanded") === "true";
        closeRetrievalForms();
        if (wasOpen || button.disabled) return;
        const slot = button.closest(".sector-storage-line")?.querySelector(".sector-storage-retrieve-slot");
        if (!slot) return;
        slot.innerHTML = retrievalForm(button);
        button.setAttribute("aria-expanded", "true");
        slot.querySelector("select, input")?.focus();
    }

    async function submitRetrievalForm(form) {
        const formData = new FormData(form);
        const mannyId = String(formData.get("mannyId") || "");
        const kind = form.dataset.retrieveKind;
        const id = form.dataset.retrieveId || "";
        const deuterium = kind === "resources" && id === "deuterium";
        let payload;
        let endpoint;
        if (kind === "resources") {
            const amount = Number(formData.get("amount"));
            const available = Number(form.dataset.retrieveAvailable || 0);
            if (!Number.isFinite(amount) || amount <= 0 || amount > available) {
                setStatus(tr("sectorStorageInvalidQuantity", "Enter an available quantity greater than zero."));
                return;
            }
            payload = deuterium
                ? {objectId: state.selectedId, amount}
                : {objectId: state.selectedId, direction: "from_storage", containerId: String(formData.get("containerId") || ""), kind, resources: {[id]: amount}};
            endpoint = deuterium ? "/transfer-deuterium-from-external-storage" : "/storage-transfers";
        } else {
            payload = {objectId: state.selectedId, direction: "from_storage", containerId: String(formData.get("containerId") || ""), kind: "items", itemIds: [id]};
            endpoint = "/storage-transfers";
        }
        const body = JSON.stringify(payload);
        if (form.dataset.retrieveRequest !== body) {
            form.dataset.retrieveRequest = body;
            form.dataset.retrieveKey = crypto.randomUUID();
        }
        const submit = form.querySelector('button[type="submit"]');
        if (submit) submit.disabled = true;
        setStatus(tr("sectorStorageRetrievalSending", "Sending the Manny..."));
        try {
            const response = await window.VNG.apiJson(explicitProbeApiPath("/mannies/" + encodeURIComponent(mannyId) + endpoint), {
                method: "POST",
                headers: {"Idempotency-Key": form.dataset.retrieveKey},
                body,
            });
            const tankTransfer = response && response.transfer ? response.transfer.tankTransfer : null;
            await loadStorages();
            let message = tr("sectorStorageRetrievalAccepted", "Retrieval order accepted.");
            if (tankTransfer && tankTransfer.clamped) {
                message += " " + tr("sectorStorageRefuelClamped", "Tank capacity limited the transfer to {amount} ECE.")
                    .replace("{amount}", window.VNG.numberValue(tankTransfer.acceptedAmountEce));
            }
            setStatus(message);
        } catch (error) {
            if (submit) submit.disabled = false;
            setStatus((error && error.message) || tr("requestDenied", "Request denied"));
        }
    }

    function renderInventory() {
        const container = document.getElementById("sector-storage-inventory");
        if (!container) return;
        if (!state.selectedId) {
            container.innerHTML = '<p class="sector-storage-empty">' + escaped(tr("sectorStorageNone", "No accessible storage in this sector.")) + "</p>";
            return;
        }
        const target = state.targets.find((entry) => entry.id === state.selectedId);
        container.innerHTML = '<div class="sector-storage-selected"><strong>' + escaped(target ? (target.name || target.id) : state.selectedId) + '</strong><span>' + escaped(target ? storageKindLabel(target) : "") + "</span></div>"
            + '<section><h3>' + escaped(tr("resources", "Resources")) + "</h3>" + resourceRows() + "</section>"
            + '<section><h3>' + escaped(tr("items", "Items")) + "</h3>" + itemRows()
            + (state.nextCursor ? '<button id="sector-storage-next" type="button">' + escaped(tr("nextPage", "Next page")) + "</button>" : "")
            + "</section>";
        document.getElementById("sector-storage-next")?.addEventListener("click", () => loadInventory(false));
    }

    async function loadInventory(reset, retryAfterChange) {
        const objectId = state.selectedId;
        if (!objectId) return;
        const sequence = ++state.requestSequence;
        if (reset) {
            state.resources = [];
            state.items = [];
            state.nextCursor = "";
            renderInventory();
        }
        setStatus(tr("sectorStorageInventoryLoading", "Loading inventory..."));
        try {
            const query = new URLSearchParams({"limit": String(PAGE_SIZE)});
            if (!reset && state.nextCursor) query.set("cursor", state.nextCursor);
            const data = await window.VNG.apiJson(explicitProbeApiPath(
                "/sector-objects/" + encodeURIComponent(objectId) + "/inventory?" + query.toString()
            ));
            if (sequence !== state.requestSequence || objectId !== state.selectedId) return;
            if (reset) state.resources = Array.isArray(data.resources) ? data.resources : [];
            state.items = reset ? (Array.isArray(data.items) ? data.items : []) : state.items.concat(Array.isArray(data.items) ? data.items : []);
            state.nextCursor = typeof data.nextCursor === "string" ? data.nextCursor : "";
            renderInventory();
            setStatus("");
        } catch (error) {
            if (sequence !== state.requestSequence || objectId !== state.selectedId) return;
            if (!reset && retryAfterChange !== false && error && error.errorCode === "inventory_changed") {
                await loadInventory(true, false);
                return;
            }
            setStatus((error && error.message) || tr("storageUnavailable", "Storage is unavailable."));
        }
    }

    async function loadStorages() {
        const previousId = state.selectedId;
        const sequence = ++state.requestSequence;
        setStatus(tr("sectorStorageLoading", "Loading sector storage..."));
        try {
            const probeData = await window.VNG.apiJson(window.VNG.probeApiPath(""), {"method": "GET"});
            if (sequence !== state.requestSequence) return;
            const probeId = Number(probeData && probeData.probe && probeData.probe.id);
            if (!Number.isInteger(probeId) || probeId <= 0) {
                throw new Error(tr("unknownProbe", "Unknown probe"));
            }
            state.probeId = probeId;
            const [data, mannyData] = await Promise.all([
                window.VNG.apiJson(explicitProbeApiPath("/sector"), {"method": "GET"}),
                window.VNG.apiJson(explicitProbeApiPath("/mannies"), {"method": "GET"}),
            ]);
            if (sequence !== state.requestSequence) return;
            const sector = data && data.sector ? data.sector : {};
            state.mannies = Array.isArray(mannyData && mannyData.mannies) ? mannyData.mannies : [];
            state.onboardContainers = Array.isArray(probeData && probeData.probe && probeData.probe.inventory && probeData.probe.inventory.containers)
                ? probeData.probe.inventory.containers
                : [];
            state.targets = accessibleStorageTargets(sector.objects);
            state.selectedId = state.targets.some((target) => target.id === previousId)
                ? previousId
                : (state.targets[0]?.id || "");
            state.resources = [];
            state.items = [];
            state.nextCursor = "";
            renderTargetOptions();
            renderInventory();
            if (state.selectedId) {
                await loadInventory(true);
            } else {
                setStatus(tr("sectorStorageNone", "No accessible storage in this sector."));
            }
        } catch (error) {
            if (sequence !== state.requestSequence) return;
            state.targets = [];
            state.selectedId = "";
            state.probeId = null;
            state.mannies = [];
            state.onboardContainers = [];
            renderTargetOptions();
            renderInventory();
            if (!await window.VNG.renderUnreachableProbeTelemetry(error, {"panelId": "sector-storage-panel", "statusId": "sector-storage-status"})) {
                setStatus((error && error.message) || tr("requestDenied", "Request denied"));
            }
        }
    }

    function bindPage() {
        const select = document.getElementById("sector-storage-select");
        select?.addEventListener("change", () => {
            state.selectedId = select.value || "";
            loadInventory(true);
        });
        document.querySelector('[data-refresh="sector-storage"]')?.addEventListener("click", loadStorages);
        document.getElementById("sector-storage-inventory")?.addEventListener("click", (event) => {
            const retrieveButton = event.target.closest(".sector-storage-retrieve-button");
            if (retrieveButton) {
                toggleRetrievalForm(retrieveButton);
                return;
            }
            if (event.target.closest(".sector-storage-retrieve-cancel")) closeRetrievalForms();
        });
        document.getElementById("sector-storage-inventory")?.addEventListener("submit", (event) => {
            const form = event.target.closest(".sector-storage-retrieve-form");
            if (!form) return;
            event.preventDefault();
            submitRetrievalForm(form);
        });
        loadStorages();
    }

    withVng(async () => {
        i18n = await window.VNG.loadI18n();
        bindPage();
    });
}());
