<?php
declare(strict_types=1);

namespace Marques\Core;

use Marques\Core\SafetyXSS;
use Marques\Core\Content;

class MarquesApp
{
    private AppRouter $router;
    private AppTemplate $template;
    private AppNode $appcontainer;
    private DatabaseHandler $dbHandler;
    private AppLogger $logger;
    private AppEvents $eventManager;
    private User $user;
    private AppPath $appPath;
    private Content $content;
    private ThemeManager $themeManager;
    private FileManager $fileManager;

    public function __construct()
    {
        $this->initContainer();

        // Alle Kernservices werden ausschließlich über den Container bezogen.
        $this->dbHandler    = $this->appcontainer->get(DatabaseHandler::class);
        $this->appPath      = $this->appcontainer->get(AppPath::class);
        $this->logger       = $this->appcontainer->get(AppLogger::class);
        $this->eventManager = $this->appcontainer->get(AppEvents::class);
        $this->fileManager  = $this->appcontainer->get(FileManager::class);
        $this->themeManager = $this->appcontainer->get(ThemeManager::class);
        $this->user         = $this->appcontainer->get(User::class);
        $this->content      = $this->appcontainer->get(Content::class);
        $this->template     = $this->appcontainer->get(AppTemplate::class);
        $this->router       = $this->appcontainer->get(AppRouter::class);
    }

    /**
     * Initialisiert den DI-Container und registriert alle wesentlichen Services.
     */
    private function initContainer(): void {
        $this->appcontainer = new AppNode();

        $this->appcontainer->register(DatabaseHandler::class, function(AppNode $container) {
            return new DatabaseHandler();
        });
        $this->appcontainer->register(AppPath::class, function(AppNode $container) {
            return new AppPath();
        });
        $this->appcontainer->register(AppLogger::class, function(AppNode $container) {
            return new AppLogger();
        });
        $this->appcontainer->register(AppEvents::class, function(AppNode $container) {
            return new AppEvents();
        });
        $this->appcontainer->register(AppCache::class, function(AppNode $container) {
            return new AppCache();
        });
        $this->appcontainer->register(FileManager::class, function(AppNode $container) {
            return new FileManager($container->get(AppCache::class), MARQUES_CONTENT_DIR);
        });
        $this->appcontainer->register(ThemeManager::class, function(AppNode $container) {
            return new ThemeManager($container->get(DatabaseHandler::class));
        });
        $this->appcontainer->register(User::class, function(AppNode $container) {
            return new User($container->get(DatabaseHandler::class));
        });
        $this->appcontainer->register(Content::class, function(AppNode $container) {
            return new Content(
                $container->get(DatabaseHandler::class),
                $container->get(FileManager::class)
            );
        });
        $this->appcontainer->register(AppRouter::class, function(AppNode $container) {
            return new AppRouter($container, true);
        });
        $this->appcontainer->register(AppTemplate::class, function(AppNode $container) {
            return new AppTemplate(
                $container->get(DatabaseHandler::class),
                $container->get(ThemeManager::class),
                $container->get(AppPath::class),
                $container->get(AppCache::class)
            );
        });
    }

    private function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function init(): void
    {
        $this->startSession();
        $this->checkDirectAccess();
        SafetyXSS::setSecurityHeaders();
        SafetyXSS::setCSPHeader();
        $this->configureErrorReporting($this->dbHandler);
        $this->setTimezone($this->dbHandler);
        $this->checkMaintenanceMode($this->dbHandler);
        $this->logUserAccess();
    }

    private function checkDirectAccess(): void
    {
        if (!defined('MARQUES_ROOT_DIR')) {
            exit('Direkter Zugriff ist nicht erlaubt.');
        }
    }

    private function configureErrorReporting(DatabaseHandler $dbHandler): void
    {
        if ($dbHandler->getSetting('debug', false)) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
        }
    }

    private function setTimezone(DatabaseHandler $dbHandler): void
    {
        date_default_timezone_set($dbHandler->getSetting('timezone', 'UTC'));
    }

    private function checkMaintenanceMode(DatabaseHandler $dbHandler): void
    {
        if (!defined('IS_ADMIN') && $dbHandler->getSetting('maintenance_mode', false)) {
            if (!$this->user->isAdmin()) {
                $maintenanceMessage = $dbHandler->getSetting('maintenance_message', 'Die Website wird aktuell gewartet.');
                header('HTTP/1.1 503 Service Temporarily Unavailable');
                header('Status: 503 Service Temporarily Unavailable');
                header('Retry-After: 3600');
                echo $this->renderMaintenancePage($dbHandler, $maintenanceMessage);
                exit;
            }
        }
    }

    private function renderMaintenancePage(DatabaseHandler $dbHandler, string $maintenanceMessage): string
    {
        $siteName = SafetyXSS::escapeOutput($dbHandler->getSetting('site_name', 'marques CMS'), 'html');
        $message  = SafetyXSS::escapeOutput($maintenanceMessage, 'html');
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
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            if (count($parts) >= 1) {
                $parts[count($parts)-1] = '0';
                return implode(':', $parts);
            }
            return $ip;
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return (count($parts) === 4) ? "{$parts[0]}.{$parts[1]}.{$parts[2]}.0" : $ip;
        }
        return $ip;
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
        // Beispielhafter Aufruf – z. B.:
        // require_once $this->appPath->combine('core', 'Exceptions.inc.php');
    }

    public function run(): void {
        // Starte Output Buffering, um sicherzustellen, dass keine Ausgabe vor session_start() erfolgt.
        ob_start();
        $this->startSession();
        $this->init();
        try {
            $this->eventManager->trigger('before_request');
            $routeInfo = $this->router->processRequest();
            $this->eventManager->trigger('after_routing', $routeInfo);
            
            $pageData = $this->content->getPage($routeInfo['path'], $routeInfo['params'] ?? []);
            $pageData = $this->eventManager->trigger('before_render', $pageData);
            $this->template->render($pageData);
            
            $this->eventManager->trigger('after_render');
        } catch (\Exception $e) {
            $this->handleException($e);
        }
        // Output Buffer leeren
        ob_end_flush();
    }

    private function handleException(\Exception $e): void
    {
        $this->logger->error($e->getMessage(), ['exception' => $e]);
        http_response_code(500);
        echo '<h1>Ein Fehler ist aufgetreten</h1>';
        if ($this->dbHandler->getSetting('debug', false)) {
            echo '<pre>' . SafetyXSS::escapeOutput($e->getMessage(), 'html') . '</pre>';
        } else {
            echo '<p>Bitte versuchen Sie es später erneut.</p>';
        }
        exit;
    }
}
