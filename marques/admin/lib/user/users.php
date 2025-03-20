<?php
/**
 * marques CMS - Benutzerverwaltung
 * 
 * Verwaltung der Benutzer im Admin-Panel.
 *
 * @package marques
 * @subpackage admin
 */

// Benutzer-Objekt initialisieren
$user = new \Marques\Core\User();

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

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