<?php
declare(strict_types=1);

namespace Marques\Core;

class Helper {
    /**
     * Cache für die Systemkonfiguration
     *
     * @var array|null
     */
    private static ?array $config = null;

    /**
     * Lädt (oder gibt den bereits geladenen) Systemkonfiguration zurück.
     *
     * @param bool $forceReload Erzwingt das Neuladen der Konfiguration
     * @return array
     */
    public static function getConfig(bool $forceReload = false): array {
        if ($forceReload || self::$config === null) {
            self::$config = require MARQUES_CONFIG_DIR . '/system.config.php';
            // Im Frontend: "/admin" aus der Base‑URL entfernen
            if (!defined('IS_ADMIN') && isset(self::$config['base_url']) && strpos(self::$config['base_url'], '/admin') !== false) {
                self::$config['base_url'] = preg_replace('|/admin$|', '', self::$config['base_url']);
            }
        }
        return self::$config;
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
     *
     * @param array $post
     * @return string
     */
    public static function formatBlogUrl(array $post): string {
        $config = self::getConfig();
        $format = $config['blog_url_format'] ?? 'date_slash';
        $dateParts = explode('-', $post['date']);
        if (count($dateParts) !== 3) {
            return self::getSiteUrl('blog/' . $post['slug']);
        }
        [$year, $month, $day] = $dateParts;
        $slug = $post['slug'];
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
}
