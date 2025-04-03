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
        'Marques\\Admin\\' => MARQUES_ROOT_DIR . '/admin/lib/',
        'Marques\\' => MARQUES_ROOT_DIR . '/lib/',

        'Marques\\Core\\' => MARQUES_ROOT_DIR . '/lib/core/',
        'FlatFileDB\\' => MARQUES_ROOT_DIR . '/lib/flatfiledb/'
    ];
    
    // Prüfe, ob die Klasse zu einem bekannten Namespace gehört
    foreach ($namespaceMap as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $relativeClass = substr($class, strlen($prefix));
            $className = basename(str_replace('\\', '/', $relativeClass));
            $relativePath = dirname(str_replace('\\', '/', $relativeClass));
            $relativePath = $relativePath === '.' ? '' : strtolower($relativePath) . '/';
            $filePath = $dir . $relativePath . $className . '.php';
            
            // Debug-Ausgabe
            error_log("Suche nach Klasse: $class");
            error_log("Relativer Pfad: $relativePath");
            error_log("Vollständiger Pfad: $filePath");
            error_log("Datei existiert: " . (file_exists($filePath) ? 'Ja' : 'Nein'));
            
            if (file_exists($filePath)) {
                // ...
            }
        }
    }
});