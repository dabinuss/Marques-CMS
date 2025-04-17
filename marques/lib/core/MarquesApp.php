<?php
declare(strict_types=1);

namespace Marques\Core;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Util\SafetyXSS;
use FlatFileDB\FlatFileDatabase;
use FlatFileDB\FlatFileDatabaseHandler;
use Marques\Util\Helper;
use Marques\Http\Router;
use Marques\Data\FileManager;
use Marques\Service\NavigationManager;
use Marques\Service\Content;
use Marques\Service\ThemeManager;
use Marques\Service\User;
use Marques\Util\ExceptionHandler;
use Marques\Filesystem\PathRegistry;

/**
 * Main application class for Marques CMS.
 * Handles initialization, dependency injection, session management, 
 * error handling, request processing, and main run loop.
 */
class MarquesApp
{
    private Router $router;
    private Template $template;
    private Node $appcontainer;
    private DatabaseHandler $dbHandler;
    private Logger $logger;
    private Events $eventManager;
    private User $user;
    private PathRegistry $appPath;
    private Content $content;
    private ThemeManager $themeManager;
    private FileManager $fileManager;
    private Helper $helper;
    private NavigationManager $navigation;
    private ExceptionHandler $exceptionHandler;

    /**
     * Constructor: initializes the dependency container and required services.
     *
     * @param Node $rootContainer Root dependency injection container.
     */
    public function __construct(Node $rootContainer)
    {
        $this->initContainer($rootContainer);

        try {
            $this->dbHandler    = $this->appcontainer->get(DatabaseHandler::class);
            $this->appPath      = $this->appcontainer->get(PathRegistry::class);
            $this->logger       = $this->appcontainer->get(Logger::class);
            $this->eventManager = $this->appcontainer->get(Events::class);
            $this->fileManager  = $this->appcontainer->get(FileManager::class);
            $this->themeManager = $this->appcontainer->get(ThemeManager::class);
            $this->user         = $this->appcontainer->get(User::class);
            $this->content      = $this->appcontainer->get(Content::class);
            $this->template     = $this->appcontainer->get(Template::class);
            $this->router       = $this->appcontainer->get(Router::class);
            $this->helper       = $this->appcontainer->get(Helper::class);
            $this->navigation   = $this->appcontainer->get(NavigationManager::class);

            $settingsRecord = $this->dbHandler->table('settings')
                                              ->select(['debug'])
                                              ->where('id', '=', 1)
                                              ->first();
            $debugSetting = isset($settingsRecord['debug']) ?
                filter_var($settingsRecord['debug'], FILTER_VALIDATE_BOOLEAN) : false;

            $this->exceptionHandler = new ExceptionHandler($debugSetting, $this->logger);
            $this->exceptionHandler->register();
        } catch (\Exception $e) {
            error_log("Critical error during MarquesApp startup: " . $e->getMessage());
            $this->displayFatalError("The system could not be started. Please contact the administrator.");
            exit(1);
        }
    }

    /**
     * Displays a user-friendly fatal error page.
     *
     * @param string $message Error message to display.
     */
    private function displayFatalError(string $message): void {
        header('HTTP/1.1 500 Internal Server Error', true, 500);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>System Error</title>';
        echo '<style>body{font-family:sans-serif;background:#f8f9fa;color:#333;margin:0;padding:50px 20px;text-align:center;}';
        echo '.error-container{max-width:650px;margin:0 auto;background:white;border-radius:5px;padding:30px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}';
        echo 'h1{color:#e74c3c;}p{font-size:16px;line-height:1.5;}</style></head>';
        echo '<body><div class="error-container"><h1>System Error</h1>';
        echo '<p>' . htmlspecialchars($message) . '</p></div></body></html>';
    }

    /**
     * Initializes the dependency injection child container.
     *
     * @param Node $rootContainer Root DI container.
     */
    private function initContainer(Node $rootContainer): void {
        $this->appcontainer = new Node($rootContainer);
        // Application-specific overrides can be registered here if needed.
    }

    /**
     * Starts a secure session with error handling.
     */
    private function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $sessionConfig = [
                'cookie_lifetime' => 86400,
                'cookie_secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true
            ];

            foreach ($sessionConfig as $option => $value) {
                ini_set('session.' . $option, (string)$value);
            }

            session_name('marques_' . substr(md5(MARQUES_ROOT_DIR), 0, 6));

