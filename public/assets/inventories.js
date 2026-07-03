(function () {
    const DEFAULT_REFRESH_MS = 15000;
    const MIN_REFRESH_MS = 750;
    const REFRESH_CUSHION_MS = 500;
    const STORAGE_RULES_IDLE_PRESERVE_MS = 60000;

    const state = {
        currentInventory: null,
        currentMannies: [],
        currentSectorObjects: [],
        inventoryContainerFilter: "all",
        storageRulesDirty: false,
        storageRulesTouchedAt: 0,
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

    function runApiOrder(statusId, pendingText, request, onSuccess) {
        setText(statusId, pendingText);
        return request()
            .then((data) => {
                if (onSuccess) {
                    return onSuccess(data);
                }
                return data;
            })
            .catch((error) => {
                setText(statusId, error.message || tr("requestDenied", "Request denied"));
            });
    }

    function resourceTypeLabel(type) {
        return {
            "deuterium": tr("deuterium", "Deuterium"),
            "metals": tr("metals", "Metals"),
            "ice": tr("ice", "Ice"),
            "carbon_compounds": tr("carbonCompounds", "Carbon compounds"),
            "organic_compounds": tr("carbonCompounds", "Carbon compounds"),
            "other": tr("carbonCompounds", "Carbon compounds"),
        }[type] || type || "-";
    }

    function inventoryItemTypeLabel(type, fallback) {
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
            "manny": tr("mannyObject", "Manny"),
        }[type] || fallback || type || "-";
    }

    function taskLabel(task) {
        return {
            "repair": tr("repair", "Repair"),
            "mining": tr("mine", "Mine"),
            "crafting": tr("craft", "Craft"),
            "salvage": tr("salvage", "Salvage"),
            "returning": tr("returning", "Returning"),
            "waiting_for_space": tr("waitingForSpace", "Waiting for space"),
            "moving_stockage": tr("movingStorage", "Moving storage"),
            "moving_storage": tr("movingStorage", "Moving storage"),
            "installing_waypoint_bookmark": tr("installingWaypointBookmark", "Installing waypoint bookmark"),
            "detaching_storage_container": tr("detachingStorageContainer", "Detaching storage container"),
            "inspecting_sector_object": tr("inspectingSectorObject", "Inspecting sector object"),
            "inspecting_asteroid": tr("inspectingSectorObject", "Inspecting sector object"),
            "assisting_atomic_printer": tr("assistingAtomicPrinter", "Assisting the atomic printer"),
            "atomic_printing": tr("atomicPrinting", "Atomic printing"),
        }[task] || task || tr("noTask", "None");
    }

    function locationTypeLabel(type) {
        return {
            "probe": tr("tabProbe", "Probe"),
            "sector": tr("sector", "Sector"),
        }[type] || type || "-";
    }

    function objectTypeLabel(type) {
        return {
            "asteroid": tr("asteroidObject", "Asteroid"),
            "planet": tr("planetObject", "Planet"),
            "star": tr("starObject", "Star"),
            "solar_system": tr("solarSystemObject", "Solar system"),
            "black_hole": tr("blackHoleObject", "Black hole"),
        }[type] || type || tr("object", "Object");
    }

    function storageCapacityValue(inventory) {
        const used = Number(inventory && inventory.usedCapacity);
        const capacity = Number(inventory && inventory.capacity);
        const unit = (inventory && inventory.capacityUnit) === "earth_container_equivalent" ? "ECE" : String((inventory && inventory.capacityUnit) || "ECE");
        if (!Number.isFinite(used) || !Number.isFinite(capacity)) {
            return "-";
        }

        return window.VNG.numberValue(used) + " / " + window.VNG.numberValue(capacity) + " " + unit;
    }

    function containerLabel(container) {
        if (container && (container.id === "probe-core" || container.kind === "probe")) {
            if (container.label && container.label !== "Sonde") {
                return container.label;
            }
            return tr("probeCoreContainer", "Probe");
        }

        return container && (container.label || container.id)
            ? (container.label || container.id)
            : tr("unknownContainer", "Unknown container");
    }

    function inventoryContainers(inventory) {
        return Array.isArray(inventory && inventory.containers) ? inventory.containers : [];
    }

    function selectedContainerId() {
        return state.inventoryContainerFilter || "all";
    }

    function selectedInventoryContainer(inventory) {
        const id = selectedContainerId();
        if (id === "all") {
            return null;
        }

        return inventoryContainers(inventory).find((container) => container.id === id) || null;
    }

    function itemContainer(item) {
        return item && item.container ? item.container : null;
    }

    function itemMatchesContainerFilter(item) {
        return selectedContainerId() === "all"
            || (itemContainer(item) && itemContainer(item).id === selectedContainerId());
    }

    function inventoryItemName(item) {
        return item && item.type
            ? inventoryItemTypeLabel(item.type, item.name || item.type)
            : (item && (item.name || item.id)) || "-";
    }

    function inventoryEntryDetail(entry) {
        const details = [
            tr("containerSpace", "Space") + " " + window.VNG.numberValue(entry.containerSpace || 0),
        ];
        if (entry.location && entry.location.type) {
            details.push(tr("location", "Location") + " " + locationTypeLabel(entry.location.type));
        }
        if (entry.currentTask) {
            details.push(tr("task", "Task") + " " + taskLabel(entry.currentTask));
        }

        return details.join(" - ");
    }

    function stockPlacements(stock) {
        const placements = Array.isArray(stock && stock.containers) ? stock.containers : [];
        if (placements.length === 0 && Number(stock && stock.amount) > 0) {
            return [{
                "container": stock.container || null,
                "amount": Number(stock.amount),
                "containerSpace": Number(stock.containerSpace || stock.amount),
            }];
        }

        return placements;
    }

    function filteredStockPlacements(stock) {
        return stockPlacements(stock).filter((placement) => (
            selectedContainerId() === "all"
            || (placement.container && placement.container.id === selectedContainerId())
        ));
    }

    function isJettisonableItem(item) {
        if (item.type === "additional_container") {
            return true;
        }
        if (item.type === "manny") {
            return item.location && item.location.type === "probe" && item.currentTask === null;
        }

        return [
            "waypoint_bookmark",
            "steel_bar",
            "steel_plate",
            "micro_conductor",
            "ceramic_insulator",
            "crystal_substrate",
            "dopant_matrix",
            "integrated_circuit",
            "scut_relay",
            "electric_motor",
            "battery_pack",
            "linear_actuator",
        ].includes(item.type);
    }

    function isMovableItem(item) {
        return item
            && item.type !== "atomic_3d_printer"
            && item.container
            && item.container.id
            && !(item.metadata && item.metadata.movable === false);
    }

    function lineIcon(name) {
        return {
            "move": "<svg viewBox=\"0 0 24 24\" aria-hidden=\"true\"><path d=\"M5 12h14\"></path><path d=\"m13 6 6 6-6 6\"></path><path d=\"M5 5v14\"></path></svg>",
            "jettison": "<svg viewBox=\"0 0 24 24\" aria-hidden=\"true\"><path d=\"M10 11 4 5\"></path><path d=\"m5 10-1-5 5 1\"></path><path d=\"M14 13l6 6\"></path><path d=\"m19 14 1 5-5-1\"></path><path d=\"m14 11 6-6\"></path><path d=\"m19 10 1-5-5 1\"></path><path d=\"M10 13l-6 6\"></path><path d=\"m5 14-1 5 5-1\"></path></svg>",
            "settings": "<svg viewBox=\"0 0 24 24\" aria-hidden=\"true\"><path d=\"M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z\"></path><path d=\"M19.4 15a1.8 1.8 0 0 0 .36 1.98l.04.04a2.1 2.1 0 0 1-2.97 2.97l-.04-.04a1.8 1.8 0 0 0-1.98-.36 1.8 1.8 0 0 0-1.09 1.65V21.3a2.1 2.1 0 0 1-4.2 0v-.06a1.8 1.8 0 0 0-1.09-1.65 1.8 1.8 0 0 0-1.98.36l-.04.04a2.1 2.1 0 0 1-2.97-2.97l.04-.04A1.8 1.8 0 0 0 3.84 15a1.8 1.8 0 0 0-1.65-1.09H2.1a2.1 2.1 0 0 1 0-4.2h.09a1.8 1.8 0 0 0 1.65-1.09 1.8 1.8 0 0 0-.36-1.98l-.04-.04a2.1 2.1 0 0 1 2.97-2.97l.04.04a1.8 1.8 0 0 0 1.98.36 1.8 1.8 0 0 0 1.09-1.65V2.1a2.1 2.1 0 0 1 4.2 0v.28a1.8 1.8 0 0 0 1.09 1.65 1.8 1.8 0 0 0 1.98-.36l.04-.04A2.1 2.1 0 0 1 19.8 6.6l-.04.04a1.8 1.8 0 0 0-.36 1.98 1.8 1.8 0 0 0 1.65 1.09h.85a2.1 2.1 0 0 1 0 4.2h-.85A1.8 1.8 0 0 0 19.4 15Z\"></path></svg>",
        }[name] || "";
    }

    function iconButton(className, label, icon, disabled, visibleLabel, attributes) {
        return "<button class=\"inventory-icon-button " + className + "\" type=\"button\" title=\"" + window.VNG.escapeHtml(label) + "\" aria-label=\"" + window.VNG.escapeHtml(label) + "\""
            + (disabled ? " disabled aria-disabled=\"true\"" : "")
            + (attributes ? " " + attributes : "")
            + ">" + lineIcon(icon) + "<span class=\"inventory-icon-label\">" + window.VNG.escapeHtml(visibleLabel || label) + "</span></button>";
    }

    function iconButtonPlaceholder(className) {
        return "<span class=\"inventory-icon-button inventory-icon-button-placeholder " + className + "\" aria-hidden=\"true\"></span>";
    }

    function lineActionFlags(action, placement) {
        if (!action) {
            return {"canMove": false, "canJettison": false};
        }
        const hasContainer = Boolean(placement.container && placement.container.id);
        if (action.kind === "resource") {
            const amount = Number(placement.amount || 0);
            return {
                "canMove": hasContainer && amount > 0,
                "canJettison": hasContainer && amount > 0,
            };
        }

        const items = Array.isArray(placement.items) ? placement.items : [];
        return {
            "canMove": hasContainer && items.length > 0 && items.every(isMovableItem),
            "canJettison": items.length > 0 && items.every(isJettisonableItem),
        };
    }

    function lineActionAttributes(action, placement) {
        if (!action) {
            return "";
        }

        const attributes = [
            "data-line-kind=\"" + window.VNG.escapeHtml(action.kind) + "\"",
            "data-container-id=\"" + window.VNG.escapeHtml((placement.container && placement.container.id) || "") + "\"",
        ];
        if (action.kind === "resource") {
            attributes.push("data-stock-id=\"" + window.VNG.escapeHtml(action.itemId || action.resourceType || "") + "\"");
            attributes.push("data-resource-type=\"" + window.VNG.escapeHtml(action.resourceType || "") + "\"");
            attributes.push("data-max-amount=\"" + window.VNG.escapeHtml(String(Number(placement.amount || 0))) + "\"");
            return attributes.join(" ");
        }

        const itemIds = Array.isArray(placement.itemIds) ? placement.itemIds : [];
        attributes.push("data-item-type=\"" + window.VNG.escapeHtml(action.itemType || action.kind) + "\"");
        attributes.push("data-item-ids=\"" + window.VNG.escapeHtml(itemIds.join(",")) + "\"");
        attributes.push("data-max-quantity=\"" + window.VNG.escapeHtml(String(itemIds.length || Number(placement.quantity || 0))) + "\"");

        return attributes.join(" ");
    }

    function renderLineActions(action, placement) {
        const flags = lineActionFlags(action, placement);
        const isAdditionalContainer = action && action.kind === "item" && action.itemType === "additional_container";
        const jettisonLabel = isAdditionalContainer
            ? tr("detachStorageContainer", "Detach container")
            : tr("jettisonLine", "Jettison line");
        const jettisonVisibleLabel = isAdditionalContainer
            ? tr("detachStorageContainerShort", "Detach")
            : tr("jettison", "Jettison");

        return "<span class=\"inventory-line-controls\">"
            + (isAdditionalContainer ? iconButtonPlaceholder("inventory-line-move-placeholder") : iconButton("inventory-line-move", tr("moveStorageLine", "Move"), "move", !flags.canMove))
            + iconButton("inventory-line-jettison", jettisonLabel, "jettison", !flags.canJettison, jettisonVisibleLabel)
            + "</span>"
            + "<div class=\"inventory-line-form-slot\"></div>";
    }

    function placementLines(placements, valueKey, action) {
        if (placements.length === 0) {
            return "<p class=\"inventory-muted\">" + window.VNG.escapeHtml(tr("emptyContainerLine", "No stock in this container.")) + "</p>";
        }

        return "<ul class=\"inventory-container-lines\">"
            + placements.map((placement) => (
                "<li class=\"inventory-container-line\" " + lineActionAttributes(action, placement) + ">"
                    + "<span class=\"inventory-line-container\">" + window.VNG.escapeHtml(containerLabel(placement.container)) + "</span>"
                    + inventoryLineReadouts(placement, valueKey)
                    + renderLineActions(action, placement)
                + "</li>"
            )).join("")
            + "</ul>";
    }

    function renderSystemsSummary(probe) {
        const systems = probe && probe.systems ? probe.systems : {};
        window.VNG.renderMetrics(document.getElementById("systems-summary"), [
            {"name": "integrity", "label": tr("integrity", "Integrity"), "value": window.VNG.numberValue(systems.integrityPercent, "%")},
            {"name": "energy", "label": tr("energy", "Energy"), "value": window.VNG.numberValue(systems.energyStored)},
            {"name": "storage", "label": tr("storageCapacity", "Storage capacity"), "value": storageCapacityValue(probe && probe.inventory)},
            {"name": "clock", "label": tr("internalClock", "Internal clock"), "value": window.VNG.numberValue(systems.internalClockRate)},
            {"name": "task", "label": tr("task", "Task"), "value": systems.currentTask ? taskLabel(systems.currentTask) : tr("noTask", "None")},
        ]);
    }

    function splitRuleValue(value) {
        return String(value || "").split(",").map((entry) => entry.trim()).filter(Boolean);
    }

    function storageRuleValues(formData, name) {
        return formData.getAll(name).flatMap(splitRuleValue);
    }

    const defaultStorageRuleTypes = [
        "metals",
        "ice",
        "carbon_compounds",
        "manny",
        "waypoint_bookmark",
        "steel_bar",
        "steel_plate",
        "additional_container",
        "micro_conductor",
        "ceramic_insulator",
        "crystal_substrate",
        "dopant_matrix",
        "integrated_circuit",
        "electric_motor",
        "battery_pack",
        "linear_actuator",
    ];

    function storageRuleTypeLabel(type, fallback) {
        if (["metals", "ice", "carbon_compounds"].includes(type)) {
            return resourceTypeLabel(type);
        }

        return inventoryItemTypeLabel(type, fallback || type);
    }

    function addStorageRuleOption(options, type, label) {
        const value = String(type || "").trim();
        if (!value || options.has(value)) {
            return;
        }
        options.set(value, label || storageRuleTypeLabel(value));
    }

    function storageRuleOptions(inventory, containers) {
        const options = new Map();
        defaultStorageRuleTypes.forEach((type) => addStorageRuleOption(options, type));
        (Array.isArray(inventory && inventory.resourceStocks) ? inventory.resourceStocks : []).forEach((stock) => {
            addStorageRuleOption(options, stock.type, resourceTypeLabel(stock.type));
        });
        (Array.isArray(inventory && inventory.items) ? inventory.items : []).forEach((item) => {
            addStorageRuleOption(options, item.type, storageRuleTypeLabel(item.type, item.name || item.type));
        });
        containers.forEach((container) => {
            const rules = container.rules || {};
            ["priority", "exclusion", "strictExclusion"].forEach((name) => {
                (Array.isArray(rules[name]) ? rules[name] : []).forEach((type) => addStorageRuleOption(options, type));
            });
        });

        return Array.from(options, ([value, label]) => ({"value": value, "label": label}));
    }

    function syncStorageRuleForm(form, changedSelect) {
        if (!form) {
            return;
        }
        const selects = Array.from(form.querySelectorAll(".storage-rule-select"));
        if (changedSelect) {
            const selectedValues = new Set(Array.from(changedSelect.selectedOptions).map((option) => option.value));
            selects.filter((select) => select !== changedSelect).forEach((select) => {
                Array.from(select.options).forEach((option) => {
                    if (selectedValues.has(option.value)) {
                        option.selected = false;
                    }
                });
            });
            return;
        }

        const seen = new Set();
        selects.forEach((select) => {
            Array.from(select.options).forEach((option) => {
                if (!option.selected) {
                    return;
                }
                if (seen.has(option.value)) {
                    option.selected = false;
                    return;
                }
                seen.add(option.value);
            });
        });
    }

    function markStorageRulesTouched() {
        state.storageRulesTouchedAt = Date.now();
    }

    function storageRulesAreActive() {
        const node = document.getElementById("storage-rules-panel");
        const active = document.activeElement;
        return Boolean(
            node
            && active instanceof Element
            && node.contains(active)
            && active.matches("select, input, button, textarea")
        );
    }

    function storageRulesDetailsOpen() {
        const node = document.getElementById("storage-rules-panel");
        const details = node ? node.querySelector(".storage-rules") : null;

        return Boolean(details && details.open);
    }

    function shouldPreserveStorageRules() {
        return Boolean(
            state.storageRulesDirty
            || storageRulesAreActive()
            || storageRulesDetailsOpen()
            || (state.storageRulesTouchedAt > 0 && Date.now() - state.storageRulesTouchedAt < STORAGE_RULES_IDLE_PRESERVE_MS)
        );
    }

    function renderStorageRules(inventory) {
        const node = document.getElementById("storage-rules-panel");
        if (!node) {
            return;
        }

        const containers = inventoryContainers(inventory);
        state.storageRulesDirty = false;
        state.storageRulesTouchedAt = 0;

        if (!inventory || containers.length === 0) {
            node.innerHTML = "";
            return;
        }

        const filterOptions = ["<option value=\"all\">" + window.VNG.escapeHtml(tr("allContainers", "All containers")) + "</option>"]
            .concat(containers.map((container) => (
                "<option value=\"" + window.VNG.escapeHtml(container.id) + "\"" + (selectedContainerId() === container.id ? " selected" : "") + ">"
                + window.VNG.escapeHtml(containerLabel(container))
                + "</option>"
            )));
        const ruleOptions = storageRuleOptions(inventory, containers);
        const ruleField = (container, name, label) => {
            const rules = container.rules || {};
            const selectedValues = Array.isArray(rules[name]) ? rules[name] : [];
            return "<label><span>" + window.VNG.escapeHtml(label) + "</span>"
                + "<select class=\"storage-rule-select\" name=\"" + window.VNG.escapeHtml(name) + "\" multiple size=\"" + window.VNG.escapeHtml(String(Math.min(Math.max(ruleOptions.length, 4), 8))) + "\">"
                + ruleOptions.map((option) => (
                    "<option value=\"" + window.VNG.escapeHtml(option.value) + "\""
                    + (selectedValues.includes(option.value) ? " selected" : "")
                    + ">" + window.VNG.escapeHtml(option.label) + "</option>"
                )).join("")
                + "</select></label>";
        };

        node.innerHTML = "<details class=\"storage-rules\">"
            + "<summary>" + window.VNG.escapeHtml(tr("manageStorageRules", "Manage storage rules by container")) + "</summary>"
            + "<p class=\"storage-rules-help\">" + window.VNG.escapeHtml(tr("storageRulesHelp", "Priority routes matching new items to this container first unless it is full. Exclusion avoids this container unless no other non-strict container can accept the item. Strict exclusion prevents automatic placement into this container.")) + "</p>"
            + "<div class=\"storage-container-list\">"
            + containers.map((container) => (
                "<form class=\"storage-rules-form\" data-container-id=\"" + window.VNG.escapeHtml(container.id) + "\">"
                + "<div><span>" + window.VNG.escapeHtml(containerLabel(container)) + "</span><b>"
                + window.VNG.escapeHtml(window.VNG.numberValue(container.usedCapacity || 0) + " / " + window.VNG.numberValue(container.capacity || 0))
                + "</b></div>"
                + "<div class=\"storage-rule-fields\">"
                + ruleField(container, "priority", tr("storagePriorityFilter", "Priority"))
                + ruleField(container, "exclusion", tr("storageExclusionFilter", "Exclusion"))
                + ruleField(container, "strictExclusion", tr("storageStrictExclusionFilter", "Strict exclusion"))
                + "</div>"
                + "<button type=\"submit\">" + window.VNG.escapeHtml(tr("saveStorageRules", "Save rules")) + "</button>"
                + "</form>"
            )).join("")
            + "</div>"
            + "</details>"
            + "<div class=\"inventory-filter-row\">"
            + "<label>" + window.VNG.escapeHtml(tr("inventoryContainerFilter", "Inventory view"))
            + "<select id=\"inventory-container-filter\">" + filterOptions.join("") + "</select></label>"
            + iconButton(
                "inventory-container-rename-button",
                tr("renameStorageContainer", "Rename container"),
                "settings",
                false,
                tr("renameStorageContainer", "Rename container"),
                selectedContainerId() === "all" ? "hidden" : "",
            )
            + "<form class=\"inventory-container-rename-form\" hidden>"
            + "<label><span>" + window.VNG.escapeHtml(tr("renameStorageContainerPrompt", "New container name")) + "</span>"
            + "<input name=\"label\" maxlength=\"80\" required></label>"
            + "<button type=\"submit\">" + window.VNG.escapeHtml(tr("renameStorageContainer", "Rename container")) + "</button>"
            + "<button class=\"inventory-container-rename-cancel\" type=\"button\">" + window.VNG.escapeHtml(tr("forumCancelEdit", "Cancel")) + "</button>"
            + "</form>"
            + "</div>";

        const select = node.querySelector("#inventory-container-filter");
        if (select) {
            select.addEventListener("change", () => {
                state.inventoryContainerFilter = select.value || "all";
                syncStorageContainerRenameControls();
                renderInventory(state.currentInventory, {"preserveStorageRules": true});
            });
        }
        syncStorageContainerRenameControls();
        node.querySelectorAll(".storage-rules-form").forEach((form) => syncStorageRuleForm(form));
        node.querySelectorAll(".storage-rule-select").forEach((selectNode) => {
            selectNode.addEventListener("focus", markStorageRulesTouched);
            selectNode.addEventListener("pointerdown", markStorageRulesTouched);
            selectNode.addEventListener("change", () => {
                state.storageRulesDirty = true;
                markStorageRulesTouched();
                syncStorageRuleForm(selectNode.closest(".storage-rules-form"), selectNode);
            });
        });
    }

    function inventoryReadouts(readouts) {
        return "<dl class=\"inventory-card-readouts\">"
            + readouts.map((readout) => (
                "<div><dt>" + window.VNG.escapeHtml(readout.label) + "</dt><dd>"
                + window.VNG.escapeHtml(readout.value)
                + "</dd></div>"
            )).join("")
            + "</dl>";
    }

    function inventoryLineReadouts(placement, valueKey) {
        const isAmount = valueKey === "amount";
        const value = Number(placement[valueKey] || 0);
        const space = Number(placement.containerSpace || (isAmount ? placement.amount : 0) || 0);

        return "<span class=\"inventory-line-readouts\">"
            + "<span class=\"inventory-line-readout\"><span>" + window.VNG.escapeHtml(isAmount ? tr("storedAmount", "Amount") : tr("quantity", "Quantity")) + "</span><b>"
            + window.VNG.escapeHtml(window.VNG.numberValue(value))
            + "</b></span>"
            + "<span class=\"inventory-line-readout\"><span>" + window.VNG.escapeHtml(tr("containerSpace", "Space")) + "</span><b>"
            + window.VNG.escapeHtml(window.VNG.numberValue(space))
            + "</b></span>"
            + "</span>";
    }

    function groupInventoryItems(items) {
        const groups = new Map();
        items.forEach((item) => {
            const key = [item.type || "item", item.name || ""].join("::");
            if (!groups.has(key)) {
                groups.set(key, {
                    "type": item.type || "item",
                    "sample": item,
                    "items": [],
                    "placements": new Map(),
                    "totalSpace": 0,
                });
            }
            const group = groups.get(key);
            group.items.push(item);
            group.totalSpace += Number(item.containerSpace || 0);
            const container = itemContainer(item);
            const containerId = container && container.id ? container.id : "unknown";
            if (!group.placements.has(containerId)) {
                group.placements.set(containerId, {
                    "container": container,
                    "quantity": 0,
                    "containerSpace": 0,
                    "itemIds": [],
                    "items": [],
                });
            }
            const placement = group.placements.get(containerId);
            placement.quantity += 1;
            placement.containerSpace += Number(item.containerSpace || 0);
            placement.itemIds.push(item.id);
            placement.items.push(item);
        });

        return Array.from(groups.values());
    }

    function renderInventory(inventory, options) {
        const node = document.getElementById("inventory-list");
        if (!node) {
            return;
        }
        const renderOptions = options || {};
        state.currentInventory = inventory && typeof inventory === "object" ? inventory : null;
        if (!state.inventoryContainerFilter) {
            state.inventoryContainerFilter = "all";
        }
        if ((!renderOptions.preserveStorageRules && !storageRulesDetailsOpen()) || !shouldPreserveStorageRules()) {
            renderStorageRules(inventory);
        }
        if (!inventory || typeof inventory !== "object") {
            node.innerHTML = "";
            return;
        }

        const stockCards = (Array.isArray(inventory.resourceStocks) ? inventory.resourceStocks : [])
            .map((stock) => {
                const placements = filteredStockPlacements(stock);
                const amount = placements.reduce((total, placement) => total + Number(placement.amount || 0), 0);
                const space = placements.reduce((total, placement) => total + Number(placement.containerSpace || placement.amount || 0), 0);
                if (amount <= 0) {
                    return "";
                }

                return "<article class=\"inventory-card\">"
                    + "<div class=\"inventory-card-heading\"><span>" + window.VNG.escapeHtml(tr("inventoryStock", "Stock")) + "</span><b>" + window.VNG.escapeHtml(resourceTypeLabel(stock.type)) + "</b></div>"
                    + inventoryReadouts([
                        {"label": tr("storedAmount", "Amount"), "value": window.VNG.numberValue(amount)},
                        {"label": tr("containerSpace", "Space"), "value": window.VNG.numberValue(space)},
                    ])
                    + placementLines(placements, "amount", {"kind": "resource", "resourceType": stock.type, "itemId": stock.id || stock.type})
                    + "</article>";
            })
            .filter(Boolean);

        const tankCards = (Array.isArray(inventory.externalTanks) ? inventory.externalTanks : [])
            .filter(() => selectedContainerId() === "all")
            .filter((tank) => Number(tank.fillPercent) > 0)
            .map((tank) => (
                "<article class=\"inventory-card\">"
                + "<div class=\"inventory-card-heading\"><span>" + window.VNG.escapeHtml(tr("externalTank", "External tank")) + "</span><b>" + window.VNG.escapeHtml(resourceTypeLabel(tank.type)) + "</b></div>"
                + inventoryReadouts([
                    {"label": tr("storedAmount", "Amount"), "value": window.VNG.numberValue(tank.fillPercent, "%")},
                ])
                + "</article>"
            ));

        const filteredItems = (Array.isArray(inventory.items) ? inventory.items : []).filter(itemMatchesContainerFilter);
        const itemCards = groupInventoryItems(filteredItems).map((group) => {
            const placements = Array.from(group.placements.values()).map((placement) => ({
                "container": placement.container,
                "quantity": placement.quantity,
                "containerSpace": placement.containerSpace,
                "itemIds": placement.itemIds,
                "items": placement.items,
            }));
            const kind = group.type === "manny" ? "manny" : "item";

            return "<article class=\"inventory-card\">"
                + "<div class=\"inventory-card-heading\"><span>" + window.VNG.escapeHtml(tr("inventoryItem", "Equipment")) + "</span><b>" + window.VNG.escapeHtml(inventoryItemName(group.sample)) + "</b></div>"
                + inventoryReadouts([
                    {"label": tr("quantity", "Quantity"), "value": window.VNG.numberValue(group.items.length)},
                    {"label": tr("containerSpace", "Space"), "value": window.VNG.numberValue(group.totalSpace)},
                ])
                + placementLines(placements, "quantity", {"kind": kind, "itemType": group.type})
                + (group.items.length === 1 ? "<p class=\"inventory-entry-detail\">" + window.VNG.escapeHtml(inventoryEntryDetail(group.sample)) + "</p>" : "")
                + "</article>";
        });

        node.innerHTML = stockCards.concat(tankCards, itemCards).join("");
    }

    function splitLineIds(value) {
        return String(value || "").split(",").map((entry) => entry.trim()).filter(Boolean);
    }

    function availableStorageMoveMannies(excludedIds) {
        const excluded = new Set((excludedIds || []).map(String));
        return (Array.isArray(state.currentMannies) ? state.currentMannies : []).filter((manny) => (
            manny
            && manny.id
            && !excluded.has(String(manny.id))
            && manny.currentTask === null
            && manny.location
            && manny.location.type === "probe"
        ));
    }

    function detachableStorageContainers() {
        return inventoryContainers(state.currentInventory).filter((container) => (
            container
            && container.id
            && container.id !== "probe-core"
            && (container.kind === undefined || container.kind === "container" || String(container.id).startsWith("container-"))
        ));
    }

    function storageContainerMatchesItemIds(container, itemIds) {
        const id = String(container && container.id ? container.id : "");
        return Boolean(id) && itemIds.some((itemId) => id === "container-" + String(itemId));
    }

    function asteroidTargets() {
        const targets = [];
        const seen = new Set();
        const collect = (object) => {
            if (!object || typeof object !== "object") {
                return;
            }
            if (object.type === "asteroid" && object.id && !seen.has(object.id)) {
                seen.add(object.id);
                targets.push(object);
            }
            ["bookmarkTargets", "minableTargets"].forEach((key) => {
                if (Array.isArray(object[key])) {
                    object[key].forEach(collect);
                }
            });
        };
        state.currentSectorObjects.forEach(collect);

        return targets;
    }

    function closeInventoryLineForms(exceptSlot) {
        document.querySelectorAll(".inventory-line-form-slot").forEach((slot) => {
            if (slot !== exceptSlot) {
                slot.innerHTML = "";
            }
        });
    }

    async function ensureManniesLoaded() {
        if (!Array.isArray(state.currentMannies) || state.currentMannies.length === 0) {
            await loadMannies();
        }
    }

    async function renderStorageMoveForm(line) {
        const slot = line.querySelector(".inventory-line-form-slot");
        if (!slot) {
            return;
        }
        if (slot.querySelector(".inventory-move-form")) {
            slot.innerHTML = "";
            return;
        }
        closeInventoryLineForms(slot);
        await ensureManniesLoaded();

        const kind = line.dataset.lineKind || "";
        const sourceContainerId = line.dataset.containerId || "";
        const itemIds = splitLineIds(line.dataset.itemIds);
        const excludedMannyIds = kind === "manny" ? itemIds : [];
        const mannies = availableStorageMoveMannies(excludedMannyIds);
        const destinations = inventoryContainers(state.currentInventory).filter((container) => container && container.id && container.id !== sourceContainerId);
        const isResource = kind === "resource";
        const maxQuantity = isResource ? Number.parseFloat(line.dataset.maxAmount || "0") : Number.parseInt(line.dataset.maxQuantity || "0", 10);
        const hasFormChoices = mannies.length > 0 && destinations.length > 0 && Number.isFinite(maxQuantity) && maxQuantity > 0;
        const quantityAttributes = isResource
            ? "type=\"number\" min=\"0.0001\" max=\"" + window.VNG.escapeHtml(String(maxQuantity)) + "\" step=\"0.0001\" value=\"" + window.VNG.escapeHtml(String(maxQuantity)) + "\""
            : "type=\"number\" min=\"1\" max=\"" + window.VNG.escapeHtml(String(maxQuantity)) + "\" step=\"1\" value=\"" + window.VNG.escapeHtml(String(maxQuantity)) + "\"";
        const mannyOptions = mannies.map((manny) => (
            "<option value=\"" + window.VNG.escapeHtml(manny.id) + "\">" + window.VNG.escapeHtml(manny.name || manny.id) + "</option>"
        )).join("");
        const destinationOptions = destinations.map((container) => (
            "<option value=\"" + window.VNG.escapeHtml(container.id) + "\">" + window.VNG.escapeHtml(containerLabel(container)) + "</option>"
        )).join("");
        const unavailableMessage = mannies.length === 0 ? tr("noAvailableManny", "No available Manny.") : tr("noDestinationContainer", "No destination container available.");

        slot.innerHTML = "<form class=\"inventory-move-form\""
            + " data-line-kind=\"" + window.VNG.escapeHtml(kind) + "\""
            + " data-source-container-id=\"" + window.VNG.escapeHtml(sourceContainerId) + "\""
            + " data-resource-type=\"" + window.VNG.escapeHtml(line.dataset.resourceType || "") + "\""
            + " data-max-amount=\"" + window.VNG.escapeHtml(line.dataset.maxAmount || "") + "\""
            + " data-item-ids=\"" + window.VNG.escapeHtml(itemIds.join(",")) + "\">"
            + "<label>" + window.VNG.escapeHtml(tr("moveQuantity", "Quantity")) + "<input name=\"quantity\" " + quantityAttributes + " required></label>"
            + "<label>" + window.VNG.escapeHtml(tr("actorManny", "Manny")) + "<select name=\"actorMannyId\" required>" + mannyOptions + "</select></label>"
            + "<label>" + window.VNG.escapeHtml(tr("destinationContainer", "Destination container")) + "<select name=\"toContainerId\" required>" + destinationOptions + "</select></label>"
            + "<button type=\"submit\"" + (hasFormChoices ? "" : " disabled aria-disabled=\"true\"") + ">" + window.VNG.escapeHtml(tr("moveStorageLine", "Move")) + "</button>"
            + (hasFormChoices ? "" : "<p class=\"inventory-muted\">" + window.VNG.escapeHtml(unavailableMessage) + "</p>")
            + "</form>";
    }

    async function renderDetachStorageContainerForm(line) {
        const slot = line.querySelector(".inventory-line-form-slot");
        if (!slot) {
            return;
        }
        if (slot.querySelector(".inventory-detach-container-form")) {
            slot.innerHTML = "";
            return;
        }
        closeInventoryLineForms(slot);
        await ensureManniesLoaded();

        const itemIds = splitLineIds(line.dataset.itemIds);
        const mannies = availableStorageMoveMannies();
        const containers = detachableStorageContainers();
        const preferredContainer = containers.find((container) => storageContainerMatchesItemIds(container, itemIds)) || containers[0] || null;
        const asteroids = asteroidTargets();
        const hasFormChoices = mannies.length > 0 && containers.length > 0;
        const mannyOptions = mannies.map((manny) => (
            "<option value=\"" + window.VNG.escapeHtml(manny.id) + "\">" + window.VNG.escapeHtml(manny.name || manny.id) + "</option>"
        )).join("");
        const containerOptions = containers.map((container) => (
            "<option value=\"" + window.VNG.escapeHtml(container.id) + "\"" + (preferredContainer && preferredContainer.id === container.id ? " selected" : "") + ">"
            + window.VNG.escapeHtml(containerLabel(container))
            + "</option>"
        )).join("");
        const asteroidOptions = asteroids.length === 0
            ? "<option value=\"\">-</option>"
            : asteroids.map((target) => (
                "<option value=\"" + window.VNG.escapeHtml(target.id) + "\">" + window.VNG.escapeHtml([objectTypeLabel(target.type), target.name || target.id].filter(Boolean).join(" ")) + "</option>"
            )).join("");
        const unavailableMessage = mannies.length === 0 ? tr("noAvailableManny", "No available Manny.") : tr("noDetachableContainer", "No additional container can be detached.");

        slot.innerHTML = "<form class=\"inventory-detach-container-form\">"
            + "<label>" + window.VNG.escapeHtml(tr("storageContainer", "Container")) + "<select name=\"containerId\" required>" + containerOptions + "</select></label>"
            + "<label>" + window.VNG.escapeHtml(tr("actorManny", "Manny")) + "<select name=\"actorMannyId\" required>" + mannyOptions + "</select></label>"
            + "<label>" + window.VNG.escapeHtml(tr("detachStorageMode", "Mode")) + "<select class=\"detach-storage-mode\" name=\"mode\" required>"
            + "<option value=\"drifting\">" + window.VNG.escapeHtml(tr("detachModeDrifting", "Leave drifting")) + "</option>"
            + "<option value=\"hidden_on_asteroid\">" + window.VNG.escapeHtml(tr("detachModeHiddenOnAsteroid", "Hide on an asteroid")) + "</option>"
            + "</select></label>"
            + "<label class=\"detach-asteroid-label\" hidden>" + window.VNG.escapeHtml(tr("asteroidObject", "Asteroid")) + "<select class=\"detach-asteroid-target\" name=\"objectId\">" + asteroidOptions + "</select></label>"
            + "<button class=\"detach-storage-button\" type=\"submit\"" + (hasFormChoices ? "" : " disabled aria-disabled=\"true\"") + ">" + window.VNG.escapeHtml(tr("detachStorageContainerShort", "Detach")) + "</button>"
            + (hasFormChoices ? "" : "<p class=\"inventory-muted\">" + window.VNG.escapeHtml(unavailableMessage) + "</p>")
            + "</form>";
        updateDetachStorageContainerForm(slot.querySelector(".inventory-detach-container-form"));
    }

    function updateDetachStorageContainerForm(form) {
        if (!form) {
            return;
        }
        const mode = form.querySelector(".detach-storage-mode");
        const asteroidLabel = form.querySelector(".detach-asteroid-label");
        const asteroid = form.querySelector(".detach-asteroid-target");
        const button = form.querySelector(".detach-storage-button");
        const hiddenMode = mode && mode.value === "hidden_on_asteroid";
        if (asteroidLabel) {
            asteroidLabel.hidden = !hiddenMode;
        }
        if (asteroid) {
            asteroid.required = Boolean(hiddenMode);
            asteroid.disabled = !hiddenMode;
        }
        if (button) {
            const formData = new FormData(form);
            const coherent = Boolean(formData.get("containerId"))
                && Boolean(formData.get("actorMannyId"))
                && Boolean(formData.get("mode"))
                && (!hiddenMode || Boolean(asteroid && asteroid.value));
            button.disabled = !coherent;
            button.setAttribute("aria-disabled", coherent ? "false" : "true");
        }
    }

    function detachStorageContainerPayloadFromForm(form) {
        updateDetachStorageContainerForm(form);
        const formData = new FormData(form);
        const actorMannyId = String(formData.get("actorMannyId") || "");
        const containerId = String(formData.get("containerId") || "");
        const mode = String(formData.get("mode") || "");
        const objectId = String(formData.get("objectId") || "");
        if (!actorMannyId || !containerId || !["drifting", "hidden_on_asteroid"].includes(mode)) {
            return null;
        }
        if (mode === "hidden_on_asteroid" && !objectId) {
            return null;
        }

        return {
            "mannyId": actorMannyId,
            "payload": {
                "containerId": containerId,
                "mode": mode,
                ...(mode === "hidden_on_asteroid" ? {"objectId": objectId} : {}),
            },
        };
    }

    function storageMovePayloadFromForm(form) {
        const formData = new FormData(form);
        const actorMannyId = String(formData.get("actorMannyId") || "");
        const toContainerId = String(formData.get("toContainerId") || "");
        const kind = form.dataset.lineKind || "";
        if (!actorMannyId || !toContainerId || !kind) {
            return null;
        }
        if (kind === "resource") {
            const amount = Math.min(
                Number.parseFloat(form.dataset.maxAmount || "0"),
                Number.parseFloat(String(formData.get("quantity") || "0"))
            );
            if (!Number.isFinite(amount) || amount <= 0) {
                return null;
            }
            return {
                "actorMannyId": actorMannyId,
                "kind": kind,
                "resourceType": form.dataset.resourceType || "",
                "amount": Math.round(amount * 10000) / 10000,
                "fromContainerId": form.dataset.sourceContainerId || "",
                "toContainerId": toContainerId,
            };
        }

        const itemIds = splitLineIds(form.dataset.itemIds);
        const quantity = Math.min(itemIds.length, Math.max(1, Number.parseInt(String(formData.get("quantity") || "0"), 10)));
        const selectedIds = itemIds.slice(0, quantity);
        if (selectedIds.length === 0) {
            return null;
        }
        if (kind === "manny") {
            return {
                "actorMannyId": actorMannyId,
                "kind": kind,
                "targetMannyIds": selectedIds,
                "quantity": selectedIds.length,
                "toContainerId": toContainerId,
            };
        }

        return {
            "actorMannyId": actorMannyId,
            "kind": "item",
            "itemIds": selectedIds,
            "quantity": selectedIds.length,
            "toContainerId": toContainerId,
        };
    }

    async function jettisonInventoryLine(line) {
        const kind = line.dataset.lineKind || "";
        if (kind === "resource") {
            return window.VNG.apiJson("/api/probe/inventory/" + encodeURIComponent(line.dataset.stockId || line.dataset.resourceType || "") + "/jettison", {
                "method": "POST",
                "body": JSON.stringify({
                    "amount": Number.parseFloat(line.dataset.maxAmount || "0"),
                    "containerId": line.dataset.containerId || "",
                }),
            });
        }

        let latest = null;
        for (const itemId of splitLineIds(line.dataset.itemIds)) {
            latest = await window.VNG.apiJson("/api/probe/inventory/" + encodeURIComponent(itemId) + "/jettison", {
                "method": "POST",
                "body": JSON.stringify({}),
            });
        }

        return latest || {};
    }

    async function loadMannies() {
        const data = await window.VNG.apiJson("/api/probe/mannies", {"method": "GET"});
        state.currentMannies = Array.isArray(data.mannies) ? data.mannies : [];
        return data;
    }

    async function loadCurrentSectorObjects() {
        try {
            const data = await window.VNG.apiJson("/api/probe/sector", {"method": "GET"});
            state.currentSectorObjects = Array.isArray(data.sector && data.sector.objects) ? data.sector.objects : [];
            return data;
        } catch (error) {
            state.currentSectorObjects = [];
            return null;
        }
    }

    function clearRefreshTimer() {
        if (refreshTimer !== null) {
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
        }
    }

    function scheduleRefresh(data) {
        clearRefreshTimer();
        refreshTimer = window.setTimeout(() => refreshInventoryPage({"preserveStorageRules": true}), window.VNG.nextRefreshDelay(
            data,
            DEFAULT_REFRESH_MS,
            MIN_REFRESH_MS,
            REFRESH_CUSHION_MS
        ));
    }

    async function refreshInventoryPage(options) {
        const refreshOptions = options || {};
        if (loadInProgress) {
            return;
        }
        loadInProgress = true;
        clearRefreshTimer();

        try {
            const [probeData, mannyData, sectorData] = await Promise.all([
                window.VNG.apiJson("/api/probe", {"method": "GET"}),
                loadMannies(),
                loadCurrentSectorObjects(),
            ]);
            const probe = probeData.probe || {};
            renderSystemsSummary(probe);
            renderInventory(probe.inventory || {}, refreshOptions);
            scheduleRefresh({"probe": probe, "mannies": mannyData.mannies, "sector": sectorData && sectorData.sector});
        } catch (error) {
            setText("inventory-status", error.message || tr("requestDenied", "Request denied"));
            renderSystemsSummary(null);
            renderInventory(null, refreshOptions);
            scheduleRefresh(null);
        } finally {
            loadInProgress = false;
        }
    }

    function syncStorageContainerRenameControls() {
        const node = document.getElementById("storage-rules-panel");
        const button = node ? node.querySelector(".inventory-container-rename-button") : null;
        const form = node ? node.querySelector(".inventory-container-rename-form") : null;
        const hasSelectedContainer = selectedContainerId() !== "all";
        if (button) {
            button.hidden = !hasSelectedContainer;
        }
        if (!hasSelectedContainer && form) {
            form.hidden = true;
            form.reset();
        }
    }

    function storageContainerRenameDefaultLabel() {
        const container = selectedInventoryContainer(state.currentInventory);
        if (container) {
            return containerLabel(container);
        }
        const select = document.getElementById("inventory-container-filter");
        if (select instanceof HTMLSelectElement) {
            return select.selectedOptions[0]?.textContent || selectedContainerId();
        }

        return selectedContainerId();
    }

    function toggleStorageContainerRenameForm() {
        const form = document.querySelector(".inventory-container-rename-form");
        if (!form || selectedContainerId() === "all") {
            syncStorageContainerRenameControls();
            return;
        }

        const willOpen = form.hidden;
        form.hidden = !willOpen;
        if (willOpen) {
            const input = form.querySelector("input[name=\"label\"]");
            if (input instanceof HTMLInputElement) {
                input.value = storageContainerRenameDefaultLabel();
                input.focus();
                input.select();
            }
        }
    }

    async function renameSelectedStorageContainer(form) {
        const containerId = selectedContainerId();
        if (!containerId || containerId === "all") {
            return;
        }
        const formData = new FormData(form);
        const label = String(formData.get("label") || "");
        const trimmedLabel = label.trim();
        if (trimmedLabel === "") {
            setText("inventory-status", tr("storageContainerLabelRequired", "Container name cannot be empty."));
            return;
        }

        await runApiOrder(
            "inventory-status",
            tr("orderSent", "Order transmitted..."),
            () => window.VNG.apiJson("/api/probe/storage-containers/" + encodeURIComponent(containerId), {
                "method": "PATCH",
                "body": JSON.stringify({"label": trimmedLabel}),
            }),
            async (data) => {
                setText("inventory-status", tr("storageContainerRenamed", "Storage container renamed."));
                form.hidden = true;
                if (data.inventory) {
                    renderInventory(data.inventory);
                }
                await refreshInventoryPage();
            }
        );
    }

    async function handleInventoryClick(event) {
        if (!(event.target instanceof Element)) {
            return;
        }

        const renameContainerButton = event.target.closest(".inventory-container-rename-button");
        if (renameContainerButton) {
            toggleStorageContainerRenameForm();
            return;
        }

        if (event.target.closest(".inventory-container-rename-cancel")) {
            const form = event.target.closest(".inventory-container-rename-form");
            if (form) {
                form.hidden = true;
                form.reset();
            }
            return;
        }

        const moveButton = event.target.closest(".inventory-line-move");
        if (moveButton) {
            const line = moveButton.closest(".inventory-container-line");
            if (line) {
                await renderStorageMoveForm(line);
            }
            return;
        }

        const jettisonButton = event.target.closest(".inventory-line-jettison");
        if (!jettisonButton) {
            return;
        }

        const line = jettisonButton.closest(".inventory-container-line");
        if (line && line.dataset.itemType === "additional_container") {
            await renderDetachStorageContainerForm(line);
            return;
        }
        if (!line || !window.confirm(tr("confirmJettisonLine", "Jettison this storage line into space?"))) {
            return;
        }

        await runApiOrder(
            "inventory-status",
            tr("orderSent", "Order transmitted..."),
            () => jettisonInventoryLine(line),
            async (data) => {
                setText("inventory-status", tr("jettisonAccepted", "Inventory entry jettisoned into space."));
                if (data.inventory) {
                    renderInventory(data.inventory);
                }
                await refreshInventoryPage();
            }
        );
    }

    async function handleInventorySubmit(event) {
        if (!(event.target instanceof Element)) {
            return;
        }

        if (event.target.classList.contains("inventory-container-rename-form")) {
            event.preventDefault();
            await renameSelectedStorageContainer(event.target);
            return;
        }

        if (event.target.classList.contains("storage-rules-form")) {
            event.preventDefault();
            const containerId = event.target.dataset.containerId;
            if (!containerId) {
                return;
            }
            const formData = new FormData(event.target);
            await runApiOrder(
                "inventory-status",
                tr("orderSent", "Order transmitted..."),
                () => window.VNG.apiJson("/api/probe/storage-containers/" + encodeURIComponent(containerId) + "/rules", {
                    "method": "PATCH",
                    "body": JSON.stringify({
                        "priority": storageRuleValues(formData, "priority"),
                        "exclusion": storageRuleValues(formData, "exclusion"),
                        "strictExclusion": storageRuleValues(formData, "strictExclusion"),
                    }),
                }),
                async (data) => {
                    setText("inventory-status", tr("storageRulesSaved", "Storage rules saved."));
                    state.storageRulesDirty = false;
                    state.storageRulesTouchedAt = 0;
                    if (data.inventory) {
                        renderInventory(data.inventory);
                    }
                    await refreshInventoryPage();
                }
            );
            return;
        }

        if (event.target.classList.contains("inventory-detach-container-form")) {
            event.preventDefault();
            const order = detachStorageContainerPayloadFromForm(event.target);
            if (!order) {
                setText("inventory-status", tr("invalidDetachStorageOrder", "Invalid container detachment order."));
                return;
            }

            await runApiOrder(
                "inventory-status",
                tr("orderSent", "Order transmitted..."),
                () => window.VNG.apiJson("/api/probe/mannies/" + encodeURIComponent(order.mannyId) + "/detach-storage-container", {
                    "method": "POST",
                    "body": JSON.stringify(order.payload),
                }),
                async () => {
                    setText("inventory-status", tr("detachStorageAccepted", "Container detachment assigned."));
                    await refreshInventoryPage();
                }
            );
            return;
        }

        if (event.target.classList.contains("inventory-move-form")) {
            event.preventDefault();
            const payload = storageMovePayloadFromForm(event.target);
            if (!payload) {
                setText("inventory-status", tr("invalidStorageMove", "Invalid storage move order."));
                return;
            }

            await runApiOrder(
                "inventory-status",
                tr("orderSent", "Order transmitted..."),
                () => window.VNG.apiJson("/api/probe/storage-moves", {
                    "method": "POST",
                    "body": JSON.stringify(payload),
                }),
                async (data) => {
                    setText("inventory-status", tr("storageMoveAccepted", "Storage move assigned."));
                    if (data.inventory) {
                        renderInventory(data.inventory);
                    }
                    await refreshInventoryPage();
                }
            );
        }
    }

    function bindPage() {
        const panel = document.getElementById("systems-panel");
        panel?.addEventListener("click", handleInventoryClick);
        panel?.addEventListener("submit", handleInventorySubmit);
        panel?.addEventListener("change", (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }
            const form = event.target.closest(".inventory-detach-container-form");
            if (form && panel.contains(form)) {
                updateDetachStorageContainerForm(form);
            }
        });
        document.querySelector("[data-refresh=\"inventory\"]")?.addEventListener("click", refreshInventoryPage);
    }

    document.addEventListener("DOMContentLoaded", () => {
        if (document.body.dataset.authenticated !== "1" || !document.getElementById("inventory-list")) {
            return;
        }

        withVng(async () => {
            i18n = await window.VNG.loadI18n();
            bindPage();
            refreshInventoryPage();
        });
    });
}());
