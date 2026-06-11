<?php
namespace VonNeumannGame\FrontRoute;

use Throwable;
use VonNeumannGame\Auth\OAuthConfig;
use VonNeumannGame\Auth\OAuthService;
use VonNeumannGame\I18n\Translator;
use VonNeumannGame\View\TplBlock;

class FrontRouteHome extends FrontRoute{

    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
        if ($bearer === null) {
            return $this->handleHome($language);
        }
        return $this->handleProbe($routePath,$language);
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        if ($bearer === null) {
            return "Von Neumann Game";
        }
        return 'Von Neumann Game - Probe';
    }

    public function getCustomJs(): string
    {
        return '<script src="/assets/probe.js?v=' . (defined('ASSET_VERSION') ? ASSET_VERSION : '') .'" defer></script>';
    }


    public function getMetaDescription(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return self::e($translator->get('homeMetaDescription'));
    }

    private function handleProbe(string $routePath, string $language): string
    {
        $projectRoot = dirname(__DIR__, 2);
        $translator = new Translator(Translator::normalize($language));
        $tpl = new TplBlock();
        $tpl->addPrefixedVars('t', $translator->allEscaped());

        return $tpl->applyTplFile($projectRoot . '/templates/Probe.html');
        
    }
    private function handleHome(string $language): string
    {
        $projectRoot = dirname(__DIR__, 2);
        $translator = new Translator(Translator::normalize($language));
        $tplLoginview = new TplBlock("loginview");
        $tplLoginview->dontReplaceNonGivenVars();

        $oauthProviderLinks = $this->oauthProviderLinks($projectRoot, $translator);
        if ($oauthProviderLinks !== []) {
            $oauthSection = new TplBlock('oauthsection');
            foreach ($oauthProviderLinks as $provider) {
                $oauthSection->addSubBlock((new TplBlock('oauthprovider'))->addVars([
                    'class' => self::e($provider['class']),
                    'label' => self::e($provider['label']),
                    'url' => self::e($provider['url']),
                ]));
            }
            $tplLoginview->addSubBlock($oauthSection);
        } else {
            $tplLoginview->addSubBlock(new TplBlock('oauthmissing'));
        }

        $template = file_get_contents($projectRoot . '/templates/loginview.html');
        if ($template === false) {
            throw new \UnexpectedValueException('Cannot read login view template');
        }

        $tpl = new TplBlock();
        $tpl->addPrefixedVars('t', $translator->allEscaped());

        return $tpl->applyTplStr($tplLoginview->applyTplStr($template, 'loginview'));
    }

    private function oauthProviderLinks(string $projectRoot, Translator $translator): array
    {
        try {
            $oauth = new OAuthService(
                OAuthConfig::fromFile($projectRoot . '/config/oauth.json')
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn(string $provider): array => [
            'class' => $provider,
            'label' => match ($provider) {
                'google' => $translator->get('oauthLoginGoogle'),
                'discord' => $translator->get('oauthLoginDiscord'),
                'github' => $translator->get('oauthLoginGitHub'),
                default => $provider,
            },
            'url' => '/auth/provider/' . rawurlencode($provider),
        ], $oauth->availableProviders());
    }

}
