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

class MarquesApp
{
    private Router $router;
    private Template $template;
    private Node $appcontainer;
    private DatabaseHandler $dbHandler;
    private Logger $logger;
    private Events $eventManager;
    private User $user;
    private Path $appPath;
    private Content $content;
    private ThemeManager $themeManager;
    private FileManager $fileManager;
    private Helper $helper;
    private NavigationManager $navigation;
    private ExceptionHandler $exceptionHandler;

    // Erhalte den Root-Container als Parameter
    public function __construct(Node $rootContainer)
    {
        $this->initContainer($rootContainer);

        try {
            // Dienste beziehen mit Fehlerbehandlung
            $this->dbHandler    = $this->appcontainer->get(DatabaseHandler::class);
            $this->appPath      = $this->appcontainer->get(Path::class);
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
            error_log("Kritischer Fehler beim Start von MarquesApp: " . $e->getMessage());
            $this->displayFatalError("Das System konnte nicht gestartet werden. Bitte kontaktieren Sie den Administrator.");
            exit(1);
        }
    }

    /**
     * Zeigt eine freundliche Fehlermeldung an
     */
    private function displayFatalError(string $message): void {
        header('HTTP/1.1 500 Internal Server Error', true, 500);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Systemfehler</title>';
        echo '<style>body{font-family:sans-serif;background:#f8f9fa;color:#333;margin:0;padding:50px 20px;text-align:center;}';
        echo '.error-container{max-width:650px;margin:0 auto;background:white;border-radius:5px;padding:30px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}';
        echo 'h1{color:#e74c3c;}p{font-size:16px;line-height:1.5;}</style></head>';
        echo '<body><div class="error-container"><h1>Systemfehler</h1>';
        echo '<p>' . htmlspecialchars($message) . '</p></div></body></html>';
    }

    /**
     * Erzeugt einen Child-Container basierend auf dem übergebenen Root-Container.
     */
    private function initContainer(Node $rootContainer): void {
        $this->appcontainer = new Node($rootContainer);
        // Hier kannst du spezifische Überschreibungen für die Hauptanwendung vornehmen, falls nötig.
    }

