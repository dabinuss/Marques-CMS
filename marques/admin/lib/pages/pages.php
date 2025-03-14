<?php
/**
 * marques CMS - Seiten-Verwaltung
 * 
 * Listet alle Seiten auf und ermöglicht deren Verwaltung.
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

// PageManager initialisieren
$pageManager = new \Marques\Core\PageManager();

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

// Löschung verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $page_id = $_POST['page_id'] ?? '';
        
        if ($pageManager->deletePage($page_id)) {
            $success_message = 'Seite erfolgreich gelöscht.';
        } else {
            $error_message = 'Fehler beim Löschen der Seite.';
        }
    }
}

// Seiten abrufen
$pages = $pageManager->getAllPages();

// Nach Titel sortieren
usort($pages, function($a, $b) {
    return strcasecmp($a['title'], $b['title']);
});

?>
<!DOCTYPE html>
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seiten verwalten - Admin-Panel - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
    <div class="admin-layout">

        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title">Seiten verwalten</h2>
                
                <div class="admin-actions">
                    <a href="page-edit.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-plus"></i></span>
                        Neue Seite
                    </a>
                    <a href="../" class="admin-button" target="_blank">
                        <span class="admin-button-icon"><i class="fas fa-external-link-alt"></i></span>
                        Website ansehen
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
            
            <?php if (empty($pages)): ?>
                <div class="admin-welcome">
                    <h3>Keine Seiten gefunden</h3>
                    <p>Erstellen Sie Ihre erste Seite mit dem Button "Neue Seite".</p>
                </div>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Titel</th>
                            <th>Pfad</th>
                            <th>Erstellt</th>
                            <th>Geändert</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $page): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($page['title']); ?></td>
                                <td><?php echo htmlspecialchars($page['path']); ?></td>
                                <td><?php echo htmlspecialchars($page['date_created']); ?></td>
                                <td><?php echo htmlspecialchars($page['date_modified']); ?></td>
                                <td class="admin-table-actions">
                                    <a href="../<?php echo htmlspecialchars($page['path']); ?>" target="_blank" class="admin-table-action" title="Ansehen">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="page-edit.php?id=<?php echo htmlspecialchars($page['id']); ?>" class="admin-table-action" title="Bearbeiten">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="page-versions.php?id=<?php echo htmlspecialchars($page['id']); ?>" class="admin-table-action" title="Versionen">
                                        <i class="fas fa-history"></i>
                                    </a>
                                    <a href="#" class="admin-table-action delete" title="Löschen" data-id="<?php echo htmlspecialchars($page['id']); ?>" data-title="<?php echo htmlspecialchars($page['title']); ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Lösch-Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Seite löschen</h3>
            <div class="modal-body">
                Sind Sie sicher, dass Sie die Seite "<span id="deletePage"></span>" löschen möchten?
                Dieser Vorgang kann nicht rückgängig gemacht werden.
            </div>
            <div class="modal-actions">
                <button id="cancelDelete" class="admin-button modal-close">Abbrechen</button>
                <form id="deleteForm" method="post" style="display: inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="page_id" id="deletePageId">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <button type="submit" class="admin-button modal-delete">Löschen</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Modal-Funktionalität
        document.addEventListener('DOMContentLoaded', function() {
            var modal = document.getElementById('deleteModal');
            var deleteButtons = document.querySelectorAll('.admin-table-action.delete');
            var cancelButton = document.getElementById('cancelDelete');
            var deletePageElement = document.getElementById('deletePage');
            var deletePageIdInput = document.getElementById('deletePageId');
            
            deleteButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    var pageId = this.dataset.id;
                    var pageTitle = this.dataset.title;
                    
                    deletePageElement.textContent = pageTitle;
                    deletePageIdInput.value = pageId;
                    
                    modal.style.display = 'block';
                });
            });
            
            cancelButton.addEventListener('click', function() {
                modal.style.display = 'none';
            });
            
            window.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>