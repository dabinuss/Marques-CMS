<?php
declare(strict_types=1);

/**
 * marques CMS - Bootstrap
 * 
 * Initialisiert die Systemumgebung, lädt notwendige Dateien
 * und richtet Autoloading ein.
 *
 * @package marques
 * @subpackage core
 */

// Session starten
session_start();

// Direkten Zugriff verhindern
if (!defined('MARQUES_ROOT_DIR')) {
    exit('Direkter Zugriff ist nicht erlaubt.');
}

// Konstanten definieren
define('MARQUES_VERSION', '0.3.0'); // FALLBACK
define('MARQUES_SYSTEM_DIR', MARQUES_ROOT_DIR . '/system');
define('MARQUES_CONFIG_DIR', MARQUES_ROOT_DIR . '/config');
define('MARQUES_CONTENT_DIR', MARQUES_ROOT_DIR . '/content');
define('MARQUES_TEMPLATE_DIR', MARQUES_ROOT_DIR . '/templates'); /* DEPRECIATED */
define('MARQUES_CACHE_DIR', MARQUES_SYSTEM_DIR . '/cache');
define('MARQUES_ADMIN_DIR', MARQUES_ROOT_DIR . '/admin');
define('MARQUES_THEMES_DIR', MARQUES_ROOT_DIR . '/themes');

// Temporäre Fehleranzeige für die Entwicklung
error_reporting(E_ALL);
ini_set('display_errors', 1);

