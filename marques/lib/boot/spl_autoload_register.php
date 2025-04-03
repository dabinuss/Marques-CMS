<?php

// Stelle sicher, dass MARQUES_ROOT_DIR definiert ist
if (!defined('MARQUES_ROOT_DIR')) {
    throw new Exception('MARQUES_ROOT_DIR ist nicht definiert. Bitte überprüfe deine Konfigurationsdateien.');
}

if (!function_exists('safe_implode')) {
    function safe_implode(string $glue, $array): string {
        if (!is_array($array)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[1] ?? $trace[0];
            $file = $caller['file'] ?? 'unbekannte Datei';
            $line = $caller['line'] ?? 'unbekannte Zeile';
            throw new Exception("safe_implode erwartet ein Array, aber " . gettype($array) . " übergeben in $file, Zeile $line.");
        }
        return implode($glue, $array);
    }
}

/**
 * spl_autoload_register
 * 
 * Autoloads classes from the Marques namespace.
 * Folgt der Konvention: Ordnernamen sind kleingeschrieben, Dateien folgen dem CamelCase-Format.
 */
spl_autoload_register(function (string $class): void {
    // Caching der bereits geladenen Klassen
    static $classCache = [];
    
    if (isset($classCache[$class])) {
        return; // Klasse wurde bereits geladen
    }
    
    // Namespace-Mapping
    $namespaceMap = [
        'Marques\\' => MARQUES_ROOT_DIR . '/lib/',
        'Marques\\Admin\\' => MARQUES_ADMIN_DIR . '/lib/',
        'FlatFileDB\\' => MARQUES_ROOT_DIR . '/lib/flatfiledb/'
    ];
    
    // Prüfe, ob die Klasse zu einem bekannten Namespace gehört
    foreach ($namespaceMap as $prefix => $dir) {
        // Wenn die Klasse mit diesem Namespace beginnt
        if (strpos($class, $prefix) === 0) {
            // Extrahiere den relativen Klassenpfad (ohne Namespace-Präfix)
            $relativeClass = substr($class, strlen($prefix));
            
            // Klassenname für die Datei (CamelCase behalten)
            $className = basename(str_replace('\\', '/', $relativeClass));
            
            // Pfad für die Ordnerstruktur (in Kleinbuchstaben)
            $relativePath = dirname(str_replace('\\', '/', $relativeClass));
            $relativePath = $relativePath === '.' ? '' : strtolower($relativePath) . '/';
            
            // Zusammengesetzter Pfad: Basisverzeichnis + Ordnerstruktur klein + Klassendatei CamelCase
            $filePath = $dir . $relativePath . $className . '.php';
            
            if (file_exists($filePath)) {
                require_once $filePath;
                $classCache[$class] = true;
                return;
            }
            
            // Fehlerbehandlung - nur einen sinnvollen Pfad anzeigen
            throw new Exception(
                "Autoloader konnte die Klasse '{$class}' nicht laden. " .
                "Gesuchter Pfad: {$filePath}"
            );
        }
    }
});