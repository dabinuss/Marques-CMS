<?php
/**
 * marques CMS - Admin-Logout
 * 
 * Loggt den Benutzer aus dem Admin-Panel aus.
 *
 * @package marques
 * @subpackage admin
 */

// Benutzer-Objekt erstellen
$user = new \Marques\Core\User();

// Benutzer ausloggen
$user->logout();

// Zur Login-Seite weiterleiten
header('Location: login.php');
exit;