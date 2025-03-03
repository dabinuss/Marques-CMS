<?php
/**
 * marces CMS - Template Klasse
 * 
 * Behandelt Template-Rendering.
 *
 * @package marces
 * @subpackage core
 */

namespace Marces\Core;

class Template {
    /**
     * @var array Systemkonfiguration
     */
    private $_config;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $this->_config = require MARCES_CONFIG_DIR . '/system.config.php';
    }
    
    /**
     * Rendert ein Template mit Daten
     *
     * @param array $data Daten, die an das Template übergeben werden
     * @return void
     * @throws \Exception Wenn das Template nicht gefunden wird
     */
    public function render($data) {
        // Template-Namen abrufen
        $templateName = $data['template'] ?? 'page';
        
        // Prüfen und sicherstellen, dass vorhandene Dateien korrekt sind
        if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $templateName)) {
            throw new \Exception("Ungültiger Template-Name: " . htmlspecialchars($templateName));
        }
        
        // Prüfen, ob Template existiert
        $templateFile = MARCES_TEMPLATE_DIR . '/' . $templateName . '.tpl.php';
        if (!file_exists($templateFile)) {
            throw new \Exception("Template nicht gefunden: " . $templateName);
        }
        
        // Prüfen, ob Basis-Template existiert
        $baseTemplateFile = MARCES_TEMPLATE_DIR . '/base.tpl.php';
        if (!file_exists($baseTemplateFile)) {
            throw new \Exception("Basis-Template nicht gefunden");
        }
    
        // Systemeinstellungen holen und anpassen
        $settings_manager = new \Marces\Core\SettingsManager();
        $system_settings = $settings_manager->getAllSettings();
        
        // Base URL korrigieren
        if (defined('IS_ADMIN')) {
            // Im Admin-Bereich
            if (strpos($system_settings['base_url'], '/admin') === false) {
                $system_settings['base_url'] = rtrim($system_settings['base_url'], '/') . '/admin';
            }
        } else {
            // Im Frontend
            if (strpos($system_settings['base_url'], '/admin') !== false) {
                $system_settings['base_url'] = preg_replace('|/admin$|', '', $system_settings['base_url']);
            }
        }
        
        // Aktualisierte Systemeinstellungen zu den Daten hinzufügen
        $data['system_settings'] = $system_settings;
        
        // Rest des Codes bleibt unverändert
        $data = array_merge(['templateName' => $templateName], $data);
        extract($data);
        
        foreach ($data as $key => $value) {
            if (in_array($key, ['title', 'content', 'description', 'templateName', 'path', 'featured_image', 'date_created', 'date_modified'])) {
                ${$key} = $value;
            }
        }
        
        $config = $this->_config;
        
        ob_start();
        include $baseTemplateFile;
        echo ob_get_clean();
    }
    
    /**
     * Prüft, ob ein Template existiert
     *
     * @param string $templateName Template-Name
     * @return bool True, wenn das Template existiert
     */
    public function exists($templateName) {
        $templateFile = MARCES_TEMPLATE_DIR . '/' . $templateName . '.tpl.php';
        return file_exists($templateFile);
    }
    
    /**
     * Bindet ein Partial-Template ein
     *
     * @param string $partialName Name des Partial-Templates
     * @param array $data Daten, die an das Partial übergeben werden
     * @return void
     */
    public function includePartial($partialName, $data = []) {
        // Konfiguration für Templates verfügbar machen
        $config = $this->_config;
        
        // Systemeinstellungen holen, falls sie nicht übergeben wurden
        if (!isset($data['system_settings'])) {
            $settings_manager = new \Marces\Core\SettingsManager();
            $system_settings = $settings_manager->getAllSettings();
            
            // Base URL korrigieren (wie in render())
            if (defined('IS_ADMIN')) {
                if (strpos($system_settings['base_url'], '/admin') === false) {
                    $system_settings['base_url'] = rtrim($system_settings['base_url'], '/') . '/admin';
                }
            } else {
                if (strpos($system_settings['base_url'], '/admin') !== false) {
                    $system_settings['base_url'] = preg_replace('|/admin$|', '', $system_settings['base_url']);
                }
            }
            
            // Systemeinstellungen zu den Daten hinzufügen
            $data['system_settings'] = $system_settings;
        }
        
        // Daten zu Variablen extrahieren für einfache Verwendung im Template
        extract($data);
        
        // Das Partial-Template einbinden
        $partialFile = MARCES_TEMPLATE_DIR . '/partials/' . $partialName . '.tpl.php';
        if (file_exists($partialFile)) {
            include $partialFile;
        } else {
            echo "<!-- Partial nicht gefunden: $partialName -->";
        }
    }
}