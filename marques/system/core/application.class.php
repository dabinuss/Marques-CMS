<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Hauptanwendungsklasse
 */
class Application extends Core {
    private Router $router;
    private Template $template;
    private EventDispatcher $eventDispatcher;
    private ConfigManager $configManager;

    /**
     * Konstruktor mit vereinfachtem DI
     */
    public function __construct(Docker $docker) {
        parent::__construct($docker);
        $this->router = $this->resolve('route_manager');
        $this->template = $this->resolve('template_renderer');
        $this->eventDispatcher = $this->resolve('event_dispatcher');
        $this->configManager = $this->resolve('config');
    }

    /**
     * Führt die Anwendung aus
     */
    public function run(): void {
        try {
            $this->eventDispatcher->dispatch('onRequestStart');

            $route = $this->router->processRequest();

            $route = $this->eventDispatcher->dispatch('onRouteResolved', $route);

            $content = new Content($this->docker);

            $pageData = $content->getPage($route['path']);

            $pageData = $this->eventDispatcher->dispatch('onBeforeRender', $pageData);

            $this->template->render($pageData);
            $this->eventDispatcher->dispatch('onAfterRender');

        } catch (\Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Behandelt Anwendungsfehler
     */
    private function handleError(\Exception $e): void {
        $this->eventDispatcher->dispatch('before_error_handle', ['exception' => $e]);

        $settings = $this->resolve('settings_manager');
        $debug_mode = $settings->getSetting('debug', false);

        $statusCode = 500;
        if ($e instanceof NotFoundException) {
            $statusCode = 404;
        } elseif ($e instanceof PermissionException) {
            $statusCode = 403;
        }

        http_response_code($statusCode);

        $errorTemplate = 'errors/error-' . $statusCode;

        $errorData = [
            'title' => 'Fehler ' . $statusCode,
            'content' => $e->getMessage(),
            'error_code' => $statusCode,
            'debug' => $debug_mode,
            'exception' => $e,
            'template' => $errorTemplate,
            'system_settings' => $settings->getAllSettings()
        ];

        $errorData = $this->eventDispatcher->dispatch('after_error_handle', $errorData);

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
                <a href="' . marques_site_url() . '">Zurück zur Startseite</a>
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