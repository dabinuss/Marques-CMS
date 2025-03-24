<?php
/**
 * marques CMS - Seiten-Editor
 * 
 * Ermöglicht das Erstellen und Bearbeiten von Seiten.
 *
 * @package marques
 * @subpackage admin
 */

use Marques\Admin\MarquesAdmin;
use \Marques\Core\DatabaseHandler;
use \Marques\Core\PageManager;

$adminApp = new MarquesAdmin();
$container = $adminApp->getContainer();

// Hole den DatabaseHandler via DI
$dbHandler = $container->get(DatabaseHandler::class);
$pageManager = $container->get(PageManager::class);

$dbHandler->useTable('settings');
$system_config = $dbHandler->getAllSettings();

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