    /**
     * Verbesserte Session-Initialisierung mit Fehlerbehandlung
     */
    private function startSession(): void {
        // Prüfen, ob Session bereits gestartet
        if (session_status() === PHP_SESSION_NONE) {
            // Sichere Session-Einstellungen
            $sessionConfig = [
                'cookie_lifetime' => 86400,
                'cookie_secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax', // 'Strict' kann Probleme mit externen Redirects verursachen
                'use_strict_mode' => true
            ];
            
            // Session-Einstellungen anwenden
            foreach ($sessionConfig as $option => $value) {
                if (ini_set('session.' . $option, (string)$value) === false) {
                    error_log("Konnte Session-Option {$option} nicht setzen");
                }
            }
            
            // Zufälliger Session-Name für bessere Sicherheit
            session_name('marques_' . substr(md5(MARQUES_ROOT_DIR), 0, 6));
            
            // Session-Start mit Fehlerbehandlung
            if (!session_start()) {
                error_log("Fehler beim Starten der Session");
                // Fallback: Cookies deaktivieren, damit die Anwendung noch funktioniert
                ini_set('session.use_cookies', '0');
            }
        }
    }

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
            // Fehler während der Initialisierung sollten nicht die Anwendung zum Absturz bringen
            error_log("Fehler in MarquesApp::init(): " . $e->getMessage());
            // Je nach Fehlertyp entsprechend reagieren
            if ($e->getCode() === 503) { // Wartungsmodus
                $this->displayMaintenancePage($e->getMessage() ?: "Die Website wird aktuell gewartet.");
                exit;
            }
        }
    }

    private function checkDirectAccess(): void {
        if (!defined('MARQUES_ROOT_DIR')) {
            header('HTTP/1.1 403 Forbidden');
            exit('Direkter Zugriff ist nicht erlaubt.');
        }
    }

    /**
     * Verbesserte Fehlerberichtskonfiguration mit Fallbacks
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
            // Bei Datenbankproblemen Standard-Fehlerbehandlung verwenden
            error_log("Fehler beim Lesen der Debug-Einstellung: " . $e->getMessage());
        }
        
        // Fehlerberichterstattung entsprechend konfigurieren
        error_reporting($debugSetting ? E_ALL : E_ERROR | E_WARNING | E_PARSE);
        ini_set('display_errors', $debugSetting ? '1' : '0');
        
        // Immer Fehlerprotokollierung aktivieren, unabhängig vom Debug-Modus
        ini_set('log_errors', '1');
        ini_set('error_log', MARQUES_ROOT_DIR . '/logs/php_error.log');
    }

    /**
     * Verbesserte Zeitzonen-Einstellung mit Validierung und Fallback
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
            
            // Überprüfen, ob die Zeitzone gültig ist
            if (!in_array($timezone, \DateTimeZone::listIdentifiers())) {
                error_log("Ungültige Zeitzone in Einstellungen: {$timezone}, verwende {$defaultTimezone}");
                $timezone = $defaultTimezone;
            }
            
            // Zeitzone setzen
            date_default_timezone_set($timezone);
        } catch (\Exception $e) {
            error_log("Fehler beim Setzen der Zeitzone: " . $e->getMessage());
            // Fallback auf UTC
            date_default_timezone_set($defaultTimezone);
        }
    }

    /**
     * Verbesserte Wartungsmodus-Überprüfung mit angepasster Anzeige
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
                                     : 'Die Website wird aktuell gewartet.';
                $this->displayMaintenancePage($maintenanceMessage);
                exit;
            }
        } catch (\Exception $e) {
            error_log("Fehler bei der Überprüfung des Wartungsmodus: " . $e->getMessage());
            // Bei Fehlern keinen Wartungsmodus annehmen
        }
    }

    /**
     * Zeigt eine ansprechende Wartungsmodus-Seite an
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
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Wartungsmodus - {$siteName}</title>
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
        <h1>Website wird gewartet</h1>
        <p>{$message}</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Verbesserte Benutzerprotokollierung mit Fallbacks
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
                error_log("Fehler bei der Benutzerprotokollierung: " . $e->getMessage());
                // Stille Fehlerbehandlung, Protokollierung sollte die Anwendung nicht beeinträchtigen
            }
        }
    }
    
    /**
     * Überprüft, ob der Benutzer ein Bot ist
     */
    private function isBot(): bool {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return preg_match('/(bot|crawler|spider|slurp|bingbot|googlebot)/i', $userAgent) === 1;
    }

    /**
     * Verbesserte IP-Anonymisierung mit korrekter IPv6-Handhabung
     */
    private function anonymizeIp(string $ip): string {
        if (empty($ip)) return '0.0.0.0';
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6: Letzte 64 Bits (letzten 4 Blöcke) auf Null setzen
            $blocks = explode(':', $ip);
            if (count($blocks) === 8) {
                // Normale IPv6-Adresse
                return implode(':', array_slice($blocks, 0, 4)) . ':0:0:0:0';
            } elseif (count($blocks) < 8 && strpos($ip, '::') !== false) {
                // Verkürzte IPv6-Adresse mit ::
                $expanded = str_replace('::', ':' . str_repeat('0:', 8 - count($blocks) + 1), $ip);
                $blocks = explode(':', $expanded);
                return implode(':', array_slice($blocks, 0, 4)) . ':0:0:0:0';
            }
            return $ip; // Fallback bei ungewöhnlichem Format
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // IPv4: Letztes Oktett auf Null setzen
            $parts = explode('.', $ip);
            return (count($parts) === 4) ? "{$parts[0]}.{$parts[1]}.{$parts[2]}.0" : $ip;
        }
        return $ip; // Unbekanntes Format beibehalten
    }

    /**
     * Verbesserte URL-Ermittlung mit Fehlerbehandlung
     */
    private function getCurrentUrl(): string {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return "{$protocol}://{$host}{$uri}";
    }

    /**
     * Verbesserte Hauptausführungsmethode mit detaillierter Fehlerbehandlung
     */
    public function run(): void {
        // Ausgabe-Pufferung starten für sauberere Fehlerbehandlung
        ob_start();
        
        try {
            // Session und Initialisierung
            $this->startSession();
            $this->init();
            
            // Event-Trigger vor Request-Verarbeitung
            $this->eventManager->trigger('before_request');
            
            // Aktuelle Anfrage auswerten
            $requestPath = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
            $normalizedPath = trim($requestPath, '/');
            $isRootRequest = $requestPath === '/' || empty($normalizedPath);
            
            // Pfad für Content-Verarbeitung vorbereiten
            $contentPath = $isRootRequest ? 'home' : $normalizedPath;
            error_log("Verarbeite Anfrage für Pfad: " . $contentPath);
            
            // Content-First Strategie: Versuche zuerst, die Seite direkt zu laden
            $directContentLoad = false;
            
            if ($isRootRequest || file_exists(MARQUES_CONTENT_DIR . '/pages/' . $contentPath . '.md')) {
                error_log("Versuche direktes Laden über Content für: " . $contentPath);
                try {
                    $pageData = $this->content->getPage($contentPath);
                    $directContentLoad = true;
                    $routeInfo = [
                        'path' => $contentPath,
                        'params' => []
                    ];
                    error_log("Seite direkt geladen: " . $contentPath);
                } catch (\Exception $contentEx) {
                    error_log("Fehler beim direkten Laden der Seite, fallback auf Routing: " . $contentEx->getMessage());
                    // Fehlgeschlagen, wir fallen zurück auf Routing
                }
            }
            
            // Nur Routing versuchen, wenn Content nicht direkt geladen wurde
            if (!$directContentLoad) {
                try {
                    $routeInfo = $this->router->processRequest();
                    $contentPath = $routeInfo['path'] ?? $contentPath;
                    $params = $routeInfo['params'] ?? [];
                    
                    error_log("Route gefunden, lade Seite: " . $contentPath);
                    $pageData = $this->content->getPage($contentPath, $params);
                } catch (\Exception $routeException) {
                    // Wenn sowohl Content-First als auch Routing fehlschlagen
                    error_log("Routing-Fehler: " . $routeException->getMessage());
                    throw $routeException;
                }
            }
            
            // Nach-Routing-Ereignis
            $routeInfo = $routeInfo ?? ['path' => $contentPath, 'params' => []];
            $this->eventManager->trigger('after_routing', $routeInfo);
            
            // Vor-Rendering-Ereignis
            $pageDataProcessed = $this->eventManager->trigger('before_render', $pageData);
            
            // Bei null oder undefinierten Rückgabewerten auf ursprüngliche Daten zurückfallen
            if ($pageDataProcessed === null || !is_array($pageDataProcessed)) {
                $pageDataProcessed = $pageData;
            }
            
            // Rendering
            $this->template->render($pageDataProcessed);

            // Nach-Rendering-Ereignis
            $this->eventManager->trigger('after_render');
        } catch (\Exception $e) {
            throw $e;
        } finally {
            // Ausgabe-Pufferung abschließen, sofern noch aktiv
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
        }
    }
    
    /**
     * Bestimmt Fehlertitel basierend auf HTTP-Status-Code
     */
    private function getErrorTitleForCode(int $code): string {
        switch ($code) {
            case 400: return 'Fehlerhafte Anfrage';
            case 401: return 'Nicht autorisiert';
            case 403: return 'Zugriff verweigert';
            case 404: return 'Seite nicht gefunden';
            case 500: return 'Interner Serverfehler';
            case 503: return 'Service nicht verfügbar';
            default: return 'Fehler ' . $code;
        }
    }
}