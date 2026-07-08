<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\Mission;
use VonNeumannGame\Domain\MissionStep;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeMessage;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Repository\MissionRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeMessageRepository;
use VonNeumannGame\Sector\DeuteriumRefuelStation;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorService;

final class MissionService
{
    public const SCENARIO_RETURN_TO_SPACE_PROGRAM = 'return_to_space_program';
    public const FIRST_CONTACT_SIGNAL = '-- --- ----- -------';
    public const FIRST_CONTACT_FULL_REPLY = '-- --- ----- ------- -----------';
    public const FIRST_CONTACT_SHORT_REPLY = '-----------';

    private const FIRST_CONTACT_MISSION_TYPE = 'first_contact.return_to_space_program';
    private const FIRST_CONTACT_REPLY_STEP_UID = 'decode_prime_signal';
    private const FIRST_CONTACT_WAIT_STEP_UID = 'await_planetary_reply';
    private const FIRST_CONTACT_DELIVER_METALS_STEP_UID = 'deliver_required_metals';
    private const FIRST_CONTACT_DELIVER_CARBON_STEP_UID = 'deliver_required_carbon_compounds';
    private const FIRST_CONTACT_AWAIT_STATION_STEP_UID = 'await_deuterium_refuel_station';
    private const RETURN_TO_SPACE_STATION_DELAY_SECONDS = 172800;
    private const RETURN_TO_SPACE_PLANET_REPLY = "We are the people of this world.\n\nOur civilization reached space 312 of our orbital cycles ago. We built orbital stations, scientific satellites, and missions to the other bodies of our system.\n\nThose activities ended.\n\nThe materials required to build launch vehicles are now exhausted, dispersed, or beyond our reach. Debris accumulated in orbit also makes new launches dangerous.\n\nOur knowledge remains. Our industrial skill remains. Our energy needs are covered.\n\nOur reserves of metals are insufficient.\nOur reserves of advanced carbon compounds are insufficient.\n\nWe have detected your ability to move matter between celestial bodies.\n\nWe request your assistance.\n\nRequested resources:\n\nMetals: 5 ECE\nCarbon compounds: 1 ECE\n\nIn return, once our path to orbit is restored, we will place a deuterium refuel station in orbit. We have more deuterium than we can use, and any probe that helped us return to space will be welcome there.";
    private const RETURN_TO_SPACE_MATERIAL_REQUIREMENTS = [
        ResourceComposition::METALS => 5.0,
        ResourceComposition::CARBON_COMPOUNDS => 1.0,
    ];
    private const RETURN_TO_SPACE_COMPLETION_MESSAGE_TEMPLATE = "Star traveler,\n\n%s\n\nThe materials you delivered contain elements that had become rare, unreachable, or impossible for us to refine with our present infrastructure. Our engineers have already begun integrating them into the first machines of our restored orbital industry.\n\nThese machines will let us reopen the deep-ocean deposits that still hold what our space program needs. For generations, those reserves were beyond our reach.\n\nFor the first time in centuries, our people can look upward with practical intent, not only memory.\n\nThe first orbital works will take time to assemble and test. Return in 48 hours. If our calculations hold, we will then be able to show you what your help has made possible.\n\nWith the gratitude of our world.";
    private const RETURN_TO_SPACE_STATION_READY_MESSAGE_TEMPLATE = "Star traveler,\n\n%s\n\nThe first orbital launch was successful. Our people have reached space again.\n\nAs promised, we have placed a deuterium refuel station in stable orbit. Its reserves are marked for the probes that helped us restore our path to the sky. We have no action protocol to offer you yet, but the station is present, transmitting, and ready for the day your systems can use it.\n\nTo us, the probes are friends of this world. May this station keep your journeys alive.";
    private const RETURN_TO_SPACE_FRIENDSHIP_REPLY = "Friend probe,\n\nThe sky is open to us again because of you and those who helped you. We consider the probes friends of this world.\n\nA deuterium refuel station is waiting in orbit. Its reserves are available to you whenever your systems are ready to use them.";

