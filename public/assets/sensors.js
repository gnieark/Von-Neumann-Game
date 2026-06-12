(function () {
    const DEFAULT_REFRESH_MS = 15000;
    const MIN_REFRESH_MS = 750;
    const REFRESH_CUSHION_MS = 500;

    let i18n = {};
    let refreshTimer = null;
    let countdownTimer = null;
    let loadInProgress = false;
    let currentProbeSectorRelative = null;
    let currentScanTarget = null;
    let neighborScanRunId = 0;
    let neighborScanInProgress = false;

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

    function pluralWord(count, singularKey, singularFallback, pluralKey, pluralFallback) {
        return Number(count) === 1 ? tr(singularKey, singularFallback) : tr(pluralKey, pluralFallback);
    }

    function objectTypeLabel(type) {
        return {
            "star": tr("starObject", "Star"),
            "planet": tr("planetObject", "Planet"),
            "asteroid": tr("asteroidObject", "Asteroid"),
            "dust_cloud": tr("dustCloudObject", "Dust cloud"),
            "black_hole": tr("blackHoleObject", "Black hole"),
            "solar_system": tr("solarSystemObject", "Solar system"),
            "manny": tr("mannyObject", "Manny"),
            "probe": tr("tabProbe", "Probe"),
            "waypoint_bookmark": tr("waypointBookmark", "Waypoint bookmark"),
            "drifting_item": tr("driftingItemObject", "Drifting item"),
            "detached_container": tr("detachedContainerObject", "Detached container"),
            "object": tr("object", "Object"),
            "unknown": tr("unknownObject", "Unknown object"),
        }[type] || type || tr("unknownObject", "Unknown object");
    }

    function dangerLevelLabel(level) {
        return {
            "low": tr("dangerLow", "low"),
            "moderate": tr("dangerModerate", "moderate"),
            "extreme": tr("dangerExtreme", "extreme"),
            "unknown": tr("dangerUnknown", "unknown"),
        }[level] || level || tr("dangerUnknown", "unknown");
    }

    function resourceTypeLabel(type) {
        return {
            "deuterium": tr("deuterium", "Deuterium"),
            "metals": tr("metals", "Metals"),
            "ice": tr("ice", "Ice"),
            "carbon_compounds": tr("carbonCompounds", "Carbon compounds"),
            "organic_compounds": tr("carbonCompounds", "Carbon compounds"),
            "other": tr("carbonCompounds", "Carbon compounds"),
        }[type] || type;
    }

    function planetCategoryLabel(category) {
        return {
            "rocky": tr("planetCategoryRocky", "rocky"),
            "frozen": tr("planetCategoryFrozen", "frozen"),
            "ocean": tr("planetCategoryOcean", "ocean"),
            "lava": tr("planetCategoryLava", "lava"),
            "dwarf": tr("planetCategoryDwarf", "dwarf"),
            "gas_giant": tr("planetCategoryGasGiant", "gas giant"),
            "ice_giant": tr("planetCategoryIceGiant", "ice giant"),
        }[category] || category || "-";
    }

    function asteroidCompositionLabel(composition) {
        return {
            "iron": tr("asteroidCompositionIron", "iron"),
            "silicate": tr("asteroidCompositionSilicate", "silicate"),
            "carbonaceous": tr("asteroidCompositionCarbonaceous", "carbonaceous"),
            "ice": tr("asteroidCompositionIce", "ice"),
            "rare_metals": tr("asteroidCompositionRareMetals", "rare metals"),
        }[composition] || composition || "-";
    }

    function sizeCategoryLabel(size) {
        return {
            "small": tr("sizeCategorySmall", "small"),
            "medium": tr("sizeCategoryMedium", "medium"),
            "large": tr("sizeCategoryLarge", "large"),
        }[size] || size || "-";
    }

    function mannyStateLabel(state) {
        return {
            "abandoned": tr("abandonedManny", "abandoned"),
            "forgotten": tr("forgottenManny", "forgotten"),
        }[state] || state || "-";
    }

    function observationSummaryLabel(summary) {
        const value = String(summary || "");
        const solarSystem = value.match(/^Stellar system with (\d+) star\(s\) and (\d+) orbital body\(ies\)\.$/);
        if (solarSystem) {
            const stars = Number(solarSystem[1]);
            const orbitals = Number(solarSystem[2]);

            return window.VNG.formatText(tr("observationSummarySolarSystem", "Stellar system with {stars} {starWord} and {orbitals} {orbitalWord}."), {
                "stars": stars,
                "starWord": pluralWord(stars, "starSingular", "star", "starPlural", "stars"),
                "orbitals": orbitals,
                "orbitalWord": pluralWord(orbitals, "orbitalObjectSingular", "orbital object", "orbitalObjectPlural", "orbital objects"),
            });
        }

        const driftingItems = value.match(/^(\d+) inventory item\(s\) drifting in open space\.$/);
        if (driftingItems) {
            const count = Number(driftingItems[1]);

            return window.VNG.formatText(tr("observationSummaryDriftingItems", "{count} inventory {itemWord} drifting in open space."), {
                "count": count,
                "itemWord": pluralWord(count, "inventoryItemSingular", "item", "inventoryItemPlural", "items"),
            });
        }

        return {
            "Isolated star or stellar remnant.": tr("observationSummaryStar", "Isolated star or stellar remnant."),
            "Planetary body detected.": tr("observationSummaryPlanet", "Planetary body detected."),
            "Wandering asteroid body.": tr("observationSummaryAsteroid", "Wandering asteroid body."),
            "Diffuse dust cloud with sensor interference.": tr("observationSummaryDustCloud", "Diffuse dust cloud with sensor interference."),
            "Dangerous compact object detected.": tr("observationSummaryBlackHole", "Dangerous compact object detected."),
            "Manny left behind by a probe.": tr("observationSummaryForgottenManny", "Manny left behind by a probe."),
            "Abandoned Manny drifting in this sector.": tr("observationSummaryAbandonedManny", "Abandoned Manny drifting in this sector."),
            "Another probe is present in this sector.": tr("observationSummaryProbePresence", "Another probe is present in this sector."),
            "Waypoint bookmark detected in this sector.": tr("observationSummaryWaypointBookmark", "Waypoint bookmark detected in this sector."),
            "Detached storage container drifting in open space.": tr("observationSummaryDetachedContainerDrifting", "Detached storage container drifting in open space."),
            "Detached storage container hidden on an asteroid.": tr("observationSummaryDetachedContainerHidden", "Detached storage container hidden on an asteroid."),
            "Unknown astronomical object.": tr("observationSummaryUnknown", "Unknown astronomical object."),
        }[value] || value;
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

    function sleep(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }

    function movementUrl(target) {
        return "/movement/" + encodeURIComponent(String(target.x)) + "/" + encodeURIComponent(String(target.y)) + "/" + encodeURIComponent(String(target.z));
    }

    function numericCount(value) {
        const number = Number(value);
        return Number.isFinite(number) ? number : 0;
    }

    function sumCount(items, key) {
        return items.reduce((total, item) => total + numericCount(item[key]), 0);
    }

    function sectorContext(sector) {
        const distance = Number(sector && sector.distance);
        if (!Number.isFinite(distance)) {
            return tr("sectorContextUnavailable", "Displayed sector: unavailable.");
        }
        if (distance === 0) {
            return tr("sectorContextCurrent", "Displayed sector: current probe position.");
        }

        return window.VNG.formatText(tr("sectorContextRemote", "Displayed sector: sector {distance} {sectorStepWord} away."), {
            "distance": distance,
            "sectorStepWord": pluralWord(distance, "sectorStepSingular", "sector", "sectorStepPlural", "sectors"),
        });
    }

    function detailedSectorSummary(objects) {
        if (objects.length === 0) {
            return tr("sectorSummaryEmpty", "Empty sector.");
        }

        const blackHoles = objects.filter((object) => object.type === "black_hole");
        if (blackHoles.length > 0) {
            const otherObjects = objects.length - blackHoles.length;
            if (otherObjects === 0) {
                if (blackHoles.length === 1) {
                    return tr("sectorSummaryBlackHole", "Hazardous sector: black hole detected.");
                }

                return window.VNG.formatText(tr("sectorSummaryBlackHoles", "Hazardous sector: {blackHoles} {blackHoleWord} detected."), {
                    "blackHoles": blackHoles.length,
                    "blackHoleWord": pluralWord(blackHoles.length, "blackHoleSingular", "black hole", "blackHolePlural", "black holes"),
                });
            }

            return window.VNG.formatText(tr("sectorSummaryBlackHoleWithObjects", "Hazardous sector: {blackHoles} {blackHoleWord} and {objects} {otherObjectWord} present."), {
                "blackHoles": blackHoles.length,
                "blackHoleWord": pluralWord(blackHoles.length, "blackHoleSingular", "black hole", "blackHolePlural", "black holes"),
                "objects": otherObjects,
                "otherObjectWord": pluralWord(otherObjects, "otherObjectSingular", "other object", "otherObjectPlural", "other objects"),
            });
        }

        const solarSystems = objects.filter((object) => object.type === "solar_system");
        if (solarSystems.length > 0) {
            const planets = sumCount(solarSystems, "planetCount");
            const orbitals = sumCount(solarSystems, "orbitalBodyCount");
            const stars = Math.max(1, sumCount(solarSystems, "starCount"));

            return window.VNG.formatText(tr("sectorSummarySolarSystem", "Solar system: {planets} {planetWord} among {orbitals} {orbitalObjectWord}, around {stars} {starWord}."), {
                "planets": planets,
                "planetWord": pluralWord(planets, "planetSingular", "planet", "planetPlural", "planets"),
                "orbitals": orbitals,
                "orbitalObjectWord": pluralWord(orbitals, "orbitalObjectSingular", "orbital object", "orbitalObjectPlural", "orbital objects"),
                "stars": stars,
                "starWord": pluralWord(stars, "starSingular", "star", "starPlural", "stars"),
            });
        }

        if (objects.length === 1) {
            return tr("sectorSummarySingleObject", "Occupied sector: 1 object detected.");
        }

        return window.VNG.formatText(tr("sectorSummaryObjects", "Occupied sector: {count} objects detected."), {"count": objects.length});
    }

    function estimatedSectorSummary(estimate) {
        if (Number(estimate.blackHoleProbability || 0) >= 0.5) {
            return tr("sectorSummaryBlackHoleLikely", "Strong gravity signature: black hole likely.");
        }
        if (estimate.star) {
            return window.VNG.formatText(tr("sectorSummaryNeighborStar", "Probable stellar system: {min} to {max} planets estimated."), {
                "min": numericCount(estimate.planetCountMin),
                "max": numericCount(estimate.planetCountMax),
            });
        }

        return tr("sectorSummaryNoMajorNearby", "No major nearby object estimated.");
    }

    function possibleSectorSummary(sector) {
        const signatures = Array.isArray(sector.possibleObjects) ? sector.possibleObjects : [];
        if (signatures.includes("strong_gravity_signature")) {
            return tr("sectorSummaryGravitySignature", "Strong gravity signature: black hole possible.");
        }
        if (signatures.includes("stellar_mass_detected")) {
            return tr("sectorSummaryDistantStar", "Distant stellar signature detected.");
        }
        if (signatures.includes("dust_cloud_possible")) {
            return tr("sectorSummaryDustPossible", "Possible dust cloud in the sector.");
        }

        return tr("sectorSummaryNoMajorSignature", "No major signature detected.");
    }

    function sectorSummary(sector) {
        if (!sector) {
            return tr("sectorSummaryUnavailable", "No sector analysis available.");
        }
        if (sector.sensorMode === "degraded" || sector.message === "Sensors are degraded during intersector maneuvering.") {
            return tr("sectorSummarySensorsDegradedMovement", "Sensors are too degraded by travel velocity; inventory impossible.");
        }
        if (Array.isArray(sector.objects)) {
            return detailedSectorSummary(sector.objects);
        }
        if (sector.estimatedObjects && typeof sector.estimatedObjects === "object") {
            return estimatedSectorSummary(sector.estimatedObjects);
        }
        if (Array.isArray(sector.possibleObjects)) {
            return possibleSectorSummary(sector);
        }

        return tr("sectorSummaryLongRange", "Long-range estimate: not enough detail for a reliable inventory.");
    }

    function scanErrorMessage(error) {
        const retryAfterSeconds = Number(error && error.details && error.details.retryAfterSeconds);
        if (error && error.errorCode === "insufficient_scan_data" && Number.isFinite(retryAfterSeconds)) {
            return window.VNG.formatText(
                tr("sectorScanInsufficientDataRetry", "The probe has not collected enough data from the current sector to display this scan. Try again in {duration}."),
                {"duration": window.VNG.duration(retryAfterSeconds, tr)}
            );
        }

        return error && error.message ? error.message : tr("requestDenied", "Request denied");
    }

    function resourceTypesForTarget(target) {
        if (Array.isArray(target.resourceTypes) && target.resourceTypes.length > 0) {
            return target.resourceTypes;
        }
        if (target.resourceComposition && typeof target.resourceComposition === "object") {
            return Object.entries(target.resourceComposition)
                .filter(([, amount]) => Number(amount) > 0)
                .map(([type]) => type);
        }

        return Array.isArray(target.resources) ? target.resources : [];
    }

    function resourceCompositionLabel(target) {
        const composition = target.resourceComposition && typeof target.resourceComposition === "object"
            ? target.resourceComposition
            : null;
        if (!composition) {
            return Array.isArray(target.resources) && target.resources.length > 0
                ? target.resources.map(resourceTypeLabel).join(", ")
                : tr("compositionUnknown", "unknown composition");
        }

        const parts = Object.entries(composition)
            .filter(([, amount]) => Number(amount) > 0)
            .map(([type, amount]) => resourceTypeLabel(type) + " " + Math.round(Number(amount) * 100) + "%");

        return parts.length > 0 ? parts.join(", ") : tr("compositionUnknown", "unknown composition");
    }

    function objectMassUnit(body) {
        return body.massUnit || ({
            "star": "solar_mass",
            "black_hole": "solar_mass",
            "dust_cloud": "solar_mass",
            "planet": "earth_mass",
            "asteroid": "earth_mass",
        }[body.type] || "");
    }

    function objectRadiusUnit(body) {
        return body.radiusUnit || ({
            "star": "solar_radius",
            "planet": "earth_radius",
            "asteroid": "earth_radius",
            "black_hole": "kilometer",
            "dust_cloud": "astronomical_unit",
            "solar_system": "astronomical_unit",
        }[body.type] || "");
    }

    function physicalUnitLabel(unit) {
        return {
            "solar_mass": "M\u2609",
            "solar_radius": "R\u2609",
            "earth_mass": "M earth",
            "earth_radius": "R earth",
            "kilometer": "km",
            "astronomical_unit": "AU",
        }[unit] || "";
    }

    function physicalValue(value, unit) {
        const label = physicalUnitLabel(unit);
        return label ? window.VNG.numberValue(value) + " " + label : window.VNG.numberValue(value);
    }

    function systemBodyKey(body) {
        return String(body && (body.id || body.name || body.type) ? (body.id || body.name || body.type) : "");
    }

    function solarSystemBodies(system) {
        const bodiesByKey = new Map();
        ["bookmarkTargets", "minableTargets"].forEach((key) => {
            if (!Array.isArray(system[key])) {
                return;
            }
            system[key].forEach((body) => {
                const bodyKey = systemBodyKey(body);
                if (bodyKey) {
                    bodiesByKey.set(bodyKey, {...(bodiesByKey.get(bodyKey) || {}), ...body});
                }
            });
        });

        return Array.from(bodiesByKey.values());
    }

    function hasResourceDetails(body) {
        return resourceTypesForTarget(body).length > 0
            || (body.resourceComposition && typeof body.resourceComposition === "object");
    }

    function systemBodyLabel(body) {
        return objectTypeLabel(body.type || "object") + " - " + (body.name || body.id || tr("unknownObject", "Unknown object"));
    }

    function systemBodyDetails(body) {
        const details = [];
        if (Number.isFinite(Number(body.mass))) {
            details.push({"label": tr("mass", "Mass"), "value": physicalValue(body.mass, objectMassUnit(body))});
        }
        if (Number.isFinite(Number(body.radius))) {
            details.push({"label": tr("radius", "Radius"), "value": physicalValue(body.radius, objectRadiusUnit(body))});
        }
        if (body.composition) {
            details.push({"label": tr("composition", "Composition"), "value": asteroidCompositionLabel(body.composition)});
        }
        if (body.category) {
            details.push({"label": tr("category", "Category"), "value": planetCategoryLabel(body.category)});
        }
        if (body.sizeCategory) {
            details.push({"label": tr("size", "Size"), "value": sizeCategoryLabel(body.sizeCategory)});
        }
        if (hasResourceDetails(body)) {
            const resourceTypes = resourceTypesForTarget(body);
            if (resourceTypes.length > 0) {
                details.push({"label": tr("resources", "Resources"), "value": resourceTypes.map(resourceTypeLabel).join(", ")});
            }
            details.push({"label": tr("composition", "Composition"), "value": resourceCompositionLabel(body)});
        }

        if (details.length === 0) {
            return "";
        }

        return "<dl class=\"sector-system-body-details\">"
            + details.map((detail) => (
                "<div><dt>" + window.VNG.escapeHtml(detail.label) + "</dt><dd>" + window.VNG.escapeHtml(detail.value) + "</dd></div>"
            )).join("")
            + "</dl>";
    }

    function solarSystemDetails(system, index) {
        if (system.type !== "solar_system") {
            return "";
        }

        const bodies = solarSystemBodies(system);
        if (bodies.length === 0) {
            return "";
        }

        const panelId = "sector-system-bodies-" + String(index);
        const openLabel = window.VNG.formatText(tr("showSolarSystemBodies", "System bodies ({count})"), {"count": bodies.length});
        return "<button class=\"sector-system-toggle\" type=\"button\" aria-expanded=\"false\" aria-controls=\"" + window.VNG.escapeHtml(panelId) + "\" data-open-label=\"" + window.VNG.escapeHtml(openLabel) + "\" data-close-label=\"" + window.VNG.escapeHtml(tr("hideSolarSystemBodies", "Hide bodies")) + "\">"
            + window.VNG.escapeHtml(openLabel)
            + "</button>"
            + "<div id=\"" + window.VNG.escapeHtml(panelId) + "\" class=\"sector-system-body-panel\" hidden>"
            + "<ul class=\"sector-system-body-list\">"
            + bodies.map((body) => (
                "<li class=\"sector-system-body\">"
                    + "<span class=\"sector-system-body-title\">" + window.VNG.escapeHtml(systemBodyLabel(body)) + "</span>"
                    + systemBodyDetails(body)
                + "</li>"
            )).join("")
            + "</ul>"
            + "</div>";
    }

    function bindSolarSystemToggles(root) {
        root.querySelectorAll(".sector-system-toggle").forEach((button) => {
            button.addEventListener("click", () => {
                const panel = document.getElementById(button.getAttribute("aria-controls") || "");
                if (!panel) {
                    return;
                }

                const willOpen = button.getAttribute("aria-expanded") !== "true";
                button.setAttribute("aria-expanded", willOpen ? "true" : "false");
                button.textContent = willOpen
                    ? (button.dataset.closeLabel || tr("hideSolarSystemBodies", "Hide bodies"))
                    : (button.dataset.openLabel || tr("showSolarSystemBodies", "System bodies"));
                panel.hidden = !willOpen;
            });
        });
    }

    function bookmarkedSectorObjects(sector) {
        const distance = Number(sector && sector.distance);
        if (!Number.isFinite(distance) || distance !== 0 || !Array.isArray(sector && sector.objects)) {
            return [];
        }

        const result = [];
        const seen = new Set();
        const collect = (object) => {
            if (!object || typeof object !== "object") {
                return;
            }
            if (Array.isArray(object.waypointBookmarks) && object.waypointBookmarks.length > 0) {
                const label = [objectTypeLabel(object.type || "object"), object.name || object.id].filter(Boolean).join(" ");
                const key = String(object.id || object.name || label);
                if (!seen.has(key)) {
                    seen.add(key);
                    result.push({
                        "key": key,
                        "label": label || key,
                        "bookmarks": object.waypointBookmarks,
                    });
                }
            }
            ["bookmarkTargets", "minableTargets"].forEach((childKey) => {
                if (Array.isArray(object[childKey])) {
                    object[childKey].forEach(collect);
                }
            });
        };
        sector.objects.forEach(collect);

        return result;
    }

    function sectorBookmarkObjects(sector) {
        return bookmarkedSectorObjects(sector).map((bookmarkTarget, index) => {
            const bookmarks = Array.isArray(bookmarkTarget.bookmarks) ? bookmarkTarget.bookmarks : [];
            const bookmarkNames = bookmarks.map((bookmark) => bookmark && bookmark.name ? bookmark.name : "").filter(Boolean);

            return {
                "id": "waypoint-bookmark-" + String(bookmarkTarget.key || index),
                "type": "waypoint_bookmark",
                "name": bookmarkNames.join(", ") || tr("waypointBookmark", "Waypoint bookmark"),
                "estimated": false,
                "summary": "Waypoint bookmark detected in this sector.",
                "dangerLevel": "low",
                "targetLabel": bookmarkTarget.label || "",
                "bookmarkNames": bookmarkNames,
            };
        });
    }

    function sectorProbeObjects(sector) {
        const probes = Array.isArray(sector && sector.probes) ? sector.probes : [];

        return probes.map((probe, index) => ({
            "id": "probe-" + String(probe && probe.id ? probe.id : index),
            "type": "probe",
            "name": probe && probe.name ? probe.name : tr("unknownProbe", "Unknown probe"),
            "estimated": false,
            "summary": "Another probe is present in this sector.",
            "dangerLevel": "low",
            "moving": Boolean(probe && probe.moving),
        }));
    }

    function countdownHtml(object) {
        const secondsRemaining = Number(object && object.noReturnCountdown && object.noReturnCountdown.secondsRemaining);
        if (!Number.isFinite(secondsRemaining)) {
            return "";
        }

        const endAt = Date.now() + Math.max(0, secondsRemaining) * 1000;
        const duration = window.VNG.duration(secondsRemaining, tr);
        return "<p class=\"sector-object-countdown\">"
            + window.VNG.escapeHtml(window.VNG.formatText(tr("blackHoleNoReturnCountdown", "Point of no return in {duration}"), {"duration": duration}))
            + "</p>"
            + "<span class=\"sector-countdown-value\" data-countdown-end-at=\"" + window.VNG.escapeHtml(String(endAt)) + "\" hidden></span>";
    }

    function objectDetailHtml(object) {
        if (object.type === "manny") {
            return "<p>" + window.VNG.escapeHtml(tr("mannyState", "State") + " " + mannyStateLabel(object.mannyState)) + "</p>";
        }
        if (object.type === "drifting_item") {
            return "<p>" + window.VNG.escapeHtml(tr("quantity", "Quantity") + " " + String(object.quantity || 0)) + "</p>";
        }
        if (object.type === "detached_container") {
            return "<p>" + window.VNG.escapeHtml([
                tr("detachStorageMode", "Mode") + " " + (object.mode === "hidden_on_asteroid" ? tr("hiddenOnAsteroid", "hidden on asteroid") : tr("detachModeDrifting", "Leave drifting")),
                tr("storageCapacity", "Storage capacity") + " " + window.VNG.numberValue(object.capacity || 0),
            ].filter(Boolean).join(" - ")) + "</p>";
        }
        if (object.type === "waypoint_bookmark") {
            return "<p>" + window.VNG.escapeHtml(tr("bookmarkTarget", "Target") + " " + (object.targetLabel || "-")) + "</p>"
                + "<p>" + window.VNG.escapeHtml(tr("bookmarkName", "Name") + " " + (Array.isArray(object.bookmarkNames) && object.bookmarkNames.length > 0 ? object.bookmarkNames.join(", ") : (object.name || "-"))) + "</p>";
        }
        if (object.type === "probe") {
            return "<p>" + window.VNG.escapeHtml(
                [object.name || tr("unknownProbe", "Unknown probe"), object.moving
                    ? tr("probeMovementActive", "movement in progress")
                    : tr("probeMovementInactive", "no movement in progress")].filter(Boolean).join(" - ")
            ) + "</p>";
        }

        return "";
    }

    function renderSectorObjects(sector) {
        const node = document.getElementById("sector-objects");
        if (!node) {
            return;
        }

        const openPanels = window.VNG.openDisclosureIds(node, ".sector-system-toggle[aria-expanded=\"true\"][aria-controls]");
        setText("sector-context", sectorContext(sector));
        const objects = Array.isArray(sector && sector.objects) ? sector.objects : [];
        const displayObjects = objects.concat(sectorBookmarkObjects(sector), sectorProbeObjects(sector));
        const summarySector = sector && (Array.isArray(sector.objects) || displayObjects.length > 0)
            ? {...sector, "objects": displayObjects}
            : sector;
        setText("sector-summary", sectorSummary(summarySector));

        node.innerHTML = displayObjects.map((object, index) => {
            const danger = object.dangerLevel || "unknown";
            const classes = ["sector-object", danger === "extreme" ? "sector-object-warning" : ""].filter(Boolean).join(" ");

            return "<article class=\"" + classes + "\">"
                + "<div class=\"sector-object-heading\"><span>" + window.VNG.escapeHtml(objectTypeLabel(object.type || "unknown")) + "</span><b>" + window.VNG.escapeHtml(dangerLevelLabel(danger)) + "</b></div>"
                + "<p>" + window.VNG.escapeHtml(observationSummaryLabel(object.summary || "")) + "</p>"
                + objectDetailHtml(object)
                + solarSystemDetails(object, index)
                + countdownHtml(object)
                + "</article>";
        }).join("");

        bindSolarSystemToggles(node);
        window.VNG.restoreDisclosureIds(node, openPanels, ".sector-system-toggle[aria-controls]");
        node.querySelectorAll(".sector-system-toggle[aria-expanded=\"true\"]").forEach((button) => {
            button.textContent = button.dataset.closeLabel || tr("hideSolarSystemBodies", "Hide bodies");
        });
        scheduleLiveCountdowns();
    }

    function syncSectorForm(sector) {
        const relative = sector && sector.relativeCoordinates;
        const form = document.getElementById("sector-form");
        if (!form || !relative) {
            return;
        }

        ["x", "y", "z"].forEach((field) => {
            if (form.elements[field]) {
                form.elements[field].value = relative[field] ?? 0;
            }
        });
    }

    function syncPrepareJumpButton(sector) {
        const button = document.getElementById("prepare-jump-button");
        if (!button) {
            return;
        }

        const target = relativeCoordinates(sector && sector.relativeCoordinates);
        const distance = Number(sector && sector.distance);
        const isRemoteSector = target !== null && (
            Number.isFinite(distance)
                ? distance !== 0
                : !sameRelativeCoordinates(target, currentProbeSectorRelative)
        );

        button.hidden = !isRemoteSector;
        button.disabled = !isRemoteSector;
        button.setAttribute("aria-disabled", isRemoteSector ? "false" : "true");
        button.dataset.targetX = isRemoteSector ? String(target.x) : "";
        button.dataset.targetY = isRemoteSector ? String(target.y) : "";
        button.dataset.targetZ = isRemoteSector ? String(target.z) : "";
    }

    function sectorFormTarget() {
        const form = document.getElementById("sector-form");
        if (!form) {
            return null;
        }

        return {
            "x": Number.parseInt(form.elements.x?.value, 10),
            "y": Number.parseInt(form.elements.y?.value, 10),
            "z": Number.parseInt(form.elements.z?.value, 10),
        };
    }

    function applySectorScanButtonState() {
        const button = document.getElementById("sector-scan-submit");
        const control = document.getElementById("sector-scan-control");
        if (!button) {
            return;
        }

        const invalid = !window.VNG.validRelativeCoordinates(sectorFormTarget());
        const invalidMessage = tr("invalidCoordinates", "Invalid relative coordinates: x + y + z must be even.");
        button.disabled = invalid;
        button.title = invalid ? invalidMessage : "";
        button.setAttribute("aria-disabled", invalid ? "true" : "false");
        if (control) {
            control.classList.toggle("disabled", invalid);
            control.title = invalid ? invalidMessage : "";
            control.setAttribute("aria-disabled", invalid ? "true" : "false");
        }
    }

    function scanRefreshHints(data) {
        const scan = data && data.sector && data.sector.scan;
        const current = Number(scan && scan.currentSectorResidenceSeconds);
        const required = Number(scan && scan.requiredResidenceSeconds);
        if (!Number.isFinite(current) || !Number.isFinite(required) || required <= current) {
            return data;
        }

        return {
            ...data,
            "scanRemainingSeconds": Math.max(0, required - current),
        };
    }

    function clearRefreshTimer() {
        if (refreshTimer !== null) {
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
        }
    }

    function clearCountdownTimer() {
        if (countdownTimer !== null) {
            window.clearTimeout(countdownTimer);
            countdownTimer = null;
        }
    }

    function scheduleRefresh(data) {
        clearRefreshTimer();
        refreshTimer = window.setTimeout(loadDisplayedSector, window.VNG.nextRefreshDelay(
            scanRefreshHints(data),
            DEFAULT_REFRESH_MS,
            MIN_REFRESH_MS,
            REFRESH_CUSHION_MS
        ));
    }

    function updateLiveCountdowns() {
        const countdowns = Array.from(document.querySelectorAll(".sector-countdown-value[data-countdown-end-at]"));
        let hasPendingCountdown = false;
        const now = Date.now();

        countdowns.forEach((marker) => {
            const endAt = Number(marker.dataset.countdownEndAt);
            const paragraph = marker.previousElementSibling;
            if (!Number.isFinite(endAt) || !paragraph) {
                return;
            }

            const remainingSeconds = Math.max(0, Math.ceil((endAt - now) / 1000));
            paragraph.textContent = window.VNG.formatText(tr("blackHoleNoReturnCountdown", "Point of no return in {duration}"), {
                "duration": window.VNG.duration(remainingSeconds, tr),
            });
            if (remainingSeconds > 0 && now < endAt) {
                hasPendingCountdown = true;
            }
        });

        countdownTimer = hasPendingCountdown ? window.setTimeout(updateLiveCountdowns, 1000) : null;
    }

    function scheduleLiveCountdowns() {
        clearCountdownTimer();
        if (!document.querySelector(".sector-countdown-value[data-countdown-end-at]")) {
            return;
        }

        updateLiveCountdowns();
    }

    function neighborSectorDeltas() {
        return [
            {"x": 1, "y": 1, "z": 0},
            {"x": 1, "y": -1, "z": 0},
            {"x": -1, "y": 1, "z": 0},
            {"x": -1, "y": -1, "z": 0},
            {"x": 1, "y": 0, "z": 1},
            {"x": 1, "y": 0, "z": -1},
            {"x": -1, "y": 0, "z": 1},
            {"x": -1, "y": 0, "z": -1},
            {"x": 0, "y": 1, "z": 1},
            {"x": 0, "y": 1, "z": -1},
            {"x": 0, "y": -1, "z": 1},
            {"x": 0, "y": -1, "z": -1},
        ];
    }

    function neighborSectorTargets(origin) {
        const base = relativeCoordinates(origin);
        if (!base) {
            return [];
        }

        return neighborSectorDeltas().map((delta) => ({
            "x": base.x + delta.x,
            "y": base.y + delta.y,
            "z": base.z + delta.z,
        }));
    }

    function setNeighborScanStatus(message) {
        const node = document.getElementById("neighbor-scan-status");
        if (!node) {
            return;
        }

        node.textContent = message;
        node.hidden = message === "";
    }

    function neighborTileHtml(target, sector, error) {
        const coordinates = window.VNG.coordinate(target);
        const summary = error
            ? scanErrorMessage(error)
            : sectorSummary(sector);
        const context = error ? "" : sectorContext(sector);

        return "<article class=\"neighbor-sector-tile\">"
            + "<h4>" + window.VNG.escapeHtml(coordinates) + "</h4>"
            + (context ? "<p class=\"neighbor-sector-context\">" + window.VNG.escapeHtml(context) + "</p>" : "")
            + "<p>" + window.VNG.escapeHtml(summary) + "</p>"
            + "<a class=\"button-link neighbor-sector-jump\" href=\"" + window.VNG.escapeHtml(movementUrl(target)) + "\">" + window.VNG.escapeHtml(tr("prepareJump", "Prepare jump")) + "</a>"
            + "</article>";
    }

    function appendNeighborTile(target, sector, error) {
        const grid = document.getElementById("neighbor-sector-grid");
        if (!grid) {
            return;
        }

        const holder = document.createElement("div");
        holder.innerHTML = neighborTileHtml(target, sector, error);
        const tile = holder.firstElementChild;
        if (tile) {
            grid.appendChild(tile);
        }
    }

    async function fetchProbeSectorRelative() {
        const data = await window.VNG.apiJson("/api/probe/sector", {"method": "GET"});
        currentProbeSectorRelative = relativeCoordinates(data.sector && data.sector.relativeCoordinates);

        return currentProbeSectorRelative;
    }

    async function fetchSectorScan(target) {
        const query = new URLSearchParams({
            "x": String(target.x),
            "y": String(target.y),
            "z": String(target.z),
        });
        const data = await window.VNG.apiJson("/api/sector?" + query.toString(), {"method": "GET"});

        return data && data.sector ? data.sector : null;
    }

    async function scanNeighborSectors() {
        if (neighborScanInProgress) {
            return;
        }

        const button = document.getElementById("neighbor-scan-button");
        const grid = document.getElementById("neighbor-sector-grid");
        const runId = neighborScanRunId + 1;
        neighborScanRunId = runId;
        neighborScanInProgress = true;
        if (button) {
            button.disabled = true;
            button.setAttribute("aria-disabled", "true");
        }
        if (grid) {
            grid.innerHTML = "";
        }

        try {
            const origin = await fetchProbeSectorRelative();
            const targets = neighborSectorTargets(origin);
            if (targets.length === 0) {
                setNeighborScanStatus(tr("sectorNeighborScanFailed", "Scan unavailable for this sector."));
                return;
            }

            for (let index = 0; index < targets.length; index += 1) {
                if (runId !== neighborScanRunId) {
                    return;
                }

                const target = targets[index];
                setNeighborScanStatus(window.VNG.formatText(
                    tr("sectorNeighborScanStatus", "Scan sector {current}/{total} - aiming sensors at coords {coords}"),
                    {
                        "current": index + 1,
                        "total": targets.length,
                        "coords": window.VNG.coordinate(target),
                    }
                ));

                let sector = null;
                let error = null;
                try {
                    const [scan] = await Promise.all([fetchSectorScan(target), sleep(1000)]);
                    sector = scan;
                } catch (caught) {
                    error = caught instanceof Error ? caught : new Error(tr("sectorNeighborScanFailed", "Scan unavailable for this sector."));
                    await sleep(1000);
                }

                appendNeighborTile(target, sector, error);
            }

            setNeighborScanStatus("");
        } catch (error) {
            setNeighborScanStatus(scanErrorMessage(error));
        } finally {
            if (runId === neighborScanRunId) {
                neighborScanInProgress = false;
                if (button) {
                    button.disabled = false;
                    button.setAttribute("aria-disabled", "false");
                }
            }
        }
    }

    async function loadSector(path) {
        if (loadInProgress) {
            return;
        }

        loadInProgress = true;
        clearRefreshTimer();
        clearCountdownTimer();

        try {
            const data = await window.VNG.apiJson(path, {"method": "GET"});
            if (path === "/api/probe/sector") {
                currentProbeSectorRelative = relativeCoordinates(data.sector && data.sector.relativeCoordinates);
            }
            syncSectorForm(data.sector);
            renderSectorObjects(data.sector);
            syncPrepareJumpButton(data.sector);
            applySectorScanButtonState();
            scheduleRefresh(data);
            return data;
        } catch (error) {
            renderSectorObjects(null);
            syncPrepareJumpButton(null);
            setText("sector-context", scanErrorMessage(error));
            applySectorScanButtonState();
            scheduleRefresh(null);
            return null;
        } finally {
            loadInProgress = false;
        }
    }

    function loadCurrentSector() {
        currentScanTarget = null;
        return loadSector("/api/probe/sector");
    }

    function loadDisplayedSector() {
        if (!currentScanTarget) {
            return loadCurrentSector();
        }

        const query = new URLSearchParams({
            "x": String(currentScanTarget.x),
            "y": String(currentScanTarget.y),
            "z": String(currentScanTarget.z),
        });
        return loadSector("/api/sector?" + query.toString());
    }

    async function scanSubmittedSector(event) {
        event.preventDefault();
        const target = sectorFormTarget();
        if (!window.VNG.validRelativeCoordinates(target)) {
            renderSectorObjects(null);
            setText("sector-context", tr("invalidCoordinates", "Invalid relative coordinates: x + y + z must be even."));
            applySectorScanButtonState();
            return;
        }

        currentScanTarget = target;
        await loadDisplayedSector();
    }

    function bindPage() {
        document.getElementById("sector-form")?.addEventListener("submit", scanSubmittedSector);
        document.getElementById("sector-form")?.addEventListener("input", applySectorScanButtonState);
        document.getElementById("prepare-jump-button")?.addEventListener("click", (event) => {
            const button = event.currentTarget;
            const target = {
                "x": Number.parseInt(button.dataset.targetX || "", 10),
                "y": Number.parseInt(button.dataset.targetY || "", 10),
                "z": Number.parseInt(button.dataset.targetZ || "", 10),
            };
            if (!window.VNG.validRelativeCoordinates(target)) {
                return;
            }

            window.location.assign("/movement/" + encodeURIComponent(String(target.x)) + "/" + encodeURIComponent(String(target.y)) + "/" + encodeURIComponent(String(target.z)));
        });
        document.querySelector("[data-refresh=\"sector\"]")?.addEventListener("click", () => {
            loadCurrentSector();
        });
        document.getElementById("neighbor-scan-button")?.addEventListener("click", scanNeighborSectors);
    }

    document.addEventListener("DOMContentLoaded", () => {
        if (document.body.dataset.authenticated !== "1" || !document.getElementById("sector-form")) {
            return;
        }

        withVng(async () => {
            i18n = await window.VNG.loadI18n();
            bindPage();
            applySectorScanButtonState();
            loadCurrentSector();
        });
    });
}());
