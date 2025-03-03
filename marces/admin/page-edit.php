<?php
/**
 * marces CMS - Seiten-Editor
 * 
 * Ermöglicht das Erstellen und Bearbeiten von Seiten.
 *
 * @package marces
 * @subpackage admin
 */

// Basispfad definieren
define('MARCES_ROOT_DIR', dirname(__DIR__));
define('IS_ADMIN', true);

// Bootstrap laden
require_once MARCES_ROOT_DIR . '/system/core/bootstrap.inc.php';

// Admin-Klasse initialisieren
$admin = new \Marces\Core\Admin();
$admin->requireLogin();

// Benutzer-Objekt initialisieren
$user = new \Marces\Core\User();

// PageManager initialisieren
$pageManager = new \Marces\Core\PageManager();

// Konfiguration laden
$system_config = require MARCES_CONFIG_DIR . '/system.config.php';

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message = '';

// Seitendaten initialisieren
$page = [
    'id' => '',
    'title' => '',
    'description' => '',
    'content' => '',
    'template' => 'page',
    'featured_image' => ''
];

// Überprüfen, ob es sich um die Bearbeitung einer bestehenden Seite handelt
$editing = false;
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $page_id = $_GET['id'];
    $existing_page = $pageManager->getPage($page_id);
    
    if ($existing_page) {
        $page = $existing_page;
        $editing = true;
    } else {
        $error_message = 'Die angeforderte Seite wurde nicht gefunden.';
    }
}

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        // Formulardaten verarbeiten
        $page['id'] = $_POST['id'] ?? '';
        $page['title'] = $_POST['title'] ?? '';
        $page['description'] = $_POST['description'] ?? '';
        $page['content'] = $_POST['content'] ?? '';
        $page['template'] = $_POST['template'] ?? 'page';
        $page['featured_image'] = $_POST['featured_image'] ?? '';
        
        // Validierung
        if (empty($page['title'])) {
            $error_message = 'Bitte geben Sie einen Titel ein.';
        } elseif (empty($page['content'])) {
            $error_message = 'Der Inhalt darf nicht leer sein.';
        } else {
            // Seite speichern
            if ($pageManager->savePage($page)) {
                $success_message = 'Seite erfolgreich gespeichert.';
                
                // ID könnte bei einer neuen Seite generiert worden sein
                if (empty($_POST['id']) && !empty($page['title'])) {
                    $page['id'] = $pageManager->generateSlug($page['title']);
                    $editing = true;
                }
            } else {
                $error_message = 'Fehler beim Speichern der Seite.';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editing ? 'Seite bearbeiten' : 'Neue Seite erstellen'; ?> - Admin-Panel - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marces CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- TinyMCE einbinden -->
    <script src="assets/js/tinymce/tinymce.min.js"></script>
    <script src="assets/js/tinymce-config.js"></script>
</head>
<body>
    <div class="admin-layout">

        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title"><?php echo $editing ? 'Seite bearbeiten' : 'Neue Seite erstellen'; ?></h2>
                
                <div class="admin-actions">
                    <a href="pages.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-arrow-left"></i></span>
                        Zurück zur Übersicht
                    </a>
                    <?php if ($editing): ?>
                    <a href="../<?php echo htmlspecialchars($page['id']); ?>" class="admin-button" target="_blank">
                        <span class="admin-button-icon"><i class="fas fa-eye"></i></span>
                        Seite ansehen
                    </a>
                    <?php endif; ?>
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
            
            <form method="post" class="admin-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($page['id']); ?>">
                
                <div class="form-row">
                    <div class="form-column">
                        <div class="form-group">
                            <label for="title" class="form-label">Titel</label>
                            <input type="text" id="title" name="title" class="form-control" value="<?php echo htmlspecialchars($page['title']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-column">
                        <div class="form-group">
                            <label for="template" class="form-label">Template</label>
                            <select id="template" name="template" class="form-control">
                                <option value="page" <?php echo $page['template'] === 'page' ? 'selected' : ''; ?>>Standard-Seite</option>
                                <option value="landing" <?php echo $page['template'] === 'landing' ? 'selected' : ''; ?>>Landing-Page</option>
                                <option value="sidebar" <?php echo $page['template'] === 'sidebar' ? 'selected' : ''; ?>>Seite mit Seitenleiste</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="description" class="form-label">Beschreibung</label>
                    <input type="text" id="description" name="description" class="form-control" value="<?php echo htmlspecialchars($page['description']); ?>">
                    <div class="form-help">Kurze Beschreibung der Seite für SEO-Zwecke.</div>
                </div>
                
                <div class="form-group">
                    <label for="featured_image" class="form-label">Beitragsbild (URL)</label>
                    <input type="text" id="featured_image" name="featured_image" class="form-control" value="<?php echo htmlspecialchars($page['featured_image']); ?>">
                    <div class="form-help">Relativer Pfad zum Beitragsbild (z.B. "uploads/image.jpg").</div>
                </div>
                
                <div class="form-group">
                    <label for="content" class="form-label">Inhalt</label>
                    <textarea id="content" name="content" class="form-control content-editor"><?php echo htmlspecialchars($page['content']); ?></textarea>
                </div>
                
                <div class="admin-actions">
                    <button type="submit" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-save"></i></span>
                        Speichern
                    </button>
                </div>
            </form>
        </main>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // TinyMCE initialisieren
            initTinyMCE('.content-editor');
        });
    </script>
</body>
</html>