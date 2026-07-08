(function () {
    const MANNY_REFRESH_MS = 5000;
    const PROGRESS_TICK_MS = 1000;
    const MINING_RESOURCE_TYPES = ["deuterium", "metals", "ice", "carbon_compounds"];
    const MANNY_MINING_AMOUNT_MAX = 0.55;
    const MANNY_HASH_FIELD = "mannyStateHash";
    const STATE_HASH_IGNORED_FIELDS = new Set([MANNY_HASH_FIELD, "hash", "taskProgressPercent"]);
    const PROBE_INVENTORY_ACTIONS = new Set(["detach-storage", "drop-storage", "bookmark", "craft", "atomic-printer-craft", "turn-on-relay", "improve-probe", "assemble-probe"]);
    const PROBE_ASSEMBLY_COMPONENTS = [
        {"type": "deuterium_engine", "quantity": 1},
        {"type": "scut_relay", "quantity": 1},
        {"type": "electric_motor", "quantity": 5},
        {"type": "atomic_printer_part", "quantity": 2},
        {"type": "solar_panel", "quantity": 4},
    ];

    const state = {
        currentCraftingRecipes: [],
        currentInventory: null,
        currentMannies: [],
        currentMannyMineTargets: [],
        currentMannySalvageTargets: [],
        currentProbeImprovements: [],
        currentProbeSectorRelative: null,
        currentSectorObjects: [],
        remoteSectorScans: {},
    };

    let i18n = {};
    let refreshTimer = null;
    let progressTickTimer = null;
    let loadInProgress = false;
    let loadRequestedWhileInProgress = false;
    let craftingRecipesLoadPromise = null;

    function withVng(callback) {
        if (window.VNG) {
            callback(window.VNG);
            return;
        }

        window.addEventListener("VNGReady", () => callback(window.VNG), {"once": true});
    }

    function loadCraftingRecipesOnce() {
        if (craftingRecipesLoadPromise === null) {
            craftingRecipesLoadPromise = window.VNG.apiJson("/api/crafting-recipes", {"method": "GET"})
                .then((recipeData) => {
                    state.currentCraftingRecipes = Array.isArray(recipeData && recipeData.recipes)
                        ? recipeData.recipes
                        : [];
                })
                .catch(() => {
                    state.currentCraftingRecipes = [];
                });
        }

        return craftingRecipesLoadPromise;
    }

    async function refreshProbeInventory() {
        const probeData = await window.VNG.apiJson(window.VNG.probeApiPath(""), {"method": "GET"});
        const probe = probeData && probeData.probe ? probeData.probe : {};
        state.currentInventory = probe.inventory || null;
        state.currentProbeSectorRelative = relativeCoordinates(probe.sector && probe.sector.relative);

        return state.currentInventory;
    }

    async function refreshProbeImprovements() {
        const data = await window.VNG.apiJson(window.VNG.probeApiPath("/probe-improvements-available"), {"method": "GET"});
        state.currentProbeImprovements = Array.isArray(data && data.improvements)
            ? data.improvements
            : [];

        return state.currentProbeImprovements;
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

    function setStatus(value) {
        setText("manny-status", value);
    }

    function stableHashPayload(value) {
        if (Array.isArray(value)) {
            return "[" + value.map(stableHashPayload).join(",") + "]";
        }
        if (value !== null && typeof value === "object") {
            return "{" + Object.keys(value)
                .filter((key) => !STATE_HASH_IGNORED_FIELDS.has(key))
                .sort()
                .map((key) => JSON.stringify(key) + ":" + stableHashPayload(value[key]))
                .join(",") + "}";
        }

        return JSON.stringify(value);
    }

    function hashString(value) {
        let hash = 0x811c9dc5;
        for (let index = 0; index < value.length; index += 1) {
            hash ^= value.charCodeAt(index);
            hash = Math.imul(hash, 0x01000193);
        }

        return (hash >>> 0).toString(16).padStart(8, "0");
    }

    function withMannyStateHash(manny) {
        const item = manny && typeof manny === "object" ? {...manny} : {};
        item[MANNY_HASH_FIELD] = hashString(stableHashPayload({
            "manny": item,
            "actions": {
                "deuteriumRefuelStationAvailable": sectorHasDeuteriumRefuelStation(),
                "inactiveScutRelays": inactiveScutRelayTargets().map((relay) => relay.id).join(","),
                "remoteSectorMine": remoteMannyMineStateHash(item),
                "remoteSectorInspect": remoteMannyInspectStateHash(item),
            },
        }));

        return item;
    }

    function normalizeResourceType(type) {
        return type === "organic_compounds" ? "carbon_compounds" : String(type || "");
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
            "atomic_printer_part": tr("atomicPrinterPart", "Atomic printer part"),
            "deuterium_engine": tr("deuteriumEngine", "Deuterium engine"),
            "solar_panel": tr("solarPanel", "Solar panel"),
            "thermal_protection_shell": tr("thermalProtectionShell", "Thermal protection shell"),
            "parachute_pack": tr("parachutePack", "Parachute pack"),
            "descent_guidance_module": tr("descentGuidanceModule", "Descent guidance module"),
            "atmospheric_drop_kit": tr("atmosphericDropKit", "Atmospheric drop kit"),
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
            "dropping_storage_container": tr("droppingStorageContainer", "Dropping storage container"),
            "inspecting_sector_object": tr("inspectingSectorObject", "Inspecting sector object"),
            "inspecting_asteroid": tr("inspectingSectorObject", "Inspecting sector object"),
            "refilling_deuterium_tank": tr("refillingDeuteriumTank", "Refilling deuterium tank"),
            "turning_on_scut_relay": tr("turningOnScutRelay", "Activating SCUT relay"),
            "improving_probe": tr("improvingProbe", "Improving probe"),
            "assembling_probe": tr("assemblingProbe", "Assembling probe"),
            "assisting_atomic_printer": tr("assistingAtomicPrinter", "Assisting the atomic printer"),
            "atomic_printing": tr("atomicPrinting", "Atomic printing"),
            "unknown_too_far": tr("mannyUnknownTooFar", "Status unknown, too far"),
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
            "dust_cloud": tr("dustCloudObject", "Dust cloud"),
            "drifting_item": tr("driftingItemObject", "Drifting item"),
            "detached_container": tr("detachedContainerObject", "Detached container"),
            "dormant_construct": tr("dormantConstructObject", "Dormant construct"),
            "deuterium_refuel_station": tr("deuteriumRefuelStationObject", "Deuterium refuel station"),
            "scut_relay": tr("scutRelayObject", "SCUT relay"),
            "waypoint_bookmark": tr("waypointBookmark", "Waypoint bookmark"),
            "manny": tr("mannyObject", "Manny"),
            "probe": tr("tabProbe", "Probe"),
            "object": tr("object", "Object"),
        }[type] || type || tr("object", "Object");
    }

    function mannyStateLabel(value) {
        return {
            "abandoned": tr("abandonedManny", "abandoned"),
            "forgotten": tr("forgottenManny", "forgotten"),
        }[value] || value || "-";
    }

    function escaped(value) {
        return window.VNG.escapeHtml(value);
    }

    function metric(label, value, valueClass, valueAttributes) {
        return window.VNG.metricHtml({
            "label": label,
            "value": value,
            "valueClass": valueClass || "",
            "valueAttributes": valueAttributes || "",
        });
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

    function sameRelativeSector(left, right) {
        const a = relativeCoordinates(left);
        const b = relativeCoordinates(right);

        return a !== null && b !== null && a.x === b.x && a.y === b.y && a.z === b.z;
    }

    function relativeSectorKey(relative) {
        const coordinates = relativeCoordinates(relative);
        return coordinates ? coordinates.x + ":" + coordinates.y + ":" + coordinates.z : "";
    }

    function remoteSectorScanForKey(key) {
        return key && state.remoteSectorScans[key] ? state.remoteSectorScans[key] : null;
    }

    async function fetchRelativeSectorScan(relative) {
        const coordinates = relativeCoordinates(relative);
        if (!coordinates) {
            return {"status": "unavailable", "objects": []};
        }

        const query = new URLSearchParams({
            "x": String(coordinates.x),
            "y": String(coordinates.y),
            "z": String(coordinates.z),
        });

        try {
            const data = await window.VNG.apiJson("/api/sector?" + query.toString(), {"method": "GET"});
            const sector = data && data.sector ? data.sector : {};
            return {
                "status": "loaded",
                "objects": Array.isArray(sector.objects) ? sector.objects : [],
            };
        } catch (error) {
            return {"status": "unavailable", "objects": []};
        }
    }

    function resourceTypeFromHint(hint) {
        const value = String(hint || "").toLowerCase();
        if (value.includes("deuterium") || value.includes("hydrogen")) {
            return "deuterium";
        }
        if (value.includes("iron") || value.includes("nickel") || value.includes("metal") || value.includes("platinum") || value.includes("magnesium") || value.includes("silicate")) {
            return "metals";
        }
        if (value.includes("water") || value.includes("ice") || value.includes("volatile") || value.includes("ammonia")) {
            return "ice";
        }
        if (value.includes("carbon") || value.includes("organic") || value.includes("hydrocarbon")) {
            return "carbon_compounds";
        }

        return "carbon_compounds";
    }

    function resourceCompositionForTarget(target) {
        const composition = target && target.resourceComposition && typeof target.resourceComposition === "object"
            ? target.resourceComposition
            : null;
        if (composition) {
            return MINING_RESOURCE_TYPES.reduce((result, type) => {
                result[type] = Math.max(0, Number(composition[type]) || 0);
                return result;
            }, {});
        }

        const hints = Array.isArray(target && target.resources) ? target.resources : [];
        const counts = MINING_RESOURCE_TYPES.reduce((result, type) => {
            result[type] = 0;
            return result;
        }, {});
        hints.forEach((hint) => {
            counts[resourceTypeFromHint(hint)] += 1;
        });
        const total = MINING_RESOURCE_TYPES.reduce((sum, type) => sum + counts[type], 0) || 1;

        return MINING_RESOURCE_TYPES.reduce((result, type) => {
            result[type] = counts[type] / total;
            return result;
        }, {});
    }

    function resourceTypesForTarget(target) {
        if (!target) {
            return [];
        }
        if (Array.isArray(target.resourceTypes) && target.resourceTypes.length > 0) {
            return MINING_RESOURCE_TYPES.filter((type) => target.resourceTypes.includes(type));
        }
        const composition = resourceCompositionForTarget(target);

        return MINING_RESOURCE_TYPES.filter((type) => Number(composition[type]) > 0);
    }

    function resourceCompositionLabel(target) {
        const composition = resourceCompositionForTarget(target);
        const parts = MINING_RESOURCE_TYPES
            .filter((type) => Number(composition[type]) > 0)
            .map((type) => resourceTypeLabel(type) + " " + Math.round(Number(composition[type]) * 100) + "%");

        return parts.length > 0 ? parts.join(", ") : tr("compositionUnknown", "unknown composition");
    }

    function mineTargetLabel(target) {
        const name = target && (target.name || target.id) ? (target.name || target.id) : "";
        const base = [objectTypeLabel(target && target.type ? target.type : "object"), name].filter(Boolean).join(" ");

        return base + " (" + resourceCompositionLabel(target) + ")";
    }

    function miningTargetDetails(target) {
        if (!target) {
            return tr("unknownMiningTarget", "unknown target");
        }

        return [objectTypeLabel(target.type || "object"), target.name || target.id].filter(Boolean).join(" ")
            + " (" + resourceCompositionLabel(target) + ")";
    }

    function salvageTargetLabel(target) {
        if (target && target.type === "drifting_item") {
            const name = target.name || target.itemType || tr("unknownObject", "Unknown object");
            const quantity = Number(target.quantity);

            return objectTypeLabel("drifting_item") + " " + name
                + (Number.isFinite(quantity) && quantity > 0 ? " x" + String(quantity) : "");
        }

        const type = objectTypeLabel(target && target.type ? target.type : "object");
        const name = target && (target.name || target.id) ? (target.name || target.id) : tr("unknownObject", "Unknown object");
        const targetState = target && target.mannyState ? " - " + mannyStateLabel(target.mannyState) : "";

        return type + " " + name + targetState;
    }

    function mineTargetsFromObjects(objects) {
        return (Array.isArray(objects) ? objects : []).flatMap((object) => {
            const direct = object && object.mannyMineable ? [{
                "id": object.id,
                "type": object.type || "object",
                "name": object.name || object.id || "",
                "resources": object.resources || [],
                "resourceTypes": object.resourceTypes || [],
                "resourceComposition": object.resourceComposition || {},
                "resourceAmounts": object.resourceAmounts || {},
            }] : [];
            const nested = Array.isArray(object && object.minableTargets)
                ? object.minableTargets.map((target) => ({
                    "id": target.id,
                    "type": target.type || "object",
                    "name": target.name || target.id || "",
                    "resources": target.resources || [],
                    "resourceTypes": target.resourceTypes || [],
                    "resourceComposition": target.resourceComposition || {},
                    "resourceAmounts": target.resourceAmounts || {},
                }))
                : [];

            return direct.concat(nested).filter((target) => target.id);
        });
    }

    function salvageTargetsFromObjects(objects) {
        return (Array.isArray(objects) ? objects : []).filter((object) => (
            object && object.salvageable && object.id
            && !(object.type === "detached_container" && object.mode === "hidden_on_asteroid")
        )).map((object) => ({
            "id": object.id,
            "type": object.type || "object",
            "name": object.name || object.id || "",
            "mannyState": object.mannyState || null,
            "itemType": object.itemType || null,
            "quantity": object.quantity || null,
            "containerSpace": object.containerSpace || null,
            "status": object.status || null,
            "mode": object.mode || null,
        }));
    }

    function bookmarkTargetsFromObjects(objects) {
        return (Array.isArray(objects) ? objects : []).flatMap((object) => {
            if (!object) {
                return [];
            }
            const direct = !["manny", "probe", "drifting_item"].includes(object.type) ? [{
                "id": object.id,
                "type": object.type || "object",
                "name": object.name || object.id || "",
            }] : [];
            const nested = Array.isArray(object.bookmarkTargets)
                ? object.bookmarkTargets.map((target) => ({
                    "id": target.id,
                    "type": target.type || "object",
                    "name": target.name || target.id || "",
                }))
                : [];

            return direct.concat(nested).filter((target) => target.id);
        });
    }

    function asteroidTargetsFromObjects(objects) {
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

        (Array.isArray(objects) ? objects : []).forEach(collect);
        return targets;
    }

    function asteroidTargets() {
        return asteroidTargetsFromObjects(state.currentSectorObjects);
    }

    function planetTargetsFromObjects(objects) {
        const targets = [];
        const seen = new Set();
        const collect = (object) => {
            if (!object || typeof object !== "object") {
                return;
            }
            if (object.type === "planet" && object.id && !seen.has(object.id)) {
                seen.add(object.id);
                targets.push(object);
            }
            ["bookmarkTargets", "minableTargets"].forEach((key) => {
                if (Array.isArray(object[key])) {
                    object[key].forEach(collect);
                }
            });
        };

        (Array.isArray(objects) ? objects : []).forEach(collect);
        return targets;
    }

    function planetTargets() {
        return planetTargetsFromObjects(state.currentSectorObjects);
    }

    function sectorHasDeuteriumRefuelStation() {
        return state.currentSectorObjects.some((object) => object && object.type === "deuterium_refuel_station");
    }

    function inactiveScutRelayTargets() {
        return (Array.isArray(state.currentSectorObjects) ? state.currentSectorObjects : [])
            .filter((object) => object && object.type === "scut_relay" && object.status === "off" && object.id);
    }

    function bookmarkTargetLabel(target) {
        return [objectTypeLabel(target.type || "object"), target.name || target.id].filter(Boolean).join(" ");
    }

    function asteroidTargetLabel(target) {
        return [objectTypeLabel("asteroid"), target && (target.name || target.id)].filter(Boolean).join(" ");
    }

    function sectorObjectInspectionTargetLabel(target) {
        if (!target) {
            return tr("sectorObject", "Sector object");
        }
        if (target.type === "asteroid") {
            return asteroidTargetLabel(target);
        }
        if (target.type === "detached_container") {
            return detachedContainerRecoveryTargetLabel(target);
        }

        return [objectTypeLabel(target.type || "object"), target.name || target.id].filter(Boolean).join(" ");
    }

    function planetTargetLabel(target) {
        return [objectTypeLabel("planet"), target && (target.name || target.id)].filter(Boolean).join(" ");
    }

    function artificialObjectDetection(payload) {
        return payload && payload.artificialObjectDetected && typeof payload.artificialObjectDetected === "object"
            ? payload.artificialObjectDetected
            : null;
    }

    function mannyInCurrentProbeSector(manny) {
        if (!manny || !manny.location || manny.location.type === "probe") {
            return true;
        }

        const relative = mannyRelativeSector(manny);
        const current = state.currentProbeSectorRelative;
        return relative
            && current
            && relative.x === current.x
            && relative.y === current.y
            && relative.z === current.z;
    }

    function detectedDetachedContainerTargets() {
        const targets = [];
        const seen = new Set();
        const add = (target) => {
            if (!target || !target.id || seen.has(target.id)) {
                return;
            }
            seen.add(target.id);
            targets.push(target);
        };

        detachedContainerTargetsFromObjects(state.currentSectorObjects).forEach(add);

        state.currentMannies.forEach((manny) => {
            if (!mannyInCurrentProbeSector(manny)) {
                return;
            }
            const detection = artificialObjectDetection(manny && manny.task);
            if (detection && detection.type === "detached_storage_container" && detection.objectId) {
                add({
                    "id": detection.objectId,
                    "name": tr("detectedDetachedContainer", "Detected detached container"),
                    "source": "asteroid",
                    "hidden": true,
                    "targetObjectId": detection.targetObjectId || (manny && manny.task ? manny.task.targetObjectId || manny.task.objectId || null : null),
                });
            }
        });

        return targets;
    }

    function recoverableDetachedContainerTargets() {
        return detachedContainerTargetsFromObjects(state.currentSectorObjects);
    }

    function sectorObjectInspectionTargetsFromObjects(objects, detachedTargets) {
        const targets = [];
        const seen = new Set();
        const add = (target) => {
            if (!target || !target.id || seen.has(target.id)) {
                return;
            }
            seen.add(target.id);
            targets.push(target);
        };

        asteroidTargetsFromObjects(objects).forEach(add);
        (Array.isArray(detachedTargets) ? detachedTargets : detachedContainerTargetsFromObjects(objects))
            .forEach((target) => add(Object.assign({"type": "detached_container"}, target)));
        (Array.isArray(objects) ? objects : [])
            .filter((object) => object && object.type === "dormant_construct" && object.id)
            .forEach(add);

        return targets;
    }

    function sectorObjectInspectionTargets() {
        return sectorObjectInspectionTargetsFromObjects(state.currentSectorObjects, detectedDetachedContainerTargets());
    }

    function detachedContainerTargetsFromObjects(objects) {
        return (Array.isArray(objects) ? objects : [])
            .filter((object) => object && object.type === "detached_container" && object.id)
            .map((object) => ({
                "id": object.id,
                "name": object.name || object.id,
                "source": object.mode === "hidden_on_asteroid" ? "asteroid" : "drifting",
                "hidden": object.mode === "hidden_on_asteroid",
                "targetObjectId": object.targetObjectId || null,
            }));
    }

    function miningStorageTargetsForTarget(target, containers) {
        if (!target || !target.id) {
            return [];
        }

        return (Array.isArray(containers) ? containers : []).filter((container) => (
            container
            && container.id
            && (
                container.source === "drifting"
                || (
                    container.hidden
                    && container.targetObjectId
                    && container.targetObjectId === target.id
                )
            )
        ));
    }

    function miningStorageTargets(target) {
        return miningStorageTargetsForTarget(target, detectedDetachedContainerTargets());
    }

    function miningStorageTargetLabel(target) {
        const name = target && (target.name || target.id) ? (target.name || target.id) : tr("detachedContainerObject", "Detached container");
        const suffix = target && target.hidden
            ? tr("hiddenOnAsteroid", "hidden on asteroid")
            : tr("detachModeDrifting", "Leave drifting");

        return name + " - " + suffix;
    }

    function miningTaskTargetContainerDetail(payload) {
        const container = payload && payload.targetContainer && typeof payload.targetContainer === "object"
            ? payload.targetContainer
            : null;
        if (!container || !container.id) {
            return "";
        }

        const name = container.name || container.id;
        const mode = container.mode === "hidden_on_asteroid"
            ? tr("detachedContainerHiddenOnAsteroid", "hidden on asteroid")
            : tr("detachedContainerDrifting", "drifting");

        return "<p>" + escaped(window.VNG.formatText(tr("miningTargetContainerDetail", "Storage: {container} ({mode})."), {
            "container": name,
            "mode": mode,
        })) + "</p>";
    }

    function miningStorageTargetOptionsForTarget(target, selected, containers, includeDefault) {
        const targetContainers = miningStorageTargetsForTarget(target, containers);
        const defaultOption = includeDefault ? "<option value=\"\">" + escaped(tr("mineStoreOnProbe", "Probe and linked containers")) + "</option>" : "";
        if (targetContainers.length === 0 && !includeDefault) {
            return "<option value=\"\">-</option>";
        }
        if (targetContainers.length === 0) {
            return defaultOption;
        }

        return defaultOption + targetContainers.map((container) => (
            "<option value=\"" + escaped(container.id) + "\"" + (container.id === selected ? " selected" : "") + ">"
            + escaped(miningStorageTargetLabel(container))
            + "</option>"
        )).join("");
    }

    function miningStorageTargetOptions(target, selected) {
        return miningStorageTargetOptionsForTarget(target, selected, detectedDetachedContainerTargets(), true);
    }

    function detachedContainerRecoveryTargetLabel(target) {
        const name = target && (target.name || target.id) ? (target.name || target.id) : tr("detachedContainerObject", "Detached container");
        return name + (target && target.hidden ? " - " + tr("hiddenOnAsteroid", "hidden on asteroid") : "");
    }

    function currentStorageContainers() {
        return Array.isArray(state.currentInventory && state.currentInventory.containers)
            ? state.currentInventory.containers
            : [];
    }

    function storageContainerLabel(container) {
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

    function detachableStorageContainers() {
        return currentStorageContainers().filter((container) => (
            container
            && container.id
            && container.id !== "probe-core"
            && (container.kind === undefined || container.kind === "container" || String(container.id).startsWith("container-"))
        ));
    }

    function bookmarkItems() {
        return Array.isArray(state.currentInventory && state.currentInventory.items)
            ? state.currentInventory.items.filter((item) => item.type === "waypoint_bookmark")
            : [];
    }

    function integratedCircuitItems() {
        return Array.isArray(state.currentInventory && state.currentInventory.items)
            ? state.currentInventory.items.filter((item) => item.type === "integrated_circuit")
            : [];
    }

    function atmosphericDropKitItems() {
        return Array.isArray(state.currentInventory && state.currentInventory.items)
            ? state.currentInventory.items.filter((item) => item.type === "atmospheric_drop_kit")
            : [];
    }

    function bookmarkTargets() {
        return bookmarkTargetsFromObjects(state.currentSectorObjects);
    }

    function atomicPrinterItem() {
        return Array.isArray(state.currentInventory && state.currentInventory.items)
            ? state.currentInventory.items.find((item) => item.type === "atomic_3d_printer") || null
            : null;
    }

    function availableAtomicPrinterAssistants() {
        return state.currentMannies.filter((manny) => (
            manny
            && manny.id
            && manny.currentTask === null
            && manny.location
            && manny.location.type === "probe"
        ));
    }

    function atomicPrinterAssistant() {
        return state.currentMannies.find((manny) => manny && manny.currentTask === "assisting_atomic_printer") || null;
    }

    function craftingRecipesForFabricator(fabricator) {
        return state.currentCraftingRecipes.filter((recipe) => (
            recipe
            && typeof recipe === "object"
            && Array.isArray(recipe.craftableBy)
            && recipe.craftableBy.includes(fabricator)
        ));
    }

    function fallbackMannyCraftingRecipes() {
        return [{
            "id": "waypoint_bookmark",
            "name": tr("waypointBookmark", "Waypoint bookmark"),
            "craftableBy": ["manny"],
            "description": "A transmitting beacon placed on an object such as an asteroid or planet, or set in orbit around a star or gas giant. Its message can be read by every Neumann probe present in the sector.",
            "ingredients": [{
                "type": "metals",
                "quantity": 0.01,
                "unit": "earth_container_equivalent",
            }],
        }];
    }

    function mannyCraftingRecipes() {
        const recipes = craftingRecipesForFabricator("manny");
        return recipes.length > 0 ? recipes : fallbackMannyCraftingRecipes();
    }

    function atomicPrinterCraftingRecipes() {
        return craftingRecipesForFabricator("atomic_3d_printer");
    }

    function atomicPrinterHasRecipes() {
        return atomicPrinterCraftingRecipes().length > 0;
    }

    function craftingRecipeName(recipe) {
        if (!recipe) {
            return "-";
        }

        return inventoryItemTypeLabel(recipe.id, recipe.name || recipe.id || "-");
    }

    function craftingRecipeDescriptionKey(recipe) {
        return {
            "waypoint_bookmark": "recipeDescriptionWaypointBookmark",
            "steel_bar": "recipeDescriptionSteelBar",
            "steel_plate": "recipeDescriptionSteelPlate",
            "additional_container": "recipeDescriptionAdditionalContainer",
            "micro_conductor": "recipeDescriptionMicroConductor",
            "ceramic_insulator": "recipeDescriptionCeramicInsulator",
            "crystal_substrate": "recipeDescriptionCrystalSubstrate",
            "dopant_matrix": "recipeDescriptionDopantMatrix",
            "integrated_circuit": "recipeDescriptionIntegratedCircuit",
            "electric_motor": "recipeDescriptionElectricMotor",
            "battery_pack": "recipeDescriptionBatteryPack",
            "linear_actuator": "recipeDescriptionLinearActuator",
            "atomic_printer_part": "recipeDescriptionAtomicPrinterPart",
            "deuterium_engine": "recipeDescriptionDeuteriumEngine",
            "thermal_protection_shell": "recipeDescriptionThermalProtectionShell",
            "parachute_pack": "recipeDescriptionParachutePack",
            "descent_guidance_module": "recipeDescriptionDescentGuidanceModule",
            "atmospheric_drop_kit": "recipeDescriptionAtmosphericDropKit",
            "manny": "recipeDescriptionManny",
        }[recipe && recipe.id] || "";
    }

    function craftingRecipeDescription(recipe) {
        if (!recipe) {
            return "";
        }

        const fallback = typeof recipe.description === "string" ? recipe.description : "";
        const key = craftingRecipeDescriptionKey(recipe);

        return key ? tr(key, fallback) : fallback;
    }

    function craftingRecipeById(id, fabricator) {
        const recipes = fabricator === "atomic_3d_printer" ? atomicPrinterCraftingRecipes() : mannyCraftingRecipes();
        return recipes.find((recipe) => recipe.id === id) || null;
    }

    function craftingRecipeOutputType(recipe) {
        const output = recipe && recipe.output && typeof recipe.output === "object" ? recipe.output : {};
        return String(output.type || (recipe && recipe.id) || "");
    }

    function craftingRecipeByOutputType(type) {
        return state.currentCraftingRecipes.find((recipe) => (
            craftingRecipeOutputType(recipe) === type || recipe.id === type
        )) || null;
    }

    function craftRecipeOptions(selected, fabricator) {
        const recipes = fabricator === "atomic_3d_printer" ? atomicPrinterCraftingRecipes() : mannyCraftingRecipes();
        if (recipes.length === 0) {
            return "<option value=\"\">" + escaped(tr("noCraftingRecipes", "No recipes available.")) + "</option>";
        }

        return recipes.map((recipe, index) => {
            const recipeId = String(recipe.id || "");
            const isSelected = recipeId === selected || (!selected && index === 0);

            return "<option value=\"" + escaped(recipeId) + "\"" + (isSelected ? " selected" : "") + ">"
                + escaped(craftingRecipeName(recipe))
                + "</option>";
        }).join("");
    }

    function inventoryResourceAmount(type) {
        if (!state.currentInventory) {
            return 0;
        }

        const normalizedType = normalizeResourceType(type);
        if (normalizedType === "deuterium") {
            return (Array.isArray(state.currentInventory.externalTanks) ? state.currentInventory.externalTanks : [])
                .filter((tank) => normalizeResourceType(tank.type) === "deuterium")
                .reduce((total, tank) => total + Math.max(0, (Number(tank.fillPercent) || 0) / 100), 0);
        }
        if (!Array.isArray(state.currentInventory.resourceStocks)) {
            return 0;
        }

        return state.currentInventory.resourceStocks.reduce((total, stock) => (
            normalizeResourceType(stock.type) === normalizedType
                ? total + Math.max(0, Number(stock.amount) || 0)
                : total
        ), 0);
    }

    function craftIngredientKind(ingredient) {
        if (ingredient && ingredient.kind) {
            return String(ingredient.kind);
        }

        return ingredient && ingredient.unit === "item" ? "item" : "resource";
    }

    function craftIngredientAmount(ingredient) {
        const quantity = Number(ingredient && ingredient.quantity);
        return Number.isFinite(quantity) ? quantity : 0;
    }

    function addCraftPlanResourceCost(resourceCosts, type, quantity) {
        const normalizedType = normalizeResourceType(type);
        const amount = Number(quantity);
        if (!normalizedType || !Number.isFinite(amount) || amount <= 0) {
            return;
        }

        resourceCosts[normalizedType] = Math.round(((resourceCosts[normalizedType] || 0) + amount) * 10000) / 10000;
    }

    function resourceCostsLabel(resourceCosts) {
        const entries = Object.entries(resourceCosts && typeof resourceCosts === "object" ? resourceCosts : {})
            .filter(([, quantity]) => Number(quantity) > 0);
        if (entries.length === 0) {
            return window.VNG.numberValue(0) + " " + tr("containerUnit", "containers");
        }

        return entries.map(([type, quantity]) => (
            window.VNG.numberValue(quantity) + " " + tr("containerUnit", "containers") + " " + resourceTypeLabel(normalizeResourceType(type))
        )).join(", ");
    }

    function inventoryItemCounts() {
        return (Array.isArray(state.currentInventory && state.currentInventory.items)
            ? state.currentInventory.items
            : []
        ).reduce((counts, item) => {
            const type = String(item && item.type || "");
            if (type) {
                counts[type] = (counts[type] || 0) + 1;
            }

            return counts;
        }, {});
    }

    function recipeCanBeAutoCrafted(recipe) {
        return recipe
            && Array.isArray(recipe.craftableBy)
            && (recipe.craftableBy.includes("manny") || recipe.craftableBy.includes("atomic_3d_printer"));
    }

    function resolveCraftRecipe(recipe, itemCounts, resourceCosts, path) {
        const recipeId = String(recipe && recipe.id || craftingRecipeOutputType(recipe));
        if (!recipe || !recipeId || path.includes(recipeId)) {
            return {"canResolve": false, "durationSeconds": 0};
        }

        let canResolve = true;
        let durationSeconds = Math.max(0, Number(recipe.durationSeconds) || 0);
        (Array.isArray(recipe.ingredients) ? recipe.ingredients : []).forEach((ingredient) => {
            if (craftIngredientKind(ingredient) !== "item") {
                addCraftPlanResourceCost(resourceCosts, ingredient.type, craftIngredientAmount(ingredient));
                return;
            }

            const type = String(ingredient.type || "");
            const required = Math.ceil(Math.max(0, craftIngredientAmount(ingredient)));
            const available = Math.max(0, itemCounts[type] || 0);
            const consumed = Math.min(required, available);
            itemCounts[type] = available - consumed;
            const missing = required - consumed;
            if (missing <= 0) {
                return;
            }

            const componentRecipe = craftingRecipeByOutputType(type);
            if (!recipeCanBeAutoCrafted(componentRecipe)) {
                canResolve = false;
                return;
            }

            for (let index = 0; index < missing; index += 1) {
                const componentPlan = resolveCraftRecipe(componentRecipe, itemCounts, resourceCosts, path.concat([recipeId]));
                canResolve = canResolve && componentPlan.canResolve;
                durationSeconds += componentPlan.durationSeconds;
            }
        });

        return {canResolve, durationSeconds};
    }

    function craftAvailability(recipe) {
        const result = {
            "canCraft": false,
            "durationSeconds": 0,
            "itemStatuses": [],
            "resourceStatuses": [],
        };
        if (!recipe) {
            return result;
        }

        const ingredients = Array.isArray(recipe.ingredients) ? recipe.ingredients : [];
        const resourceCosts = {};
        const itemCounts = inventoryItemCounts();
        let canCraft = true;
        let durationSeconds = Math.max(0, Number(recipe.durationSeconds) || 0);
        ingredients.forEach((ingredient) => {
            if (craftIngredientKind(ingredient) !== "item") {
                addCraftPlanResourceCost(resourceCosts, ingredient.type, craftIngredientAmount(ingredient));
                return;
            }

            const type = String(ingredient.type || "");
            const required = Math.ceil(Math.max(0, craftIngredientAmount(ingredient)));
            const available = Math.max(0, itemCounts[type] || 0);
            const consumed = Math.min(required, available);
            itemCounts[type] = available - consumed;
            const missing = required - consumed;
            let craftedFromResources = 0;
            if (missing > 0) {
                const componentRecipe = craftingRecipeByOutputType(type);
                if (!recipeCanBeAutoCrafted(componentRecipe)) {
                    canCraft = false;
                } else {
                    for (let index = 0; index < missing; index += 1) {
                        const componentPlan = resolveCraftRecipe(componentRecipe, itemCounts, resourceCosts, [String(recipe.id || "")]);
                        canCraft = canCraft && componentPlan.canResolve;
                        durationSeconds += componentPlan.durationSeconds;
                        craftedFromResources += componentPlan.canResolve ? 1 : 0;
                    }
                }
            }

            result.itemStatuses.push({
                type,
                required,
                available,
                missing,
                craftedFromResources,
                "canResolve": missing === 0 || craftedFromResources === missing,
            });
        });

        result.resourceStatuses = Object.entries(resourceCosts).map(([type, required]) => {
            const available = inventoryResourceAmount(type);
            const hasEnough = available + 0.00001 >= Number(required);
            canCraft = canCraft && hasEnough;

            return {
                type,
                "required": Number(required),
                available,
                hasEnough,
            };
        });
        canCraft = canCraft && result.itemStatuses.every((status) => status.canResolve);
        result.canCraft = canCraft;
        result.durationSeconds = durationSeconds;

        return result;
    }

    function canCraftRecipe(recipe) {
        return craftAvailability(recipe).canCraft;
    }

    function renderCraftIngredients(recipe) {
        const availability = craftAvailability(recipe);
        if (availability.itemStatuses.length === 0 && availability.resourceStatuses.length === 0) {
            return "<span class=\"manny-craft-ingredients-title\">" + escaped(tr("craftIngredientsRequired", "Required ingredients")) + "</span>"
                + "<p>" + escaped(tr("noCraftIngredients", "No ingredients required.")) + "</p>";
        }

        return "<span class=\"manny-craft-ingredients-title\">" + escaped(tr("craftIngredientsRequired", "Required ingredients")) + "</span>"
            + "<ul>"
            + availability.itemStatuses.map((status) => {
                const detail = status.craftedFromResources > 0
                    ? window.VNG.formatText(tr("ingredientItemCraftedLine", "{required} required - {available} available - {crafted} crafted from resources"), {
                        "required": status.required,
                        "available": status.available,
                        "crafted": status.craftedFromResources,
                    })
                    : window.VNG.formatText(tr("ingredientItemStockLine", "{required} required - {available} available"), {
                        "required": status.required,
                        "available": status.available,
                    });

                return "<li class=\"" + (status.canResolve ? "available" : "missing") + "\">"
                    + "<span>" + escaped(inventoryItemTypeLabel(status.type, status.type)) + "</span>"
                    + "<b>" + escaped(detail) + "</b>"
                    + "</li>";
            }).join("")
            + availability.resourceStatuses.map((status) => {
                const detail = window.VNG.formatText(tr("ingredientStockLine", "{required} required - {available} available"), {
                    "required": window.VNG.numberValue(status.required) + " " + tr("containerUnit", "containers"),
                    "available": window.VNG.numberValue(status.available) + " " + tr("containerUnit", "containers"),
                });

                return "<li class=\"" + (status.hasEnough ? "available" : "missing") + "\">"
                    + "<span>" + escaped(resourceTypeLabel(normalizeResourceType(status.type))) + "</span>"
                    + "<b>" + escaped(detail) + "</b>"
                    + "</li>";
            }).join("")
            + "</ul>"
            + "<p class=\"manny-craft-duration\">" + escaped(tr("craftingDuration", "Duration") + " " + window.VNG.duration(availability.durationSeconds, tr)) + "</p>";
    }

    function availableProbeImprovements() {
        return (Array.isArray(state.currentProbeImprovements) ? state.currentProbeImprovements : [])
            .filter((improvement) => improvement && improvement.available === true && improvement.done !== true);
    }

    function probeImprovementById(id) {
        return availableProbeImprovements().find((improvement) => String(improvement.id || "") === String(id || "")) || null;
    }

    function probeImprovementAvailability(improvement) {
        const result = {
            "canImprove": false,
            "itemStatuses": [],
            "resourceStatuses": [],
        };
        if (!improvement) {
            return result;
        }

        const itemCounts = inventoryItemCounts();
        let canImprove = true;
        (Array.isArray(improvement.ingredients) ? improvement.ingredients : []).forEach((ingredient) => {
            const type = String(ingredient && ingredient.type || "");
            const required = Number(ingredient && ingredient.quantity) || 0;
            if (!type || required <= 0) {
                return;
            }
            if (craftIngredientKind(ingredient) === "item") {
                const requiredItems = Math.ceil(required);
                const available = Math.max(0, itemCounts[type] || 0);
                const hasEnough = available >= requiredItems;
                canImprove = canImprove && hasEnough;
                result.itemStatuses.push({
                    type,
                    "required": requiredItems,
                    available,
                    hasEnough,
                });
                return;
            }

            const available = inventoryResourceAmount(type);
            const hasEnough = available + 0.00001 >= required;
            canImprove = canImprove && hasEnough;
            result.resourceStatuses.push({
                type,
                "required": required,
                available,
                hasEnough,
            });
        });

        result.canImprove = canImprove;
        return result;
    }

    function renderProbeImprovementIngredients(improvement) {
        const availability = probeImprovementAvailability(improvement);
        if (availability.itemStatuses.length === 0 && availability.resourceStatuses.length === 0) {
            return "<span class=\"manny-craft-ingredients-title\">" + escaped(tr("craftIngredientsRequired", "Required ingredients")) + "</span>"
                + "<p>" + escaped(tr("noCraftIngredients", "No ingredients required.")) + "</p>";
        }

        return "<span class=\"manny-craft-ingredients-title\">" + escaped(tr("craftIngredientsRequired", "Required ingredients")) + "</span>"
            + "<ul>"
            + availability.itemStatuses.map((status) => {
                const detail = window.VNG.formatText(tr("ingredientItemStockLine", "{required} required - {available} available"), {
                    "required": status.required,
                    "available": status.available,
                });

                return "<li class=\"" + (status.hasEnough ? "available" : "missing") + "\">"
                    + "<span>" + escaped(inventoryItemTypeLabel(status.type, status.type)) + "</span>"
                    + "<b>" + escaped(detail) + "</b>"
                    + "</li>";
            }).join("")
            + availability.resourceStatuses.map((status) => {
                const detail = window.VNG.formatText(tr("ingredientStockLine", "{required} required - {available} available"), {
                    "required": window.VNG.numberValue(status.required) + " " + tr("containerUnit", "containers"),
                    "available": window.VNG.numberValue(status.available) + " " + tr("containerUnit", "containers"),
                });

                return "<li class=\"" + (status.hasEnough ? "available" : "missing") + "\">"
                    + "<span>" + escaped(resourceTypeLabel(normalizeResourceType(status.type))) + "</span>"
                    + "<b>" + escaped(detail) + "</b>"
                    + "</li>";
            }).join("")
            + "</ul>"
            + (improvement && Number(improvement.durationSeconds) > 0
                ? "<p class=\"manny-craft-duration\">" + escaped(tr("craftingDuration", "Duration") + " " + window.VNG.duration(Number(improvement.durationSeconds), tr)) + "</p>"
                : "");
    }

    function emptyAdditionalContainers() {
        return detachableStorageContainers().filter((container) => (
            Math.max(0, Number(container && container.usedCapacity) || 0) <= 0.00001
        ));
    }

    function probeAssemblyAvailability() {
        const itemCounts = inventoryItemCounts();
        const itemStatuses = PROBE_ASSEMBLY_COMPONENTS.map((component) => {
            const available = Math.max(0, itemCounts[component.type] || 0);
            return {
                "type": component.type,
                "required": component.quantity,
                available,
                "hasEnough": available >= component.quantity,
            };
        });

        return {
            itemStatuses,
            "emptyContainers": emptyAdditionalContainers(),
            "hasComponents": itemStatuses.every((status) => status.hasEnough),
        };
    }

    function renderProbeAssemblyIngredients() {
        const availability = probeAssemblyAvailability();
        return "<span class=\"manny-craft-ingredients-title\">" + escaped(tr("craftIngredientsRequired", "Required ingredients")) + "</span>"
            + "<ul>"
            + availability.itemStatuses.map((status) => {
                const detail = window.VNG.formatText(tr("ingredientItemStockLine", "{required} required - {available} available"), {
                    "required": status.required,
                    "available": status.available,
                });

                return "<li class=\"" + (status.hasEnough ? "available" : "missing") + "\">"
                    + "<span>" + escaped(inventoryItemTypeLabel(status.type, status.type)) + "</span>"
                    + "<b>" + escaped(detail) + "</b>"
                    + "</li>";
            }).join("")
            + "<li class=\"" + (availability.emptyContainers.length >= 2 ? "available" : "missing") + "\">"
            + "<span>" + escaped(tr("emptyStorageContainers", "Empty containers")) + "</span>"
            + "<b>" + escaped(window.VNG.formatText(tr("ingredientItemStockLine", "{required} required - {available} available"), {
                "required": 2,
                "available": availability.emptyContainers.length,
            })) + "</b>"
            + "</li>"
            + "</ul>"
            + "<p class=\"manny-craft-duration\">" + escaped(tr("craftingDuration", "Duration") + " " + window.VNG.duration(10800, tr)) + "</p>";
    }

    function updateCraftForm(form) {
        if (!form) {
            return;
        }

        const select = form.querySelector(".manny-craft-recipe");
        const descriptionNode = form.querySelector(".manny-craft-description");
        const ingredientsNode = form.querySelector(".manny-craft-ingredients");
        const button = form.querySelector(".manny-craft-button, .printer-craft-button");
        if (!select) {
            return;
        }

        const selected = select.value;
        const fabricator = form.dataset.fabricator || "manny";
        select.innerHTML = craftRecipeOptions(selected, fabricator);
        const recipe = craftingRecipeById(select.value, fabricator);
        const canCraft = canCraftRecipe(recipe);
        if (descriptionNode) {
            const description = craftingRecipeDescription(recipe);
            descriptionNode.textContent = description;
            descriptionNode.hidden = description === "";
        }
        if (ingredientsNode) {
            ingredientsNode.innerHTML = renderCraftIngredients(recipe);
        }
        if (button) {
            button.disabled = !canCraft;
            button.title = canCraft ? "" : tr("missingCraftIngredients", "Insufficient ingredients.");
            button.setAttribute("aria-disabled", canCraft ? "false" : "true");
        }
    }

    function updateMannyCraftForms() {
        document.querySelectorAll(".manny-craft-form, .printer-craft-form").forEach(updateCraftForm);
    }

    function updateProbeImprovementForm(form) {
        if (!form) {
            return;
        }

        const improvements = availableProbeImprovements();
        const select = form.querySelector(".manny-probe-improvement");
        const descriptionNode = form.querySelector(".manny-probe-improvement-description");
        const ingredientsNode = form.querySelector(".manny-probe-improvement-ingredients");
        const emptyNode = form.querySelector(".manny-probe-improvement-empty");
        const button = form.querySelector(".manny-improve-probe-button");
        if (improvements.length === 0) {
            if (emptyNode) {
                emptyNode.textContent = tr("noProbeImprovementAvailable", "Aucune amélioration n'est disponible");
            }
            if (button) {
                button.disabled = true;
                button.setAttribute("aria-disabled", "true");
            }
            return;
        }

        const selected = select ? select.value : "";
        if (select) {
            select.innerHTML = probeImprovementOptions(selected);
        }
        const improvement = probeImprovementById(select ? select.value : selected) || improvements[0] || null;
        const availability = probeImprovementAvailability(improvement);
        if (descriptionNode) {
            descriptionNode.textContent = improvement && improvement.description ? improvement.description : "";
            descriptionNode.hidden = !descriptionNode.textContent;
        }
        if (ingredientsNode) {
            ingredientsNode.innerHTML = renderProbeImprovementIngredients(improvement);
        }
        if (button) {
            button.disabled = !improvement || !availability.canImprove;
            button.setAttribute("aria-disabled", button.disabled ? "true" : "false");
            button.title = improvement && !availability.canImprove ? tr("missingProbeImprovementIngredients", "Insufficient resources for this improvement.") : "";
        }
    }

    function updateProbeImprovementForms() {
        document.querySelectorAll(".manny-improve-probe-form").forEach(updateProbeImprovementForm);
    }

    function selectedResourceLabels(types) {
        const resources = Array.isArray(types) ? types : (types ? [types] : []);
        return resources.map(resourceTypeLabel).join(", ");
    }

    function remainingMinutesText(endAt, now) {
        if (!Number.isFinite(endAt)) {
            return "";
        }
        const minutes = Math.max(0, Math.ceil((endAt - now) / 60000));

        return window.VNG.numberValue(minutes) + " min";
    }

    function progressDisplayText(progress, endAt, now) {
        const percent = window.VNG.numberValue(progress, "%");
        const remaining = remainingMinutesText(endAt, now);

        return remaining ? percent + " · " + remaining : percent;
    }

    function progressText(manny) {
        return progressDisplayText(
            manny.taskProgressPercent,
            Date.parse(manny.taskEstimatedEndTime || ""),
            Date.now()
        );
    }

    function progressDataAttributes(manny) {
        const progress = Number(manny.taskProgressPercent);
        const endAt = Date.parse(manny.taskEstimatedEndTime || "");
        if (!manny.currentTask || !Number.isFinite(progress) || !Number.isFinite(endAt)) {
            return "";
        }

        return "data-progress-start=\"" + escaped(Math.max(0, Math.min(100, progress))) + "\""
            + " data-progress-observed-at=\"" + escaped(Date.now()) + "\""
            + " data-progress-end-at=\"" + escaped(endAt) + "\"";
    }

    function progressValueHtml(manny) {
        const attributes = progressDataAttributes(manny);

        return "<span class=\"manny-task-progress-value\"" + (attributes ? " " + attributes : "") + ">"
            + escaped(progressText(manny))
            + "</span>";
    }

    function clearProgressTick() {
        if (progressTickTimer !== null) {
            window.clearTimeout(progressTickTimer);
            progressTickTimer = null;
        }
    }

    function updateLiveProgressValues() {
        const progressNodes = Array.from(document.querySelectorAll("#manny-list .manny-task-progress-value[data-progress-end-at]"));
        const now = Date.now();
        let hasPendingProgress = false;

        progressNodes.forEach((node) => {
            const startProgress = Number(node.dataset.progressStart);
            const observedAt = Number(node.dataset.progressObservedAt);
            const endAt = Number(node.dataset.progressEndAt);
            if (!Number.isFinite(startProgress) || !Number.isFinite(observedAt) || !Number.isFinite(endAt)) {
                return;
            }

            const remainingDuration = Math.max(1, endAt - observedAt);
            const ratio = Math.max(0, Math.min(1, (now - observedAt) / remainingDuration));
            const progress = Math.max(startProgress, Math.min(100, startProgress + ((100 - startProgress) * ratio)));
            node.textContent = progressDisplayText(progress, endAt, now);
            if (progress < 100 && now < endAt) {
                hasPendingProgress = true;
            }
        });

        progressTickTimer = hasPendingProgress ? window.setTimeout(updateLiveProgressValues, PROGRESS_TICK_MS) : null;
    }

    function scheduleProgressUpdates() {
        clearProgressTick();
        updateLiveProgressValues();
    }

    function miningResourceSummary(manny) {
        const payload = manny && manny.task ? manny.task : {};
        const resources = selectedResourceLabels(payload.resourceTypes || payload.resourceType);

        return resources || "";
    }

    function mannyAccordionTaskText(manny, taskName) {
        if (!manny) {
            return taskName;
        }
        if (manny.currentTask === null) {
            return mannySectorVisibleViaScut(manny)
                ? [taskName, tr("mannyRemoteScutTask", "Remote sector via SCUT")].join(" - ")
                : taskName;
        }

        const parts = [taskName, progressText(manny)];
        if (manny.currentTask === "mining") {
            const resources = miningResourceSummary(manny);
            if (resources) {
                parts.push(resources);
            }
        }
        if (mannySectorVisibleViaScut(manny)) {
            parts.push(tr("mannyRemoteScutTask", "Remote sector via SCUT"));
        }

        return parts.join(" - ");
    }

    function mannyAccordionTaskHtml(manny, taskName) {
        if (!manny) {
            return escaped(taskName);
        }
        if (manny.currentTask === null) {
            return mannySectorVisibleViaScut(manny)
                ? "<span class=\"manny-accordion-task-status\">" + escaped(taskName) + "</span>"
                    + "<span class=\"manny-accordion-task-scut\">" + escaped(tr("mannyRemoteScutTask", "Remote sector via SCUT")) + "</span>"
                : escaped(taskName);
        }

        const resources = manny.currentTask === "mining" ? miningResourceSummary(manny) : "";

        return "<span class=\"manny-accordion-task-status\">" + escaped(taskName) + "</span>"
            + "<span class=\"manny-accordion-task-progress\">" + progressValueHtml(manny) + "</span>"
            + (resources ? "<span class=\"manny-accordion-task-resources\">" + escaped(resources) + "</span>" : "")
            + (mannySectorVisibleViaScut(manny) ? "<span class=\"manny-accordion-task-scut\">" + escaped(tr("mannyRemoteScutTask", "Remote sector via SCUT")) + "</span>" : "");
    }

    function miningTaskTarget(payload) {
        if (payload && payload.target) {
            return payload.target;
        }

        return state.currentMannyMineTargets.find((target) => target.id === (payload && payload.objectId)) || null;
    }

    function renderMannyTaskPanel(manny) {
        const payload = manny.task || {};
        const progress = progressValueHtml(manny);
        const recallLabel = mannyTaskVisibleViaScut(manny)
            ? tr("abandonRemoteMannyTask", "Abandon task")
            : null;
        if (manny.currentTask === "repair") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("repairInProgress", "Repair in progress")) + "</h4>"
                + "<p>" + escaped(window.VNG.formatText(tr("repairTaskDetail", "{percent}% integrity scheduled, {metals} metal containers committed."), {
                    "percent": window.VNG.numberValue(payload.integrityPercent),
                    "metals": window.VNG.numberValue(payload.metalsCost),
                })) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "<button class=\"manny-recall-button\" type=\"button\">" + escaped(recallLabel || tr("cancelRepair", "Cancel repairs")) + "</button>"
                + "</section>";
        }
        if (manny.currentTask === "mining") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("miningInProgress", "Mining in progress")) + "</h4>"
                + "<p>" + escaped(window.VNG.formatText(tr("miningTaskDetail", "{resources} on {target}."), {
                    "resources": selectedResourceLabels(payload.resourceTypes || payload.resourceType),
                    "target": miningTargetDetails(miningTaskTarget(payload)),
                })) + "</p>"
                + miningTaskTargetContainerDetail(payload)
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "<button class=\"manny-recall-button\" type=\"button\">" + escaped(recallLabel || tr("recall", "Recall")) + "</button>"
                + "</section>";
        }
        if (manny.currentTask === "crafting") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("craftingInProgress", "Crafting in progress")) + "</h4>"
                + "<p>" + escaped(window.VNG.formatText(tr("craftingTaskDetail", "{recipe}, {resources} committed."), {
                    "recipe": payload.recipeName || tr("waypointBookmark", "Waypoint bookmark"),
                    "resources": resourceCostsLabel(payload.resourceCosts || {"metals": payload.metalsCost || 0}),
                })) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "<button class=\"manny-recall-button\" type=\"button\">" + escaped(recallLabel || tr("cancelCrafting", "Cancel crafting")) + "</button>"
                + "</section>";
        }
        if (manny.currentTask === "improving_probe") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("improvingProbeInProgress", "Probe improvement in progress")) + "</h4>"
                + "<p>" + escaped(window.VNG.formatText(tr("improvingProbeTaskDetail", "{improvement} is being installed."), {
                    "improvement": payload.improvementName || payload.improvement || tr("probeImprovement", "Probe improvement"),
                })) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "<button class=\"manny-recall-button\" type=\"button\">" + escaped(recallLabel || tr("cancelCrafting", "Cancel crafting")) + "</button>"
                + "</section>";
        }
        if (manny.currentTask === "assembling_probe") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("assemblingProbeInProgress", "Probe assembly in progress")) + "</h4>"
                + "<p>" + escaped(tr("assemblingProbeTaskDetail", "A new probe is being assembled outside the current hull.")) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "<button class=\"manny-recall-button\" type=\"button\">" + escaped(recallLabel || tr("cancelCrafting", "Cancel crafting")) + "</button>"
                + "</section>";
        }
        if (manny.currentTask === "assisting_atomic_printer") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("atomicPrinterAssistanceInProgress", "Atomic printer assistance in progress")) + "</h4>"
                + "<p>" + escaped(window.VNG.formatText(tr("atomicPrinterAssistanceTaskDetail", "{recipe}, loading and unloading support."), {
                    "recipe": payload.recipeName || tr("integratedCircuit", "Integrated circuit"),
                })) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "<button class=\"manny-recall-button\" type=\"button\">" + escaped(recallLabel || tr("cancelCrafting", "Cancel crafting")) + "</button>"
                + "</section>";
        }
        if (manny.currentTask === "waiting_for_space") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("waitingForSpace", "Waiting for space")) + "</h4>"
                + "<p>" + escaped(tr("waitingForSpaceCargoHint", "The Manny is waiting outside until the probe can accept its cargo and storage slot.")) + "</p>"
                + "<button class=\"manny-drop-cargo-button\" type=\"button\">" + escaped(tr("dropMannyCargo", "Bring Manny back without cargo")) + "</button>"
                + "</section>";
        }
        if (manny.currentTask === "salvage") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("salvageInProgress", "Recovery in progress")) + "</h4>"
                + "<p>" + escaped(window.VNG.formatText(tr("salvageTaskDetail", "{target} will be checked and recovered after the delay."), {
                    "target": salvageTargetLabel(payload.target || {}),
                })) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "</section>";
        }
        if (manny.currentTask === "turning_on_scut_relay") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("turningOnScutRelayInProgress", "SCUT relay activation in progress")) + "</h4>"
                + "<p>" + escaped(window.VNG.formatText(tr("turningOnScutRelayTaskDetail", "{target} is receiving its final circuit."), {
                    "target": payload.target ? salvageTargetLabel(payload.target) : (payload.relayId || tr("scutRelayObject", "SCUT relay")),
                })) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "</section>";
        }
        if (manny.currentTask === "installing_waypoint_bookmark") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("bookmarkInstallInProgress", "Waypoint bookmark installation in progress")) + "</h4>"
                + "<p>" + escaped(window.VNG.formatText(tr("bookmarkInstallTaskDetail", "{name} toward {target}."), {
                    "name": payload.name || tr("waypointBookmark", "Waypoint bookmark"),
                    "target": bookmarkTargetLabel(payload.target || {}),
                })) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "</section>";
        }
        if (manny.currentTask === "detaching_storage_container") {
            const modeLabel = payload.mode === "hidden_on_asteroid"
                ? tr("detachModeHiddenOnAsteroid", "Hide on an asteroid")
                : tr("detachModeDrifting", "Leave drifting");
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("detachStorageInProgress", "Container detachment in progress")) + "</h4>"
                + "<p>" + escaped(window.VNG.formatText(tr("detachStorageTaskDetail", "{container} will be detached: {mode}."), {
                    "container": payload.containerId || tr("storageContainer", "Container"),
                    "mode": modeLabel,
                })) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "</section>";
        }
        if (manny.currentTask === "dropping_storage_container") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("dropStorageInProgress", "Container drop in progress")) + "</h4>"
                + "<p>" + escaped(window.VNG.formatText(tr("dropStorageTaskDetail", "{container} is descending toward {target}."), {
                    "container": payload.containerId || tr("storageContainer", "Container"),
                    "target": planetTargetLabel(payload.target || {"id": payload.planetId || payload.targetObjectId}),
                })) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "</section>";
        }
        if (manny.currentTask === "inspecting_sector_object" || manny.currentTask === "inspecting_asteroid") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("sectorObjectInspectionInProgress", "Sector object inspection in progress")) + "</h4>"
                + "<p>" + escaped(window.VNG.formatText(tr("sectorObjectInspectionTaskDetail", "{target} is being inspected."), {
                    "target": bookmarkTargetLabel(payload.target || {}),
                })) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "</section>";
        }
        if (manny.currentTask === "refilling_deuterium_tank") {
            return "<section class=\"manny-task-panel\">"
                + "<h4>" + escaped(tr("deuteriumRefillInProgress", "Deuterium refill in progress")) + "</h4>"
                + "<p>" + escaped(tr("deuteriumRefillTaskDetail", "The Manny is topping up the probe deuterium tank.")) + "</p>"
                + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
                + "</section>";
        }

        return "<section class=\"manny-task-panel\">"
            + "<h4>" + escaped(taskLabel(manny.currentTask)) + "</h4>"
            + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + progress + "</p>"
            + "</section>";
    }

    function actionNeedsProbeInventory(actionId) {
        return PROBE_INVENTORY_ACTIONS.has(actionId);
    }

    function renderMannyActionAccordion(id, title, actionId, formHtml) {
        const lazy = actionNeedsProbeInventory(actionId);
        return "<section class=\"manny-action-section manny-action-accordion\">"
            + "<button class=\"manny-action-accordion-trigger\" type=\"button\" aria-expanded=\"false\" aria-controls=\"" + escaped(id) + "\" data-action-id=\"" + escaped(actionId) + "\">"
            + "<span>" + escaped(title) + "</span>"
            + "</button>"
            + "<div id=\"" + escaped(id) + "\" class=\"manny-action-accordion-panel\" data-action-id=\"" + escaped(actionId) + "\" data-lazy-inventory=\"" + (lazy ? "1" : "0") + "\" hidden>"
            + (lazy ? "" : formHtml)
            + "</div>"
            + "</section>";
    }

    function renderMannyActionGroupAccordion(id, title, actions) {
        const items = Array.isArray(actions) ? actions.filter(Boolean) : [];
        if (items.length === 0) {
            return "";
        }

        return "<section class=\"manny-action-section manny-action-accordion manny-action-group\">"
            + "<button class=\"manny-action-accordion-trigger manny-action-group-trigger\" type=\"button\" aria-expanded=\"false\" aria-controls=\"" + escaped(id) + "\">"
            + "<span>" + escaped(title) + "</span>"
            + "</button>"
            + "<div id=\"" + escaped(id) + "\" class=\"manny-action-accordion-panel manny-action-group-panel\" hidden>"
            + items.join("")
            + "</div>"
            + "</section>";
    }

    function renderRepairForm() {
        return "<form class=\"manny-repair-form manny-form\">"
            + "<label>" + escaped(tr("repairPercent", "Integrity to restore")) + "<input name=\"integrityPercent\" type=\"number\" min=\"1\" max=\"100\" step=\"1\" value=\"1\"></label>"
            + "<button type=\"submit\">" + escaped(tr("repair", "Repair")) + "</button>"
            + "</form>";
    }

    function probeImprovementOptions(selected) {
        const improvements = availableProbeImprovements();
        if (improvements.length === 0) {
            return "<option value=\"\">-</option>";
        }

        return improvements.map((improvement, index) => {
            const id = String(improvement.id || "");
            const isSelected = id === selected || (!selected && index === 0);

            return "<option value=\"" + escaped(id) + "\"" + (isSelected ? " selected" : "") + ">"
                + escaped(improvement.name || id)
                + "</option>";
        }).join("");
    }

    function renderImproveProbeForm() {
        const improvements = availableProbeImprovements();
        const improvement = improvements[0] || null;
        const availability = probeImprovementAvailability(improvement);
        const hasImprovement = improvements.length > 0;

        return "<form class=\"manny-improve-probe-form manny-form\">"
            + (hasImprovement
                ? "<label>" + escaped(tr("probeImprovement", "Probe improvement")) + "<select class=\"manny-probe-improvement\" name=\"improvement\">" + probeImprovementOptions("") + "</select></label>"
                    + "<p class=\"manny-probe-improvement-description\">" + escaped(improvement && improvement.description ? improvement.description : "") + "</p>"
                    + "<div class=\"manny-probe-improvement-ingredients\" aria-live=\"polite\">" + renderProbeImprovementIngredients(improvement) + "</div>"
                : "<p class=\"manny-probe-improvement-empty\">" + escaped(tr("noProbeImprovementAvailable", "Aucune amélioration n'est disponible")) + "</p>")
            + "<button class=\"manny-improve-probe-button\" type=\"submit\"" + (!hasImprovement || !availability.canImprove ? " disabled aria-disabled=\"true\"" : "") + ">"
            + escaped(tr("improveProbe", "Improve the probe")) + "</button>"
            + "</form>";
    }

    function mineTargetOptionsForTargets(targets, selected) {
        if (targets.length === 0) {
            return "<option value=\"\">-</option>";
        }

        return targets.map((target) => (
            "<option value=\"" + escaped(target.id) + "\"" + (target.id === selected ? " selected" : "") + ">"
            + escaped(mineTargetLabel(target))
            + "</option>"
        )).join("");
    }

    function mineTargetOptions(selected) {
        return mineTargetOptionsForTargets(state.currentMannyMineTargets, selected);
    }

    function resourceProfileForMineSelection(target, selectedResources) {
        const available = resourceTypesForTarget(target);
        const selected = selectedResources.filter((type) => available.includes(type));
        const effectiveSelection = selected.length > 0 ? selected : available;
        const composition = resourceCompositionForTarget(target);
        const total = effectiveSelection.reduce((sum, type) => sum + Math.max(0, Number(composition[type]) || 0), 0);
        if (total <= 0) {
            return {};
        }

        return effectiveSelection.reduce((profile, type) => {
            profile[type] = Math.max(0, Number(composition[type]) || 0) / total;
            return profile;
        }, {});
    }

    function mineTargetMaxAmount(target, selectedResources) {
        if (!target) {
            return MANNY_MINING_AMOUNT_MAX;
        }

        const amounts = target.resourceAmounts && typeof target.resourceAmounts === "object" ? target.resourceAmounts : null;
        if (!amounts) {
            return MANNY_MINING_AMOUNT_MAX;
        }

        const profile = resourceProfileForMineSelection(target, selectedResources);
        const limits = Object.entries(profile)
            .filter(([, share]) => Number(share) > 0)
            .map(([type, share]) => {
                const available = Number(amounts[type]);
                return Number.isFinite(available) ? Math.max(0, available) / Number(share) : null;
            })
            .filter((value) => value !== null && Number.isFinite(value));
        if (limits.length === 0) {
            return MANNY_MINING_AMOUNT_MAX;
        }

        const capped = Math.min(MANNY_MINING_AMOUNT_MAX, ...limits);
        return Math.max(0, Math.floor(capped * 100) / 100);
    }

    function miningAmountLabel(maxAmount) {
        return window.VNG.formatText(tr("targetAmountWithMax", "Amount (max. {max})"), {
            "max": window.VNG.numberValue(maxAmount),
        });
    }

    function mineResourceOptions(target, selectedResources) {
        const available = resourceTypesForTarget(target);
        const selected = selectedResources.filter((type) => available.includes(type));
        const effectiveSelection = selected.length > 0 ? selected : available;

        return MINING_RESOURCE_TYPES.map((type) => {
            const disabled = !available.includes(type);
            const isSelected = effectiveSelection.includes(type);

            return "<option value=\"" + escaped(type) + "\""
                + (disabled ? " disabled" : "")
                + (isSelected ? " selected" : "")
                + ">" + escaped(resourceTypeLabel(type)) + "</option>";
        }).join("");
    }

    function remoteMannyMineContext(manny) {
        const key = relativeSectorKey(mannyRelativeSector(manny));
        const scan = remoteSectorScanForKey(key);
        const objects = scan && Array.isArray(scan.objects) ? scan.objects : [];

        return {
            "key": key,
            "status": scan ? scan.status : "loading",
            "targets": mineTargetsFromObjects(objects),
            "storageTargets": detachedContainerTargetsFromObjects(objects),
        };
    }

    function remoteMannyMineStateHash(manny) {
        if (!mannyRemoteIdleViaScut(manny)) {
            return "";
        }
        const context = remoteMannyMineContext(manny);

        return stableHashPayload({
            "key": context.key,
            "status": context.status,
            "targets": context.targets.map((target) => target.id),
            "storageTargets": context.storageTargets.map((target) => target.id + ":" + (target.targetObjectId || "")),
        });
    }

    function remoteMannyInspectContext(manny) {
        const key = relativeSectorKey(mannyRelativeSector(manny));
        const scan = remoteSectorScanForKey(key);
        const objects = scan && Array.isArray(scan.objects) ? scan.objects : [];

        return {
            "key": key,
            "status": scan ? scan.status : "loading",
            "targets": sectorObjectInspectionTargetsFromObjects(objects),
        };
    }

    function remoteMannyInspectStateHash(manny) {
        if (!mannyRemoteIdleViaScut(manny)) {
            return "";
        }
        const context = remoteMannyInspectContext(manny);

        return stableHashPayload({
            "key": context.key,
            "status": context.status,
            "targets": context.targets.map((target) => target.id),
        });
    }

    function renderMineFormWithContext(context) {
        const targets = Array.isArray(context && context.targets) ? context.targets : state.currentMannyMineTargets;
        const storageTargets = Array.isArray(context && context.storageTargets) ? context.storageTargets : detectedDetachedContainerTargets();
        const requireExternalStorage = Boolean(context && context.requireExternalStorage);
        const sectorKey = context && context.sectorKey ? context.sectorKey : "";
        const status = context && context.status ? context.status : "loaded";
        const mineTarget = targets[0] || null;
        const initialResources = resourceTypesForTarget(mineTarget);
        const mineAmountMax = mineTargetMaxAmount(mineTarget, initialResources);
        const mineStorageTargets = miningStorageTargetsForTarget(mineTarget, storageTargets);
        const externalStorageDisabled = requireExternalStorage && initialResources.includes("deuterium");
        const disabled = !mineTarget || mineAmountMax < 0.01 || (requireExternalStorage && (mineStorageTargets.length === 0 || externalStorageDisabled));
        const hint = status === "unavailable"
            ? tr("remoteMannySectorUnavailable", "Remote sector data is unavailable.")
            : (requireExternalStorage && mineStorageTargets.length === 0
                ? tr("noRemoteMiningStorageTarget", "No detached container is available in this Manny sector.")
                : tr("mannyMiningHint", "A Manny can carry 0.05 ECE (Earth container equivalent). If you ask it to mine more, it will make round trips between the mined object and the probe."));

        return "<form class=\"manny-mine-form manny-form\" data-require-external-storage=\"" + (requireExternalStorage ? "1" : "0") + "\" data-sector-key=\"" + escaped(sectorKey) + "\">"
            + "<label>" + escaped(tr("mineTarget", "Object")) + "<select class=\"manny-mine-target\" name=\"objectId\">" + mineTargetOptionsForTargets(targets, "") + "</select></label>"
            + "<label>" + escaped(tr("mineResourcesSelection", "Select resources to extract")) + "<select class=\"manny-mine-resources\" name=\"resources\" multiple size=\"4\">"
            + mineResourceOptions(mineTarget, [])
            + "</select></label>"
            + "<label class=\"manny-mine-storage-label\"" + (!requireExternalStorage && mineStorageTargets.length === 0 ? " hidden" : "") + ">" + escaped(tr("mineStoreOn", "Store in")) + "<select class=\"manny-mine-storage-target\" name=\"targetContainerId\"" + (requireExternalStorage ? " required" : "") + (mineStorageTargets.length === 0 || externalStorageDisabled ? " disabled" : "") + ">" + miningStorageTargetOptionsForTarget(mineTarget, "", storageTargets, !requireExternalStorage) + "</select></label>"
            + "<label class=\"manny-mine-amount-label\"><span class=\"manny-mine-amount-text\">" + escaped(miningAmountLabel(mineAmountMax)) + "</span><input name=\"targetAmount\" type=\"number\" min=\"0.01\" max=\"" + escaped(String(mineAmountMax)) + "\" step=\"0.01\" value=\"" + escaped(mineAmountMax >= 0.01 ? "0.01" : "0") + "\"></label>"
            + "<button class=\"manny-mine-button\" type=\"submit\"" + (disabled ? " disabled aria-disabled=\"true\"" : "") + ">" + escaped(tr("mine", "Mine")) + "</button>"
            + "<p class=\"manny-mine-hint\">" + escaped(hint) + "</p>"
            + "</form>";
    }

    function renderMineForm() {
        return renderMineFormWithContext({});
    }

    function renderRemoteMineForm(manny) {
        const context = remoteMannyMineContext(manny);
        return renderMineFormWithContext({
            "targets": context.targets,
            "storageTargets": context.storageTargets,
            "requireExternalStorage": true,
            "sectorKey": context.key,
            "status": context.status,
        });
    }

    function salvageTargetOptions(selected) {
        if (state.currentMannySalvageTargets.length === 0) {
            return "<option value=\"\">-</option>";
        }

        return state.currentMannySalvageTargets.map((target) => (
            "<option value=\"" + escaped(target.id) + "\"" + (target.id === selected ? " selected" : "") + ">"
            + escaped(salvageTargetLabel(target))
            + "</option>"
        )).join("");
    }

    function renderSalvageForm() {
        const salvageTarget = state.currentMannySalvageTargets[0] || null;

        return "<form class=\"manny-salvage-form manny-form\">"
            + "<label>" + escaped(tr("salvageTarget", "Drifting object")) + "<select class=\"manny-salvage-target\" name=\"objectId\">" + salvageTargetOptions("") + "</select></label>"
            + "<button class=\"manny-salvage-button\" type=\"submit\"" + (salvageTarget ? "" : " disabled aria-disabled=\"true\"") + ">" + escaped(tr("salvage", "Salvage")) + "</button>"
            + "<p class=\"manny-salvage-hint\">" + escaped(tr("mannySalvageHint", "A recovered object is checked again at the end of the five-minute recovery delay.")) + "</p>"
            + "</form>";
    }

    function asteroidTargetOptions(selected) {
        const targets = asteroidTargets();
        if (targets.length === 0) {
            return "<option value=\"\">-</option>";
        }

        return targets.map((target) => (
            "<option value=\"" + escaped(target.id) + "\"" + (target.id === selected ? " selected" : "") + ">"
            + escaped(asteroidTargetLabel(target))
            + "</option>"
        )).join("");
    }

    function sectorObjectInspectionTargetOptionsForTargets(targets, selected) {
        if (targets.length === 0) {
            return "<option value=\"\">-</option>";
        }

        return targets.map((target) => (
            "<option value=\"" + escaped(target.id) + "\"" + (target.id === selected ? " selected" : "") + ">"
            + escaped(sectorObjectInspectionTargetLabel(target))
            + "</option>"
        )).join("");
    }

    function sectorObjectInspectionTargetOptions(selected) {
        return sectorObjectInspectionTargetOptionsForTargets(sectorObjectInspectionTargets(), selected);
    }

    function renderInspectSectorObjectFormWithContext(context) {
        const targets = Array.isArray(context && context.targets) ? context.targets : sectorObjectInspectionTargets();
        const sectorKey = context && context.sectorKey ? context.sectorKey : "";
        const status = context && context.status ? context.status : "loaded";
        const hasTarget = targets.length > 0;
        const hint = status === "unavailable"
            ? tr("remoteMannySectorUnavailable", "Remote sector data is unavailable.")
            : (hasTarget
                ? tr("inspectSectorObjectHint", "Inspection can reveal hidden containers or report detached-container contents.")
                : tr("noSectorObjectInspectionTarget", "No inspectable object available in the current sector."));

        return "<form class=\"manny-inspect-sector-object-form manny-form\" data-sector-key=\"" + escaped(sectorKey) + "\">"
            + "<label>" + escaped(tr("sectorObject", "Sector object")) + "<select class=\"manny-inspect-sector-object-target\" name=\"objectId\">" + sectorObjectInspectionTargetOptionsForTargets(targets, "") + "</select></label>"
            + "<button class=\"manny-inspect-sector-object-button\" type=\"submit\"" + (hasTarget ? "" : " disabled aria-disabled=\"true\"") + ">" + escaped(tr("inspectSectorObject", "Inspect")) + "</button>"
            + "<p class=\"manny-inspect-sector-object-hint\">" + escaped(hint) + "</p>"
            + "</form>";
    }

    function renderInspectSectorObjectForm() {
        return renderInspectSectorObjectFormWithContext({});
    }

    function renderRemoteInspectSectorObjectForm(manny) {
        const context = remoteMannyInspectContext(manny);
        return renderInspectSectorObjectFormWithContext({
            "targets": context.targets,
            "sectorKey": context.key,
            "status": context.status,
        });
    }

    function detachStorageContainerOptions(selected) {
        const containers = detachableStorageContainers();
        if (containers.length === 0) {
            return "<option value=\"\">-</option>";
        }

        return containers.map((container) => (
            "<option value=\"" + escaped(container.id) + "\"" + (container.id === selected ? " selected" : "") + ">"
            + escaped(storageContainerLabel(container))
            + "</option>"
        )).join("");
    }

    function emptyStorageContainerOptions(selected) {
        const containers = emptyAdditionalContainers();
        if (containers.length === 0) {
            return "<option value=\"\">-</option>";
        }

        return containers.map((container) => (
            "<option value=\"" + escaped(container.id) + "\"" + (container.id === selected ? " selected" : "") + ">"
            + escaped(storageContainerLabel(container))
            + "</option>"
        )).join("");
    }

    function probeAssemblyHint(availability) {
        if (!availability.hasComponents) {
            return tr("missingProbeAssemblyComponents", "Insufficient components for this assembly.");
        }
        if (availability.emptyContainers.length < 2) {
            return tr("missingProbeAssemblyContainers", "Two empty containers are required.");
        }

        return tr("assembleProbeHint", "Assemble a new probe outside the current hull. The Manny will transfer to it when the task completes.");
    }

    function renderAssembleProbeForm() {
        const availability = probeAssemblyAvailability();
        const containers = availability.emptyContainers;
        const disabled = !availability.hasComponents || containers.length < 2;

        return "<form class=\"manny-assemble-probe-form manny-form\">"
            + "<p class=\"manny-assemble-probe-description\">" + escaped(tr("assembleProbeDescription", "Assemblez dans l'espace une nouvelle sonde que vous pourrez piloter via SCUT, ou vous transférer dedans. Les composants suivants sont nécessaires.")) + "</p>"
            + "<div class=\"manny-assemble-probe-ingredients\" aria-live=\"polite\">" + renderProbeAssemblyIngredients() + "</div>"
            + "<label>" + escaped(tr("emptyStorageContainerOne", "Empty container 1")) + "<select class=\"manny-assemble-probe-container\" name=\"containerIdA\" required>" + emptyStorageContainerOptions(containers[0] ? containers[0].id : "") + "</select></label>"
            + "<label>" + escaped(tr("emptyStorageContainerTwo", "Empty container 2")) + "<select class=\"manny-assemble-probe-container\" name=\"containerIdB\" required>" + emptyStorageContainerOptions(containers[1] ? containers[1].id : "") + "</select></label>"
            + "<button class=\"manny-assemble-probe-button\" type=\"submit\"" + (disabled ? " disabled aria-disabled=\"true\"" : "") + ">" + escaped(tr("assembleProbe", "Assemble")) + "</button>"
            + "<p class=\"manny-assemble-probe-hint\">" + escaped(probeAssemblyHint(availability)) + "</p>"
            + "</form>";
    }

    function updateAssembleProbeForms() {
        document.querySelectorAll(".manny-assemble-probe-form").forEach((form) => {
            const availability = probeAssemblyAvailability();
            const containers = availability.emptyContainers;
            const selects = Array.from(form.querySelectorAll(".manny-assemble-probe-container"));
            const selectedA = selects[0] ? selects[0].value : "";
            const selectedB = selects[1] ? selects[1].value : "";
            const firstDefault = containers.some((container) => container.id === selectedA) ? selectedA : (containers[0] ? containers[0].id : "");
            const secondDefault = containers.some((container) => container.id === selectedB) ? selectedB : (containers.find((container) => container.id !== firstDefault)?.id || "");
            const ingredientsNode = form.querySelector(".manny-assemble-probe-ingredients");
            const button = form.querySelector(".manny-assemble-probe-button");
            const hint = form.querySelector(".manny-assemble-probe-hint");

            selects.forEach((select, index) => {
                const value = index === 0 ? firstDefault : secondDefault;
                select.innerHTML = emptyStorageContainerOptions(value);
                select.value = value;
            });
            if (ingredientsNode) {
                ingredientsNode.innerHTML = renderProbeAssemblyIngredients();
            }
            if (button) {
                const distinctContainers = Boolean(firstDefault && secondDefault && firstDefault !== secondDefault);
                const disabled = !availability.hasComponents || containers.length < 2 || !distinctContainers;
                button.disabled = disabled;
                button.setAttribute("aria-disabled", disabled ? "true" : "false");
                button.title = disabled ? probeAssemblyHint(availability) : "";
            }
            if (hint) {
                hint.textContent = probeAssemblyHint(availability);
            }
        });
    }

    function renderDetachStorageContainerForm() {
        const containers = detachableStorageContainers();
        const asteroids = asteroidTargets();
        const hasContainer = containers.length > 0;

        return "<form class=\"manny-detach-storage-container-form manny-form\">"
            + "<label>" + escaped(tr("storageContainer", "Container")) + "<select class=\"manny-detach-container\" name=\"containerId\" required>" + detachStorageContainerOptions("") + "</select></label>"
            + "<label>" + escaped(tr("detachStorageMode", "Mode")) + "<select class=\"manny-detach-storage-mode\" name=\"mode\" required>"
            + "<option value=\"drifting\">" + escaped(tr("detachModeDrifting", "Leave drifting")) + "</option>"
            + "<option value=\"hidden_on_asteroid\">" + escaped(tr("detachModeHiddenOnAsteroid", "Hide on an asteroid")) + "</option>"
            + "</select></label>"
            + "<label class=\"manny-detach-asteroid-label\" hidden>" + escaped(tr("asteroidObject", "Asteroid")) + "<select class=\"manny-detach-asteroid-target\" name=\"objectId\">" + asteroidTargetOptions("") + "</select></label>"
            + "<button class=\"manny-detach-storage-button\" type=\"submit\"" + (hasContainer ? "" : " disabled aria-disabled=\"true\"") + ">" + escaped(tr("detachStorageContainerShort", "Detach")) + "</button>"
            + "<p class=\"manny-detach-storage-hint\">" + escaped(hasContainer ? tr("detachStorageHint", "The container and its content leave the probe when the order is accepted.") : tr("noDetachableContainer", "No additional container can be detached."))
            + (asteroids.length === 0 ? " " + escaped(tr("noAsteroidTarget", "No asteroid available in the current sector.")) : "") + "</p>"
            + "</form>";
    }

    function dropPlanetTargetOptions(selected) {
        const targets = planetTargets();
        if (targets.length === 0) {
            return "<option value=\"\">-</option>";
        }

        return targets.map((target) => (
            "<option value=\"" + escaped(target.id) + "\"" + (target.id === selected ? " selected" : "") + ">"
            + escaped(planetTargetLabel(target))
            + "</option>"
        )).join("");
    }

    function dropStorageContainerHint(containerCount, planetCount, hasKit) {
        if (containerCount === 0) {
            return tr("noDetachableContainer", "No additional container can be detached.");
        }
        if (planetCount === 0) {
            return tr("noPlanetTarget", "No planet available in the current sector.");
        }
        if (!hasKit) {
            return tr("missingAtmosphericDropKit", "An atmospheric drop kit is required in stock.");
        }

        return tr("dropStorageHint", "The container and its content leave the probe; one atmospheric drop kit is consumed.");
    }

    function renderDropStorageContainerForm() {
        const containers = detachableStorageContainers();
        const planets = planetTargets();
        const hasKit = atmosphericDropKitItems().length > 0;
        const disabled = containers.length === 0 || planets.length === 0 || !hasKit;

        return "<form class=\"manny-drop-storage-container-form manny-form\">"
            + "<label>" + escaped(tr("planetObject", "Planet")) + "<select class=\"manny-drop-planet-target\" name=\"planetId\" required>" + dropPlanetTargetOptions("") + "</select></label>"
            + "<label>" + escaped(tr("storageContainer", "Container")) + "<select class=\"manny-drop-container\" name=\"containerId\" required>" + detachStorageContainerOptions("") + "</select></label>"
            + "<button class=\"manny-drop-storage-button\" type=\"submit\"" + (disabled ? " disabled aria-disabled=\"true\"" : "") + ">" + escaped(tr("dropStorageContainerShort", "Drop")) + "</button>"
            + "<p class=\"manny-drop-storage-hint\">" + escaped(dropStorageContainerHint(containers.length, planets.length, hasKit)) + "</p>"
            + "</form>";
    }

    function recoverStorageContainerTargetOptions(selected) {
        const targets = recoverableDetachedContainerTargets();
        if (targets.length === 0) {
            return "<option value=\"\">-</option>";
        }

        return targets.map((target) => (
            "<option value=\"" + escaped(target.id) + "\" data-source=\"" + escaped(target.source || "") + "\"" + (target.id === selected ? " selected" : "") + ">"
            + escaped(detachedContainerRecoveryTargetLabel(target))
            + "</option>"
        )).join("");
    }

    function renderRecoverStorageContainerForm() {
        const targets = recoverableDetachedContainerTargets();
        const hasTarget = targets.length > 0;

        return "<form class=\"manny-recover-storage-container-form manny-form\">"
            + "<label>" + escaped(tr("detachedContainerObject", "Detached container")) + "<select class=\"manny-recover-storage-container-target\" name=\"objectId\">" + recoverStorageContainerTargetOptions("") + "</select></label>"
            + "<button class=\"manny-recover-storage-container-button\" type=\"submit\"" + (hasTarget ? "" : " disabled aria-disabled=\"true\"") + ">" + escaped(tr("recoverStorageContainer", "Recover container")) + "</button>"
            + "<p class=\"manny-recover-storage-container-hint\">" + escaped(hasTarget ? tr("recoverStorageContainerHint", "Visible containers and detected hidden containers can be recovered here.") : tr("noRecoverableStorageContainer", "No detached container has been detected in this sector.")) + "</p>"
            + "</form>";
    }

    function renderCraftForm() {
        return "<form class=\"manny-craft-form manny-form\" data-fabricator=\"manny\">"
            + "<div class=\"manny-craft-picker\">"
            + "<label>" + escaped(tr("recipe", "Recipe")) + "<select class=\"manny-craft-recipe\" name=\"recipe\">" + craftRecipeOptions("", "manny") + "</select></label>"
            + "<p class=\"manny-craft-description\" aria-live=\"polite\"></p>"
            + "<div class=\"manny-craft-ingredients\" aria-live=\"polite\"></div>"
            + "</div>"
            + "<button class=\"manny-craft-button\" type=\"submit\">" + escaped(tr("craft", "Craft")) + "</button>"
            + "</form>";
    }

    function renderBookmarkForm() {
        const hasStock = bookmarkItems().length > 0;
        const hasTarget = bookmarkTargets().length > 0;
        const disabled = !hasStock || !hasTarget;
        const message = !hasStock
            ? tr("noWaypointBookmark", "No waypoint bookmark in inventory.")
            : (!hasTarget ? tr("noBookmarkTarget", "No celestial target available in the current sector.") : "");

        return "<form class=\"manny-bookmark-form manny-form\">"
            + "<label>" + escaped(tr("bookmarkTarget", "Target")) + "<select class=\"manny-bookmark-target\" name=\"objectId\">" + bookmarkTargetOptions("") + "</select></label>"
            + "<label>" + escaped(tr("bookmarkName", "Name")) + "<input name=\"name\" maxlength=\"80\" required></label>"
            + "<button class=\"manny-bookmark-button\" type=\"submit\"" + (disabled ? " disabled aria-disabled=\"true\"" : "") + ">" + escaped(tr("installBookmark", "Install")) + "</button>"
            + "<p class=\"manny-bookmark-hint\">" + escaped(message) + "</p>"
            + "</form>";
    }

    function renderDeuteriumRefillForm() {
        return "<form class=\"manny-refill-deuterium-form manny-form\">"
            + "<button class=\"manny-refill-deuterium-button\" type=\"submit\">" + escaped(tr("refillDeuteriumTank", "Refill deuterium tank")) + "</button>"
            + "<p class=\"manny-refill-deuterium-hint\">" + escaped(tr("refillDeuteriumTankHint", "Requires a deuterium refuel station in the current sector. Duration: 1 minute.")) + "</p>"
            + "</form>";
    }

    function scutRelayTargetOptions(selected) {
        const targets = inactiveScutRelayTargets();
        if (targets.length === 0) {
            return "<option value=\"\">-</option>";
        }

        return targets.map((target) => (
            "<option value=\"" + escaped(target.id) + "\"" + (String(target.id) === String(selected) ? " selected" : "") + ">"
            + escaped([objectTypeLabel("scut_relay"), target.name || target.id].filter(Boolean).join(" "))
            + "</option>"
        )).join("");
    }

    function turnOnRelayHint(relayCount, circuitCount) {
        if (relayCount === 0) {
            return tr("noInactiveScutRelay", "No inactive SCUT relay is present in this sector.");
        }
        if (circuitCount === 0) {
            return tr("missingIntegratedCircuitForRelay", "An integrated circuit is required in stock.");
        }

        return tr("turnOnScutRelayHint", "Send a Manny to solder the final electronic circuit onto the relay and bring it online.");
    }

    function renderTurnOnRelayForm() {
        const relays = inactiveScutRelayTargets();
        const circuits = integratedCircuitItems();
        const disabled = relays.length === 0 || circuits.length === 0;

        return "<form class=\"manny-turn-on-relay-form manny-form\">"
            + "<label>" + escaped(tr("scutRelayObject", "SCUT relay")) + "<select class=\"manny-turn-on-relay-target\" name=\"relayId\" required>" + scutRelayTargetOptions("") + "</select></label>"
            + "<label>" + escaped(tr("scutNetworkNameOptional", "Network name to create (optional)")) + "<input name=\"networkName\" maxlength=\"80\"></label>"
            + "<button class=\"manny-turn-on-relay-button\" type=\"submit\"" + (disabled ? " disabled aria-disabled=\"true\"" : "") + ">" + escaped(tr("turnOnScutRelay", "Activate relay")) + "</button>"
            + "<p class=\"manny-turn-on-relay-hint\">" + escaped(turnOnRelayHint(relays.length, circuits.length)) + "</p>"
            + "</form>";
    }

    function bookmarkTargetOptions(selected) {
        const targets = bookmarkTargets();
        if (targets.length === 0) {
            return "<option value=\"\">-</option>";
        }

        return targets.map((target) => (
            "<option value=\"" + escaped(target.id) + "\"" + (target.id === selected ? " selected" : "") + ">"
            + escaped(bookmarkTargetLabel(target))
            + "</option>"
        )).join("");
    }

    function renderMannyActionForms(idPrefix) {
        const prefix = String(idPrefix || "manny-actions").replace(/[^a-zA-Z0-9_-]/g, "-");
        const renderAction = (action) => renderMannyActionAccordion(
            prefix + "-" + action.id,
            action.title,
            action.id,
            actionNeedsProbeInventory(action.id) ? "" : action.render()
        );
        const probeActions = [
            {"id": "repair", "title": tr("repairActionTitle", "Repair the probe"), "render": renderRepairForm},
            {"id": "improve-probe", "title": tr("improveProbeActionTitle", "Improve the probe"), "render": renderImproveProbeForm},
        ];
        const sectorActions = [
            {"id": "mine", "title": tr("miningActionTitle", "Mine the sector"), "render": renderMineForm},
            {"id": "salvage", "title": tr("salvageActionTitle", "Recover a drifting object"), "render": renderSalvageForm},
            {"id": "inspect-sector-object", "title": tr("inspectSectorObjectActionTitle", "Inspect a sector object"), "render": renderInspectSectorObjectForm},
            {"id": "bookmark", "title": tr("installBookmarkActionTitle", "Install a waypoint bookmark"), "render": renderBookmarkForm},
            {"id": "turn-on-relay", "title": tr("turnOnScutRelayActionTitle", "Activate a SCUT relay"), "render": renderTurnOnRelayForm},
        ];
        if (sectorHasDeuteriumRefuelStation()) {
            sectorActions.unshift({"id": "refill-deuterium", "title": tr("refillDeuteriumTankActionTitle", "Refill deuterium tank"), "render": renderDeuteriumRefillForm});
        }
        const containerActions = [
            {"id": "detach-storage", "title": tr("detachStorageActionTitle", "Detach a container"), "render": renderDetachStorageContainerForm},
            {"id": "drop-storage", "title": tr("dropStorageActionTitle", "Drop a container on a planet"), "render": renderDropStorageContainerForm},
            {"id": "recover-storage", "title": tr("recoverStorageContainerActionTitle", "Recover a detached container"), "render": renderRecoverStorageContainerForm},
        ];
        const craftActions = [
            {"id": "assemble-probe", "title": tr("assembleProbeActionTitle", "Assemble a new probe"), "render": renderAssembleProbeForm},
            {"id": "craft", "title": tr("craftingActionTitle", "Craft"), "render": renderCraftForm},
        ];

        return "<div class=\"manny-action-grid\">"
            + "<h4 class=\"manny-action-heading\">" + escaped(tr("assignMannyTask", "Assign a task to this Manny")) + "</h4>"
            + renderMannyActionGroupAccordion(prefix + "-probe-group", tr("mannyActionGroupProbe", "Probe"), probeActions.map(renderAction))
            + renderMannyActionGroupAccordion(prefix + "-sector-group", tr("mannyActionGroupSector", "Sector"), sectorActions.map(renderAction))
            + renderMannyActionGroupAccordion(prefix + "-containers-group", tr("mannyActionGroupContainers", "Containers"), containerActions.map(renderAction))
            + renderMannyActionGroupAccordion(prefix + "-craft-group", tr("mannyActionGroupCraft", "Craft"), craftActions.map(renderAction))
            + "</div>";
    }

    function renderRemoteMannyActionForms(idPrefix, manny) {
        const prefix = String(idPrefix || "manny-remote-actions").replace(/[^a-zA-Z0-9_-]/g, "-");
        const sectorActions = [
            renderMannyActionAccordion(
                prefix + "-mine",
                tr("miningActionTitle", "Mine the sector"),
                "remote-mine",
                renderRemoteMineForm(manny)
            ),
            renderMannyActionAccordion(
                prefix + "-inspect-sector-object",
                tr("inspectSectorObjectActionTitle", "Inspect a sector object"),
                "remote-inspect-sector-object",
                renderRemoteInspectSectorObjectForm(manny)
            ),
        ];

        return "<div class=\"manny-action-grid\">"
            + "<h4 class=\"manny-action-heading\">" + escaped(tr("assignMannyTask", "Assign a task to this Manny")) + "</h4>"
            + renderMannyActionGroupAccordion(prefix + "-sector-group", tr("mannyActionGroupSector", "Sector"), sectorActions)
            + "</div>";
    }

    function renderAtomicPrinterCraftForm() {
        const noAssistant = availableAtomicPrinterAssistants().length === 0;
        const noRecipes = !atomicPrinterHasRecipes();
        const disabled = noAssistant || noRecipes;
        const hint = noRecipes
            ? tr("noAtomicPrinterRecipes", "No atomic printer recipe available.")
            : (noAssistant ? tr("noAvailableMannyForPrinter", "No available Manny can assist the printer.") : tr("atomicPrinterAssistantHint", "A free Manny aboard will handle loading and unloading."));

        return "<form class=\"printer-craft-form manny-form\" data-fabricator=\"atomic_3d_printer\">"
            + "<div class=\"manny-craft-picker\">"
            + "<label>" + escaped(tr("recipe", "Recipe")) + "<select class=\"manny-craft-recipe\" name=\"recipe\">" + craftRecipeOptions("", "atomic_3d_printer") + "</select></label>"
            + "<p class=\"manny-craft-description\" aria-live=\"polite\"></p>"
            + "<div class=\"manny-craft-ingredients\" aria-live=\"polite\"></div>"
            + "</div>"
            + "<button class=\"printer-craft-button\" type=\"submit\"" + (disabled ? " disabled aria-disabled=\"true\"" : "") + ">" + escaped(tr("craft", "Craft")) + "</button>"
            + "<p class=\"printer-craft-hint\">" + escaped(hint) + "</p>"
            + "</form>";
    }

    function renderLazyActionForm(actionId) {
        return {
            "detach-storage": renderDetachStorageContainerForm,
            "drop-storage": renderDropStorageContainerForm,
            "bookmark": renderBookmarkForm,
            "craft": renderCraftForm,
            "atomic-printer-craft": renderAtomicPrinterCraftForm,
            "turn-on-relay": renderTurnOnRelayForm,
            "improve-probe": renderImproveProbeForm,
            "assemble-probe": renderAssembleProbeForm,
        }[actionId]?.() || "";
    }

    function renderAtomicPrinterTaskPanel(assistant) {
        const payload = assistant && assistant.task ? assistant.task : {};
        return "<section class=\"manny-task-panel printer-task-panel\">"
            + "<h4>" + escaped(tr("atomicPrintingInProgress", "Atomic printing in progress")) + "</h4>"
            + "<p>" + escaped(window.VNG.formatText(tr("atomicPrintingTaskDetail", "{recipe}, assisted by {manny}."), {
                "recipe": payload.recipeName || tr("integratedCircuit", "Integrated circuit"),
                "manny": assistant ? assistant.name : tr("mannyObject", "Manny"),
            })) + "</p>"
            + "<p>" + escaped(tr("taskProgress", "Progress")) + " " + (assistant ? progressValueHtml(assistant) : window.VNG.numberValue(0, "%")) + "</p>"
            + (assistant ? "<button class=\"printer-recall-button\" type=\"button\">" + escaped(tr("cancelCrafting", "Cancel crafting")) + "</button>" : "")
            + "</section>";
    }

    function atomicPrinterStateHash() {
        return hashString(stableHashPayload({
            "printer": atomicPrinterItem() ? "available" : null,
            "assistant": atomicPrinterAssistant(),
        }));
    }

    function renderAtomicPrinterCard(expanded, printerHash) {
        const printer = atomicPrinterItem();
        const assistant = atomicPrinterAssistant();
        if (!printer && !assistant) {
            return "";
        }

        const busy = assistant !== null;
        const printerName = printer
            ? inventoryItemTypeLabel(printer.type, printer.name || tr("atomicPrinter", "Atomic printer"))
            : tr("atomicPrinter", "Atomic printer");
        const taskName = busy ? taskLabel("atomic_printing") : tr("noTask", "None");
        const panelId = "atomic-printer-panel";
        const actionPanelId = panelId + "-craft";
        const buttonTitle = printerName + " - " + taskName;

        return "<article class=\"manny-card printer-card\" data-printer-id=\"atomic_3d_printer\" data-printer-hash=\"" + escaped(printerHash || "") + "\"" + (assistant ? " data-assistant-manny-id=\"" + escaped(assistant.id) + "\"" : "") + ">"
            + "<button class=\"manny-accordion-trigger\" type=\"button\" aria-expanded=\"" + (expanded ? "true" : "false") + "\" aria-controls=\"" + panelId + "\" title=\"" + escaped(buttonTitle) + "\" aria-label=\"" + escaped(buttonTitle) + "\">"
            + "<span class=\"manny-accordion-title\">"
            + "<b>" + escaped(printerName) + "</b>"
            + "<span class=\"manny-accordion-task\">" + escaped(taskName) + "</span>"
            + "</span>"
            + "</button>"
            + "<div id=\"" + panelId + "\" class=\"manny-accordion-panel\"" + (expanded ? "" : " hidden") + ">"
            + "<div class=\"manny-metrics printer-metrics\">"
            + metric(tr("location", "Location"), locationTypeLabel("probe"))
            + metric(tr("task", "Task"), busy ? progressText(assistant) : tr("noTask", "None"), busy ? "manny-task-progress-value" : null, busy ? progressDataAttributes(assistant) : "")
            + metric(tr("assistantManny", "Assistant Manny"), assistant ? assistant.name : "-")
            + "</div>"
            + (busy ? renderAtomicPrinterTaskPanel(assistant) : renderMannyActionAccordion(actionPanelId, tr("craftingActionTitle", "Craft"), "atomic-printer-craft", ""))
            + "</div>"
            + "</article>";
    }

    function mannyLocation(manny) {
        const location = manny.location || {};
        if (location.type === "probe") {
            return locationTypeLabel("probe");
        }
        return location.sector && location.sector.relative
            ? tr("sector", "Sector") + " " + window.VNG.coordinate(location.sector.relative)
            : tr("sector", "Sector");
    }

    function mannyRelativeSector(manny) {
        const relative = manny && manny.location && manny.location.sector
            ? manny.location.sector.relative
            : null;
        return relativeCoordinates(relative);
    }

    function mannySectorVisibleViaScut(manny) {
        return Boolean(
            manny
            && manny.currentTask !== "unknown_too_far"
            && manny.taskVisibility === "scut_network"
            && manny.location
            && manny.location.type === "sector"
        );
    }

    function mannyTaskVisibleViaScut(manny) {
        return mannySectorVisibleViaScut(manny)
            && manny.currentTask !== null;
    }

    function mannyRemoteIdleViaScut(manny) {
        return mannySectorVisibleViaScut(manny)
            && manny.currentTask === null
            && manny.canReceiveOrders === false;
    }

    function mannyIsTooFar(manny) {
        if (!manny || !manny.location || manny.location.type !== "sector") {
            return false;
        }
        if (manny.currentTask === "unknown_too_far") {
            return true;
        }
        if (mannySectorVisibleViaScut(manny)) {
            return false;
        }
        if (manny.currentTask === null && manny.canReceiveOrders === false) {
            return true;
        }

        return state.currentProbeSectorRelative !== null
            && mannyRelativeSector(manny) !== null
            && !sameRelativeSector(mannyRelativeSector(manny), state.currentProbeSectorRelative);
    }

    function mannyRackStatusClass(manny, tooFar) {
        if (tooFar) {
            return "manny-status-away";
        }
        if (mannyTaskVisibleViaScut(manny)) {
            return "manny-status-scut";
        }
        if (manny && manny.currentTask === "waiting_for_space") {
            return "manny-status-waiting";
        }
        if (manny && manny.currentTask !== null) {
            return "manny-status-active";
        }
        return "manny-status-inactive";
    }

    function mannyCargo(manny) {
        const cargo = manny.cargo || {};
        return [
            resourceTypeLabel("deuterium") + ": " + window.VNG.numberValue(cargo.deuterium),
            resourceTypeLabel("metals") + ": " + window.VNG.numberValue(cargo.metals),
            resourceTypeLabel("ice") + ": " + window.VNG.numberValue(cargo.ice),
            resourceTypeLabel("carbon_compounds") + ": " + window.VNG.numberValue(cargo.organicCompounds),
        ].join("\n");
    }

    function renderMannyCard(manny, expanded) {
        const busy = manny.currentTask !== null;
        const mannyId = String(manny.id || "");
        const tooFar = mannyIsTooFar(manny);
        const canReceiveOrders = manny.canReceiveOrders !== false;
        const remoteIdleViaScut = mannyRemoteIdleViaScut(manny);
        const taskName = tooFar
            ? tr("mannyTooFar", "Too far away")
            : (manny.currentTask ? taskLabel(manny.currentTask) : tr("mannyInactive", "Inactive"));
        const panelId = "manny-panel-" + mannyId.replace(/[^a-zA-Z0-9_-]/g, "-");
        const buttonTaskTitle = tooFar ? taskName : mannyAccordionTaskText(manny, taskName);
        const buttonTitle = (manny.name || mannyId) + " - " + buttonTaskTitle;
        const rackStatusClass = mannyRackStatusClass(manny, tooFar);
        const panelContent = tooFar
            ? "<div class=\"manny-metrics\">"
                + metric(tr("location", "Location"), mannyLocation(manny))
                + "</div>"
            : "<div class=\"manny-card-tools\">"
                + "<button class=\"manny-settings-button icon-button\" type=\"button\" aria-expanded=\"false\" title=\"" + escaped(tr("mannySettings", "Manny settings")) + "\" aria-label=\"" + escaped(tr("mannySettings", "Manny settings")) + "\">&#9881;</button>"
                + "</div>"
                + "<div class=\"manny-metrics\">"
                + metric(tr("location", "Location"), mannyLocation(manny))
                + metric(tr("cargo", "Cargo"), mannyCargo(manny), "manny-cargo-value")
                + metric(tr("task", "Task"), busy ? progressText(manny) : tr("noTask", "None"), busy ? "manny-task-progress-value" : null, busy ? progressDataAttributes(manny) : "")
                + "</div>"
                + "<form class=\"manny-rename-form manny-form\" hidden>"
                + "<label>" + escaped(tr("rename", "Rename")) + "<input name=\"name\" value=\"" + escaped(manny.name || "") + "\" maxlength=\"40\"></label>"
                + "<button type=\"submit\">" + escaped(tr("rename", "Rename")) + "</button>"
                + "</form>"
                + (busy ? renderMannyTaskPanel(manny) : (canReceiveOrders ? renderMannyActionForms(panelId) : (remoteIdleViaScut ? renderRemoteMannyActionForms(panelId, manny) : "")));

        return "<article class=\"manny-card " + rackStatusClass + "\" data-manny-id=\"" + escaped(manny.id) + "\" data-manny-hash=\"" + escaped(manny[MANNY_HASH_FIELD] || "") + "\">"
            + "<button class=\"manny-accordion-trigger\" type=\"button\" aria-expanded=\"" + (expanded ? "true" : "false") + "\" aria-controls=\"" + escaped(panelId) + "\" title=\"" + escaped(buttonTitle) + "\" aria-label=\"" + escaped(buttonTitle) + "\">"
            + "<span class=\"manny-accordion-title\">"
            + "<b>" + escaped(manny.name || mannyId) + "</b>"
            + "<span class=\"manny-accordion-task\">" + (tooFar ? escaped(taskName) : mannyAccordionTaskHtml(manny, taskName)) + "</span>"
            + "</span>"
            + "</button>"
            + "<div id=\"" + escaped(panelId) + "\" class=\"manny-accordion-panel\"" + (expanded ? "" : " hidden") + ">"
            + panelContent
            + "</div>"
            + "</article>";
    }

    function elementFromHtml(html) {
        const template = document.createElement("template");
        template.innerHTML = html.trim();

        return template.content.firstElementChild;
    }

    function renderMannyList(mannies) {
        const node = document.getElementById("manny-list");
        if (!node) {
            return;
        }
        const mannyItems = Array.isArray(mannies) ? mannies : [];
        const existingCards = new Map(Array.from(node.querySelectorAll(".manny-card[data-manny-id]"))
            .map((card) => [card.dataset.mannyId, card]));
        let cursor = node.querySelector(".printer-card");
        let changed = false;

        const printerHash = atomicPrinterStateHash();
        const printerHtml = renderAtomicPrinterCard(false, printerHash);
        if (!printerHtml && cursor) {
            cursor.remove();
            cursor = null;
            changed = true;
        } else if (printerHtml && (!cursor || cursor.dataset.printerHash !== printerHash)) {
            const expanded = cursor
                ? cursor.querySelector(".manny-accordion-trigger")?.getAttribute("aria-expanded") === "true"
                : false;
            const printerCard = elementFromHtml(renderAtomicPrinterCard(expanded, printerHash));
            if (cursor) {
                cursor.replaceWith(printerCard);
            } else {
                node.insertBefore(printerCard, node.firstChild);
            }
            cursor = printerCard;
            changed = true;
        }

        mannyItems.forEach((manny) => {
            const mannyId = String(manny.id || "");
            const mannyHash = String(manny[MANNY_HASH_FIELD] || "");
            const existing = existingCards.get(mannyId) || null;
            let card = existing;

            if (!card || card.dataset.mannyHash !== mannyHash) {
                const expanded = card
                    ? card.querySelector(".manny-accordion-trigger")?.getAttribute("aria-expanded") === "true"
                    : false;
                card = elementFromHtml(renderMannyCard(manny, expanded));
                if (existing) {
                    existing.replaceWith(card);
                }
                changed = true;
            }

            existingCards.delete(mannyId);
            if (cursor) {
                if (card.previousElementSibling !== cursor) {
                    node.insertBefore(card, cursor.nextSibling);
                    changed = true;
                }
            } else if (card !== node.firstElementChild) {
                node.insertBefore(card, node.firstChild);
                changed = true;
            }
            cursor = card;
        });

        existingCards.forEach((card) => {
            card.remove();
            changed = true;
        });

        const emptyHtml = mannyItems.length === 0
            ? "<p class=\"empty-state\" data-manny-empty=\"1\">" + escaped(tr("noMannies", "No Manny is available.")) + "</p>"
            : "";
        const emptyNode = node.querySelector("[data-manny-empty]");
        if (emptyHtml && !emptyNode) {
            const emptyElement = elementFromHtml(emptyHtml);
            node.appendChild(emptyElement);
            changed = true;
        } else if (!emptyHtml && emptyNode) {
            emptyNode.remove();
            changed = true;
        }

        if (changed) {
            updateMannyMineForms();
            updateMannyCraftForms();
            updateProbeImprovementForms();
            updatePrinterCraftForms();
            updateMannyBookmarkForms();
            updateMannyInspectSectorObjectForms();
            updateMannyDetachStorageContainerForms();
            updateMannyDropStorageContainerForms();
            updateMannyRecoverStorageContainerForms();
            updateAssembleProbeForms();
        }
        scheduleProgressUpdates();
    }

    function updateMannyMineForms() {
        document.querySelectorAll(".manny-mine-form").forEach(updateMannyMineFormState);
    }

    function mineTargetsForForm(form) {
        if (form && form.dataset.requireExternalStorage === "1") {
            const scan = remoteSectorScanForKey(form.dataset.sectorKey || "");
            return mineTargetsFromObjects(scan && Array.isArray(scan.objects) ? scan.objects : []);
        }

        return state.currentMannyMineTargets;
    }

    function miningStorageContainersForForm(form) {
        if (form && form.dataset.requireExternalStorage === "1") {
            const scan = remoteSectorScanForKey(form.dataset.sectorKey || "");
            return detachedContainerTargetsFromObjects(scan && Array.isArray(scan.objects) ? scan.objects : []);
        }

        return detectedDetachedContainerTargets();
    }

    function updateMannyMineFormState(form) {
        if (!form) {
            return;
        }

        const targetSelect = form.querySelector(".manny-mine-target");
        const resourceSelect = form.querySelector(".manny-mine-resources");
        const amountInput = form.querySelector("input[name=\"targetAmount\"]");
        const amountText = form.querySelector(".manny-mine-amount-text");
        const storageLabel = form.querySelector(".manny-mine-storage-label");
        const storageSelect = form.querySelector(".manny-mine-storage-target");
        const mineButton = form.querySelector(".manny-mine-button");
        if (!targetSelect || !resourceSelect) {
            return;
        }

        const targets = mineTargetsForForm(form);
        const storageContainers = miningStorageContainersForForm(form);
        const requireExternalStorage = form.dataset.requireExternalStorage === "1";
        const target = targets.find((item) => item.id === targetSelect.value) || null;
        const selectedResources = Array.from(resourceSelect.selectedOptions)
            .filter((option) => !option.disabled)
            .map((option) => option.value);
        const maxAmount = mineTargetMaxAmount(target, selectedResources);
        const selectedStorage = storageSelect ? storageSelect.value : "";
        const storageTargets = miningStorageTargetsForTarget(target, storageContainers);
        const externalStorageDisabled = selectedResources.includes("deuterium");
        if (storageSelect) {
            storageSelect.innerHTML = miningStorageTargetOptionsForTarget(target, selectedStorage, storageContainers, !requireExternalStorage);
            if (!storageTargets.some((container) => container.id === storageSelect.value) || externalStorageDisabled) {
                storageSelect.value = requireExternalStorage && !externalStorageDisabled && storageTargets[0] ? storageTargets[0].id : "";
            }
            storageSelect.disabled = storageTargets.length === 0 || externalStorageDisabled;
        }
        if (storageLabel) {
            storageLabel.hidden = !requireExternalStorage && storageTargets.length === 0;
        }
        if (amountText) {
            amountText.textContent = miningAmountLabel(maxAmount);
        }
        if (amountInput) {
            amountInput.max = String(maxAmount);
            const currentAmount = Number(amountInput.value);
            if (!Number.isFinite(currentAmount) || currentAmount <= 0) {
                amountInput.value = maxAmount >= 0.01 ? "0.01" : "0";
            } else if (currentAmount > maxAmount) {
                amountInput.value = String(maxAmount);
            }
        }
        if (mineButton) {
            const missingRequiredStorage = requireExternalStorage && (!storageSelect || !storageSelect.value || storageTargets.length === 0 || externalStorageDisabled);
            const disabled = !target || maxAmount < 0.01 || missingRequiredStorage;
            mineButton.disabled = disabled;
            mineButton.setAttribute("aria-disabled", disabled ? "true" : "false");
            mineButton.title = !target
                ? tr("noMiningTargetSelected", "Select a mining target.")
                : (missingRequiredStorage ? tr("noRemoteMiningStorageSelected", "Select a detached container in the Manny sector.") : "");
        }
    }

    function updateMannyResourceOptions(form) {
        if (!form) {
            return;
        }

        const targetSelect = form.querySelector(".manny-mine-target");
        const resourceSelect = form.querySelector(".manny-mine-resources");
        if (!targetSelect || !resourceSelect) {
            return;
        }

        const target = mineTargetsForForm(form).find((item) => item.id === targetSelect.value) || null;
        const selectedResources = Array.from(resourceSelect.selectedOptions).map((option) => option.value);
        resourceSelect.innerHTML = mineResourceOptions(target, selectedResources);
        updateMannyMineFormState(form);
    }

    function updateMannyInspectSectorObjectForms() {
        document.querySelectorAll(".manny-inspect-sector-object-form").forEach((form) => {
            const targetSelect = form.querySelector(".manny-inspect-sector-object-target");
            const button = form.querySelector(".manny-inspect-sector-object-button");
            const hint = form.querySelector(".manny-inspect-sector-object-hint");
            const selected = targetSelect ? targetSelect.value : "";
            const targets = sectorObjectInspectionTargetsForForm(form);
            const remoteScan = form.dataset.sectorKey ? remoteSectorScanForKey(form.dataset.sectorKey) : null;
            if (targetSelect) {
                targetSelect.innerHTML = sectorObjectInspectionTargetOptionsForTargets(targets, selected);
                if (!targets.some((target) => target.id === targetSelect.value)) {
                    targetSelect.value = targets[0] ? targets[0].id : "";
                }
            }
            if (button) {
                button.disabled = targets.length === 0;
                button.setAttribute("aria-disabled", targets.length === 0 ? "true" : "false");
            }
            if (hint) {
                hint.textContent = remoteScan && remoteScan.status === "unavailable"
                    ? tr("remoteMannySectorUnavailable", "Remote sector data is unavailable.")
                    : (targets.length > 0
                    ? tr("inspectSectorObjectHint", "Inspection can reveal hidden containers or report detached-container contents.")
                    : tr("noSectorObjectInspectionTarget", "No inspectable object available in the current sector."));
            }
        });
    }

    function sectorObjectInspectionTargetsForForm(form) {
        if (form && form.dataset.sectorKey) {
            const scan = remoteSectorScanForKey(form.dataset.sectorKey || "");
            return sectorObjectInspectionTargetsFromObjects(scan && Array.isArray(scan.objects) ? scan.objects : []);
        }

        return sectorObjectInspectionTargets();
    }

    function updateMannyDetachStorageContainerForms() {
        document.querySelectorAll(".manny-detach-storage-container-form").forEach((form) => {
            const containerSelect = form.querySelector(".manny-detach-container");
            const modeSelect = form.querySelector(".manny-detach-storage-mode");
            const asteroidLabel = form.querySelector(".manny-detach-asteroid-label");
            const asteroidSelect = form.querySelector(".manny-detach-asteroid-target");
            const button = form.querySelector(".manny-detach-storage-button");
            const hint = form.querySelector(".manny-detach-storage-hint");
            const selectedContainer = containerSelect ? containerSelect.value : "";
            const selectedAsteroid = asteroidSelect ? asteroidSelect.value : "";
            const containers = detachableStorageContainers();
            const asteroids = asteroidTargets();
            const hiddenMode = modeSelect && modeSelect.value === "hidden_on_asteroid";

            if (containerSelect) {
                containerSelect.innerHTML = detachStorageContainerOptions(selectedContainer);
                if (!containers.some((container) => container.id === containerSelect.value)) {
                    containerSelect.value = containers[0] ? containers[0].id : "";
                }
            }
            if (asteroidSelect) {
                asteroidSelect.innerHTML = asteroidTargetOptions(selectedAsteroid);
                if (!asteroids.some((target) => target.id === asteroidSelect.value)) {
                    asteroidSelect.value = asteroids[0] ? asteroids[0].id : "";
                }
                asteroidSelect.required = Boolean(hiddenMode);
                asteroidSelect.disabled = !hiddenMode;
            }
            if (asteroidLabel) {
                asteroidLabel.hidden = !hiddenMode;
            }
            if (button) {
                const disabled = containers.length === 0 || (hiddenMode && asteroids.length === 0);
                button.disabled = disabled;
                button.setAttribute("aria-disabled", disabled ? "true" : "false");
            }
            if (hint) {
                hint.textContent = containers.length === 0
                    ? tr("noDetachableContainer", "No additional container can be detached.")
                    : (hiddenMode && asteroids.length === 0
                        ? tr("noAsteroidTarget", "No asteroid available in the current sector.")
                        : tr("detachStorageHint", "The container and its content leave the probe when the order is accepted."));
            }
        });
    }

    function updateMannyDropStorageContainerForms() {
        document.querySelectorAll(".manny-drop-storage-container-form").forEach((form) => {
            const containerSelect = form.querySelector(".manny-drop-container");
            const planetSelect = form.querySelector(".manny-drop-planet-target");
            const button = form.querySelector(".manny-drop-storage-button");
            const hint = form.querySelector(".manny-drop-storage-hint");
            const selectedContainer = containerSelect ? containerSelect.value : "";
            const selectedPlanet = planetSelect ? planetSelect.value : "";
            const containers = detachableStorageContainers();
            const planets = planetTargets();
            const hasKit = atmosphericDropKitItems().length > 0;
            const disabled = containers.length === 0 || planets.length === 0 || !hasKit;

            if (containerSelect) {
                containerSelect.innerHTML = detachStorageContainerOptions(selectedContainer);
                if (!containers.some((container) => container.id === containerSelect.value)) {
                    containerSelect.value = containers[0] ? containers[0].id : "";
                }
            }
            if (planetSelect) {
                planetSelect.innerHTML = dropPlanetTargetOptions(selectedPlanet);
                if (!planets.some((target) => target.id === planetSelect.value)) {
                    planetSelect.value = planets[0] ? planets[0].id : "";
                }
            }
            if (button) {
                button.disabled = disabled;
                button.setAttribute("aria-disabled", disabled ? "true" : "false");
            }
            if (hint) {
                hint.textContent = dropStorageContainerHint(containers.length, planets.length, hasKit);
            }
        });
    }

    function updateMannyRecoverStorageContainerForms() {
        document.querySelectorAll(".manny-recover-storage-container-form").forEach((form) => {
            const targetSelect = form.querySelector(".manny-recover-storage-container-target");
            const button = form.querySelector(".manny-recover-storage-container-button");
            const hint = form.querySelector(".manny-recover-storage-container-hint");
            const selected = targetSelect ? targetSelect.value : "";
            const targets = recoverableDetachedContainerTargets();
            if (targetSelect) {
                targetSelect.innerHTML = recoverStorageContainerTargetOptions(selected);
                if (!targets.some((target) => target.id === targetSelect.value)) {
                    targetSelect.value = targets[0] ? targets[0].id : "";
                }
            }
            if (button) {
                button.disabled = targets.length === 0;
                button.setAttribute("aria-disabled", targets.length === 0 ? "true" : "false");
            }
            if (hint) {
                hint.textContent = targets.length > 0
                    ? tr("recoverStorageContainerHint", "Visible containers and detected hidden containers can be recovered here.")
                    : tr("noRecoverableStorageContainer", "No detached container has been detected in this sector.");
            }
        });
    }

    function updateMannyBookmarkForms() {
        document.querySelectorAll(".manny-bookmark-form").forEach((form) => {
            const targetSelect = form.querySelector(".manny-bookmark-target");
            const button = form.querySelector(".manny-bookmark-button");
            const hint = form.querySelector(".manny-bookmark-hint");
            const selected = targetSelect ? targetSelect.value : "";
            const targets = bookmarkTargets();
            const hasStock = bookmarkItems().length > 0;
            const hasTarget = targets.length > 0;
            if (targetSelect) {
                targetSelect.innerHTML = bookmarkTargetOptions(selected);
                if (!targets.some((target) => target.id === targetSelect.value)) {
                    targetSelect.value = targets[0] ? targets[0].id : "";
                }
            }
            if (button) {
                const disabled = !hasStock || !hasTarget;
                button.disabled = disabled;
                button.setAttribute("aria-disabled", disabled ? "true" : "false");
            }
            if (hint) {
                hint.textContent = !hasStock
                    ? tr("noWaypointBookmark", "No waypoint bookmark in inventory.")
                    : (!hasTarget ? tr("noBookmarkTarget", "No celestial target available in the current sector.") : "");
            }
        });
    }

    function updatePrinterCraftForms() {
        document.querySelectorAll(".printer-craft-form").forEach((form) => {
            const select = form.querySelector(".manny-craft-recipe");
            const button = form.querySelector(".printer-craft-button");
            const hint = form.querySelector(".printer-craft-hint");
            const noAssistant = availableAtomicPrinterAssistants().length === 0;
            const noRecipes = !atomicPrinterHasRecipes();
            const recipe = select ? craftingRecipeById(select.value, "atomic_3d_printer") : null;
            const canCraft = canCraftRecipe(recipe);
            if (button) {
                const disabled = noAssistant || noRecipes || !canCraft;
                button.disabled = disabled;
                button.setAttribute("aria-disabled", disabled ? "true" : "false");
                button.title = !canCraft && !noAssistant && !noRecipes ? tr("missingCraftIngredients", "Insufficient ingredients.") : "";
            }
            if (hint) {
                hint.textContent = noRecipes
                    ? tr("noAtomicPrinterRecipes", "No atomic printer recipe available.")
                    : (noAssistant ? tr("noAvailableMannyForPrinter", "No available Manny can assist the printer.") : tr("atomicPrinterAssistantHint", "A free Manny aboard will handle loading and unloading."));
            }
        });
    }

    function updateActionForm(panel) {
        if (!panel) {
            return;
        }

        const form = panel.querySelector(".manny-form");
        if (!form) {
            return;
        }
        if (form.classList.contains("manny-craft-form")) {
            updateCraftForm(form);
        }
        if (form.classList.contains("printer-craft-form")) {
            updateCraftForm(form);
            updatePrinterCraftForms();
        }
        if (form.classList.contains("manny-detach-storage-container-form")) {
            updateMannyDetachStorageContainerForms();
        }
        if (form.classList.contains("manny-drop-storage-container-form")) {
            updateMannyDropStorageContainerForms();
        }
        if (form.classList.contains("manny-bookmark-form")) {
            updateMannyBookmarkForms();
        }
        if (form.classList.contains("manny-improve-probe-form")) {
            updateProbeImprovementForm(form);
        }
        if (form.classList.contains("manny-assemble-probe-form")) {
            updateAssembleProbeForms();
        }
    }

    async function openMannyActionAccordion(button, panel) {
        if (panel && panel.dataset.lazyInventory === "1") {
            panel.innerHTML = "";
            button.disabled = true;
            try {
                if (panel.dataset.actionId === "improve-probe") {
                    await Promise.all([refreshProbeInventory(), refreshProbeImprovements()]);
                } else {
                    await refreshProbeInventory();
                }
                panel.innerHTML = renderLazyActionForm(panel.dataset.actionId || "");
                updateActionForm(panel);
            } catch (error) {
                setStatus(error.message || tr("requestDenied", "Request denied"));
                return false;
            } finally {
                button.disabled = false;
            }
        }

        return true;
    }

    function scheduleMannyRefresh() {
        if (refreshTimer !== null) {
            window.clearTimeout(refreshTimer);
        }
        refreshTimer = window.setTimeout(loadManniesPage, MANNY_REFRESH_MS);
    }

    async function loadRemoteMannySectorScans(mannies) {
        const relativeByKey = {};
        (Array.isArray(mannies) ? mannies : []).forEach((manny) => {
            if (!mannyRemoteIdleViaScut(manny)) {
                return;
            }
            const relative = mannyRelativeSector(manny);
            const key = relativeSectorKey(relative);
            if (key) {
                relativeByKey[key] = relative;
            }
        });

        const entries = await Promise.all(Object.entries(relativeByKey).map(async ([key, relative]) => [
            key,
            await fetchRelativeSectorScan(relative),
        ]));
        entries.forEach(([key, scan]) => {
            state.remoteSectorScans[key] = scan;
        });
    }

    async function loadManniesPage() {
        if (loadInProgress) {
            loadRequestedWhileInProgress = true;
            return;
        }
        loadInProgress = true;
        loadRequestedWhileInProgress = false;
        if (refreshTimer !== null) {
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
        }

        try {
            const [probeData, mannyData, sectorData] = await Promise.all([
                window.VNG.apiJson(window.VNG.probeApiPath(""), {"method": "GET"}),
                window.VNG.apiJson(window.VNG.probeApiPath("/mannies"), {"method": "GET"}),
                window.VNG.apiJson(window.VNG.probeApiPath("/sector"), {"method": "GET"}).catch(() => null),
            ]);
            const probe = probeData && probeData.probe ? probeData.probe : {};
            const sector = sectorData && sectorData.sector ? sectorData.sector : {};
            state.currentInventory = probe.inventory || (sectorData && sectorData.inventory) || null;
            state.currentSectorObjects = Array.isArray(sector.objects) ? sector.objects : [];
            const rawMannies = Array.isArray(mannyData && mannyData.mannies) ? mannyData.mannies : [];
            state.currentProbeSectorRelative = relativeCoordinates(probe.sector && probe.sector.relative);
            state.currentMannyMineTargets = mineTargetsFromObjects(state.currentSectorObjects);
            state.currentMannySalvageTargets = salvageTargetsFromObjects(state.currentSectorObjects);
            await loadRemoteMannySectorScans(rawMannies);
            state.currentMannies = rawMannies.map(withMannyStateHash);
            renderMannyList(state.currentMannies);
        } catch (error) {
            renderMannyList([]);
            if (!await window.VNG.renderUnreachableProbeTelemetry(error, {"statusId": "manny-status"})) {
                setStatus(error.message || tr("requestDenied", "Request denied"));
            }
        } finally {
            loadInProgress = false;
            if (loadRequestedWhileInProgress) {
                loadManniesPage();
            } else {
                scheduleMannyRefresh();
            }
        }
    }

    async function submitMannyForm(form, mannyId, formData) {
        if (form.classList.contains("manny-rename-form")) {
            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId)), {
                "method": "PATCH",
                "body": JSON.stringify({"name": formData.get("name")}),
            });
        }
        if (form.classList.contains("manny-repair-form")) {
            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/repair"), {
                "method": "POST",
                "body": JSON.stringify({"integrityPercent": Number.parseFloat(formData.get("integrityPercent"))}),
            });
        }
        if (form.classList.contains("manny-mine-form")) {
            const targetSelect = form.querySelector(".manny-mine-target");
            if (!targetSelect || !targetSelect.value) {
                updateMannyMineFormState(form);
                setStatus(tr("noMiningTargetSelected", "Select a mining target."));
                return null;
            }
            const resourceSelect = form.querySelector(".manny-mine-resources");
            const resources = resourceSelect
                ? Array.from(resourceSelect.selectedOptions).filter((option) => !option.disabled).map((option) => option.value)
                : [];
            if (resources.length === 0) {
                setStatus(tr("noMiningResourceSelected", "Select at least one available resource."));
                return null;
            }
            const body = {
                "objectId": formData.get("objectId"),
                resources,
                "targetAmount": Number.parseFloat(formData.get("targetAmount")),
            };
            const targetContainerId = String(formData.get("targetContainerId") || "");
            if (form.dataset.requireExternalStorage === "1" && targetContainerId === "") {
                updateMannyMineFormState(form);
                setStatus(tr("noRemoteMiningStorageSelected", "Select a detached container in the Manny sector."));
                return null;
            }
            if (targetContainerId !== "") {
                body.targetContainerId = targetContainerId;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/mine"), {
                "method": "POST",
                "body": JSON.stringify(body),
            });
        }
        if (form.classList.contains("manny-salvage-form")) {
            const targetSelect = form.querySelector(".manny-salvage-target");
            if (!targetSelect || !targetSelect.value) {
                setStatus(tr("noSalvageTargetSelected", "Select a drifting object to recover."));
                return null;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/salvage"), {
                "method": "POST",
                "body": JSON.stringify({"objectId": formData.get("objectId")}),
            });
        }
        if (form.classList.contains("manny-refill-deuterium-form")) {
            if (!sectorHasDeuteriumRefuelStation()) {
                setStatus(tr("noDeuteriumRefuelStation", "No deuterium refuel station is available in the current sector."));
                return null;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/refill-deuterium-tank"), {
                "method": "POST",
                "body": JSON.stringify({}),
            });
        }
        if (form.classList.contains("manny-inspect-sector-object-form")) {
            const targetSelect = form.querySelector(".manny-inspect-sector-object-target");
            if (!targetSelect || !targetSelect.value) {
                updateMannyInspectSectorObjectForms();
                setStatus(tr("noSectorObjectInspectionTarget", "No inspectable object available in the current sector."));
                return null;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/inspect-sector-object"), {
                "method": "POST",
                "body": JSON.stringify({"objectId": formData.get("objectId")}),
            });
        }
        if (form.classList.contains("manny-detach-storage-container-form")) {
            updateMannyDetachStorageContainerForms();
            const containerId = String(formData.get("containerId") || "");
            const mode = String(formData.get("mode") || "");
            const objectId = String(formData.get("objectId") || "");
            if (!containerId || !["drifting", "hidden_on_asteroid"].includes(mode) || (mode === "hidden_on_asteroid" && !objectId)) {
                setStatus(tr("invalidDetachStorageOrder", "Invalid container detachment order."));
                return null;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/detach-storage-container"), {
                "method": "POST",
                "body": JSON.stringify({
                    containerId,
                    mode,
                    ...(mode === "hidden_on_asteroid" ? {objectId} : {}),
                }),
            });
        }
        if (form.classList.contains("manny-drop-storage-container-form")) {
            updateMannyDropStorageContainerForms();
            const containerId = String(formData.get("containerId") || "");
            const planetId = String(formData.get("planetId") || "");
            if (!containerId || !planetId || atmosphericDropKitItems().length === 0) {
                setStatus(tr("invalidDropStorageOrder", "Invalid container drop order."));
                return null;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/drop-storage-container"), {
                "method": "POST",
                "body": JSON.stringify({containerId, planetId}),
            });
        }
        if (form.classList.contains("manny-recover-storage-container-form")) {
            const targetSelect = form.querySelector(".manny-recover-storage-container-target");
            if (!targetSelect || !targetSelect.value) {
                updateMannyRecoverStorageContainerForms();
                setStatus(tr("noRecoverableStorageContainer", "No detached container has been detected in this sector."));
                return null;
            }
            const selectedOption = targetSelect.selectedOptions[0] || null;
            const source = selectedOption && selectedOption.dataset.source ? selectedOption.dataset.source : undefined;

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/recover-storage-container"), {
                "method": "POST",
                "body": JSON.stringify({
                    "objectId": formData.get("objectId"),
                    ...(source ? {source} : {}),
                }),
            });
        }
        if (form.classList.contains("manny-turn-on-relay-form")) {
            const targetSelect = form.querySelector(".manny-turn-on-relay-target");
            if (integratedCircuitItems().length === 0) {
                setStatus(tr("missingIntegratedCircuitForRelay", "An integrated circuit is required in stock."));
                return null;
            }
            if (!targetSelect || !targetSelect.value) {
                setStatus(tr("noInactiveScutRelay", "No inactive SCUT relay is present in this sector."));
                return null;
            }

            const body = {"relayId": Number.parseInt(String(formData.get("relayId") || ""), 10)};
            const networkName = String(formData.get("networkName") || "").trim();
            if (networkName !== "") {
                body.networkName = networkName;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/turn-on-relay"), {
                "method": "POST",
                "body": JSON.stringify(body),
            });
        }
        if (form.classList.contains("manny-improve-probe-form")) {
            const selected = String(formData.get("improvement") || "");
            const improvement = probeImprovementById(selected);
            if (!improvement) {
                updateProbeImprovementForm(form);
                setStatus(tr("noProbeImprovementAvailable", "Aucune amélioration n'est disponible"));
                return null;
            }
            if (!probeImprovementAvailability(improvement).canImprove) {
                updateProbeImprovementForm(form);
                setStatus(tr("missingProbeImprovementIngredients", "Insufficient resources for this improvement."));
                return null;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/improve-probe"), {
                "method": "POST",
                "body": JSON.stringify({"improvement": selected}),
            });
        }
        if (form.classList.contains("manny-assemble-probe-form")) {
            updateAssembleProbeForms();
            const containerIds = [
                String(formData.get("containerIdA") || ""),
                String(formData.get("containerIdB") || ""),
            ];
            const availability = probeAssemblyAvailability();
            if (!availability.hasComponents) {
                setStatus(tr("missingProbeAssemblyComponents", "Insufficient components for this assembly."));
                return null;
            }
            if (availability.emptyContainers.length < 2 || !containerIds[0] || !containerIds[1] || containerIds[0] === containerIds[1]) {
                setStatus(tr("missingProbeAssemblyContainers", "Two distinct empty containers are required."));
                return null;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/assemble-probe"), {
                "method": "POST",
                "body": JSON.stringify({containerIds}),
            });
        }
        if (form.classList.contains("manny-craft-form")) {
            const recipe = craftingRecipeById(String(formData.get("recipe") || ""), "manny");
            if (!canCraftRecipe(recipe)) {
                updateCraftForm(form);
                setStatus(tr("missingCraftIngredients", "Insufficient ingredients."));
                return null;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/craft"), {
                "method": "POST",
                "body": JSON.stringify({"recipe": formData.get("recipe")}),
            });
        }
        if (form.classList.contains("printer-craft-form")) {
            const recipe = craftingRecipeById(String(formData.get("recipe") || ""), "atomic_3d_printer");
            if (!atomicPrinterHasRecipes()) {
                updatePrinterCraftForms();
                setStatus(tr("noAtomicPrinterRecipes", "No atomic printer recipe available."));
                return null;
            }
            if (availableAtomicPrinterAssistants().length === 0) {
                updatePrinterCraftForms();
                setStatus(tr("noAvailableMannyForPrinter", "No available Manny can assist the printer."));
                return null;
            }
            if (!canCraftRecipe(recipe)) {
                updateCraftForm(form);
                updatePrinterCraftForms();
                setStatus(tr("missingCraftIngredients", "Insufficient ingredients."));
                return null;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/atomic-printer/craft"), {
                "method": "POST",
                "body": JSON.stringify({"recipe": formData.get("recipe")}),
            });
        }
        if (form.classList.contains("manny-bookmark-form")) {
            const targetSelect = form.querySelector(".manny-bookmark-target");
            if (bookmarkItems().length === 0) {
                updateMannyBookmarkForms();
                setStatus(tr("noWaypointBookmark", "No waypoint bookmark in inventory."));
                return null;
            }
            if (!targetSelect || !targetSelect.value) {
                updateMannyBookmarkForms();
                setStatus(tr("noBookmarkTarget", "No celestial target available in the current sector."));
                return null;
            }

            return window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/install-bookmark"), {
                "method": "POST",
                "body": JSON.stringify({
                    "objectId": formData.get("objectId"),
                    "name": formData.get("name"),
                }),
            });
        }

        return false;
    }

    function isMannyTaskAssignmentForm(form) {
        return form && form.classList.contains("manny-form") && !form.classList.contains("manny-rename-form");
    }

    function toggleAccordion(button) {
        const targetId = button.getAttribute("aria-controls");
        const panel = targetId ? document.getElementById(targetId) : null;
        if (!panel) {
            return;
        }
        const expanded = button.getAttribute("aria-expanded") === "true";
        button.setAttribute("aria-expanded", expanded ? "false" : "true");
        panel.hidden = expanded;
    }

    function bindEvents() {
        const mannyList = document.getElementById("manny-list");
        if (!mannyList) {
            return;
        }

        mannyList.addEventListener("submit", async (event) => {
            event.preventDefault();
            const card = event.target.closest(".manny-card");
            const mannyId = card ? card.dataset.mannyId : null;
            if (!mannyId && !event.target.classList.contains("printer-craft-form")) {
                return;
            }
            const form = new FormData(event.target);
            setStatus(tr("orderSent", "Order transmitted..."));
            try {
                const result = await submitMannyForm(event.target, mannyId, form);
                if (result === false || result === null) {
                    return;
                }
                setStatus(tr("mannyOrderAccepted", "Manny order accepted."));
                if (isMannyTaskAssignmentForm(event.target)) {
                    await loadManniesPage();
                }
            } catch (error) {
                setStatus(error.message || tr("requestDenied", "Request denied"));
            }
        });

        mannyList.addEventListener("change", (event) => {
            if (event.target.classList.contains("manny-mine-target")) {
                updateMannyResourceOptions(event.target.closest(".manny-mine-form"));
            }
            if (event.target.classList.contains("manny-mine-resources")) {
                updateMannyMineFormState(event.target.closest(".manny-mine-form"));
            }
            if (event.target.classList.contains("manny-mine-storage-target")) {
                updateMannyMineFormState(event.target.closest(".manny-mine-form"));
            }
            if (event.target.classList.contains("manny-inspect-sector-object-target")) {
                updateMannyInspectSectorObjectForms();
            }
            if (event.target.classList.contains("manny-detach-container") || event.target.classList.contains("manny-detach-storage-mode") || event.target.classList.contains("manny-detach-asteroid-target")) {
                updateMannyDetachStorageContainerForms();
            }
            if (event.target.classList.contains("manny-drop-container") || event.target.classList.contains("manny-drop-planet-target")) {
                updateMannyDropStorageContainerForms();
            }
            if (event.target.classList.contains("manny-recover-storage-container-target")) {
                updateMannyRecoverStorageContainerForms();
            }
            if (event.target.classList.contains("manny-bookmark-target")) {
                updateMannyBookmarkForms();
            }
            if (event.target.classList.contains("manny-craft-recipe")) {
                updateCraftForm(event.target.closest(".manny-craft-form"));
                updateCraftForm(event.target.closest(".printer-craft-form"));
                updatePrinterCraftForms();
            }
            if (event.target.classList.contains("manny-probe-improvement")) {
                updateProbeImprovementForm(event.target.closest(".manny-improve-probe-form"));
            }
            if (event.target.classList.contains("manny-assemble-probe-container")) {
                updateAssembleProbeForms();
            }
        });

        mannyList.addEventListener("click", async (event) => {
            const accordionButton = event.target.closest(".manny-accordion-trigger, .manny-action-accordion-trigger");
            if (accordionButton) {
                const targetId = accordionButton.getAttribute("aria-controls");
                const panel = targetId ? document.getElementById(targetId) : null;
                const willOpen = accordionButton.getAttribute("aria-expanded") !== "true";
                if (willOpen && accordionButton.classList.contains("manny-action-accordion-trigger") && !accordionButton.classList.contains("manny-action-group-trigger")) {
                    const canOpen = await openMannyActionAccordion(accordionButton, panel);
                    if (!canOpen) {
                        return;
                    }
                }
                toggleAccordion(accordionButton);
                return;
            }

            const settingsButton = event.target.closest(".manny-settings-button");
            if (settingsButton) {
                const card = settingsButton.closest(".manny-card");
                const renameForm = card ? card.querySelector(".manny-rename-form") : null;
                if (!renameForm) {
                    return;
                }
                const willOpen = renameForm.hidden;
                renameForm.hidden = !willOpen;
                settingsButton.setAttribute("aria-expanded", willOpen ? "true" : "false");
                if (willOpen) {
                    renameForm.querySelector("input[name=\"name\"]")?.focus();
                }
                return;
            }

            const dropCargoButton = event.target.closest(".manny-drop-cargo-button");
            if (dropCargoButton) {
                const card = dropCargoButton.closest(".manny-card");
                const mannyId = card && card.dataset.mannyId;
                if (!mannyId) {
                    return;
                }
                setStatus(tr("orderSent", "Order transmitted..."));
                try {
                    await window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/drop-manny-cargo"), {
                        "method": "POST",
                        "body": JSON.stringify({}),
                    });
                    setStatus(tr("mannyOrderAccepted", "Manny order accepted."));
                } catch (error) {
                    setStatus(error.message || tr("requestDenied", "Request denied"));
                }
                return;
            }

            const recallButton = event.target.closest(".manny-recall-button, .printer-recall-button");
            if (!recallButton) {
                return;
            }
            const card = recallButton.closest(".manny-card");
            const mannyId = recallButton.classList.contains("printer-recall-button")
                ? card && card.dataset.assistantMannyId
                : card && card.dataset.mannyId;
            if (!mannyId) {
                return;
            }
            setStatus(tr("orderSent", "Order transmitted..."));
            try {
                await window.VNG.apiJson(window.VNG.probeApiPath("/mannies/" + encodeURIComponent(mannyId) + "/recall"), {
                    "method": "POST",
                    "body": JSON.stringify({}),
                });
                setStatus(tr("mannyOrderAccepted", "Manny order accepted."));
            } catch (error) {
                setStatus(error.message || tr("requestDenied", "Request denied"));
            }
        });
    }

    withVng(async () => {
        if (document.body.dataset.authenticated !== "1" || !document.getElementById("manny-list")) {
            return;
        }

        i18n = await window.VNG.loadI18n();
        await loadCraftingRecipesOnce();
        bindEvents();
        loadManniesPage();
    });
})();
