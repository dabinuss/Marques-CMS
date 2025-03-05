<?php
declare(strict_types=1);

/**
 * marques CMS - Configuration Manager Klasse
 * 
 * Verwaltet die Konfigurationsdateien des CMS einheitlich.
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
    public function load(string $name, bool $forceReload = false): array {
        // Normalisiere den Namen
        $name = $this->normalizeConfigName($name);
        
        // Aus Cache zurückgeben, wenn vorhanden und kein Neuladen erzwungen wird
        if (!$forceReload && isset($this->_cache[$name])) {
            return $this->_cache[$name];
        }
        
        // Dateipfad bestimmen
        $filePath = $this->getConfigPath($name);
        
        // Standard-Konfiguration
        $config = [];
        
        // Datei laden, wenn sie existiert
        if (file_exists($filePath)) {
            if (pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
                // PHP-Konfigurationsdatei
                $config = require $filePath;
            } else {
                // JSON-Konfigurationsdatei
                $content = file_get_contents($filePath);
                $config = json_decode($content, true) ?: [];
            }
        }
        
        // In Cache speichern
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
        
        // Sicherstellen, dass das Verzeichnis existiert
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Je nach Dateityp speichern
        if (pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
            // PHP-Konfigurationsdatei
            $content = "<?php\n/**\n * marques CMS - " . ucfirst($name) . " Konfiguration\n * \n * Automatisch generierte Konfigurationsdatei.\n *\n * @package marques\n * @subpackage config\n */\n\n";
            $content .= "return " . $this->varExport($data, true) . ";\n";
        } else {
            // JSON-Konfigurationsdatei
            $content = json_encode($data, JSON_PRETTY_PRINT);
        }
        
        // Datei speichern
        $success = file_put_contents($filePath, $content) !== false;
        
        // Cache aktualisieren, wenn erfolgreich
        if ($success) {
            $this->_cache[$name] = $data;
        }
        
        return $success;
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
        
        return $name;
    }
    
    /**
     * Gibt den Pfad zu einer Konfigurationsdatei zurück
     *
     * @param string $name Name der Konfiguration
     * @return string Dateipfad
     */
    private function getConfigPath($name) {
        if (in_array($name, ['system', 'routes', 'users'])) {
            // System-Konfigurationen als PHP-Dateien
            return MARQUES_CONFIG_DIR . '/' . $name . '.config.php';
        } else {
            // Andere Konfigurationen als JSON-Dateien
            return MARQUES_CONFIG_DIR . '/' . $name . '.json';
        }
    }
    
    /**
     * Formatierte Ausgabe von Variablen für Konfigurationsdateien
     * Alternative zu var_export mit besserer Formatierung
     *
     * @param mixed $var Variable zum Exportieren
     * @param bool $return Ob das Ergebnis zurückgegeben werden soll
     * @param int $indent Einrückungsebene
     * @return string|void Exportierte Variable
     */
    private function varExport($var, bool $return = false, int $indent = 0) {
        $indentStr = str_repeat('    ', $indent);
        
        if (is_array($var)) {
            $indexed = array_keys($var) === range(0, count($var) - 1);
            $r = [];
            
            foreach ($var as $key => $value) {
                $r[] = $indentStr . '    ' . 
                       ($indexed ? '' : $this->varExport($key, true) . ' => ') . 
                       $this->varExport($value, true, $indent + 1);
            }
            
            $output = "[\n" . implode(",\n", $r) . "\n" . $indentStr . "]";
            
            if ($return) {
                return $output;
            } else {
                echo $output;
            }
        } elseif (is_bool($var)) {
            if ($return) {
                return $var ? 'true' : 'false';
            } else {
                echo $var ? 'true' : 'false';
            }
        } elseif (is_null($var)) {
            if ($return) {
                return 'null';
            } else {
                echo 'null';
            }
        } elseif (is_string($var)) {
            $var = str_replace("'", "\\'", $var);
            if ($return) {
                return "'" . $var . "'";
            } else {
                echo "'" . $var . "'";
            }
        } else {
            if ($return) {
                return var_export($var, true);
            } else {
                var_export($var);
            }
        }
    }
}