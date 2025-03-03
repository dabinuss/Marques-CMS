<?php
/**
 * marces CMS - Systemkonfiguration
 * 
 * Hauptkonfigurationsdatei des Systems.
 *
 * @package marces
 * @subpackage config
 */

// Direkten Zugriff verhindern
if (!defined('MARCES_ROOT_DIR')) {
    exit('Direkter Zugriff ist nicht erlaubt.');
}

return [
    // Grundlegende Website-Informationen
    'site_name' => 'marces CMS',
    'site_description' => 'Ein leichtgewichtiges, dateibasiertes CMS',
    'base_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                '://' . $_SERVER['HTTP_HOST'] .
                rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'),
    
    // Datums- und Zeiteinstellungen
    'timezone' => 'Europe/Berlin',
    'date_format' => 'd.m.Y',
    'time_format' => 'H:i',
    
    // Inhaltseinstellungen
    'posts_per_page' => 10,
    'excerpt_length' => 150,
    
    // Systemeinstellungen
    'debug' => true, // Im Produktiveinsatz auf false setzen
    'cache_enabled' => false, // Im Produktiveinsatz auf true setzen
    'version' => '0.1.0',
    
    // Admin-Einstellungen
    'admin_language' => 'de',
];