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
 */
spl_autoload_register(function (string $class): void {
    // Caching der bereits geladenen Klassen
    static $classCache = [];
    
    if (isset($classCache[$class])) {
        return; // Klasse wurde bereits geladen
    }
    
    // Wir brauchen nur ein Basis-Mapping:
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
            
            // Konvertiere Namespace-Separatoren zu Verzeichnis-Separatoren
            $filePath = $dir . str_replace('\\', '/', $relativeClass);
            
            // Mögliche Dateiformate
            $formats = [
                '.class.php',
                '.php'
            ];
            
            // Versuche, die Datei in verschiedenen Formaten zu laden
            foreach ($formats as $format) {
                $file = $filePath . $format;
                if (file_exists($file)) {
                    require_once $file;
                    $classCache[$class] = true;
                    return;
                }
                
                // Versuche mit Kleinbuchstaben
                $fileLower = strtolower($filePath) . $format;
                if (file_exists($fileLower)) {
                    require_once $fileLower;
                    $classCache[$class] = true;
                    return;
                }
            }
            
            // Klasse nicht gefunden - sammle und zeige die gesuchten Pfade
            $searchedPaths = [];
            foreach ($formats as $format) {
                $searchedPaths[] = $filePath . $format;
                $searchedPaths[] = strtolower($filePath) . $format;
            }
            
            // Werfe eine Exception mit den gesuchten Pfaden
            throw new Exception(
                "Autoloader konnte die Klasse '{$class}' nicht laden. " .
                "Gesuchte Pfade: " . implode(', ', $searchedPaths)
            );
        }
    }
});