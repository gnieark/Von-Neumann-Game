<?php

declare(strict_types=1);

namespace VonNeumannGame\I18n;

final class Translator
{
    public const DEFAULT_LANGUAGE = 'fr';

    private const SUPPORTED_LANGUAGES = ['fr', 'en'];

    private const MESSAGES = [
        'fr' => [
            'htmlLang' => 'fr',
            'languageLabel' => 'Langue',
            'languageFrench' => 'Français',
            'languageEnglish' => 'English',
            'logout' => 'Déconnexion',
            'loginEyebrow' => 'Terminal d\'accès',
            'loginTitle' => 'Réveiller la sonde',
            'loginInvalid' => 'Identifiants invalides.',
            'username' => 'Identifiant',
            'password' => 'Mot de passe',
            'rememberMe' => 'Se souvenir de moi',
            'authenticate' => 'Authentifier',
            'briefEyebrow' => 'Prototype de navigation interstellaire',
            'briefTitle' => 'Une intelligence embarquée, une coque fatiguée, un univers à cartographier.',
            'briefText' => 'Vous pilotez une sonde Von Neumann dans une grille de secteurs procéduraux. Chaque saut consomme du deutérium, perturbe les capteurs et laisse derrière lui une mémoire partielle de l\'environnement.',
            'consoleEyebrow' => 'Console active',
            'tabProbe' => 'Sonde',
            'tabEnvironment' => 'Environnement',
            'tabSystems' => 'Sous-systèmes',
            'tabActions' => 'Actions',
            'refresh' => 'Rafraîchir',
            'loading' => 'Chargement...',
            'scan' => 'Scanner',
            'movement' => 'Déplacement',
            'targetX' => 'X cible',
            'targetY' => 'Y cible',
            'targetZ' => 'Z cible',
            'initiateJump' => 'Initier le saut',
            'status' => 'Statut',
            'sensors' => 'Capteurs',
            'deuterium' => 'Deutérium',
            'sector' => 'Secteur',
            'velocityC' => 'Vitesse c',
            'heading' => 'Cap',
            'transit' => 'Transit',
            'integrity' => 'Intégrité',
            'energy' => 'Énergie',
            'internalClock' => 'Horloge interne',
            'task' => 'Tâche',
            'noTask' => 'Aucune',
            'requestDenied' => 'Requête refusée',
            'invalidCoordinates' => 'Coordonnées relatives invalides: x + y + z doit être pair.',
            'orderSent' => 'Ordre transmis...',
            'movementAccepted' => 'Déplacement accepté.',
            'originSector' => 'Secteur d\'origine',
            'destinationSector' => 'Secteur d\'arrivée',
            'remainingTime' => 'Temps restant',
            'secondsShort' => 's',
            'sensorDegradedInfo' => 'À des vitesses relativistes, proches des ordres de grandeur de la vitesse de la lumière, les capteurs externes accumulent trop de décalage et de bruit pour analyser finement l\'environnement.',
            'sensorBlindInfo' => 'À cette vitesse relativiste, les capteurs externes sont aveuglés et ne peuvent plus fournir d\'analyse fiable de l\'environnement.',
        ],
        'en' => [
            'htmlLang' => 'en',
            'languageLabel' => 'Language',
            'languageFrench' => 'Français',
            'languageEnglish' => 'English',
            'logout' => 'Log out',
            'loginEyebrow' => 'Access terminal',
            'loginTitle' => 'Wake the probe',
            'loginInvalid' => 'Invalid credentials.',
            'username' => 'Username',
            'password' => 'Password',
            'rememberMe' => 'Remember me',
            'authenticate' => 'Authenticate',
            'briefEyebrow' => 'Interstellar navigation prototype',
            'briefTitle' => 'An onboard intelligence, a tired hull, a universe to chart.',
            'briefText' => 'You pilot a Von Neumann probe through a procedural sector grid. Each jump consumes deuterium, disrupts the sensors, and leaves behind a partial memory of the environment.',
            'consoleEyebrow' => 'Active console',
            'tabProbe' => 'Probe',
            'tabEnvironment' => 'Environment',
            'tabSystems' => 'Subsystems',
            'tabActions' => 'Actions',
            'refresh' => 'Refresh',
            'loading' => 'Loading...',
            'scan' => 'Scan',
            'movement' => 'Movement',
            'targetX' => 'Target X',
            'targetY' => 'Target Y',
            'targetZ' => 'Target Z',
            'initiateJump' => 'Initiate jump',
            'status' => 'Status',
            'sensors' => 'Sensors',
            'deuterium' => 'Deuterium',
            'sector' => 'Sector',
            'velocityC' => 'Velocity c',
            'heading' => 'Heading',
            'transit' => 'Transit',
            'integrity' => 'Integrity',
            'energy' => 'Energy',
            'internalClock' => 'Internal clock',
            'task' => 'Task',
            'noTask' => 'None',
            'requestDenied' => 'Request denied',
            'invalidCoordinates' => 'Invalid relative coordinates: x + y + z must be even.',
            'orderSent' => 'Order transmitted...',
            'movementAccepted' => 'Movement accepted.',
            'originSector' => 'Origin sector',
            'destinationSector' => 'Arrival sector',
            'remainingTime' => 'Remaining time',
            'secondsShort' => 's',
            'sensorDegradedInfo' => 'At relativistic speeds, close to the order of magnitude of light speed, external sensors accumulate too much shift and noise to analyze the environment in detail.',
            'sensorBlindInfo' => 'At this relativistic speed, external sensors are blinded and can no longer provide a reliable environmental analysis.',
        ],
    ];

    public function __construct(private readonly string $language) {}

    public static function supportedLanguages(): array
    {
        return self::SUPPORTED_LANGUAGES;
    }

    public static function normalize(?string $language): string
    {
        $language = strtolower(substr((string) $language, 0, 2));

        return in_array($language, self::SUPPORTED_LANGUAGES, true) ? $language : self::DEFAULT_LANGUAGE;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function get(string $key): string
    {
        return self::MESSAGES[$this->language][$key]
            ?? self::MESSAGES[self::DEFAULT_LANGUAGE][$key]
            ?? $key;
    }

    public function allEscaped(): array
    {
        $messages = [];
        foreach (self::MESSAGES[$this->language] as $key => $value) {
            $messages[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $messages;
    }

    public function jsMessages(): array
    {
        return [
            'status' => $this->get('status'),
            'sensors' => $this->get('sensors'),
            'deuterium' => $this->get('deuterium'),
            'sector' => $this->get('sector'),
            'velocityC' => $this->get('velocityC'),
            'heading' => $this->get('heading'),
            'transit' => $this->get('transit'),
            'integrity' => $this->get('integrity'),
            'energy' => $this->get('energy'),
            'internalClock' => $this->get('internalClock'),
            'task' => $this->get('task'),
            'noTask' => $this->get('noTask'),
            'requestDenied' => $this->get('requestDenied'),
            'invalidCoordinates' => $this->get('invalidCoordinates'),
            'orderSent' => $this->get('orderSent'),
            'movementAccepted' => $this->get('movementAccepted'),
            'originSector' => $this->get('originSector'),
            'destinationSector' => $this->get('destinationSector'),
            'remainingTime' => $this->get('remainingTime'),
            'secondsShort' => $this->get('secondsShort'),
            'sensorDegradedInfo' => $this->get('sensorDegradedInfo'),
            'sensorBlindInfo' => $this->get('sensorBlindInfo'),
        ];
    }
}
