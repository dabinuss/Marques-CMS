<?php
/**
 * marques CMS - Medienverwaltung
 * 
 * Verwaltung von Mediendateien wie Bilder, Videos, etc.
 *
 * @package marques
 * @subpackage admin
 */

use Marques\Admin\MarquesAdmin;
use \Marques\Core\DatabaseHandler;
use Marques\Core\MediaManager;

$adminApp = new MarquesAdmin();
$container = $adminApp->getContainer();

// Hole den DatabaseHandler via DI
$dbHandler = $container->get(DatabaseHandler::class);
$mediaManager = $container->get(MediaManager::class);

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