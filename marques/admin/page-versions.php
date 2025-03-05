<?php
/**
 * marques CMS - Seiten-Versionen
 * 
 * Anzeige und Verwaltung von Versionen einer Seite.
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

// PageManager initialisieren
$pageManager = new \Marques\Core\PageManager();

// VersionManager initialisieren
$versionManager = new \Marques\Core\VersionManager();

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

// Überprüfen, ob eine Seiten-ID übergeben wurde
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: pages.php');
    exit;
}

$page_id = $_GET['id'];
$page = $pageManager->getPage($page_id);

// Prüfen, ob die Seite existiert
if (!$page) {
    header('Location: pages.php');
    exit;
}

// Versionen der Seite abrufen
$versions = $versionManager->getVersions('pages', $page_id);

// Versions-Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            $version_id = $_POST['version_id'] ?? '';
            
            if (empty($version_id)) {
                $error_message = 'Keine Versions-ID angegeben.';
            } else {
                // Version wiederherstellen
                if ($action === 'restore') {
                    if ($versionManager->restoreVersion('pages', $page_id, $version_id, $user->getCurrentUsername())) {
                        $success_message = 'Version wurde erfolgreich wiederhergestellt.';
                    } else {
                        $error_message = 'Fehler beim Wiederherstellen der Version.';
                    }
                }
                // Version löschen
                elseif ($action === 'delete') {
                    if ($versionManager->deleteVersion('pages', $page_id, $version_id)) {
                        $success_message = 'Version wurde erfolgreich gelöscht.';
                        // Versionen neu laden
                        $versions = $versionManager->getVersions('pages', $page_id);
                    } else {
                        $error_message = 'Fehler beim Löschen der Version.';
                    }
                }
            }
        }
    }
}

// Versions-Inhalt anzeigen, wenn angefordert
$show_version = false;
$version_content = '';
$version_metadata = null;
$diff = [];

if (isset($_GET['version']) && !empty($_GET['version'])) {
    $version_id = $_GET['version'];
    $version_content = $versionManager->getVersionContent('pages', $page_id, $version_id);
    
    if ($version_content !== false) {
        $show_version = true;
        
        // Versions-Metadaten finden
        foreach ($versions as $v) {
            if ($v['version_id'] === $version_id) {
                $version_metadata = $v;
                break;
            }
        }
        
        // Diff zur aktuellen Version erstellen
        $current_content = $page['content'];
        $diff = $versionManager->createDiff($version_content, $current_content);
    }
}

?>
<!DOCTYPE html>
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Versionen: <?php echo htmlspecialchars($page['title']); ?> - Admin-Panel - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .version-diff {
            margin-top: 20px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .diff-header {
            background-color: var(--gray-100);
            padding: 10px 15px;
            border-bottom: 1px solid var(--gray-300);
            font-weight: 600;
        }
        .diff-content {
            padding: 15px;
            background-color: var(--white);
            overflow-x: auto;
        }
        .diff-line {
            display: flex;
            margin-bottom: 5px;
            font-family: monospace;
        }
        .diff-line-number {
            width: 40px;
            color: var(--text-light);
            text-align: right;
            padding-right: 10px;
            user-select: none;
        }
        .diff-line-content {
            flex: 1;
            padding: 2px 5px;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .diff-added {
            background-color: rgba(0, 255, 0, 0.1);
        }
        .diff-removed {
            background-color: rgba(255, 0, 0, 0.1);
        }
        .diff-changed {
            background-color: rgba(255, 255, 0, 0.1);
        }
        .version-metadata {
            margin-bottom: 20px;
            padding: 10px;
            background-color: var(--gray-100);
            border-radius: var(--radius);
        }
        .version-metadata p {
            margin: 5px 0;
        }
        .version-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        
        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title">Versionen: <?php echo htmlspecialchars($page['title']); ?></h2>
                
                <div class="admin-actions">
                    <a href="page-edit.php?id=<?php echo htmlspecialchars($page_id); ?>" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-edit"></i></span>
                        Seite bearbeiten
                    </a>
                    <a href="pages.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-arrow-left"></i></span>
                        Zurück zur Übersicht
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
            
            <?php if ($show_version && $version_metadata): ?>
                <div class="admin-form">
                    <h3>Version vom <?php echo date('d.m.Y H:i:s', $version_metadata['timestamp']); ?></h3>
                    
                    <div class="version-metadata">
                        <p><strong>Erstellt von:</strong> <?php echo htmlspecialchars($version_metadata['username']); ?></p>
                        <p><strong>Versions-ID:</strong> <?php echo htmlspecialchars($version_metadata['version_id']); ?></p>
                        <p><strong>Datum:</strong> <?php echo htmlspecialchars($version_metadata['date']); ?></p>
                    </div>
                    
                    <div class="version-actions">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="version_id" value="<?php echo htmlspecialchars($version_metadata['version_id']); ?>">
                            <button type="submit" class="admin-button">
                                <span class="admin-button-icon"><i class="fas fa-history"></i></span>
                                Diese Version wiederherstellen
                            </button>
                        </form>
                        
                        <a href="page-versions.php?id=<?php echo htmlspecialchars($page_id); ?>" class="admin-button">
                            <span class="admin-button-icon"><i class="fas fa-times"></i></span>
                            Zurück zur Versionsliste
                        </a>
                    </div>
                    
                    <?php if (!empty($diff)): ?>
                        <div class="version-diff">
                            <div class="diff-header">Unterschiede zur aktuellen Version</div>
                            <div class="diff-content">
                                <?php foreach ($diff as $line): ?>
                                    <div class="diff-line">
                                        <div class="diff-line-number"><?php echo $line['line']; ?></div>
                                        <div class="diff-line-content diff-<?php echo $line['type']; ?>">
                                            <?php if ($line['type'] === 'changed'): ?>
                                                <div>- <?php echo htmlspecialchars($line['old']); ?></div>
                                                <div>+ <?php echo htmlspecialchars($line['new']); ?></div>
                                            <?php elseif ($line['type'] === 'added'): ?>
                                                <div>+ <?php echo htmlspecialchars($line['new']); ?></div>
                                            <?php elseif ($line['type'] === 'removed'): ?>
                                                <div>- <?php echo htmlspecialchars($line['old']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <h3>Inhalt dieser Version</h3>
                    <div class="form-group">
                        <textarea class="form-control" style="height: 400px; font-family: monospace;" readonly><?php echo htmlspecialchars($version_content); ?></textarea>
                    </div>
                </div>
            <?php else: ?>
                <div class="admin-form">
                    <h3>Versionsverlauf</h3>
                    <?php if (empty($versions)): ?>
                        <p>Keine Versionen gefunden. Änderungen an der Seite erstellen automatisch neue Versionen.</p>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Benutzer</th>
                                    <th>Versions-ID</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($versions as $version): ?>
                                    <tr>
                                        <td><?php echo date('d.m.Y H:i:s', $version['timestamp']); ?></td>
                                        <td><?php echo htmlspecialchars($version['username']); ?></td>
                                        <td><?php echo htmlspecialchars($version['version_id']); ?></td>
                                        <td class="admin-table-actions">
                                            <a href="page-versions.php?id=<?php echo htmlspecialchars($page_id); ?>&version=<?php echo htmlspecialchars($version['version_id']); ?>" class="admin-table-action" title="Anzeigen">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="#" class="admin-table-action restore" title="Wiederherstellen" data-id="<?php echo htmlspecialchars($version['version_id']); ?>" data-date="<?php echo date('d.m.Y H:i:s', $version['timestamp']); ?>">
                                                <i class="fas fa-history"></i>
                                            </a>
                                            <a href="#" class="admin-table-action delete" title="Löschen" data-id="<?php echo htmlspecialchars($version['version_id']); ?>" data-date="<?php echo date('d.m.Y H:i:s', $version['timestamp']); ?>">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Wiederherstellungs-Modal -->
    <div id="restoreModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Version wiederherstellen</h3>
            <div class="modal-body">
                Möchten Sie die Version vom <span id="restoreVersionDate"></span> wirklich wiederherstellen?
                Die aktuelle Version wird dabei als neue Version gesichert.
            </div>
            <div class="modal-actions">
                <button id="cancelRestore" class="admin-button modal-close">Abbrechen</button>
                <form id="restoreForm" method="post" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="version_id" id="restoreVersionId">
                    <button type="submit" class="admin-button">Wiederherstellen</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Lösch-Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Version löschen</h3>
            <div class="modal-body">
                Möchten Sie die Version vom <span id="deleteVersionDate"></span> wirklich löschen?
                Dieser Vorgang kann nicht rückgängig gemacht werden.
            </div>
            <div class="modal-actions">
                <button id="cancelDelete" class="admin-button modal-close">Abbrechen</button>
                <form id="deleteForm" method="post" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="version_id" id="deleteVersionId">
                    <button type="submit" class="admin-button modal-delete">Löschen</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Modal-Funktionalität
        document.addEventListener('DOMContentLoaded', function() {
            // Restore-Modal
            var restoreModal = document.getElementById('restoreModal');
            var restoreButtons = document.querySelectorAll('.admin-table-action.restore');
            var cancelRestoreButton = document.getElementById('cancelRestore');
            var restoreVersionDateElement = document.getElementById('restoreVersionDate');
            var restoreVersionIdInput = document.getElementById('restoreVersionId');
            
            restoreButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    var versionId = this.dataset.id;
                    var versionDate = this.dataset.date;
                    
                    restoreVersionDateElement.textContent = versionDate;
                    restoreVersionIdInput.value = versionId;
                    
                    restoreModal.style.display = 'block';
                });
            });
            
            cancelRestoreButton.addEventListener('click', function() {
                restoreModal.style.display = 'none';
            });
            
            // Delete-Modal
            var deleteModal = document.getElementById('deleteModal');
            var deleteButtons = document.querySelectorAll('.admin-table-action.delete');
            var cancelDeleteButton = document.getElementById('cancelDelete');
            var deleteVersionDateElement = document.getElementById('deleteVersionDate');
            var deleteVersionIdInput = document.getElementById('deleteVersionId');
            
            deleteButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    var versionId = this.dataset.id;
                    var versionDate = this.dataset.date;
                    
                    deleteVersionDateElement.textContent = versionDate;
                    deleteVersionIdInput.value = versionId;
                    
                    deleteModal.style.display = 'block';
                });
            });
            
            cancelDeleteButton.addEventListener('click', function() {
                deleteModal.style.display = 'none';
            });
            
            // Schließen bei Klick außerhalb der Modals
            window.addEventListener('click', function(e) {
                if (e.target === restoreModal) {
                    restoreModal.style.display = 'none';
                }
                if (e.target === deleteModal) {
                    deleteModal.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>