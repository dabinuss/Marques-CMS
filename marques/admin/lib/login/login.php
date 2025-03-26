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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use Marques\Admin\MarquesAdmin;
use Marques\Core\DatabaseHandler;
use Marques\Core\User;
use Marques\Admin\AdminAuthService;

$adminApp = new MarquesAdmin();
$container = $adminApp->getContainer();

$dbHandler = $container->get(DatabaseHandler::class);
$dbHandler->useTable('user');

$authService = $container->get(AdminAuthService::class);

$csrf_token = $authService->generateCsrfToken();

$showAdminDefaultPassword = false;
$usersData = $dbHandler->getAllSettings() ?: [];
if (isset($usersData['admin'])) {
    $adminData = $usersData['admin'];
    if (empty($adminData['password']) || (isset($adminData['first_login']) && $adminData['first_login'] === true)) {
        $showAdminDefaultPassword = true;
    }
}

if ($authService->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !$authService->validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Ungültige oder abgelaufene Anfrage. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($password) && $authService->login($username, $password)) {

            session_regenerate_id(true);
            $csrf_token = $authService->generateCsrfToken(true);

            if (isset($_SESSION['marques_user']['initial_login']) && $_SESSION['marques_user']['initial_login'] === true) {
                header('Location: user-edit.php?username=admin&initial_setup=true');
                exit;
            }

            header('Location: index.php');
            exit;

        } else {
            $error = 'Ungültiger Benutzername oder Passwort.';
        }
    }
    $username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
}