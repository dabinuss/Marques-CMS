<?php
/**
 * marques CMS - Seiten-Verwaltung
 * 
 * Listet alle Seiten auf und ermöglicht deren Verwaltung.
 *
 * @package marques
 * @subpackage admin
 */

// PageManager initialisieren
$pageManager = new \Marques\Core\PageManager();

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

// Löschung verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $page_id = $_POST['page_id'] ?? '';
        
        if ($pageManager->deletePage($page_id)) {
            $success_message = 'Seite erfolgreich gelöscht.';
        } else {
            $error_message = 'Fehler beim Löschen der Seite.';
        }
    }
}

// Seiten abrufen
$pages = $pageManager->getAllPages();

// Nach Titel sortieren
usort($pages, function($a, $b) {
    return strcasecmp($a['title'], $b['title']);
});