    public function __construct(
        private readonly MissionRepository $missions,
        private readonly ?ProbeMessageRepository $messages = null,
        private readonly array $gameplayConfig = [],
        private readonly string $worldSeed = 'default-world',
        private readonly ?SectorService $sectors = null,
        private readonly ?NeumannProbeRepository $probes = null,
        private readonly ?PlayerRepository $players = null,
    ) {}

    /**
     * @return array<Mission>
     */
    public function activeMissionsForPlayer(int $playerId): array
    {
        return $this->missions->activeForPlayer($playerId);
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

        return $this->missions->create($probe->playerId, $type, $title, $description, $stepOrder, $metadata, $createdByEvent, $steps, $uid);
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
        $stationActivated = $this->completeReadyReturnToSpacePrograms($probe);
        if (!$this->isPrimeSignalReply($body)) {
            if (!$stationActivated) {
                $this->createReturnToSpaceFriendshipReplyIfReady($probe, $planetId);
            }
            return null;
        }

        if ($stationActivated) {
            return null;
        }

        foreach ($this->missions->activeForPlayer($probe->playerId) as $mission) {
            if ($mission->type !== self::FIRST_CONTACT_MISSION_TYPE) {
                continue;
            }
            if (($mission->metadata['scenario'] ?? null) !== self::SCENARIO_RETURN_TO_SPACE_PROGRAM) {
                continue;
            }
            if (($mission->metadata['planetId'] ?? null) !== $planetId) {
                continue;
            }

            return $this->progressReturnToSpaceProgramAfterPrimeReply($probe, $mission);
        }

        return null;
    }

