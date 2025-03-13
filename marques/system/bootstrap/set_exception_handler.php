<?php
use Marques\Core\AppLogger;
use Marques\Core\AppUnhandledExceptions;
use Marques\Core\ConfigManager;

/**
 * set_exception_handler
 * 
 * Set the exception handler for unhandled exceptions.
 */

set_exception_handler(function (\Throwable $e) {
    // Singleton-Logger nutzen
    $logger = AppLogger::getInstance();
    $logger->error('Unhandled Exception: ' . $e->getMessage(), [
        'exception' => $e,
        'trace'     => $e->getTraceAsString(),
    ]);

    // HTTP-Statuscode ermitteln
    $statusCode = ($e instanceof AppUnhandledExceptions) ? $e->getStatusCode() : 500;
    http_response_code($statusCode);

    // Debug-Modus aus der Konfiguration holen
    $debug = ConfigManager::getInstance()->get('debug', false);

    echo '<!DOCTYPE html><html><head><title>Fehler ' . $statusCode . '</title></head>';
    echo '<body><h1>Fehler ' . $statusCode . '</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>';
    if ($debug) {
        echo '<pre>' . print_r($e, true) . '</pre>';
    }
    echo '</body></html>';
    exit;
});