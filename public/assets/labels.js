export const createLabels = (i18n) => {
    const t = (key, fallback) => i18n[key] || fallback;
    const pluralWord = (count, singularKey, singularFallback, pluralKey, pluralFallback) => (
        Number(count) === 1 ? t(singularKey, singularFallback) : t(pluralKey, pluralFallback)
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
    }[type] || type);
    const mannyStateLabel = (state) => ({
        abandoned: t('abandonedManny', 'abandoned'),
        forgotten: t('forgottenManny', 'forgotten'),
    }[state] || state || '-');
    const taskLabel = (task) => ({
        repair: t('repair', 'Repair'),
        mining: t('mine', 'Mine'),
        crafting: t('craft', 'Craft'),
        salvage: t('salvage', 'Salvage'),
        returning: t('returning', 'Returning'),
        waiting_for_space: t('waitingForSpace', 'Waiting for space'),
    }[task] || task || t('noTask', 'None'));
    const inventoryItemTypeLabel = (type, fallback) => ({
        waypoint_bookmark: t('waypointBookmark', 'Waypoint bookmark'),
        steel_bar: t('steelBar', 'Steel bar'),
        steel_plate: t('steelPlate', 'Steel plate'),
        additional_container: t('additionalContainer', 'Additional container'),
    }[type] || fallback || type);

    return {
        inventoryItemTypeLabel,
        mannyStateLabel,
        normalizeResourceType,
        objectTypeLabel,
        pluralWord,
        resourceTypeLabel,
        t,
        taskLabel,
    };
};
