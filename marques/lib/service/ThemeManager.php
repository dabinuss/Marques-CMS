<?php
declare(strict_types=1);

namespace Marques\Service;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Data\FileManager;
use Marques\Filesystem\PathRegistry;
use Marques\Filesystem\PathResolver;
use Marques\Filesystem\Filesystem;

class ThemeManager {
    private array $themes = [];
    private string $currentTheme = 'default';
    private string $themesPath;
    private DatabaseHandler $dbHandler;
    private PathRegistry $paths;
    private FileManager $fileManager;

    public function __construct(DatabaseHandler $dbHandler, PathRegistry $paths, FileManager $fileManager) {

        $this->dbHandler = $dbHandler;
        $settings = $this->dbHandler->table('settings')->where('id', '=', 1)->first();
        $this->currentTheme = $settings['active_theme'] ?? 'default';
        $this->paths      = $paths;
        $this->themesPath = $paths->getPath('themes');
        $this->fileManager = new FileManager(new \Marques\Core\Cache(), $this->themesPath);

        $this->loadThemes();
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
        return $this->currentTheme;
    }
    
    public function setActiveTheme(string $themeName): bool {
        if (!array_key_exists($themeName, $this->themes)) {
            error_log("Theme '$themeName' nicht gefunden!");
            return false;
        }
        $result = $this->dbHandler->table('settings')
                                  ->where('id', '=', 1)
                                  ->data(['active_theme' => $themeName])
                                  ->update();
        if ($result) {
            $this->currentTheme = $themeName;
            error_log("Theme erfolgreich auf '$themeName' geändert");
        } else {
            error_log("Fehler beim Ändern des Themes auf '$themeName'");
        }
        return $result;
    }
    
    public function getThemePath(string $file = ''): string {
        $basePath = $this->paths->combine('themes', $this->currentTheme);
        return $file ? PathResolver::resolve($basePath, $file) : $basePath;
    }
    
    public function getThemeAssetsUrl(string $file = ''): string {
        $systemConfig = $this->dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];
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
