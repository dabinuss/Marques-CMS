<?php
declare(strict_types=1);

/**
 * marques CMS - Configuration Manager Klasse
 * 
 * Verwaltet die Konfigurationsdateien des CMS einheitlich als JSON-Dateien.
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Core;

class AppConfig {

    private static ?AppConfig $_instance = null;
    private array $_cache = [];
    
    /**
     * Privater Konstruktor (Singleton-Pattern)
     */
    private function __construct() {

        // Stellen sicher, dass das Konfigurationsverzeichnis existiert
        try {
            AppPath::getInstance()->preparePath('config');
        } catch (\Exception $e) {
            echo "Fehler beim Vorbereiten des Konfigurationsverzeichnisses: " . $e->getMessage();
        }
        
        // .htaccess-Datei erstellen, falls nicht vorhanden
        $this->ensureHtaccessExists();
    }
    
    /**
     * Gibt die einzige Instanz der Klasse zurück
     *
     * @return AppConfig
     */
    public static function getInstance(): AppConfig {
        if (self::$_instance === null) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    /**
     * Lädt eine Konfigurationsdatei
     *
     * @param string $name Name der Konfiguration (ohne Pfad/Erweiterung)
     * @param bool $forceReload Erzwingt das Neuladen aus der Datei
     * @return array Konfigurationsdaten
     */
    public function load(string $name, bool $forceReload = false): ?array {
        $name = $this->normalizeConfigName($name);
        if (!$forceReload && isset($this->_cache[$name])) {
            return $this->_cache[$name];
        }
        $filePath = $this->getConfigPath($name);
        if (!file_exists($filePath)) {
            error_log("AppConfig: Konfigurationsdatei nicht gefunden: " . $filePath);
            return null;
        }
        if (!is_readable($filePath)) {
            error_log("AppConfig: Konfigurationsdatei nicht lesbar: " . $filePath);
            return null;
        }
        $content = file_get_contents($filePath);
        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("AppConfig: Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
            return null;
        }
        $this->_cache[$name] = $config;
        return $config;
    }
    
    /**
     * Speichert eine Konfigurationsdatei
     *
     * @param string $name Name der Konfiguration (ohne Pfad/Erweiterung)
     * @param array $data Zu speichernde Konfigurationsdaten
     * @return bool Erfolg
     */
    public function save(string $name, array $data): bool {
        $name = $this->normalizeConfigName($name);
        $filePath = $this->getConfigPath($name);
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                error_log("AppConfig: Konnte Verzeichnis nicht erstellen: " . $dir);
                return false;
            }
        }
        $content = json_encode($data, JSON_PRETTY_PRINT);
        $tempFile = $filePath . '.tmp';
        if (file_put_contents($tempFile, $content) === false) {
            error_log("AppConfig: Fehler beim Schreiben der temporären Datei: " . $tempFile);
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            return false;
        }
        if (!@rename($tempFile, $filePath)) {
            error_log("AppConfig: Fehler beim Umbenennen der temporären Datei zu: " . $filePath);
            @unlink($tempFile);
            return false;
        }
        $this->_cache[$name] = $data;
        return true;
    }
    
    /**
     * Holt einen Wert aus der Konfiguration.
     *
     * @param string $key Schlüssel des Werts
     * @param mixed $default Standardwert, falls nicht gefunden
     * @return mixed Konfigurationswert
     */
    public function get(string $key, $config = null, $default = null) {
        // Wenn der übergebene Konfigurationsname kein String ist, verwende "system"
        if (!is_string($config)) {
            $config = 'system';
        }
        
        // Lade die Konfiguration anhand des angegebenen Namens
        $data = $this->load($config);
        if ($data === null) {
            return $default;
        }
        
        // Unterstütze verschachtelte Schlüssel (z. B. "admin_language")
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $value = $data;
            foreach ($keys as $k) {
                if (!isset($value[$k])) {
                    return $default;
                }
                $value = $value[$k];
            }
            return $value;
        }
        
        return $data[$key] ?? $default;
    }
    
    
    /**
     * Setzt einen Wert in einer Konfiguration
     *
     * @param string $name Name der Konfiguration
     * @param string $key Schlüssel des Werts
     * @param mixed $value Zu setzender Wert
     * @return bool Erfolg
     */
    public function set(string $name, string $key, $value): bool {
        $config = $this->load($name);
        if ($config === null) {
            $config = [];
        }
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $lastKey = array_pop($keys);
            $current = &$config;
            foreach ($keys as $k) {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
            $current[$lastKey] = $value;
        } else {
            $config[$key] = $value;
        }
        return $this->save($name, $config);
    }
    
    /**
     * Löscht einen Wert aus einer Konfiguration
     *
     * @param string $name Name der Konfiguration
     * @param string $key Schlüssel des zu löschenden Werts
     * @return bool Erfolg
     */
    public function delete(string $name, string $key): bool {
        $config = $this->load($name);
        if ($config === null) {
            return false;
        }
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $lastKey = array_pop($keys);
            $current = &$config;
            foreach ($keys as $k) {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    return false;
                }
                $current = &$current[$k];
            }
            if (isset($current[$lastKey])) {
                unset($current[$lastKey]);
            } else {
                return false;
            }
        } else {
            if (isset($config[$key])) {
                unset($config[$key]);
            } else {
                return false;
            }
        }
        return $this->save($name, $config);
    }
    
    /**
     * Normalisiert den Konfigurationsnamen
     *
     * @param string $name Name der Konfiguration
     * @return string Normalisierter Name
     */
    private function normalizeConfigName($name) {
        $name = basename($name);
        $name = preg_replace('/\.(json|php)$/', '', $name);
        $name = preg_replace('/\.config$/', '', $name);
        return $name;
    }
    
    /**
     * Gibt den Pfad zu einer Konfigurationsdatei zurück
     *
     * @param string $name Name der Konfiguration
     * @return string Dateipfad
     */
    private function getConfigPath(string $name): string {
        return MARQUES_CONFIG_DIR . '/' . $name . '.config.json';
    }

    /**
     * Gibt Standard-Einstellungen zurück
     *
     * @return array Standard-Einstellungen
     */
    public function getDefaultSettings() {
    
        return [
            // Grundlegende Website-Informationen
            'site_name' => 'marques CMS',
            'site_description' => 'Ein leichtgewichtiges, dateibasiertes CMS',
            'site_logo' => '',
            'site_favicon' => '',
            'base_url' => 'https://faktenfront.de',

            // Datums- und Zeiteinstellungen
            'timezone' => 'Europe/Berlin',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',

            // Systemeinstellungen
            'debug' => false,
            'cache_enabled' => true,
            'version' => MARQUES_VERSION,
            'maintenance' => false,
            'maintenance_message' => 'Die Website wird aktuell gewartet. Bitte versuchen Sie es später erneut.',

            // Admin-Einstellungen
            'admin_language' => 'de',
            'admin_email' => '',

            // Kontaktinformationen
            'contact_email' => '',
            'contact_phone' => '',

            // Inhaltseinstellungen
            'posts_per_page' => 10,
            'excerpt_length' => 150,
            'comments_enabled' => false,
            'blog_url_format'=> 'date_slash',
            
            'security' => [
                'max_login_attempts' => 6,
                'login_attempt_window' => 600,
                'login_block_duration' => 600,
            ],
        ];
    }
    
    /**
     * Stellt sicher, dass eine .htaccess-Datei im Konfigurationsverzeichnis existiert
     */
    private function ensureHtaccessExists(): void {
        // Hier nutzen wir den AppPath-Zugriff (optional: könntest du auch preparePath() einsetzen)
        $configDir = AppPath::getInstance()->getPath('config');
        $htaccessPath = $configDir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            $htaccessContent = "# Konfigurationsverzeichnis vor direktem Zugriff schützen\n";
            $htaccessContent .= "Order deny,allow\n";
            $htaccessContent .= "Deny from all\n\n";
            $htaccessContent .= "# Zusätzlicher Schutz für alle Dateien im Verzeichnis\n";
            $htaccessContent .= "<Files *>\n    Order deny,allow\n    Deny from all\n</Files>\n";
            file_put_contents($htaccessPath, $htaccessContent);
        }
    }

    public function loadUrlMapping(): array {
        $filePath = MARQUES_CONFIG_DIR . '/urlmapping.config.json';
        if (!file_exists($filePath)) {
            return [];
        }
        $content = file_get_contents($filePath);
        $mapping = json_decode($content, true);
        return is_array($mapping) ? $mapping : [];
    }
    
    public function updateUrlMapping(array $mapping): bool {
        $filePath = MARQUES_CONFIG_DIR . '/urlmapping.config.json';
        $content = json_encode($mapping, JSON_PRETTY_PRINT);
        return file_put_contents($filePath, $content) !== false;
    }
}