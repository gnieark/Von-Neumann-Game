export const createLabels = (i18n) => {
    const t = (key, fallback) => i18n[key] || fallback;
    const pluralWord = (count, singularKey, singularFallback, pluralKey, pluralFallback) => (
        Number(count) === 1 ? t(singularKey, singularFallback) : t(pluralKey, pluralFallback)
    );
    const formatLabel = (template, values) => Object.entries(values).reduce(
        (message, [name, value]) => message.split('{' + name + '}').join(String(value)),
        template,
    );
    const resourceTypeLabel = (type) => ({
        deuterium: t('deuterium', 'Deuterium'),
        metals: t('metals', 'Metals'),
        ice: t('ice', 'Ice'),
        carbon_compounds: t('carbonCompounds', 'Carbon compounds'),
        organic_compounds: t('carbonCompounds', 'Carbon compounds'),
        other: t('carbonCompounds', 'Carbon compounds'),
    }[type] || type);
    const normalizeResourceType = (type) => ({
        organic_compounds: 'carbon_compounds',
        organicCompounds: 'carbon_compounds',
        other: 'carbon_compounds',
    }[type] || type);
    const objectTypeLabel = (type) => ({
        star: t('starObject', 'Star'),
        planet: t('planetObject', 'Planet'),
        asteroid: t('asteroidObject', 'Asteroid'),
        dust_cloud: t('dustCloudObject', 'Dust cloud'),
        black_hole: t('blackHoleObject', 'Black hole'),
        solar_system: t('solarSystemObject', 'Solar system'),
        manny: t('mannyObject', 'Manny'),
        probe: t('tabProbe', 'Probe'),
        atomic_3d_printer: t('atomicPrinter', 'Atomic printer'),
        waypoint_bookmark: t('waypointBookmark', 'Waypoint bookmark'),
        drifting_item: t('driftingItemObject', 'Drifting item'),
        object: t('object', 'Object'),
        unknown: t('unknownObject', 'Unknown object'),
    }[type] || type);
    const mannyStateLabel = (state) => ({
        abandoned: t('abandonedManny', 'abandoned'),
        forgotten: t('forgottenManny', 'forgotten'),
    }[state] || state || '-');
    const dangerLevelLabel = (level) => ({
        low: t('dangerLow', 'low'),
        moderate: t('dangerModerate', 'moderate'),
        extreme: t('dangerExtreme', 'extreme'),
        unknown: t('dangerUnknown', 'unknown'),
    }[level] || level || t('dangerUnknown', 'unknown'));
    const locationTypeLabel = (type) => ({
        probe: t('tabProbe', 'Probe'),
        sector: t('sector', 'Sector'),
    }[type] || type || '-');
    const planetCategoryLabel = (category) => ({
        rocky: t('planetCategoryRocky', 'rocky'),
        frozen: t('planetCategoryFrozen', 'frozen'),
        ocean: t('planetCategoryOcean', 'ocean'),
        lava: t('planetCategoryLava', 'lava'),
        dwarf: t('planetCategoryDwarf', 'dwarf'),
        gas_giant: t('planetCategoryGasGiant', 'gas giant'),
        ice_giant: t('planetCategoryIceGiant', 'ice giant'),
    }[category] || category || '-');
    const asteroidCompositionLabel = (composition) => ({
        iron: t('asteroidCompositionIron', 'iron'),
        silicate: t('asteroidCompositionSilicate', 'silicate'),
        carbonaceous: t('asteroidCompositionCarbonaceous', 'carbonaceous'),
        ice: t('asteroidCompositionIce', 'ice'),
        rare_metals: t('asteroidCompositionRareMetals', 'rare metals'),
    }[composition] || composition || '-');
    const sizeCategoryLabel = (size) => ({
        small: t('sizeCategorySmall', 'small'),
        medium: t('sizeCategoryMedium', 'medium'),
        large: t('sizeCategoryLarge', 'large'),
    }[size] || size || '-');
    const probeStatusLabel = (status) => ({
        idle: t('probeStatusIdle', 'Idle'),
        preparing: t('probeStatusPreparing', 'Preparing'),
        accelerating: t('probeStatusAccelerating', 'Accelerating'),
        cruising: t('probeStatusCruising', 'Cruising'),
        decelerating: t('probeStatusDecelerating', 'Decelerating'),
        arrived: t('probeStatusArrived', 'Arrived'),
        dead: t('probeStatusDead', 'Out of service'),
        destroyed: t('probeStatusDestroyed', 'Destroyed'),
        trapped_by_black_hole: t('probeStatusTrappedByBlackHole', 'Trapped by a black hole'),
    }[status] || status || '-');
    const sensorModeLabel = (mode) => ({
        normal: t('sensorModeNormal', 'Normal'),
        degraded: t('sensorModeDegraded', 'Degraded'),
        blind: t('sensorModeBlind', 'Blind'),
    }[mode] || mode || '-');
    const observationSummaryLabel = (summary) => {
        const value = String(summary || '');
        const solarSystem = value.match(/^Stellar system with (\d+) star\(s\) and (\d+) orbital body\(ies\)\.$/);
        if (solarSystem) {
            const stars = Number(solarSystem[1]);
            const orbitals = Number(solarSystem[2]);

            return formatLabel(t('observationSummarySolarSystem', 'Stellar system with {stars} {starWord} and {orbitals} {orbitalWord}.'), {
                stars,
                starWord: pluralWord(stars, 'starSingular', 'star', 'starPlural', 'stars'),
                orbitals,
                orbitalWord: pluralWord(orbitals, 'orbitalObjectSingular', 'orbital object', 'orbitalObjectPlural', 'orbital objects'),
            });
        }

        const driftingItems = value.match(/^(\d+) inventory item\(s\) drifting in open space\.$/);
        if (driftingItems) {
            const count = Number(driftingItems[1]);

            return formatLabel(t('observationSummaryDriftingItems', '{count} inventory {itemWord} drifting in open space.'), {
                count,
                itemWord: pluralWord(count, 'inventoryItemSingular', 'item', 'inventoryItemPlural', 'items'),
            });
        }

        return {
            'Isolated star or stellar remnant.': t('observationSummaryStar', 'Isolated star or stellar remnant.'),
            'Planetary body detected.': t('observationSummaryPlanet', 'Planetary body detected.'),
            'Wandering asteroid body.': t('observationSummaryAsteroid', 'Wandering asteroid body.'),
            'Diffuse dust cloud with sensor interference.': t('observationSummaryDustCloud', 'Diffuse dust cloud with sensor interference.'),
            'Dangerous compact object detected.': t('observationSummaryBlackHole', 'Dangerous compact object detected.'),
            'Manny left behind by a probe.': t('observationSummaryForgottenManny', 'Manny left behind by a probe.'),
            'Abandoned Manny drifting in this sector.': t('observationSummaryAbandonedManny', 'Abandoned Manny drifting in this sector.'),
            'Another probe is present in this sector.': t('observationSummaryProbePresence', 'Another probe is present in this sector.'),
            'Waypoint bookmark detected in this sector.': t('observationSummaryWaypointBookmark', 'Waypoint bookmark detected in this sector.'),
            'Unknown astronomical object.': t('observationSummaryUnknown', 'Unknown astronomical object.'),
        }[value] || value;
    };
    const taskLabel = (task) => ({
        repair: t('repair', 'Repair'),
        mining: t('mine', 'Mine'),
        crafting: t('craft', 'Craft'),
        salvage: t('salvage', 'Salvage'),
        returning: t('returning', 'Returning'),
        waiting_for_space: t('waitingForSpace', 'Waiting for space'),
        moving_stockage: t('movingStorage', 'Moving storage'),
        installing_waypoint_bookmark: t('installingWaypointBookmark', 'Installing waypoint bookmark'),
    }[task] || task || t('noTask', 'None'));
    const inventoryItemTypeLabel = (type, fallback) => ({
        atomic_3d_printer: t('atomicPrinter', 'Atomic printer'),
        waypoint_bookmark: t('waypointBookmark', 'Waypoint bookmark'),
        steel_bar: t('steelBar', 'Steel bar'),
        steel_plate: t('steelPlate', 'Steel plate'),
        additional_container: t('additionalContainer', 'Additional container'),
        micro_conductor: t('microConductor', 'Micro-etched conductor'),
        ceramic_insulator: t('ceramicInsulator', 'Ceramo-organic insulator'),
        crystal_substrate: t('crystalSubstrate', 'Crystal substrate'),
        dopant_matrix: t('dopantMatrix', 'Dopant matrix'),
        integrated_circuit: t('integratedCircuit', 'Integrated circuit'),
    }[type] || fallback || type);

    return {
        asteroidCompositionLabel,
        dangerLevelLabel,
        inventoryItemTypeLabel,
        locationTypeLabel,
        mannyStateLabel,
        normalizeResourceType,
        objectTypeLabel,
        observationSummaryLabel,
        planetCategoryLabel,
        pluralWord,
        probeStatusLabel,
        resourceTypeLabel,
        sensorModeLabel,
        sizeCategoryLabel,
        t,
        taskLabel,
    };
};
