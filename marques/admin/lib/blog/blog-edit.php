<?php
/**
 * marques CMS - Blog-Editor
 * 
 * Ermöglicht das Erstellen und Bearbeiten von Blog-Beiträgen.
 *
 * @package marques
 * @subpackage admin
 */

// BlogManager initialisieren
$blogManager = new \Marques\Core\BlogManager();

// Konfiguration laden
$configManager = \Marques\Core\AppConfig::getInstance();
$system_config = $configManager->load('system') ?: [];

require_once MARQUES_ROOT_DIR . '/system/core/Helper.class.php';
use Marques\Core\Helper;

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message = '';

// Beitragsdaten initialisieren
$post = [
    'id' => '',
    'title' => '',
    'slug' => '',
    'date' => date('Y-m-d'),
    'author' => $user->getCurrentUsername(),
    'excerpt' => '',
    'content' => '',
    'categories' => [],
    'tags' => [],
    'featured_image' => '',
    'status' => 'published'
];

// Überprüfen, ob es sich um die Bearbeitung eines bestehenden Beitrags handelt
$editing = false;

// Stelle sicher, dass die Katalogdateien existieren
$blogManager->initCatalogFiles();

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $post_id = $_GET['id'];
    $existing_post = $blogManager->getPost($post_id);
    
    if ($existing_post) {
        $post = $existing_post;
        $editing = true;
    } else {
        $error_message = 'Der angeforderte Beitrag wurde nicht gefunden.';
    }
}

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        // Formulardaten verarbeiten
        $post['id'] = $_POST['id'] ?? '';
        $post['title'] = $_POST['title'] ?? '';
        $post['slug'] = $_POST['slug'] ?? '';
        $post['date'] = $_POST['date'] ?? date('Y-m-d');
        $post['author'] = $_POST['author'] ?? $user->getCurrentUsername();
        $post['excerpt'] = $_POST['excerpt'] ?? '';
        $post['content'] = $_POST['content'] ?? '';
        $post['categories'] = isset($_POST['categories']) ? explode(',', $_POST['categories']) : [];
        $post['tags'] = isset($_POST['tags']) ? explode(',', $_POST['tags']) : [];
        $post['featured_image'] = $_POST['featured_image'] ?? '';
        $post['status'] = $_POST['status'] ?? 'published';
        
        // Validierung
        if (empty($post['title'])) {
            $error_message = 'Bitte geben Sie einen Titel ein.';
        } elseif (empty($post['content'])) {
            $error_message = 'Der Inhalt darf nicht leer sein.';
        } else {
            // Beitrag speichern
            $result = $blogManager->savePost($post);
            
            if ($result) {
                $success_message = 'Beitrag erfolgreich gespeichert.';
                
                // Wenn es eine neue ID gibt (bei neuen Beiträgen oder Slug-Änderungen)
                if (is_string($result) && $result !== $post['id']) {
                    // Umleiten zur Bearbeitungsseite mit der neuen ID
                    header('Location: blog-edit.php?id=' . urlencode($result) . '&saved=1');
                    exit;
                }
                
                // ID könnte bei einem neuen Beitrag generiert worden sein
                $editing = true;
            } else {
                $error_message = 'Fehler beim Speichern des Beitrags.';
            }
        }
    }
}

// Nach der POST-Verarbeitung der Kategorien und Tags, etwa Zeile 95
if (!empty($post['categories'])) {
    foreach ($post['categories'] as $category) {
        if (!empty($category)) {
            // Kategorie zum Katalog hinzufügen
            $blogManager->addCategory($category);
        }
    }
}

if (!empty($post['tags'])) {
    foreach ($post['tags'] as $tag) {
        if (!empty($tag)) {
            // Tag zum Katalog hinzufügen
            $blogManager->addTag($tag);
        }
    }
}

// Erfolgsmeldung von Weiterleitung anzeigen
if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $success_message = 'Beitrag erfolgreich gespeichert.';
}

// Alle Kategorien und Tags laden für Autovervollständigung
$all_categories = $blogManager->getCategories();
$categories_json = json_encode(array_keys($all_categories));

$all_tags = $blogManager->getTags();
$tags_json = json_encode(array_keys($all_tags));

?>
<!DOCTYPE html>
<html lang="<?= $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editing ? 'Beitrag bearbeiten' : 'Neuen Beitrag erstellen'; ?> - Admin-Panel - <?= htmlspecialchars($system_config['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="assets/js/tinymce/tinymce.min.js"></script>
    <script src="assets/js/tinymce-config.js"></script>
    <style>
        .tag-container {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
            padding: 5px;
            background-color: var(--gray-100);
            border-radius: 3px;
            min-height: 30px;
        }
        
        .tag {
            display: inline-flex;
            align-items: center;
            background-color: var(--primary);
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.9rem;
        }
        
        .tag-remove {
            margin-left: 5px;
            cursor: pointer;
        }
        
        .tag-input {
            flex: 1;
            min-width: 100px;
            border: none;
            outline: none;
            padding: 3px;
            background: transparent;
        }
        
        .tag-suggestions {
            position: absolute;
            background-color: white;
            border: 1px solid var(--gray-300);
            border-radius: 3px;
            box-shadow: var(--shadow);
            max-height: 150px;
            overflow-y: auto;
            z-index: 100;
            display: none;
        }
        
        .tag-suggestion {
            padding: 5px 10px;
            cursor: pointer;
        }
        
        .tag-suggestion:hover {
            background-color: var(--gray-100);
        }
    </style>
