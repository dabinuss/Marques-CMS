<?php
/**
 * marques CMS - Systemeinstellungen
 * 
 * Verwaltung der Systemeinstellungen im Admin-Panel.
 *
 * @package marques
 * @subpackage admin
 */

// Admin-Klasse initialisieren
$admin = new \Marques\Core\Admin();
$admin->requireLogin();

// Benutzer-Objekt initialisieren
$user = new \Marques\Core\User();

// Settings Manager initialisieren
$settings = new \Marques\Core\SettingsManager();

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Meldungsvariablen
$success_message = '';
$error_message = '';

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        // Gruppe bestimmen
        $group = $_POST['settings_group'] ?? 'general';
        
        // Einstellungen basierend auf der Gruppe aktualisieren
        switch ($group) {
            case 'general':
                $settings->setMultipleSettings([
                    'site_name' => $_POST['site_name'] ?? '',
                    'site_description' => $_POST['site_description'] ?? '',
                    'admin_email' => $_POST['admin_email'] ?? '',
                    'contact_email' => $_POST['contact_email'] ?? '',
                    'contact_phone' => $_POST['contact_phone'] ?? '',
                ]);
                break;
                
            case 'other':
                $settings->setSetting('social_links.facebook', $_POST['social_facebook'] ?? '');
                $settings->setSetting('social_links.twitter', $_POST['social_twitter'] ?? '');
                $settings->setSetting('social_links.instagram', $_POST['social_instagram'] ?? '');
                $settings->setSetting('social_links.linkedin', $_POST['social_linkedin'] ?? '');
                $settings->setSetting('social_links.youtube', $_POST['social_youtube'] ?? '');
                break;
                
            case 'content':
                $settings->setMultipleSettings([
                    'posts_per_page' => (int)($_POST['posts_per_page'] ?? 10),
                    'excerpt_length' => (int)($_POST['excerpt_length'] ?? 150),
                    'comments_enabled' => isset($_POST['comments_enabled']),
                    'blog_url_format'   => $_POST['blog_url_format'] ?? 'date_slash',
                ]);
                break;
                
            case 'system':
                $settings->setMultipleSettings([
                    'debug' => isset($_POST['debug']),
                    'cache_enabled' => isset($_POST['cache_enabled']),
                    'maintenance_mode' => isset($_POST['maintenance_mode']),
                    'maintenance_message' => $_POST['maintenance_message'] ?? '',
                    'timezone' => $_POST['timezone'] ?? 'Europe/Berlin',
                    'date_format' => $_POST['date_format'] ?? 'd.m.Y',
                    'time_format' => $_POST['time_format'] ?? 'H:i',
                ]);
                break;
                
            case 'appearance':
                $settings->setMultipleSettings([
                    'active_theme' => $_POST['active_theme'] ?? '',
                ]);
                break;

            case 'seo':
                $settings->setMultipleSettings([
                    'meta_keywords' => $_POST['meta_keywords'] ?? '',
                    'meta_author' => $_POST['meta_author'] ?? '',
                    'google_analytics_id' => $_POST['google_analytics_id'] ?? '',
                ]);
                break;
        }
        
        // Einstellungen speichern
        if ($settings->saveSettings()) {
            $success_message = 'Einstellungen wurden erfolgreich gespeichert.';
        } else {
            $error_message = 'Fehler beim Speichern der Einstellungen.';
        }
    }

    // Sicherstellen, dass base_url korrekt ist, falls manuell geändert
    if (isset($_POST['base_url'])) {
        $baseUrl = $_POST['base_url'];
        if (strpos($baseUrl, '/admin') !== false) {
            $_POST['base_url'] = preg_replace('|/admin$|', '', $baseUrl);
        }
    }
}

// Aktuelle Einstellungen laden
$current_settings = $settings->getAllSettings();

// Aktiven Tab bestimmen
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
$allowed_tabs = ['general', 'other', 'content', 'system', 'seo', 'appearance'];

if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'general';
}

// Titel der Seite
$page_title = 'Systemeinstellungen';

// Liste der Zeitzonen
$timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);

// Liste der Datumsformate
$date_formats = [
    'd.m.Y' => date('d.m.Y'),
    'Y-m-d' => date('Y-m-d'),
    'd/m/Y' => date('d/m/Y'),
    'm/d/Y' => date('m/d/Y'),
    'j. F Y' => date('j. F Y'),
];

// Liste der Zeitformate
$time_formats = [
    'H:i' => date('H:i'),
    'H:i:s' => date('H:i:s'),
    'g:i a' => date('g:i a'),
    'g:i A' => date('g:i A'),
];