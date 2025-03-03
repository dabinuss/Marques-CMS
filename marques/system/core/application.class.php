<?php
/**
 * marques CMS - Application Klasse
 * 
 * Hauptanwendungsklasse, die den Anfrageverarbeitungsprozess orchestriert.
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Core;

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
            $settings = new \Marques\Core\SettingsManager();
            // Cache-Prüfung
            if ($settings->getSetting('cache_enabled', false)) {
                // Cache-Logik implementieren
            }
    
            // Anfrage durch den Router verarbeiten
            $route = $this->_router->processRequest();
            
            // WICHTIG: Route als globale Variable setzen
            $GLOBALS['route'] = $route;
            
            // Debug-Ausgabe für Fehlersuche
            error_log("Globale Route gesetzt: " . print_r($GLOBALS['route'], true));
            
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
        // Einstellungen laden
        $settings = new \Marques\Core\SettingsManager();
        $debug_mode = $settings->getSetting('debug', false);
        
        // Fehler loggen
        error_log($e->getMessage());
        
        // HTTP-Statuscode bestimmen
        $statusCode = 500;
        if ($e instanceof \Marques\Core\NotFoundException) {
            $statusCode = 404;
        } elseif ($e instanceof \Marques\Core\PermissionException) {
            $statusCode = 403;
        }
        
        // HTTP-Antwortcode setzen
        http_response_code($statusCode);
        
        // Fehlerseite-Template bestimmen
        $errorTemplate = 'errors/error-' . $statusCode;
        
        // Fallback-Template als Sicherheit
        $fallbackTemplate = 'errors/error-fallback';
        
        // Daten für das Template vorbereiten
        $errorData = [
            'title' => 'Fehler ' . $statusCode,
            'content' => $e->getMessage(),
            'error_code' => $statusCode,
            'debug' => $debug_mode,
            'exception' => $e,
            'system_settings' => $settings->getAllSettings()
        ];
        
        // Prüfen, ob die Template-Engine verfügbar ist
        if (!isset($this->_template)) {
            // Notfall-Ausgabe, wenn keine Template-Engine verfügbar ist
            $this->renderFallbackErrorPage($errorData);
            return;
        }
        
        // Prüfen, ob das spezifische Error-Template existiert
        if (!$this->_template->exists($errorTemplate)) {
            // Prüfen, ob das Fallback-Template existiert
            if ($this->_template->exists($fallbackTemplate)) {
                $this->_template->render(array_merge($errorData, ['template' => $fallbackTemplate]));
            } else {
                // Notfall-Ausgabe, wenn beide Templates nicht existieren
                $this->renderFallbackErrorPage($errorData);
            }
            return;
        }
        
        // Reguläres Error-Template rendern
        $this->_template->render(array_merge($errorData, ['template' => $errorTemplate]));
    }

    /**
     * Rendert eine Fallback-Fehlerseite, wenn keine Templates verfügbar sind
     *
     * @param array $data Daten für die Fehlerseite
     */
    private function renderFallbackErrorPage($data) {
        $errorCode = $data['error_code'] ?? 500;
        $debug = $data['debug'] ?? false;
        $exception = $data['exception'] ?? null;
        $content = $data['content'] ?? 'Ein unerwarteter Fehler ist aufgetreten.';
        
        // Titel basierend auf Error-Code
        $title = '';
        switch ($errorCode) {
            case 404:
                $title = 'Seite nicht gefunden';
                break;
            case 403:
                $title = 'Zugriff verweigert';
                break;
            default:
                $title = 'Ein Fehler ist aufgetreten';
        }
        
        // Standard-Fehlermeldung
        echo '<!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Fehler ' . $errorCode . ' - ' . htmlspecialchars($title) . '</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    padding: 20px;
                    text-align: center;
                    background-color: #f8f9fa;
                }
                .error-container {
                    background-color: white;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                    padding: 40px;
                    max-width: 500px;
                    width: 100%;
                }
                .error-code {
                    font-size: 72px;
                    font-weight: bold;
                    margin: 0;
                    color: #d9534f;
                }
                .error-title {
                    font-size: 24px;
                    margin: 10px 0 20px;
                }
                .error-message {
                    color: #6c757d;
                    margin-bottom: 30px;
                }
                .home-button {
                    display: inline-block;
                    background-color: #007bff;
                    color: white;
                    text-decoration: none;
                    padding: 10px 20px;
                    border-radius: 4px;
                    font-weight: 500;
                }
                .home-button:hover {
                    background-color: #0069d9;
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <h1 class="error-code">' . $errorCode . '</h1>
                <h2 class="error-title">' . htmlspecialchars($title) . '</h2>
                <p class="error-message">' . htmlspecialchars($content) . '</p>
                <a href="/" class="home-button">Zurück zur Startseite</a>
            </div>';
        
        // Debug-Informationen anzeigen, wenn im Debug-Modus
        if ($debug && $exception instanceof \Exception) {
            echo '<div style="max-width: 800px; margin: 20px auto; text-align: left; background: #f8f9fa; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);">
                <h3 style="color: #d9534f;">Debug-Informationen</h3>
                <div style="background: white; padding: 15px; border-radius: 4px; margin-top: 10px;">
                    <p><strong>Fehlermeldung:</strong> ' . htmlspecialchars($exception->getMessage()) . '</p>
                    <p><strong>Datei:</strong> ' . htmlspecialchars($exception->getFile()) . '</p>
                    <p><strong>Zeile:</strong> ' . $exception->getLine() . '</p>
                    
                    <h4>Stack Trace:</h4>
                    <pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; font-family: monospace; font-size: 13px;">' . htmlspecialchars($exception->getTraceAsString()) . '</pre>
                </div>
                <p style="font-size: 12px; color: #6c757d; margin-top: 10px;">Diese detaillierte Fehlermeldung wird nur im Debug-Modus angezeigt.</p>
            </div>';
        }
        
        echo '</body></html>';
    }

}