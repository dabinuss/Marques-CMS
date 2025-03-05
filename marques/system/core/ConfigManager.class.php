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
    public function load(string $name, bool $forceReload = false): ?array {
        // Normalisiere den Namen
        $name = $this->normalizeConfigName($name);
        
        // Aus Cache laden, wenn verfügbar und kein Neuladen erzwungen wird
        if (!$forceReload && isset($this->_cache[$name])) {
            return $this->_cache[$name];
        }
        
        $filePath = $this->getConfigPath($name);
        
        if (!file_exists($filePath)) {
            error_log("ConfigManager: Konfigurationsdatei nicht gefunden: " . $filePath);
            return null;
        }
        
        if (!is_readable($filePath)) {
            error_log("ConfigManager: Konfigurationsdatei nicht lesbar: " . $filePath);
            return null;
        }
        
        // Je nach Dateityp laden
        if (pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
            // PHP-Konfigurationsdatei
            $config = require $filePath;
        } else {
            // JSON-Konfigurationsdatei
            $content = file_get_contents($filePath);
            $config = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("ConfigManager: Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
                return null;
            }
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
        
        // Sicherstellen, dass die Datei beschreibbar ist
        if (!$this->ensureWritable($filePath)) {
            error_log("ConfigManager: Kann Konfiguration nicht speichern - Datei nicht beschreibbar: " . $filePath);
            return false;
        }
        
        // Je nach Dateityp speichern
        if (pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
            // PHP-Konfigurationsdatei
            $content = "<?php\n/**\n * marques CMS - " . ucfirst($name) . " Konfiguration\n * \n * Automatisch generierte Konfigurationsdatei.\n *\n * @package marques\n * @subpackage config\n */\n\n";
            $content .= "// Direkten Zugriff verhindern\nif (!defined('MARQUES_ROOT_DIR')) {\n    exit('Direkter Zugriff ist nicht erlaubt.');\n}\n\n";
            $content .= "return " . $this->varExport($data, true) . ";\n";
        } else {
            // JSON-Konfigurationsdatei
            $content = json_encode($data, JSON_PRETTY_PRINT);
        }
        
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
    private function getConfigPath(string $name): string {
        return MARQUES_CONFIG_DIR . '/' . $name . '.config.php';
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

    /**
     * Überprüft und korrigiert bei Bedarf die Dateiberechtigungen
     * 
     * @param string $filePath Pfad zur Konfigurationsdatei
     * @param bool $createIfNotExists Datei erstellen, falls sie nicht existiert
     * @return bool True wenn die Datei beschreibbar ist oder beschreibbar gemacht wurde
     */
    private function ensureWritable(string $filePath, bool $createIfNotExists = true): bool {
        // Verzeichnis überprüfen und ggf. erstellen
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0755, true)) {
                error_log("ConfigManager: Konnte Verzeichnis nicht erstellen: " . $directory);
                return false;
            }
        }
        
        // Wenn die Datei nicht existiert und erstellt werden soll
        if (!file_exists($filePath) && $createIfNotExists) {
            // Leere Datei erstellen
            if (@file_put_contents($filePath, '') === false) {
                error_log("ConfigManager: Konnte Datei nicht erstellen: " . $filePath);
                return false;
            }
            
            // Berechtigungen für neue Datei setzen
            if (!@chmod($filePath, 0640)) {
                error_log("ConfigManager: Konnte Berechtigungen für neue Datei nicht setzen: " . $filePath);
                // Trotzdem weitermachen, möglicherweise sind die Standard-Berechtigungen ok
            }
        }
        
        // Wenn die Datei existiert aber nicht beschreibbar ist
        if (file_exists($filePath) && !is_writable($filePath)) {
            error_log("ConfigManager-Debug: Datei existiert aber ist nicht beschreibbar: " . $filePath);
            error_log("ConfigManager-Debug: Aktuelle Berechtigungen: " . substr(sprintf('%o', fileperms($filePath)), -4));
            
            // Versuchen die Berechtigungen zu ändern
            $chmod_result = @chmod($filePath, 0640);
            error_log("ConfigManager-Debug: chmod Ergebnis: " . ($chmod_result ? "Erfolg" : "Fehlgeschlagen"));
            error_log("ConfigManager-Debug: Neue Berechtigungen: " . substr(sprintf('%o', fileperms($filePath)), -4));
            
            if (!$chmod_result) {
                // Wenn chmod fehlschlägt, versuchen wir einen alternativen Ansatz
                // Dateiinhalt lesen
                $content = @file_get_contents($filePath);
                if ($content === false) {
                    error_log("ConfigManager: Konnte die nicht-beschreibbare Datei nicht lesen: " . $filePath);
                    return false;
                }
                
                // Temporäre Datei erstellen
                $tempFile = $filePath . '.new';
                if (@file_put_contents($tempFile, $content) === false) {
                    error_log("ConfigManager: Konnte keine temporäre Datei erstellen: " . $tempFile);
                    return false;
                }
                
                // Berechtigungen für temporäre Datei setzen
                if (!@chmod($tempFile, 0640)) {
                    error_log("ConfigManager: Konnte Berechtigungen für temporäre Datei nicht setzen: " . $tempFile);
                    // Trotzdem weitermachen
                }
                
                // Versuchen, die alte Datei zu löschen
                if (!@unlink($filePath)) {
                    error_log("ConfigManager: Konnte die nicht-beschreibbare Datei nicht löschen: " . $filePath);
                    @unlink($tempFile); // Temporäre Datei aufräumen
                    return false;
                }
                
                // Temporäre Datei umbenennen
                if (!@rename($tempFile, $filePath)) {
                    error_log("ConfigManager: Konnte temporäre Datei nicht umbenennen: " . $tempFile);
                    return false;
                }
                
                // Überprüfen, ob die neue Datei beschreibbar ist
                if (!is_writable($filePath)) {
                    error_log("ConfigManager: Neue Datei ist immer noch nicht beschreibbar: " . $filePath);
                    return false;
                }
                
                error_log("ConfigManager: Datei erfolgreich durch Kopieren neu erstellt mit Berechtigungen: " . 
                        substr(sprintf('%o', fileperms($filePath)), -4));
            }
        }
        
        // Final prüfen, ob die Datei jetzt beschreibbar ist
        return is_writable($filePath);
    }
}