            if (!session_start()) {
                error_log("Failed to start session");
                ini_set('session.use_cookies', '0');
            }
        }
    }

    /**
     * Main application initialization: session, security headers, error reporting, timezone, maintenance, access log.
     */
    public function init(): void
    {
        try {
            $this->startSession();
            $this->checkDirectAccess();
            SafetyXSS::setSecurityHeaders();
            SafetyXSS::setCSPHeader();
            $this->configureErrorReporting($this->dbHandler);
            $this->setTimezone($this->dbHandler);
            $this->checkMaintenanceMode($this->dbHandler);
            $this->logUserAccess();
        } catch (\Exception $e) {
            error_log("Error in MarquesApp::init(): " . $e->getMessage());
            if ($e->getCode() === 503) {
                $this->displayMaintenancePage($e->getMessage() ?: "The website is currently under maintenance.");
                exit;
            }
        }
    }

    /**
     * Prevents direct script access if MARQUES_ROOT_DIR is not defined.
     */
    private function checkDirectAccess(): void {
        if (!defined('MARQUES_ROOT_DIR')) {
            header('HTTP/1.1 403 Forbidden');
            exit('Direct access is not allowed.');
        }
    }

    /**
     * Sets error reporting and logging based on the debug setting from database.
     *
     * @param DatabaseHandler $dbHandler
     */
    private function configureErrorReporting(DatabaseHandler $dbHandler): void {
        $debugSetting = false;
        try {
            $settingsRecord = $dbHandler->table('settings')
                                        ->select(['debug'])
                                        ->where('id', '=', 1)
                                        ->first();
            if (is_array($settingsRecord) && isset($settingsRecord['debug'])) {
                $debugSetting = filter_var($settingsRecord['debug'], FILTER_VALIDATE_BOOLEAN);
            }
        } catch (\Exception $e) {
            error_log("Error reading debug setting: " . $e->getMessage());
        }

        error_reporting($debugSetting ? E_ALL : E_ERROR | E_WARNING | E_PARSE);
        ini_set('display_errors', $debugSetting ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', MARQUES_ROOT_DIR . '/logs/php_error.log');
    }

    /**
     * Sets the default timezone from the database setting, with fallback to UTC.
     *
     * @param DatabaseHandler $dbHandler
     */
    private function setTimezone(DatabaseHandler $dbHandler): void {
        $defaultTimezone = 'UTC';
        try {
            $settingsRecord = $dbHandler->table('settings')
                                        ->select(['timezone'])
                                        ->where('id', '=', 1)
                                        ->first();
            $timezone = (isset($settingsRecord['timezone']) && is_string($settingsRecord['timezone']) && !empty($settingsRecord['timezone']))
                        ? $settingsRecord['timezone']
                        : $defaultTimezone;

            if (!in_array($timezone, \DateTimeZone::listIdentifiers())) {
                error_log("Invalid timezone in settings: {$timezone}, using {$defaultTimezone}");
                $timezone = $defaultTimezone;
            }
            date_default_timezone_set($timezone);
        } catch (\Exception $e) {
            error_log("Error setting timezone: " . $e->getMessage());
            date_default_timezone_set($defaultTimezone);
        }
    }

    /**
     * Checks if the system is in maintenance mode, and displays a maintenance page if necessary.
     *
     * @param DatabaseHandler $dbHandler
     */
    private function checkMaintenanceMode(DatabaseHandler $dbHandler): void {
        try {
            $settingsRecord = $dbHandler->table('settings')
                                        ->select(['maintenance_mode', 'maintenance_message'])
                                        ->where('id', '=', 1)
                                        ->first();
            $maintenanceMode = filter_var($settingsRecord['maintenance_mode'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (!defined('IS_ADMIN') && $maintenanceMode && !$this->user->isAdmin()) {
                $maintenanceMessage = (isset($settingsRecord['maintenance_message']) && is_string($settingsRecord['maintenance_message']) && !empty($settingsRecord['maintenance_message']))
                                     ? $settingsRecord['maintenance_message']
                                     : 'The website is currently under maintenance.';
                $this->displayMaintenancePage($maintenanceMessage);
                exit;
            }
        } catch (\Exception $e) {
            error_log("Error checking maintenance mode: " . $e->getMessage());
        }
    }

    /**
     * Displays a visually appealing maintenance page.
     *
     * @param string $maintenanceMessage The maintenance message to show.
     */
    private function displayMaintenancePage(string $maintenanceMessage): void {
        header('HTTP/1.1 503 Service Temporarily Unavailable', true, 503);
        header('Retry-After: 3600');

        try {
            $settingsRecord = $this->dbHandler->table('settings')
                                            ->select(['site_name'])
                                            ->where('id', '=', 1)
                                            ->first();
            $siteNameValue = (isset($settingsRecord['site_name']) && is_string($settingsRecord['site_name']) && !empty($settingsRecord['site_name']))
                           ? $settingsRecord['site_name']
                           : 'marques CMS';
        } catch (\Exception $e) {
            $siteNameValue = 'marques CMS';
        }

        $siteName = SafetyXSS::escapeOutput($siteNameValue, 'html');
        $message = SafetyXSS::escapeOutput($maintenanceMessage, 'html');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Maintenance - {$siteName}</title>
    <style>
        body { font-family: sans-serif; background-color: #f8f9fa; color: #212529; margin: 0; padding: 0; display: flex; height: 100vh; align-items: center; justify-content: center; }
        .maintenance-container { text-align: center; max-width: 600px; padding: 2rem; background-color: white; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { color: #343a40; margin-top: 0; }
        p { font-size: 1.1rem; line-height: 1.6; color: #6c757d; }
        .icon { font-size: 4rem; margin-bottom: 1rem; color: #007bff; }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="icon">⚙️</div>
        <h1>Website Under Maintenance</h1>
        <p>{$message}</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Logs user access (except for admin and bots).
     */
    private function logUserAccess(): void {
        if (!defined('IS_ADMIN') && !$this->isBot()) {
            try {
                $logData = [
                    'time' => date('Y-m-d H:i:s'),
                    'ip' => $this->anonymizeIp($_SERVER['REMOTE_ADDR'] ?? ''),
                    'url' => $this->getCurrentUrl(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'referrer' => $_SERVER['HTTP_REFERER'] ?? ''
                ];
                $this->logger->info('User Access', $logData);
            } catch (\Exception $e) {
                error_log("Error logging user access: " . $e->getMessage());
            }
        }
    }

    /**
     * Detects if the request comes from a bot (simple user-agent check).
     *
     * @return bool
     */
    private function isBot(): bool {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return preg_match('/(bot|crawler|spider|slurp|bingbot|googlebot)/i', $userAgent) === 1;
    }

    /**
     * Anonymizes an IP address (IPv4 last octet, IPv6 last four blocks).
     *
     * @param string $ip
     * @return string
     */
    private function anonymizeIp(string $ip): string {
        if (empty($ip)) return '0.0.0.0';

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $blocks = explode(':', $ip);
            if (count($blocks) === 8) {
                return implode(':', array_slice($blocks, 0, 4)) . ':0:0:0:0';
            } elseif (count($blocks) < 8 && strpos($ip, '::') !== false) {
                $expanded = str_replace('::', ':' . str_repeat('0:', 8 - count($blocks) + 1), $ip);
                $blocks = explode(':', $expanded);
                return implode(':', array_slice($blocks, 0, 4)) . ':0:0:0:0';
            }
            return $ip;
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return (count($parts) === 4) ? "{$parts[0]}.{$parts[1]}.{$parts[2]}.0" : $ip;
        }
        return $ip;
    }

    /**
     * Returns the current request URL with protocol and host.
     *
     * @return string
     */
    private function getCurrentUrl(): string {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return "{$protocol}://{$host}{$uri}";
    }

    /**
     * Main execution method: handles session, initialization, request routing, and rendering.
     */
    public function run(): void {
        ob_start();

        try {
            $this->startSession();
            $this->init();

            $this->eventManager->trigger('before_request');

            $requestPath = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
            $normalizedPath = trim($requestPath, '/');
            $isRootRequest = $requestPath === '/' || empty($normalizedPath);

            $contentPath = $isRootRequest ? 'home' : $normalizedPath;
            error_log("Processing request for path: " . $contentPath);

            $directContentLoad = false;

            if ($isRootRequest || file_exists(MARQUES_CONTENT_DIR . '/pages/' . $contentPath . '.md')) {
                error_log("Trying direct content load for: " . $contentPath);
                try {
                    $pageData = $this->content->getPage($contentPath);
                    $directContentLoad = true;
                    $routeInfo = [
                        'path' => $contentPath,
                        'params' => []
                    ];
                    error_log("Page loaded directly: " . $contentPath);
                } catch (\Exception $contentEx) {
                    error_log("Direct content load failed, falling back to routing: " . $contentEx->getMessage());
                }
            }

            if (!$directContentLoad) {
                try {
                    $routeInfo = $this->router->processRequest();
                    $contentPath = $routeInfo['path'] ?? $contentPath;
                    $params = $routeInfo['params'] ?? [];
                    error_log("Route found, loading page: " . $contentPath);
                    $pageData = $this->content->getPage($contentPath, $params);
                } catch (\Exception $routeException) {
                    error_log("Routing error: " . $routeException->getMessage());
                    throw $routeException;
                }
            }

            $routeInfo = $routeInfo ?? ['path' => $contentPath, 'params' => []];
            $this->eventManager->trigger('after_routing', $routeInfo);

            $pageDataProcessed = $this->eventManager->trigger('before_render', $pageData);

            if ($pageDataProcessed === null || !is_array($pageDataProcessed)) {
                $pageDataProcessed = $pageData;
            }

            $this->template->render($pageDataProcessed);

            $this->eventManager->trigger('after_render');
        } catch (\Exception $e) {
            throw $e;
        } finally {
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
        }
    }

    /**
     * Returns a human-readable error title for a given HTTP status code.
     *
     * @param int $code
     * @return string
     */
    private function getErrorTitleForCode(int $code): string {
        switch ($code) {
            case 400: return 'Bad Request';
            case 401: return 'Unauthorized';
            case 403: return 'Access Denied';
            case 404: return 'Page Not Found';
            case 500: return 'Internal Server Error';
            case 503: return 'Service Unavailable';
            default: return 'Error ' . $code;
        }
    }
}