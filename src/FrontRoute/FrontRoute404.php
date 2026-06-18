<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\I18n\Translator;
use VonNeumannGame\View\TplBlock;

class FrontRoute404 extends FrontRoute{
    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
        http_response_code(404);

        $projectRoot = dirname(__DIR__, 2);
        $translator = new Translator(Translator::normalize($language));
        $tpl = new TplBlock();
        $tpl->addPrefixedVars('t', $translator->allEscaped());
        $tpl->addVars([
            'requestedPath' => self::e($routePath),
        ]);

        return $tpl->applyTplFile($projectRoot . '/templates/404.html');
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return 'Von Neumann Game - ' . $translator->get('notFoundTitle');
    }

    public function getMetaDescription(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return self::e($translator->get('notFoundMetaDescription'));
    }
}
