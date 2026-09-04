"""Moisson et production du vaisseau mère pendant la défense."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timedelta
from math import floor
from typing import Any, Callable

from .contracts import require_mapping, require_string
from .errors import ApiContractError, ApiRequestError
from .geometry import parse_coordinates
from .models import CycleResult
from .ports import OthersApi

RESOURCE_TYPES = ("metals", "ice", "carbon_compounds", "deuterium")
ACTIVE_STATUSES = {"queued", "running"}


@dataclass(frozen=True)
class LogisticsPolicy:
    auxiliary_target: int = 20
    missile_target: int = 60
    reserve_auxiliaries: int = 10
    reserve_missiles: int = 10
    max_harvest_auxiliaries: int = 10
    harvest_cycle_seconds: int = 3600


@dataclass(frozen=True)
class WorkshopRecipe:
    identifier: str
    duration_seconds: int
    ingredients: dict[str, float]
    output_kind: str
    output_space_ece: float

    @classmethod
    def from_api(cls, value: dict[str, Any]) -> WorkshopRecipe:
        identifier = require_string(value.get("id"), "recipes[].id")
        duration = _non_negative_number(value.get("durationSeconds"), "recipes[].durationSeconds")
        ingredients_value = require_mapping(value.get("ingredients"), "recipes[].ingredients")
        ingredients = {
            resource_type: _non_negative_number(
                ingredients_value.get(resource_type),
                f"recipe {identifier}.ingredients.{resource_type}",
            )
            for resource_type in RESOURCE_TYPES
        }
        output = require_mapping(value.get("output"), f"recipe {identifier}.output")
        output_kind = require_string(output.get("kind"), f"recipe {identifier}.output.kind")
        output_space = _non_negative_number(
            output.get("containerSpaceEce", 0.0),
            f"recipe {identifier}.output.containerSpaceEce",
        )
        return cls(identifier, int(duration), ingredients, output_kind, output_space)


class MothershipLogistics:
    def __init__(
        self,
        api: OthersApi,
        *,
        logger: Callable[[str], None],
        now: Callable[[], datetime],
        policy: LogisticsPolicy | None = None,
    ) -> None:
        self.api = api
        self.log = logger
        self.now = now
        self.policy = policy or LogisticsPolicy()
        self._recipes: dict[str, WorkshopRecipe] | None = None
        self._harvest_cycle_started_at: datetime | None = None
        self._harvest_cycle_ends_at: datetime | None = None
        self._harvest_target_id: str | None = None
        self._command_sequence = 0

    def reconcile(
        self,
        mothership: dict[str, Any],
        result: CycleResult,
        *,
        fleet_missile_stock: int,
        missile_transfers_active: bool,
    ) -> None:
        ship_id = require_string(mothership.get("id"), "mothership.id")
        auxiliaries = self.api.get_auxiliaries(ship_id)
        if not auxiliaries:
            self.log("Logistique suspendue : le vaisseau mère ne possède aucun auxiliaire.")
            return

        recipes = self._workshop_recipes()
        auxiliary_recipe = recipes["others_auxiliary"]
        missile_recipe = recipes["missile"]
        inventory = self.api.get_inventory(ship_id)
        crafts = self.api.get_crafts(ship_id)
        active_crafts = [craft for craft in crafts if craft.get("status") in ACTIVE_STATUSES]
        for craft in active_crafts:
            result.add_event_date(craft.get("endsAt"), "crafts[].endsAt")

        active_harvest_actions = self._active_harvest_actions(auxiliaries)
        for action in active_harvest_actions.values():
            result.add_event_date(action.get("endsAt"), "auxiliaries[].action.endsAt")

        resources = self._available_resources(inventory)
        free_capacity = self._free_capacity(inventory)
        available_auxiliaries = self._available_auxiliaries(auxiliaries)
        pending_auxiliaries = sum(
            craft.get("recipeId") == auxiliary_recipe.identifier for craft in active_crafts
        )
        pending_missiles = sum(
            craft.get("recipeId") == missile_recipe.identifier for craft in active_crafts
        )
        projected_auxiliaries = len(auxiliaries) + pending_auxiliaries
        projected_missiles = fleet_missile_stock + pending_missiles

        while available_auxiliaries:
            if projected_auxiliaries < self.policy.auxiliary_target:
                recipe = auxiliary_recipe
            elif (
                projected_missiles < self.policy.missile_target
                and not missile_transfers_active
            ):
                recipe = missile_recipe
            else:
                break
            if not self._can_craft(recipe, resources, free_capacity):
                break

            assistant = available_auxiliaries.pop(0)
            assistant_id = require_string(assistant.get("id"), "auxiliaries[].id")
            try:
                action = self.api.start_craft(
                    ship_id,
                    recipe.identifier,
                    assistant_id,
                    self._operation_key("craft"),
                )
            except ApiRequestError as error:
                if error.status in {409, 422}:
                    self.log(
                        f"Craft {recipe.identifier} différé avec {assistant_id} : "
                        f"{error.code} ({error.message})."
                    )
                    break
                raise

            self._consume_recipe(recipe, resources)
            free_capacity -= recipe.output_space_ece
            result.accepted_commands += 1
            result.add_event_date(action.get("endsAt"), f"craft {recipe.identifier}.endsAt")
            if recipe.identifier == auxiliary_recipe.identifier:
                projected_auxiliaries += 1
            else:
                projected_missiles += 1
            self.log(f"Craft {recipe.identifier} lancé avec l'auxiliaire {assistant_id}.")

        reserve = self._reserve_requirements(auxiliary_recipe, missile_recipe)
        production_complete = (
            projected_auxiliaries >= self.policy.auxiliary_target
            and projected_missiles >= self.policy.missile_target
        )
        reserve_complete = all(
            resources[resource_type] + 0.00001 >= reserve[resource_type]
            for resource_type in RESOURCE_TYPES
        )
        if production_complete and reserve_complete:
            self._clear_harvest_cycle()
            self.log("Objectifs logistiques atteints : production et réserve de reconstruction complètes.")
            return

        if active_harvest_actions:
            self._ensure_harvest_cycle()
            self._add_harvest_cycle_deadline(result)
            return
        if not available_auxiliaries:
            self._add_harvest_cycle_deadline(result)
            return

        center = self._mothership_sector(mothership)
        scan = self.api.scan_sector(ship_id, center)
        target = self._select_harvest_target(scan)
        if target is None:
            self._clear_harvest_cycle()
            self.log("Moisson suspendue : aucune planète locale moissonnable.")
            return

        capacity_limited_count = floor((free_capacity + 0.00001) / 2.0)
        harvest_count = min(
            self.policy.max_harvest_auxiliaries,
            len(available_auxiliaries),
            capacity_limited_count,
        )
        if harvest_count <= 0:
            self.log("Moisson suspendue : capacité d'inventaire insuffisante.")
            return

        cycle_started = self._ensure_harvest_cycle()
        target_id = require_string(target.get("id"), "harvest target.id")
        self._harvest_target_id = target_id
        if cycle_started:
            self.log("Nouveau cycle de moisson d'une heure.")
        try:
            action = self.api.start_harvest(
                ship_id,
                target_id,
                harvest_count,
                self._operation_key("harvest"),
            )
        except ApiRequestError as error:
            if error.status in {409, 422}:
                self.log(
                    f"Moisson différée sur {target_id} : {error.code} ({error.message})."
                )
                self._add_harvest_cycle_deadline(result)
                return
            raise

        result.accepted_commands += 1
        result.add_event_date(action.get("endsAt"), "harvest action.endsAt")
        self._add_harvest_cycle_deadline(result)
        self.log(f"Moisson lancée sur {target_id} avec {harvest_count} auxiliaire(s).")

    def _workshop_recipes(self) -> dict[str, WorkshopRecipe]:
        if self._recipes is None:
            self._recipes = {
                recipe.identifier: recipe
                for recipe in (
                    WorkshopRecipe.from_api(value)
                    for value in self.api.get_crafting_recipes()
                )
            }
            missing = {"others_auxiliary", "missile"} - self._recipes.keys()
            if missing:
                raise ApiContractError(
                    "Recettes Others manquantes : " + ", ".join(sorted(missing)) + "."
                )
        return self._recipes

    @staticmethod
    def _available_resources(inventory: dict[str, Any]) -> dict[str, float]:
        values = require_mapping(inventory.get("resources"), "inventory.resources")
        resources: dict[str, float] = {}
        for resource_type in RESOURCE_TYPES:
            resource = require_mapping(
                values.get(resource_type),
                f"inventory.resources.{resource_type}",
            )
            amount = _non_negative_number(
                resource.get("amount"),
                f"inventory.resources.{resource_type}.amount",
            )
            reserved = _non_negative_number(
                resource.get("reserved"),
                f"inventory.resources.{resource_type}.reserved",
            )
            resources[resource_type] = max(0.0, amount - reserved)
        return resources

    @staticmethod
    def _free_capacity(inventory: dict[str, Any]) -> float:
        capacity = _non_negative_number(inventory.get("capacityEce"), "inventory.capacityEce")
        used = _non_negative_number(inventory.get("usedEce"), "inventory.usedEce")
        reserved = _non_negative_number(inventory.get("reservedEce"), "inventory.reservedEce")
        return max(0.0, capacity - used - reserved)

    @staticmethod
    def _available_auxiliaries(auxiliaries: list[dict[str, Any]]) -> list[dict[str, Any]]:
        return sorted(
            (
                auxiliary
                for auxiliary in auxiliaries
                if auxiliary.get("locationType") == "embarked"
                and auxiliary.get("status") in {"inactive", "available"}
                and auxiliary.get("action") is None
            ),
            key=lambda auxiliary: str(auxiliary.get("id", "")),
        )

    @staticmethod
    def _active_harvest_actions(
        auxiliaries: list[dict[str, Any]],
    ) -> dict[str, dict[str, Any]]:
        actions: dict[str, dict[str, Any]] = {}
        for auxiliary in auxiliaries:
            action_value = auxiliary.get("action")
            if action_value is None:
                continue
            action = require_mapping(action_value, "auxiliaries[].action")
            if action.get("type") != "planet_harvest" or action.get("status") not in ACTIVE_STATUSES:
                continue
            actions[require_string(action.get("id"), "auxiliaries[].action.id")] = action
        return actions

    @staticmethod
    def _can_craft(
        recipe: WorkshopRecipe,
        resources: dict[str, float],
        free_capacity: float,
    ) -> bool:
        return free_capacity + 0.00001 >= recipe.output_space_ece and all(
            resources[resource_type] + 0.00001 >= amount
            for resource_type, amount in recipe.ingredients.items()
        )

    @staticmethod
    def _consume_recipe(recipe: WorkshopRecipe, resources: dict[str, float]) -> None:
        for resource_type, amount in recipe.ingredients.items():
            resources[resource_type] = max(0.0, resources[resource_type] - amount)

    def _reserve_requirements(
        self,
        auxiliary_recipe: WorkshopRecipe,
        missile_recipe: WorkshopRecipe,
    ) -> dict[str, float]:
        return {
            resource_type: (
                auxiliary_recipe.ingredients[resource_type]
                * self.policy.reserve_auxiliaries
                + missile_recipe.ingredients[resource_type]
                * self.policy.reserve_missiles
            )
            for resource_type in RESOURCE_TYPES
        }

    @staticmethod
    def _mothership_sector(mothership: dict[str, Any]) -> tuple[int, int, int]:
        sector = require_mapping(mothership.get("sector"), "mothership.sector")
        return parse_coordinates(sector.get("relative"), "mothership.sector.relative")

    def _select_harvest_target(self, scan: dict[str, Any]) -> dict[str, Any] | None:
        if scan.get("knowledgeLevel") != "detailed":
            return None
        objects = scan.get("objects")
        if not isinstance(objects, list):
            raise ApiContractError("sector.objects doit être une liste.")

        candidates: dict[str, dict[str, Any]] = {}
        for value in objects:
            object_value = require_mapping(value, "sector.objects[]")
            self._collect_harvestable_planet(object_value, candidates)
            for collection_name in ("bookmarkTargets", "minableTargets"):
                nested = object_value.get(collection_name, [])
                if not isinstance(nested, list):
                    raise ApiContractError(
                        f"sector.objects[].{collection_name} doit être une liste."
                    )
                for target_value in nested:
                    self._collect_harvestable_planet(
                        require_mapping(target_value, f"{collection_name}[]"),
                        candidates,
                    )
        if self._harvest_target_id in candidates:
            return candidates[self._harvest_target_id]
        if not candidates:
            return None
        return min(
            candidates.values(),
            key=lambda candidate: (
                candidate.get("intelligentLife") is True,
                str(candidate.get("id", "")),
            ),
        )

    @staticmethod
    def _collect_harvestable_planet(
        value: dict[str, Any],
        candidates: dict[str, dict[str, Any]],
    ) -> None:
        if value.get("type") != "planet" or value.get("harvestable") is not True:
            return
        identifier = require_string(value.get("id"), "harvestable planet.id")
        candidates[identifier] = value

    def _ensure_harvest_cycle(self) -> bool:
        now = self.now()
        if self._harvest_cycle_ends_at is not None and now < self._harvest_cycle_ends_at:
            return False
        self._harvest_cycle_started_at = now
        self._harvest_cycle_ends_at = now + timedelta(
            seconds=self.policy.harvest_cycle_seconds
        )
        self._harvest_target_id = None
        return True

    def _add_harvest_cycle_deadline(self, result: CycleResult) -> None:
        if self._harvest_cycle_ends_at is not None:
            result.event_dates.append(self._harvest_cycle_ends_at)

    def _clear_harvest_cycle(self) -> None:
        self._harvest_cycle_started_at = None
        self._harvest_cycle_ends_at = None
        self._harvest_target_id = None

    def _operation_key(self, kind: str) -> str:
        self._command_sequence += 1
        cycle = self._harvest_cycle_started_at or self.now()
        return f"{kind}:{cycle.isoformat()}:{self._command_sequence}"


def _non_negative_number(value: Any, context: str) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)) or value < 0:
        raise ApiContractError(f"{context} doit être un nombre positif ou nul.")
    return float(value)
