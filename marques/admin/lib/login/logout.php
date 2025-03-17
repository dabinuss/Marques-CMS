<?php
/**
 * marques CMS - Admin-Logout
 * 
 * Loggt den Benutzer aus dem Admin-Panel aus.
 *
 * @package marques
 * @subpackage admin
 */

// Setze HTTP-Sicherheitsheader
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

// Stelle sicher, dass die Session gestartet wurde
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use Marques\Core\User;
use Marques\Admin\AdminAuthService;

// Erstelle das User-Modell und initialisiere den AuthService
$user = new User();
$authService = new AdminAuthService($user);

// Führe den Logout über den AuthService durch
$authService->logout();

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
