<?php
/**
 * marces CMS - Haupteinstiegspunkt
 * 
 * Diese Datei dient als Front-Controller für das marces CMS.
 * Alle Anfragen werden über .htaccess-Rewriting hierher geleitet.
 *
 * @package marces
 * @version 0.1.0
 */

// Basispfad definieren
define('MARCES_ROOT_DIR', __DIR__);

// Bootstrap laden
require_once MARCES_ROOT_DIR . '/system/core/bootstrap.inc.php';

// Anwendung initialisieren
$app = new Marces\Core\Application();
$app->run();