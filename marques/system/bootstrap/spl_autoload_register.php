<?php

/**
 * spl_autoload_register
 * 
 * Autoloads classes from the Marques namespace.
 */

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Marques\\')) {
        return;
    }
    
    static $cache = [];
    
    if (isset($cache[$class])) {
        require_once $cache[$class];
        return;
    }
    
    $relativeClass = substr($class, 8);
    $parts = explode('\\', $relativeClass);
    $className = array_pop($parts);
    $namespacePath = strtolower(implode('/', $parts));
    
    $basePath = MARQUES_ROOT_DIR . '/system/' . $namespacePath . '/';
    $paths = [
        $basePath . $className . '.class.php',
        $basePath . strtolower($className) . '.class.php', // Fallback
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            $cache[$class] = $path;
            require_once $path;
            return;
        }
    }
});