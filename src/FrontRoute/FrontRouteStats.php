<?php
namespace VonNeumannGame\FrontRoute;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use VonNeumannGame\I18n\Translator;
use VonNeumannGame\View\TplBlock;

class FrontRouteStats extends FrontRoute{
    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
        $projectRoot = dirname(__DIR__, 2);
        $translator = new Translator(Translator::normalize($language));
        $stats = $this->loadStats($projectRoot . '/var/stats.json');
        $tpl = new TplBlock();
        $tpl->addPrefixedVars('t', $translator->allEscaped());
        $tpl->addVars([
            'generatedAt' => self::e($this->formatGeneratedAt($stats['generatedAt'], $translator)),
        ]);
        $podium = $this->topVisitedProbeRows($stats['metrics'], $translator);
        if ($podium === []) {
            $tpl->addSubBlock(new TplBlock('emptyPodium'));
        } else {
            foreach ($podium as $probe) {
                $tpl->addSubBlock((new TplBlock('podiumProbe'))->addVars([
                    'rank' => self::e($probe['rank']),
                    'name' => self::e($probe['name']),
                    'visitedSectors' => self::e($probe['visitedSectors']),
                    'visitedSectorsLabel' => self::e($probe['visitedSectorsLabel']),
                ]));
            }
        }
        $waypointPodium = $this->topWaypointPlayerRows($stats['metrics'], $translator);
        if ($waypointPodium === []) {
            $tpl->addSubBlock(new TplBlock('emptyWaypointPodium'));
        } else {
            foreach ($waypointPodium as $player) {
                $tpl->addSubBlock((new TplBlock('waypointPlayer'))->addVars([
                    'rank' => self::e($player['rank']),
                    'name' => self::e($player['name']),
                    'waypointBookmarks' => self::e($player['waypointBookmarks']),
                    'waypointBookmarksLabel' => self::e($player['waypointBookmarksLabel']),
                ]));
            }
        }
        foreach ($this->metricRows($stats['metrics'], $translator) as $metric) {
            $tpl->addSubBlock((new TplBlock('metric'))->addVars([
                'label' => self::e($metric['label']),
                'value' => self::e($metric['value']),
            ]));
        }

