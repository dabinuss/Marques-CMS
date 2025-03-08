<?php
declare(strict_types=1);

use Marques\Core\Helper;

// Direkter Zugriff verhindern
if (!defined('MARQUES_ROOT_DIR')) {
    exit('Direkter Zugriff ist nicht erlaubt.');
}

/**
 * Lädt die Systemkonfiguration.
 *
 * @param bool $refresh Ob die Konfiguration neu geladen werden soll
 * @return array
 */
function marques_get_config($refresh = false) {
    return Helper::getConfig((bool)$refresh);
}

/**
 * Gibt die Site-URL zurück.
 *
 * @param string $path Optionaler Pfad, der an die URL angehängt wird
 * @return string
 */
function marques_site_url($path = '') {
    return Helper::getSiteUrl($path);
}

/**
 * Gibt die URL zu einer Theme-Asset-Datei zurück.
 *
 * @param string $path Optionaler Pfad, der an die URL angehängt wird
 * @return string
 */
function marques_theme_url($path = '') {
    return Helper::themeUrl($path);
}

/**
 * Formatiert ein Datum.
 *
 * @param string $date
 * @param string $format Datumsformat (Standard: aus Konfiguration)
 * @return string
 */
function marques_format_date(string $date, string $format = 'Y-m-d'): string {
    return Helper::formatDate($date, $format);
}

/**
 * Escaped einen String für die HTML-Ausgabe.
 *
 * @param string|null $string
 * @return string
 */
function marques_escape_html($string) {
    return Helper::escapeHtml($string);
}

/**
 * Erstellt einen Slug aus einem String.
 *
 * @param string $string
 * @return string
 */
function marques_create_slug(string $string): string {
    return Helper::createSlug($string);
}

/**
 * Debug-Funktion (nur im Entwicklungsmodus).
 *
 * @param mixed $var Zu debuggende Variable
 * @param bool $die Ob nach der Ausgabe die Ausführung beendet werden soll
 * @return void
 */
function marques_debug($var, $die = false) {
    Helper::debug($var, (bool)$die);
}

/**
 * Generiert eine formatierte Blog-URL basierend auf den Systemeinstellungen.
 *
 * @param array $post Blog-Post-Daten
 * @return string Die formatierte Blog-URL
 */
function marques_format_blog_url($post) {
    return Helper::formatBlogUrl($post);
}

/**
 * Generiert die URL für einen Blogbeitrag basierend auf den Systemeinstellungen.
 *
 * @param array $post Post-Daten (muss 'id', 'slug' und 'date' enthalten)
 * @return string Generierte URL
 */
function marques_generate_blog_url($post) {
    return Helper::generateBlogUrl($post);
}

/**
 * [NEU] Generiert die URL für einen Blogbeitrag – Kompatibilitätsfunktion.
 *
 * Diese Funktion dient als Alias für marques_generate_blog_url(), damit
 * auch alte Aufrufe, die direkt generateBlogUrl() nutzen, weiterhin funktionieren.
 *
 * @param array|null $post Post-Daten (muss 'id', 'slug' und 'date' enthalten)
 * @return string Generierte URL oder leerer String bei ungültigen Parametern
 */
function generateBlogUrl($post = null) {
    if (!is_array($post)) {
        trigger_error('Parameter $post muss ein Array sein.', E_USER_WARNING);
        return '';
    }
    return Helper::generateBlogUrl($post);
}
