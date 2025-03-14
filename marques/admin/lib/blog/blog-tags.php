<?php
/**
 * marques CMS - Tags-Verwaltung
 * 
 * Verwaltung von Blog-Tags.
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

// BlogManager initialisieren
$blogManager = new \Marques\Core\BlogManager();
$blogManager->initCatalogFiles();

// Konfiguration laden
$configManager = \Marques\Core\AppConfig::getInstance();
$system_config = $configManager->load('system') ?: [];

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message = '';

// Tags abrufen
$tags = $blogManager->getTags();

// Aktion verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $action = $_POST['action'] ?? '';
        
        // Neuen Tag hinzufügen
        if ($action === 'add' && !empty($_POST['tag_name'])) {
            $tag_name = trim($_POST['tag_name']);
            
            // Prüfen, ob Tag bereits existiert
            if (isset($tags[$tag_name])) {
                $error_message = 'Dieser Tag existiert bereits.';
            } else {
                if ($blogManager->addTag($tag_name)) {
                    $success_message = 'Tag erfolgreich hinzugefügt: ' . htmlspecialchars($tag_name);
                    // Tags neu laden
                    $tags = $blogManager->getTags();
                } else {
                    $error_message = 'Fehler beim Hinzufügen des Tags.';
                }
            }
        }
        // Tag umbenennen
        else if ($action === 'rename' && !empty($_POST['old_name']) && !empty($_POST['new_name'])) {
            $old_name = trim($_POST['old_name']);
            $new_name = trim($_POST['new_name']);
            
            // Prüfen, ob alte Tag existiert
            if (!isset($tags[$old_name])) {
                $error_message = 'Der zu ändernde Tag existiert nicht.';
            }
            // Prüfen, ob neue Tag bereits existiert
            else if ($old_name !== $new_name && isset($tags[$new_name])) {
                $error_message = 'Der neue Tag existiert bereits.';
            } else {
                if ($blogManager->renameTag($old_name, $new_name)) {
                    $success_message = 'Tag erfolgreich umbenannt von "' . htmlspecialchars($old_name) . '" zu "' . htmlspecialchars($new_name) . '".';
                    // Tags neu laden
                    $tags = $blogManager->getTags();
                } else {
                    $error_message = 'Fehler beim Umbenennen des Tags.';
                }
            }
        }
        // Tag löschen
        else if ($action === 'delete' && !empty($_POST['tag_name'])) {
            $tag_name = trim($_POST['tag_name']);
            
            if (!isset($tags[$tag_name])) {
                $error_message = 'Der zu löschende Tag existiert nicht.';
            } else {
                if ($blogManager->deleteTag($tag_name)) {
                    $success_message = 'Tag erfolgreich gelöscht.';
                    // Tags neu laden
                    $tags = $blogManager->getTags();
                } else {
                    $error_message = 'Fehler beim Löschen des Tags.';
                }
            }
        }
    }
}

// Nach Anzahl der Posts sortieren (absteigend)
arsort($tags);

?>
<!DOCTYPE html>
<html lang="<?= $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tags verwalten - Admin-Panel - <?= htmlspecialchars($system_config['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .tag-list {
            margin-top: 20px;
        }
        
        .tag-item {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .tag-item:last-child {
            border-bottom: none;
        }
        
        .tag-name {
            flex: 1;
            font-weight: 500;
        }
        
        .tag-count {
            margin-right: 20px;
            color: var(--text-light);
            background-color: var(--gray-200);
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        .tag-actions {
            display: flex;
            gap: 10px;
        }
        
        .empty-message {
            padding: 20px;
            text-align: center;
            color: var(--text-light);
        }
        
        .add-form {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }
        
        .add-form input {
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        
        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title">Tags verwalten</h2>
                
                <div class="admin-actions">
                    <a href="blog.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-arrow-left"></i></span>
                        Zurück zum Blog
                    </a>
                </div>
            </div>
            
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3>Neuen Tag hinzufügen</h3>
                </div>
                <div class="admin-card-content">
                    <form method="post" class="add-form form-group">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="text" name="tag_name" class="form-control" placeholder="Tagname" required>
                        <button type="submit" class="admin-button">
                            <span class="admin-button-icon"><i class="fas fa-plus"></i></span>
                            Hinzufügen
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3>Bestehende Tags</h3>
                </div>
                <div class="admin-card-content">
                    <div class="tag-list">
                        <?php if (empty($tags)): ?>
                            <div class="empty-message">Keine Tags gefunden.</div>
                        <?php else: ?>
                            <?php foreach ($tags as $name => $count): ?>
                                <div class="tag-item">
                                    <div class="tag-name"><?= htmlspecialchars($name); ?></div>
                                    <div class="tag-count"><?= $count; ?> Beiträge</div>
                                    <div class="tag-actions">
                                        <!-- Bearbeitungsformular -->
                                        <form method="post" style="display:inline;" class="form-group">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                                            <input type="hidden" name="action" value="rename">
                                            <input type="hidden" name="old_name" value="<?= htmlspecialchars($name); ?>">
                                            <input type="text" name="new_name" class="form-control" value="<?= htmlspecialchars($name); ?>" required>
                                            <button type="submit" class="admin-button">
                                                <i class="fas fa-save"></i> Umbenennen
                                            </button>
                                        </form>
                                        
                                        <!-- Löschformular -->
                                        <form method="post" style="display:inline;" class="form-group" onsubmit="return confirm('Wirklich löschen? Der Tag wird aus allen zugehörigen Beiträgen entfernt.')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="tag_name" value="<?= htmlspecialchars($name); ?>">
                                            <button type="submit" class="admin-button admin-button-danger">
                                                <i class="fas fa-trash-alt"></i> Löschen
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>