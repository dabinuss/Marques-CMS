<?php
declare(strict_types=1);

/**
 * marques CMS - Navigation Manager Klasse
 * 
 * Verwaltet die Navigation des CMS (Hauptmenü und Footermenü).
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Core;

use Marques\Core\Helper;

class NavigationManager {
    /**
     * @var array Systemkonfiguration
     */
    private $_config;
    
    /**
     * @var ConfigManager ConfigManager-Instanz
     */
    private $_configManager;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $configManager = \Marques\Core\ConfigManager::getInstance();
        $this->_config = $configManager->load('system') ?: [];
        $this->_configManager = ConfigManager::getInstance();
        $this->initNavigationFile();
    }
    
    /**
     * Initialisiert die Navigationsdatei, wenn sie nicht existiert
     */
    public function initNavigationFile() {
        // Prüfen, ob Navigation bereits im ConfigManager existiert
        $navigation = $this->_configManager->load('navigation');
        
        if (empty($navigation)) {
            // Standard-Navigation erstellen
            $defaultNavigation = [
                'main_menu' => [],
                'footer_menu' => []
            ];
            $this->_configManager->save('navigation', $defaultNavigation);
        }
    }
    
    /**
     * Lädt die Navigationsdaten
     * 
     * @return array Navigationsdaten
     */
    public function getNavigation(): array {
        $navigation = $this->_configManager->load('navigation', true); // true für Force-Reload
        
        // Sicherstellen, dass die Grundstruktur vorhanden ist
        if (!isset($navigation['main_menu'])) {
            $navigation['main_menu'] = [];
        }
        if (!isset($navigation['footer_menu'])) {
            $navigation['footer_menu'] = [];
        }
        
        return $navigation;
    }
    
    /**
     * Speichert die Navigationsdaten
     * 
     * @param array $navigation Navigationsdaten
     * @return bool Erfolg
     */
    public function saveNavigation($navigation) {
        // Sicherstellen, dass die grundlegende Struktur vorhanden ist
        if (!isset($navigation['main_menu'])) {
            $navigation['main_menu'] = [];
        }
        if (!isset($navigation['footer_menu'])) {
            $navigation['footer_menu'] = [];
        }
        
        return $this->_configManager->save('navigation', $navigation);
    }
    
    /**
     * Speichert einen Menübereich
     * 
     * @param string $menuType Menütyp ('main_menu' oder 'footer_menu')
     * @param array $menuItems Menüelemente
     * @return bool Erfolg
     */
    public function saveMenu($menuType, $menuItems) {
        $navigation = $this->getNavigation();
        $navigation[$menuType] = $menuItems;
        return $this->saveNavigation($navigation);
    }
    
    /**
     * Gibt ein spezifisches Menü zurück
     * 
     * @param string $menuType Menütyp ('main_menu' oder 'footer_menu')
     * @return array Menüelemente
     */
    public function getMenu($menuType) {
        $navigation = $this->getNavigation();
        return $navigation[$menuType] ?? [];
    }
    
    /**
     * Fügt einen Menüpunkt hinzu
     * 
     * @param string $menuType Menütyp ('main_menu' oder 'footer_menu')
     * @param array $menuItem Menüelement
     * @return bool Erfolg
     */
    public function addMenuItem(string $menuType, array $menuItem): bool {
        $navigation = $this->getNavigation();
        
        if (!isset($menuItem['id'])) {
            $menuItem['id'] = uniqid('menu_');
        }
        
        $navigation[$menuType][] = $menuItem;
        return $this->saveNavigation($navigation);
    }
    
    /**
     * Aktualisiert einen Menüpunkt
     * 
     * @param string $menuType Menütyp ('main_menu' oder 'footer_menu')
     * @param string $menuItemId ID des zu aktualisierenden Menüelements
     * @param array $menuItem Neue Daten für das Menüelement
     * @return bool Erfolg
     */
    public function updateMenuItem(string $menuType, string $menuItemId, array $menuItem): bool {
        $navigation = $this->getNavigation();
        
        foreach ($navigation[$menuType] as $key => $item) {
            if ($item['id'] === $menuItemId) {
                $menuItem['id'] = $menuItemId; // ID beibehalten
                $navigation[$menuType][$key] = $menuItem;
                return $this->saveNavigation($navigation);
            }
        }
        
        // Menüpunkt nicht gefunden
        return false;
    }
    
    /**
     * Löscht einen Menüpunkt
     * 
     * @param string $menuType Menütyp ('main_menu' oder 'footer_menu')
     * @param string $menuItemId ID des zu löschenden Menüelements
     * @return bool Erfolg
     */
    public function deleteMenuItem(string $menuType, string $menuItemId): bool {
        $navigation = $this->getNavigation();
        
        foreach ($navigation[$menuType] as $key => $item) {
            if ($item['id'] === $menuItemId) {
                unset($navigation[$menuType][$key]);
                $navigation[$menuType] = array_values($navigation[$menuType]); // Indizes neu nummerieren
                return $this->saveNavigation($navigation);
            }
        }
        
        // Menüpunkt nicht gefunden
        return false;
    }
    
    /**
     * Sortiert die Menüpunkte neu
     * 
     * @param string $menuType Menütyp ('main_menu' oder 'footer_menu')
     * @param array $order Array mit Menüpunkt-IDs in der gewünschten Reihenfolge
     * @return bool Erfolg
     */
    public function reorderMenu(string $menuType, array $order): bool {
        $navigation = $this->getNavigation();
        $currentMenu = $navigation[$menuType];
        $newMenu = [];
        
        // Menüpunkte in der neuen Reihenfolge anordnen
        foreach ($order as $menuItemId) {
            foreach ($currentMenu as $item) {
                if ($item['id'] === $menuItemId) {
                    $newMenu[] = $item;
                    break;
                }
            }
        }
        
        // Sicherstellen, dass kein Menüpunkt verloren geht
        if (count($newMenu) !== count($currentMenu)) {
            return false;
        }
        
        $navigation[$menuType] = $newMenu;
        return $this->saveNavigation($navigation);
    }
    
    /**
     * Gibt das Hauptmenü als HTML zurück
     * 
     * @param array $options Optionen für die Ausgabe
     * @return string HTML des Hauptmenüs
     */
    public function renderMainMenu(array $options = []): string {
        $mainMenu = $this->getMenu('main_menu');
        
        // Deine bestehende Menüstruktur
        $html = '<nav class="marques-main-navigation">';
        $html .= '<ul class="marques-menu">';
        
        foreach ($mainMenu as $item) {
            $active = '';
            
            // Aktiven Menüpunkt erkennen
            if (isset($_SERVER['REQUEST_URI'])) {
                $currentUrl = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                $menuUrl = isset($item['url']) ? parse_url($item['url'], PHP_URL_PATH) : '';
                
                // Sicherstellen, dass beide Variablen Strings sind
                $currentUrl = (string)$currentUrl;
                $menuUrl = (string)$menuUrl;
                
                if ($currentUrl === $menuUrl || ($menuUrl !== '/' && $menuUrl !== '' && strpos($currentUrl, $menuUrl) === 0)) {
                    $active = ' class="active"';
                }
            }
            
            $target = isset($item['target']) && $item['target'] === '_blank' ? ' target="_blank"' : '';
            $html .= '<li class="marques-menu-item' . ($active ? ' active' : '') . '">';
            $html .= '<a href="' . htmlspecialchars($item['url'] ?? '#') . '"' . $target . '>' . htmlspecialchars($item['title'] ?? 'Menüpunkt') . '</a>';
            $html .= '</li>';
        }
        
        $html .= '</ul>';
        $html .= '</nav>';
        
        return $html;
    }
    
    /**
     * Gibt das Footermenü als HTML zurück
     * 
     * @param array $options Optionen für die Ausgabe
     * @return string HTML des Footermenüs
     */
    public function renderFooterMenu(array $options = []): string {
        $footerMenu = $this->getMenu('footer_menu');
        
        // Für Footer-Menü könnten wir ähnliche Klassen verwenden oder anpassen
        $html = '<nav class="marques-footer-navigation">';
        $html .= '<ul class="marques-menu">';
        
        foreach ($footerMenu as $item) {
            $target = isset($item['target']) && $item['target'] === '_blank' ? ' target="_blank"' : '';
            $html .= '<li class="marques-menu-item">';
            $html .= '<a href="' . htmlspecialchars($item['url'] ?? '#') . '"' . $target . '>' . htmlspecialchars($item['title'] ?? 'Menüpunkt') . '</a>';
            $html .= '</li>';
        }
        
        $html .= '</ul>';
        $html .= '</nav>';
        
        return $html;
    }

    /**
     * Migriert das bestehende statische Menü zu einem dynamischen Menü
     * 
     * @return bool Erfolg
     */
    public function migrateExistingMenu() {
        // Nur ausführen, wenn das Menü leer ist
        $navigation = $this->getNavigation();
        
        if (count($navigation['main_menu']) > 0) {
            return false; // Es existieren bereits Menüpunkte
        }
        
        // Standardmenüpunkte
        $defaultItems = [
            [
                'id' => uniqid('menu_'),
                'title' => 'Startseite',
                'url' => Helper::getSiteUrl(),
                'target' => '_self'
            ],
            [
                'id' => uniqid('menu_'),
                'title' => 'Blog',
                'url' => Helper::getSiteUrl('blog'),
                'target' => '_self'
            ],
            [
                'id' => uniqid('menu_'),
                'title' => 'Über uns',
                'url' => Helper::getSiteUrl('about'),
                'target' => '_self'
            ],
            [
                'id' => uniqid('menu_'),
                'title' => 'Kontakt',
                'url' => Helper::getSiteUrl('contact'),
                'target' => '_self'
            ]
        ];
        
        $navigation['main_menu'] = $defaultItems;
        
        return $this->saveNavigation($navigation);
    }
}