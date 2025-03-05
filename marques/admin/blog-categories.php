<?php
/**
 * marques CMS - Kategorien-Verwaltung
 * 
 * Verwaltung von Blog-Kategorien.
 *
 * @package marques
 * @subpackage admin
 */

// Basispfad definieren
define('MARQUES_ROOT_DIR', dirname(__DIR__));
define('IS_ADMIN', true);

// Bootstrap laden
require_once MARQUES_ROOT_DIR . '/system/core/bootstrap.inc.php';

// Admin-Klasse initialisieren
$admin = new \Marques\Core\Admin();
$admin->requireLogin();

// Benutzer-Objekt initialisieren
$user = new \Marques\Core\User();

// BlogManager initialisieren
$blogManager = new \Marques\Core\BlogManager();
$blogManager->initCatalogFiles();

// Konfiguration laden
$system_config = require MARQUES_CONFIG_DIR . '/system.config.php';

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message = '';

// Kategorien abrufen
$categories = $blogManager->getCategories();

// Aktion verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $action = $_POST['action'] ?? '';
        
        // Neue Kategorie hinzufügen
        if ($action === 'add' && !empty($_POST['category_name'])) {
            $category_name = trim($_POST['category_name']);
            
            // Prüfen, ob Kategorie bereits existiert
            if (isset($categories[$category_name])) {
                $error_message = 'Diese Kategorie existiert bereits.';
            } else {
                // Dummy-Beitrag mit neuer Kategorie erstellen, falls nötig
                // In einer realen Implementierung könnte hier ein separates System für Kategorien genutzt werden
                if ($blogManager->addCategory($category_name)) {
                    $success_message = 'Kategorie erfolgreich hinzugefügt.';
                    // Kategorien neu laden
                    $categories = $blogManager->getCategories();
                } else {
                    $error_message = 'Fehler beim Hinzufügen der Kategorie.';
                }
            }
        }
        // Kategorie umbenennen
        else if ($action === 'rename' && !empty($_POST['old_name']) && !empty($_POST['new_name'])) {
            $old_name = trim($_POST['old_name']);
            $new_name = trim($_POST['new_name']);
            
            // Prüfen, ob alte Kategorie existiert
            if (!isset($categories[$old_name])) {
                $error_message = 'Die zu ändernde Kategorie existiert nicht.';
            }
            // Prüfen, ob neue Kategorie bereits existiert
            else if ($old_name !== $new_name && isset($categories[$new_name])) {
                $error_message = 'Die neue Kategorie existiert bereits.';
            } else {
                if ($blogManager->renameCategory($old_name, $new_name)) {
                    $success_message = 'Kategorie erfolgreich umbenannt.';
                    // Kategorien neu laden
                    $categories = $blogManager->getCategories();
                } else {
                    $error_message = 'Fehler beim Umbenennen der Kategorie.';
                }
            }
        }
        // Kategorie löschen
        else if ($action === 'delete' && !empty($_POST['category_name'])) {
            $category_name = trim($_POST['category_name']);
            
            if (!isset($categories[$category_name])) {
                $error_message = 'Die zu löschende Kategorie existiert nicht.';
            } else {
                if ($blogManager->deleteCategory($category_name)) {
                    $success_message = 'Kategorie erfolgreich gelöscht.';
                    // Kategorien neu laden
                    $categories = $blogManager->getCategories();
                } else {
                    $error_message = 'Fehler beim Löschen der Kategorie.';
                }
            }
        }
    }
}

// Nach Anzahl der Posts sortieren (absteigend)
arsort($categories);

?>
<!DOCTYPE html>
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kategorien verwalten - Admin-Panel - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .category-list {
            margin-top: 20px;
        }
        
        .category-item {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .category-item:last-child {
            border-bottom: none;
        }
        
        .category-name {
            flex: 1;
            font-weight: 500;
        }
        
        .category-count {
            margin-right: 20px;
            color: var(--text-light);
            background-color: var(--gray-200);
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        .category-actions {
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
        
        .edit-form {
            display: flex;
            margin-top: 10px;
            margin-bottom: 10px;
        }
        
        .edit-form input {
            flex: 1;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        
        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title">Kategorien verwalten</h2>
                
                <div class="admin-actions">
                    <a href="blog.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-arrow-left"></i></span>
                        Zurück zum Blog
                    </a>
                </div>
            </div>
            
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3>Neue Kategorie hinzufügen</h3>
                </div>
                <div class="admin-card-content">
                    <form method="post" class="add-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="text" name="category_name" placeholder="Kategoriename" required>
                        <button type="submit" class="admin-button">
                            <span class="admin-button-icon"><i class="fas fa-plus"></i></span>
                            Hinzufügen
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3>Bestehende Kategorien</h3>
                </div>
                <div class="admin-card-content">
                    <div class="category-list">
                        <?php if (empty($categories)): ?>
                            <div class="empty-message">Keine Kategorien gefunden.</div>
                        <?php else: ?>
                            <?php foreach ($categories as $name => $count): ?>
                                <div class="category-item">
                                    <div class="category-name"><?php echo htmlspecialchars($name); ?></div>
                                    <div class="category-count"><?php echo $count; ?> Beiträge</div>
                                    <div class="category-actions">
                                        <!-- Bearbeitungsformular -->
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="rename">
                                            <input type="hidden" name="old_name" value="<?php echo htmlspecialchars($name); ?>">
                                            <input type="text" name="new_name" value="<?php echo htmlspecialchars($name); ?>" required>
                                            <button type="submit" class="admin-button">
                                                <i class="fas fa-save"></i> Umbenennen
                                            </button>
                                        </form>
                                        
                                        <!-- Löschformular -->
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Wirklich löschen? Die Kategorie wird aus allen zugehörigen Beiträgen entfernt.')">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="category_name" value="<?php echo htmlspecialchars($name); ?>">
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