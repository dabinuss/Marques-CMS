<?php
/**
 * marques CMS - Tags-Verwaltung
 * 
 * Verwaltung von Blog-Tags.
 *
 * @package marques
 * @subpackage admin
*/

use Admin\MarquesAdmin;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Service\BlogManager;



// Hole den DatabaseHandler via DI

$blogManager = $container->get(BlogManager::class);

$blogManager->initCatalogFiles();

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message = '';

// Tags abrufen
$tags = $blogManager->getTags();

// Aktion verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $action = $_POST['action'] ?? '';
        
        // Neuen Tag hinzufügen
        if ($action === 'add' && !empty($_POST['tag_name'])) {
            $tag_name = trim($_POST['tag_name']);
            
            // Prüfen, ob Tag bereits existiert
            if (isset($tags[$tag_name])) {
                $error_message = 'Dieser Tag existiert bereits.';
            } else {
                if ($blogManager->addTag($tag_name)) {
                    $success_message = 'Tag erfolgreich hinzugefügt: ' . htmlspecialchars($tag_name);
                    // Tags neu laden
                    $tags = $blogManager->getTags();
                } else {
                    $error_message = 'Fehler beim Hinzufügen des Tags.';
                }
            }
        }
        // Tag umbenennen
        else if ($action === 'rename' && !empty($_POST['old_name']) && !empty($_POST['new_name'])) {
            $old_name = trim($_POST['old_name']);
            $new_name = trim($_POST['new_name']);
            
            // Prüfen, ob alte Tag existiert
            if (!isset($tags[$old_name])) {
                $error_message = 'Der zu ändernde Tag existiert nicht.';
            }
            // Prüfen, ob neue Tag bereits existiert
            else if ($old_name !== $new_name && isset($tags[$new_name])) {
                $error_message = 'Der neue Tag existiert bereits.';
            } else {
                if ($blogManager->renameTag($old_name, $new_name)) {
                    $success_message = 'Tag erfolgreich umbenannt von "' . htmlspecialchars($old_name) . '" zu "' . htmlspecialchars($new_name) . '".';
                    // Tags neu laden
                    $tags = $blogManager->getTags();
                } else {
                    $error_message = 'Fehler beim Umbenennen des Tags.';
                }
            }
        }
        // Tag löschen
        else if ($action === 'delete' && !empty($_POST['tag_name'])) {
            $tag_name = trim($_POST['tag_name']);
            
            if (!isset($tags[$tag_name])) {
                $error_message = 'Der zu löschende Tag existiert nicht.';
            } else {
                if ($blogManager->deleteTag($tag_name)) {
                    $success_message = 'Tag erfolgreich gelöscht.';
                    // Tags neu laden
                    $tags = $blogManager->getTags();
                } else {
                    $error_message = 'Fehler beim Löschen des Tags.';
                }
            }
        }
    }
}

// Nach Anzahl der Posts sortieren (absteigend)
arsort($tags);
