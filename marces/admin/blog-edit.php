<?php
/**
 * marces CMS - Blog-Editor
 * 
 * Ermöglicht das Erstellen und Bearbeiten von Blog-Beiträgen.
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

// BlogManager initialisieren
$blogManager = new \Marces\Core\BlogManager();

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
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editing ? 'Beitrag bearbeiten' : 'Neuen Beitrag erstellen'; ?> - Admin-Panel - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marces CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- TinyMCE einbinden -->
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
                <h2 class="admin-page-title"><?php echo $editing ? 'Beitrag bearbeiten' : 'Neuen Beitrag erstellen'; ?></h2>
                
                <div class="admin-actions">
                    <a href="blog.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-arrow-left"></i></span>
                        Zurück zur Übersicht
                    </a>
                    <?php if ($editing): ?>
                    <a href="../blog/<?php echo htmlspecialchars($post['date'] . '/' . $post['slug']); ?>" class="admin-button" target="_blank">
                        <span class="admin-button-icon"><i class="fas fa-eye"></i></span>
                        Beitrag ansehen
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
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($post['id']); ?>">
                
                <div class="form-row">
                    <div class="form-column">
                        <div class="form-group">
                            <label for="title" class="form-label">Titel</label>
                            <input type="text" id="title" name="title" class="form-control" value="<?php echo htmlspecialchars($post['title']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-column">
                        <div class="form-group">
                            <label for="slug" class="form-label">Slug</label>
                            <input type="text" id="slug" name="slug" class="form-control" value="<?php echo htmlspecialchars($post['slug']); ?>" placeholder="wird-automatisch-generiert">
                            <div class="form-help">Leer lassen für automatische Generierung aus dem Titel.</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-column">
                        <div class="form-group">
                            <label for="date" class="form-label">Datum</label>
                            <input type="date" id="date" name="date" class="form-control" value="<?php echo htmlspecialchars($post['date']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-column">
                        <div class="form-group">
                            <label for="author" class="form-label">Autor</label>
                            <input type="text" id="author" name="author" class="form-control" value="<?php echo htmlspecialchars($post['author']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-column">
                        <div class="form-group">
                            <label for="status" class="form-label">Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="published" <?php echo $post['status'] === 'published' ? 'selected' : ''; ?>>Veröffentlicht</option>
                                <option value="draft" <?php echo $post['status'] === 'draft' ? 'selected' : ''; ?>>Entwurf</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="excerpt" class="form-label">Auszug</label>
                    <textarea id="excerpt" name="excerpt" class="form-control" rows="3"><?php echo htmlspecialchars($post['excerpt']); ?></textarea>
                    <div class="form-help">Kurze Zusammenfassung des Beitrags. Leer lassen für automatische Generierung.</div>
                </div>
                
                <div class="form-row">
                    <div class="form-column">
                        <div class="form-group">
                            <label class="form-label">Kategorien</label>
                            <input type="hidden" id="categories" name="categories" value="<?php echo htmlspecialchars(implode(',', $post['categories'])); ?>">
                            <div class="tag-container" id="categoryContainer">
                                <?php foreach ($post['categories'] as $category): ?>
                                    <?php if (!empty($category)): ?>
                                        <div class="tag">
                                            <?php echo htmlspecialchars($category); ?>
                                            <span class="tag-remove" data-value="<?php echo htmlspecialchars($category); ?>">×</span>
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
                            <input type="hidden" id="tags" name="tags" value="<?php echo htmlspecialchars(implode(',', $post['tags'])); ?>">
                            <div class="tag-container" id="tagContainer">
                                <?php foreach ($post['tags'] as $tag): ?>
                                    <?php if (!empty($tag)): ?>
                                        <div class="tag">
                                            <?php echo htmlspecialchars($tag); ?>
                                            <span class="tag-remove" data-value="<?php echo htmlspecialchars($tag); ?>">×</span>
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
                    <input type="text" id="featured_image" name="featured_image" class="form-control" value="<?php echo htmlspecialchars($post['featured_image']); ?>">
                    <div class="form-help">Relativer Pfad zum Beitragsbild (z.B. "assets/media/bild.jpg").</div>
                </div>
                
                <div class="form-group">
                    <label for="content" class="form-label">Inhalt</label>
                    <textarea id="content" name="content" class="form-control content-editor"><?php echo htmlspecialchars($post['content']); ?></textarea>
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
            const categories = <?php echo $categories_json; ?>;
            const tags = <?php echo $tags_json; ?>;

            setupTagSystem('category', categories);
            setupTagSystem('tag', tags);

            function setupTagSystem(type, suggestions) {
                const container = document.getElementById(type + 'Container');
                const input = document.getElementById(type + 'Input');

                // Spezielle Behandlung für "category" (wird zu "categories")
                const hiddenInputId = type === 'category' ? 'categories' : type + 's';
                const hiddenInput = document.getElementById(hiddenInputId);
                console.log(`${type} - Hidden-Input-ID:`, hiddenInputId);

                const suggestionBox = document.getElementById(type + 'Suggestions');
                
                // Debugging: Prüfen, ob Elemente vorhanden sind
                console.log(`${type} Setup - Elemente gefunden:`, {
                    container: !!container,
                    input: !!input,
                    hiddenInput: !!hiddenInput,
                    suggestionBox: !!suggestionBox
                });
                
                // Überprüfen, ob alle Elemente existieren
                if (!container || !input || !hiddenInput || !suggestionBox) {
                    console.error(`Ein Element für das ${type}-System fehlt!`);
                    return; // Abbrechen, wenn ein Element fehlt
                }
                
                // Event-Listener für Entfernen von Tags
                container.addEventListener('click', function(e) {
                    if (e.target.classList.contains('tag-remove')) {
                        const value = e.target.dataset.value;
                        e.target.parentElement.remove();
                        updateHiddenInput();
                        console.log(`${type} entfernt:`, value);
                    }
                });
                
                // Tastaturereignisse für Eingabefeld
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        const value = input.value.trim();
                        if (value) {
                            addTag(value);
                            console.log(`${type} hinzugefügt:`, value);
                        }
                    }
                });

                // Füge zusätzlich einen blur-Event-Listener hinzu, der auch einen Tag/Kategorie hinzufügt:
                input.addEventListener('blur', function() {
                    const value = input.value.trim();
                    if (value) {
                        addTag(value);
                        console.log(`${type} beim Verlassen des Feldes hinzugefügt:`, value);
                    }
                    suggestionBox.style.display = 'none';
                });
                
                // Fokus- und Blur-Ereignisse
                input.addEventListener('focus', function() {
                    showSuggestions();
                });
                
                input.addEventListener('input', function() {
                    showSuggestions();
                });
                
                document.addEventListener('click', function(e) {
                    if (!container.contains(e.target) && !suggestionBox.contains(e.target)) {
                        suggestionBox.style.display = 'none';
                    }
                });
                
                // Vorschläge anzeigen
                function showSuggestions() {
                    const inputVal = input.value.trim().toLowerCase();
                    
                    // Debugging
                    console.log(`${type} Suggestions - Eingabe:`, inputVal);
                    console.log(`${type} Suggestions - Verfügbare Werte:`, suggestions);
                    
                    // Vorschläge filtern
                    const filtered = suggestions.filter(item => {
                        const itemLower = (typeof item === 'string') ? item.toLowerCase() : '';
                        return itemLower.includes(inputVal) && !getValues().includes(item);
                    });
                    
                    console.log(`${type} Suggestions - Gefilterte Werte:`, filtered);
                    
                    // Vorschlagbox aktualisieren
                    suggestionBox.innerHTML = '';
                    
                    if (filtered.length > 0) {
                        filtered.forEach(item => {
                            const div = document.createElement('div');
                            div.className = 'tag-suggestion';
                            div.textContent = item;
                            div.addEventListener('click', function() {
                                addTag(item);
                                console.log(`${type} aus Vorschlag ausgewählt:`, item);
                            });
                            suggestionBox.appendChild(div);
                        });
                        
                        // Position der Vorschlagbox
                        const rect = input.getBoundingClientRect();
                        suggestionBox.style.top = (rect.bottom + window.scrollY) + 'px';
                        suggestionBox.style.left = (rect.left + window.scrollX) + 'px';
                        suggestionBox.style.width = container.offsetWidth + 'px';
                        suggestionBox.style.display = 'block';
                    } else {
                        suggestionBox.style.display = 'none';
                    }
                }
                
                // Tag hinzufügen
                function addTag(value) {
                    value = value.trim();
                    if (value && !getValues().includes(value)) {
                        console.log(`${type} - Füge hinzu:`, value);
                        const tag = document.createElement('div');
                        tag.className = 'tag';
                        tag.innerHTML = `${value}<span class="tag-remove" data-value="${value}">×</span>`;
                        container.insertBefore(tag, input);
                        input.value = '';
                        updateHiddenInput();
                    }
                    suggestionBox.style.display = 'none';
                }
                
                // Aktuelle Tags/Kategorien erhalten
                function getValues() {
                    const tagElements = container.querySelectorAll('.tag');
                    const values = Array.from(tagElements).map(tag => {
                        // Nimm nur den Textinhalt ohne das "×"
                        const text = tag.textContent || '';
                        return text.replace(/×$/, '').trim();
                    });
                    console.log(`${type} - Aktuelle Werte:`, values);
                    return values;
                }
                
                // Hidden-Input aktualisieren
                function updateHiddenInput() {
                    const values = getValues();
                    if (hiddenInput) {
                        hiddenInput.value = values.join(',');
                        console.log(`${type} - Hidden-Input aktualisiert:`, hiddenInput.value);
                    } else {
                        console.error(`${type} - Hidden-Input nicht gefunden!`);
                    }
                }
            }

        });

    </script>
    
</body>
</html>