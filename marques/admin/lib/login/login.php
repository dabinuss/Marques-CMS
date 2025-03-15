<?php
/**
 * marques CMS - Admin-Login
 * 
 * Login-Seite für das Admin-Panel.
 *
 * @package marques
 * @subpackage admin
 */

session_start();

// AppConfig initialisieren
$configManager = \Marques\Core\AppConfig::getInstance();

// Konfiguration laden
$system_config = $configManager->load('system') ?: [];

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Benutzer-Objekt erstellen
$user = new \Marques\Core\User();

// Wenn Benutzer bereits eingeloggt ist, zum Dashboard weiterleiten
if ($user->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Login-Verarbeitung
$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        // DEBUG-Logging
        error_log("Login attempt from login.php: $username");
        
        // Login versuchen
        if ($user->login($username, $password)) {
            // Neues CSRF-Token nach Login
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            // Wenn erster Login für Admin, zur Passwort-Änderung zwingen
            if (isset($_SESSION['marques_user']['initial_login']) && $_SESSION['marques_user']['initial_login'] === true) {
                error_log("Redirecting to initial password setup");
                header('Location: user-edit.php?username=admin&initial_setup=true');
                exit;
            }
            
            // Weiterleitung zum Dashboard
            error_log("Redirecting to dashboard");
            header('Location: index.php');
            exit;
        } else {
            $error = 'Ungültiger Benutzername oder Passwort';
            error_log("Login failed: $error");
        }
    }
}

// Überprüfen, ob Admin-Standardpasswort-Meldung angezeigt werden soll
$showAdminDefaultPassword = false;
$users = $configManager->load('users') ?: [];

// Prüfen ob Admin-Account mit leerem/Standard-Passwort existiert
if (isset($users['admin'])) {
    if (empty($users['admin']['password']) || 
        (isset($users['admin']['first_login']) && $users['admin']['first_login'] === true)) {
        $showAdminDefaultPassword = true;
    }
}