<?php
/**
 * marques CMS - Benutzerverwaltung
 * 
 * Verwaltung der Benutzer im Admin-Panel.
 *
 * @package marques
 * @subpackage admin
 */

// Basispfad definieren
define('MARQUES_ROOT_DIR', dirname(__DIR__));
define('IS_ADMIN', true);

// Bootstrap laden
require_once MARQUES_ROOT_DIR . '/system/core/Bootstrap.inc.php';

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

// Benutzer löschen
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $username = $_POST['username'] ?? '';
        
        // Verhindern, dass der Admin-Account gelöscht wird
        if ($username === 'admin') {
            $error_message = 'Der Administrator-Account kann nicht gelöscht werden.';
        } elseif ($username === $user->getCurrentUsername()) {
            $error_message = 'Sie können Ihren eigenen Account nicht löschen.';
        } else {
            if ($user->deleteUser($username)) {
                $success_message = 'Benutzer erfolgreich gelöscht.';
            } else {
                $error_message = 'Fehler beim Löschen des Benutzers.';
            }
        }
    }
}

// Alle Benutzer laden
$all_users = $user->getAllUsers();

// Seitentitel festlegen
$page_title = 'Benutzer verwalten';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title); ?> - Admin-Panel - marques CMS</title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
    <div class="admin-layout">
        
        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title"><?= htmlspecialchars($page_title); ?></h2>
                
                <div class="admin-actions">
                    <a href="user-edit.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-plus"></i></span>
                        Neuer Benutzer
                    </a>
                </div>
            </div>
            
            <?php if (!empty($success_message)): ?>
                <div class="admin-alert success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="admin-alert error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <div class="admin-card">
                <div class="admin-card-content">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Benutzername</th>
                                <th>Anzeigename</th>
                                <th>Rolle</th>
                                <th>Erstellt am</th>
                                <th>Letzter Login</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_users as $username => $userData): ?>
                                <tr>
                                    <td><?= htmlspecialchars($username); ?></td>
                                    <td><?= htmlspecialchars($userData['display_name']); ?></td>
                                    <td>
                                        <?php 
                                        switch ($userData['role']) {
                                            case 'admin':
                                                echo '<span class="role-badge role-admin">Administrator</span>';
                                                break;
                                            case 'editor':
                                                echo '<span class="role-badge role-editor">Editor</span>';
                                                break;
                                            case 'author':
                                                echo '<span class="role-badge role-author">Autor</span>';
                                                break;
                                            default:
                                                echo htmlspecialchars($userData['role']);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($userData['created'])) {
                                            echo date('d.m.Y H:i', $userData['created']); 
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($userData['last_login'])) {
                                            echo date('d.m.Y H:i', $userData['last_login']); 
                                        } else {
                                            echo 'Noch nie';
                                        }
                                        ?>
                                    </td>
                                    <td class="actions">
                                        <a href="user-edit.php?username=<?= urlencode($username); ?>" class="action-btn edit" title="Bearbeiten">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <?php if ($username !== 'admin'): ?>
                                            <form method="post" action="" onsubmit="return confirm('Möchten Sie den Benutzer <?= htmlspecialchars($username); ?> wirklich löschen?');" style="display: inline;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="username" value="<?= htmlspecialchars($username); ?>">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                                                <button type="submit" class="action-btn delete" title="Löschen">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>