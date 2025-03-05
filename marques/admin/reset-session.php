<?php
// Basispfad definieren
define('MARQUES_ROOT_DIR', dirname(__DIR__));

// Bootstrap laden
require_once MARQUES_ROOT_DIR . '/system/core/bootstrap.inc.php';

// Session komplett zurücksetzen
$success = false;
$message = '';

// Prüfen und Session zurücksetzen
if (session_status() === PHP_SESSION_ACTIVE) {
    // Alle Session-Variablen löschen
    $_SESSION = array();

    // Session-Cookie löschen
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Session zerstören
    session_unset();
    session_destroy();
    
    $success = true;
    $message = 'Session erfolgreich zurückgesetzt.';
} else {
    $message = 'Keine aktive Session gefunden.';
}

// Neue Session starten
session_start();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Reset</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            text-align: center;
            padding: 20px;
            background-color: #f0f0f0;
        }
        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
            border: 1px solid #d6e9c6;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
            border: 1px solid #ebccd1;
        }
        .links {
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        .links a {
            display: inline-block;
            padding: 10px 15px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="message <?php echo $success ? 'success' : 'error'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    
    <div class="links">
        <a href="login.php">Zum Login</a>
        <a href="index.php">Zur Startseite</a>
    </div>
</body>
</html>