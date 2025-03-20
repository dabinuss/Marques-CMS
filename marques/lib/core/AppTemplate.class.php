<?php
declare(strict_types=1);

namespace Marques\Core;

use Marques\Core\SafetyXSS;

class AppTemplate {
    /**
     * @var array Systemkonfiguration
     */
    protected array $_config;
    /**
     * @var NavigationManager|null NavigationManager-Instanz (wird bei Bedarf erstellt)
     */
    protected ?NavigationManager $_navManager = null;
    /**
     * @var string Pfad zum Template-Verzeichnis
     */
    protected string $templatePath;

    protected ?AppPath $appPath = null;

    public function __construct() {
        // Systemkonfiguration laden
        $configManager = AppConfig::getInstance();
        $this->_config = $configManager->load('system') ?: [];

        // Standard-Template-Pfad aus dem ThemeManager
        $themeManager = new ThemeManager();
        $this->templatePath = $themeManager->getThemePath('templates');

        $this->appPath = AppPath::getInstance();
    }

    /**
     * Liefert die URL zu Theme-Assets.
     *
     * @param string $path Optionaler Unterordner
     * @return string
     */
    public function themeUrl(string $path = ''): string {
        static $themeManager = null;
        if ($themeManager === null) {
            $themeManager = new ThemeManager();
        }
        return $themeManager->getThemeAssetsUrl($path);
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

        // Suche nach dem Template im definierten Pfad mit der Endung .phtml
        $templateFile = $this->templatePath . '/' . $templateName . '.phtml';
        if (!file_exists($templateFile)) {
            $templateFile = MARQUES_TEMPLATE_DIR . '/' . $templateName . '.phtml';
            if (!file_exists($templateFile)) {
                throw new \Exception("Template nicht gefunden: " . $templateName . " (" . $this->templatePath . ")");
            }
        }

        // Basis-Template (Layout) laden
        $baseTemplateFile = $this->templatePath . '/base.phtml';
        if (!file_exists($baseTemplateFile)) {
            $baseTemplateFile = MARQUES_TEMPLATE_DIR . '/base.phtml';
            if (!file_exists($baseTemplateFile)) {
                throw new \Exception("Basis-Template nicht gefunden: " . $baseTemplateFile . " (" . $this->templatePath . ")");
            }
        }

        // Systemeinstellungen laden
        $settingsManager = new AppSettings();
        $system_settings = $settingsManager->getAllSettings();

        // Basis-URL anpassen, falls IS_ADMIN definiert ist
        if (defined('IS_ADMIN')) {
            if (strpos($system_settings['base_url'], '/admin') === false) {
                $system_settings['base_url'] = rtrim($system_settings['base_url'], '/') . '/admin';
            }
        } else {
            if (strpos($system_settings['base_url'], '/admin') !== false) {
                $system_settings['base_url'] = preg_replace('|/admin$|', '', $system_settings['base_url']);
            }
        }

        // Zusätzliche Daten für das Template bereitstellen
        $themeManager = new ThemeManager();
        $data['themeManager']   = $themeManager;
        $data['templateFile']   = $templateFile;
        $data['system_settings'] = $system_settings;
        $data['templateName']   = $templateName;
        $data['config']         = $this->_config;

        // Erzeugen von Template-Variablen
        $tpl = new TemplateVars($data);

        // Cache-Schlüssel generieren
        $cacheKey = 'template_' . $tpl->templateName . '_' . ($tpl->id ?? md5($tpl->content));
        $cacheManager = AppCache::getInstance();
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
     * Bindet ein Partial-Template ein.
     *
     * @param string $partialName Name des Partial-Templates
     * @param array  $data          Daten, die an das Partial übergeben werden
     */
    public function includePartial(string $partialName, array $data = []): void {
        $config = $this->_config;
        if (!isset($data['system_settings'])) {
            $settings_manager = new AppSettings();
            $system_settings = $settings_manager->getAllSettings();

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

        extract($data);

        $partialFile = $this->templatePath . '/partials/' . $partialName . '.phtml';
        if (!file_exists($partialFile)) {
            $partialFile = MARQUES_TEMPLATE_DIR . '/partials/' . $partialName . '.phtml';
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
     * Gibt den NavigationManager zurück oder erstellt ihn, falls noch nicht vorhanden.
     *
     * @return NavigationManager
     */
    public function getNavigationManager() {
        if ($this->_navManager === null) {
            $this->_navManager = new NavigationManager();
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
        if (file_exists($templateFile)) {
            return true;
        }
        $templateFile = MARQUES_TEMPLATE_DIR . '/' . $templateName . '.phtml';
        return file_exists($templateFile);
    }

    /* 
     * Funktion zum Rendern des SVG-Icons mit Custom Class
     * 
     * @param string $iconName
     * @param string $customClass
     * @return string
     */
    public function renderIcon(string $iconName, string $customClass = 'stat-icon', ?string $size = null): string {
        $iconPath = $this->appPath->combine('admin', 'assets/icons') . '/' . $iconName . '.svg';
        
        if (file_exists($iconPath)) {
            $svg = file_get_contents($iconPath);
            
            // Entferne die Attribute width, height und fill (alle immer)
            $svg = preg_replace('/\s+(width|height)="[^"]*"/i', '', $svg);
            
            // Bestehende Klassen erhalten und die custom class zusätzlich anhängen:
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
            
            // Wenn ein $size-Wert angegeben ist, füge ein style-Attribut ein,
            // das width und height (gleichmäßig) festlegt.
            if ($size !== null) {
                // Falls bereits ein style-Attribut existiert, hänge die Größe einfach an:
                if (preg_match('/<svg\s[^>]*style="/i', $svg)) {
                    $svg = preg_replace(
                        '/(<svg\s[^>]*style=")([^"]*)(")/i',
                        '$1$2 width:' . $size . '; height:' . $size . ';$3',
                        $svg,
                        1
                    );
                } else {
                    // Andernfalls füge ein neues style-Attribut ein
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
