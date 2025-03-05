<?php
/**
 * marques CMS - Blog-Verwaltung
 * 
 * Listet alle Blog-Beiträge auf und ermöglicht deren Verwaltung.
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

// Konfiguration laden
$configManager = \Marques\Core\ConfigManager::getInstance();
$system_config = $configManager->load('system') ?: [];

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message = '';

// Kategoriefilter
$filter_category = isset($_GET['category']) ? $_GET['category'] : '';

// Löschung verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $post_id = $_POST['post_id'] ?? '';
        
        if ($blogManager->deletePost($post_id)) {
            $success_message = 'Beitrag erfolgreich gelöscht.';
        } else {
            $error_message = 'Fehler beim Löschen des Beitrags.';
        }
    }
}

// Blog-Beiträge abrufen
$posts = $blogManager->getAllPosts(0, 0, $filter_category);

// Kategorien abrufen
$categories = $blogManager->getCategories();

?>
<!DOCTYPE html>
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blog verwalten - Admin-Panel - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .category-badge {
            display: inline-block;
            background-color: var(--primary);
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.8rem;
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.8rem;
        }
        
        .status-published {
            background-color: #28a745;
            color: white;
        }
        
        .status-draft {
            background-color: #6c757d;
            color: white;
        }
        
        .filter-panel {
            background-color: var(--white);
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }
        
        .filter-title {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
        
        .filter-categories {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .filter-category {
            display: inline-block;
            background-color: var(--gray-200);
            padding: 3px 8px;
            border-radius: 3px;
            text-decoration: none;
            color: var(--text);
            font-size: 0.9rem;
        }
        
        .filter-category:hover {
            background-color: var(--primary);
            color: white;
        }
        
        .filter-category.active {
            background-color: var(--primary);
            color: white;
        }
        
        .filter-category-count {
            background-color: rgba(0,0,0,0.1);
            padding: 1px 5px;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-left: 3px;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        
        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title">Blog verwalten</h2>
                
                <div class="admin-actions">
                    <a href="blog-edit.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-plus"></i></span>
                        Neuer Beitrag
                    </a>
                    <a href="../blog" class="admin-button" target="_blank">
                        <span class="admin-button-icon"><i class="fas fa-external-link-alt"></i></span>
                        Blog ansehen
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
            
            <?php if (!empty($categories)): ?>
                <div class="filter-panel">
                    <h3 class="filter-title">Nach Kategorie filtern</h3>
                    <div class="filter-categories">
                        <a href="blog.php" class="filter-category <?php echo empty($filter_category) ? 'active' : ''; ?>">
                            Alle <span class="filter-category-count"><?php echo count($posts); ?></span>
                        </a>
                        <?php foreach ($categories as $category => $count): ?>
                            <a href="blog.php?category=<?php echo urlencode($category); ?>" class="filter-category <?php echo $filter_category === $category ? 'active' : ''; ?>">
                                <?php echo htmlspecialchars($category); ?> <span class="filter-category-count"><?php echo $count; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (empty($posts)): ?>
                <div class="admin-welcome">
                    <h3>Keine Blog-Beiträge gefunden</h3>
                    <p>Erstellen Sie Ihren ersten Blog-Beitrag mit dem Button "Neuer Beitrag".</p>
                </div>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Titel</th>
                            <th>Datum</th>
                            <th>Autor</th>
                            <th>Kategorien</th>
                            <th>Status</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $post): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($post['title']); ?></td>
                                <td><?php echo htmlspecialchars($post['date']); ?></td>
                                <td><?php echo htmlspecialchars($post['author']); ?></td>
                                <td>
                                    <?php foreach ($post['categories'] as $category): ?>
                                        <?php if (!empty($category)): ?>
                                            <span class="category-badge"><?php echo htmlspecialchars($category); ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $post['status']; ?>">
                                        <?php echo $post['status'] === 'published' ? 'Veröffentlicht' : 'Entwurf'; ?>
                                    </span>
                                </td>
                                <td class="admin-table-actions">
                                    <a href="../blog/<?php echo htmlspecialchars($post['date'] . '/' . $post['slug']); ?>" target="_blank" class="admin-table-action" title="Ansehen">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="blog-edit.php?id=<?php echo htmlspecialchars($post['id']); ?>" class="admin-table-action" title="Bearbeiten">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="blog-versions.php?id=<?php echo htmlspecialchars($post['id']); ?>" class="admin-table-action" title="Versionen">
                                        <i class="fas fa-history"></i>
                                    </a>
                                    <a href="#" class="admin-table-action delete" title="Löschen" data-id="<?php echo htmlspecialchars($post['id']); ?>" data-title="<?php echo htmlspecialchars($post['title']); ?>">
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
            <h3 class="modal-title">Beitrag löschen</h3>
            <div class="modal-body">
                Sind Sie sicher, dass Sie den Beitrag "<span id="deletePost"></span>" löschen möchten?
                Dieser Vorgang kann nicht rückgängig gemacht werden.
            </div>
            <div class="modal-actions">
                <button id="cancelDelete" class="admin-button modal-close">Abbrechen</button>
                <form id="deleteForm" method="post" style="display: inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="post_id" id="deletePostId">
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
            var deletePostElement = document.getElementById('deletePost');
            var deletePostIdInput = document.getElementById('deletePostId');
            
            deleteButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    var postId = this.dataset.id;
                    var postTitle = this.dataset.title;
                    
                    deletePostElement.textContent = postTitle;
                    deletePostIdInput.value = postId;
                    
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