<?php

use Marques\Core\AppLogger;
use Marques\Core\AppExceptions;
use Marques\Core\AppConfig;
use Marques\Core\AppPath;

set_exception_handler(function (\Throwable $e) {
    // Logger direkt instanziieren (kein getInstance mehr)
    $logger = new AppLogger();
    $logger->error('Unhandled Exception: ' . $e->getMessage(), [
        'exception' => $e,
        'trace'     => $e->getTraceAsString(),
    ]);

    $statusCode = ($e instanceof AppExceptions) ? $e->getStatusCode() : 500;
    http_response_code($statusCode);

    // Erstelle AppConfig, dabei wird intern eine neue AppPath-Instanz genutzt.
    $config = new AppConfig();
    $debug = $config->get('debug', false);

    echo '<!DOCTYPE html><html><head><title>Fehler ' . $statusCode . '</title></head>';
    echo '<body><h1>Fehler ' . $statusCode . '</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>';
    if ($debug) {
        echo '<pre>' . print_r($e, true) . '</pre>';
    }
    echo '</body></html>';
    exit;
});