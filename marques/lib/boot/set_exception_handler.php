<?php

use Marques\Core\Logger;

set_exception_handler(function (\Throwable $e) {
    // Logger direkt instanziieren (kein getInstance mehr)
    $logger = new Logger();
    $logger->error('Unhandled Exception: ' . $e->getMessage(), [
        'exception' => $e,
        'trace'     => $e->getTraceAsString(),
    ]);

    // Verwende getCode() statt direktem Zugriff auf die code-Eigenschaft
    $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 
                 ($e->getCode() > 0 ? $e->getCode() : 500);
    
    http_response_code($statusCode);

    // TODO: hole debugstatus aus der datenbank
    $debug = true;

    echo '<!DOCTYPE html><html><head><title>Fehler ' . $statusCode . '</title></head>';
    echo '<body><h1>Fehler ' . $statusCode . '</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>';
    if ($debug) {
        echo '<pre>' . print_r($e, true) . '</pre>';
    }
    echo '</body></html>';
    exit;
});