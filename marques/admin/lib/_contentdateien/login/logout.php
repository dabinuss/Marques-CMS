<?php
/**
 * marques CMS - Admin-Logout
 * 
 * Loggt den Benutzer aus dem Admin-Panel aus.
 *
 * @package marques
 * @subpackage admin
 */

// Stelle sicher, dass die Session gestartet wurde
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use Marques\Service\User;
use Admin\Auth\Service;

// Erstelle das User-Modell und initialisiere den Service
$user = new User();
$Service = new Service($user);

// Führe den Logout über den Service durch
$Service->logout();

// Lösche alle Session-Daten
$_SESSION = [];

// Lösche das Session-Cookie, falls vorhanden
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"], 
        $params["secure"], 
        $params["httponly"]
    );
}

// Zerstöre die Session
session_destroy();

// Weiterleitung zur Login-Seite
header('Location: login.php');
exit;
