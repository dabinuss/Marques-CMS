<?php
declare(strict_types=1);

namespace Marques\Util;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Service\ThemeManager;

class Helper {

    private array $config = [];
    private ?array $urlMappingCache = null;
    private DatabaseHandler $dbHandler;
    private ?ThemeManager $themeManager;

    /**
     * Konstruktor – alle Abhängigkeiten werden via DI bereitgestellt.
     *
     * @param DatabaseHandler $dbHandler
     * @param ThemeManager|null $themeManager (Optional, falls benötigt)
     */
    public function __construct(DatabaseHandler $dbHandler, ?ThemeManager $themeManager = null) {
        $this->dbHandler = $dbHandler;
        $this->themeManager = $themeManager;
        
        // Konfiguration aus der settings-Tabelle laden (nur ein Datensatz mit id=1)
        $settingsRecord = $dbHandler->table('settings')
                                    ->where('id', '=', 1)
                                    ->first();
        $this->config = is_array($settingsRecord) ? $settingsRecord : [];
    
        // Falls Base-URL im Frontend angepasst werden muss:
        if (!defined('IS_ADMIN') && isset($this->config['base_url']) && strpos($this->config['base_url'], '/admin') !== false) {
            $this->config['base_url'] = preg_replace('|/admin$|', '', $this->config['base_url']);
        }
    }

    /**
     * Gibt die Systemkonfiguration zurück. Kann optional neu geladen werden.
     *
     * @param bool $forceReload
     * @return array
     */
    public function getConfig(bool $forceReload = false): array {
        if ($forceReload || empty($this->config)) {
            $settingsRecord = $this->dbHandler->table('settings')
                                              ->where('id', '=', 1)
                                              ->first();
            $this->config = is_array($settingsRecord) ? $settingsRecord : [];
            if (!defined('IS_ADMIN') && isset($this->config['base_url']) && strpos($this->config['base_url'], '/admin') !== false) {
                $this->config['base_url'] = preg_replace('|/admin$|', '', $this->config['base_url']);
            }
        }
        return $this->config;
    }    

    /**
     * Lädt das URL-Mapping aus der "urlmapping"-Tabelle.
     *
     * @param bool $forceReload
     * @return array
     */
    public function getUrlMapping(bool $forceReload = false): array {
        if ($forceReload || $this->urlMappingCache === null) {
            $mappingHandler = $this->dbHandler->table('urlmapping');
            $this->urlMappingCache = $mappingHandler->find();
        }
        return $this->urlMappingCache;
    }    

    /**
     * Gibt die Base‑URL zurück, angepasst je nach Admin-/Frontend-Kontext.
     *
     * @param bool $isAdmin
     * @return string
     */
    public function getBaseUrl(bool $isAdmin = false): string {
        $config = $this->getConfig();
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
    public function getSiteUrl(string $path = ''): string {
        $baseUrl = $this->getBaseUrl(defined('IS_ADMIN'));
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
    public function formatDate(string $date, ?string $format = null): string {
        $config = $this->getConfig();
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
    public function createSlug(string $string): string {
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
    public function debug($var, bool $die = false): void {
        $config = $this->getConfig();
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
     * Formatiert die Blog-URL basierend auf den Systemeinstellungen und Post-Daten.
     *
     * @param array $post
     * @return string
     */
    public function formatBlogUrl(array $post): string {
        $urlMappings = $this->getUrlMapping();
        $postId = $post['id'] ?? null;
        if ($postId !== null) {
            foreach ($urlMappings as $routeConfig) {
                if (isset($routeConfig['options']['blog_post_id']) && $routeConfig['options']['blog_post_id'] === $postId) {
                    return $this->getSiteUrl($routeConfig['pattern']);
                }
            }
        }
        return $this->getSiteUrl($this->generateBlogUrlPath($post));
    }

    /**
     * Gibt die URL zu einer Theme-Asset-Datei zurück.
     *
     * @param string $path Optionaler Pfad, der an die Theme-URL angehängt wird
     * @return string
     * @throws \Exception Falls kein ThemeManager verfügbar ist.
     */
    public function themeUrl(string $path = ''): string {
        if ($this->themeManager === null) {
            throw new \Exception("ThemeManager ist in Helper nicht verfügbar.");
        }
        return $this->themeManager->getThemeAssetsUrl($path);
    }

    /**
     * Formatiert eine Byte-Größe in ein menschenlesbares Format.
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    public function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = ($bytes > 0) ? floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);
        $bytes /= (1024 ** $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Fügt einen GET-Parameter zur aktuellen URL hinzu oder aktualisiert ihn.
     *
     * @param string $param
     * @param string|null $url
     * @return string
     */
    public function appQueryParam(string $param, ?string $url = null): string {
        if ($url === null) {
            $url = $_SERVER['REQUEST_URI'] ?? '';
        }
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';
        parse_str($query, $existingParams);
        parse_str($param, $newParams);
        if (empty($newParams) && strpos($param, '=') !== false) {
            $partsParam = explode('=', $param, 2);
            $newParams[trim($partsParam[0])] = trim($partsParam[1]);
        }
        $mergedParams = array_merge($existingParams, $newParams);
        $newQuery = http_build_query($mergedParams);
        $result = $path;
        if ($newQuery) {
            $result .= '?' . $newQuery;
        }
        if (isset($parts['fragment'])) {
            $result .= '#' . $parts['fragment'];
        }
        return $result;
    }

    /**
     * Generiert den Blog-URL-Pfad basierend auf den Post-Daten und Systemeinstellungen.
     *
     * @param array $post
     * @return string
     */
    public function generateBlogUrlPath(array $post): string {
        $config = $this->getConfig();
        $blogUrlFormat = $config['blog_url_format'] ?? 'post_name';
        $timestamp = strtotime($post['date'] ?? '');
        if ($timestamp === false) {
            $timestamp = time();
        }
        switch ($blogUrlFormat) {
            case 'date_slash':
                return "blog/" . date('Y/m/d', $timestamp) . "/" . ($post['slug'] ?? '');
            case 'date_dash':
                return "blog/" . date('Y-m-d', $timestamp) . "/" . ($post['slug'] ?? '');
            case 'year_month':
                return "blog/" . date('Y/m', $timestamp) . "/" . ($post['slug'] ?? '');
            case 'numeric':
                return "blog/" . ($post['id'] ?? '');
            case 'post_name':
            default:
                return "blog/" . ($post['slug'] ?? '');
        }
    }
}
