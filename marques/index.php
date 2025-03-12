<?php
/**
 * marques CMS - Haupteinstiegspunkt
 *
 * Diese Datei dient als Front-Controller für das marques CMS.
 * Alle Anfragen werden über .htaccess-Rewriting hierher geleitet.
 *
 * @package marques
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Basispfad definieren
define('MARQUES_ROOT_DIR', __DIR__);

// Konstanten definieren
define('MARQUES_VERSION', '0.3.0'); // FALLBACK
define('MARQUES_SYSTEM_DIR', MARQUES_ROOT_DIR . '/system');
define('MARQUES_CONFIG_DIR', MARQUES_ROOT_DIR . '/config');
define('MARQUES_CONTENT_DIR', MARQUES_ROOT_DIR . '/content');
define('MARQUES_TEMPLATE_DIR', MARQUES_ROOT_DIR . '/templates'); /* DEPRECIATED */
define('MARQUES_CACHE_DIR', MARQUES_SYSTEM_DIR . '/cache');
define('MARQUES_ADMIN_DIR', MARQUES_ROOT_DIR . '/admin');
define('MARQUES_THEMES_DIR', MARQUES_ROOT_DIR . '/themes');

// Autoloading *vor* allem anderen
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

require_once MARQUES_ROOT_DIR . '/system/core/MarquesApp.class.php';

$app = new Marques\Core\MarquesApp();
$app->init();
$app->run();