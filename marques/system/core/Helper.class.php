<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Helper-Klasse für das marques CMS
 *
 * Diese Klasse fasst sämtliche Utility-Funktionen zusammen.
 */
class Helper {
    /**
     * Cache für die Systemkonfiguration
     *
     * @var array|null
     */
    private static ?array $config = null;

    /**
     * Cache für das URL-Mapping
     *
     * @var array|null
     */
    private static ?array $urlMappingCache = null;

    /**
     * ConfigManager-Instanz
     *
     * @var ConfigManager|null
     */
    private static ?ConfigManager $configManager = null;

    /**
     * Lädt (oder gibt den bereits geladenen) Systemkonfiguration zurück.
     *
     * @param bool $forceReload Erzwingt das Neuladen der Konfiguration
     * @return array
     */
    public static function getConfig(bool $forceReload = false): array {
        if (self::$configManager === null) {
            self::$configManager = ConfigManager::getInstance();
        }

        if ($forceReload || self::$config === null) {
            self::$config = self::$configManager->load('system') ?: [];

            // Im Frontend: "/admin" aus der Base‑URL entfernen
            if (!defined('IS_ADMIN') && isset(self::$config['base_url']) && strpos(self::$config['base_url'], '/admin') !== false) {
                self::$config['base_url'] = preg_replace('|/admin$|', '', self::$config['base_url']);
            }
        }
        return self::$config;
    }

    /**
     * Lädt (oder gibt das bereits geladene) URL-Mapping zurück.
     *
     * @param bool $forceReload Erzwingt das Neuladen des Mappings
     * @return array
     */
    private static function getUrlMapping(bool $forceReload = false): array {
        if (self::$configManager === null) {
            self::$configManager = ConfigManager::getInstance();
        }

        if ($forceReload || self::$urlMappingCache === null) {
            self::$urlMappingCache = self::$configManager->loadUrlMapping() ?: [];
        }
        return self::$urlMappingCache;
    }

    /**
     * Gibt die Base‑URL zurück und passt sie je nach Kontext an.
     *
     * @param bool $isAdmin True, wenn im Admin-Bereich
     * @return string
     */
    public static function getBaseUrl(bool $isAdmin = false): string {
        $config = self::getConfig();
        $baseUrl = rtrim($config['base_url'] ?? '', '/');
        if ($isAdmin) {
            if (strpos($baseUrl, '/admin') === false) {
                $baseUrl .= '/admin';
            }
        } else {
            if (strpos($baseUrl, '/admin') !== false) {
                $baseUrl = preg_replace('|/admin$|', '', $baseUrl);
            }
        }
        return $baseUrl;
    }

    /**
     * Validiert einen Pfad anhand eines Regex.
     *
     * @param string $path
     * @return bool
     */
    public static function isValidPath(string $path): bool {
        return preg_match('/^[a-zA-Z0-9\-_\/]+$/', $path) === 1;
    }

    /**
     * Escaped einen String für die HTML-Ausgabe.
     *
     * @param string|null $string
     * @return string
     */
    public static function escapeHtml(?string $string): string {
        return $string === null ? '' : htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Gibt die vollständige Site-URL zurück, optional mit angehängtem Pfad.
     *
     * @param string $path
     * @return string
     */
    public static function getSiteUrl(string $path = ''): string {
        // Übergibt den Admin-Status an getBaseUrl
        $baseUrl = self::getBaseUrl(defined('IS_ADMIN'));
        if (!empty($path)) {
            $path = '/' . ltrim($path, '/');
        }
        return $baseUrl . $path;
    }

    /**
     * Formatiert ein Datum gemäß dem in der Konfiguration definierten Format.
     *
     * @param string $date
     * @param string|null $format
     * @return string
     */
    public static function formatDate(string $date, ?string $format = null): string {
        $config = self::getConfig();
        if ($format === null) {
            $format = $config['date_format'] ?? 'Y-m-d';
        }
        $timestamp = strtotime($date);
        return date($format, $timestamp);
    }

    /**
     * Erzeugt einen Slug aus einem String.
     *
     * @param string $string
     * @return string
     */
    public static function createSlug(string $string): string {
        if (function_exists('transliterator_transliterate')) {
            $string = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $string);
        } else {
            $string = strtolower($string);
        }
        $string = preg_replace('/[^a-z0-9]+/', '-', $string);
        return trim($string, '-');
    }

