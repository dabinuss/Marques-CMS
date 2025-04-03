<?php
/**
 * marques CMS - Navigation Management (Content for Editing)
 */
use Admin\MarquesAdmin;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Util\Helper;




$dbHandler->useTable('navigation');

$helper = $container->get(Helper::class);

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
    $menu_item = $dbHandler->get($item_id);
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
            if ($dbHandler->update($menu_item['id'], $menu_item)) {
                $success_message = 'Menüpunkt erfolgreich aktualisiert.';
            } else {
                $error_message = 'Fehler beim Aktualisieren des Menüpunkts.';
            }
        }
    }
}

$commonUrls = [
    ['title' => 'Startseite', 'url' => $helper->getSiteUrl()],
    ['title' => 'Blog', 'url' => $helper->getSiteUrl('blog')],
    ['title' => 'Über uns', 'url' => $helper->getSiteUrl('about')],
    ['title' => 'Kontakt', 'url' => $helper->getSiteUrl('contact')]
];

// Nun stehen $menu_item, $success_message, $error_message und $commonUrls zur Verfügung.
