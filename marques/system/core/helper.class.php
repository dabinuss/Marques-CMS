<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Helper-Klasse für das marques CMS
 */
class Helper extends Core { // Nicht mehr statisch!

    private  $config = null;
    private  $urlMappingCache = null;
    private  $configManager;

    public function __construct(Docker $docker) {
        parent::__construct($docker);
        $this->configManager = $this->resolve('config');
    }

    public  function getConfig(bool $forceReload = false): array {
        if ($forceReload || $this->config === null) {
            $this->config = $this->configManager->load('system') ?: [];
            if (!defined('IS_ADMIN') && isset($this->config['base_url']) && strpos($this->config['base_url'], '/admin') !== false) {
                $this->config['base_url'] = preg_replace('|/admin$|', '', $this->config['base_url']);
            }
        }
        return $this->config;
    }

    private  function getUrlMapping(bool $forceReload = false): array {
        if ($forceReload || $this->urlMappingCache === null) {
            $this->urlMappingCache = $this->configManager->loadUrlMapping() ?: [];
        }
        return $this->urlMappingCache;
    }

    public  function getBaseUrl(bool $isAdmin = false): string {
        $config = $this->getConfig();
        $baseUrl = rtrim($config['base_url'] ?? '', '/');
         // Füge /admin nur hinzu, wenn es NICHT bereits vorhanden ist UND wir im Admin-Bereich sind.
        if ($isAdmin && strpos($baseUrl, '/admin') === false) {
                $baseUrl .= '/admin';
        }
        return $baseUrl;
    }

    public  function isValidPath(string $path): bool {
        return preg_match('/^[a-zA-Z0-9\-_\/]+$/', $path) === 1;
    }

    public  function escapeHtml(?string $string): string {
        return $string === null ? '' : htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }

    public  function getSiteUrl(string $path = ''): string {
        $baseUrl = $this->getBaseUrl(defined('IS_ADMIN'));
        if (!empty($path)) {
            $path = '/' . ltrim($path, '/');
        }
        return $baseUrl . $path;
    }

    public  function formatDate(string $date, ?string $format = null): string {
        $config = $this->getConfig();
        if ($format === null) {
            $format = $config['date_format'] ?? 'Y-m-d';
        }
        $timestamp = strtotime($date);
        return ($timestamp !== false) ? date($format, $timestamp) : ''; // Sichere Behandlung
    }

    public  function createSlug(string $string): string {
        if (function_exists('transliterator_transliterate')) {
            $string = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $string);
        } else {
            $string = strtolower($string);
        }
        $string = preg_replace('/[^a-z0-9]+/', '-', $string);
        return trim($string, '-');
    }

    public  function debug($var, bool $die = false): void {
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
  public  function formatBlogUrl(array $post): string
    {
        $urlMapping = $this->getUrlMapping();
        $config = $this->getConfig();
        $format = $config['blog_url_format'] ?? 'date_slash';

        // Stelle sicher, dass $post['date'] im richtigen Format vorliegt
        $date = is_numeric($post['date']) ? date('Y-m-d', (int)$post['date']) : $post['date'];
        $dateParts = explode('-', $date);

        if (count($dateParts) !== 3) {
            // Wenn das Datum ungültig ist, verwende den Slug (oder eine andere Fallback-Logik)
            return $this->getSiteUrl('blog/' . $post['slug']);
        }

        [$year, $month, $day] = $dateParts;
        $slug = $post['slug'];
        $postId = $post['id'];

        if (isset($urlMapping[$postId])) {
            return $this->getSiteUrl($urlMapping[$postId]);
        }

        switch ($format) {
            case 'date_slash':
                return $this->getSiteUrl("blog/{$year}/{$month}/{$day}/{$slug}");
            case 'date_dash':
                 return $this->getSiteUrl("blog/{$year}-{$month}-{$day}/{$slug}");
            case 'year_month':
                return $this->getSiteUrl("blog/{$year}/{$month}/{$slug}");
            case 'numeric':
                return isset($post['id']) ? $this->getSiteUrl("blog/{$post['id']}") : $this->getSiteUrl("blog/{$year}{$month}{$day}-{$slug}");
            case 'post_name':
                return $this->getSiteUrl("blog/{$slug}");
            default:
                return $this->getSiteUrl("blog/{$year}/{$month}/{$day}/{$slug}");
        }
    }

    public  function generateBlogUrl(array $post): string
    {
        $urlMapping = $this->getUrlMapping();
        $config = $this->getConfig();
        $blogUrlFormat = $config['blog_url_format'] ?? 'internal';

        $timestamp = strtotime($post['date']);
        if ($timestamp === false) {
            $timestamp = time(); // Aktuelle Zeit, wenn das Datum ungültig ist
        }

        $postId = $post['id'];

        if (isset($urlMapping[$postId])) {
             return $this->getSiteUrl($urlMapping[$postId]); // Vollständige URL
        }

        $year = date('Y', $timestamp);
        $month = date('m', $timestamp);
        $day = date('d', $timestamp);
        $slug = $post['slug'];

        switch($blogUrlFormat){
            case 'internal':
                return $this->getSiteUrl('blog/' . urlencode($postId));
            case 'post_name':
                return $this->getSiteUrl('blog/' . urlencode($slug));
            case 'year_month':
                return $this->getSiteUrl("blog/{$year}/{$month}/" . urlencode($slug));
            case 'date_slash':
                 return $this->getSiteUrl("blog/{$year}/{$month}/{$day}/" . urlencode($slug));
            default:
                return $this->getSiteUrl('blog/' . urlencode($postId)); // Fallback
        }
    }

    public  function themeUrl(string $path = ''): string {
        $themeManager = $this->resolve('theme_manager'); // Verwende den Docker!
        return $themeManager->getThemeAssetsUrl($path);
    }
}

?>
<!--
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
-->