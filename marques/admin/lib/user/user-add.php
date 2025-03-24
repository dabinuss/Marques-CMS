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

// Meldungsvariablen initialisieren
$success_message = '';
$error_message = '';

// Prüfen, ob ein Benutzer bearbeitet wird (Edit-Modus)
$edit_mode = isset($_GET['username']) && !empty($_GET['username']);
$username = $edit_mode ? $_GET['username'] : '';
$userData = [];

// Verfügbare Rollen definieren
$available_roles = [
    'admin'  => 'Administrator',
    'editor' => 'Editor',
    'author' => 'Autor'
];

// Falls Edit-Modus: Benutzerdaten laden
if ($edit_mode) {
    $userData = $user->getUserData($username);
    if (!$userData) {
        header('Location: users.php');
        exit;
    }
}

$initial_setup = isset($_GET['initial_setup']) && $_GET['initial_setup'] === 'true';

// Formularverarbeitung
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $form_username = $_POST['username'] ?? '';
        $display_name  = $_POST['display_name'] ?? '';
        $password      = $_POST['password'] ?? '';
        $role          = $_POST['role'] ?? 'editor';

        // Spezieller Fall: Admin-Passwort im initialen Setup ändern
        if ($username === 'admin' && $initial_setup) {
            if (empty($password)) {
                $error_message = 'Bitte setzen Sie ein neues Passwort.';
            } elseif (strlen($password) < 8) {
                $error_message = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
            } else {
                $user_data = [
                    'password'    => $password,
                    'first_login' => false  // Erstes Login abgeschlossen
                ];
                if ($user->updateUser($username, $user_data)) {
                    session_unset();
                    session_destroy();
                    $success_message = 'Passwort erfolgreich geändert. Bitte melden Sie sich mit dem neuen Passwort an.';
                    header('Location: login.php');
                    exit;
                } else {
                    $error_message = 'Fehler beim Aktualisieren des Passworts.';
                }
            }
        }
        
        // Rolle validieren
        if (!array_key_exists($role, $available_roles)) {
            $role = 'editor';
        }
        
        // Daten für Update oder Erstellung vorbereiten
        $user_data = [
            'display_name' => $display_name,
            'role'         => $role
        ];
        
        // Passwort nur verarbeiten, wenn angegeben
        if (!empty($password)) {
            $user_data['password'] = $password;
        }
        
        // Benutzer erstellen oder aktualisieren
        if ($edit_mode) {
            if ($user->updateUser($username, $user_data)) {
                $success_message = 'Benutzer erfolgreich aktualisiert.';
                $userData = $user->getUserData($username);
            } else {
                $error_message = 'Fehler beim Aktualisieren des Benutzers.';
            }
        } else {
            if (empty($form_username) || !preg_match('/^[a-zA-Z0-9_]+$/', $form_username)) {
                $error_message = 'Ungültiger Benutzername. Bitte nur Buchstaben, Zahlen und Unterstriche verwenden.';
            } elseif ($user->exists($form_username)) {
                $error_message = 'Ein Benutzer mit diesem Namen existiert bereits.';
            } elseif (empty($password)) {
                $error_message = 'Bitte geben Sie ein Passwort an.';
            } else {
                if ($user->createUser($form_username, $password, $display_name, $role)) {
                    $success_message = 'Benutzer erfolgreich erstellt.';
                    // Formularfelder zurücksetzen
                    $form_username = '';
                    $display_name  = '';
                    $role          = 'editor';
                } else {
                    $error_message = 'Fehler beim Erstellen des Benutzers.';
                }
            }
        }
    }
}

// Seitentitel festlegen
$page_title = $edit_mode ? 'Benutzer bearbeiten' : 'Neuen Benutzer erstellen';