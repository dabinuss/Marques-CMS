<?php
/**
 * marques CMS - Kategorien-Verwaltung
 *
 * Verwaltung von Blog-Kategorien.
 *
 * @package marques
 * @subpackage admin
*/

use Marques\Admin\MarquesAdmin;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Service\BlogManager;

// Hole den DatabaseHandler via DI

$blogManager = $container->get(BlogManager::class);

$blogManager->initCatalogFiles();

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message = '';

// Kategorien abrufen
$categories = $blogManager->getCategories();

// Aktion verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $action = $_POST['action'] ?? '';

        // Neue Kategorie hinzufügen
        if ($action === 'add' && !empty($_POST['category_name'])) {
            $category_name = trim($_POST['category_name']);

            // Prüfen, ob Kategorie bereits existiert
            if (isset($categories[$category_name])) {
                $error_message = 'Diese Kategorie existiert bereits.';
            } else {
                if ($blogManager->addCategory($category_name)) {
                    $success_message = 'Kategorie erfolgreich hinzugefügt: ' . htmlspecialchars($category_name);
                    // Kategorien neu laden
                    $categories = $blogManager->getCategories();
                } else {
                    $error_message = 'Fehler beim Hinzufügen der Kategorie.';
                }
            }
        }
        // Kategorie umbenennen
        else if ($action === 'rename' && !empty($_POST['old_name']) && !empty($_POST['new_name'])) {
            $old_name = trim($_POST['old_name']);
            $new_name = trim($_POST['new_name']);

            // Prüfen, ob alte Kategorie existiert
            if (!isset($categories[$old_name])) {
                $error_message = 'Die zu ändernde Kategorie existiert nicht.';
            }
            // Prüfen, ob neue Kategorie bereits existiert
            else if ($old_name !== $new_name && isset($categories[$new_name])) {
                $error_message = 'Die neue Kategorie existiert bereits.';
            } else {
                if ($blogManager->renameCategory($old_name, $new_name)) {
                    $success_message = 'Kategorie erfolgreich umbenannt von "' . htmlspecialchars($old_name) . '" zu "' . htmlspecialchars($new_name) . '".';
                    // Kategorien neu laden
                    $categories = $blogManager->getCategories();
                } else {
                    $error_message = 'Fehler beim Umbenennen der Kategorie.';
                }
            }
        }
        // Kategorie löschen
        else if ($action === 'delete' && !empty($_POST['category_name'])) {
            $category_name = trim($_POST['category_name']);

            if (!isset($categories[$category_name])) {
                $error_message = 'Die zu löschende Kategorie existiert nicht.';
            } else {
                if ($blogManager->deleteCategory($category_name)) {
                    $success_message = 'Kategorie erfolgreich gelöscht.';
                    // Kategorien neu laden
                    $categories = $blogManager->getCategories();
                } else {
                    $error_message = 'Fehler beim Löschen der Kategorie.';
                }
            }
        }
    }
}

// Nach Anzahl der Posts sortieren (absteigend)
arsort($categories);
