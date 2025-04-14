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
ini_set('error_log', __DIR__ . '/error_log.txt');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$rootContainer = require_once __DIR__ . '/lib/boot/bootstrap.php';

require_once MARQUES_ROOT_DIR . '/lib/core/MarquesApp.php';

$app = new \Marques\Core\MarquesApp($rootContainer);
$app->init();
$app->run();