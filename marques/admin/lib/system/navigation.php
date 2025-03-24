<?php
/**
 * marques CMS - Navigation Management (Content for List)
 */
use Marques\Admin\MarquesAdmin;
use Marques\Core\DatabaseHandler;
use Marques\Core\Helper;

$adminApp = new MarquesAdmin();
$container = $adminApp->getContainer();

$dbHandler = $container->get(DatabaseHandler::class);
$dbHandler->useTable('navigation');

$success_message = '';
$error_message   = '';

// Standard-Menütyp (default: main_menu)
$activeMenu = (isset($_GET['tab']) && $_GET['tab'] === 'footer') ? 'footer_menu' : 'main_menu';

// Alle Navigationseinträge laden
$navigationItems = $dbHandler->getAllRecords();

// Aktionen verarbeiten (Löschen und Sortierung)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'delete_item') {
            $menuItemId = (int)($_POST['menu_item_id'] ?? 0);
            if ($menuItemId === 0) {
                $error_message = 'Ungültige Menüpunkt-ID.';
            } else {
                $index = null;
                foreach ($navigationItems as $key => $item) {
                    if ((int)$item['id'] === $menuItemId) {
                        $index = $key;
                        break;
                    }
                }
                if ($index !== null) {
                    if ($dbHandler->deleteRecord($menuItemId)) {
                        $success_message = 'Menüpunkt erfolgreich gelöscht.';
                        unset($navigationItems[$index]);
                        $navigationItems = array_values($navigationItems);
                    } else {
                        $error_message = 'Fehler beim Löschen des Menüpunkts.';
                    }
                } else {
                    $error_message = 'Ungültige Menüpunkt-ID.';
                }
            }
        } elseif ($action === 'reorder_menu') {
            $order = isset($_POST['menu_order']) ? json_decode($_POST['menu_order'], true) : [];
            $menuType = $_POST['menu_type'] ?? $activeMenu;
            if (empty($order)) {
                $error_message = 'Ungültige Sortierreihenfolge.';
            } else {
                $sort_success = true;
                foreach ($order as $index => $itemId) {
                    $found = false;
                    foreach ($navigationItems as $item) {
                        if ((int)$item['id'] === (int)$itemId && $item['menu_type'] === $menuType) {
                            $item['order'] = $index + 1;
                            if (!$dbHandler->updateRecord((int)$itemId, $item)) {
                                $error_message = "Fehler beim Speichern der Sortierung für ID $itemId.";
                                $sort_success = false;
                                break 2;
                            }
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $error_message = "Ungültiger Menüpunkt in Sortierreihenfolge: $itemId";
                        $sort_success = false;
                        break;
                    }
                }
                if ($sort_success) {
                    $success_message = 'Menü erfolgreich neu sortiert.';
                }
            }
        }
    }
    // Nach POST-Daten: Navigation neu laden
    $navigationItems = $dbHandler->getAllRecords();
}

// Häufig verwendete URLs
$commonUrls = [
    ['title' => 'Startseite', 'url' => Helper::getSiteUrl()],
    ['title' => 'Blog', 'url' => Helper::getSiteUrl('blog')],
    ['title' => 'Über uns', 'url' => Helper::getSiteUrl('about')],
    ['title' => 'Kontakt', 'url' => Helper::getSiteUrl('contact')]
];

// Menüs aus den Navigationseinträgen trennen und nach 'order' sortieren
$mainMenu = [];
$footerMenu = [];
foreach ($navigationItems as $item) {
    if ($item['menu_type'] === 'main_menu') {
        $mainMenu[] = $item;
    } elseif ($item['menu_type'] === 'footer_menu') {
        $footerMenu[] = $item;
    }
}
usort($mainMenu, fn($a, $b) => $a['order'] <=> $b['order']);
usort($footerMenu, fn($a, $b) => $a['order'] <=> $b['order']);

// Jetzt stehen Variablen wie $mainMenu, $footerMenu, $success_message, $error_message, $commonUrls und $activeMenu zur Verfügung.
