<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\Mission;
use VonNeumannGame\Domain\MissionStep;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeMessage;
use VonNeumannGame\Repository\MissionRepository;
use VonNeumannGame\Repository\ProbeMessageRepository;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorCoordinates;

final class MissionService
{
    public const SCENARIO_RETURN_TO_SPACE_PROGRAM = 'return_to_space_program';
    public const FIRST_CONTACT_SIGNAL = '- -- --- ----- -------';
    public const FIRST_CONTACT_FULL_REPLY = '- -- --- ----- ------- -----------';
    public const FIRST_CONTACT_SHORT_REPLY = '-----------';

    private const FIRST_CONTACT_MISSION_TYPE = 'first_contact.return_to_space_program';
    private const FIRST_CONTACT_REPLY_STEP_UID = 'decode_prime_signal';
    private const FIRST_CONTACT_WAIT_STEP_UID = 'await_planetary_reply';

    public function __construct(
        private readonly MissionRepository $missions,
        private readonly ?ProbeMessageRepository $messages = null,
        private readonly array $gameplayConfig = [],
        private readonly string $worldSeed = 'default-world',
    ) {}

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

    public function startIntelligentLifeScenario(
        NeumannProbe $probe,
        SectorCoordinates $sector,
        Planet $planet,
        ?int $movementId = null,
    ): ?Mission {
        $scenario = $this->selectIntelligentLifeScenario($probe, $sector, $planet);
        if ($scenario !== self::SCENARIO_RETURN_TO_SPACE_PROGRAM) {
            return null;
        }

        return $this->startReturnToSpaceProgram($probe, $sector, $planet, $movementId);
    }

