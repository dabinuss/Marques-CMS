<?php
namespace Marques\Core;

/**
 * Hauptanwendungsklasse
 */
class Application {
    private $router;
    private $template;
    private $config;
    private $eventManager;
    
    /**
     * Konstruktor mit vereinfachtem DI
     */
    public function __construct(Router $router, Template $template, EventManager $eventManager) {
        $this->router = $router;
        $this->template = $template;
        $this->eventManager = $eventManager;
        $this->config = require MARQUES_CONFIG_DIR . '/system.config.php';
    }
    
    /**
     * Führt die Anwendung aus
     */
    public function run() {
        try {
            // Event vor der Anfrageverarbeitung
            $this->eventManager->trigger('before_request');
            
            // Anfrage durch den Router verarbeiten
            $route = $this->router->processRequest();
            $GLOBALS['route'] = $route;
            
            // Event nach dem Routing
            $route = $this->eventManager->trigger('after_routing', $route);
            
            // Inhalt basierend auf der Route abrufen
            $content = new Content();
            $pageData = $content->getPage($route['path']);
            
            // Event vor dem Rendering
            $pageData = $this->eventManager->trigger('before_render', $pageData);
            
            // Seite mit der Template-Engine rendern
            $this->template->render($pageData);
            
            // Event nach dem Rendering
            $this->eventManager->trigger('after_render');
            
        } catch (\Exception $e) {
            // Fehler behandeln
            $this->handleError($e);
        }
    }
    
    /**
     * Behandelt Anwendungsfehler
     */
    private function handleError(\Exception $e) {
        // Event vor der Fehlerbehandlung
        $this->eventManager->trigger('before_error_handle', ['exception' => $e]);
        
        // Logger holen und Fehler loggen
        $logger = $GLOBALS['container']->get(\Marques\Core\Logger::class);
        $logger->error($e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        
        // Einstellungen laden
        $settings = $GLOBALS['container']->get(\Marques\Core\SettingsManager::class);
        $debug_mode = $settings->getSetting('debug', false);
        
        // HTTP-Statuscode bestimmen
        $statusCode = 500;
        if ($e instanceof NotFoundException) {
            $statusCode = 404;
        } elseif ($e instanceof PermissionException) {
            $statusCode = 403;
        }
        
        http_response_code($statusCode);
        
        // Fehlerseite-Template bestimmen
        $errorTemplate = 'errors/error-' . $statusCode;
        
        // Daten für das Template vorbereiten
        $errorData = [
            'title' => 'Fehler ' . $statusCode,
            'content' => $e->getMessage(),
            'error_code' => $statusCode,
            'debug' => $debug_mode,
            'exception' => $e,
            'template' => $errorTemplate,
            'system_settings' => $settings->getAllSettings()
        ];
        
        // Event nach der Fehlerbehandlung
        $errorData = $this->eventManager->trigger('after_error_handle', $errorData);
        
        // Versuchen, Error-Template zu rendern, ansonsten Standard-Fehlerseite
        try {
            $this->template->render($errorData);
        } catch (\Exception $renderException) {
            $this->renderFallbackErrorPage($errorData);
        }
    }
    
    /**
     * Rendert eine Fallback-Fehlerseite
     */
    private function renderFallbackErrorPage($data) {
        $errorCode = $data['error_code'] ?? 500;
        $debug = $data['debug'] ?? false;
        $exception = $data['exception'] ?? null;
        $content = $data['content'] ?? 'Ein unerwarteter Fehler ist aufgetreten.';
        
        // Einfache HTML-Fehlerseite ausgeben
        echo '<!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <title>Fehler ' . $errorCode . '</title>
            <style>
                body { font-family: sans-serif; padding: 20px; }
                .error-container { max-width: 600px; margin: 0 auto; }
                .error-code { font-size: 72px; color: #d9534f; }
            </style>
        </head>
        <body>
            <div class="error-container">
                <h1 class="error-code">' . $errorCode . '</h1>
                <p>' . htmlspecialchars($content) . '</p>
                <a href="/">Zurück zur Startseite</a>
            </div>';
        
        if ($debug && $exception) {
            echo '<div style="margin-top: 30px; padding: 15px; background: #f5f5f5;">
                <h3>Debug-Informationen</h3>
                <p>Datei: ' . htmlspecialchars($exception->getFile()) . '</p>
                <p>Zeile: ' . $exception->getLine() . '</p>
                <pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>
            </div>';
        }
        
        echo '</body></html>';
    }
}