<?php
/**
 * marques CMS - Systemkonfiguration
 * 
 * Hauptkonfigurationsdatei des Systems.
 *
 * @package marques
 * @subpackage config
 */

// Direkten Zugriff verhindern
if (!defined('MARQUES_ROOT_DIR')) {
    exit('Direkter Zugriff ist nicht erlaubt.');
}

// Bestimme die Basis-URL einmalig und korrekt
$script_path = dirname($_SERVER['SCRIPT_NAME']);
// Entferne /admin vom Pfad, falls vorhanden
if (strpos($script_path, '/admin') !== false) {
    $script_path = preg_replace('|/admin$|', '', $script_path);
}

$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
            '://' . $_SERVER['HTTP_HOST'] .
            rtrim($script_path, '/');

return [
    // Grundlegende Website-Informationen
    'site_name' => 'marques CMS',
    'site_description' => 'Ein leichtgewichtiges, dateibasiertes CMS',
    'site_logo' => '',
    'site_favicon' => '',
    'base_url' => $base_url, // Verwende die vorberechnete URL ohne /admin
    
    // Datums- und Zeiteinstellungen
    'timezone' => 'Europe/Berlin',
    'date_format' => 'd.m.Y',
    'time_format' => 'H:i',
    
    // Inhaltseinstellungen
    'posts_per_page' => 10,
    'excerpt_length' => 150,
    'blog_url_format' => 'date_slash', // Mögliche Werte: date_slash, date_dash, year_month, numeric, post_name
    
    // Systemeinstellungen
    'debug' => true, // Im Produktiveinsatz auf false setzen
    'cache_enabled' => false, // Im Produktiveinsatz auf true setzen
    'version' => '0.3.0', // Phase 3 der Entwicklung
    
    // Theme-Einstellungen
    'active_theme' => 'default', // Standard-Theme
    'themes_path' => MARQUES_THEMES_DIR, // Pfad zum Themes-Verzeichnis
    
    // Admin-Einstellungen
    'admin_language' => 'de',

    'security' => [
        'max_login_attempts' => 6,
        'login_attempt_window' => 600, // 10 Minuten
        'login_block_duration' => 600  // 10 Minuten Sperrzeit
    ]
];