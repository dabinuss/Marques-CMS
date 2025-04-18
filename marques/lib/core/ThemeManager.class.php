<?php
declare(strict_types=1);

namespace Marques\Core;

class ThemeManager {
    private array $themes = [];
    private string $currentTheme = 'default';
    private string $themesPath;
    private DatabaseHandler $dbHandler;

    public function __construct(DatabaseHandler $dbHandler) {
        $this->themesPath = MARQUES_ROOT_DIR . '/themes';
        $this->dbHandler = $dbHandler;
        $this->loadThemes();
        $this->currentTheme = $this->dbHandler->getSetting('active_theme', 'default');
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
        return $this->dbHandler->getSetting('active_theme', 'default');
    }
    
    public function setActiveTheme(string $themeName): bool {
        if (!array_key_exists($themeName, $this->themes)) {
            error_log("Theme '$themeName' nicht gefunden!");
            return false;
        }
        $result = $this->dbHandler->setSetting('active_theme', $themeName);
        if ($result) {
            $this->currentTheme = $themeName;
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
        $systemConfig = $this->dbHandler->getAllSettings() ?: [];
        $baseUrl = $systemConfig['base_url'] ?? '';
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
