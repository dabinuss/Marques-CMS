<?php
/**
 * marques CMS - Haupteinstiegspunkt
 * 
 * Diese Datei dient als Front-Controller für das marques CMS.
 * Alle Anfragen werden über .htaccess-Rewriting hierher geleitet.
 *
 * @package marques
 * @version 0.1.0
 */

// Basispfad definieren
define('MARCES_ROOT_DIR', __DIR__);

// Bootstrap laden
require_once MARCES_ROOT_DIR . '/system/core/bootstrap.inc.php';

// Anwendung initialisieren
$app = new Marques\Core\Application();
$app->run();