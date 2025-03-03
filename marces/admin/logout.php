<?php
/**
 * marces CMS - Admin-Logout
 * 
 * Loggt den Benutzer aus dem Admin-Panel aus.
 *
 * @package marces
 * @subpackage admin
 */

// Basispfad definieren
define('MARCES_ROOT_DIR', dirname(__DIR__));

// Bootstrap laden
require_once MARCES_ROOT_DIR . '/system/core/bootstrap.inc.php';

// Benutzer-Objekt erstellen
$user = new \Marces\Core\User();

// Benutzer ausloggen
$user->logout();

// Zur Login-Seite weiterleiten
header('Location: login.php');
exit;