    public function abandonMission(NeumannProbe $probe, string $missionUid): Mission
    {
        $mission = $this->missions->findByUidForPlayer($probe->playerId, $missionUid)
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
        $mission = $this->missions->findByUidForPlayer($probe->playerId, $missionUid) ?? $mission;
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

    public function completeReadyReturnToSpacePrograms(NeumannProbe $probe): bool
    {
        if ($this->sectors === null) {
            return false;
        }

        $sector = $this->sectors->getOrCreateSector($probe->currentSector);
        $changed = false;
        foreach ($sector->returnToSpaceProgramMaterialCounters() as $planetId => $counter) {
            if (!$this->returnToSpaceStationCanActivate((string) $planetId, $counter)) {
                continue;
            }

            $station = $this->returnToSpaceDeuteriumStation((string) $planetId, $counter);
            if (!$sector->replaceObject($station)) {
                $sector->addObject($station);
            }
            $this->createReturnToSpaceStationReadyMessages($probe, $sector, (string) $planetId, $counter);
            $this->completeReturnToSpaceContributorMissions($probe, $sector, (string) $planetId, $counter);
            $sector->markReturnToSpaceProgramStationActivated((string) $planetId, $station->getId());
            $changed = true;
        }

        if ($changed) {
            $this->sectors->saveSector($sector);
        }

        return $changed;
    }

    private function progressReturnToSpaceProgramAfterPrimeReply(NeumannProbe $probe, Mission $mission): Mission
    {
        $replyStepUid = $this->firstContactReplyStepUid($mission);
        $replyStep = $this->missions->findStepByUid($mission->id, $replyStepUid);
        if ($replyStep !== null && $replyStep->status === MissionStep::STATUS_PENDING) {
            $mission = $this->completeStep($probe, $mission->uid, $replyStepUid);
        }

        $this->ensureReturnToSpaceResourceSteps($mission);
        $mission = $this->missions->findByUidForPlayer($probe->playerId, $mission->uid) ?? $mission;

        $waitStepUid = $this->firstContactWaitStepUid($mission);
        $waitStep = $this->missions->findStepByUid($mission->id, $waitStepUid);
        if ($waitStep !== null && $waitStep->status === MissionStep::STATUS_PENDING) {
            $this->createReturnToSpacePlanetReply($probe, $mission);
            $mission = $this->completeStep($probe, $mission->uid, $waitStepUid);
        }

        $this->initializeReturnToSpaceMaterialCounter($mission);

        return $this->missions->findByUidForPlayer($probe->playerId, $mission->uid) ?? $mission;
    }

    private function ensureReturnToSpaceResourceSteps(Mission $mission): void
    {
        $metalsUid = $this->firstContactStepUid($mission->uid, self::FIRST_CONTACT_DELIVER_METALS_STEP_UID);
        if ($this->missions->findStepByUid($mission->id, $metalsUid) === null) {
            $this->missions->createStep(
                $mission->id,
                'Fournir les métaux demandés',
                'Livrer 5 ECE de métaux à la civilisation afin de relancer sa capacité spatiale.',
                [
                    'resourceType' => 'metals',
                    'amount' => 5.0,
                    'unit' => 'earth_container_equivalent',
                ],
                3,
                $metalsUid,
            );
        }

        $carbonUid = $this->firstContactStepUid($mission->uid, self::FIRST_CONTACT_DELIVER_CARBON_STEP_UID);
        if ($this->missions->findStepByUid($mission->id, $carbonUid) === null) {
            $this->missions->createStep(
                $mission->id,
                'Fournir les composés carbonés demandés',
                'Livrer 1 ECE de composés carbonés à la civilisation afin de relancer sa capacité spatiale.',
                [
                    'resourceType' => ResourceComposition::CARBON_COMPOUNDS,
                    'amount' => 1.0,
                    'unit' => 'earth_container_equivalent',
                ],
                4,
                $carbonUid,
            );
        }

        $stationUid = $this->firstContactStepUid($mission->uid, self::FIRST_CONTACT_AWAIT_STATION_STEP_UID);
        if ($this->missions->findStepByUid($mission->id, $stationUid) === null) {
            $this->missions->createStep(
                $mission->id,
                'Attendre la station de recharge',
                'Revenir dans le secteur après le délai annoncé afin de confirmer la mise en orbite de la station de recharge en deutérium.',
                [
                    'delaySeconds' => self::RETURN_TO_SPACE_STATION_DELAY_SECONDS,
                    'rewardObjectType' => 'deuterium_refuel_station',
                ],
                5,
                $stationUid,
            );
        }
    }

    /**
     * @param array<string, mixed> $resources
     * @return array<string, mixed>|null
     */
    public function handleReturnToSpaceProgramMaterialDrop(
        NeumannProbe $probe,
        SectorContent $sector,
        string $planetId,
        int $playerId,
        string $containerObjectId,
        array $resources,
    ): ?array {
        $resources = $this->returnToSpaceRelevantMaterials($resources);
        if ($resources === []) {
            return null;
        }

        $mission = $this->activeReturnToSpaceMissionForPlanet($probe, $planetId);
        if ($mission === null || !$this->returnToSpaceResourceRequestStarted($mission)) {
            return null;
        }

        $counter = $sector->returnToSpaceProgramMaterialCounterForPlanet($planetId);
        if ($counter === null) {
            $counter = $sector->ensureReturnToSpaceProgramMaterialCounter(
                $planetId,
                is_string($mission->metadata['planetName'] ?? null) ? $mission->metadata['planetName'] : null,
                self::RETURN_TO_SPACE_MATERIAL_REQUIREMENTS,
            );
        }

        $missionPlanetName = $mission !== null && is_string($mission->metadata['planetName'] ?? null)
            ? $mission->metadata['planetName']
            : null;
        $planetName = is_string($counter['planetName'] ?? null) ? $counter['planetName'] : $missionPlanetName;
        $counter = $sector->recordReturnToSpaceProgramMaterialDonation(
            $planetId,
            $planetName,
            self::RETURN_TO_SPACE_MATERIAL_REQUIREMENTS,
            $playerId,
            $probe->id,
            $containerObjectId,
            $resources,
        );

        if ($mission !== null) {
            $this->ensureReturnToSpaceResourceSteps($mission);
            $mission = $this->missions->findByUidForPlayer($probe->playerId, $mission->uid) ?? $mission;
            $this->completeReturnToSpaceResourceStepsReachedByCounter($probe, $mission, $counter);
        }
        if ($this->returnToSpaceRequirementsReached($counter)) {
            $this->createReturnToSpaceCompletionMessages($probe, $sector, $planetId, $planetName, $counter);
        } else {
            $this->createReturnToSpaceMaterialDropThanks($probe, $planetId, $planetName, $sector->getCoordinates(), $counter);
        }

        return $counter;
    }

    private function createReturnToSpacePlanetReply(NeumannProbe $probe, Mission $mission): void
    {
        $planetId = (string) ($mission->metadata['planetId'] ?? '');
        if ($planetId === '') {
            return;
        }

        $sectorData = is_array($mission->metadata['sector'] ?? null) ? $mission->metadata['sector'] : null;
        if ($sectorData === null || !isset($sectorData['x'], $sectorData['y'], $sectorData['z'])) {
            return;
        }

        $this->messages?->createForEndpoints(
            ProbeMessage::ENDPOINT_PLANET,
            $planetId,
            is_string($mission->metadata['planetName'] ?? null) ? $mission->metadata['planetName'] : null,
            null,
            ProbeMessage::ENDPOINT_PROBE,
            (string) $probe->id,
            null,
            $probe->id,
            new SectorCoordinates((int) $sectorData['x'], (int) $sectorData['y'], (int) $sectorData['z']),
            self::RETURN_TO_SPACE_PLANET_REPLY,
        );
    }

    private function initializeReturnToSpaceMaterialCounter(Mission $mission): void
    {
        if ($this->sectors === null) {
            return;
        }
        $planetId = (string) ($mission->metadata['planetId'] ?? '');
        if ($planetId === '') {
            return;
        }
        $sectorData = is_array($mission->metadata['sector'] ?? null) ? $mission->metadata['sector'] : null;
        if ($sectorData === null || !isset($sectorData['x'], $sectorData['y'], $sectorData['z'])) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector(new SectorCoordinates((int) $sectorData['x'], (int) $sectorData['y'], (int) $sectorData['z']));
        $sector->ensureReturnToSpaceProgramMaterialCounter(
            $planetId,
            is_string($mission->metadata['planetName'] ?? null) ? $mission->metadata['planetName'] : null,
            self::RETURN_TO_SPACE_MATERIAL_REQUIREMENTS,
        );
        $this->sectors->saveSector($sector);
    }

    private function activeReturnToSpaceMissionForPlanet(NeumannProbe $probe, string $planetId): ?Mission
    {
        foreach ($this->missions->activeForPlayer($probe->playerId) as $mission) {
            if ($mission->type !== self::FIRST_CONTACT_MISSION_TYPE) {
                continue;
            }
            if (($mission->metadata['scenario'] ?? null) !== self::SCENARIO_RETURN_TO_SPACE_PROGRAM) {
                continue;
            }
            if (($mission->metadata['planetId'] ?? null) !== $planetId) {
                continue;
            }

            return $mission;
        }

        return null;
    }

    private function returnToSpaceResourceRequestStarted(Mission $mission): bool
    {
        $waitStep = $this->missions->findStepByUid($mission->id, $this->firstContactWaitStepUid($mission));
        if ($waitStep !== null && $waitStep->status === MissionStep::STATUS_COMPLETED) {
            return true;
        }

        return $this->missions->findStepByUid($mission->id, $this->firstContactStepUid($mission->uid, self::FIRST_CONTACT_DELIVER_METALS_STEP_UID)) !== null;
    }

    /**
     * @param array<string, mixed> $resources
     * @return array<string, float>
     */
    private function returnToSpaceRelevantMaterials(array $resources): array
    {
        $relevant = [];
        foreach (self::RETURN_TO_SPACE_MATERIAL_REQUIREMENTS as $type => $_required) {
            $amount = round(max(0.0, (float) ($resources[$type] ?? 0.0)), 4);
            if ($amount > 0.0) {
                $relevant[$type] = $amount;
            }
        }

        return $relevant;
    }

    /**
     * @param array<string, mixed> $counter
     */
    private function completeReturnToSpaceResourceStepsReachedByCounter(NeumannProbe $probe, Mission $mission, array $counter): void
    {
        $remaining = is_array($counter['remaining'] ?? null) ? $counter['remaining'] : [];
        $steps = [
            ResourceComposition::METALS => self::FIRST_CONTACT_DELIVER_METALS_STEP_UID,
            ResourceComposition::CARBON_COMPOUNDS => self::FIRST_CONTACT_DELIVER_CARBON_STEP_UID,
        ];

        foreach ($steps as $type => $stepKey) {
            if (round(max(0.0, (float) ($remaining[$type] ?? 0.0)), 4) > 0.0) {
                continue;
            }
            $mission = $this->missions->findByUidForPlayer($probe->playerId, $mission->uid) ?? $mission;
            if ($mission->isTerminal()) {
                return;
            }
            $stepUid = $this->firstContactStepUid($mission->uid, $stepKey);
            $step = $this->missions->findStepByUid($mission->id, $stepUid);
            if ($step === null || $step->status !== MissionStep::STATUS_PENDING) {
                continue;
            }
            if ($mission->stepOrder === Mission::STEP_ORDER_SEQUENTIAL && !$this->previousStepsCompleted($mission, $step)) {
                continue;
            }
            $mission = $this->completeStep($probe, $mission->uid, $stepUid);
        }
    }

    /**
     * @param array<string, mixed> $counter
     */
    private function createReturnToSpaceMaterialDropThanks(
        NeumannProbe $probe,
        string $planetId,
        ?string $planetName,
        SectorCoordinates $sector,
        array $counter,
    ): void {
        $remaining = is_array($counter['remaining'] ?? null) ? $counter['remaining'] : [];
        $this->messages?->createForEndpoints(
            ProbeMessage::ENDPOINT_PLANET,
            $planetId,
            $planetName,
            null,
            ProbeMessage::ENDPOINT_PROBE,
            (string) $probe->id,
            null,
            $probe->id,
            $sector,
            "We acknowledge receipt of your drop. Thank you for your help.\n\nResources still required:\nMetals: "
                . $this->formatEceAmount((float) ($remaining[ResourceComposition::METALS] ?? 0.0))
                . " ECE\nCarbon compounds: "
                . $this->formatEceAmount((float) ($remaining[ResourceComposition::CARBON_COMPOUNDS] ?? 0.0))
                . ' ECE',
        );
    }

    /**
     * @param array<string, mixed> $counter
     */
    private function returnToSpaceRequirementsReached(array $counter): bool
    {
        $remaining = is_array($counter['remaining'] ?? null) ? $counter['remaining'] : [];
        foreach (self::RETURN_TO_SPACE_MATERIAL_REQUIREMENTS as $type => $_required) {
            if (round(max(0.0, (float) ($remaining[$type] ?? 0.0)), 4) > 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $counter
     */
    private function createReturnToSpaceCompletionMessages(
        NeumannProbe $probe,
        SectorContent $sector,
        string $planetId,
        ?string $planetName,
        array $counter,
    ): void {
        if ($this->messages === null || ($counter['completionMessageSentAt'] ?? null) !== null) {
            return;
        }

        $contributorPlayerIds = $this->returnToSpaceContributorPlayerIds($counter);
        if (!in_array($probe->playerId, $contributorPlayerIds, true)) {
            $contributorPlayerIds[] = $probe->playerId;
        }
        $namesByPlayerId = $this->returnToSpaceContributorNames($contributorPlayerIds);
        $presentRecipientProbes = $this->presentReturnToSpaceContributorProbes($probe, $sector->getCoordinates(), $contributorPlayerIds);

        foreach ($presentRecipientProbes as $recipientProbe) {
            $this->messages->createForEndpoints(
                ProbeMessage::ENDPOINT_PLANET,
                $planetId,
                $planetName,
                null,
                ProbeMessage::ENDPOINT_PROBE,
                (string) $recipientProbe->id,
                null,
                $recipientProbe->id,
                $sector->getCoordinates(),
                $this->returnToSpaceCompletionMessage($recipientProbe->playerId, $namesByPlayerId),
            );
        }

        $sector->markReturnToSpaceProgramCompletionMessageSent(
            $planetId,
            gmdate('c', time() + self::RETURN_TO_SPACE_STATION_DELAY_SECONDS),
        );
    }

    /**
     * @param array<string, mixed> $counter
     * @return array<int>
     */
    private function returnToSpaceContributorPlayerIds(array $counter): array
    {
        $ids = [];
        $donations = is_array($counter['donations'] ?? null) ? $counter['donations'] : [];
        foreach ($donations as $donation) {
            if (!is_array($donation) || !isset($donation['playerId'])) {
                continue;
            }
            $ids[] = (int) $donation['playerId'];
        }

        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }

    /**
     * @param array<int> $playerIds
     * @return array<int, string>
     */
    private function returnToSpaceContributorNames(array $playerIds): array
    {
        $names = [];
        foreach ($playerIds as $playerId) {
            $player = $this->players?->findById($playerId);
            $name = $player !== null
                ? trim((string) ($player->displayName ?? $player->username))
                : '';
            $names[$playerId] = $name !== '' ? $name : 'visitor #' . $playerId;
        }

        return $names;
    }

    /**
     * @param array<int> $contributorPlayerIds
     * @return array<NeumannProbe>
     */
    private function presentReturnToSpaceContributorProbes(
        NeumannProbe $triggerProbe,
        SectorCoordinates $sector,
        array $contributorPlayerIds,
    ): array {
        $present = [$triggerProbe->id => $triggerProbe];
        if ($this->probes !== null) {
            foreach ($this->probes->findBySector($sector) as $probe) {
                if (!in_array($probe->playerId, $contributorPlayerIds, true)) {
                    continue;
                }
                $present[$probe->id] = $probe;
            }
        }

        ksort($present);

        return array_values($present);
    }

    /**
     * @param array<int, string> $namesByPlayerId
     */
    private function returnToSpaceCompletionMessage(int $recipientPlayerId, array $namesByPlayerId): string
    {
        $otherNames = [];
        foreach ($namesByPlayerId as $playerId => $name) {
            if ($playerId === $recipientPlayerId) {
                continue;
            }
            $otherNames[] = $name;
        }

        $intro = $otherNames === []
            ? 'Votre aide a dépassé nos espérances.'
            : 'Votre aide, ainsi que celle d\'autres visiteurs (' . implode(', ', $otherNames) . '), a dépassé nos espérances.';

        return sprintf(self::RETURN_TO_SPACE_COMPLETION_MESSAGE_TEMPLATE, $intro);
    }

    /**
     * @param array<string, mixed> $counter
     */
    private function returnToSpaceStationCanActivate(string $planetId, array $counter): bool
    {
        if ($planetId === '' || !$this->returnToSpaceRequirementsReached($counter)) {
            return false;
        }
        if (($counter['completionMessageSentAt'] ?? null) === null || ($counter['finalMessageSentAt'] ?? null) !== null) {
            return false;
        }

        $availableAt = (string) ($counter['stationAvailableAt'] ?? '');
        if ($availableAt === '') {
            return false;
        }

        try {
            $available = new \DateTimeImmutable($availableAt);
        } catch (\Throwable) {
            return false;
        }

        return $available <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $counter
     */
    private function returnToSpaceDeuteriumStation(string $planetId, array $counter): DeuteriumRefuelStation
    {
        $planetName = is_string($counter['planetName'] ?? null) ? $counter['planetName'] : null;

        return new DeuteriumRefuelStation(
            DeuteriumRefuelStation::objectIdForPlanet($planetId),
            'Deuterium refuel station',
            $planetId,
            $planetName,
            gmdate('c'),
            [
                'missionType' => self::FIRST_CONTACT_MISSION_TYPE,
                'scenario' => self::SCENARIO_RETURN_TO_SPACE_PROGRAM,
                'planetId' => $planetId,
                'planetName' => $planetName,
            ],
            'Orbital deuterium refuel station placed by a civilization restored to spaceflight.',
        );
    }

    /**
     * @param array<string, mixed> $counter
     */
    private function createReturnToSpaceStationReadyMessages(
        NeumannProbe $probe,
        SectorContent $sector,
        string $planetId,
        array $counter,
    ): void {
        if ($this->messages === null) {
            return;
        }

        $contributorPlayerIds = $this->returnToSpaceContributorPlayerIds($counter);
        if (!in_array($probe->playerId, $contributorPlayerIds, true)) {
            $contributorPlayerIds[] = $probe->playerId;
        }
        $namesByPlayerId = $this->returnToSpaceContributorNames($contributorPlayerIds);
        $presentRecipientProbes = $this->presentReturnToSpaceContributorProbes($probe, $sector->getCoordinates(), $contributorPlayerIds);
        $planetName = is_string($counter['planetName'] ?? null) ? $counter['planetName'] : null;

        foreach ($presentRecipientProbes as $recipientProbe) {
            $this->messages->createForEndpoints(
                ProbeMessage::ENDPOINT_PLANET,
                $planetId,
                $planetName,
                null,
                ProbeMessage::ENDPOINT_PROBE,
                (string) $recipientProbe->id,
                null,
                $recipientProbe->id,
                $sector->getCoordinates(),
                $this->returnToSpaceStationReadyMessage($recipientProbe->playerId, $namesByPlayerId),
            );
        }
    }

    /**
     * @param array<string, mixed> $counter
     */
    private function completeReturnToSpaceContributorMissions(
        NeumannProbe $triggerProbe,
        SectorContent $sector,
        string $planetId,
        array $counter,
    ): void {
        $contributorPlayerIds = $this->returnToSpaceContributorPlayerIds($counter);
        if (!in_array($triggerProbe->playerId, $contributorPlayerIds, true)) {
            $contributorPlayerIds[] = $triggerProbe->playerId;
        }

        foreach ($contributorPlayerIds as $playerId) {
            $probe = $playerId === $triggerProbe->playerId
                ? $triggerProbe
                : $this->probes?->findByPlayerId($playerId);
            if (!$probe instanceof NeumannProbe) {
                continue;
            }

            $missionUid = $this->returnToSpaceMissionUidForPlanet($probe, $sector->getCoordinates(), $planetId);
            $mission = $this->activeReturnToSpaceMissionForPlanet($probe, $planetId)
                ?? $this->missions->findByUidForPlayer($probe->playerId, $missionUid);
            if ($mission === null) {
                $mission = $this->startMission(
                    $probe,
                    self::FIRST_CONTACT_MISSION_TYPE,
                    'Programme de retour à l\'espace',
                    'Cette sonde a contribué au retour orbital d\'une civilisation rencontrée dans un secteur exploré.',
                    Mission::STEP_ORDER_FREE,
                    [
                        'scenario' => self::SCENARIO_RETURN_TO_SPACE_PROGRAM,
                        'planetId' => $planetId,
                        'planetName' => is_string($counter['planetName'] ?? null) ? $counter['planetName'] : null,
                        'sector' => $sector->getCoordinates()->toArray(),
                        'completedByContribution' => true,
                    ],
                    [
                        'type' => 'return_to_space_program_completed',
                        'planetId' => $planetId,
                        'sector' => $sector->getCoordinates()->toArray(),
                    ],
                    [],
                    $missionUid,
                );
            }

            $this->completeReturnToSpaceMission($mission);
        }
    }

    private function completeReturnToSpaceMission(Mission $mission): void
    {
        if ($mission->isTerminal()) {
            return;
        }

        $stationStepUid = $this->firstContactStepUid($mission->uid, self::FIRST_CONTACT_AWAIT_STATION_STEP_UID);
        $stationStep = $this->missions->findStepByUid($mission->id, $stationStepUid);
        if ($stationStep !== null && $stationStep->status === MissionStep::STATUS_PENDING) {
            $this->missions->markStepCompleted($stationStep);
            $mission = $this->missions->findByUidForPlayer($mission->playerId, $mission->uid) ?? $mission;
        }

        $this->missions->markCompleted($mission);
    }

    /**
     * @param array<int, string> $namesByPlayerId
     */
    private function returnToSpaceStationReadyMessage(int $recipientPlayerId, array $namesByPlayerId): string
    {
        $otherNames = [];
        foreach ($namesByPlayerId as $playerId => $name) {
            if ($playerId === $recipientPlayerId) {
                continue;
            }
            $otherNames[] = $name;
        }

        $intro = $otherNames === []
            ? 'Your help carried us through the last impossible steps.'
            : 'Your help, and the help of other visitors (' . implode(', ', $otherNames) . '), carried us through the last impossible steps.';

        return sprintf(self::RETURN_TO_SPACE_STATION_READY_MESSAGE_TEMPLATE, $intro);
    }

    private function createReturnToSpaceFriendshipReplyIfReady(NeumannProbe $probe, string $planetId): void
    {
        if ($this->messages === null || $this->sectors === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($probe->currentSector);
        $counter = $sector->returnToSpaceProgramMaterialCounterForPlanet($planetId);
        if (!is_array($counter) || ($counter['finalMessageSentAt'] ?? null) === null) {
            return;
        }

        $this->messages->createForEndpoints(
            ProbeMessage::ENDPOINT_PLANET,
            $planetId,
            is_string($counter['planetName'] ?? null) ? $counter['planetName'] : null,
            null,
            ProbeMessage::ENDPOINT_PROBE,
            (string) $probe->id,
            null,
            $probe->id,
            $sector->getCoordinates(),
            self::RETURN_TO_SPACE_FRIENDSHIP_REPLY,
        );
    }

    private function formatEceAmount(float $amount): string
    {
        $amount = round(max(0.0, $amount), 4);
        $formatted = rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function activeMissionForProbe(NeumannProbe $probe, string $missionUid): Mission
    {
        $mission = $this->missions->findByUidForPlayer($probe->playerId, $missionUid)
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
        $existing = $this->activeReturnToSpaceMissionForPlanet($probe, $planet->getId())
            ?? $this->missions->findByUidForPlayer($probe->playerId, $uid);
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
                    'uid' => $this->firstContactStepUid($uid, self::FIRST_CONTACT_REPLY_STEP_UID),
                    'title' => 'Repondre a la suite du motif',
                    'description' => 'Transmettre a la planete la suite du motif recu, complete ou reduite au terme suivant.',
                    'metadata' => [
                        'acceptedReplies' => [self::FIRST_CONTACT_FULL_REPLY, self::FIRST_CONTACT_SHORT_REPLY],
                    ],
                ],
                [
                    'uid' => $this->firstContactStepUid($uid, self::FIRST_CONTACT_WAIT_STEP_UID),
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
        return $this->returnToSpaceMissionUidForPlanet($probe, $sector, $planet->getId());
    }

    private function returnToSpaceMissionUidForPlanet(NeumannProbe $probe, SectorCoordinates $sector, string $planetId): string
    {
        return 'mis_first_contact_' . substr(hash('sha256', implode('|', [
            $probe->playerId,
            $sector->toKey(),
            $planetId,
            self::SCENARIO_RETURN_TO_SPACE_PROGRAM,
        ])), 0, 20);
    }

    private function firstContactStepUid(string $missionUid, string $stepUid): string
    {
        return $missionUid . '_' . $stepUid;
    }

    private function firstContactReplyStepUid(Mission $mission): string
    {
        $uid = $this->firstContactStepUid($mission->uid, self::FIRST_CONTACT_REPLY_STEP_UID);
        if ($this->missions->findStepByUid($mission->id, $uid) !== null) {
            return $uid;
        }

        return self::FIRST_CONTACT_REPLY_STEP_UID;
    }

    private function firstContactWaitStepUid(Mission $mission): string
    {
        $uid = $this->firstContactStepUid($mission->uid, self::FIRST_CONTACT_WAIT_STEP_UID);
        if ($this->missions->findStepByUid($mission->id, $uid) !== null) {
            return $uid;
        }

        return self::FIRST_CONTACT_WAIT_STEP_UID;
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
