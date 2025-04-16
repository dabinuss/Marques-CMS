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
    protected Helper $helper;
    protected TokenParser $tokenParser;
    protected AssetManager $assetManager;

    // Konstruktor mit Injektion aller benötigten Services.
    public function __construct(
        DatabaseHandler $dbHandler,
        ThemeManager $themeManager,
        Path $appPath,
        Cache $cache,
        Helper $helper,
        TokenParser $tokenParser
    ) {
        $this->dbHandler = $dbHandler;
        $this->themeManager = $themeManager;
        // Konfiguration über den neuen DatabaseHandler laden
        $this->_config = $dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];
        $this->templatePath = $themeManager->getThemePath('templates');
        $this->appPath = $appPath;
        $this->cache = $cache;
        $this->helper = $helper;
        
        // Token-Manager initialisieren
        $this->tokenParser = $tokenParser;

        $this->assetManager = $tokenParser->getAssetManager();
        $this->assetManager->setBaseUrl($this->helper->getSiteUrl());
        
        // Standard-Variablen setzen
        $this->setStandardVariables();
    }
    
    /**
     * Setzt Standard-Variablen für Templates
     */
    protected function setStandardVariables(): void
    {
        $this->tokenParser->setVariables([
            'site_name' => $this->_config['site_name'] ?? 'Marques CMS',
            'site_description' => $this->_config['site_description'] ?? '',
            'base_url' => $this->helper->getBaseUrl(),
            'year' => date('Y'),
            'version' => defined('MARQUES_VERSION') ? MARQUES_VERSION : '1.0',
        ]);
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
     * Startet einen Block
     *
     * @param string $name Name des Blocks
     * @return void
     */
    public function startBlock(string $name): void
    {
        $this->tokenParser->startBlock($name);
    }

    /**
     * Beendet den aktuellen Block
     *
     * @return void
     */
    public function endBlock(): void
    {
        $this->tokenParser->endBlock();
    }

    /**
     * Setzt einen Block-Inhalt direkt
     *
     * @param string $name Block-Name
     * @param string $content Block-Inhalt
     * @return void
     */
    public function setBlock(string $name, string $content): void
    {
        $this->tokenParser->setBlock($name, $content);
    }

    /**
     * Gibt den Inhalt eines Blocks zurück
     *
     * @param string $name Block-Name
     * @param string $default Standard-Inhalt
     * @return string
     */
    public function getBlock(string $name, string $default = ''): string
    {
        return $this->tokenParser->getBlock($name, $default);
    }

    /**
     * Prüft ob ein Block existiert
     *
     * @param string $name Block-Name
     * @return bool
     */
    public function hasBlock(string $name): bool
    {
        return $this->tokenParser->hasBlock($name);
    }

    /**
     * Setzt eine Template-Variable
     *
     * @param string $name Variablen-Name
     * @param string $value Variablen-Wert
     * @return void
     */
    public function setVariable(string $name, string $value): void
    {
        $this->tokenParser->setVariable($name, $value);
    }

    /**
     * Setzt mehrere Template-Variablen auf einmal
     *
     * @param array<string, string> $variables Variablen-Array
     * @return void
     */
    public function setVariables(array $variables): void
    {
        $this->tokenParser->setVariables($variables);
    }

    /**
     * Fügt eine CSS-Ressource hinzu
     *
     * @param string $path Pfad zur CSS-Datei
     * @param bool $isExternal Externe Ressource?
     * @return void
     */
    public function addCss(string $path, bool $isExternal = false): void
    {
        $this->tokenParser->addCss($path, $isExternal);
    }

    /**
     * Fügt eine JavaScript-Ressource hinzu
     *
     * @param string $path Pfad zur JS-Datei
     * @param bool $isExternal Externe Ressource?
     * @param bool $defer Defer-Attribut setzen?
     * @return void
     */
    public function addJs(string $path, bool $isExternal = false, bool $defer = true): void
    {
        $this->tokenParser->addJs($path, $isExternal, $defer);
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

        // Daten für ViewRendering vorbereiten
        $viewData = [
            'helper' => $this->helper,
            'themeManager' => $this->themeManager,
            'templateFile' => $templateFile,
            'system_settings' => $system_settings,
            'templateName' => $templateName,
            'config' => $this->_config,
            'template' => $this, // Referenz auf die Template-Instanz
            'tokenParser' => $this->tokenParser,
        ];
        
        // Arrays zusammenführen, $data überschreibt Standardwerte
        $viewData = array_merge($viewData, $data);
        
        // Variablen aus $data automatisch als Template-Variablen verfügbar machen
        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $this->tokenParser->setVariable($key, (string)$value);
            }
        }

        // Erstelle TemplateVars-Instanz für interne Verwendung
        $tpl = new TemplateVars($this->cache, $viewData);

        // Cache-Key für dieses spezifische Template
        $cacheKey = 'template_' . $tpl->templateName . '_' . ($tpl->id ?? md5($tpl->content ?? 'default'));
        
        // Versuche Cache zu verwenden
        $cachedOutput = $this->cache->get($cacheKey);
        
        if ($cachedOutput !== null) {
            // Wenn gecachter Output vorhanden, direkt ausgeben
            echo $cachedOutput;
        } else {
            // Rendere zuerst das Content-Template
            ob_start();
            include $templateFile;
            $content = ob_get_clean();
            
            // Setze den Content-Block, falls er nicht explizit gesetzt wurde
            if (!$this->tokenParser->hasBlock('content')) {
                $this->tokenParser->setBlock('content', $content);
            }
            
            // Lade das Basis-Template
            $baseTemplate = file_get_contents($baseTemplateFile);
            
            // Verarbeite alle Tokens im Basis-Template
            $output = $this->tokenParser->parseTokens($baseTemplate);
            
            // Speichere im Cache und gebe aus
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
        
        // Template und TokenParser dem Partial zur Verfügung stellen
        $data['template'] = $this;
        $data['tokenParser'] = $this->tokenParser;

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