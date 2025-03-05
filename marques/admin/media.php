<?php
/**
 * marques CMS - Medienverwaltung
 * 
 * Verwaltung von Mediendateien wie Bilder, Videos, etc.
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

// Media Manager initialisieren
$mediaManager = new \Marques\Core\MediaManager();

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

// TinyMCE Mode (wenn aus TinyMCE aufgerufen)
$tinyMCEMode = isset($_GET['tinymce']) && $_GET['tinymce'] === '1';

// Medium löschen, wenn gewünscht
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $filename = $_POST['media_id'] ?? '';
        
        if ($mediaManager->deleteMedia($filename)) {
            $success_message = 'Medium erfolgreich gelöscht. Verweise in Inhalten wurden durch Platzhalter ersetzt.';
        } else {
            $error_message = 'Fehler beim Löschen des Mediums.';
        }
    }
}

// Medium hochladen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
        $result = $mediaManager->uploadMedia($_FILES['media']);
        if ($result) {
            $success_message = 'Medium erfolgreich hochgeladen.';
        } else {
            $error_message = 'Fehler beim Hochladen des Mediums.';
        }
    } else {
        $error_message = 'Fehler beim Hochladen: Code ' . ($_FILES['media']['error'] ?? 'Unbekannt');
    }
}

// Medien abrufen
$media = $mediaManager->getAllMedia();

?>
<!DOCTYPE html>
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medienverwaltung - Admin-Panel - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .media-item {
            border: 1px solid var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
            background-color: #fff;
            transition: all 0.2s ease;
        }
        .media-item:hover {
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .media-thumbnail {
            height: 150px;
            background-color: var(--bg-light);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .media-thumbnail img {
            max-width: 100%;
            max-height: 150px;
            object-fit: contain;
        }
        .media-info {
            padding: 10px;
            font-size: 0.85rem;
        }
        .media-filename {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 500;
            margin-bottom: 5px;
        }
        .media-actions {
            position: absolute;
            top: 5px;
            right: 5px;
            display: none;
            background-color: rgba(255,255,255,0.9);
            border-radius: 3px;
            padding: 3px;
        }
        .media-item:hover .media-actions {
            display: flex;
        }
        .media-details {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .media-table-action {
            color: var(--primary-color);
            margin-right: 5px;
            text-decoration: none;
            font-size: 14px;
        }
        .media-table-action:hover {
            color: var(--primary-dark);
        }
        .media-table-action.delete {
            color: var(--danger-color);
        }
        .media-table-action.delete:hover {
            color: var(--danger-dark);
        }
        .media-upload-form {
            background-color: #fff;
            padding: 20px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .media-upload-input {
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        
        <!-- SIDEBAR & NAVIGATION -->
        <?php if (!$tinyMCEMode): ?>
            <?php include 'includes/sidebar.php'; ?>
        <?php endif; ?>
        
        <main class="<?php echo $tinyMCEMode ? 'tinymce-content' : 'admin-content'; ?>">
            <div class="admin-topbar">
                <h2 class="admin-page-title">
                    <?php echo $tinyMCEMode ? 'Medium auswählen' : 'Medienverwaltung'; ?>
                </h2>
                
                <?php if (!$tinyMCEMode): ?>
                <div class="admin-actions">
                    <a href="../" class="admin-button" target="_blank">
                        <span class="admin-button-icon"><i class="fas fa-external-link-alt"></i></span>
                        Website ansehen
                    </a>
                </div>
                <?php endif; ?>
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
            
            <!-- Upload-Formular -->
            <form method="post" enctype="multipart/form-data" class="media-upload-form">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="media-upload-input">
                    <label for="media" class="form-label">Medium hochladen</label>
                    <input type="file" id="media" name="media" accept="image/*,application/pdf" class="form-control">
                </div>
                
                <div>
                    <button type="submit" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-upload"></i></span>
                        Hochladen
                    </button>
                </div>
            </form>
            
            <!-- Medien-Grid -->
            <?php if (empty($media)): ?>
                <div class="admin-welcome">
                    <h3>Keine Medien gefunden</h3>
                    <p>Laden Sie Ihre ersten Medien mit dem Formular oben hoch.</p>
                </div>
            <?php else: ?>
                <div class="media-grid">
                    <?php foreach ($media as $item): ?>
                        <div class="media-item" data-url="<?php echo marques_site_url($item['cache_bust_url']); ?>" data-filename="<?php echo htmlspecialchars($item['filename']); ?>">
                            <div class="media-thumbnail">
                                <?php if (strpos($item['filetype'], 'image/') === 0): ?>
                                    <img src="../<?php echo $item['cache_bust_url']; ?>" alt="<?php echo htmlspecialchars($item['filename']); ?>">
                                <?php elseif ($item['filetype'] === 'application/pdf'): ?>
                                    <i class="fas fa-file-pdf" style="font-size: 3rem; color: #dc3545;"></i>
                                <?php else: ?>
                                    <i class="fas fa-file" style="font-size: 3rem; color: #6c757d;"></i>
                                <?php endif; ?>
                            </div>

                            <div class="media-info">
                                <div class="media-filename" title="<?php echo htmlspecialchars($item['filename']); ?>">
                                    <?php echo htmlspecialchars($item['filename']); ?>
                                </div>
                                <div class="media-details">
                                    <?php echo htmlspecialchars($item['filesize']); ?>
                                    <?php if (!empty($item['dimensions'])): ?>
                                        <br><?php echo htmlspecialchars($item['dimensions']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="media-actions">
                                <?php if ($tinyMCEMode): ?>
                                    <a href="#" class="media-table-action select-media" title="Auswählen">
                                        <i class="fas fa-check"></i>
                                    </a>
                                <?php else: ?>

                                    <a href="../<?php echo $item['cache_bust_url']; ?>" class="media-table-action" title="Ansehen" target="_blank">
                                        <i class="fas fa-eye"></i>
                                    </a>

                                    <a href="#" class="media-table-action delete" title="Löschen" data-id="<?php echo htmlspecialchars($item['filename']); ?>" data-title="<?php echo htmlspecialchars($item['filename']); ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Lösch-Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Medium löschen</h3>
            <div class="modal-body">
                Sind Sie sicher, dass Sie das Medium "<span id="deleteMedia"></span>" löschen möchten?
                Dieser Vorgang kann nicht rückgängig gemacht werden.
            </div>
            <div class="modal-actions">
                <button id="cancelDelete" class="admin-button modal-close">Abbrechen</button>
                <form id="deleteForm" method="post" style="display: inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="media_id" id="deleteMediaId">
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
            var deleteButtons = document.querySelectorAll('.media-table-action.delete');
            var cancelButton = document.getElementById('cancelDelete');
            var deleteMediaElement = document.getElementById('deleteMedia');
            var deleteMediaIdInput = document.getElementById('deleteMediaId');
            
            deleteButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    var mediaId = this.dataset.id;
                    var mediaTitle = this.dataset.title;
                    
                    deleteMediaElement.textContent = mediaTitle;
                    deleteMediaIdInput.value = mediaId;
                    
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
            
            <?php if ($tinyMCEMode): ?>
            // TinyMCE Integration für Media Browser
            document.querySelectorAll('.select-media').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const mediaItem = this.closest('.media-item');
                    const mediaUrl = mediaItem.dataset.url; // Dies enthält bereits den Cache-Busting-Parameter
                    const fileName = mediaItem.dataset.filename;
                    
                    // Callback an TinyMCE
                    window.parent.postMessage({
                        mceAction: 'insertMedia',
                        content: mediaUrl,
                        alt: fileName
                    }, '*');
                });
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>