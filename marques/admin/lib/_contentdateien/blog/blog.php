<?php
/**
 * marques CMS - Blog-Verwaltung
 *
 * Listet alle Blog-Beiträge auf und ermöglicht deren Verwaltung.
 *
 * @package marques
 * @subpackage admin
*/

use Admin\MarquesAdmin;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Service\BlogManager;



// Hole den DatabaseHandler via DI

$blogManager = $container->get(BlogManager::class);

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