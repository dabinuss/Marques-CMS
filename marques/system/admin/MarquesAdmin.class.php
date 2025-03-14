<?php
declare(strict_types=1);

namespace Marques\Admin;

use Marques\Core\AppCore;
use Marques\Core\AppContainer;
use Marques\Core\AppConfig;
use Marques\Core\User;
use Marques\Core\Admin; // Für requireLogin()

class MarquesAdmin extends AppCore
{
    private AdminRouter $router;
    private AdminTemplate $template;
    private AppContainer $appcontainer;
    private array $systemConfig;
    protected User $user;

    public function __construct()
    {
        parent::__construct();

        // Initialisiere den Container und registriere grundlegende Services
        $this->appcontainer = new AppContainer();
        $appConfig = AppConfig::getInstance();
        $this->appcontainer->register(AppConfig::class, $appConfig);

        // AdminRouter und AdminTemplate initialisieren
        $this->router = new AdminRouter();
        $this->template = new AdminTemplate();
    }

    /**
     * Initialisiert den Admin-Bereich (Session, Konfiguration, Authentifizierung).
     */
    public function init(): void
    {
        session_start();

        if (!defined('MARQUES_ROOT_DIR')) {
            exit('Direkter Zugriff ist nicht erlaubt.');
        }

        /** @var AppConfig $appConfig */
        $appConfig = $this->appcontainer->get(AppConfig::class);

        // Systemkonfiguration laden; falls nicht vorhanden, Default-Einstellungen nutzen
        $systemConfig = $appConfig->load('system');
        if (empty($systemConfig)) {
            $systemConfig = $appConfig->getDefaultSettings();
        }
        $this->systemConfig = $systemConfig;
        $this->appcontainer->register('systemConfig', $this->systemConfig);

        // Benutzerkonfiguration laden – falls nicht vorhanden, leeres Array als Fallback
        $userConfig = $appConfig->load('user') ?: [];
        $this->appcontainer->register(User::class, new User($userConfig));

        // Fehlerberichterstattung und Zeitzone konfigurieren
        if (($this->systemConfig['debug'] ?? false) === true) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } else {
            error_reporting(0);
            ini_set('display_errors', 0);
        }
        date_default_timezone_set($this->systemConfig['timezone'] ?? 'UTC');

        // Admin-Zugang prüfen
        $admin = new Admin();
        $admin->requireLogin();

        // Authentifizierten Benutzer aus dem Container holen
        $this->user = $this->appcontainer->get(User::class);
    }

    /**
     * Gibt globale Variablen zurück, die in allen Templates verfügbar sein sollen.
     */
    public function getGlobalVars(): array
    {
        return [
            'page_title'    => $this->router->route(),
            'site_name'     => $this->systemConfig['site_name'] ?? 'Marques CMS',
            'system_config' => $this->systemConfig,
            'user'          => $this->user,
            'username'      => $this->user->getCurrentDisplayName(),
        ];
    }

    /**
     * Führt die Anwendung aus.
     */
    public function run(): void
    {
        try {
            $this->triggerEvent('before_request');

            // Ermittelt den Basis-Pfad für die Content-Datei,
            // z. B. MARQUES_ROOT_DIR . '/admin/pages/dashboard'
            $basePath = $this->router->route();

            // Globale Variablen abrufen und Template rendern
            $vars = $this->getGlobalVars();
            $this->template->render($vars, $basePath);

            $this->triggerEvent('after_render');
        } catch (\Exception $e) {
            $this->handleException($e);
        }
    }
}
