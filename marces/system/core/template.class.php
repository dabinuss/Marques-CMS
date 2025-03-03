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
        
        // Sicherstellen, dass Template-Name nur gültige Zeichen enthält
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

        // Konfiguration für Templates verfügbar machen
        $config = $this->_config;
        
        // Daten zu Variablen extrahieren für einfache Verwendung im Template
        // Hinzufügen des Template-Namens
        $data = array_merge(['templateName' => $templateName], $data);
        extract($data);
        
        // Daten gefiltert extrahieren
        foreach ($data as $key => $value) {
            // Erlaubte Variablen definieren
            if (in_array($key, ['title', 'content', 'description', 'templateName', 'path', 'featured_image', 'date_created', 'date_modified'])) {
                ${$key} = $value;
            }
        }
        
        // Konfiguration für Templates verfügbar machen
        $config = $this->_config;
        
        // Output-Buffering starten
        ob_start();
        
        // Das Basis-Template einbinden, das das spezifische Template einbinden wird
        include $baseTemplateFile;
        
        // Output-Buffer ausgeben
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