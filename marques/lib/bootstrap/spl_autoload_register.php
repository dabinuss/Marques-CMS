<?php

/**
 * spl_autoload_register
 * 
 * Autoloads classes from the Marques namespace.
 */

 spl_autoload_register(function (string $class): void {
    // Definiere eine Whitelist mit den erlaubten Namespaces und deren Basisverzeichnissen.
    $whitelist = [
        'Marques\\'   => MARQUES_ROOT_DIR . '/lib/',
        'FlatFileDB\\' => MARQUES_ROOT_DIR . '/lib/flatfiledb/',
    ];

    // Caching: Bereits aufgelöste Klassenpfade werden zwischengespeichert.
    static $cache = [];

    if (isset($cache[$class])) {
        require_once $cache[$class];
        return;
    }

    // Durchlaufe alle Whitelist-Einträge.
    foreach ($whitelist as $prefix => $baseDir) {
        // Prüfe, ob die Klasse mit einem der erlaubten Prefixe beginnt.
        if (str_starts_with($class, $prefix)) {
            // Entferne den Namespace-Prefix aus dem Klassennamen.
            $relativeClass = substr($class, strlen($prefix));
            // Zerlege den Rest in einzelne Teile.
            $parts = explode('\\', $relativeClass);
            // Das letzte Element ist der eigentliche Klassenname.
            $className = array_pop($parts);
            // Falls vorhanden, wird der Namespace-Pfad in Kleinbuchstaben umgewandelt.
            $namespacePath = !empty($parts) ? strtolower(implode('/', $parts)) . '/' : '';

            // Definiere mögliche Dateipfade:
            // 1. Originalfall: Klassenname + ".class.php"
            // 2. Fallback: Klassenname in Kleinbuchstaben + ".class.php"
            // 3. Alternative: Klassenname + ".php"
            // 4. Alternative Fallback: Klassenname in Kleinbuchstaben + ".php"
            $paths = [
                $baseDir . $namespacePath . $className . '.class.php',
                $baseDir . $namespacePath . strtolower($className) . '.class.php',
                $baseDir . $namespacePath . $className . '.php',
                $baseDir . $namespacePath . strtolower($className) . '.php',
            ];

            foreach ($paths as $path) {
                if (file_exists($path)) {
                    $cache[$class] = $path;
                    require_once $path;
                    return;
                }
            }
            // Wenn der Namespace erlaubt ist, aber keine Datei gefunden wurde, 
            // können wir hier auch ein Log schreiben oder einen Fehler werfen.
            return;
        }
    }
});