<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\I18n\Translator;
use VonNeumannGame\View\TplBlock;


class FrontRoute{
    /*
    * Classe mère, sert à centraliser des méthodes pour les classes filles
    */

   

    public function __construct()
    {
        
    }
    public function getCustomJs(): string
    {
        return "";
    }
    public function getCustomCss(): string
    {
        return "";
    }
    public function handle(string $method, string $routePath, ?string $bearer, string $language): void
    {

        if(isset($this->displayOnMainPage) && $this->displayOnMainPage === false){
            // Si la route n'est pas censée être affichée sur la page principale, on l'affiche seule
            echo $this->getContent($method, $routePath);
        }
        else{
            echo $this->renderMainPage(
                $this->getContent($method, $routePath, $bearer, $language),
                $bearer,
                $language
            );
        
        }
        
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        return "";
    }

    public function getMetaDescription(?string $bearer, string $language): string
    {
        return "";
    }

    protected function addLanguageOptions(TplBlock $tpl, Translator $translator): void
    {
        foreach (Translator::supportedLanguages() as $availableLanguage) {
            $labelKey = match ($availableLanguage) {
                'fr' => 'languageFrench',
                'en' => 'languageEnglish',
                default => 'languageLabel',
            };

            $tpl->addSubBlock((new TplBlock('languageoptions'))->addVars([
                'value' => self::e($availableLanguage),
                'selected' => $translator->language() === $availableLanguage ? 'selected' : '',
                'name' => self::e($translator->get($labelKey)),
            ]));
        }
    }

    protected static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function renderMainPage(string $content, ?string $bearer, string $language): string
    {
        $projectRoot = dirname(__DIR__, 2);
        $translator = new Translator(Translator::normalize($language));

        $tpl = new TplBlock("");
        $tpl->addVars([
            "pageTitle" => $this->getPageTitle($bearer, $language),
            "metaDescription" => $this->getMetaDescription($bearer, $language),
            "language" => $translator->language(),
            "assetVersion" => defined('ASSET_VERSION') ? ASSET_VERSION : '',
            "authenticated" => is_null($bearer) ? '0' : '1',
            "i18nUrl" => "/i18n?lang=" . rawurlencode($translator->language()),
            "customJs" => $this->getCustomJs(),
            "customCss" => $this->getCustomCss(),
            "mainContent" => "",
        ]);
        $tpl->addPrefixedVars('t', $translator->allEscaped());
        $this->addLanguageOptions($tpl, $translator);

        if(is_null($bearer)){
            //user deconnecté
            $tpl->addSubBlock((new TplBlock('anonymousUserContent'))->addVars([
                'mainContent' => $content,
            ]));
        }else{
            //user connecté, on affiche la console, le content ira dedans
            $tpl->addSubBlock((new TplBlock('authenticatedUserContent'))->addVars([
                'mainContent' => $content,
            ]));
        }

        return $tpl->applyTplFile($projectRoot . '/templates/main.html');
    }

}
