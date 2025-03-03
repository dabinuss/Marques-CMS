<?php
/**
 * marques CMS - Benutzer bearbeiten/erstellen
 * 
 * Formular zum Erstellen und Bearbeiten von Benutzern.
 *
 * @package marques
 * @subpackage admin
 */

// Basispfad definieren
define('MARCES_ROOT_DIR', dirname(__DIR__));
define('IS_ADMIN', true);

// Bootstrap laden
require_once MARCES_ROOT_DIR . '/system/core/bootstrap.inc.php';

// Admin-Klasse initialisieren
$admin = new \Marques\Core\Admin();
$admin->requireLogin();

// Benutzer-Objekt initialisieren
$user = new \Marques\Core\User();

// Nur Administratoren dürfen auf diese Seite zugreifen
if (!$user->isAdmin()) {
    header('Location: index.php');
    exit;
}

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Meldungsvariablen
$success_message = '';
$error_message = '';

// Prüfen, ob ein Benutzer bearbeitet wird
$edit_mode = isset($_GET['username']) && !empty($_GET['username']);
$username = $edit_mode ? $_GET['username'] : '';
$userData = [];

// Verfügbare Rollen
$available_roles = [
    'admin' => 'Administrator',
    'editor' => 'Editor',
    'author' => 'Autor'
];

// Wenn im Bearbeitungsmodus, Benutzerdaten laden
if ($edit_mode) {
    $userData = $user->getUserInfo($username);
    
    if (!$userData) {
        header('Location: users.php');
        exit;
    }
}

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $form_username = $_POST['username'] ?? '';
        $display_name = $_POST['display_name'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'editor';
        
        // Validieren der Rolle
        if (!array_key_exists($role, $available_roles)) {
            $role = 'editor';
        }
        
        // Daten für Update vorbereiten
        $user_data = [
            'display_name' => $display_name,
            'role' => $role
        ];
        
        // Passwort nur hinzufügen, wenn eines angegeben wurde
        if (!empty($password)) {
            $user_data['password'] = $password;
        }
        
        // Benutzer erstellen oder aktualisieren
        if ($edit_mode) {
            // Benutzer aktualisieren
            if ($user->updateUser($username, $user_data)) {
                $success_message = 'Benutzer erfolgreich aktualisiert.';
                $userData = $user->getUserInfo($username); // Aktualisierte Daten laden
            } else {
                $error_message = 'Fehler beim Aktualisieren des Benutzers.';
            }
        } else {
            // Neuen Benutzer erstellen
            // Benutzername validieren
            if (empty($form_username) || !preg_match('/^[a-zA-Z0-9_]+$/', $form_username)) {
                $error_message = 'Ungültiger Benutzername. Bitte nur Buchstaben, Zahlen und Unterstriche verwenden.';
            } elseif ($user->exists($form_username)) {
                $error_message = 'Ein Benutzer mit diesem Namen existiert bereits.';
            } elseif (empty($password)) {
                $error_message = 'Bitte geben Sie ein Passwort an.';
            } else {
                if ($user->createUser($form_username, $password, $display_name, $role)) {
                    $success_message = 'Benutzer erfolgreich erstellt.';
                    // Zurücksetzen des Formulars
                    $form_username = '';
                    $display_name = '';
                    $role = 'editor';
                } else {
                    $error_message = 'Fehler beim Erstellen des Benutzers.';
                }
            }
        }
    }
}

// Seitentitel festlegen
$page_title = $edit_mode ? 'Benutzer bearbeiten' : 'Neuen Benutzer erstellen';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Admin-Panel - marques CMS</title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
    <div class="admin-layout">
        
        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title"><?php echo htmlspecialchars($page_title); ?></h2>
                
                <div class="admin-actions">
                    <a href="users.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-arrow-left"></i></span>
                        Zurück zur Übersicht
                    </a>
                </div>
            </div>
            
            <?php if (!empty($success_message)): ?>
                <div class="admin-alert success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="admin-alert error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <div class="admin-card">
                <div class="admin-card-content">
                    <form method="post" action="">
                        <div class="form-group">
                            <label for="username">Benutzername</label>
                            <?php if ($edit_mode): ?>
                                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" readonly class="readonly">
                                <p class="form-hint">Der Benutzername kann nicht geändert werden.</p>
                            <?php else: ?>
                                <input type="text" id="username" name="username" value="<?php echo isset($form_username) ? htmlspecialchars($form_username) : ''; ?>" required>
                                <p class="form-hint">Nur Buchstaben, Zahlen und Unterstriche erlaubt.</p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="display_name">Anzeigename</label>
                            <input type="text" id="display_name" name="display_name" value="<?php echo isset($userData['display_name']) ? htmlspecialchars($userData['display_name']) : (isset($display_name) ? htmlspecialchars($display_name) : ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Passwort <?php echo $edit_mode ? '(leer lassen, um nicht zu ändern)' : ''; ?></label>
                            <input type="password" id="password" name="password" <?php echo $edit_mode ? '' : 'required'; ?>>
                            <?php if ($edit_mode): ?>
                                <p class="form-hint">Lassen Sie dieses Feld leer, wenn Sie das Passwort nicht ändern möchten.</p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="role">Rolle</label>
                            <select id="role" name="role">
                                <?php foreach ($available_roles as $role_value => $role_name): ?>
                                    <option value="<?php echo htmlspecialchars($role_value); ?>" <?php echo (isset($userData['role']) && $userData['role'] === $role_value) || (isset($role) && $role === $role_value) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($edit_mode && isset($userData['created'])): ?>
                            <div class="form-group">
                                <label>Erstellt am</label>
                                <p><?php echo date('d.m.Y H:i', $userData['created']); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($edit_mode && isset($userData['last_login']) && $userData['last_login'] > 0): ?>
                            <div class="form-group">
                                <label>Letzter Login</label>
                                <p><?php echo date('d.m.Y H:i', $userData['last_login']); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="form-group">
                            <button type="submit" class="admin-button">
                                <span class="admin-button-icon"><i class="fas fa-save"></i></span>
                                <?php echo $edit_mode ? 'Benutzer speichern' : 'Benutzer erstellen'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>