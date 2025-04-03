<?php
declare(strict_types=1);

namespace Marques\Service;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Util\SafetyXSS;

class NavigationManager {
    private DatabaseHandler $dbHandler;
    
    public function __construct(DatabaseHandler $dbHandler) {
        $this->dbHandler = $dbHandler;
    }
    
    /**
     * Liefert alle Navigationseinträge aus der Tabelle.
     *
     * @return array Alle Datensätze der Navigationstabelle
     */
    public function getAllNavigationItems(): array {
        return $this->dbHandler->table('navigation')->find();
    }
    
    /**
     * Gruppiert die Navigationseinträge nach Menütyp und sortiert sie nach "order".
     *
     * @return array Array mit den Schlüsseln "main_menu" und "footer_menu"
     */
    public function getNavigation(): array {
        $records = $this->dbHandler->table('navigation')->find();
        $navigation = [
            'main_menu'   => [],
            'footer_menu' => []
        ];
        foreach ($records as $record) {
            if (isset($record['menu_type'])) {
                if ($record['menu_type'] === 'main_menu') {
                    $navigation['main_menu'][] = $record;
                } elseif ($record['menu_type'] === 'footer_menu') {
                    $navigation['footer_menu'][] = $record;
                }
            }
        }
        // Sortiere beide Menüs anhand des "order"-Feldes
        usort($navigation['main_menu'], function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        usort($navigation['footer_menu'], function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        return $navigation;
    }
    
    /**
     * Fügt einen neuen Navigationseintrag hinzu.
     *
     * @param string $menuType 'main_menu' oder 'footer_menu'
     * @param array  $menuItem Assoziatives Array mit Feldern (z. B. title, url, target, order optional)
     * @return bool Erfolg
     */
    public function addMenuItem(string $menuType, array $menuItem): bool {
        $navTable = $this->dbHandler->table('navigation');
        $menuItem['menu_type'] = $menuType;
        // Falls order nicht gesetzt, bestimme die nächste Reihenfolge für diesen Menütyp
        $navigation = $this->getNavigation();
        $orders = array_column($navigation[$menuType], 'order');
        $menuItem['order'] = empty($orders) ? 1 : max($orders) + 1;
        // Generiere eine eindeutige int-ID (Beispiel: aktuelle Zeit in Millisekunden)
        if (!isset($menuItem['id'])) {
            $menuItem['id'] = (int)(microtime(true) * 1000);
        }
        // Daten setzen und dann einfügen (ohne Parameter an insert())
        return (bool) $navTable->data($menuItem)->insert();
    }
    
    /**
     * Aktualisiert einen existierenden Navigationseintrag.
     *
     * @param int   $menuItemId ID des zu aktualisierenden Eintrags
     * @param array $menuItem   Neue Felddaten
     * @return bool Erfolg
     */
    public function updateMenuItem(int $menuItemId, array $menuItem): bool {
        $navTable = $this->dbHandler->table('navigation');
        $menuItem['id'] = $menuItemId;
        // Update über WHERE-Bedingung
        return $navTable->where('id', '=', $menuItemId)->data($menuItem)->update();
    }
    
    /**
     * Löscht einen Navigationseintrag.
     *
     * @param int $menuItemId ID des zu löschenden Eintrags
     * @return bool Erfolg
     */
    public function deleteMenuItem(int $menuItemId): bool {
        // Löschen über WHERE-Bedingung
        return $this->dbHandler->table('navigation')->where('id', '=', $menuItemId)->delete();
    }
    
    /**
     * Sortiert die Navigationseinträge eines bestimmten Typs neu.
     * Das Array $order enthält die IDs in der gewünschten Reihenfolge.
     *
     * @param string $menuType 'main_menu' oder 'footer_menu'
     * @param array  $order    Array von int‑IDs in der gewünschten Reihenfolge
     * @return bool Erfolg
     */
    public function reorderMenu(string $menuType, array $order): bool {
        $navTable = $this->dbHandler->table('navigation');
        // Für jede ID den entsprechenden Datensatz abrufen und das Order-Feld aktualisieren
        foreach ($order as $index => $id) {
            $record = $navTable->where('id', '=', $id)->first();
            if ($record !== null) {
                $record['order'] = $index + 1;
                $navTable->where('id', '=', $id)->data($record)->update();
            }
        }
        return true;
    }
    
    /**
     * Rendert das Hauptmenü als HTML.
     *
     * @param array $options Optionen für die Darstellung (optional)
     * @return string HTML-Code
     */
    public function renderMainMenu(array $options = []): string {
        $navigation = $this->getNavigation();
        $mainMenu = $navigation['main_menu'];
        $html = '<nav class="marques-main-navigation"><ul class="marques-menu">';
        foreach ($mainMenu as $item) {
            $active = '';
            if (isset($_SERVER['REQUEST_URI'])) {
                $currentUrl = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                $menuUrl = isset($item['url']) ? parse_url($item['url'], PHP_URL_PATH) : '';
                if ((string)$currentUrl === (string)$menuUrl ||
                    (((string)$menuUrl !== '/') && ((string)$menuUrl !== '') && strpos((string)$currentUrl, (string)$menuUrl) === 0)
                ) {
                    $active = ' class="active"';
                }
            }
            $target = (isset($item['target']) && $item['target'] === '_blank') ? ' target="_blank"' : '';
            $html .= '<li class="marques-menu-item' . $active . '">';
            $html .= '<a href="' . SafetyXSS::escapeOutput($item['url'] ?? '#', 'html') . '"' . $target . '>' .
                     SafetyXSS::escapeOutput($item['title'] ?? 'Menüpunkt', 'html') . '</a>';
            $html .= '</li>';
        }
        $html .= '</ul></nav>';
        return $html;
    }
    
    /**
     * Rendert das Footermenü als HTML.
     *
     * @param array $options Optionen für die Darstellung (optional)
     * @return string HTML-Code
     */
    public function renderFooterMenu(array $options = []): string {
        $navigation = $this->getNavigation();
        $footerMenu = $navigation['footer_menu'];
        $html = '<nav class="marques-footer-navigation"><ul class="marques-menu">';
        foreach ($footerMenu as $item) {
            $target = (isset($item['target']) && $item['target'] === '_blank') ? ' target="_blank"' : '';
            $html .= '<li class="marques-menu-item">';
            $html .= '<a href="' . SafetyXSS::escapeOutput($item['url'] ?? '#', 'html') . '"' . $target . '>' .
                     SafetyXSS::escapeOutput($item['title'] ?? 'Menüpunkt', 'html') . '</a>';
            $html .= '</li>';
        }
        $html .= '</ul></nav>';
        return $html;
    }   
}
