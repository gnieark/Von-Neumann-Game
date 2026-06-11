<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\I18n\Translator;
use VonNeumannGame\View\TplBlock;

class FrontRouteInventories extends FrontRoute{
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
        $tpl = new TplBlock();
        $tpl->addPrefixedVars('t', $translator->allEscaped());

        return $tpl->applyTplFile($projectRoot . '/templates/inventories.html');
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return 'Von Neumann Game - ' . $translator->get('tabSystems');
    }

    public function getCustomJs(): string
    {
        return '<script src="/assets/inventories.js?v=' . (defined('ASSET_VERSION') ? ASSET_VERSION : '') .'" defer></script>';
    }

    public function getMetaDescription(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return self::e($translator->get('homeMetaDescription'));
    }
}
