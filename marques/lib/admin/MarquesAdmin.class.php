<?php
declare(strict_types=1);

namespace Marques\Admin;

use Marques\Core\AppNode;
use Marques\Core\DatabaseHandler;
use Marques\Core\AppLogger;
use Marques\Core\AppEvents;
use Marques\Core\AppCache;
use Marques\Core\AppPath;
use Marques\Core\MediaManager;
use Marques\Core\PageManager;
use Marques\Core\User;
use Marques\Core\Content;
use Marques\Core\ThemeManager;
use Marques\Core\BlogManager;
use Marques\Core\FileManager;

class MarquesAdmin
{
    private DatabaseHandler $dbHandler;
    private AdminRouter $router;
    private AdminTemplate $template;
    private AppNode $adminContainer;
    private array $systemConfig;
    private AdminAuthService $authService;
    protected User $user;
    public string $csrf_token;

    public function __construct()
    {
        $this->initContainer();

        // Dienste über den Container beziehen
        $this->dbHandler    = $this->adminContainer->get(DatabaseHandler::class);
        $this->systemConfig = $this->dbHandler->getAllSettings();
        $this->authService  = $this->adminContainer->get(AdminAuthService::class);
        $this->router       = $this->adminContainer->get(AdminRouter::class);
        $this->template     = $this->adminContainer->get(AdminTemplate::class);
        $this->user         = $this->adminContainer->get(User::class);
    }

    /**
     * Initialisiert den DI-Container und registriert alle wesentlichen Services für den Admin-Bereich.
     */
    private function initContainer(): void
    {
        $this->adminContainer = new AppNode();

        // Registrierung über Closures
        $this->adminContainer->register(DatabaseHandler::class, function(AppNode $container) {
            return new DatabaseHandler();
        });
        $this->adminContainer->register(AppPath::class, function(AppNode $container) {
            return new AppPath();
        });
        $this->adminContainer->register(AppLogger::class, function(AppNode $container) {
            // Hier kannst du eine Instanz von AppLogger erzeugen (ohne Singleton)
            return new AppLogger();
        });
        $this->adminContainer->register(AppEvents::class, function(AppNode $container) {
            return new AppEvents();
        });
        $this->adminContainer->register(AppCache::class, function(AppNode $container) {
            return new AppCache();
        });
        // AdminRouter als Service registrieren
        $this->adminContainer->register(AdminRouter::class, function(AppNode $container) {
            return new AdminRouter();
        });
        // User – hier evtl. mit benötigten Abhängigkeiten (z. B. DatabaseHandler)
        $this->adminContainer->register(User::class, function(AppNode $container) {
            return new User($container->get(DatabaseHandler::class));
        });
        // Content erhält DatabaseHandler und FileManager
        $this->adminContainer->register(Content::class, function(AppNode $container) {
            return new Content(
                $container->get(DatabaseHandler::class),
                $container->get(FileManager::class)
            );
        });
        // ThemeManager
        $this->adminContainer->register(ThemeManager::class, function(AppNode $container) {
            return new ThemeManager($container->get(DatabaseHandler::class));
        });
        // FileManager – Beachte: Parameterreihenfolge (AppCache, baseDir)
        $this->adminContainer->register(FileManager::class, function(AppNode $container) {
            return new FileManager(
                $container->get(AppCache::class),
                MARQUES_CONTENT_DIR
            );
        });
        // AdminAuthService benötigt User und AppConfig (hier über DatabaseHandler)
        $this->adminContainer->register(AdminAuthService::class, function(AppNode $container) {
            // Wir holen hier AppConfig über den DatabaseHandler (alternativ könntest du AppConfig als eigenen Service registrieren)
            $config = $container->get(DatabaseHandler::class)->getAllSettings();
            return new AdminAuthService($container->get(User::class), $config);
        });
        // AdminTemplate – erbt von AppTemplate und benötigt alle Abhängigkeiten
        $this->adminContainer->register(AdminTemplate::class, function(AppNode $container) {
            return new AdminTemplate(
                $container->get(DatabaseHandler::class),
                $container->get(ThemeManager::class),
                $container->get(AppPath::class),
                $container->get(AppCache::class)
            );
        });
        // PageManager
        $this->adminContainer->register(PageManager::class, function(AppNode $container) {
            return new PageManager($container->get(DatabaseHandler::class));
        });
        // PageManager
        $this->adminContainer->register(MediaManager::class, function(AppNode $container) {
            return new MediaManager($container->get(DatabaseHandler::class));
        });
        $this->adminContainer->register(BlogManager::class, function(AppNode $container) {
            return new BlogManager(
                $container->get(DatabaseHandler::class),
                $container->get(FileManager::class),
            );
        });
        // Optional: weitere Admin-spezifische Services können hier registriert werden
    }

    public function getContainer(): AppNode
    {
        return $this->adminContainer;
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function init(): void
    {
        $this->startSession();
        if (!defined('MARQUES_ROOT_DIR')) {
            exit('Direkter Zugriff ist nicht erlaubt.');
        }
        // CSRF-Token generieren
        $_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
        $this->csrf_token = $_SESSION['csrf_token'];

        // Falls nicht auf der Login-Seite, Authentifizierung erzwingen
        $currentPage = $_GET['page'] ?? '';
        if (strtolower($currentPage) !== 'login') {
            $this->authService->requireLogin();
        }

        // Systemkonfiguration laden
        $systemConfig = $this->dbHandler->getAllSettings() ?: [];
        $this->systemConfig = $systemConfig;
        $this->adminContainer->register('systemConfig', $this->systemConfig);

        if (($this->systemConfig['debug'] ?? false) === true) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
        }
        date_default_timezone_set($this->systemConfig['timezone'] ?? 'UTC');

        $this->user = $this->adminContainer->get(User::class);
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
     * Zentrale Fehlerbehandlung.
     */
    private function handleException(\Exception $e): void
    {
        $logger = $this->adminContainer->get(AppLogger::class);
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

    /**
     * Löst ein Event aus.
     */
    private function triggerEvent(string $event, $data = null)
    {
        $eventManager = $this->adminContainer->get(AppEvents::class);
        return $eventManager ? $eventManager->trigger($event, $data) : $data;
    }
}