        return $tpl->applyTplFile($projectRoot . '/templates/stats.html');
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return 'Von Neumann Game - ' . $translator->get('statsFooterLink');
    }

    public function getMetaDescription(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return self::e($translator->get('statsMetaDescription'));
    }

    /**
     * @return array{generatedAt: ?string, metrics: array<string, mixed>}
     */
    private function loadStats(string $path): array
    {
        $fallbackMetrics = [
            'probesInUniverse' => 0,
            'generatedSectors' => 0,
            'visitedSectors' => 0,
            'blackHoles' => 0,
            'asteroidsByResource' => [
                'deuterium' => 0,
                'metals' => 0,
                'ice' => 0,
                'carbon_compounds' => 0,
            ],
            'lostMannies' => 0,
            'forgottenMannies' => 0,
            'driftingContainers' => 0,
            'hiddenContainers' => 0,
            'furthestProbeDistance' => 0,
            'closestProbeDistance' => 0,
            'waypointBookmarksInstalled' => 0,
            'intelligentLifeWorlds' => 0,
            'successfulMissions' => 0,
            'failedMissions' => 0,
            'topVisitedProbes' => [],
            'topWaypointPlayers' => [],
        ];

        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            return ['generatedAt' => null, 'metrics' => $fallbackMetrics];
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['generatedAt' => null, 'metrics' => $fallbackMetrics];
        }

        if (!is_array($data) || !is_array($data['metrics'] ?? null)) {
            return ['generatedAt' => null, 'metrics' => $fallbackMetrics];
        }

        return [
            'generatedAt' => is_string($data['generatedAt'] ?? null) ? $data['generatedAt'] : null,
            'metrics' => array_replace_recursive($fallbackMetrics, $data['metrics']),
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<int, array{rank: string, name: string, visitedSectors: string, visitedSectorsLabel: string}>
     */
    private function topVisitedProbeRows(array $metrics, Translator $translator): array
    {
        $rows = is_array($metrics['topVisitedProbes'] ?? null) ? $metrics['topVisitedProbes'] : [];
        $podium = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $visitedSectors = max(0, (int) ($row['visitedSectors'] ?? 0));
            $podium[] = [
                'rank' => '#' . ((int) ($row['rank'] ?? 0) > 0 ? (int) $row['rank'] : $index + 1),
                'name' => trim((string) ($row['probeName'] ?? '')) !== '' ? (string) $row['probeName'] : $translator->get('unknownProbe'),
                'visitedSectors' => $this->formatNumber($visitedSectors),
                'visitedSectorsLabel' => $translator->get($visitedSectors > 1 ? 'statsVisitedSectorsPodiumPlural' : 'statsVisitedSectorsPodiumSingular'),
            ];
        }

        return $podium;
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<int, array{rank: string, name: string, waypointBookmarks: string, waypointBookmarksLabel: string}>
     */
    private function topWaypointPlayerRows(array $metrics, Translator $translator): array
    {
        $rows = is_array($metrics['topWaypointPlayers'] ?? null) ? $metrics['topWaypointPlayers'] : [];
        $podium = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $waypointBookmarks = max(0, (int) ($row['waypointBookmarks'] ?? 0));
            $podium[] = [
                'rank' => '#' . ((int) ($row['rank'] ?? 0) > 0 ? (int) $row['rank'] : $index + 1),
                'name' => trim((string) ($row['playerName'] ?? '')) !== '' ? (string) $row['playerName'] : $translator->get('unknownPlayer'),
                'waypointBookmarks' => $this->formatNumber($waypointBookmarks),
                'waypointBookmarksLabel' => $translator->get($waypointBookmarks > 1 ? 'statsWaypointBookmarksPlural' : 'statsWaypointBookmarksSingular'),
            ];
        }

        return $podium;
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<int, array{label: string, value: string}>
     */
    private function metricRows(array $metrics, Translator $translator): array
    {
        $asteroidsByResource = is_array($metrics['asteroidsByResource'] ?? null) ? $metrics['asteroidsByResource'] : [];

        return [
            ['label' => $translator->get('statsProbesInUniverse'), 'value' => $this->formatNumber($metrics['probesInUniverse'] ?? 0)],
            ['label' => $translator->get('statsGeneratedSectors'), 'value' => $this->formatNumber($metrics['generatedSectors'] ?? 0)],
            ['label' => $translator->get('statsVisitedSectors'), 'value' => $this->formatNumber($metrics['visitedSectors'] ?? 0)],
            ['label' => $translator->get('statsBlackHoles'), 'value' => $this->formatNumber($metrics['blackHoles'] ?? 0)],
            ['label' => $translator->get('statsAsteroidsDeuterium'), 'value' => $this->formatNumber($asteroidsByResource['deuterium'] ?? 0)],
            ['label' => $translator->get('statsAsteroidsMetals'), 'value' => $this->formatNumber($asteroidsByResource['metals'] ?? 0)],
            ['label' => $translator->get('statsAsteroidsIce'), 'value' => $this->formatNumber($asteroidsByResource['ice'] ?? 0)],
            ['label' => $translator->get('statsAsteroidsCarbonCompounds'), 'value' => $this->formatNumber($asteroidsByResource['carbon_compounds'] ?? 0)],
            ['label' => $translator->get('statsLostMannies'), 'value' => $this->formatNumber($metrics['lostMannies'] ?? 0)],
            ['label' => $translator->get('statsForgottenMannies'), 'value' => $this->formatNumber($metrics['forgottenMannies'] ?? 0)],
            ['label' => $translator->get('statsDriftingContainers'), 'value' => $this->formatNumber($metrics['driftingContainers'] ?? 0)],
            ['label' => $translator->get('statsHiddenContainers'), 'value' => $this->formatNumber($metrics['hiddenContainers'] ?? 0)],
            ['label' => $translator->get('statsFurthestProbeDistance'), 'value' => $this->formatNumber($metrics['furthestProbeDistance'] ?? 0)],
            ['label' => $translator->get('statsClosestProbeDistance'), 'value' => $this->formatNumber($metrics['closestProbeDistance'] ?? 0)],
            ['label' => $translator->get('statsWaypointBookmarksInstalled'), 'value' => $this->formatNumber($metrics['waypointBookmarksInstalled'] ?? 0)],
            ['label' => $translator->get('statsIntelligentLifeWorlds'), 'value' => $this->formatNumber($metrics['intelligentLifeWorlds'] ?? 0)],
            ['label' => $translator->get('statsSuccessfulMissions'), 'value' => $this->formatNumber($metrics['successfulMissions'] ?? 0)],
            ['label' => $translator->get('statsFailedMissions'), 'value' => $this->formatNumber($metrics['failedMissions'] ?? 0)],
        ];
    }

    private function formatNumber(mixed $value): string
    {
        return number_format(max(0, (int) $value), 0, ',', ' ');
    }

    private function formatGeneratedAt(?string $value, Translator $translator): string
    {
        if ($value === null || trim($value) === '') {
            return $translator->get('statsNeverGenerated');
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            return $translator->get('statsNeverGenerated');
        }

        return $date->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i T');
    }
}
