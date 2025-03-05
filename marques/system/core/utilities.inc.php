<?php
declare(strict_types=1);

/**
 * marques CMS - Hilfsfunktionen
 * 
 * Hilfsfunktionen für das marques CMS.
 *
 * @package marques
 * @subpackage core
 */

// Direkten Zugriff verhindern
if (!defined('MARQUES_ROOT_DIR')) {
    exit('Direkter Zugriff ist nicht erlaubt.');
}

/**
 * Lädt die Systemkonfiguration
 *
 * @param bool $refresh Ob die Konfiguration neu geladen werden soll
 * @return array Systemkonfiguration
 */
function marques_get_config($refresh = false) {
    static $config = null;
    
    // Config neu laden, wenn angefordert
    if ($refresh || $config === null) {
        $configManager = \Marques\Core\ConfigManager::getInstance();
        $config = $configManager->load('system');
        
        if (!$config) {
            // Fallback zu Standard-Einstellungen
            $settingsManager = new \Marques\Core\SettingsManager();
            $config = $settingsManager->getDefaultSettings();
        }
        
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
function marques_site_url($path = '') {
    $config = marques_get_config();
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
 * Gibt die URL zu einer Theme-Asset-Datei zurück
 */
function marques_theme_url($path = '') {
    static $themeManager = null;
    if ($themeManager === null) {
        $themeManager = new \Marques\Core\ThemeManager();
    }
    return $themeManager->getThemeAssetsUrl($path);
}

/**
 * Formatiert ein Datum
 *
 * @param string $date Datums-String
 * @param string $format Datumsformat (Standard: Systemkonfiguration)
 * @return string Formatiertes Datum
 */
function marques_format_date(string $date, string $format = 'Y-m-d'): string {
    $config = marques_get_config();
    
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
function marques_escape_html($string) {
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
function marques_create_slug(string $string): string {
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
function marques_debug($var, $die = false) {
    $configManager = \Marques\Core\ConfigManager::getInstance();
    $config = $configManager->load('system') ?: [];
    
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
function marques_format_blog_url($post) {
    // Konfiguration laden mit ConfigManager
    $configManager = \Marques\Core\ConfigManager::getInstance();
    $config = $configManager->load('system') ?: [];
    $format = $config['blog_url_format'] ?? 'date_slash';
    
    // Datum und Slug extrahieren
    $dateParts = explode('-', $post['date']);
    if (count($dateParts) !== 3) {
        // Fallback wenn Datum ungültig ist
        return marques_site_url('blog/' . $post['slug']);
    }
    
    $year = $dateParts[0];
    $month = $dateParts[1];
    $day = $dateParts[2];
    $slug = $post['slug'];
    
    // URL basierend auf Format generieren
    switch ($format) {
        case 'date_slash':
            // Format: blog/YYYY/MM/DD/slug
            return marques_site_url("blog/{$year}/{$month}/{$day}/{$slug}");
            
        case 'date_dash':
            // Format: blog/YYYY-MM-DD/slug
            return marques_site_url("blog/{$year}-{$month}-{$day}/{$slug}");
            
        case 'year_month':
            // Format: blog/YYYY/MM/slug
            return marques_site_url("blog/{$year}/{$month}/{$slug}");
            
        case 'numeric':
            // Format: blog/ID (falls ID vorhanden, sonst Fallback)
            if (isset($post['id']) && !empty($post['id'])) {
                return marques_site_url("blog/{$post['id']}");
            }
            return marques_site_url("blog/{$year}{$month}{$day}-{$slug}");
            
        case 'post_name':
            // Format: blog/slug
            return marques_site_url("blog/{$slug}");
            
        default:
            // Standard-Format
            return marques_site_url("blog/{$year}/{$month}/{$day}/{$slug}");
    }
}