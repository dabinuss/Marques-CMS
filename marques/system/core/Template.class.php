<?php
declare(strict_types=1);

/**
 * marques CMS - Template Klasse
 * 
 * Behandelt Template-Rendering.
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Core;

class Template {
    /**
     * @var array Systemkonfiguration
     */
    private $_config;
    private $_navManager = null;

    /**
     * @var string Pfad zum Template-Verzeichnis
     */
    private $templatePath; // Vorab deklarieren
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $configManager = \Marques\Core\AppConfig::getInstance();
        $this->_config = $configManager->load('system') ?: [];

        $themeManager = new ThemeManager();
        $this->templatePath = $themeManager->getThemePath('templates');
    }

    // Methode für Template-Assets
    public function themeUrl($path = '') {
        static $themeManager = null;
        if ($themeManager === null) {
            $themeManager = new ThemeManager();
        }
        return $themeManager->getThemeAssetsUrl($path);
    }
    
    /**
     * Rendert ein Template mit Daten
     *
     * @param array $data Daten, die an das Template übergeben werden
     * @return void
     * @throws \Exception Wenn das Template nicht gefunden wird
     */
    public function render(array $data): void {
        $templateName = $data['template'] ?? 'page';
        
        if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $templateName)) {
            throw new \Exception("Ungültiger Template-Name: " . htmlspecialchars($templateName));
        }
        
        $templateFile = $this->templatePath . '/' . $templateName . '.tpl.php';
        if (!file_exists($templateFile)) {
            $templateFile = MARQUES_TEMPLATE_DIR . '/' . $templateName . '.tpl.php';
            if (!file_exists($templateFile)) {
                throw new \Exception("Template nicht gefunden: " . $templateName);
            }
        }
        
        $baseTemplateFile = $this->templatePath . '/base.tpl.php';
        if (!file_exists($baseTemplateFile)) {
            $baseTemplateFile = MARQUES_TEMPLATE_DIR . '/base.tpl.php';
            if (!file_exists($baseTemplateFile)) {
                throw new \Exception("Basis-Template nicht gefunden");
            }
        }
        
        $settingsManager = new \Marques\Core\AppSettings();
        $system_settings = $settingsManager->getAllSettings();
        
        if (defined('IS_ADMIN')) {
            if (strpos($system_settings['base_url'], '/admin') === false) {
                $system_settings['base_url'] = rtrim($system_settings['base_url'], '/') . '/admin';
            }
        } else {
            if (strpos($system_settings['base_url'], '/admin') !== false) {
                $system_settings['base_url'] = preg_replace('|/admin$|', '', $system_settings['base_url']);
            }
        }
        
        $themeManager = new ThemeManager();
        $data['themeManager'] = $themeManager;
        $data['templateFile'] = $templateFile;
        $data['system_settings'] = $system_settings;
        $data['templateName'] = $templateName;
        $data['config'] = $this->_config;
        
        $tpl = new TemplateVars($data);
        
        // Hier wird der Cache-Schlüssel erweitert, um den Seiteninhalt zu differenzieren:
        $cacheKey = 'template_' . $tpl->templateName . '_' . ($tpl->id ?? md5($tpl->content));
        
        $cacheManager = \Marques\Core\AppCache::getInstance();
        $cachedOutput = $cacheManager->get($cacheKey);
        
        if ($cachedOutput !== null) {
            echo $cachedOutput;
        } else {
            ob_start();
            include $baseTemplateFile;
            $output = ob_get_clean();
            $cacheManager->set($cacheKey, $output, 3600, ['templates']);
            echo $output;
        }
    }
    
    
    /**
     * Bindet ein Partial-Template ein
     *
     * @param string $partialName Name des Partial-Templates
     * @param array $data Daten, die an das Partial übergeben werden
     * @return void
     */
    public function includePartial($partialName, $data = []) {
        // Konfiguration für Templates verfügbar machen
        $config = $this->_config;
        
        // Systemeinstellungen holen, falls sie nicht übergeben wurden
        if (!isset($data['system_settings'])) {
            $settings_manager = new \Marques\Core\AppSettings();
            $system_settings = $settings_manager->getAllSettings();
            
            // Base URL korrigieren (wie in render())
            if (defined('IS_ADMIN')) {
                if (strpos($system_settings['base_url'], '/admin') === false) {
                    $system_settings['base_url'] = rtrim($system_settings['base_url'], '/') . '/admin';
                }
            } else {
                if (strpos($system_settings['base_url'], '/admin') !== false) {
                    $system_settings['base_url'] = preg_replace('|/admin$|', '', $system_settings['base_url']);
                }
            }
            
            // Systemeinstellungen zu den Daten hinzufügen
            $data['system_settings'] = $system_settings;
        }
        
        // Daten zu Variablen extrahieren für einfache Verwendung im Template
        extract($data);
        
        // Das Partial-Template aus dem Theme-Verzeichnis einbinden
        $partialFile = $this->templatePath . '/partials/' . $partialName . '.tpl.php';
        if (!file_exists($partialFile)) {
            // Fallback auf Standard-Template-Verzeichnis
            $partialFile = MARQUES_TEMPLATE_DIR . '/partials/' . $partialName . '.tpl.php';
            if (file_exists($partialFile)) {
                include $partialFile;
            } else {
                echo "<!-- Partial nicht gefunden: $partialName -->";
            }
        } else {
            include $partialFile;
        }
    }

    /**
     * Gibt den NavigationManager zurück oder erstellt ihn, falls er noch nicht existiert
     *
     * @return NavigationManager
     */
    public function getNavigationManager() {
        if ($this->_navManager === null) {
            $this->_navManager = new \Marques\Core\NavigationManager();
        }
        return $this->_navManager;
    }

    /**
     * Prüft, ob ein Template existiert
     *
     * @param string $templateName Template-Name
     * @return bool True, wenn das Template existiert
     */
    public function exists($templateName) {
        // Zuerst im Theme-Verzeichnis suchen
        $templateFile = $this->templatePath . '/' . $templateName . '.tpl.php';
        if (file_exists($templateFile)) {
            return true;
        }
        
        // Fallback auf Standard-Template-Verzeichnis
        $templateFile = MARQUES_TEMPLATE_DIR . '/' . $templateName . '.tpl.php';
        return file_exists($templateFile);
    }
}