<?php
/**
 * marques CMS - Benutzer bearbeiten/erstellen
 * 
 * Formular zum Erstellen und Bearbeiten von Benutzern.
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

// Prüfen, ob ein Benutzer bearbeitet wird
$edit_mode = isset($_GET['username']) && !empty($_GET['username']);
$username = $edit_mode ? $_GET['username'] : '';
$userData = [];

// Verfügbare Rollen
$available_roles = [
    'admin' => 'Administrator',
    'editor' => 'Editor',
    'author' => 'Autor'
];

// Wenn im Bearbeitungsmodus, Benutzerdaten laden
if ($edit_mode) {
    $userData = $user->getUserInfo($username);
    
    if (!$userData) {
        header('Location: users.php');
        exit;
    }
}

$initial_setup = isset($_GET['initial_setup']) && $_GET['initial_setup'] === 'true';

// Bei initialem Setup zusätzliche Validierungen
if ($initial_setup) {
    // Initialisiere $password mit leerem String, falls nicht gesetzt
    $password = $_POST['password'] ?? '';

    // Passwort ist Pflicht
    if (empty($password)) {
        $error_message = 'Bitte setzen Sie ein neues Passwort für den Admin-Account.';
    }
}

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $form_username = $_POST['username'] ?? '';
        $display_name = $_POST['display_name'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'editor';

        if ($username === 'admin' && $initial_setup) {
            if (empty($password)) {
                $error_message = 'Bitte setzen Sie ein neues Passwort.';
            } elseif (strlen($password) < 8) {
                $error_message = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
            } else {
                // Passwort aktualisieren und Session bereinigen
                $user_data = [
                    'password' => $password,
                    'first_login' => false  // Erstes Login abgeschlossen
                ];
                
                if ($user->updateUser($username, $user_data)) {
                    // Session komplett zurücksetzen
                    session_unset();
                    session_destroy();
                    
                    $success_message = 'Passwort erfolgreich geändert. Bitte melden Sie sich mit dem neuen Passwort an.';
                    
                    // Weiterleitung zum Login
                    header('Location: login.php');
                    exit;
                } else {
                    $error_message = 'Fehler beim Aktualisieren des Passworts.';
                }
            }
        }
        
        // Validieren der Rolle
        if (!array_key_exists($role, $available_roles)) {
            $role = 'editor';
        }
        
        // Daten für Update vorbereiten
        $user_data = [
            'display_name' => $display_name,
            'role' => $role
        ];
        
        // Passwort nur hinzufügen, wenn eines angegeben wurde
        if (!empty($password)) {
            $user_data['password'] = $password;
        }
        
        // Benutzer erstellen oder aktualisieren
        if ($edit_mode) {
            // Benutzer aktualisieren
            if ($user->updateUser($username, $user_data)) {
                $success_message = 'Benutzer erfolgreich aktualisiert.';
                $userData = $user->getUserInfo($username); // Aktualisierte Daten laden
            } else {
                $error_message = 'Fehler beim Aktualisieren des Benutzers.';
            }
        } else {
            // Neuen Benutzer erstellen
            // Benutzername validieren
            if (empty($form_username) || !preg_match('/^[a-zA-Z0-9_]+$/', $form_username)) {
                $error_message = 'Ungültiger Benutzername. Bitte nur Buchstaben, Zahlen und Unterstriche verwenden.';
            } elseif ($user->exists($form_username)) {
                $error_message = 'Ein Benutzer mit diesem Namen existiert bereits.';
            } elseif (empty($password)) {
                $error_message = 'Bitte geben Sie ein Passwort an.';
            } else {
                if ($user->createUser($form_username, $password, $display_name, $role)) {
                    $success_message = 'Benutzer erfolgreich erstellt.';
                    // Zurücksetzen des Formulars
                    $form_username = '';
                    $display_name = '';
                    $role = 'editor';
                } else {
                    $error_message = 'Fehler beim Erstellen des Benutzers.';
                }
            }
        }
    }
}

// Seitentitel festlegen
$page_title = $edit_mode ? 'Benutzer bearbeiten' : 'Neuen Benutzer erstellen';