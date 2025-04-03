<?php
/**
 * marques CMS - Navigation Management (Content for Adding)
 */
use Marques\Admin\MarquesAdmin;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Util\Helper;




$dbHandler->useTable('navigation');

$helper = $container->get(Helper::class);

$success_message = '';
$error_message   = '';

// Standard-Menütyp (default: main_menu)
$activeMenu = (isset($_GET['tab']) && $_GET['tab'] === 'footer') ? 'footer_menu' : 'main_menu';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $menuItem = [
            'menu_type' => $activeMenu,
            'title'     => $_POST['title'] ?? '',
            'url'       => $_POST['url'] ?? '',
            'target'    => (isset($_POST['target']) && $_POST['target'] === '_blank') ? '_blank' : '_self',
            'order'     => 0,
        ];
        if (empty($menuItem['title']) || empty($menuItem['url'])) {
            $error_message = 'Bitte füllen Sie alle Felder aus.';
        } else {
            // Bestimme die höchste Order für den aktuellen Menütyp
            $navigationItems = $dbHandler->findAll();
            $maxOrder = 0;
            foreach ($navigationItems as $item) {
                if ($item['menu_type'] === $activeMenu && isset($item['order']) && $item['order'] > $maxOrder) {
                    $maxOrder = (int)$item['order'];
                }
            }
            $menuItem['order'] = $maxOrder + 1;
            $newId = $dbHandler->insert($menuItem);
            if ($newId !== false) {
                $success_message = 'Menüpunkt erfolgreich hinzugefügt. (ID: ' . $newId . ')';
            } else {
                $error_message = 'Fehler beim Hinzufügen des Menüpunkts.';
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

// Nun stehen $success_message, $error_message, $activeMenu und $commonUrls zur Verfügung.
