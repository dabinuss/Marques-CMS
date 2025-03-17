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
$authService = new \Marques\Admin\AdminAuthService();

// Benutzer ausloggen
$authService->logout();

// Zur Login-Seite weiterleiten
header('Location: login.php');
exit;