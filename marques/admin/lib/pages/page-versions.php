<?php
/**
 * marques CMS - Seiten-Versionen
 * 
 * Anzeige und Verwaltung von Versionen einer Seite.
 *
 * @package marques
 * @subpackage admin
 */

// Admin-Klasse initialisieren
$admin = new \Marques\Core\Admin();
$admin->requireLogin();

// Benutzer-Objekt initialisieren
$user = new \Marques\Core\User();

// PageManager initialisieren
$pageManager = new \Marques\Core\PageManager();

// VersionManager initialisieren
$versionManager = new \Marques\Core\VersionManager();

// Konfiguration laden
$configManager = \Marques\Core\AppConfig::getInstance();
$system_config = $configManager->load('system') ?: [];

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message = '';

// Überprüfen, ob eine Seiten-ID übergeben wurde
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: pages.php');
    exit;
}

$page_id = $_GET['id'];
$page = $pageManager->getPage($page_id);

// Prüfen, ob die Seite existiert
if (!$page) {
    header('Location: pages.php');
    exit;
}

// Versionen der Seite abrufen
$versions = $versionManager->getVersions('pages', $page_id);

// Versions-Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            $version_id = $_POST['version_id'] ?? '';
            
            if (empty($version_id)) {
                $error_message = 'Keine Versions-ID angegeben.';
            } else {
                // Version wiederherstellen
                if ($action === 'restore') {
                    if ($versionManager->restoreVersion('pages', $page_id, $version_id, $user->getCurrentUsername())) {
                        $success_message = 'Version wurde erfolgreich wiederhergestellt.';
                    } else {
                        $error_message = 'Fehler beim Wiederherstellen der Version.';
                    }
                }
                // Version löschen
                elseif ($action === 'delete') {
                    if ($versionManager->deleteVersion('pages', $page_id, $version_id)) {
                        $success_message = 'Version wurde erfolgreich gelöscht.';
                        // Versionen neu laden
                        $versions = $versionManager->getVersions('pages', $page_id);
                    } else {
                        $error_message = 'Fehler beim Löschen der Version.';
                    }
                }
            }
        }
    }
}

// Versions-Inhalt anzeigen, wenn angefordert
$show_version = false;
$version_content = '';
$version_metadata = null;
$diff = [];

if (isset($_GET['version']) && !empty($_GET['version'])) {
    $version_id = $_GET['version'];
    $version_content = $versionManager->getVersionContent('pages', $page_id, $version_id);
    
    if ($version_content !== false) {
        $show_version = true;
        
        // Versions-Metadaten finden
        foreach ($versions as $v) {
            if ($v['version_id'] === $version_id) {
                $version_metadata = $v;
                break;
            }
        }
        
        // Diff zur aktuellen Version erstellen
        $current_content = $page['content'];
        $diff = $versionManager->createDiff($version_content, $current_content);
    }
}