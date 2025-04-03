<?php
declare(strict_types=1);

namespace Marques\Util;

use Marques\Core\Logger;

/**
 * Zentraler Exception-Handler für bessere Fehlerdarstellung
 */
class ExceptionHandler
{
    private bool $debugMode;
    private ?Logger $logger;
    
    /**
     * @param bool $debugMode Ob Details angezeigt werden sollen
     * @param Logger|null $logger Logger-Instanz oder null
     */
    public function __construct(bool $debugMode = false, ?Logger $logger = null)
    {
        $this->debugMode = $debugMode;
        $this->logger = $logger;
    }
    
    /**
     * Registriert den Handler für die Anwendung
     */
    public function register(): void
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
    }
    
    /**
     * Behandelt Exceptions und zeigt detaillierte Informationen im Debug-Modus
     */
    public function handleException(\Throwable $exception): void
    {
        $this->logException($exception);
        
        // HTTP-Statuscode setzen
        $statusCode = $this->getStatusCodeFromException($exception);
        http_response_code($statusCode);
        
        // Im Admin-Bereich? Dann spezielle Behandlung
        if ($this->isAdminRequest()) {
            $this->handleAdminException($exception);
            return;
        }
        
        // Im Debug-Modus detaillierte Informationen anzeigen
        if ($this->debugMode) {
            $this->renderDebugExceptionPage($exception);
        } else {
            $this->renderProductionExceptionPage($exception);
        }
    }
    
    /**
     * Behandelt PHP-Fehler und konvertiert sie zu Exceptions
     */
    public function handleError(int $level, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $level)) {
            // Dieser Fehlertyp ist nicht in der aktuellen Fehlerberichterstattung
            return false;
        }
        
        throw new \ErrorException($message, 0, $level, $file, $line);
    }
    
    /**
     * Loggt die Exception
     */
    private function logException(\Throwable $exception): void
    {
        if ($this->logger) {
            $context = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'code' => $exception->getCode(),
            ];
            
            $this->logger->error($exception->getMessage(), $context);
        }
    }
    
    /**
     * Gibt HTTP-Statuscode basierend auf Exception-Typ zurück
     */
    private function getStatusCodeFromException(\Throwable $exception): int
    {
        $code = $exception->getCode();
        
        if ($code >= 400 && $code < 600) {
            return $code;
        }
        
        // Spezifische Exceptions auf passende HTTP-Codes mappen
        if ($exception instanceof \InvalidArgumentException) {
            return 400;
        }
        
        if ($exception instanceof \UnexpectedValueException) {
            return 400;
        }
        
        if ($exception instanceof \RuntimeException) {
            return 500;
        }
        
        return 500; // Standard: Internal Server Error
    }
    
    /**
     * Prüft, ob die aktuelle Anfrage zum Admin-Bereich gehört
     */
    private function isAdminRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return strpos($uri, '/admin') === 0 || strpos($uri, MARQUES_ADMIN_DIR ?? 'admin') === 0;
    }
    
    /**
     * Behandelt Exceptions im Admin-Bereich
     */
    private function handleAdminException(\Throwable $exception): void
    {
        // Versuchen, AdminTemplate zu verwenden, falls verfügbar
        if (class_exists('\\Marques\\Admin\\AdminTemplate') && $this->getAdminTemplate()) {
            try {
                $data = [
                    'error_code' => $this->getStatusCodeFromException($exception),
                    'error_message' => $exception->getMessage(),
                    'exception_details' => $this->debugMode ? [
                        'class' => get_class($exception),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'trace' => $this->formatTrace($exception)
                    ] : null
                ];
                
                $this->getAdminTemplate()->render($data, 'error');
                return;
            } catch (\Throwable $e) {
                // Fallback, wenn Template-Rendering fehlschlägt
            }
        }
        
        // Fallback zur normalen Fehleranzeige
        if ($this->debugMode) {
            $this->renderDebugExceptionPage($exception);
        } else {
            $this->renderProductionExceptionPage($exception);
        }
    }
    
    /**
     * Versucht, die AdminTemplate-Instanz zu holen
     */
    private function getAdminTemplate()
    {
        // Dies ist ein Beispiel - der genaue Weg hängt von deiner Container-Implementierung ab
        global $adminContainer;
        
        if ($adminContainer && method_exists($adminContainer, 'get')) {
            try {
                return $adminContainer->get('\\Marques\\Admin\\AdminTemplate');
            } catch (\Throwable $e) {
                return null;
            }
        }
        
        return null;
    }
    
    /**
     * Formatiert den Stack Trace für die Anzeige
     */
    private function formatTrace(\Throwable $exception): array
    {
        $frames = [];
        foreach ($exception->getTrace() as $i => $frame) {
            $frames[] = [
                'index' => $i,
                'file' => $frame['file'] ?? '(internal function)',
                'line' => $frame['line'] ?? '',
                'function' => (isset($frame['class']) ? $frame['class'] . $frame['type'] : '') . $frame['function'],
                'args' => $this->formatArgs($frame['args'] ?? [])
            ];
        }
        return $frames;
    }
    
    /**
     * Formatiert die Funktionsargumente für die Anzeige
     */
    private function formatArgs(array $args): array
    {
        return array_map(function($arg) {
            if (is_scalar($arg)) {
                return is_string($arg) ? "'" . htmlspecialchars(substr($arg, 0, 100)) . "'" : $arg;
            }
            
            if (is_array($arg)) {
                return 'Array(' . count($arg) . ')';
            }
            
            if (is_object($arg)) {
                return 'Object(' . get_class($arg) . ')';
            }
            
            if (is_resource($arg)) {
                return 'Resource(' . get_resource_type($arg) . ')';
            }
            
            return gettype($arg);
        }, $args);
    }
    
    /**
     * Zeigt eine detaillierte Fehlerseite im Debug-Modus
     */
    private function renderDebugExceptionPage(\Throwable $exception): void
    {
        $exceptionClass = get_class($exception);
        $exceptionMessage = htmlspecialchars($exception->getMessage());
        $exceptionFile = $exception->getFile();
        $exceptionLine = $exception->getLine();
        $trace = $this->formatTrace($exception);
        
        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fehler {$this->getStatusCodeFromException($exception)}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; padding: 20px; max-width: 1200px; margin: 0 auto; }
        h1 { color: #e74c3c; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        h2 { margin-top: 25px; color: #3498db; }
        .exception { background: #f8f8f8; border-left: 4px solid #e74c3c; padding: 10px 15px; margin: 20px 0; }
        .exception-message { font-family: monospace; font-size: 16px; margin: 10px 0; color: #e74c3c; }
        .exception-location { font-size: 14px; color: #7f8c8d; margin-bottom: 15px; }
        .trace-table { width: 100%; border-collapse: collapse; }
        .trace-table th, .trace-table td { text-align: left; padding: 8px; border-bottom: 1px solid #ddd; }
        .trace-table th { background-color: #f2f2f2; }
        .trace-file { color: #3498db; }
        .trace-line { color: #e67e22; }
        .trace-function { font-family: monospace; }
        .trace-args { color: #7f8c8d; font-size: 0.9em; }
        .file-excerpt { background: #f9f9f9; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin: 15px 0; overflow: auto; }
        .file-excerpt pre { margin: 0; font-family: monospace; font-size: 14px; }
        .line-highlight { background-color: #ffe0e0; display: block; }
    </style>
</head>
<body>
    <h1>Fehler {$this->getStatusCodeFromException($exception)}</h1>
    
    <div class="exception">
        <div class="exception-message">{$exceptionClass}: {$exceptionMessage}</div>
        <div class="exception-location">
            In Datei <strong>{$exceptionFile}</strong> in Zeile <strong>{$exceptionLine}</strong>
        </div>
    </div>
    
    <h2>Quelldatei</h2>
    {$this->getFileExcerpt($exceptionFile, $exceptionLine)}
    
    <h2>Stack Trace</h2>
    <table class="trace-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Datei:Zeile</th>
                <th>Funktion</th>
                <th>Argumente</th>
            </tr>
        </thead>
        <tbody>
HTML;

        foreach ($trace as $frame) {
            $fileInfo = isset($frame['file']) ? 
                "<span class='trace-file'>{$frame['file']}</span>:" .
                "<span class='trace-line'>{$frame['line']}</span>" : 
                "(internal function)";
                
            $functionName = htmlspecialchars($frame['function']);
            
            $argsHtml = '';
            foreach ($frame['args'] as $arg) {
                $argsHtml .= "<span class='trace-args'>" . htmlspecialchars((string)$arg) . "</span>, ";
            }
            $argsHtml = rtrim($argsHtml, ', ');
            
            echo <<<HTML
            <tr>
                <td>{$frame['index']}</td>
                <td>{$fileInfo}</td>
                <td class="trace-function">{$functionName}()</td>
                <td>{$argsHtml}</td>
            </tr>
HTML;
        }
        
        echo <<<HTML
        </tbody>
    </table>
    
    <h2>Server</h2>
    <table class="trace-table">
        <tbody>
HTML;
        
        foreach ($_SERVER as $key => $value) {
            if (is_string($value)) {
                $key = htmlspecialchars($key);
                $value = htmlspecialchars($value);
                echo "<tr><td>{$key}</td><td>{$value}</td></tr>";
            }
        }
        
        echo <<<HTML
        </tbody>
    </table>
</body>
</html>
HTML;
    }
    
    /**
     * Zeigt eine einfache Fehlerseite im Produktionsmodus
     */
    private function renderProductionExceptionPage(\Throwable $exception): void
    {
        $statusCode = $this->getStatusCodeFromException($exception);
        
        // Im Produktionsmodus zeigen wir keine technischen Details
        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fehler {$statusCode}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; padding: 20px; max-width: 800px; margin: 0 auto; text-align: center; }
        h1 { color: #e74c3c; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        p { margin: 20px 0; }
    </style>
</head>
<body>
    <h1>Fehler {$statusCode}</h1>
    <p>Es ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.</p>
</body>
</html>
HTML;
    }
    
    /**
     * Liest einen Abschnitt der Datei um die fehlerverursachende Zeile herum
     */
    private function getFileExcerpt(string $file, int $line, int $context = 10): string
    {
        if (!file_exists($file) || !is_readable($file)) {
            return '<div class="file-excerpt"><pre>Datei nicht lesbar</pre></div>';
        }
        
        $lines = file($file);
        if (!$lines) {
            return '<div class="file-excerpt"><pre>Datei konnte nicht gelesen werden</pre></div>';
        }
        
        $start = max(0, $line - $context - 1);
        $end = min(count($lines), $line + $context);
        
        $excerpt = '';
        for ($i = $start; $i < $end; $i++) {
            $currentLine = $i + 1;
            $lineContent = htmlspecialchars($lines[$i]);
            
            if ($currentLine == $line) {
                $excerpt .= "<span class=\"line-highlight\">{$currentLine}: {$lineContent}</span>";
            } else {
                $excerpt .= "{$currentLine}: {$lineContent}";
            }
        }
        
        return '<div class="file-excerpt"><pre>' . $excerpt . '</pre></div>';
    }
}