<?php
/**
 * marques CMS - Admin-Login
 * 
 * Login-Seite für das Admin-Panel.
 *
 * @package marques
 * @subpackage admin
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$csrf_token = $Service->generateCsrfToken();

$showAdminDefaultPassword = false;
try {
    // Direkt $dbHandler verwenden
    $adminUserData = $dbHandler->table(\Marques\Data\Database\Config::TABLE_USER) // Konstante verwenden!
                               ->where('username', '=', 'admin')
                               ->first();
    if ($adminUserData && (empty($adminUserData['password']) || ($adminUserData['first_login'] ?? false) === true)) {
        $showAdminDefaultPassword = true;
    }
} catch (\Exception $e) {
    error_log("Login Template: Error checking admin default password: " . $e->getMessage());
    // Fehlerbehandlung im Template ist schwierig, evtl. nur Loggen
}

if ($Service->isLoggedIn()) {
    // Im Template ist ein header()-Redirect schwierig und unsauber.
    // Das sollte *vor* dem Rendern in MarquesAdmin passieren.
    // header('Location: index.php');
    // exit;
    echo "<p>Bereits eingeloggt. <a href='index.php'>Zum Dashboard</a></p>"; // Sicherere Alternative im Template
    header('Location: index.php');
    exit;
}


$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !$Service->validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Ungültige oder abgelaufene Anfrage. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($password) && $Service->login($username, $password)) {

            session_regenerate_id(true);
            $csrf_token = $Service->generateCsrfToken();

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