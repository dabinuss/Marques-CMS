<?php
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
if (!defined('MARCES_ROOT_DIR')) {
    exit('Direkter Zugriff ist nicht erlaubt.');
}

// Konstanten definieren
define('MARCES_VERSION', '0.1.0');
define('MARCES_SYSTEM_DIR', MARCES_ROOT_DIR . '/system');
define('MARCES_CONFIG_DIR', MARCES_ROOT_DIR . '/config');
define('MARCES_CONTENT_DIR', MARCES_ROOT_DIR . '/content');
define('MARCES_TEMPLATE_DIR', MARCES_ROOT_DIR . '/templates');
define('MARCES_CACHE_DIR', MARCES_SYSTEM_DIR . '/cache');
define('MARCES_ADMIN_DIR', MARCES_ROOT_DIR . '/admin');

// Temporäre Fehleranzeige für die Entwicklung
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Autoloading für Klassen einrichten
spl_autoload_register(function ($class) {
    // Namespace in Verzeichnisstruktur umwandeln
    $prefix = 'Marques\\';
    $base_dir = MARCES_SYSTEM_DIR . '/';
    
    // Prüfen, ob die Klasse das Präfix verwendet
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    // Relativen Klassennamen holen
    $relative_class = substr($class, $len);
    
    // Namespace-Separatoren in Verzeichnisseparatoren umwandeln
    $file = $base_dir . str_replace('\\', '/', strtolower($relative_class)) . '.class.php';
    
    // Wenn die Datei nicht existiert, versuche es mit dem kebab-case-Format
    if (!file_exists($file)) {
        $parts = explode('\\', $relative_class);
        $class_name = array_pop($parts);
        $directory = strtolower(implode('/', $parts));
        
        // PascalCase in kebab-case umwandeln
        $file_name = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $class_name));
        
        // Vollständigen Dateipfad erstellen
        $file = $base_dir . $directory . '/' . $file_name . '.class.php';
    }
    
    // Prüfen, ob die Datei existiert
    if (file_exists($file)) {
        require_once $file;
    }
});

// Hilfsfunktionen laden
require_once MARCES_SYSTEM_DIR . '/core/utilities.inc.php';

// Benutzerdefinierte Exceptions laden
require_once MARCES_SYSTEM_DIR . '/core/exceptions.inc.php';

// Konfiguration laden
$system_config = require MARCES_CONFIG_DIR . '/system.config.php';

// JETZT den SettingsManager verwenden, nachdem Autoloading eingerichtet ist
$settings = new \Marques\Core\SettingsManager();

// Fehlerberichterstattung einrichten basierend auf Debug-Einstellung
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

// ConfigManager initialisieren (einmalig, da Singleton)
$configManager = \Marques\Core\ConfigManager::getInstance();