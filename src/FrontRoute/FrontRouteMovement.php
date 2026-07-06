<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Config\JsonConfigLoader;
use VonNeumannGame\I18n\Translator;
use VonNeumannGame\View\TplBlock;

class FrontRouteMovement extends FrontRoute{
    public function handle(string $method, string $routePath, ?string $bearer, string $language): void
    {
        if ($bearer === null) {
            http_response_code(401);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Unauthorized';
            return;
        }

        parent::handle($method, $routePath, $bearer, $language);
    }

    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
        $projectRoot = dirname(__DIR__, 2);
        $translator = new Translator(Translator::normalize($language));
        $coordinates = $this->coordinatesFromRoutePath($routePath);
        $movementConfig = Config::getArray((new JsonConfigLoader($projectRoot))->load('gameplay'), 'movement');
        $destructionWarningDistance = Config::int($movementConfig, 'destructionSafeDistance', 2) + 1;
        $destructionRisks = Config::getArray($movementConfig, 'destructionRiskByDistance', ['3' => 0.05]);
        $destructionRisk = is_numeric($destructionRisks[(string) $destructionWarningDistance] ?? null)
            ? (float) $destructionRisks[(string) $destructionWarningDistance]
            : (float) ($destructionRisks['default'] ?? 0.05);
        $tpl = new TplBlock();
        $tpl->addPrefixedVars('t', $translator->allEscaped());
        $tpl->addVars([
            'initialX' => self::e((string) ($coordinates['x'] ?? 0)),
            'initialY' => self::e((string) ($coordinates['y'] ?? 0)),
            'initialZ' => self::e((string) ($coordinates['z'] ?? 0)),
            'destructionWarningDistance' => self::e((string) $destructionWarningDistance),
            'destructionWarningRiskPercent' => self::e((string) round(max(0.0, min(1.0, $destructionRisk)) * 100, 2)),
        ]);

        return $tpl->applyTplFile($projectRoot . '/templates/movement.html');
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return 'Von Neumann Game - ' . $translator->get('tabActions');
    }

    public function getCustomJs(): string
    {
        return '<script src="/assets/movement.js?v=' . (defined('ASSET_VERSION') ? ASSET_VERSION : '') .'" defer></script>';
    }

    public function getMetaDescription(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return self::e($translator->get('homeMetaDescription'));
    }

    /**
     * @return array{x:int,y:int,z:int}|null
     */
    private function coordinatesFromRoutePath(string $routePath): ?array
    {
        if (preg_match('#^/movement/\d+/(-?\d+)/(-?\d+)/(-?\d+)$#', $routePath, $matches) === 1) {
            return [
                'x' => (int) $matches[1],
                'y' => (int) $matches[2],
                'z' => (int) $matches[3],
            ];
        }

        if (preg_match('#^/movement/(-?\d+)/(-?\d+)/(-?\d+)$#', $routePath, $matches) !== 1) {
            return null;
        }

        return [
            'x' => (int) $matches[1],
            'y' => (int) $matches[2],
            'z' => (int) $matches[3],
        ];
    }
}