    /**
     * Debug-Funktion – gibt eine Variable formatiert aus, wenn Debug aktiviert ist.
     *
     * @param mixed $var
     * @param bool $die
     * @return void
     */
    public static function debug($var, bool $die = false): void {
        $config = self::getConfig();
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
     * Formatiert die Blog-URL basierend auf den Systemeinstellungen und Blog-Post-Daten.
     * Nutzt URL-Mapping, falls vorhanden.
     *
     * @param array $post Post-Daten (muss 'id', 'slug' und 'date' enthalten)
     * @return string Generierte URL
     */
    public static function formatBlogUrl(array $post): string {
        $urlMapping = self::getUrlMapping();
        $config = self::getConfig();
        $format = $config['blog_url_format'] ?? 'date_slash';
        $dateParts = explode('-', $post['date']);
        if (count($dateParts) !== 3) {
            return self::getSiteUrl('blog/' . $post['slug']);
        }
        [$year, $month, $day] = $dateParts;
        $slug = $post['slug'];
        $postId = $post['id']; // Interne Post-ID

        // URL-Mapping prüfen
        if (isset($urlMapping[$postId])) {
            return self::getSiteUrl($urlMapping[$postId]); // Gemappte URL verwenden
        }

        switch ($format) {
            case 'date_slash':
                return self::getSiteUrl("blog/{$year}/{$month}/{$day}/{$slug}");
            case 'date_dash':
                return self::getSiteUrl("blog/{$year}-{$month}-{$day}/{$slug}");
            case 'year_month':
                return self::getSiteUrl("blog/{$year}/{$month}/{$slug}");
            case 'numeric':
                if (isset($post['id']) && !empty($post['id'])) {
                    return self::getSiteUrl("blog/{$post['id']}");
                }
                return self::getSiteUrl("blog/{$year}{$month}{$day}-{$slug}");
            case 'post_name':
                return self::getSiteUrl("blog/{$slug}");
            default:
                return self::getSiteUrl("blog/{$year}/{$month}/{$day}/{$slug}");
        }
    }


    /**
     * Generiert die URL für einen Blogbeitrag basierend auf den Systemeinstellungen.
     * Nutzt URL-Mapping, falls vorhanden.
     *
     * @param array $post Post-Daten (muss 'id', 'slug' und 'date' enthalten)
     * @return string Generierte URL (z. B. "../blog/000-25C" oder "../blog/2025/03/15/mein-beitrag")
     */
    public static function generateBlogUrl(array $post): string {
        $urlMapping = self::getUrlMapping(); // URL-Mapping laden
        $configManager = ConfigManager::getInstance();
        $systemSettings = $configManager->load('system') ?: [];
        $blogUrlFormat = $systemSettings['blog_url_format'] ?? 'internal';

        $timestamp = strtotime($post['date']);
        if ($timestamp === false) {
            $timestamp = time();
        }

        $postId = $post['id']; // Interne Post-ID

        // URL-Mapping prüfen - zuerst nach interner ID suchen
        if (isset($urlMapping[$postId])) {
            return '../' . $urlMapping[$postId]; // Gemappte URL verwenden (relativ zum Root)
        }

        // Fallback: Generiere URL basierend auf blog_url_format (wenn kein Mapping gefunden)
        if ($blogUrlFormat === 'internal') {
            return '../blog/' . urlencode($post['id']);
        } elseif ($blogUrlFormat === 'post_name') {
            return '../blog/' . urlencode($post['slug']);
        } elseif ($blogUrlFormat === 'year_month') {
            $year = date('Y', $timestamp);
            $month = date('m', $timestamp);
            return "../blog/{$year}/{$month}/" . urlencode($post['slug']);
        } elseif ($blogUrlFormat === 'date_slash') {
            $year = date('Y', $timestamp);
            $month = date('m', $timestamp);
            $day = date('d', $timestamp);
            return "../blog/{$year}/{$month}/{$day}/" . urlencode($post['slug']);
        }
        return '../blog/' . urlencode($post['id']); // Fallback zu interner ID, falls Format unbekannt
    }


    /**
     * Gibt die URL zu einer Theme-Asset-Datei zurück.
     *
     * @param string $path Optionaler Pfad, der an die Theme-URL angehängt wird
     * @return string Die Theme-URL
     */
    public static function themeUrl(string $path = ''): string {
        static $themeManager = null;
        if ($themeManager === null) {
            $themeManager = new ThemeManager();
        }
        return $themeManager->getThemeAssetsUrl($path);
    }
}

/**
 * === Kompatibilitätsfunktionen ===
 * Die folgenden Funktionen rufen intern die statischen Methoden der Helper-Klasse auf.
 */

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