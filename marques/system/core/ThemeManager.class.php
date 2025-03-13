<?php
declare(strict_types=1);

namespace Marques\Core;

class ThemeManager {
    private $themes = [];
    private $currentTheme = 'default';
    private $themesPath;
    private $settingsManager;
    private $configManager;
    
    public function __construct() {
        $this->themesPath = MARQUES_ROOT_DIR . '/themes';
        $this->configManager = AppConfig::getInstance();
        $this->loadThemes();
        $this->settingsManager = new SettingsManager();
        $this->currentTheme = $this->settingsManager->getSetting('active_theme', 'default');
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
    
    public function getThemes(): array {
        return $this->themes;
    }
    
    public function getActiveTheme(): string {
        return $this->settingsManager->getSetting('active_theme', 'default');
    }
    
    public function setActiveTheme(string $themeName): bool {
        if (!array_key_exists($themeName, $this->themes)) {
            error_log("Theme '$themeName' nicht gefunden!");
            return false;
        }
        
        // System-Konfiguration laden
        $systemConfig = $this->configManager->load('system');
        if (!$systemConfig) {
            error_log("ThemeManager: Konnte System-Konfiguration nicht laden");
            return false;
        }
        
        // Theme aktualisieren
        $systemConfig['active_theme'] = $themeName;
        
        // Konfiguration speichern
        $result = $this->configManager->save('system', $systemConfig);
        
        if ($result) {
            $this->currentTheme = $themeName;
            // Auch den SettingsManager aktualisieren
            $this->settingsManager->setSetting('active_theme', $themeName);
            error_log("Theme erfolgreich auf '$themeName' geändert");
        } else {
            error_log("Fehler beim Ändern des Themes auf '$themeName'");
        }
        
        return $result;
    }
    
    public function getThemePath(string $file = ''): string {
        $basePath = $this->themesPath . '/' . $this->currentTheme;
        return $file ? $basePath . '/' . $file : $basePath;
    }
    
    public function getThemeAssetsUrl(string $file = ''): string {
        // Konfiguration laden
        $systemConfig = $this->configManager->load('system') ?: [];
        
        // Basispfad aus der Konfiguration holen
        $baseUrl = $systemConfig['base_url'] ?? '';
        
        // Fallback-Implementierung, falls base_url nicht verfügbar ist
        if (empty($baseUrl)) {
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                    '://' . $_SERVER['HTTP_HOST'] .
                    dirname($_SERVER['SCRIPT_NAME']);
            if (strpos($baseUrl, '/admin') !== false) {
                $baseUrl = preg_replace('|/admin$|', '', $baseUrl);
            }
        }
        
        $assetPath = 'themes/' . $this->currentTheme . '/assets';
        $url = rtrim($baseUrl, '/') . '/' . $assetPath;
        if ($file) {
            $url .= '/' . ltrim($file, '/');
        }
        
        if (($systemConfig['debug'] ?? false) === true) {
            error_log("Theme Asset URL: {$url}");
        }
        
        return $url;
    }
}