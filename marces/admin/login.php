<?php
/**
 * marces CMS - Admin-Login
 * 
 * Login-Seite für das Admin-Panel.
 *
 * @package marces
 * @subpackage admin
 */

// Basispfad definieren
define('MARCES_ROOT_DIR', dirname(__DIR__));

// Bootstrap laden
require_once MARCES_ROOT_DIR . '/system/core/bootstrap.inc.php';

// Konfiguration laden
$system_config = require MARCES_CONFIG_DIR . '/system.config.php';

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Benutzer-Objekt erstellen
$user = new \Marces\Core\User();

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
        
        // Login versuchen
        if ($user->login($username, $password)) {
            // Neues CSRF-Token nach Login
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            // Weiterleitung zum Dashboard
            header('Location: index.php');
            exit;
        } else {
            $error = 'Ungültiger Benutzername oder Passwort';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marces CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/login-style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>marces CMS</h1>
            <p>Admin-Login</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form class="login-form" method="post" action="">
            <div class="form-group">
                <label for="username">Benutzername</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <button type="submit" class="submit-button">Anmelden</button>
        </form>
    </div>
</body>
</html>