import {mannyMiningAmountMax, miningResourceTypes} from './sector.js';
import {toggleAccordion} from './ui-accordion.js';
import {
    coordinate,
    escapeHtml,
    formatText,
    metric,
    numberValue,
    pretty,
    setText,
} from './utils.js';

export const createMannyModule = ({state, labels, sector, crafting, api, refreshers = {}}) => {
    const {
        resourceTypeLabel,
        t,
        taskLabel,
    } = labels;

    let mannyProgressTickTimer = null;
    let mannyCompletionRefreshPending = false;
    let mannyCompletionTimers = [];

    const mannyProgressText = (manny) => numberValue(manny.taskProgressPercent, '%');

    const mannyProgressDataAttributes = (manny, observedAt) => {
        const progress = Number(manny.taskProgressPercent);
        const endAt = Date.parse(manny.taskEstimatedEndTime || '');
        if (!manny.currentTask || !Number.isFinite(progress) || !Number.isFinite(endAt)) {
            return '';
        }

        return 'data-progress-start="' + escapeHtml(Math.max(0, Math.min(100, progress))) + '"'
            + ' data-progress-observed-at="' + escapeHtml(observedAt) + '"'
            + ' data-progress-end-at="' + escapeHtml(endAt) + '"';
    };

    const mannyProgressValueHtml = (manny, observedAt) => {
        const attributes = mannyProgressDataAttributes(manny, observedAt);

        return '<span class="manny-task-progress-value"' + (attributes ? ' ' + attributes : '') + '>'
            + escapeHtml(mannyProgressText(manny))
            + '</span>';
    };

    const selectedResourceLabels = (types) => {
        const resources = Array.isArray(types) ? types : (types ? [types] : []);

        return resources.map(resourceTypeLabel).join(', ');
    };

    const miningTaskTarget = (payload) => {
        if (payload && payload.target) {
            return payload.target;
        }

        return state.currentMannyMineTargets.find((target) => target.id === (payload && payload.objectId)) || null;
    };

    const renderMannyTaskPanel = (manny, observedAt) => {
        const payload = manny.task || {};
        const progress = mannyProgressValueHtml(manny, observedAt);
        if (manny.currentTask === 'repair') {
            return '<section class="manny-task-panel">'
                + '<h4>' + escapeHtml(t('repairInProgress', 'Repair in progress')) + '</h4>'
                + '<p>' + escapeHtml(formatText(t('repairTaskDetail', '{percent}% integrity scheduled, {metals} metal containers committed.'), {
                    percent: numberValue(payload.integrityPercent),
                    metals: numberValue(payload.metalsCost),
                })) + '</p>'
                + '<p>' + escapeHtml(t('taskProgress', 'Progress')) + ' ' + progress + '</p>'
                + '<button class="manny-recall-button" type="button">' + escapeHtml(t('cancelRepair', 'Cancel repairs')) + '</button>'
                + '</section>';
        }
        if (manny.currentTask === 'mining') {
            return '<section class="manny-task-panel">'
                + '<h4>' + escapeHtml(t('miningInProgress', 'Mining in progress')) + '</h4>'
                + '<p>' + escapeHtml(formatText(t('miningTaskDetail', '{resources} on {target}.'), {
                    resources: selectedResourceLabels(payload.resourceTypes || payload.resourceType),
                    target: sector.miningTargetDetails(miningTaskTarget(payload)),
                })) + '</p>'
                + '<p>' + escapeHtml(t('taskProgress', 'Progress')) + ' ' + progress + '</p>'
                + '<button class="manny-recall-button" type="button">' + escapeHtml(t('recall', 'Recall')) + '</button>'
                + '</section>';
        }
        if (manny.currentTask === 'crafting') {
            return '<section class="manny-task-panel">'
                + '<h4>' + escapeHtml(t('craftingInProgress', 'Crafting in progress')) + '</h4>'
                + '<p>' + escapeHtml(formatText(t('craftingTaskDetail', '{recipe}, {metals} metal containers committed.'), {
                    recipe: payload.recipeName || t('waypointBookmark', 'Waypoint bookmark'),
                    metals: numberValue(payload.metalsCost),
                })) + '</p>'
                + '<p>' + escapeHtml(t('taskProgress', 'Progress')) + ' ' + progress + '</p>'
                + '<button class="manny-recall-button" type="button">' + escapeHtml(t('cancelCrafting', 'Cancel crafting')) + '</button>'
                + '</section>';
        }
        if (manny.currentTask === 'salvage') {
            const target = payload.target || {};
            return '<section class="manny-task-panel">'
                + '<h4>' + escapeHtml(t('salvageInProgress', 'Recovery in progress')) + '</h4>'
                + '<p>' + escapeHtml(formatText(t('salvageTaskDetail', '{target} will be checked and recovered after the delay.'), {
                    target: sector.salvageTargetLabel(target),
                })) + '</p>'
                + '<p>' + escapeHtml(t('taskProgress', 'Progress')) + ' ' + progress + '</p>'
                + '</section>';
        }

        return '<section class="manny-task-panel">'
            + '<h4>' + escapeHtml(taskLabel(manny.currentTask)) + '</h4>'
            + '<p>' + escapeHtml(t('taskProgress', 'Progress')) + ' ' + progress + '</p>'
            + '</section>';
    };

    const renderMannyActionAccordion = (id, title, formHtml) => (
        '<section class="manny-action-section manny-action-accordion">'
        + '<button class="manny-action-accordion-trigger" type="button" aria-expanded="false" aria-controls="' + escapeHtml(id) + '">'
        + '<span>' + escapeHtml(title) + '</span>'
        + '</button>'
        + '<div id="' + escapeHtml(id) + '" class="manny-action-accordion-panel" hidden>'
        + formHtml
        + '</div>'
        + '</section>'
    );

    const renderRepairForm = () => (
        '<form class="manny-repair-form manny-form">'
        + '<label>' + escapeHtml(t('repairPercent', 'Integrity to restore')) + '<input name="integrityPercent" type="number" min="1" max="100" step="1" value="1"></label>'
        + '<button type="submit">' + escapeHtml(t('repair', 'Repair')) + '</button>'
        + '</form>'
    );

    const renderMineForm = () => {
        const mineTarget = state.currentMannyMineTargets[0] || null;
        const mineAmountMax = mineTargetMaxAmount(mineTarget, sector.resourceTypesForTarget(mineTarget));

        return '<form class="manny-mine-form manny-form">'
            + '<label>' + escapeHtml(t('mineTarget', 'Object')) + '<select class="manny-mine-target" name="objectId">' + mineTargetOptions('') + '</select></label>'
            + '<label>' + escapeHtml(t('mineResourcesSelection', 'Select resources to extract')) + '<select class="manny-mine-resources" name="resources" multiple size="4">'
            + mineResourceOptions(mineTarget, [])
            + '</select></label>'
            + '<label class="manny-mine-amount-label"><span class="manny-mine-amount-text">' + escapeHtml(miningAmountLabel(mineAmountMax)) + '</span><input name="targetAmount" type="number" min="0.01" max="' + escapeHtml(String(mineAmountMax)) + '" step="0.01" value="' + escapeHtml(mineAmountMax >= 0.01 ? '0.01' : '0') + '"></label>'
            + '<button class="manny-mine-button" type="submit"' + (mineTarget ? '' : ' disabled aria-disabled="true"') + '>' + escapeHtml(t('mine', 'Mine')) + '</button>'
            + '<p class="manny-mine-hint">' + escapeHtml(t('mannyMiningHint', 'A Manny can carry 0.05 ECE (Earth container equivalent). If you ask it to mine more, it will make round trips between the mined object and the probe.')) + '</p>'
            + '</form>';
    };

    const renderSalvageForm = () => {
        const salvageTarget = state.currentMannySalvageTargets[0] || null;

        return '<form class="manny-salvage-form manny-form">'
            + '<label>' + escapeHtml(t('salvageTarget', 'Drifting object')) + '<select class="manny-salvage-target" name="objectId">' + salvageTargetOptions('') + '</select></label>'
            + '<button class="manny-salvage-button" type="submit"' + (salvageTarget ? '' : ' disabled aria-disabled="true"') + '>' + escapeHtml(t('salvage', 'Salvage')) + '</button>'
            + '<p class="manny-salvage-hint">' + escapeHtml(t('mannySalvageHint', 'A recovered object is checked again at the end of the five-minute recovery delay.')) + '</p>'
            + '</form>';
    };

    const renderCraftForm = () => (
        '<form class="manny-craft-form manny-form">'
        + '<div class="manny-craft-picker">'
        + '<label>' + escapeHtml(t('recipe', 'Recipe')) + '<select class="manny-craft-recipe" name="recipe">' + crafting.craftRecipeOptions('') + '</select></label>'
        + '<div class="manny-craft-ingredients" aria-live="polite"></div>'
        + '</div>'
        + '<button class="manny-craft-button" type="submit">' + escapeHtml(t('craft', 'Craft')) + '</button>'
        + '</form>'
    );

    const renderMannyActionForms = (idPrefix) => {
        const prefix = String(idPrefix || 'manny-actions').replace(/[^a-zA-Z0-9_-]/g, '-');
        const actionForms = [
            {id: 'repair', title: t('repairActionTitle', 'Repair'), render: renderRepairForm},
            {id: 'mine', title: t('miningActionTitle', 'Mine'), render: renderMineForm},
            {id: 'salvage', title: t('salvageActionTitle', 'Recover a drifting object'), render: renderSalvageForm},
            {id: 'craft', title: t('craftingActionTitle', 'Craft'), render: renderCraftForm},
        ];

        return '<div class="manny-action-grid">'
            + '<h4 class="manny-action-heading">' + escapeHtml(t('assignMannyTask', 'Assign a task to this Manny')) + '</h4>'
            + actionForms.map((action) => renderMannyActionAccordion(prefix + '-' + action.id, action.title, action.render())).join('')
            + '</div>';
    };

    const mannyLocation = (manny) => {
        const location = manny.location || {};
        if (location.type === 'probe') {
            return t('tabProbe', 'Probe');
        }
        return location.sector && location.sector.relative
            ? t('sector', 'Sector') + ' ' + coordinate(location.sector.relative)
            : t('sector', 'Sector');
    };

    const mannyCargoAmounts = (manny) => {
        const cargo = manny.cargo || {};
        return {
            deuterium: cargo.deuterium,
            metals: cargo.metals,
            ice: cargo.ice,
            organicCompounds: cargo.organicCompounds,
        };
    };

    const mannyCargo = (manny) => {
        const cargo = mannyCargoAmounts(manny);
        return [
            resourceTypeLabel('deuterium') + ': ' + numberValue(cargo.deuterium),
            resourceTypeLabel('metals') + ': ' + numberValue(cargo.metals),
            resourceTypeLabel('ice') + ': ' + numberValue(cargo.ice),
            resourceTypeLabel('carbon_compounds') + ': ' + numberValue(cargo.organicCompounds),
        ].join('\n');
    };

    function mineTargetOptions(selected) {
        if (state.currentMannyMineTargets.length === 0) {
            return '<option value="">-</option>';
        }

        return state.currentMannyMineTargets.map((target) => (
            '<option value="' + escapeHtml(target.id) + '"' + (target.id === selected ? ' selected' : '') + '>'
            + escapeHtml(sector.mineTargetLabel(target))
            + '</option>'
        )).join('');
    }

    function salvageTargetOptions(selected) {
        if (state.currentMannySalvageTargets.length === 0) {
            return '<option value="">-</option>';
        }

        return state.currentMannySalvageTargets.map((target) => (
            '<option value="' + escapeHtml(target.id) + '"' + (target.id === selected ? ' selected' : '') + '>'
            + escapeHtml(sector.salvageTargetLabel(target))
            + '</option>'
        )).join('');
    }

    function resourceProfileForMineSelection(target, selectedResources) {
        const available = sector.resourceTypesForTarget(target);
        const selected = selectedResources.filter((type) => available.includes(type));
        const effectiveSelection = selected.length > 0 ? selected : available;
        const composition = sector.resourceCompositionForTarget(target);
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
            return mannyMiningAmountMax;
        }

        const amounts = target.resourceAmounts && typeof target.resourceAmounts === 'object'
            ? target.resourceAmounts
            : null;
        if (!amounts) {
            return mannyMiningAmountMax;
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
            return mannyMiningAmountMax;
        }

        const capped = Math.min(mannyMiningAmountMax, ...limits);
        return Math.max(0, Math.floor(capped * 100) / 100);
    }

    function miningAmountLabel(maxAmount) {
        return formatText(t('targetAmountWithMax', 'Amount (max. {max})'), {
            max: numberValue(maxAmount),
        });
    }

    function mineResourceOptions(target, selectedResources) {
        const available = sector.resourceTypesForTarget(target);
        const selected = selectedResources.filter((type) => available.includes(type));
        const effectiveSelection = selected.length > 0 ? selected : available;

        return miningResourceTypes.map((type) => {
            const disabled = !available.includes(type);
            const isSelected = effectiveSelection.includes(type);

            return '<option value="' + escapeHtml(type) + '"'
                + (disabled ? ' disabled' : '')
                + (isSelected ? ' selected' : '')
                + '>' + escapeHtml(resourceTypeLabel(type)) + '</option>';
        }).join('');
    }

    function updateMannyMineFormState(form) {
        if (!form) {
            return;
        }

        const targetSelect = form.querySelector('.manny-mine-target');
        const resourceSelect = form.querySelector('.manny-mine-resources');
        const amountInput = form.querySelector('input[name="targetAmount"]');
        const amountText = form.querySelector('.manny-mine-amount-text');
        const mineButton = form.querySelector('.manny-mine-button');
        if (!targetSelect || !resourceSelect) {
            return;
        }

        const target = state.currentMannyMineTargets.find((item) => item.id === targetSelect.value) || null;
        const selectedResources = Array.from(resourceSelect.selectedOptions)
            .filter((option) => !option.disabled)
            .map((option) => option.value);
        const maxAmount = mineTargetMaxAmount(target, selectedResources);
        if (amountText) {
            amountText.textContent = miningAmountLabel(maxAmount);
        }
        if (amountInput) {
            amountInput.max = String(maxAmount);
            const currentAmount = Number(amountInput.value);
            if (!Number.isFinite(currentAmount) || currentAmount <= 0) {
                amountInput.value = maxAmount >= 0.01 ? '0.01' : '0';
            } else if (currentAmount > maxAmount) {
                amountInput.value = String(maxAmount);
            }
        }
        if (mineButton) {
            const disabled = !target || maxAmount < 0.01;
            mineButton.disabled = disabled;
            mineButton.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            mineButton.title = !target ? t('noMiningTargetSelected', 'Select a mining target.') : '';
        }
    }

    function updateMannyResourceOptions(form) {
        if (!form) {
            return;
        }

        const targetSelect = form.querySelector('.manny-mine-target');
        const resourceSelect = form.querySelector('.manny-mine-resources');
        if (!targetSelect || !resourceSelect) {
            return;
        }

        const target = state.currentMannyMineTargets.find((item) => item.id === targetSelect.value) || null;
        const selectedResources = Array.from(resourceSelect.selectedOptions).map((option) => option.value);
        resourceSelect.innerHTML = mineResourceOptions(target, selectedResources);
        updateMannyMineFormState(form);
    }

    function updateMannyTargetOptions() {
        document.querySelectorAll('.manny-mine-target').forEach((select) => {
            const selected = select.value;
            select.innerHTML = mineTargetOptions(selected);
            if (!state.currentMannyMineTargets.some((target) => target.id === select.value)) {
                select.value = state.currentMannyMineTargets[0] ? state.currentMannyMineTargets[0].id : '';
            }
            updateMannyResourceOptions(select.closest('.manny-mine-form'));
        });
        document.querySelectorAll('.manny-salvage-target').forEach((select) => {
            const selected = select.value;
            select.innerHTML = salvageTargetOptions(selected);
            if (!state.currentMannySalvageTargets.some((target) => target.id === select.value)) {
                select.value = state.currentMannySalvageTargets[0] ? state.currentMannySalvageTargets[0].id : '';
            }
            const form = select.closest('.manny-salvage-form');
            const button = form ? form.querySelector('.manny-salvage-button') : null;
            if (button) {
                const hasTarget = state.currentMannySalvageTargets.some((target) => target.id === select.value);
                button.disabled = !hasTarget;
                button.setAttribute('aria-disabled', hasTarget ? 'false' : 'true');
            }
        });
    }

    function clearMannyProgressTimers() {
        if (mannyProgressTickTimer !== null) {
            window.clearTimeout(mannyProgressTickTimer);
            mannyProgressTickTimer = null;
        }
        mannyCompletionTimers.forEach((timer) => window.clearTimeout(timer));
        mannyCompletionTimers = [];
    }

    function updateLiveMannyProgressValues() {
        const progressNodes = Array.from(document.querySelectorAll('#manny-list .manny-task-progress-value[data-progress-end-at]'));
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
            node.textContent = numberValue(progress, '%');
            if (progress < 100 && now < endAt) {
                hasPendingProgress = true;
            }
        });

        mannyProgressTickTimer = hasPendingProgress
            ? window.setTimeout(updateLiveMannyProgressValues, 1000)
            : null;
    }

    function refreshManniesAfterTaskEnd() {
        if (mannyCompletionRefreshPending) {
            return;
        }
        mannyCompletionRefreshPending = true;
        window.setTimeout(async () => {
            try {
                await loadMannies();
                await refreshers.loadProbe?.();
                await refreshers.loadCurrentSector?.();
            } finally {
                mannyCompletionRefreshPending = false;
            }
        }, 150);
    }

    function scheduleMannyProgressUpdates() {
        const endTimes = new Set(Array.from(document.querySelectorAll('#manny-list .manny-task-progress-value[data-progress-end-at]'))
            .map((node) => Number(node.dataset.progressEndAt))
            .filter((endAt) => Number.isFinite(endAt)));
        if (endTimes.size === 0) {
            return;
        }

        updateLiveMannyProgressValues();
        endTimes.forEach((endAt) => {
            mannyCompletionTimers.push(window.setTimeout(refreshManniesAfterTaskEnd, Math.max(0, endAt - Date.now()) + 500));
        });
    }

    function renderMannyList(mannies) {
        const node = document.getElementById('manny-list');
        if (!node) {
            return;
        }
        clearMannyProgressTimers();
        const openMannyIds = new Set(Array.from(node.querySelectorAll('.manny-card[data-manny-id] .manny-accordion-trigger[aria-expanded="true"]'))
            .map((button) => button.closest('.manny-card')?.dataset.mannyId)
            .filter(Boolean));
        if (!Array.isArray(mannies) || mannies.length === 0) {
            node.innerHTML = '';
            return;
        }

        const observedAt = Date.now();
        node.innerHTML = mannies.map((manny) => {
            const busy = manny.currentTask !== null;
            const mannyId = String(manny.id ?? '');
            const taskName = manny.currentTask || t('noTask', 'None');
            const panelId = 'manny-panel-' + mannyId.replace(/[^a-zA-Z0-9_-]/g, '-');
            const expanded = openMannyIds.has(mannyId);
            const buttonTitle = manny.name + ' - ' + taskName;
            const progressAttributes = busy ? mannyProgressDataAttributes(manny, observedAt) : '';
            return '<article class="manny-card" data-manny-id="' + escapeHtml(manny.id) + '">'
                + '<button class="manny-accordion-trigger" type="button" aria-expanded="' + (expanded ? 'true' : 'false') + '" aria-controls="' + escapeHtml(panelId) + '" title="' + escapeHtml(buttonTitle) + '" aria-label="' + escapeHtml(buttonTitle) + '">'
                + '<span class="manny-accordion-title">'
                + '<b>' + escapeHtml(manny.name) + '</b>'
                + '<span class="manny-accordion-task">' + escapeHtml(taskName) + '</span>'
                + '</span>'
                + '</button>'
                + '<div id="' + escapeHtml(panelId) + '" class="manny-accordion-panel"' + (expanded ? '' : ' hidden') + '>'
                + '<div class="manny-card-tools">'
                + '<button class="manny-settings-button icon-button" type="button" aria-expanded="false" title="' + escapeHtml(t('mannySettings', 'Manny settings')) + '" aria-label="' + escapeHtml(t('mannySettings', 'Manny settings')) + '">&#9881;</button>'
                + '</div>'
                + '<div class="manny-metrics">'
                + metric(t('location', 'Location'), mannyLocation(manny))
                + metric(t('cargo', 'Cargo'), mannyCargo(manny), null, 'manny-cargo-value')
                + metric(t('task', 'Task'), busy ? mannyProgressText(manny) : t('noTask', 'None'), null, busy ? 'manny-task-progress-value' : null, progressAttributes)
                + '</div>'
                + '<form class="manny-rename-form manny-form" hidden>'
                + '<label>' + escapeHtml(t('rename', 'Rename')) + '<input name="name" value="' + escapeHtml(manny.name) + '" maxlength="40"></label>'
                + '<button type="submit">' + escapeHtml(t('rename', 'Rename')) + '</button>'
                + '</form>'
                + (busy ? renderMannyTaskPanel(manny, observedAt) : renderMannyActionForms(panelId))
                + '</div>'
                + '</article>';
        }).join('');
        scheduleMannyProgressUpdates();
        crafting.updateMannyCraftForms();
    }

    async function loadMannies() {
        try {
            const data = await api('/api/probe/mannies');
            renderMannyList(data.mannies || []);
            setText('manny-json', pretty(data));
        } catch (error) {
            renderMannyList([]);
            setText('manny-json', error.message);
        }
    }

    const submitHandlers = [
        {
            matches: (form) => form.classList.contains('manny-rename-form'),
            submit: ({form, formData, mannyId}) => api('/api/probe/mannies/' + encodeURIComponent(mannyId), {
                method: 'PATCH',
                body: JSON.stringify({name: formData.get('name')}),
            }),
        },
        {
            matches: (form) => form.classList.contains('manny-repair-form'),
            submit: ({formData, mannyId}) => api('/api/probe/mannies/' + encodeURIComponent(mannyId) + '/repair', {
                method: 'POST',
                body: JSON.stringify({integrityPercent: Number.parseFloat(formData.get('integrityPercent'))}),
            }),
        },
        {
            matches: (form) => form.classList.contains('manny-mine-form'),
            submit: ({form, formData, mannyId}) => {
                const targetSelect = form.querySelector('.manny-mine-target');
                if (!targetSelect || !targetSelect.value) {
                    updateMannyMineFormState(form);
                    setText('manny-status', t('noMiningTargetSelected', 'Select a mining target.'));
                    return null;
                }
                const resourceSelect = form.querySelector('.manny-mine-resources');
                const resources = resourceSelect
                    ? Array.from(resourceSelect.selectedOptions).filter((option) => !option.disabled).map((option) => option.value)
                    : [];
                if (resources.length === 0) {
                    setText('manny-status', t('noMiningResourceSelected', 'Select at least one available resource.'));
                    return null;
                }

                return api('/api/probe/mannies/' + encodeURIComponent(mannyId) + '/mine', {
                    method: 'POST',
                    body: JSON.stringify({
                        objectId: formData.get('objectId'),
                        resources,
                        targetAmount: Number.parseFloat(formData.get('targetAmount')),
                    }),
                });
            },
        },
        {
            matches: (form) => form.classList.contains('manny-salvage-form'),
            submit: ({form, formData, mannyId}) => {
                const targetSelect = form.querySelector('.manny-salvage-target');
                if (!targetSelect || !targetSelect.value) {
                    setText('manny-status', t('noSalvageTargetSelected', 'Select a drifting object to recover.'));
                    return null;
                }

                return api('/api/probe/mannies/' + encodeURIComponent(mannyId) + '/salvage', {
                    method: 'POST',
                    body: JSON.stringify({objectId: formData.get('objectId')}),
                });
            },
        },
        {
            matches: (form) => form.classList.contains('manny-craft-form'),
            submit: ({form, formData, mannyId}) => {
                const recipe = crafting.craftingRecipeById(String(formData.get('recipe') || ''));
                if (!crafting.canCraftRecipe(recipe)) {
                    crafting.updateMannyCraftForm(form);
                    setText('manny-status', t('missingCraftIngredients', 'Insufficient ingredients.'));
                    return null;
                }

                return api('/api/probe/mannies/' + encodeURIComponent(mannyId) + '/craft', {
                    method: 'POST',
                    body: JSON.stringify({recipe: formData.get('recipe')}),
                });
            },
        },
    ];

    async function submitMannyForm(form, mannyId, formData) {
        const handler = submitHandlers.find((candidate) => candidate.matches(form));
        if (!handler) {
            return false;
        }

        const result = handler.submit({form, formData, mannyId});
        if (result === null) {
            return false;
        }
        await result;

        return true;
    }

    function bindMannyEvents() {
        const mannyList = document.getElementById('manny-list');
        if (!mannyList) {
            return;
        }

        mannyList.addEventListener('submit', async (event) => {
            event.preventDefault();
            const card = event.target.closest('.manny-card');
            const mannyId = card ? card.dataset.mannyId : null;
            if (!mannyId) {
                return;
            }
            const form = new FormData(event.target);
            setText('manny-status', t('orderSent', 'Order transmitted...'));
            try {
                const handled = await submitMannyForm(event.target, mannyId, form);
                if (!handled) {
                    return;
                }
                setText('manny-status', t('mannyOrderAccepted', 'Manny order accepted.'));
                refreshers.loadProbe?.();
                loadMannies();
            } catch (error) {
                setText('manny-status', error.message);
            }
        });

        mannyList.addEventListener('change', (event) => {
            if (event.target.classList.contains('manny-mine-target')) {
                updateMannyResourceOptions(event.target.closest('.manny-mine-form'));
            }
            if (event.target.classList.contains('manny-mine-resources')) {
                updateMannyMineFormState(event.target.closest('.manny-mine-form'));
            }
            if (event.target.classList.contains('manny-salvage-target')) {
                updateMannyTargetOptions();
            }
            if (event.target.classList.contains('manny-craft-recipe')) {
                crafting.updateMannyCraftForm(event.target.closest('.manny-craft-form'));
            }
        });

        mannyList.addEventListener('click', async (event) => {
            const accordionButton = event.target.closest('.manny-accordion-trigger');
            if (accordionButton) {
                toggleAccordion(accordionButton, '#manny-list', '.manny-accordion-trigger', '.manny-accordion-panel');
                return;
            }

            const actionAccordionButton = event.target.closest('.manny-action-accordion-trigger');
            if (actionAccordionButton) {
                toggleAccordion(actionAccordionButton, '.manny-action-grid', '.manny-action-accordion-trigger', '.manny-action-accordion-panel');
                return;
            }

            const settingsButton = event.target.closest('.manny-settings-button');
            if (settingsButton) {
                const card = settingsButton.closest('.manny-card');
                const renameForm = card ? card.querySelector('.manny-rename-form') : null;
                if (!renameForm) {
                    return;
                }

                const willOpen = renameForm.hidden;
                renameForm.hidden = !willOpen;
                settingsButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                if (willOpen) {
                    renameForm.querySelector('input[name="name"]')?.focus();
                }
                return;
            }

            const button = event.target.closest('.manny-recall-button');
            if (!button) {
                return;
            }
            const card = button.closest('.manny-card');
            const mannyId = card ? card.dataset.mannyId : null;
            if (!mannyId) {
                return;
            }
            setText('manny-status', t('orderSent', 'Order transmitted...'));
            try {
                await api('/api/probe/mannies/' + encodeURIComponent(mannyId) + '/recall', {
                    method: 'POST',
                    body: JSON.stringify({}),
                });
                setText('manny-status', t('mannyOrderAccepted', 'Manny order accepted.'));
                refreshers.loadProbe?.();
                loadMannies();
            } catch (error) {
                setText('manny-status', error.message);
            }
        });
    }

    return {
        bindMannyEvents,
        loadMannies,
        renderMannyList,
        updateMannyMineFormState,
        updateMannyTargetOptions,
    };
};
