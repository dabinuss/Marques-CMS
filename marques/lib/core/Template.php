<?php
declare(strict_types=1);

namespace Marques\Core;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Util\SafetyXSS;
use Marques\Util\Helper;
use Marques\Util\TemplateVars;
use Marques\Service\NavigationManager;
use Marques\Service\ThemeManager;

class Template {
    protected array $_config;
    protected ?NavigationManager $_navManager = null;
    protected string $templatePath;
    protected Path $appPath;
    protected Cache $cache;
    protected DatabaseHandler $dbHandler;
    protected ThemeManager $themeManager;
    protected Helper $helper; // Neue Helper-Eigenschaft

    // Konstruktor mit Injektion aller benötigten Services.
    public function __construct(
        DatabaseHandler $dbHandler,
        ThemeManager $themeManager,
        Path $appPath,
        Cache $cache,
        Helper $helper // Helper als zusätzlicher Parameter
    ) {
        $this->dbHandler    = $dbHandler;
        $this->themeManager = $themeManager;
        // Konfiguration über den neuen DatabaseHandler laden
        $this->_config      = $dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];
        $this->templatePath = $themeManager->getThemePath('templates');
        $this->appPath      = $appPath;
        $this->cache        = $cache;
        $this->helper       = $helper; // Helper zuweisen
    }

    /**
     * Liefert die URL zu Theme-Assets.
     *
     * @param string $path Optionaler Unterordner
     * @return string
     */
    public function themeUrl(string $path = ''): string {
        return $this->themeManager->getThemeAssetsUrl($path);
    }

    /**
     * Rendert ein Template mit den übergebenen Daten.
     *
     * Erwartet, dass im $data-Array unter 'template' der Name des Templates (ohne Endung) übergeben wird.
     *
     * @param array $data
     * @throws \Exception
     */
    public function render(array $data): void {
        $templateName = $data['template'] ?? 'page';

        if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $templateName)) {
            throw new \Exception("Ungültiger Template-Name: " . SafetyXSS::escapeOutput($templateName, 'html'));
        }

        $templateFile = $this->templatePath . '/' . $templateName . '.phtml';
        if (!file_exists($templateFile)) {
            throw new \Exception("Template nicht gefunden: " . $templateName . " (" . $this->templatePath . ")");
        }

        $baseTemplateFile = $this->templatePath . '/base.phtml';
        if (!file_exists($baseTemplateFile)) {
            throw new \Exception("Basis-Template nicht gefunden: " . $baseTemplateFile . " (" . $this->templatePath . ")");
        }

        $system_settings = $this->_config;

        if (defined('IS_ADMIN')) {
            if (strpos($system_settings['base_url'], '/admin') === false) {
                $system_settings['base_url'] = rtrim($system_settings['base_url'], '/') . '/admin';
            }
        } else {
            if (strpos($system_settings['base_url'], '/admin') !== false) {
                $system_settings['base_url'] = preg_replace('|/admin$|', '', $system_settings['base_url']);
            }
        }

        // Füge den Helper in die Template-Daten ein
        $data['helper']          = $this->helper;
        $data['themeManager']    = $this->themeManager;
        $data['templateFile']    = $templateFile;
        $data['system_settings'] = $system_settings;
        $data['templateName']    = $templateName;
        $data['config']          = $this->_config;

        // Erstelle TemplateVars-Instanz mit Cache und den Daten
        $tpl = new TemplateVars($this->cache, $data);

        $cacheKey = 'template_' . $tpl->templateName . '_' . ($tpl->id ?? md5($tpl->content));
        $cachedOutput = $this->cache->get($cacheKey);

        if ($cachedOutput !== null) {
            echo $cachedOutput;
        } else {
            ob_start();
            include $baseTemplateFile;
            $output = ob_get_clean();
            $this->cache->set($cacheKey, $output, 3600, ['templates']);
            echo $output;
        }
    }

    /**
     * Bindet ein Partial-Template ein.
     *
     * @param string $partialName Name des Partial-Templates
     * @param array  $data          Daten, die an das Partial übergeben werden
     */
    public function includePartial(string $partialName, array $data = []): void {
        if (!isset($data['system_settings'])) {
            $system_settings = $this->_config;
            if (defined('IS_ADMIN')) {
                if (strpos($system_settings['base_url'], '/admin') === false) {
                    $system_settings['base_url'] = rtrim($system_settings['base_url'], '/') . '/admin';
                }
            } else {
                if (strpos($system_settings['base_url'], '/admin') !== false) {
                    $system_settings['base_url'] = preg_replace('|/admin$|', '', $system_settings['base_url']);
                }
            }
            $data['system_settings'] = $system_settings;
        }

        $partialFile = $this->templatePath . '/partials/' . $partialName . '.phtml';
        if (!file_exists($partialFile)) {
            throw new \Exception("Partial-Template nicht gefunden: " . $partialFile . " (" . $this->templatePath . ")");
        } else {
            include $partialFile;
        }
    }

    /**
     * Gibt den NavigationManager zurück oder erstellt ihn, falls noch nicht vorhanden.
     *
     * @return NavigationManager
     */
    public function getNavigationManager() {
        if ($this->_navManager === null) {
            $this->_navManager = new NavigationManager($this->dbHandler);
        }
        return $this->_navManager;
    }

    /**
     * Prüft, ob ein Template existiert.
     *
     * @param string $templateName
     * @return bool
     */
    public function exists(string $templateName): bool {
        $templateFile = $this->templatePath . '/' . $templateName . '.phtml';
        if (!file_exists($templateFile)) {
            throw new \Exception("Template existiert nicht: " . $templateFile . " (" . $this->templatePath . ")");
        }
        return true;
    }

    /**
     * Rendert ein SVG-Icon mit optionaler Custom Class und Größe.
     *
     * @param string $iconName
     * @param string $customClass
     * @param string|null $size
     * @return string
     */
    public function renderIcon(string $iconName, string $customClass = 'stat-icon', ?string $size = null): string {
        $iconPath = $this->appPath->combine('admin', 'assets/icons') . '/' . $iconName . '.svg';
        
        if (file_exists($iconPath)) {
            $svg = file_get_contents($iconPath);
            $svg = preg_replace('/\s+(width|height)="[^"]*"/i', '', $svg);
            if (preg_match('/<svg\s[^>]*class="/i', $svg)) {
                $svg = preg_replace(
                    '/(<svg\s[^>]*class=")([^"]*)(")/i',
                    '$1$2 ' . $customClass . '$3',
                    $svg,
                    1
                );
            } else {
                $svg = preg_replace('/<svg\b/i', '<svg class="' . $customClass . '"', $svg, 1);
            }
            if ($size !== null) {
                if (preg_match('/<svg\s[^>]*style="/i', $svg)) {
                    $svg = preg_replace(
                        '/(<svg\s[^>]*style=")([^"]*)(")/i',
                        '$1$2 width:' . $size . '; height:' . $size . ';$3',
                        $svg,
                        1
                    );
                } else {
                    $svg = preg_replace(
                        '/<svg\b/i',
                        '<svg style="width:' . $size . '; height:' . $size . ';"',
                        $svg,
                        1
                    );
                }
            }
            return $svg;
        }
        return '<!-- Icon nicht gefunden: ' . SafetyXSS::escapeOutput($iconName, 'html') . ' -->';
    }
}
