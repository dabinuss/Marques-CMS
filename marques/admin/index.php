<?php
declare(strict_types=1);

ini_set('display_errors', 1);

/**
 * marques CMS - Admin-Panel index.php
 * 
 * Haupteinstiegspunkt für das Admin-Panel.
 *
 * @package marques
 * @subpackage admin
 */

// Basispfad definieren
define('MARQUES_ROOT_DIR', dirname(__DIR__));

/*
 * Constants definition for admin | FALLBACK FOR ADMIN (DEPRECIATED CONSTANTS)
 */
define('MARQUES_VERSION', '0.3.0'); // FALLBACK
define('MARQUES_SYSTEM_DIR',    MARQUES_ROOT_DIR . '/system');
define('MARQUES_CONFIG_DIR',    MARQUES_ROOT_DIR . '/config');
define('MARQUES_CONTENT_DIR',   MARQUES_ROOT_DIR . '/content');
define('MARQUES_TEMPLATE_DIR',  MARQUES_ROOT_DIR . '/templates'); /* DEPRECIATED */
define('MARQUES_CACHE_DIR',     MARQUES_SYSTEM_DIR . '/cache');
define('MARQUES_ADMIN_DIR',     MARQUES_ROOT_DIR . '/admin');
define('MARQUES_THEMES_DIR',    MARQUES_ROOT_DIR . '/themes');

// Autoloading
require_once MARQUES_ROOT_DIR . '/system/bootstrap/spl_autoload_register.php';

// Exception Handling
require_once MARQUES_ROOT_DIR . '/system/bootstrap/set_exception_handler.php';

// Bootstrap laden (Autoloader, Konfiguration etc.)
// require_once MARQUES_ROOT_DIR . '/system/core/Bootstrap.inc.php';

// Instanz der Admin-Anwendung erzeugen, initialisieren und starten
$adminApp = new \Marques\Admin\MarquesAdmin();
$adminApp->init();
$adminApp->run();