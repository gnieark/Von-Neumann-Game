<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Domain\Mission;
use VonNeumannGame\Domain\MissionStep;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Repository\MissionRepository;

final class MissionService
{
    public function __construct(private readonly MissionRepository $missions) {}

    /**
     * @return array<Mission>
     */
    public function activeMissionsForProbe(NeumannProbe $probe): array
    {
        return $this->missions->activeForProbe($probe->id);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $createdByEvent
     * @param list<array{uid?:string,title:string,description?:?string,metadata?:array<string,mixed>}> $steps
     */
    public function startMission(
        NeumannProbe $probe,
        string $type,
        string $title,
        ?string $description = null,
        string $stepOrder = Mission::STEP_ORDER_FREE,
        array $metadata = [],
        ?array $createdByEvent = null,
        array $steps = [],
        ?string $uid = null,
    ): Mission {
        $stepOrder = in_array($stepOrder, [Mission::STEP_ORDER_FREE, Mission::STEP_ORDER_SEQUENTIAL], true)
            ? $stepOrder
            : Mission::STEP_ORDER_FREE;

        return $this->missions->create($probe->id, $type, $title, $description, $stepOrder, $metadata, $createdByEvent, $steps, $uid);
    }

    public function abandonMission(NeumannProbe $probe, string $missionUid): Mission
    {
        $mission = $this->missions->findByUidForProbe($probe->id, $missionUid)
            ?? throw new MannyActionException(404, 'mission_not_found', 'Mission not found.');
        if ($mission->isTerminal()) {
            throw new MannyActionException(409, 'mission_not_abandonable', 'Mission is already finished.');
        }

        return $this->missions->markAbandoned($mission);
    }

    public function completeStep(NeumannProbe $probe, string $missionUid, string $stepUid): Mission
    {
        $mission = $this->activeMissionForProbe($probe, $missionUid);
        $step = $this->missions->findStepByUid($mission->id, $stepUid)
            ?? throw new MannyActionException(404, 'mission_step_not_found', 'Mission step not found.');
        if ($step->status === MissionStep::STATUS_COMPLETED) {
            return $mission;
        }
        if ($step->status !== MissionStep::STATUS_PENDING) {
            throw new MannyActionException(409, 'mission_step_not_completable', 'Mission step is not pending.');
        }
        if ($mission->stepOrder === Mission::STEP_ORDER_SEQUENTIAL && !$this->previousStepsCompleted($mission, $step)) {
            throw new MannyActionException(409, 'mission_step_blocked', 'Previous mission steps must be completed first.');
        }

        $this->missions->markStepCompleted($step);
        $mission = $this->missions->findByUidForProbe($probe->id, $missionUid) ?? $mission;
        if ($this->allStepsCompleted($mission)) {
            return $this->missions->markCompleted($mission);
        }

        return $mission;
    }

    public function failStep(NeumannProbe $probe, string $missionUid, string $stepUid): Mission
    {
        $mission = $this->activeMissionForProbe($probe, $missionUid);
        $step = $this->missions->findStepByUid($mission->id, $stepUid)
            ?? throw new MannyActionException(404, 'mission_step_not_found', 'Mission step not found.');
        if ($step->status === MissionStep::STATUS_PENDING) {
            $this->missions->markStepFailed($step);
        }

        return $this->missions->markFailed($mission);
    }

    public function failMission(NeumannProbe $probe, string $missionUid): Mission
    {
        return $this->missions->markFailed($this->activeMissionForProbe($probe, $missionUid));
    }

    private function activeMissionForProbe(NeumannProbe $probe, string $missionUid): Mission
    {
        $mission = $this->missions->findByUidForProbe($probe->id, $missionUid)
            ?? throw new MannyActionException(404, 'mission_not_found', 'Mission not found.');
        if ($mission->isTerminal()) {
            throw new MannyActionException(409, 'mission_not_active', 'Mission is already finished.');
        }

        return $mission;
    }

    private function previousStepsCompleted(Mission $mission, MissionStep $step): bool
    {
        foreach ($mission->steps as $candidate) {
            if ($candidate->sortOrder >= $step->sortOrder) {
                continue;
            }
            if ($candidate->status !== MissionStep::STATUS_COMPLETED && $candidate->status !== MissionStep::STATUS_SKIPPED) {
                return false;
            }
        }

        return true;
    }

    private function allStepsCompleted(Mission $mission): bool
    {
        if ($mission->steps === []) {
            return false;
        }
        foreach ($mission->steps as $step) {
            if ($step->status !== MissionStep::STATUS_COMPLETED && $step->status !== MissionStep::STATUS_SKIPPED) {
                return false;
            }
        }

        return true;
    }
}
