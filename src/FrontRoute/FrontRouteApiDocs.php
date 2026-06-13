<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\I18n\Translator;
use VonNeumannGame\View\TplBlock;

class FrontRouteApiDocs extends FrontRoute{
    public function handle(string $method, string $routePath, ?string $bearer, string $language): void
    {
        if ($routePath === '/openapi.yaml') {
            $this->renderOpenApiSpec($method);
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

        return $tpl->applyTplFile($projectRoot . '/templates/api-docs.html');
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        return 'Von Neumann Game - API';
    }

    public function getCustomCss(): string
    {
        return '<link rel="stylesheet" href="/swagger/swagger-ui.css">';
    }

    public function getCustomJs(): string
    {
        $version = defined('ASSET_VERSION') ? ASSET_VERSION : '';

        return '<script src="/swagger/swagger-ui-bundle.js" defer></script>'
            . '<script src="/assets/api-docs.js?v=' . $version . '" defer></script>';
    }

    public function getMetaDescription(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return self::e($translator->get('apiDocsMetaDescription'));
    }

    private function renderOpenApiSpec(string $method): void
    {
        $path = dirname(__DIR__, 2) . '/docs/openapi.yaml';
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'OpenAPI specification not found';
            return;
        }

        header('Content-Type: application/yaml; charset=utf-8');
        header('Cache-Control: no-store');
        if ($method !== 'HEAD') {
            readfile($path);
        }
    }
}
