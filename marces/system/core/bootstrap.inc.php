<?php
/**
 * marces CMS - Bootstrap
 * 
 * Initialisiert die Systemumgebung, lädt notwendige Dateien
 * und richtet Autoloading ein.
 *
 * @package marces
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

// Fehlerberichterstattung einrichten
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Autoloading für Klassen einrichten
spl_autoload_register(function ($class) {
    // Namespace in Verzeichnisstruktur umwandeln
    $prefix = 'Marces\\';
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

// Zeitzone einrichten
date_default_timezone_set($system_config['timezone'] ?? 'UTC');