<?php
declare(strict_types=1);

namespace Marques\Core;

class AppSettings
{
    protected static ?AppSettings $_instance = null;
    protected array $_system_settings;
    protected AppConfig $appConfig;

    public function __construct()
    {
        // Direkter Zugriff auf AppConfig
        $this->appConfig = AppConfig::getInstance();
        $this->loadSettings();
    }

    /**
     * Singleton-Instanz von AppSettings
     */
    public static function getInstance(): AppSettings
    {
        if (self::$_instance === null) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Lädt die Systemeinstellungen über AppConfig.
     */
    protected function loadSettings(): void
    {
        $config = $this->appConfig->load('system');
        if ($config !== null) {
            // Validierung und Evaluation der geladenen Einstellungen
            $this->_system_settings = $this->validateSettings($config);
        } else {
            // Fallback: Standardwerte
            $this->_system_settings = $this->getDefaultSettings();
        }
    }

    /**
     * Gibt Standard-Einstellungen zurück.
     */
    public function getDefaultSettings(): array
    {
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $script_dir = dirname($script_name);

        // Falls im Adminbereich, /admin entfernen
        if (defined('IS_ADMIN') && strpos($script_dir, '/admin') !== false) {
            $script_dir = dirname($script_dir);
        }

        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
                    '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                    rtrim($script_dir, '/');

        return [
            // Grundlegende Website-Informationen
            'site_name'        => 'marques CMS',
            'site_description' => 'Ein leichtgewichtiges, dateibasiertes CMS',
            'site_logo'        => '',
            'site_favicon'     => '',
            'base_url'         => $base_url,
            // Kontaktinformationen
            'contact_email'    => '',
            'contact_phone'    => '',
            // Social Media
            'social_links'     => [
                'facebook'  => '',
                'twitter'   => '',
                'instagram' => '',
                'linkedin'  => '',
                'youtube'   => '',
            ],
            // SEO-Einstellungen
            'meta_keywords'       => '',
            'meta_author'         => '',
            'google_analytics_id' => '',
            // Datums- und Zeiteinstellungen
            'timezone'    => 'Europe/Berlin',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
            // Inhaltseinstellungen
            'posts_per_page'   => 10,
            'excerpt_length'   => 150,
            'comments_enabled' => false,
            // Systemeinstellungen
            'debug'              => false,
            'cache_enabled'      => true,
            'version'            => MARQUES_VERSION,
            'maintenance_mode'   => false,
            'maintenance_message'=> 'Die Website wird aktuell gewartet. Bitte versuchen Sie es später erneut.',
            // Admin-Einstellungen
            'admin_language'     => 'de',
            'admin_email'        => '',
        ];
    }

    /**
     * Validiert und evaluiert die geladenen Einstellungen.
     */
    protected function validateSettings(array $settings): array
    {
        // Beispielhafte Validierungslogik – hier können unerwünschte Werte angepasst werden
        return $settings;
    }

    /**
     * Gibt alle Systemeinstellungen zurück.
     */
    public function getAllSettings(): array
    {
        return $this->_system_settings;
    }

    /**
     * Gibt einen bestimmten Einstellungswert zurück.
     * Unterstützt Dot-Notation (z. B. "security.max_login_attempts") für verschachtelte Werte.
     */
    public function getSetting(string $key, $default = null)
    {
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $value = $this->_system_settings;
            foreach ($keys as $k) {
                if (!isset($value[$k])) {
                    return $default;
                }
                $value = $value[$k];
            }
            return $value;
        }
        return $this->_system_settings[$key] ?? $default;
    }
}
