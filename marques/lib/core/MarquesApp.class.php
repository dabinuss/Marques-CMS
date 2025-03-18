<?php
declare(strict_types=1);

namespace Marques\Core;

class MarquesApp
{
    private Router $router;
    private AppTemplate $template;
    private AppNode $appcontainer;
    private AppSettings $settings;
    private AppLogger $logger;
    private AppEvents $eventManager;
    private User $user;
    private AppPath $appPath;
    //private AdminAuthService $authService;

    public function __construct()
    {
        $this->initContainer();
        // Alle Kernservices via Container abrufen
        $this->router       = $this->appcontainer->get(Router::class);
        $this->template     = $this->appcontainer->get(AppTemplate::class);
        $this->settings     = $this->appcontainer->get(AppSettings::class);
        $this->logger       = $this->appcontainer->get(AppLogger::class);
        $this->eventManager = $this->appcontainer->get(AppEvents::class);
        $this->user         = $this->appcontainer->get(User::class);
        $this->appPath      = $this->appcontainer->get(AppPath::class);
        //$this->authService  = $this->appcontainer->get(AdminAuthService::class);
    }

    /**
     * Initialisiert den DI-Container und registriert alle wesentlichen Services.
     */
    private function initContainer(): void
    {
        $this->appcontainer = new AppNode();
        // Registrierungen – hier übergeben wir bereits fertige Instanzen oder Singletons:
        $this->appcontainer->register(AppSettings::class, AppSettings::getInstance());
        $this->appcontainer->register(User::class, new User());
        $this->appcontainer->register(AppLogger::class, AppLogger::getInstance());
        $this->appcontainer->register(AppEvents::class, new AppEvents());
        $this->appcontainer->register(AppPath::class, AppPath::getInstance());
        // Auch Router und Template als Services registrieren
        $this->appcontainer->register(Router::class, new Router());
        $this->appcontainer->register(AppTemplate::class, new AppTemplate());
        //$this->appcontainer->register(AdminAuthService::class, new AdminAuthService());
    }

    public function init(): void
    {
        $this->startSession();
        $this->checkDirectAccess();
        $this->configureErrorReporting($this->settings);
        $this->setTimezone($this->settings);
        $this->checkMaintenanceMode($this->settings);
        $this->logUserAccess();
        $this->loadHelpers();
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private function checkDirectAccess(): void
    {
        if (!defined('MARQUES_ROOT_DIR')) {
            exit('Direkter Zugriff ist nicht erlaubt.');
        }
    }

    private function configureErrorReporting(AppSettings $settings): void
    {
        if ($settings->getSetting('debug', false)) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
        }
    }

    private function setTimezone(AppSettings $settings): void
    {
        date_default_timezone_set($settings->getSetting('timezone', 'UTC'));
    }

    private function checkMaintenanceMode(AppSettings $settings): void
    {
        if (!defined('IS_ADMIN') && $settings->getSetting('maintenance_mode', false)) {
            if (!$this->user->isAdmin()) {
                $maintenanceMessage = $settings->getSetting('maintenance_message', 'Die Website wird aktuell gewartet.');
                header('HTTP/1.1 503 Service Temporarily Unavailable');
                header('Status: 503 Service Temporarily Unavailable');
                header('Retry-After: 3600');
                echo $this->renderMaintenancePage($settings, $maintenanceMessage);
                exit;
            }
        }
    }

    private function renderMaintenancePage(AppSettings $settings, string $maintenanceMessage): string
    {
        $siteName = htmlspecialchars($settings->getSetting('site_name', 'marques CMS'));
        $message  = htmlspecialchars($maintenanceMessage);
        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Wartungsmodus - {$siteName}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; background-color: #f8f9fa; color: #212529; margin: 0; padding: 0; display: flex; height: 100vh; align-items: center; justify-content: center; }
        .maintenance-container { text-align: center; max-width: 600px; padding: 2rem; background-color: white; border-radius: .5rem; box-shadow: 0 4px 6px rgba(0,0,0,.1); }
        h1 { color: #343a40; margin-top: 0; }
        p { font-size: 1.1rem; line-height: 1.6; color: #6c757d; }
        .icon { font-size: 4rem; margin-bottom: 1rem; color: #007bff; }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="icon">⚙️</div>
        <h1>Website wird gewartet</h1>
        <p>{$message}</p>
    </div>
</body>
</html>
HTML;
    }

    private function logUserAccess(): void
    {
        if (!defined('IS_ADMIN') && !preg_match('/(bot|crawler|spider|slurp|bingbot|googlebot)/i', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
            $logData = [
                'time'       => date('Y-m-d H:i:s'),
                'ip'         => $this->anonymizeIp($_SERVER['REMOTE_ADDR'] ?? ''),
                'url'        => $this->getCurrentUrl(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'referrer'   => $_SERVER['HTTP_REFERER'] ?? ''
            ];
            $this->logger->info('User Access', $logData);
        }
    }

    private function anonymizeIp(string $ip): string
    {
        $parts = explode('.', $ip);
        return (count($parts) === 4) ? "{$parts[0]}.{$parts[1]}.{$parts[2]}.0" : $ip;
    }

    private function getCurrentUrl(): string
    {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host     = $_SERVER['HTTP_HOST'] ?? '';
        $uri      = $_SERVER['REQUEST_URI'] ?? '';
        return "{$protocol}://{$host}{$uri}";
    }

    private function loadHelpers(): void
    {
        // Beispielhafter Aufruf – hier wird über den AppPath der Pfad zur Exceptions.inc.php ermittelt.
        require_once $this->appPath->combine('core', 'Exceptions.inc.php');
    }

    public function run(): void
    {
        try {
            $this->eventManager->trigger('before_request');
            $route = $this->router->processRequest();
            $route = $this->eventManager->trigger('after_routing', $route);
            $content = new Content();
            $pageData = $content->getPage($route['path']);
            $pageData = $this->eventManager->trigger('before_render', $pageData);
            $this->template->render($pageData);
            $this->eventManager->trigger('after_render');
        } catch (\Exception $e) {
            $this->handleException($e);
        }
    }

    private function handleException(\Exception $e): void
    {
        $this->logger->error($e->getMessage(), [
            'exception' => $e,
        ]);
        http_response_code(500);
        echo '<h1>Ein Fehler ist aufgetreten</h1>';
        if ($this->settings->getSetting('debug', false)) {
            echo '<pre>' . print_r($e, true) . '</pre>';
        }
        exit;
    }
}
