<?php
declare(strict_types=1);

namespace Marques\Admin;

use Marques\Core\AppNode;
use Marques\Core\AppConfig;
use Marques\Core\User;
use Marques\Admin\AdminAuthService;
use Marques\Core\AppEvents;
use Marques\Core\AppLogger;

class MarquesAdmin
{
    private AdminRouter $router;
    private AdminTemplate $template;
    private AppNode $appcontainer;
    private array $systemConfig;
    private AdminAuthService $authService;
    protected User $user;
    public $csrf_token;

    public function __construct()
    {
        // Initialisiere den DI-Container
        $this->appcontainer = new AppNode();
        $appConfig = AppConfig::getInstance();
        $this->appcontainer->register(AppConfig::class, $appConfig);
        $this->appcontainer->register(AppLogger::class, AppLogger::getInstance());
        $this->appcontainer->register(User::class, new User());
        
        // Registriere den AdminAuthService und übergebe das bereits registrierte User-Objekt
        $this->appcontainer->register(
            AdminAuthService::class,
            new AdminAuthService($this->appcontainer->get(User::class))
        );
        
        // Nutze den im Container registrierten AdminAuthService
        $this->authService = $this->appcontainer->get(AdminAuthService::class);
        
        // Admin-spezifische Klassen initialisieren
        $this->router = new AdminRouter();
        $this->template = new AdminTemplate();
    }

    /**
     * Initialisiert den Admin-Bereich: Session, Authentifizierung, Konfiguration etc.
     */
    public function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
        $this->csrf_token = $_SESSION['csrf_token'];

        if (!defined('MARQUES_ROOT_DIR')) {
            exit('Direkter Zugriff ist nicht erlaubt.');
        }

        $currentPage = $_GET['page'] ?? '';
        if (strtolower($currentPage) !== 'login') {
            $this->authService->requireLogin();
        }

        /** @var AppConfig $appConfig */
        $appConfig = $this->appcontainer->get(AppConfig::class);
        $systemConfig = $appConfig->load('system');
        if (empty($systemConfig)) {
            $systemConfig = $appConfig->getDefaultSettings();
        }
        $this->systemConfig = $systemConfig;
        $this->appcontainer->register('systemConfig', $this->systemConfig);

        $userConfig = $appConfig->load('user') ?: [];
        $this->appcontainer->register(User::class, new User($userConfig));

        if (($this->systemConfig['debug'] ?? false) === true) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } else {
            error_reporting(0);
            ini_set('display_errors', 0);
        }
        date_default_timezone_set($this->systemConfig['timezone'] ?? 'UTC');

        $this->user = $this->appcontainer->get(User::class);
    }

    /**
     * Gibt globale Variablen zurück, die in allen Admin-Templates verfügbar sein sollen.
     */
    public function getGlobalVars(): array
    {
        return [
            'page_title'    => $this->router->route(),
            'site_name'     => $this->systemConfig['site_name'] ?? 'Marques CMS',
            'system_config' => $this->systemConfig,
            'user'          => $this->user,
            'username'      => $this->user->getCurrentDisplayName(),
            'csrf_token'    => $this->csrf_token,
        ];
    }

    /**
     * Löst ein Event über den im Container registrierten AppEvents aus.
     */
    private function triggerEvent(string $event, $data = null)
    {
        $eventManager = $this->appcontainer->get(AppEvents::class);
        return $eventManager ? $eventManager->trigger($event, $data) : $data;
    }

    /**
     * Zentrale Fehlerbehandlung: Loggt den Fehler und zeigt eine Fehlermeldung an.
     */
    private function handleException(\Exception $e): void
    {
        $logger = $this->appcontainer->get(AppLogger::class);
        $logger->error($e->getMessage(), ['exception' => $e]);
        http_response_code(500);
        echo '<h1>Ein Fehler ist aufgetreten</h1>';
        if (($this->systemConfig['debug'] ?? false) === true) {
            echo '<pre>' . print_r($e, true) . '</pre>';
        }
        exit;
    }

    /**
     * Führt den Admin-Bereich aus: Routing, Rendering etc.
     */
    public function run(): void
    {
        try {
            $this->triggerEvent('before_request');

            $templateKey = $this->router->route();

            $vars = $this->getGlobalVars();
            $this->template->render($vars, $templateKey);

            $this->triggerEvent('after_render');
        } catch (\Exception $e) {
            $this->handleException($e);
        }
    }
}