// PSR-4 konformes Autoloading
spl_autoload_register(function ($class) {
    // Prüfen, ob die Klasse im Marques-Namespace ist
    if (strpos($class, 'Marques\\') !== 0) {
        return;
    }
    
    // Namespace in Dateipfad umwandeln
    $relativeClass = substr($class, 8); // 'Marques\' entfernen
    
    // Klassennamen ohne Namespace extrahieren (z.B. 'Core\BlogManager' -> 'BlogManager')
    $parts = explode('\\', $relativeClass);
    $className = array_pop($parts);
    
    // Namespace-Pfad in Kleinbuchstaben (z.B. 'Core\BlogManager' -> 'core/')
    $namespacePath = strtolower(implode('/', $parts));
    
    // Primär: PascalCase mit .class.php (neue Namenskonvention)
    $path1 = MARQUES_SYSTEM_DIR . '/' . $namespacePath . '/' . $className . '.class.php';
    
    // Fallback 1: Kleinbuchstaben mit .class.php (alte Konvention)
    $path2 = MARQUES_SYSTEM_DIR . '/' . $namespacePath . '/' . strtolower($className) . '.class.php';
    
    // Fallback 2: kebab-case mit .class.php (alte Konvention)
    $kebabClassName = preg_replace('/(?<!^)[A-Z]/', '-$0', $className);
    $kebabClassName = strtolower($kebabClassName);
    $path3 = MARQUES_SYSTEM_DIR . '/' . $namespacePath . '/' . $kebabClassName . '.class.php';
    
    // Fallback 3: PascalCase mit .php (zukünftige Konvention)
    $path4 = MARQUES_SYSTEM_DIR . '/' . $namespacePath . '/' . $className . '.php';
    
    // Datei laden, wenn eine der Varianten existiert
    foreach ([$path1, $path2, $path3, $path4] as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

// Hilfsfunktionen laden
//require_once MARQUES_SYSTEM_DIR . '/core/Utilities.inc.php';

// Benutzerdefinierte Exceptions laden
require_once MARQUES_SYSTEM_DIR . '/core/Exceptions.inc.php';

// AppContainer und Event-Manager initialisieren
$appcontainer = new \Marques\Core\AppContainer();

$configManager = \Marques\Core\AppConfig::getInstance();
$systemConfig = $configManager->load('system') ?: [];
$appcontainer->register('config', $systemConfig);

$appcontainer->register(\Marques\Core\EventManager::class, new \Marques\Core\EventManager());
$appcontainer->register(\Marques\Core\Logger::class, new \Marques\Core\AppLogger());
$appcontainer->register(\Marques\Core\AppSettings::class);
$appcontainer->register(\Marques\Core\User::class);

// Global verfügbar machen
$GLOBALS['appcontainer'] = $appcontainer;
$GLOBALS['events'] = $appcontainer->get(\Marques\Core\EventManager::class);

// Systemeinstellungen laden
$settings = $appcontainer->get(\Marques\Core\AppSettings::class);

// Fehlerberichterstattung einrichten
if ($settings->getSetting('debug', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Zeitzone einrichten
date_default_timezone_set($settings->getSetting('timezone', 'UTC'));

// Wartungsmodus prüfen (außer für Admin-Bereich)
if (!defined('IS_ADMIN') && $settings->getSetting('maintenance_mode', false)) {
    $maintenance_message = $settings->getSetting('maintenance_message', 'Die Website wird aktuell gewartet. Bitte versuchen Sie es später erneut.');
    
    // Prüfen, ob der Benutzer ein Administrator ist
    $user = new \Marques\Core\User();
    if (!$user->isAdmin()) {
        // Wartungsmodus-Seite anzeigen
        header('HTTP/1.1 503 Service Temporarily Unavailable');
        header('Status: 503 Service Temporarily Unavailable');
        header('Retry-After: 3600'); // Eine Stunde
        
        echo '<!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Wartungsmodus - ' . htmlspecialchars($settings->getSetting('site_name', 'marques CMS')) . '</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                    background-color: #f8f9fa;
                    color: #212529;
                    margin: 0;
                    padding: 0;
                    display: flex;
                    height: 100vh;
                    align-items: center;
                    justify-content: center;
                }
                .maintenance-container {
                    text-align: center;
                    max-width: 600px;
                    padding: 2rem;
                    background-color: white;
                    border-radius: 0.5rem;
                    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                }
                h1 {
                    color: #343a40;
                    margin-top: 0;
                }
                p {
                    font-size: 1.1rem;
                    line-height: 1.6;
                    color: #6c757d;
                }
                .icon {
                    font-size: 4rem;
                    margin-bottom: 1rem;
                    color: #007bff;
                }
            </style>
        </head>
        <body>
            <div class="maintenance-container">
                <div class="icon">⚙️</div>
                <h1>Website wird gewartet</h1>
                <p>' . htmlspecialchars($maintenance_message) . '</p>
            </div>
        </body>
        </html>';
        exit;
    }
}

// Nur Seitenaufrufe von echten Benutzern erfassen (keine Bots, keine Admin-Besuche)
if (!defined('IS_ADMIN') && !preg_match('/(bot|crawler|spider|slurp|bingbot|googlebot)/i', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
    // Statistikverzeichnis prüfen/erstellen
    $statsDir = __DIR__ . '/../logs/stats';
    if (!is_dir($statsDir)) {
        @mkdir($statsDir, 0755, true);
    }
    
    // Daten für die Statistik sammeln
    $logData = [
        'time' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}",
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'referrer' => $_SERVER['HTTP_REFERER'] ?? ''
    ];
    
    // Anonymisierte IP (DSGVO-konform)
    $parts = explode('.', $logData['ip']);
    if (count($parts) === 4) {
        $logData['ip'] = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
    }
    
    // Logdatei für den aktuellen Tag
    $logFile = $statsDir . '/' . date('Y-m-d') . '.log';
    
    // In die Logdatei schreiben
    @file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);
}

// Hilfsfunktionen für das Theme-System
function marques_init_default_theme() {
    $defaultThemePath = MARQUES_THEMES_DIR . '/default';
    
    if (!is_dir($defaultThemePath)) {
        // Verzeichnisse erstellen
        mkdir($defaultThemePath . '/assets', 0755, true);
        mkdir($defaultThemePath . '/templates', 0755, true);
        
        // Minimale theme.json
        $themeData = [
            'name' => 'Default Theme',
            'version' => '1.0.0',
            'author' => 'marques CMS'
        ];
        
        file_put_contents(
            $defaultThemePath . '/theme.json',
            json_encode($themeData, JSON_PRETTY_PRINT)
        );
        
        // Kopiere die bestehenden Templates ins neue Theme-Verzeichnis
        if (is_dir(MARQUES_TEMPLATE_DIR)) {
            $templateFiles = glob(MARQUES_TEMPLATE_DIR . '/*.tpl.php');
            foreach ($templateFiles as $templateFile) {
                $fileName = basename($templateFile);
                copy($templateFile, $defaultThemePath . '/templates/' . $fileName);
            }
            
            // Partials-Verzeichnis
            if (is_dir(MARQUES_TEMPLATE_DIR . '/partials')) {
                mkdir($defaultThemePath . '/templates/partials', 0755, true);
                $partialFiles = glob(MARQUES_TEMPLATE_DIR . '/partials/*.tpl.php');
                foreach ($partialFiles as $partialFile) {
                    $fileName = basename($partialFile);
                    copy($partialFile, $defaultThemePath . '/templates/partials/' . $fileName);
                }
            }
        }
        
        // Kopiere die bestehenden Assets ins neue Theme-Verzeichnis
        if (is_dir(MARQUES_ROOT_DIR . '/assets')) {
            // CSS-Dateien
            $cssFiles = glob(MARQUES_ROOT_DIR . '/assets/css/*.css');
            if (!empty($cssFiles)) {
                mkdir($defaultThemePath . '/assets/css', 0755, true);
                foreach ($cssFiles as $cssFile) {
                    $fileName = basename($cssFile);
                    copy($cssFile, $defaultThemePath . '/assets/css/' . $fileName);
                }
            }
            
            // JavaScript-Dateien
            $jsFiles = glob(MARQUES_ROOT_DIR . '/assets/js/*.js');
            if (!empty($jsFiles)) {
                mkdir($defaultThemePath . '/assets/js', 0755, true);
                foreach ($jsFiles as $jsFile) {
                    $fileName = basename($jsFile);
                    copy($jsFile, $defaultThemePath . '/assets/js/' . $fileName);
                }
            }
            
            // Bilder
            $imgFiles = glob(MARQUES_ROOT_DIR . '/assets/images/*.*');
            if (!empty($imgFiles)) {
                mkdir($defaultThemePath . '/assets/images', 0755, true);
                foreach ($imgFiles as $imgFile) {
                    $fileName = basename($imgFile);
                    copy($imgFile, $defaultThemePath . '/assets/images/' . $fileName);
                }
            }
        }
    }
}

// Alias-Funktion für Abwärtskompatibilität
function marques_asset_url($path = '') {
    return marques_theme_url($path);
}

// Default-Theme initialisieren
marques_init_default_theme();

// AppConfig initialisieren (einmalig, da Singleton)
$configManager = \Marques\Core\AppConfig::getInstance();