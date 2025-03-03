<?php
/**
 * marces CMS - Hilfsfunktionen
 * 
 * Hilfsfunktionen für das marces CMS.
 *
 * @package marces
 * @subpackage core
 */

// Direkten Zugriff verhindern
if (!defined('MARCES_ROOT_DIR')) {
    exit('Direkter Zugriff ist nicht erlaubt.');
}

/**
 * Lädt die Systemkonfiguration
 *
 * @param bool $refresh Ob die Konfiguration neu geladen werden soll
 * @return array Systemkonfiguration
 */
function marces_get_config($refresh = false) {
    static $config = null;
    
    // Config neu laden, wenn angefordert
    if ($refresh || $config === null) {
        $config = require MARCES_CONFIG_DIR . '/system.config.php';
        
        // Bei Bedarf base_url korrigieren
        if (!defined('IS_ADMIN') && isset($config['base_url']) && strpos($config['base_url'], '/admin') !== false) {
            $config['base_url'] = preg_replace('|/admin$|', '', $config['base_url']);
        }
    }
    
    return $config;
}

/**
 * Gibt die Site-URL zurück
 *
 * @param string $path Optionaler Pfad, der an die URL angehängt wird
 * @return string Die Site-URL
 */
function marces_site_url($path = '') {
    $config = marces_get_config();
    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    
    // Explizite Admin-URL-Erzeugung für Admin-Bereich
    if (defined('IS_ADMIN')) {
        // Stelle sicher, dass /admin am Ende der URL steht
        if (strpos($baseUrl, '/admin') === false) {
            $baseUrl .= '/admin';
        }
    } else {
        // Im Frontend: Entferne /admin, falls vorhanden
        if (strpos($baseUrl, '/admin') !== false) {
            $baseUrl = preg_replace('|/admin$|', '', $baseUrl);
        }
    }
    
    // Pfad anfügen
    if (!empty($path)) {
        $path = '/' . ltrim($path, '/');
    }
    
    return $baseUrl . $path;
}

/**
 * Gibt die Asset-URL zurück
 *
 * @param string $path Pfad zum Asset
 * @return string Die Asset-URL
 */
function marces_asset_url($path) {
    $config = marces_get_config();
    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    
    // Entferne /admin aus der base_url für Frontend-Assets
    if (strpos($baseUrl, '/admin') !== false && !defined('IS_ADMIN')) {
        $baseUrl = preg_replace('|/admin$|', '', $baseUrl);
    }
    
    // Verwende den richtigen Assets-Pfad je nach Kontext
    if (defined('IS_ADMIN')) {
        // Im Admin-Bereich: Stelle sicher, dass /admin im Pfad ist
        if (strpos($baseUrl, '/admin') === false) {
            $baseUrl .= '/admin';
        }
        return $baseUrl . '/assets/' . ltrim($path, '/');
    } else {
        // Im Frontend: Verwende den /assets/-Pfad
        return $baseUrl . '/assets/' . ltrim($path, '/');
    }
}

/**
 * Formatiert ein Datum
 *
 * @param string $date Datums-String
 * @param string $format Datumsformat (Standard: Systemkonfiguration)
 * @return string Formatiertes Datum
 */
function marces_format_date($date, $format = null) {
    $config = marces_get_config();
    
    if ($format === null) {
        $format = $config['date_format'] ?? 'Y-m-d';
    }
    
    $timestamp = strtotime($date);
    return date($format, $timestamp);
}

/**
 * Escape HTML
 *
 * @param string $string Zu escaptender String
 * @return string Escapeter String
 */
function marces_escape_html($string) {
    // NULL-Werte abfangen, um Deprecated-Warnungen zu vermeiden
    if ($string === null) {
        return '';
    }
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Erstellt einen Slug aus einem String
 *
 * @param string $string String, der in einen Slug umgewandelt werden soll
 * @return string Slug
 */
function marces_create_slug($string) {
    // Nicht-lateinische Zeichen transliterieren
    if (function_exists('transliterator_transliterate')) {
        $string = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $string);
    } else {
        $string = strtolower($string);
    }
    
    // Nicht-alphanumerische Zeichen durch Bindestriche ersetzen
    $string = preg_replace('/[^a-z0-9]+/', '-', strtolower($string));
    
    // Führende/nachfolgende Bindestriche entfernen
    return trim($string, '-');
}

/**
 * Debug-Funktion (nur im Entwicklungsmodus)
 *
 * @param mixed $var Zu debuggende Variable
 * @param bool $die Ob nach der Ausgabe beendet werden soll
 * @return void
 */
function marces_debug($var, $die = false) {
    $config = require MARCES_CONFIG_DIR . '/system.config.php';
    
    if (($config['debug'] ?? false) === true) {
        echo '<pre>';
        print_r($var);
        echo '</pre>';
        
        if ($die) {
            die();
        }
    }
}

/**
 * Generiert eine formatierte Blog-URL basierend auf den Systemeinstellungen
 *
 * @param array $post Blog-Post-Daten
 * @return string Die formatierte Blog-URL
 */
function marces_format_blog_url($post) {
    // Konfiguration laden
    $config = require MARCES_CONFIG_DIR . '/system.config.php';
    $format = $config['blog_url_format'] ?? 'date_slash';
    
    // Datum und Slug extrahieren
    $dateParts = explode('-', $post['date']);
    if (count($dateParts) !== 3) {
        // Fallback wenn Datum ungültig ist
        return marces_site_url('blog/' . $post['slug']);
    }
    
    $year = $dateParts[0];
    $month = $dateParts[1];
    $day = $dateParts[2];
    $slug = $post['slug'];
    
    // URL basierend auf Format generieren
    switch ($format) {
        case 'date_slash':
            // Format: blog/YYYY/MM/DD/slug
            return marces_site_url("blog/{$year}/{$month}/{$day}/{$slug}");
            
        case 'date_dash':
            // Format: blog/YYYY-MM-DD/slug
            return marces_site_url("blog/{$year}-{$month}-{$day}/{$slug}");
            
        case 'year_month':
            // Format: blog/YYYY/MM/slug
            return marces_site_url("blog/{$year}/{$month}/{$slug}");
            
        case 'numeric':
            // Format: blog/ID (falls ID vorhanden, sonst Fallback)
            if (isset($post['id']) && !empty($post['id'])) {
                return marces_site_url("blog/{$post['id']}");
            }
            return marces_site_url("blog/{$year}{$month}{$day}-{$slug}");
            
        case 'post_name':
            // Format: blog/slug
            return marces_site_url("blog/{$slug}");
            
        default:
            // Standard-Format
            return marces_site_url("blog/{$year}/{$month}/{$day}/{$slug}");
    }
}