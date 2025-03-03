<?php
/**
 * marces CMS - Application Klasse
 * 
 * Hauptanwendungsklasse, die den Anfrageverarbeitungsprozess orchestriert.
 *
 * @package marces
 * @subpackage core
 */

namespace Marces\Core;

class Application {
    /**
     * @var Router Instance des Routers
     */
    private $_router;
    
    /**
     * @var Template Instance der Template-Engine
     */
    private $_template;
    
    /**
     * @var array Systemkonfiguration
     */
    private $_config;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        // Konfiguration laden
        $this->_config = require MARCES_CONFIG_DIR . '/system.config.php';
        
        // Router initialisieren
        $this->_router = new Router();
        
        // Template-Engine initialisieren
        $this->_template = new Template();
    }
    
    /**
     * Führt die Anwendung aus
     */
    public function run() {
        try {
            // Anfrage durch den Router verarbeiten
            $route = $this->_router->processRequest();
            
            // Inhalt basierend auf der Route abrufen
            $content = new Content();
            $pageData = $content->getPage($route['path']);
            
            // Sicherstellen, dass pageData ein gültiges Array ist und alle benötigten Schlüssel hat
            if (!is_array($pageData)) {
                throw new \Exception("Ungültiges Seitenformat");
            }
            
            // Seite mit der Template-Engine rendern
            $this->_template->render($pageData);
            
        } catch (\Exception $e) {
            // Fehler behandeln
            $this->handleError($e);
        }
    }
    
    /**
     * Behandelt Anwendungsfehler
     *
     * @param \Exception $e Die zu behandelnde Exception
     */
    private function handleError(\Exception $e) {
        // Fehler loggen
        error_log($e->getMessage());
        
        // HTTP-Statuscode bestimmen
        $statusCode = 500;
        if ($e instanceof \Marces\Core\NotFoundException) {
            $statusCode = 404;
        } elseif ($e instanceof \Marces\Core\PermissionException) {
            $statusCode = 403;
        }
        
        // HTTP-Antwortcode setzen
        http_response_code($statusCode);
        
        // Fehlerseite rendern
        $errorTemplate = ($statusCode == 404) ? 'errors/error-404' : 'errors/error-500';
        
        // Template-Objekt prüfen
        if (!isset($this->_template)) {
            echo '<h1>Fehler ' . $statusCode . '</h1>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            return;
        }
        
        // Prüfen, ob das Fehlertemplate existiert
        if (!$this->_template->exists($errorTemplate)) {
            echo '<h1>Fehler ' . $statusCode . '</h1>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            return;
        }
        
        // Fehler-Template rendern
        $this->_template->render([
            'title' => 'Fehler ' . $statusCode,
            'content' => $e->getMessage(),
            'template' => $errorTemplate
        ]);
    }
}