<?php
/**
 * marques CMS - Admin-Login
 * 
 * Login-Seite für das Admin-Panel.
 *
 * @package marques
 * @subpackage admin
 */

// HTTP Security Headers setzen
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

session_start();

// AppConfig initialisieren
$configManager = \Marques\Core\AppConfig::getInstance();
$system_config = $configManager->load('system') ?: [];

// Einbinden der benötigten Klassen
use Marques\Core\User;
use Marques\Admin\AdminAuthService;

$user = new User();
$authService = new AdminAuthService($user);

// Verwende den existierenden CSRF-Token oder generiere einen, falls noch nicht vorhanden
$csrf_token = $authService->generateCsrfToken();

// Bestimme, ob die Standardpasswort-Meldung für den Admin angezeigt werden soll
$showAdminDefaultPassword = false;
$users = $configManager->load('users') ?: [];
if (isset($users['admin'])) {
    if (empty($users['admin']['password']) || (isset($users['admin']['first_login']) && $users['admin']['first_login'] === true)) {
        $showAdminDefaultPassword = true;
    }
}

// Falls bereits eingeloggt, leite zum Dashboard weiter
if ($authService->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token validieren
    if (!isset($_POST['csrf_token']) || !$authService->validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if ($authService->login($username, $password)) {
            // Nach erfolgreichem Login den CSRF-Token beibehalten oder erneuern (optional)
            // $authService->generateCsrfToken();
            
            if (isset($_SESSION['marques_user']['initial_login']) && $_SESSION['marques_user']['initial_login'] === true) {
                header('Location: user-edit.php?username=admin&initial_setup=true');
                exit;
            }
            
            header('Location: index.php');
            exit;
        } else {
            $error = 'Ungültiger Benutzername oder Passwort';
        }
    }
}