import {
    duration,
    escapeHtml,
    formatText,
    numberValue,
    numericCount,
} from './utils.js?v=20260604-system-bodies-v2';

export const createCraftingModule = ({state, labels}) => {
    const {
        inventoryItemTypeLabel,
        normalizeResourceType,
        resourceTypeLabel,
        t,
    } = labels;

    const craftingDuration = (seconds) => duration(seconds, t);

    function fallbackMannyCraftingRecipes() {
        return [{
            id: 'waypoint_bookmark',
            name: t('waypointBookmark', 'Waypoint bookmark'),
            craftableBy: ['manny'],
            ingredients: [{
                type: 'metals',
                quantity: 0.01,
                unit: 'earth_container_equivalent',
            }],
        }];
    }

    function mannyCraftingRecipes() {
        const recipes = state.currentCraftingRecipes.filter((recipe) => (
            recipe && typeof recipe === 'object' && Array.isArray(recipe.craftableBy) && recipe.craftableBy.includes('manny')
        ));

        return recipes.length > 0 ? recipes : fallbackMannyCraftingRecipes();
    }

    function craftingRecipeName(recipe) {
        if (!recipe) {
            return '-';
        }

        return inventoryItemTypeLabel(recipe.id, recipe.name || recipe.id || '-');
    }

    function craftingRecipeById(id) {
        return mannyCraftingRecipes().find((recipe) => recipe.id === id) || null;
    }

    function craftingRecipeOutputType(recipe) {
        const output = recipe && recipe.output && typeof recipe.output === 'object' ? recipe.output : {};

        return String(output.type || (recipe && recipe.id) || '');
    }

    function craftingRecipeByOutputType(type) {
        return mannyCraftingRecipes().find((recipe) => (
            craftingRecipeOutputType(recipe) === type || recipe.id === type
        )) || null;
    }

    function craftRecipeOptions(selected) {
        const recipes = mannyCraftingRecipes();
        if (recipes.length === 0) {
            return '<option value="">' + escapeHtml(t('noCraftingRecipes', 'No recipes available.')) + '</option>';
        }

        return recipes.map((recipe, index) => {
            const recipeId = String(recipe.id || '');
            const isSelected = recipeId === selected || (!selected && index === 0);

            return '<option value="' + escapeHtml(recipeId) + '"' + (isSelected ? ' selected' : '') + '>'
                + escapeHtml(craftingRecipeName(recipe))
                + '</option>';
        }).join('');
    }

    function inventoryResourceAmount(type) {
        if (!state.currentInventory || !Array.isArray(state.currentInventory.resourceStocks)) {
            return 0;
        }

        const normalizedType = normalizeResourceType(type);
        return state.currentInventory.resourceStocks.reduce((total, stock) => (
            normalizeResourceType(stock.type) === normalizedType
                ? total + numericCount(stock.amount)
                : total
        ), 0);
    }

    function inventoryItemCount(type) {
        return Array.isArray(state.currentInventory && state.currentInventory.items)
            ? state.currentInventory.items.filter((item) => item.type === type).length
            : 0;
    }

    function craftIngredientKind(ingredient) {
        if (ingredient && ingredient.kind) {
            return String(ingredient.kind);
        }

        return ingredient && ingredient.unit === 'item' ? 'item' : 'resource';
    }

    function addCraftPlanResourceCost(resourceCosts, type, quantity) {
        const normalizedType = normalizeResourceType(type);
        const amount = Number(quantity);
        if (!normalizedType || !Number.isFinite(amount) || amount <= 0) {
            return;
        }

        resourceCosts[normalizedType] = Math.round(((resourceCosts[normalizedType] || 0) + amount) * 10000) / 10000;
    }

    function directResourceCostsForRecipe(recipe) {
        if (!recipe || !Array.isArray(recipe.ingredients)) {
            return null;
        }

        return recipe.ingredients.reduce((resourceCosts, ingredient) => {
            if (resourceCosts === null || craftIngredientKind(ingredient) !== 'resource') {
                return null;
            }
            addCraftPlanResourceCost(resourceCosts, ingredient.type, craftIngredientAmount(ingredient));

            return resourceCosts;
        }, {});
    }

    function craftIngredientAmount(ingredient) {
        const quantity = Number(ingredient && ingredient.quantity);
        return Number.isFinite(quantity) ? quantity : 0;
    }

    function craftAvailability(recipe) {
        const result = {
            canCraft: false,
            durationSeconds: 0,
            itemStatuses: [],
            resourceStatuses: [],
        };
        if (!recipe) {
            return result;
        }

        const ingredients = Array.isArray(recipe.ingredients) ? recipe.ingredients : [];
        const resourceCosts = {};
        let canCraft = true;
        let durationSeconds = Number(recipe.durationSeconds);
        durationSeconds = Number.isFinite(durationSeconds) ? Math.max(0, durationSeconds) : 0;
        ingredients.forEach((ingredient) => {
            if (craftIngredientKind(ingredient) !== 'item') {
                addCraftPlanResourceCost(resourceCosts, ingredient.type, craftIngredientAmount(ingredient));
                return;
            }

            const type = String(ingredient.type || '');
            const required = Math.ceil(Math.max(0, craftIngredientAmount(ingredient)));
            const available = inventoryItemCount(type);
            const missing = Math.max(0, required - available);
            let craftedFromResources = 0;
            if (missing > 0) {
                const componentRecipe = craftingRecipeByOutputType(type);
                const componentResourceCosts = directResourceCostsForRecipe(componentRecipe);
                if (!componentRecipe || componentResourceCosts === null) {
                    canCraft = false;
                } else {
                    Object.entries(componentResourceCosts).forEach(([resourceType, quantity]) => {
                        addCraftPlanResourceCost(resourceCosts, resourceType, Number(quantity) * missing);
                    });
                    durationSeconds += Math.max(0, Number(componentRecipe.durationSeconds) || 0) * missing;
                    craftedFromResources = missing;
                }
            }

            result.itemStatuses.push({
                type,
                required,
                available,
                missing,
                craftedFromResources,
                canResolve: missing === 0 || craftedFromResources === missing,
            });
        });

        result.resourceStatuses = Object.entries(resourceCosts).map(([type, required]) => {
            const available = inventoryResourceAmount(type);
            const hasEnough = available + 0.00001 >= Number(required);
            canCraft = canCraft && hasEnough;

            return {
                type,
                required: Number(required),
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
            return '<span class="manny-craft-ingredients-title">' + escapeHtml(t('craftIngredientsRequired', 'Required ingredients')) + '</span>'
                + '<p>' + escapeHtml(t('noCraftIngredients', 'No ingredients required.')) + '</p>';
        }

        return '<span class="manny-craft-ingredients-title">' + escapeHtml(t('craftIngredientsRequired', 'Required ingredients')) + '</span>'
            + '<ul>'
            + availability.itemStatuses.map((status) => {
                const detail = status.craftedFromResources > 0
                    ? formatText(t('ingredientItemCraftedLine', '{required} required · {available} available · {crafted} crafted from resources'), {
                        required: status.required,
                        available: status.available,
                        crafted: status.craftedFromResources,
                    })
                    : formatText(t('ingredientItemStockLine', '{required} required · {available} available'), {
                        required: status.required,
                        available: status.available,
                    });

                return '<li class="' + (status.canResolve ? 'available' : 'missing') + '">'
                    + '<span>' + escapeHtml(inventoryItemTypeLabel(status.type, status.type)) + '</span>'
                    + '<b>' + escapeHtml(detail) + '</b>'
                    + '</li>';
            }).join('')
            + availability.resourceStatuses.map((status) => {
                const detail = formatText(t('ingredientStockLine', '{required} required · {available} available'), {
                    required: numberValue(status.required) + ' ' + t('containerUnit', 'containers'),
                    available: numberValue(status.available) + ' ' + t('containerUnit', 'containers'),
                });

                return '<li class="' + (status.hasEnough ? 'available' : 'missing') + '">'
                    + '<span>' + escapeHtml(resourceTypeLabel(normalizeResourceType(status.type))) + '</span>'
                    + '<b>' + escapeHtml(detail) + '</b>'
                    + '</li>';
            }).join('')
            + '</ul>'
            + '<p class="manny-craft-duration">' + escapeHtml(t('craftingDuration', 'Duration') + ' ' + craftingDuration(availability.durationSeconds)) + '</p>';
    }

    function updateMannyCraftForm(form) {
        if (!form) {
            return;
        }

        const select = form.querySelector('.manny-craft-recipe');
        const ingredientsNode = form.querySelector('.manny-craft-ingredients');
        const button = form.querySelector('.manny-craft-button');
        if (!select) {
            return;
        }

        const selected = select.value;
        select.innerHTML = craftRecipeOptions(selected);
        const recipe = craftingRecipeById(select.value);
        const canCraft = craftAvailability(recipe).canCraft;
        if (ingredientsNode) {
            ingredientsNode.innerHTML = renderCraftIngredients(recipe);
        }
        if (button) {
            button.disabled = !canCraft;
            button.title = canCraft ? '' : t('missingCraftIngredients', 'Insufficient ingredients.');
            button.setAttribute('aria-disabled', canCraft ? 'false' : 'true');
        }
    }

    function updateMannyCraftForms() {
        document.querySelectorAll('.manny-craft-form').forEach(updateMannyCraftForm);
    }

    async function loadCraftingRecipes(api) {
        try {
            const data = await api('/api/crafting-recipes');
            state.currentCraftingRecipes = Array.isArray(data.recipes) ? data.recipes : [];
        } catch (error) {
            state.currentCraftingRecipes = [];
        }
        updateMannyCraftForms();
    }

    return {
        canCraftRecipe,
        craftRecipeOptions,
        craftingRecipeById,
        loadCraftingRecipes,
        updateMannyCraftForm,
        updateMannyCraftForms,
    };
};
