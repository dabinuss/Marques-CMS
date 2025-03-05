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
define('MARQUES_ROOT_DIR', __DIR__);

// Bootstrap laden
require_once MARQUES_ROOT_DIR . '/system/core/Bootstrap.inc.php';

// Abhängigkeiten erstellen
$router = new Marques\Core\Router();
$template = new Marques\Core\Template();
$eventManager = new Marques\Core\EventManager();

// Anwendung initialisieren
$app = new Marques\Core\Application($router, $template, $eventManager);
$app->run();