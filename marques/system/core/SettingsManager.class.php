<?php
declare(strict_types=1);

/**
 * marques CMS - Settings Manager Klasse
 * 
 * Verwaltet System- und Site-Einstellungen.
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Core;

class SettingsManager {
    /**
     * @var array Aktuelle Systemeinstellungen
     */
    private $_system_settings;
    
    /**
     * @var ConfigManager Instance des ConfigManager
     */
    private $_configManager;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $this->_configManager = \Marques\Core\ConfigManager::getInstance();
        $this->_loadSettings();
    }
    
    /**
     * Lädt die Systemeinstellungen
     */
    private function _loadSettings() {
        $config = $this->_configManager->load('system');
        
        if ($config) {
            $this->_system_settings = $config;
        } else {
            // Standard-Einstellungen, falls Datei nicht existiert
            $this->_system_settings = $this->getDefaultSettings();
            $this->saveSettings();
        }
    }
    
    /**
     * Gibt Standard-Einstellungen zurück
     *
     * @return array Standard-Einstellungen
     */
    public function getDefaultSettings() {
        // Basis-URL bestimmen
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $script_dir = dirname($script_name);
        
        // Immer die Basis-URL zum Hauptverzeichnis (ohne /admin) generieren
        if (defined('IS_ADMIN') && strpos($script_dir, '/admin') !== false) {
            $script_dir = dirname($script_dir); // Ein Verzeichnis nach oben
        }
        
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                    '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                    rtrim($script_dir, '/');
    
        return [
            // Grundlegende Website-Informationen
            'site_name' => 'marques CMS',
            'site_description' => 'Ein leichtgewichtiges, dateibasiertes CMS',
            'site_logo' => '',
            'site_favicon' => '',
            'base_url' => $base_url,
            
            // Kontaktinformationen
            'contact_email' => '',
            'contact_phone' => '',
            
            // Social Media
            'social_links' => [
                'facebook' => '',
                'twitter' => '',
                'instagram' => '',
                'linkedin' => '',
                'youtube' => '',
            ],
            
            // SEO-Einstellungen
            'meta_keywords' => '',
            'meta_author' => '',
            'google_analytics_id' => '',
            
            // Datums- und Zeiteinstellungen
            'timezone' => 'Europe/Berlin',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
            
            // Inhaltseinstellungen
            'posts_per_page' => 10,
            'excerpt_length' => 150,
            'comments_enabled' => false,
            
            // Systemeinstellungen
            'debug' => false,
            'cache_enabled' => true,
            'version' => MARQUES_VERSION,
            'maintenance_mode' => false,
            'maintenance_message' => 'Die Website wird aktuell gewartet. Bitte versuchen Sie es später erneut.',
            
            // Admin-Einstellungen
            'admin_language' => 'de',
            'admin_email' => '',
        ];
    }
    
    /**
     * Gibt alle Systemeinstellungen zurück
     *
     * @return array Systemeinstellungen
     */
    public function getAllSettings() {
        return $this->_system_settings;
    }
    
    /**
     * Gibt eine bestimmte Systemeinstellung zurück
     *
     * @param string $key Einstellungsschlüssel
     * @param mixed $default Standardwert, falls Einstellung nicht existiert
     * @return mixed Einstellungswert
     */
    public function getSetting(string $key, $default = null) {
        // Unterstützt auch dot-notation für verschachtelte Einstellungen
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
    
    /**
     * Setzt eine Systemeinstellung
     *
     * @param string $key Einstellungsschlüssel
     * @param mixed $value Einstellungswert
     */
    public function setSetting(string $key, $value): void {
        // Unterstützt auch dot-notation für verschachtelte Einstellungen
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $lastKey = array_pop($keys);
            $current = &$this->_system_settings;
            
            foreach ($keys as $k) {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
            
            $current[$lastKey] = $value;
        } else {
            $this->_system_settings[$key] = $value;
        }
    }
    
    /**
     * Setzt mehrere Systemeinstellungen auf einmal
     *
     * @param array $settings Einstellungen als Schlüssel-Wert-Paare
     */
    public function setMultipleSettings($settings) {
        foreach ($settings as $key => $value) {
            $this->setSetting($key, $value);
        }
    }
    
    /**
     * Speichert die aktuellen Einstellungen in die Konfigurationsdatei
     *
     * @return bool True bei Erfolg
     */
    public function saveSettings(): bool {
        // Bereite die base_url vor
        if (isset($this->_system_settings['base_url'])) {
            $baseUrl = rtrim($this->_system_settings['base_url'], '/');
            
            // Entferne /admin vom Pfad, um eine konsistente Base-URL zu speichern
            if (strpos($baseUrl, '/admin') !== false) {
                $this->_system_settings['base_url'] = preg_replace('|/admin$|', '', $baseUrl);
            }
        }
        
        // ConfigManager verwenden
        return $this->_configManager->save('system', $this->_system_settings);
    }
}