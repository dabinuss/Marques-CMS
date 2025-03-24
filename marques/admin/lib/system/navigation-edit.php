<?php
/**
 * marques CMS - Navigation Management (Content for Editing)
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

$menu_item = [
    'id'      => '',
    'title'   => '',
    'url'     => '',
    'target'  => '_self'
];

// Menüpunkt anhand der übergebenen ID laden
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $item_id = $_GET['id'];
    // Hier wird angenommen, dass getRecord($id) den Eintrag als Array zurückliefert
    $menu_item = $dbHandler->getRecord($item_id);
    if (!$menu_item) {
        $error_message = 'Menüpunkt nicht gefunden.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültiger CSRF-Token.';
    } else {
        $menu_item['title'] = $_POST['title'] ?? '';
        $menu_item['url']   = $_POST['url'] ?? '';
        $menu_item['target'] = (isset($_POST['target']) && $_POST['target'] === '_blank') ? '_blank' : '_self';
        if (empty($menu_item['title']) || empty($menu_item['url'])) {
            $error_message = 'Bitte füllen Sie alle erforderlichen Felder aus.';
        } else {
            if ($dbHandler->updateRecord($menu_item['id'], $menu_item)) {
                $success_message = 'Menüpunkt erfolgreich aktualisiert.';
            } else {
                $error_message = 'Fehler beim Aktualisieren des Menüpunkts.';
            }
        }
    }
}

$commonUrls = [
    ['title' => 'Startseite', 'url' => Helper::getSiteUrl()],
    ['title' => 'Blog', 'url' => Helper::getSiteUrl('blog')],
    ['title' => 'Über uns', 'url' => Helper::getSiteUrl('about')],
    ['title' => 'Kontakt', 'url' => Helper::getSiteUrl('contact')]
];

// Nun stehen $menu_item, $success_message, $error_message und $commonUrls zur Verfügung.
