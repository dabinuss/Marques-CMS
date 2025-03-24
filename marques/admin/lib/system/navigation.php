<?php
/**
 * marques CMS - Navigation Management
 *
 * Verwaltung der Website-Navigation (Hauptmenü und Footermenü).
 *
 * @package marques
 * @subpackage admin
 */

use Marques\Admin\MarquesAdmin;
use Marques\Core\DatabaseHandler;
use Marques\Core\Helper;

$adminApp = new MarquesAdmin();
$container = $adminApp->getContainer();

// Hole den DatabaseHandler via DI
$dbHandler = $container->get(DatabaseHandler::class);

/** @var \Marques\Core\DatabaseHandler $dbHandler */
// Statt eines statischen Aufrufs verwenden wir nun den per DI bereitgestellten DatabaseHandler
$dbHandler->useTable('navigation');

// Erfolgsmeldung und Fehlermeldung initialisieren
$success_message = '';
$error_message   = '';

// Standard-Menütyp (default: main_menu)
$activeMenu = (isset($_GET['tab']) && $_GET['tab'] === 'footer') ? 'footer_menu' : 'main_menu';
$activeMenuTitle = ($activeMenu === 'main_menu') ? 'Hauptmenü' : 'Footermenü';

// Lade *alle* Menüeinträge *einmal*. Wir arbeiten NUR mit diesem Array.
$navigationItems = $dbHandler->getAllRecords();

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
                'menu_type' => $activeMenu, // Verwende $activeMenu
                'title'     => $_POST['title'] ?? '',
                'url'       => $_POST['url'] ?? '',
                'target'    => (isset($_POST['target']) && $_POST['target'] === '_blank') ? '_blank' : '_self',
                'order'     => 0, // Wird unten gesetzt
                // 'id' wird nicht gesetzt – die Datenbank generiert die ID automatisch
            ];
        
            if (empty($menuItem['title']) || empty($menuItem['url'])) {
                $error_message = 'Bitte füllen Sie alle Felder aus.';
            } else {
                // Bestimme die höchste 'order' für den aktuellen Menütyp.
                $maxOrder = 0;
                foreach ($navigationItems as $item) {
                    if ($item['menu_type'] === $activeMenu && isset($item['order']) && $item['order'] > $maxOrder) {
                        $maxOrder = (int)$item['order'];
                    }
                }
                $menuItem['order'] = $maxOrder + 1;
        
                // Speichere in der Datenbank.
                // Hier keine eigene ID übergeben – die Datenbank (FlatFileDatabase) liefert die neue ID zurück
                $newId = $dbHandler->insertRecord($menuItem);
                if ($newId !== false) {
                    $success_message = 'Menüpunkt erfolgreich hinzugefügt. (ID: ' . $newId . ')';
                } else {
                    $error_message = 'Fehler beim Hinzufügen des Menüpunkts.';
                }
            }
        } elseif ($action === 'update_item') {
            $menuItemId = (int)($_POST['menu_item_id'] ?? 0); // ID muss Integer sein
            $menuItem = [
                'title'  => $_POST['title'] ?? '',
                'url'    => $_POST['url'] ?? '',
                'target' => (isset($_POST['target']) && $_POST['target'] === '_blank') ? '_blank' : '_self',
            ];

            if ($menuItemId === 0 || empty($menuItem['title']) || empty($menuItem['url'])) {
                $error_message = 'Bitte füllen Sie alle Felder aus.';
            } else {
                $updated = false;
                foreach ($navigationItems as &$item) { // Referenz nutzen
                    if ((int)$item['id'] === $menuItemId) {
                        $item['title']  = $menuItem['title'];
                        $item['url']    = $menuItem['url'];
                        $item['target'] = $menuItem['target'];
                        $updated = true;

                        // Speichere den Eintrag in der Datenbank
                        if ($dbHandler->updateRecord($menuItemId, $item)) {
                            $success_message = 'Menüpunkt erfolgreich aktualisiert.';
                        } else {
                            $error_message = 'Fehler beim Aktualisieren des Menüpunkts.';
                        }
                        break;
                    }
                }
                unset($item);
                if (!$updated) {
                    $error_message = 'Ungültige Menüpunkt-ID.';
                }
            }
        } elseif ($action === 'delete_item') {
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
                $newNavigationItems = [];
                $sort_success = true;
                foreach ($order as $index => $itemId) {
                    $found = false;
                    foreach ($navigationItems as $item) {
                        if ((int)$item['id'] === (int)$itemId && $item['menu_type'] === $menuType) {
                            $item['order'] = $index + 1;
                            $newNavigationItems[] = $item;
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
                foreach ($navigationItems as $item) {
                    if ($item['menu_type'] !== $menuType) {
                        $newNavigationItems[] = $item;
                    }
                }
                $navigationItems = $newNavigationItems;
                if ($sort_success) {
                    $success_message = 'Menü erfolgreich neu sortiert.';
                }
            }
        }
    }
    // Nach der Verarbeitung der POST-Daten: Aktualisiere das lokale Array aus der Datenbank,
    // um Duplikationen und veraltete Einträge zu vermeiden.
    $navigationItems = $dbHandler->getAllRecords();
}

// Häufig verwendete URLs vorbereiten
$commonUrls = [
    ['title' => 'Startseite', 'url' => Helper::getSiteUrl()],
    ['title' => 'Blog', 'url' => Helper::getSiteUrl('blog')],
    ['title' => 'Über uns', 'url' => Helper::getSiteUrl('about')],
    ['title' => 'Kontakt', 'url' => Helper::getSiteUrl('contact')]
];

// Menüs aus den Navigationseinträgen laden und nach 'order' sortieren
$mainMenu = [];
$footerMenu = [];

foreach ($navigationItems as $item) {
    if ($item['menu_type'] === 'main_menu') {
        $mainMenu[] = $item;
    } elseif ($item['menu_type'] === 'footer_menu') {
        $footerMenu[] = $item;
    }
}

// Sortiere die Menüs nach 'order'
usort($mainMenu, function($a, $b) {
    return $a['order'] <=> $b['order'];
});

usort($footerMenu, function($a, $b) {
    return $a['order'] <=> $b['order'];
});
