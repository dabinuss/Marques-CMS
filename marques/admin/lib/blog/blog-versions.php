<?php
/**
 * marques CMS - Blog-Versionen
 * 
 * Anzeige und Verwaltung von Versionen eines Blog-Beitrags.
 *
 * @package marques
 * @subpackage admin
 */

// BlogManager initialisieren
$blogManager = new \Marques\Core\BlogManager();

// VersionManager initialisieren
$versionManager = new \Marques\Core\VersionManager();

// Konfiguration laden
$configManager = \Marques\Core\AppConfig::getInstance();
$system_config = $configManager->load('system') ?: [];

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message = '';

// Überprüfen, ob eine Beitrags-ID übergeben wurde
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: blog.php');
    exit;
}

$post_id = $_GET['id'];
$post = $blogManager->getPost($post_id);

// Prüfen, ob der Beitrag existiert
if (!$post) {
    header('Location: blog.php');
    exit;
}

// Versionen des Beitrags abrufen
$versions = $versionManager->getVersions('blog', $post_id);

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
                    if ($versionManager->restoreVersion('blog', $post_id, $version_id, $user->getCurrentDisplayName())) {
                        $success_message = 'Version wurde erfolgreich wiederhergestellt.';
                    } else {
                        $error_message = 'Fehler beim Wiederherstellen der Version.';
                    }
                }
                // Version löschen
                elseif ($action === 'delete') {
                    if ($versionManager->deleteVersion('blog', $post_id, $version_id)) {
                        $success_message = 'Version wurde erfolgreich gelöscht.';
                        // Versionen neu laden
                        $versions = $versionManager->getVersions('blog', $post_id);
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
    $version_content = $versionManager->getVersionContent('blog', $post_id, $version_id);
    
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
        $current_content = file_get_contents(MARQUES_CONTENT_DIR . '/blog/' . $post_id . '.md');
        $diff = $versionManager->createDiff($version_content, $current_content);
    }
}
