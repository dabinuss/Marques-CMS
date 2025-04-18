<?php
/**
 * marques CMS - Haupteinstiegspunkt
 *
 * Diese Datei dient als Front-Controller für das marques CMS.
 * Alle Anfragen werden über .htaccess-Rewriting hierher geleitet.
 *
 * @package marques
 */

//$file = MARQUES_CONTENT_DIR . '/blog/somefile.md';
//$file = $this->appPath->combine('content', 'blog/somefile.md');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Basispfad definieren
define('MARQUES_ROOT_DIR', __DIR__);

/*
 * Constants definition for admin | FALLBACK FOR ADMIN (DEPRECIATED CONSTANTS)
 */
define('MARQUES_VERSION', '0.3.0'); // FALLBACK
define('MARQUES_SYSTEM_DIR',    MARQUES_ROOT_DIR . '/lib');
define('MARQUES_CONFIG_DIR',    MARQUES_ROOT_DIR . '/config');
define('MARQUES_CONTENT_DIR',   MARQUES_ROOT_DIR . '/content');
define('MARQUES_TEMPLATE_DIR',  MARQUES_ROOT_DIR . '/templates'); /* DEPRECIATED */
define('MARQUES_CACHE_DIR',     MARQUES_SYSTEM_DIR . '/cache');
define('MARQUES_ADMIN_DIR',     MARQUES_ROOT_DIR . '/admin');
define('MARQUES_THEMES_DIR',    MARQUES_ROOT_DIR . '/themes');

// Autoloading
require_once MARQUES_ROOT_DIR . '/lib/bootstrap/spl_autoload_register.php';

// Exception Handling
require_once MARQUES_ROOT_DIR . '/lib/bootstrap/set_exception_handler.php';

// Init and start Marques
require_once MARQUES_ROOT_DIR . '/lib/core/MarquesApp.class.php';

$app = new Marques\Core\MarquesApp();
$app->init();
$app->run();