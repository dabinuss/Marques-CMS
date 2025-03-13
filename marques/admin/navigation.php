<?php
/**
 * marques CMS - Navigation Management
 * 
 * Verwaltung der Website-Navigation (Hauptmenü und Footermenü).
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

// NavigationManager initialisieren
$navManager = new \Marques\Core\NavigationManager();

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

// Standard-Menütyp
$activeMenu = isset($_GET['menu']) && $_GET['menu'] === 'footer' ? 'footer_menu' : 'main_menu';
$activeMenuTitle = $activeMenu === 'main_menu' ? 'Hauptmenü' : 'Footermenü';

// Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $action = $_POST['action'] ?? '';
        
        // Menüpunkt hinzufügen
        if ($action === 'add_item') {
            $menuItem = [
                'title' => $_POST['title'] ?? '',
                'url' => $_POST['url'] ?? '',
                'target' => isset($_POST['target']) && $_POST['target'] === '_blank' ? '_blank' : '_self'
            ];
            
            if (empty($menuItem['title']) || empty($menuItem['url'])) {
                $error_message = 'Bitte füllen Sie alle Felder aus.';
            } else {
                $menuType = $_POST['menu_type'] ?? $activeMenu;
                
                if ($navManager->addMenuItem($menuType, $menuItem)) {
                    $success_message = 'Menüpunkt erfolgreich hinzugefügt.';
                } else {
                    $error_message = 'Fehler beim Hinzufügen des Menüpunkts.';
                }
            }
        }
        // Menüpunkt aktualisieren
        else if ($action === 'update_item') {
            $menuItemId = $_POST['menu_item_id'] ?? '';
            $menuItem = [
                'title' => $_POST['title'] ?? '',
                'url' => $_POST['url'] ?? '',
                'target' => isset($_POST['target']) && $_POST['target'] === '_blank' ? '_blank' : '_self'
            ];
            
            if (empty($menuItemId) || empty($menuItem['title']) || empty($menuItem['url'])) {
                $error_message = 'Bitte füllen Sie alle Felder aus.';
            } else {
                $menuType = $_POST['menu_type'] ?? $activeMenu;
                
                if ($navManager->updateMenuItem($menuType, $menuItemId, $menuItem)) {
                    $success_message = 'Menüpunkt erfolgreich aktualisiert.';
                } else {
                    $error_message = 'Fehler beim Aktualisieren des Menüpunkts.';
                }
            }
        }
        // Menüpunkt löschen
        else if ($action === 'delete_item') {
            $menuItemId = $_POST['menu_item_id'] ?? '';
            
            if (empty($menuItemId)) {
                $error_message = 'Ungültige Menüpunkt-ID.';
            } else {
                $menuType = $_POST['menu_type'] ?? $activeMenu;
                
                if ($navManager->deleteMenuItem($menuType, $menuItemId)) {
                    $success_message = 'Menüpunkt erfolgreich gelöscht.';
                } else {
                    $error_message = 'Fehler beim Löschen des Menüpunkts.';
                }
            }
        }
        // Menü neu ordnen
        else if ($action === 'reorder_menu') {
            $order = isset($_POST['menu_order']) ? json_decode($_POST['menu_order'], true) : [];
            
            if (empty($order)) {
                $error_message = 'Ungültige Sortierreihenfolge.';
            } else {
                $menuType = $_POST['menu_type'] ?? $activeMenu;
                
                if ($navManager->reorderMenu($menuType, $order)) {
                    $success_message = 'Menü erfolgreich neu sortiert.';
                } else {
                    $error_message = 'Fehler beim Sortieren des Menüs.';
                }
            }
        }
    }
}

// Häufig verwendete URLs vorbereiten
$commonUrls = [
    ['title' => 'Startseite', 'url' => marques_site_url()],
    ['title' => 'Blog', 'url' => marques_site_url('blog')],
    ['title' => 'Über uns', 'url' => marques_site_url('about')],
    ['title' => 'Kontakt', 'url' => marques_site_url('contact')]
];

// Menüs laden
$mainMenu = $navManager->getMenu('main_menu');
$footerMenu = $navManager->getMenu('footer_menu');

// Migration des bestehenden Menüs, wenn leer
if (empty($mainMenu) && isset($_GET['migrate']) && $_GET['migrate'] === '1') {
    if ($navManager->migrateExistingMenu()) {
        $success_message = 'Das bestehende Menü wurde erfolgreich migriert.';
        $mainMenu = $navManager->getMenu('main_menu'); // Neu laden
    }
}

?>
<!DOCTYPE html>
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Navigation verwalten - Admin-Panel - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <style>
        .menu-list {
            margin-top: 20px;
            min-height: 50px;
            background-color: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid var(--gray-200);
            background-color: white;
            cursor: move;
            transition: background-color 0.2s;
        }
        
        .menu-item:hover {
            background-color: var(--gray-100);
        }
        
        .menu-item:last-child {
            border-bottom: none;
        }
        
        .menu-item-title {
            flex: 1;
            font-weight: 500;
            color: var(--text-dark);
        }
        
        .menu-item-url {
            margin-right: 20px;
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .menu-item-target {
            margin-right: 20px;
            font-size: 0.8rem;
            padding: 3px 8px;
            background-color: var(--primary);
            color: white;
            border-radius: 3px;
        }
        
        .menu-item-actions {
            display: flex;
            gap: 10px;
        }
        
        .empty-message {
            padding: 20px;
            text-align: center;
            color: var(--text-light);
            background-color: var(--gray-100);
            border-radius: var(--radius);
            margin-bottom: 20px;
        }
        
        .add-form {
            margin-bottom: 20px;
        }
        
        .menu-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
            background-color: var(--white);
            border-radius: var(--radius) var(--radius) 0 0;
            overflow: hidden;
        }
        
        .menu-tab {
            padding: 12px 20px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            color: var(--text-dark);
            text-decoration: none;
            border-bottom: 3px solid transparent;
        }
        
        .menu-tab:hover {
            background-color: var(--gray-100);
            color: var(--primary);
        }
        
        .menu-tab.active {
            border-bottom-color: var(--primary);
            color: var(--primary);
            background-color: var(--gray-100);
        }
        
        .ui-sortable-helper {
            box-shadow: var(--shadow);
        }
        
        .ui-sortable-placeholder {
            background-color: var(--gray-100);
            border: 2px dashed var(--gray-300);
            visibility: visible !important;
            height: 40px;
        }
        
        .quick-urls {
            margin-top: 5px;
        }

        .quick-url-link,
        .quick-url-link-edit {
            display: inline-block;
            font-size: 0.85rem;
            margin-right: 10px;
            color: var(--primary);
            text-decoration: none;
            padding: 2px 8px;
            border-radius: 3px;
            background-color: rgba(74, 111, 165, 0.1);
        }

        .quick-url-link:hover,
        .quick-url-link-edit:hover {
            text-decoration: none;
            background-color: rgba(74, 111, 165, 0.2);
        }
        
        /* Modal Styling */
        .admin-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
            align-items: center;
            justify-content: center;
        }

        .admin-modal-content {
            position: relative;
            background-color: white;
            margin: 10% auto;
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 600px;
            animation: modalFadeIn 0.3s;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .admin-modal-close {
            position: absolute;
            top: 10px;
            right: 20px;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.2s;
        }
        
        .admin-modal-close:hover {
            color: var(--text-dark);
        }

        .admin-modal h2 {
            margin-top: 0;
            margin-bottom: 20px;
            color: var(--primary);
            padding-bottom: 10px;
            border-bottom: 1px solid var(--gray-200);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--gray-200);
        }
        
        .admin-button-small {
            font-size: 0.85rem;
            padding: 5px 10px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background-color 0.2s;
        }
        
        .admin-button-small:hover {
            background-color: var(--primary-dark);
        }
        
        .admin-button-danger {
            background-color: #dc3545;
        }
        
        .admin-button-danger:hover {
            background-color: #c82333;
        }
        
        .admin-card {
            margin-bottom: 20px;
        }
        
        .admin-card-header {
            background-color: var(--gray-100);
            padding: 15px;
            border-bottom: 1px solid var(--gray-200);
            border-radius: var(--radius) var(--radius) 0 0;
        }
        
        .admin-card-header h3 {
            margin: 0;
            font-size: 1.1rem;
            color: var(--text-dark);
        }
        
        .admin-card-header small {
            display: block;
            margin-top: 5px;
            color: var(--text-light);
            font-size: 0.85rem;
        }
        
        .admin-form {
            background-color: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            padding: 0;
        }
        
        .admin-form .form-row {
            padding: 15px;
            margin-bottom: 0;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .admin-form .admin-actions {
            padding: 15px;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        
        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title">Navigation verwalten</h2>
                
                <div class="admin-actions">
                    <a href="index.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-arrow-left"></i></span>
                        Zurück zum Dashboard
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
            
            <!-- Navigation Tabs -->
            <div class="menu-tabs">
                <a href="?menu=main" class="menu-tab <?php echo $activeMenu === 'main_menu' ? 'active' : ''; ?>">
                    <i class="fas fa-bars"></i> Hauptmenü
                </a>
                <a href="?menu=footer" class="menu-tab <?php echo $activeMenu === 'footer_menu' ? 'active' : ''; ?>">
                    <i class="fas fa-shoe-prints"></i> Footermenü
                </a>
            </div>
            
            <form method="post" class="admin-form">
                <div class="admin-card-header">
                    <h3>Neuen Menüpunkt hinzufügen (<?php echo $activeMenuTitle; ?>)</h3>
                </div>
                
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="menu_type" value="<?php echo $activeMenu; ?>">
                
                <div class="form-row">
                    <div class="form-column">
                        <div class="form-group">
                            <label for="title" class="form-label">Titel</label>
                            <input type="text" id="title" name="title" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-column">
                        <div class="form-group">
                            <label for="url" class="form-label">URL</label>
                            <input type="text" id="url" name="url" class="form-control" required>
                            
                            <div class="quick-urls">
                                <small>Schnellauswahl:</small>
                                <?php foreach ($commonUrls as $commonUrl): ?>
                                    <a href="#" class="quick-url-link" data-url="<?php echo htmlspecialchars($commonUrl['url']); ?>">
                                        <?php echo htmlspecialchars($commonUrl['title']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-column">
                        <div class="form-group">
                            <label class="form-label">Öffnen in</label>
                            <div class="form-check">
                                <input type="checkbox" id="target" name="target" value="_blank">
                                <label for="target">Neuem Tab öffnen</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="admin-actions">
                    <button type="submit" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-plus"></i></span>
                        Hinzufügen
                    </button>
                </div>
            </form>
            
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3><?php echo $activeMenuTitle; ?> Einträge</h3>
                    <small>Zum Sortieren die Einträge mit der Maus ziehen</small>
                </div>
                <div class="menu-list" id="menu-sortable">
                    <?php 
                    $menuItems = $activeMenu === 'main_menu' ? $mainMenu : $footerMenu;
                    
                    if (empty($menuItems)): 
                    ?>
                        <div class="empty-message">
                            Keine Menüpunkte gefunden. 
                            <?php if ($activeMenu === 'main_menu'): ?>
                                <a href="?menu=main&migrate=1" class="admin-button admin-button-small">
                                    <i class="fas fa-magic"></i> 
                                    Standard-Menü importieren
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($menuItems as $item): ?>
                            <div class="menu-item" data-id="<?php echo htmlspecialchars($item['id']); ?>">
                                <div class="menu-item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                <div class="menu-item-url"><?php echo htmlspecialchars($item['url']); ?></div>
                                <?php if (isset($item['target']) && $item['target'] === '_blank'): ?>
                                    <div class="menu-item-target">Neuer Tab</div>
                                <?php endif; ?>
                                <div class="menu-item-actions">
                                    <button type="button" class="admin-button edit-menu-item" 
                                            data-id="<?php echo htmlspecialchars($item['id']); ?>"
                                            data-title="<?php echo htmlspecialchars($item['title']); ?>"
                                            data-url="<?php echo htmlspecialchars($item['url']); ?>"
                                            data-target="<?php echo isset($item['target']) ? htmlspecialchars($item['target']) : '_self'; ?>">
                                        <i class="fas fa-edit"></i> Bearbeiten
                                    </button>
                                    
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Möchten Sie diesen Menüpunkt wirklich löschen?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="menu_type" value="<?php echo $activeMenu; ?>">
                                        <input type="hidden" name="menu_item_id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                        <button type="submit" class="admin-button admin-button-danger">
                                            <i class="fas fa-trash-alt"></i> Löschen
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Formular zum Speichern der Sortierreihenfolge -->
                <form method="post" id="reorder-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="reorder_menu">
                    <input type="hidden" name="menu_type" value="<?php echo $activeMenu; ?>">
                    <input type="hidden" name="menu_order" id="menu-order" value="">
                </form>
            </div>
            
            <!-- Modal für Bearbeitung -->
            <div id="edit-modal" class="admin-modal">
                <div class="admin-modal-content">
                    <span class="admin-modal-close">&times;</span>
                    <h2>Menüpunkt bearbeiten</h2>
                    
                    <form method="post" id="edit-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="update_item">
                        <input type="hidden" name="menu_type" value="<?php echo $activeMenu; ?>">
                        <input type="hidden" name="menu_item_id" id="edit-menu-item-id" value="">
                        
                        <div class="form-group">
                            <label for="edit-title" class="form-label">Titel</label>
                            <input type="text" id="edit-title" name="title" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit-url" class="form-label">URL</label>
                            <input type="text" id="edit-url" name="url" class="form-control" required>
                            
                            <div class="quick-urls">
                                <small>Schnellauswahl:</small>
                                <?php foreach ($commonUrls as $commonUrl): ?>
                                    <a href="#" class="quick-url-link-edit" data-url="<?php echo htmlspecialchars($commonUrl['url']); ?>">
                                        <?php echo htmlspecialchars($commonUrl['title']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Öffnen in</label>
                            <div class="form-check">
                                <input type="checkbox" id="edit-target" name="target" value="_blank">
                                <label for="edit-target">Neuem Tab öffnen</label>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="admin-button cancel-edit">Abbrechen</button>
                            <button type="submit" class="admin-button">Speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        $(document).ready(function() {
            // Sortierbare Liste
            $("#menu-sortable").sortable({
                placeholder: "ui-sortable-placeholder",
                update: function(event, ui) {
                    // Sortierreihenfolge speichern
                    var order = [];
                    $(".menu-item").each(function() {
                        order.push($(this).data("id"));
                    });
                    
                    // Im Hidden-Input speichern
                    $("#menu-order").val(JSON.stringify(order));
                    
                    // Formular absenden
                    $("#reorder-form").submit();
                }
            });
            
            // Modal-Funktionen
            $(".edit-menu-item").click(function() {
                var id = $(this).data("id");
                var title = $(this).data("title");
                var url = $(this).data("url");
                var target = $(this).data("target");
                
                $("#edit-menu-item-id").val(id);
                $("#edit-title").val(title);
                $("#edit-url").val(url);
                $("#edit-target").prop("checked", target === "_blank");
                
                $("#edit-modal").show();
            });
            
            $(".admin-modal-close, .cancel-edit").click(function() {
                $("#edit-modal").hide();
            });
            
            // Modal schließen, wenn außerhalb geklickt wird
            $(window).click(function(event) {
                if ($(event.target).is("#edit-modal")) {
                    $("#edit-modal").hide();
                }
            });
            
            // Quick URL-Links
            $(".quick-url-link").click(function(e) {
                e.preventDefault();
                var url = $(this).data("url");
                $("#url").val(url);
            });

            // Auch im Edit-Modal
            $(".quick-url-link-edit").click(function(e) {
                e.preventDefault();
                var url = $(this).data("url");
                $("#edit-url").val(url);
            });
        });
    </script>
</body>
</html>