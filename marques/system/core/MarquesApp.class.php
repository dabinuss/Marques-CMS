<?php
declare(strict_types=1);

namespace Marques\Core;

class MarquesApp extends AppCore
{
    private Router $router;
    private Template $template;
    private AppContainer $appcontainer;

    public function __construct()
    {
        parent::__construct();
        $this->initContainer();
        $this->router   = new Router();
        $this->template = new Template();
    }

    /**
     * Initialisiert den AppContainer und registriert essentielle Services.
     */
    private function initContainer(): void
    {
        $this->appcontainer = new AppContainer();
        $this->appcontainer->register(AppSettings::class, AppSettings::getInstance());
        $this->appcontainer->register(User::class, new User());
    }

    /**
     * Führt die Initialisierung der Anwendung durch.
     */
    public function init(): void
    {
        $this->startSession();
        $this->checkDirectAccess();
        $settings = $this->loadSettings();
        $this->configureErrorReporting($settings);
        $this->setTimezone($settings);
        $this->checkMaintenanceMode($settings);
        $this->logUserAccess();
        $this->loadHelpers();
    }

    /**
     * Startet die Session, sofern noch nicht geschehen.
     */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Verhindert direkten Dateizugriff.
     */
    private function checkDirectAccess(): void
    {
        if (!defined('MARQUES_ROOT_DIR')) {
            exit('Direkter Zugriff ist nicht erlaubt.');
        }
    }

    /**
     * Lädt die Systemeinstellungen und registriert sie im Container.
     */
    private function loadSettings(): AppSettings
    {
        $settings = $this->appcontainer->get(AppSettings::class);
        $this->appcontainer->register('config', $settings->getAllSettings());
        return $settings;
    }

    /**
     * Konfiguriert die Fehlerberichterstattung anhand der Einstellungen.
     */
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

    /**
     * Stellt die Zeitzone anhand der Einstellungen ein.
     */
    private function setTimezone(AppSettings $settings): void
    {
        date_default_timezone_set($settings->getSetting('timezone', 'UTC'));
    }

    /**
     * Prüft, ob der Wartungsmodus aktiviert ist und zeigt ggf. die Wartungsseite an.
     */
    private function checkMaintenanceMode(AppSettings $settings): void
    {
        if (!defined('IS_ADMIN') && $settings->getSetting('maintenance_mode', false)) {
            if (!$this->user->isAdmin()) {
                $maintenanceMessage = $settings->getSetting('maintenance_message', 'Die Website wird aktuell gewartet.');
                header('HTTP/1.1 503 Service Temporarily Unavailable');
                header('Status: 503 Service Temporarily Unavailable');
                header('Retry-After: 3600'); // Eine Stunde
                echo $this->renderMaintenancePage($settings, $maintenanceMessage);
                exit;
            }
        }
    }

    /**
     * Rendert die HTML-Ausgabe für den Wartungsmodus.
     */
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

    /**
     * Erfasst Seitenaufrufe echter Benutzer und loggt diese mithilfe von AppLogger.
     */
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
            $logger = AppLogger::getInstance();
            $logger->info('User Access', $logData);
        }
    }

    /**
     * Anonymisiert die IP-Adresse, indem das letzte Oktett auf "0" gesetzt wird.
     */
    private function anonymizeIp(string $ip): string
    {
        $parts = explode('.', $ip);
        return (count($parts) === 4) ? "{$parts[0]}.{$parts[1]}.{$parts[2]}.0" : $ip;
    }

    /**
     * Ermittelt die aktuelle URL des Requests.
     */
    private function getCurrentUrl(): string
    {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host     = $_SERVER['HTTP_HOST'] ?? '';
        $uri      = $_SERVER['REQUEST_URI'] ?? '';
        return "{$protocol}://{$host}{$uri}";
    }

    /**
     * Lädt benötigte Helper-Funktionen.
     */
    private function loadHelpers(): void
    {
        require_once $this->appPath->combine('core', 'Exceptions.inc.php');
    }

    /**
     * Führt die Anwendungslogik aus.
     */
    public function run(): void
    {
        try {
            $this->triggerEvent('before_request');
            $route = $this->router->processRequest();
            $route = $this->triggerEvent('after_routing', $route);
            $content = new Content();
            $pageData = $content->getPage($route['path']);
            $pageData = $this->triggerEvent('before_render', $pageData);
            $this->template->render($pageData);
            $this->triggerEvent('after_render');
        } catch (\Exception $e) {
            $this->handleException($e);
        }
    }
}
