<?php
/**
 * marques CMS - Benutzerverwaltung
 * 
 * Verwaltung der Benutzer im Admin-Panel.
 *
 * @package marques
 * @subpackage admin
 */

use Marques\Admin\MarquesAdmin;
use Marques\Core\DatabaseHandler;
use Marques\Core\User;

$adminApp = new MarquesAdmin();
$container = $adminApp->getContainer();

$dbHandler = $container->get(DatabaseHandler::class);
$dbHandler->useTable('settings');

// Benutzer-Objekt initialisieren
$user = $container->get(User::class);

// Meldungsvariablen
$success_message = '';
$error_message = '';

// Benutzer löschen
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $username = $_POST['username'] ?? '';
        
        // Verhindern, dass der Admin-Account gelöscht wird
        if ($username === 'admin') {
            $error_message = 'Der Administrator-Account kann nicht gelöscht werden.';
        } elseif ($username === $user->getCurrentDisplayName()) {
            $error_message = 'Sie können Ihren eigenen Account nicht löschen.';
        } else {
            if ($user->deleteUser($username)) {
                $success_message = 'Benutzer erfolgreich gelöscht.';
            } else {
                $error_message = 'Fehler beim Löschen des Benutzers.';
            }
        }
    }
}

// Alle Benutzer laden
$all_users = $user->getAllUsers();

// Seitentitel festlegen
$page_title = 'Benutzer verwalten';