<?php
namespace Marques\Core;

class ThemeManager {
    private $themes = [];
    private $currentTheme = 'default';
    private $themesPath;
    
    public function __construct() {
        $this->themesPath = MARQUES_ROOT_DIR . '/themes';
        $this->loadThemes();
        $this->currentTheme = $this->getActiveTheme();
    }
    
    private function loadThemes() {
        if (!is_dir($this->themesPath)) {
            mkdir($this->themesPath, 0755, true);
        }
        
        $themeDirs = glob($this->themesPath . '/*', GLOB_ONLYDIR);
        
        foreach ($themeDirs as $themeDir) {
            $themeJsonPath = $themeDir . '/theme.json';
            if (file_exists($themeJsonPath)) {
                $themeData = json_decode(file_get_contents($themeJsonPath), true);
                if ($themeData) {
                    $themeName = basename($themeDir);
                    $this->themes[$themeName] = $themeData;
                }
            }
        }
    }
    
    public function getThemes() {
        return $this->themes;
    }
    
    public function getActiveTheme() {
        $settings = new SettingsManager();
        return $settings->getSetting('active_theme', 'default');
    }
    
    public function setActiveTheme($themeName) {
        if (!array_key_exists($themeName, $this->themes)) {
            return false;
        }
        
        $settings = new SettingsManager();
        return $settings->setSetting('active_theme', $themeName);
    }
    
    public function getThemePath($file = '') {
        $basePath = $this->themesPath . '/' . $this->currentTheme;
        return $file ? $basePath . '/' . $file : $basePath;
    }
    
    public function getThemeAssetsUrl($file = '') {
        // Konfiguration laden
        $config = require MARQUES_CONFIG_DIR . '/system.config.php';
        
        // Basispfad aus der Konfiguration holen
        $baseUrl = $config['base_url'] ?? '';
        
        // Fallback-Implementierung für den Fall, dass base_url nicht verfügbar ist
        if (empty($baseUrl)) {
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                    '://' . $_SERVER['HTTP_HOST'] .
                    dirname($_SERVER['SCRIPT_NAME']);
            // Admin-Pfad aus der URL entfernen, falls vorhanden
            if (strpos($baseUrl, '/admin') !== false) {
                $baseUrl = preg_replace('|/admin$|', '', $baseUrl);
            }
        }
        
        // Theme-Asset-Struktur verwenden
        $assetPath = 'themes/' . $this->currentTheme . '/assets';
        
        // Pfad bereinigen (doppelte Slashes entfernen)
        $url = rtrim($baseUrl, '/') . '/' . $assetPath;
        if ($file) {
            $url .= '/' . ltrim($file, '/');
        }
        
        // Debug-Ausgabe hinzufügen
        if (($config['debug'] ?? false) === true) {
            error_log("Theme Asset URL: {$url}");
        }
        
        return $url;
    }
}