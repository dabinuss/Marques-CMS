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

// Überprüfen, ob die Standardpasswortmeldung angezeigt werden soll
$showAdminDefaultPassword = false;
$users = require MARCES_CONFIG_DIR . '/users.config.php';
if (isset($users['admin']) && empty($users['admin']['password'])) {
    $showAdminDefaultPassword = true;
}

?>
<!DOCTYPE html>
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marces CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/login-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
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
        
        <?php if ($showAdminDefaultPassword): ?>
            <div class="info-message">
                <i class="fas fa-info-circle"></i> Standardzugang:
                <ul>
                    <li>Benutzername: admin</li>
                    <li>Passwort: admin</li>
                </ul>
                <p>Bitte ändern Sie das Passwort nach dem ersten Login!</p>
            </div>
        <?php endif; ?>
        
        <form class="login-form" method="post" action="">
            <div class="form-group">
                <label for="username">Benutzername</label>
                <div class="input-icon-wrapper">
                    <span class="input-icon"><i class="fas fa-user"></i></span>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Passwort</label>
                <div class="input-icon-wrapper">
                    <span class="input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" id="password" name="password" required>
                </div>
            </div>
            
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <button type="submit" class="submit-button">
                <i class="fas fa-sign-in-alt"></i> Anmelden
            </button>
        </form>
        
        <div class="login-footer">
            <p>marces CMS v<?php echo MARCES_VERSION; ?></p>
        </div>
    </div>
</body>
</html>