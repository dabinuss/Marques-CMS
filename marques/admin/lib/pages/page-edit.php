<?php
/**
 * marques CMS - Seiten-Editor
 *
 * Ermöglicht das Bearbeiten von bestehenden Seiten.
 *
 * @package marques
 * @subpackage admin
 */

use Marques\Admin\MarquesAdmin;
use \Marques\Data\Database\Handler as DatabaseHandler;
use \Marques\Core\PageManager;

// Hole den DatabaseHandler via DI

$pageManager = $container->get(PageManager::class);

$dbHandler->useTable('settings');
$system_config = $dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message = '';

// Seitendaten initialisieren (Default-Werte)
$page = [
    'id' => '',
    'title' => '',
    'description' => '',
    'content' => '',
    'template' => 'page',
    'featured_image' => ''
];

$editing = false;
// Überprüfen, ob es sich um die Bearbeitung einer bestehenden Seite handelt
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
                
                // Falls es sich um eine neue ID handelt (selten im Edit-Modus)
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