</head>
<body>
    <div class="admin-layout">

        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title"><?= $editing ? 'Beitrag bearbeiten' : 'Neuen Beitrag erstellen'; ?></h2>
                
                <div class="admin-actions">
                    <a href="blog.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-arrow-left"></i></span>
                        Zurück zur Übersicht
                    </a>
                    <?php 
                    if ($editing): 
                    
                        // Erzeuge die URL für den aktuellen Beitrag:
                        $blogUrl = Helper::generateBlogUrl($post);
                    ?>
                    <a href="<?= htmlspecialchars($blogUrl); ?>" class="admin-button" target="_blank">
                        <span class="admin-button-icon"><i class="fas fa-eye"></i></span>
                        Beitrag ansehen
                    </a>
                    <?php endif; ?>
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
            
            <form method="post" class="admin-form ">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <input type="hidden" name="id" value="<?= htmlspecialchars($post['id']); ?>">
                
                <div class="form-row">
                    <div class="form-column">
                        <div class="form-group">
                            <label for="title" class="form-label">Titel</label>
                            <input type="text" id="title" name="title" class="form-control" value="<?= htmlspecialchars($post['title']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-column">
                        <div class="form-group">
                            <label for="slug" class="form-label">Slug</label>
                            <input type="text" id="slug" name="slug" class="form-control" value="<?= htmlspecialchars($post['slug']); ?>" placeholder="wird-automatisch-generiert">
                            <div class="form-help">Leer lassen für automatische Generierung aus dem Titel.</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-column">
                        <div class="form-group">
                            <label for="date" class="form-label">Datum</label>
                            <input type="date" id="date" name="date" class="form-control" value="<?= htmlspecialchars($post['date']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-column">
                        <div class="form-group">
                            <label for="author" class="form-label">Autor</label>
                            <input type="text" id="author" name="author" class="form-control" value="<?= htmlspecialchars($post['author']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-column">
                        <div class="form-group">
                            <label for="status" class="form-label">Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="published" <?= $post['status'] === 'published' ? 'selected' : ''; ?>>Veröffentlicht</option>
                                <option value="draft" <?= $post['status'] === 'draft' ? 'selected' : ''; ?>>Entwurf</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="excerpt" class="form-label">Auszug</label>
                    <textarea id="excerpt" name="excerpt" class="form-control" rows="3"><?= htmlspecialchars($post['excerpt']); ?></textarea>
                    <div class="form-help">Kurze Zusammenfassung des Beitrags. Leer lassen für automatische Generierung.</div>
                </div>
                
                <div class="form-row">
                    <div class="form-column">
                        <div class="form-group">
                            <label class="form-label">Kategorien</label>
                            <input type="hidden" id="categories" name="categories" value="<?= htmlspecialchars(implode(',', $post['categories'])); ?>">
                            <div class="tag-container" id="categoryContainer">
                                <?php foreach ($post['categories'] as $category): ?>
                                    <?php if (!empty($category)): ?>
                                        <div class="tag">
                                            <?= htmlspecialchars($category); ?>
                                            <span class="tag-remove" data-value="<?= htmlspecialchars($category); ?>">×</span>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <input type="text" class="tag-input" id="categoryInput" placeholder="Neue Kategorie...">
                            </div>
                            <div class="tag-suggestions" id="categorySuggestions"></div>
                        </div>
                    </div>
                    
                    <div class="form-column">
                        <div class="form-group">
                            <label class="form-label">Tags</label>
                            <input type="hidden" id="tags" name="tags" value="<?= htmlspecialchars(implode(',', $post['tags'])); ?>">
                            <div class="tag-container" id="tagContainer">
                                <?php foreach ($post['tags'] as $tag): ?>
                                    <?php if (!empty($tag)): ?>
                                        <div class="tag">
                                            <?= htmlspecialchars($tag); ?>
                                            <span class="tag-remove" data-value="<?= htmlspecialchars($tag); ?>">×</span>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <input type="text" class="tag-input" id="tagInput" placeholder="Neuer Tag...">
                            </div>
                            <div class="tag-suggestions" id="tagSuggestions"></div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="featured_image" class="form-label">Beitragsbild (URL)</label>
                    <input type="text" id="featured_image" name="featured_image" class="form-control" value="<?= htmlspecialchars($post['featured_image']); ?>">
                    <div class="form-help">Relativer Pfad zum Beitragsbild (z.B. "assets/media/bild.jpg").</div>
                </div>
                
                <div class="form-group">
                    <label for="content" class="form-label">Inhalt</label>
                    <textarea id="content" name="content" class="form-control content-editor"><?= htmlspecialchars($post['content']); ?></textarea>
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
    
    <script src="assets/js/tagsystem-setup.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // TinyMCE initialisieren
            initTinyMCE('.content-editor');
            
            // Slug-Generator
            const titleInput = document.getElementById('title');
            const slugInput = document.getElementById('slug');
            
            titleInput.addEventListener('blur', function() {
                if (slugInput.value === '') {
                    const title = titleInput.value;
                    const slug = title.toLowerCase()
                        .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // Akzente entfernen
                        .replace(/[^a-z0-9]+/g, '-')                      // Nicht-alphanumerische Zeichen durch Bindestriche ersetzen
                        .replace(/^-+|-+$/g, '');                         // Führende/nachfolgende Bindestriche entfernen
                    
                    slugInput.value = slug;
                }
            });

            // Kategorie- und Tag-System
            const categories = <?= $categories_json; ?>;
            const tags = <?= $tags_json; ?>;

            if (typeof setupTagSystem === 'function') {
                setupTagSystem('category', categories);
                setupTagSystem('tag', tags);
            } else {
                console.error("setupTagSystem Funktion nicht gefunden! Prüfe, ob tagsystem-setup.js korrekt geladen wurde.");
            }
        });
    </script>

</body>
</html>