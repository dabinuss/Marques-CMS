<?php
/**
 * marques CMS - Navigation Management
 * 
 * Verwaltung der Website-Navigation (Hauptmenü und Footermenü).
 *
 * @package marques
 * @subpackage admin
 */

// Admin-Klasse initialisieren
$admin = new \Marques\Core\Admin();
$admin->requireLogin();

// Benutzer-Objekt initialisieren
$user = new \Marques\Core\User();

// NavigationManager initialisieren
$navManager = new \Marques\Core\NavigationManager();

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

// Standard-Menütyp
$activeMenu = isset($_GET['tab']) && $_GET['tab'] === 'footer' ? 'footer_menu' : 'main_menu';
$activeMenuTitle = $activeMenu === 'main_menu' ? 'Hauptmenü' : 'Footermenü';

// Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $action = $_POST['action'] ?? '';
        
        // Menüpunkt hinzufügen
        if ($action === 'add_item') {
            $menuItem = [
                'title' => $_POST['title'] ?? '',
                'url' => $_POST['url'] ?? '',
                'target' => isset($_POST['target']) && $_POST['target'] === '_blank' ? '_blank' : '_self'
            ];
            
            if (empty($menuItem['title']) || empty($menuItem['url'])) {
                $error_message = 'Bitte füllen Sie alle Felder aus.';
            } else {
                $menuType = $_POST['menu_type'] ?? $activeMenu;
                
                if ($navManager->addMenuItem($menuType, $menuItem)) {
                    $success_message = 'Menüpunkt erfolgreich hinzugefügt.';
                } else {
                    $error_message = 'Fehler beim Hinzufügen des Menüpunkts.';
                }
            }
        }
        // Menüpunkt aktualisieren
        else if ($action === 'update_item') {
            $menuItemId = $_POST['menu_item_id'] ?? '';
            $menuItem = [
                'title' => $_POST['title'] ?? '',
                'url' => $_POST['url'] ?? '',
                'target' => isset($_POST['target']) && $_POST['target'] === '_blank' ? '_blank' : '_self'
            ];
            
            if (empty($menuItemId) || empty($menuItem['title']) || empty($menuItem['url'])) {
                $error_message = 'Bitte füllen Sie alle Felder aus.';
            } else {
                $menuType = $_POST['menu_type'] ?? $activeMenu;
                
                if ($navManager->updateMenuItem($menuType, $menuItemId, $menuItem)) {
                    $success_message = 'Menüpunkt erfolgreich aktualisiert.';
                } else {
                    $error_message = 'Fehler beim Aktualisieren des Menüpunkts.';
                }
            }
        }
        // Menüpunkt löschen
        else if ($action === 'delete_item') {
            $menuItemId = $_POST['menu_item_id'] ?? '';
            
            if (empty($menuItemId)) {
                $error_message = 'Ungültige Menüpunkt-ID.';
            } else {
                $menuType = $_POST['menu_type'] ?? $activeMenu;
                
                if ($navManager->deleteMenuItem($menuType, $menuItemId)) {
                    $success_message = 'Menüpunkt erfolgreich gelöscht.';
                } else {
                    $error_message = 'Fehler beim Löschen des Menüpunkts.';
                }
            }
        }
        // Menü neu ordnen
        else if ($action === 'reorder_menu') {
            $order = isset($_POST['menu_order']) ? json_decode($_POST['menu_order'], true) : [];
            
            if (empty($order)) {
                $error_message = 'Ungültige Sortierreihenfolge.';
            } else {
                $menuType = $_POST['menu_type'] ?? $activeMenu;
                
                if ($navManager->reorderMenu($menuType, $order)) {
                    $success_message = 'Menü erfolgreich neu sortiert.';
                } else {
                    $error_message = 'Fehler beim Sortieren des Menüs.';
                }
            }
        }
    }
}

// Häufig verwendete URLs vorbereiten
$commonUrls = [
    ['title' => 'Startseite', 'url' => \Marques\Core\Helper::getSiteUrl()],
    ['title' => 'Blog', 'url' => \Marques\Core\Helper::getSiteUrl('blog')],
    ['title' => 'Über uns', 'url' => \Marques\Core\Helper::getSiteUrl('about')],
    ['title' => 'Kontakt', 'url' => \Marques\Core\Helper::getSiteUrl('contact')]
];

// Menüs laden
$mainMenu = $navManager->getMenu('main_menu');
$footerMenu = $navManager->getMenu('footer_menu');

// Migration des bestehenden Menüs, wenn leer
if (empty($mainMenu) && isset($_GET['migrate']) && $_GET['migrate'] === '1') {
    if ($navManager->migrateExistingMenu()) {
        $success_message = 'Das bestehende Menü wurde erfolgreich migriert.';
        $mainMenu = $navManager->getMenu('main_menu'); // Neu laden
    }
}