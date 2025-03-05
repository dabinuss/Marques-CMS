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

class ConfigManager {

    private static ?ConfigManager $_instance = null;
    private array $_cache = [];
    
    /**
     * Privater Konstruktor (Singleton-Pattern)
     */
    private function __construct() {
        // Stellen sicher, dass das Konfigurationsverzeichnis existiert
        if (!is_dir(MARQUES_CONFIG_DIR)) {
            mkdir(MARQUES_CONFIG_DIR, 0755, true);
        }
        
        // .htaccess-Datei erstellen, falls nicht vorhanden
        $this->ensureHtaccessExists();
    }
    
    /**
     * Gibt die einzige Instanz der Klasse zurück
     *
     * @return ConfigManager
     */
    public static function getInstance(): ConfigManager {
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
        // Normalisiere den Namen
        $name = $this->normalizeConfigName($name);
        
        // Aus Cache laden, wenn verfügbar und kein Neuladen erzwungen wird
        if (!$forceReload && isset($this->_cache[$name])) {
            return $this->_cache[$name];
        }
        
        $filePath = $this->getConfigPath($name);
        
        // Fallback auf alte PHP-Konfigurationsdatei
        $phpFilePath = MARQUES_CONFIG_DIR . '/' . $name . '.config.php';
        if (!file_exists($filePath) && file_exists($phpFilePath)) {
            // Automatische Migration durchführen
            $this->migrateFromPhpToJson($name);
        }
        
        if (!file_exists($filePath)) {
            error_log("ConfigManager: Konfigurationsdatei nicht gefunden: " . $filePath);
            return null;
        }
        
        if (!is_readable($filePath)) {
            error_log("ConfigManager: Konfigurationsdatei nicht lesbar: " . $filePath);
            return null;
        }
        
        // JSON-Konfigurationsdatei laden
        $content = file_get_contents($filePath);
        $config = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ConfigManager: Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
            return null;
        }
        
        // Im Cache speichern
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
        // Normalisiere den Namen
        $name = $this->normalizeConfigName($name);
        
        // Dateipfad bestimmen
        $filePath = $this->getConfigPath($name);
        
        // Verzeichnis erstellen, falls es nicht existiert
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                error_log("ConfigManager: Konnte Verzeichnis nicht erstellen: " . $dir);
                return false;
            }
        }
        
        // Direkter Speicherversuch ohne Berechtigungsprüfung
        // JSON-Konfigurationsdatei
        $content = json_encode($data, JSON_PRETTY_PRINT);
        
        // Mit temporärem Backup-Modus arbeiten, um Datenverlust zu vermeiden
        $tempFile = $filePath . '.tmp';
        
        // Zuerst in temporäre Datei schreiben
        if (file_put_contents($tempFile, $content) === false) {
            error_log("ConfigManager: Fehler beim Schreiben der temporären Datei: " . $tempFile);
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            return false;
        }
        
        // Temporäre Datei umbenennen/verschieben zur Zieldatei
        if (!@rename($tempFile, $filePath)) {
            error_log("ConfigManager: Fehler beim Umbenennen der temporären Datei zu: " . $filePath);
            @unlink($tempFile);
            return false;
        }
        
        // Cache aktualisieren
        $this->_cache[$name] = $data;
        
        return true;
    }
    
    /**
     * Holt einen Wert aus einer Konfiguration
     *
     * @param string $name Name der Konfiguration
     * @param string $key Schlüssel des Werts
     * @param mixed $default Standardwert, falls nicht gefunden
     * @return mixed Konfigurationswert
     */
    public function get(string $name, string $key, $default = null) {
        $config = $this->load($name);
        
        if ($config === null) {
            return $default;
        }
        
        // Unterstützt dot-notation für verschachtelte Einstellungen
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $value = $config;
            
            foreach ($keys as $k) {
                if (!isset($value[$k])) {
                    return $default;
                }
                $value = $value[$k];
            }
            
            return $value;
        }
        
        return $config[$key] ?? $default;
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
        
        // Unterstützt dot-notation für verschachtelte Einstellungen
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
        
        // Unterstützt dot-notation für verschachtelte Einstellungen
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $lastKey = array_pop($keys);
            $current = &$config;
            
            foreach ($keys as $k) {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    return false; // Schlüssel existiert nicht
                }
                $current = &$current[$k];
            }
            
            if (isset($current[$lastKey])) {
                unset($current[$lastKey]);
            } else {
                return false; // Schlüssel existiert nicht
            }
        } else {
            if (isset($config[$key])) {
                unset($config[$key]);
            } else {
                return false; // Schlüssel existiert nicht
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
        // Entferne Pfad und Erweiterung
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
     * Stellt sicher, dass eine .htaccess-Datei im Konfigurationsverzeichnis existiert
     */
    private function ensureHtaccessExists(): void {
        $htaccessPath = MARQUES_CONFIG_DIR . '/.htaccess';
        
        if (!file_exists($htaccessPath)) {
            $htaccessContent = "# Konfigurationsverzeichnis vor direktem Zugriff schützen\n";
            $htaccessContent .= "Order deny,allow\n";
            $htaccessContent .= "Deny from all\n";
            $htaccessContent .= "\n";
            $htaccessContent .= "# Zusätzlicher Schutz für alle Dateien im Verzeichnis\n";
            $htaccessContent .= "<Files *>\n";
            $htaccessContent .= "    Order deny,allow\n";
            $htaccessContent .= "    Deny from all\n";
            $htaccessContent .= "</Files>\n";
            
            file_put_contents($htaccessPath, $htaccessContent);
        }
    }

    /**
     * Konvertiert eine PHP-Konfigurationsdatei zu JSON (Migration)
     *
     * @param string $name Name der Konfiguration
     * @return bool Erfolg
     */
    public function migrateFromPhpToJson(string $name): bool {
        $name = $this->normalizeConfigName($name);
        $oldPath = MARQUES_CONFIG_DIR . '/' . $name . '.config.php';
        
        if (!file_exists($oldPath)) {
            error_log("ConfigManager: Keine PHP-Konfigurationsdatei zum Migrieren gefunden: " . $oldPath);
            return false;
        }
        
        try {
            // PHP-Datei laden
            $oldConfig = require $oldPath;
            
            if (!is_array($oldConfig)) {
                error_log("ConfigManager: PHP-Konfigurationsdatei enthält kein Array: " . $oldPath);
                return false;
            }
            
            // Als JSON speichern
            $result = $this->save($name, $oldConfig);
            
            if ($result) {
                // Alte PHP-Datei umbenennen (als Backup)
                rename($oldPath, $oldPath . '.bak');
                error_log("ConfigManager: Erfolgreich migriert: " . $name);
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log("ConfigManager: Fehler bei der Migration von $name: " . $e->getMessage());
            return false;
        }
    }
}