    public function handlePlanetReply(NeumannProbe $probe, string $planetId, string $body): ?Mission
    {
        if (!$this->isPrimeSignalReply($body)) {
            return null;
        }

        foreach ($this->missions->activeForProbe($probe->id) as $mission) {
            if ($mission->type !== self::FIRST_CONTACT_MISSION_TYPE) {
                continue;
            }
            if (($mission->metadata['scenario'] ?? null) !== self::SCENARIO_RETURN_TO_SPACE_PROGRAM) {
                continue;
            }
            if (($mission->metadata['planetId'] ?? null) !== $planetId) {
                continue;
            }

            return $this->completeStep($probe, $mission->uid, self::FIRST_CONTACT_REPLY_STEP_UID);
        }

        return null;
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

    private function startReturnToSpaceProgram(NeumannProbe $probe, SectorCoordinates $sector, Planet $planet, ?int $movementId): Mission
    {
        $planetName = $this->publicPlanetName($planet, $sector);
        $uid = $this->firstContactMissionUid($probe, $sector, $planet);
        $existing = $this->missions->findByUidForProbe($probe->id, $uid);
        if ($existing !== null) {
            return $existing;
        }

        $this->messages?->createForEndpoints(
            ProbeMessage::ENDPOINT_PLANET,
            $planet->getId(),
            $planetName,
            null,
            ProbeMessage::ENDPOINT_PROBE,
            (string) $probe->id,
            null,
            $probe->id,
            $sector,
            self::FIRST_CONTACT_SIGNAL,
        );

        return $this->startMission(
            $probe,
            self::FIRST_CONTACT_MISSION_TYPE,
            'Premier contact',
            'Un signal bref venu de la planete ' . $planetName . ', secteur ' . $sector->toKey() . ', semble s\'adresser a votre sonde. Il contient "' . self::FIRST_CONTACT_SIGNAL . '".',
            Mission::STEP_ORDER_SEQUENTIAL,
            [
                'scenario' => self::SCENARIO_RETURN_TO_SPACE_PROGRAM,
                'planetId' => $planet->getId(),
                'planetName' => $planetName,
                'sector' => $sector->toArray(),
                'initialSignal' => self::FIRST_CONTACT_SIGNAL,
                'acceptedReplies' => [self::FIRST_CONTACT_FULL_REPLY, self::FIRST_CONTACT_SHORT_REPLY],
            ],
            [
                'type' => 'intelligent_life_first_contact',
                'movementId' => $movementId,
                'planetId' => $planet->getId(),
                'sector' => $sector->toArray(),
            ],
            [
                [
                    'uid' => self::FIRST_CONTACT_REPLY_STEP_UID,
                    'title' => 'Repondre a la suite du motif',
                    'description' => 'Transmettre a la planete la suite du motif recu, complete ou reduite au terme suivant.',
                    'metadata' => [
                        'acceptedReplies' => [self::FIRST_CONTACT_FULL_REPLY, self::FIRST_CONTACT_SHORT_REPLY],
                    ],
                ],
                [
                    'uid' => self::FIRST_CONTACT_WAIT_STEP_UID,
                    'title' => 'Attendre la reponse planetaire',
                    'description' => 'La planete analyse votre reponse. Aucun protocole stable n\'est encore etabli.',
                    'metadata' => [],
                ],
            ],
            $uid,
        );
    }

    private function selectIntelligentLifeScenario(NeumannProbe $probe, SectorCoordinates $sector, Planet $planet): ?string
    {
        $scenarios = Config::getArray($this->gameplayConfig, 'intelligentLife.scenarios', []);
        if ($scenarios === []) {
            $scenarios = [
                self::SCENARIO_RETURN_TO_SPACE_PROGRAM => ['weight' => 100],
            ];
        }

        $weighted = [];
        foreach ($scenarios as $key => $config) {
            if (!is_string($key)) {
                continue;
            }
            $weight = is_array($config) ? (float) ($config['weight'] ?? 0) : (is_numeric($config) ? (float) $config : 0.0);
            if ($weight > 0) {
                $weighted[$key] = $weight;
            }
        }
        if ($weighted === []) {
            return null;
        }

        $total = array_sum($weighted);
        $roll = $this->deterministicUnitInterval($probe, $sector, $planet) * $total;
        $cursor = 0.0;
        foreach ($weighted as $key => $weight) {
            $cursor += $weight;
            if ($roll < $cursor) {
                return $key;
            }
        }

        return array_key_first($weighted);
    }

    private function deterministicUnitInterval(NeumannProbe $probe, SectorCoordinates $sector, Planet $planet): float
    {
        $hash = hash('sha256', implode('|', [
            $this->worldSeed,
            'intelligent-life-scenario',
            $probe->playerId,
            $probe->id,
            $sector->toKey(),
            $planet->getId(),
        ]));

        return hexdec(substr($hash, 0, 12)) / 0xffffffffffff;
    }

    private function firstContactMissionUid(NeumannProbe $probe, SectorCoordinates $sector, Planet $planet): string
    {
        return 'mis_first_contact_' . substr(hash('sha256', implode('|', [
            $probe->id,
            $sector->toKey(),
            $planet->getId(),
            self::SCENARIO_RETURN_TO_SPACE_PROGRAM,
        ])), 0, 20);
    }

    private function isPrimeSignalReply(string $body): bool
    {
        $normalized = preg_replace('/\s+/', ' ', trim($body));

        return in_array($normalized, [self::FIRST_CONTACT_FULL_REPLY, self::FIRST_CONTACT_SHORT_REPLY], true);
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

    private function publicPlanetName(Planet $planet, SectorCoordinates $sector): string
    {
        $name = $planet->getName();
        if ($name !== null && !$this->nameContainsSectorCoordinates($name, $sector)) {
            return $name;
        }

        return 'Monde habite';
    }

    private function nameContainsSectorCoordinates(string $name, SectorCoordinates $sector): bool
    {
        $absoluteKey = $sector->toKey();

        return str_contains($name, $absoluteKey)
            || str_contains($name, str_replace(':', '-', $absoluteKey))
            || str_contains($name, str_replace(':', ' ', $absoluteKey));
    }
}
