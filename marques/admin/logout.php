<?php
/**
 * marques CMS - Admin-Logout
 * 
 * Loggt den Benutzer aus dem Admin-Panel aus.
 *
 * @package marques
 * @subpackage admin
 */

// Basispfad definieren
define('MARQUES_ROOT_DIR', dirname(__DIR__));

// Bootstrap laden
require_once MARQUES_ROOT_DIR . '/system/core/Bootstrap.inc.php';

// Benutzer-Objekt erstellen
$user = new \Marques\Core\User();

// Benutzer ausloggen
$user->logout();

// Zur Login-Seite weiterleiten
header('Location: login.php');
exit;