<?php
/**
 * marques CMS - Blog-Editor
 * 
 * Ermöglicht das Erstellen und Bearbeiten von Blog-Beiträgen.
 *
 * @package marques
 * @subpackage admin
*/

use Marques\Admin\MarquesAdmin;
use Marques\Core\DatabaseHandler;
use Marques\Core\BlogManager;

$adminApp = new MarquesAdmin();
$container = $adminApp->getContainer();

// Hole den DatabaseHandler via DI
$dbHandler = $container->get(DatabaseHandler::class);
$blogManager = $container->get(BlogManager::class);

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message = '';

// Beitragsdaten initialisieren
$post = [
    'id' => '',
    'title' => '',
    'slug' => '',
    'date' => date('Y-m-d'),
    'author' => $user->getCurrentDisplayName(),
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
        $post['author'] = $_POST['author'] ?? $user->getCurrentDisplayName();
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
