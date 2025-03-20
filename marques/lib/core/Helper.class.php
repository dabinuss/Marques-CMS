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
     * AppConfig-Instanz
     *
     * @var AppConfig|null
     */
    private static ?AppConfig $configManager = null;

    /**
     * Lädt (oder gibt den bereits geladenen) Systemkonfiguration zurück.
     *
     * @param bool $forceReload Erzwingt das Neuladen der Konfiguration
     * @return array
     */
    public static function getConfig(bool $forceReload = false): array {
        if (self::$configManager === null) {
            self::$configManager = AppConfig::getInstance();
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
            self::$configManager = AppConfig::getInstance();
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
            $converted = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $string);
            $string = $converted !== false ? $converted : strtolower($string);
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
        $urlMappings = self::getUrlMapping();
        $postId = $post['id'];
        
        // Suche nach dem Eintrag mit blog_post_id == $postId
        foreach ($urlMappings as $routeConfig) {
             if (isset($routeConfig['options']['blog_post_id']) && $routeConfig['options']['blog_post_id'] === $postId) {
                 return self::getSiteUrl($routeConfig['pattern']);
             }
        }
        
        // Fallback: Generiere den Pfad anhand der zentralen Logik
        return self::getSiteUrl(self::generateBlogUrlPath($post));
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

    /**
     * Formatiert eine Byte-Größe in ein menschenlesbares Format.
     *
     * @param int $bytes Die Größe in Bytes.
     * @param int $precision Anzahl der Dezimalstellen.
     * @return string Die formatierte Größe (z.B. "1.23 MB").
     */
    public static function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = ($bytes > 0) ? floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);
        $bytes /= (1024 ** $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Fügt einen GET-Parameter zur aktuellen URL hinzu oder aktualisiert ihn, wenn er schon existiert.
     *
     * @param string      $param Der Parameter-String, z. B. "menu=footer".
     * @param string|null $url   Optional: Eine Basis-URL. Standardmäßig wird $_SERVER['REQUEST_URI'] verwendet.
     *
     * @return string Die URL mit dem neuen bzw. aktualisierten Parameter.
     */
    public static function appQueryParam(string $param, ?string $url = null): string {
        // Nutzt REQUEST_URI, um den gesamten aktuellen Pfad inkl. Query-String zu erhalten.
        if ($url === null) {
            $url = $_SERVER['REQUEST_URI'];
        }

        // Zerlege die URL in ihre Bestandteile
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        // Bestehende Parameter extrahieren
        parse_str($query, $existingParams);

        // Neuen Parameter-String in Array umwandeln
        parse_str($param, $newParams);

        if (empty($newParams) && strpos($param, '=') !== false) {
            $parts = explode('=', $param, 2);
            $newParams[trim($parts[0])] = trim($parts[1]);
        }

        // Zusammenführen: Bereits vorhandene Werte werden durch neue überschrieben
        $mergedParams = array_merge($existingParams, $newParams);

        // Neuen Query-String erzeugen
        $newQuery = http_build_query($mergedParams);

        // URL wieder zusammensetzen
        $result = $path;
        if ($newQuery) {
            $result .= '?' . $newQuery;
        }
        if (isset($parts['fragment'])) {
            $result .= '#' . $parts['fragment'];
        }
        return $result;
    }

    public static function generateBlogUrlPath(array $post): string {
        $config = self::getConfig();
        $blogUrlFormat = $config['blog_url_format'] ?? 'post_name';
        $timestamp = strtotime($post['date']);
        if ($timestamp === false) {
            $timestamp = time();
        }
        switch ($blogUrlFormat) {
            case 'date_slash':
                return "blog/" . date('Y/m/d', $timestamp) . "/" . $post['slug'];
            case 'date_dash':
                return "blog/" . date('Y-m-d', $timestamp) . "/" . $post['slug'];
            case 'year_month':
                return "blog/" . date('Y/m', $timestamp) . "/" . $post['slug'];
            case 'numeric':
                return "blog/" . $post['id'];
            case 'post_name':
            default:
                return "blog/" . $post['slug'];
        }
    }
}
