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

// CSRF-Token generieren, falls nicht vorhanden
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Erstelle das User-Modell und den AuthService
use Marques\Core\User;
use Marques\Admin\AdminAuthService;

$user = new User();
$authService = new AdminAuthService($user);

// Wenn Benutzer bereits eingeloggt ist, zum Dashboard weiterleiten
if ($authService->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        // Versuche, den Benutzer einzuloggen
        if ($authService->login($username, $password)) {
            // Neues CSRF-Token nach Login generieren
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            // Falls beim Admin der erste Login erfolgt und initial_login gesetzt wurde, weiterleiten zur Passwortänderung
            if (isset($_SESSION['marques_user']['initial_login']) && $_SESSION['marques_user']['initial_login'] === true) {
                header('Location: user-edit.php?username=admin&initial_setup=true');
                exit;
            }
            
            // Erfolgreicher Login, weiterleiten zum Dashboard
            header('Location: index.php');
            exit;
        } else {
            $error = 'Ungültiger Benutzername oder Passwort';
        }
    }
}