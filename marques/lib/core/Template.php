<?php
declare(strict_types=1);

namespace Marques\Core;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Util\SafetyXSS;
use Marques\Util\Helper;
use Marques\Util\TemplateVars;
use Marques\Service\NavigationManager;
use Marques\Service\ThemeManager;

/**
 * Core Template class for view rendering and asset management.
 */
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
        $this->_config = $dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];
        $this->templatePath = $themeManager->getThemePath('templates');
        $this->appPath = $appPath;
        $this->cache = $cache;
        $this->helper = $helper;
        $this->tokenParser = $tokenParser;
        $this->assetManager = $tokenParser->getAssetManager();
        $this->assetManager->setBaseUrl($this->helper->getSiteUrl());
        $this->setStandardVariables();
    }
    
    /**
     * Sets standard template variables.
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
     * Registers all string/numeric template variables in TokenParser.
     */
    protected function registerTemplateVars(array $vars): void
    {
        foreach ($vars as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $this->tokenParser->setVariable($key, (string)$value);
            }
        }
    }

    /**
     * Collects assets from template files.
     */
    protected function collectAssetsForTemplates(string ...$files): void
    {
        foreach ($files as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                $this->tokenParser->collectAssets($content);
            }
        }
    }

    public function themeUrl(string $path = ''): string {
        return $this->themeManager->getThemeAssetsUrl($path);
    }

    public function startBlock(string $name): void
    {
        $this->tokenParser->startBlock($name);
    }

    public function endBlock(): void
    {
        $this->tokenParser->endBlock();
    }

    public function setBlock(string $name, string $content): void
    {
        $this->tokenParser->setBlock($name, $content);
    }

    public function getBlock(string $name, string $default = ''): string
    {
        return $this->tokenParser->getBlock($name, $default);
    }

    public function hasBlock(string $name): bool
    {
        return $this->tokenParser->hasBlock($name);
    }

    public function setVariable(string $name, string $value): void
    {
        $this->tokenParser->setVariable($name, $value);
    }

    public function setVariables(array $variables): void
    {
        $this->tokenParser->setVariables($variables);
    }

    public function addCss(string $path, bool $isExternal = false): void
    {
        $this->tokenParser->addCss($path, $isExternal);
    }

    public function addJs(string $path, bool $isExternal = false, bool $defer = true): void
    {
        $this->tokenParser->addJs($path, $isExternal, $defer);
    }

    /**
     * Renders a template with given data.
     */
    public function render(array $data): void {
        $templateName = $data['template'] ?? 'page';

        if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $templateName)) {
            throw new \Exception("Invalid template name: " . SafetyXSS::escapeOutput($templateName, 'html'));
        }

        $templateFile = "{$this->templatePath}/{$templateName}.phtml";
        if (!file_exists($templateFile)) {
            throw new \Exception("Template not found: {$templateName} ({$this->templatePath})");
        }

        $baseTemplateFile = "{$this->templatePath}/base.phtml";
        if (!file_exists($baseTemplateFile)) {
            throw new \Exception("Base template not found: {$baseTemplateFile} ({$this->templatePath})");
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

        $viewData = [
            'helper' => $this->helper,
            'themeManager' => $this->themeManager,
            'templateFile' => $templateFile,
            'system_settings' => $system_settings,
            'templateName' => $templateName,
            'config' => $this->_config,
            'template' => $this,
            'tokenParser' => $this->tokenParser,
        ];
        $viewData = array_merge($viewData, $data);

        $this->registerTemplateVars($data);

        $tpl = new TemplateVars($this->cache, $viewData);
        $cacheKey = 'template_' . $tpl->templateName . '_' . ($tpl->id ?? md5($tpl->content ?? 'default'));
        $cachedOutput = $this->cache->get($cacheKey);

        if ($cachedOutput !== null) {
            echo $cachedOutput;
            return;
        }

        ob_start();
        include $templateFile;
        $content = ob_get_clean();

        if (!$this->tokenParser->hasBlock('content')) {
            $this->tokenParser->setBlock('content', $content);
        }

        $baseTemplate = file_get_contents($baseTemplateFile);
        $output = $this->tokenParser->parseTokens($baseTemplate);

        $this->cache->set($cacheKey, $output, 3600, ['templates']);
        echo $output;
    }

    /**
     * Includes a partial template.
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
        $data['template'] = $this;
        $data['tokenParser'] = $this->tokenParser;

        $partialFile = "{$this->templatePath}/partials/{$partialName}.phtml";
        if (!file_exists($partialFile)) {
            throw new \Exception("Partial template not found: {$partialFile} ({$this->templatePath})");
        }
        include $partialFile;
    }

    /**
     * Returns the NavigationManager instance.
     */
    public function getNavigationManager(): NavigationManager {
        if ($this->_navManager === null) {
            $this->_navManager = new NavigationManager($this->dbHandler);
        }
        return $this->_navManager;
    }

    /**
     * Checks if a template exists.
     */
    public function exists(string $templateName): bool {
        $templateFile = "{$this->templatePath}/{$templateName}.phtml";
        if (!file_exists($templateFile)) {
            throw new \Exception("Template does not exist: {$templateFile} ({$this->templatePath})");
        }
        return true;
    }

    /**
     * Renders an SVG icon.
     */
    public function renderIcon(string $iconName, string $customClass = 'stat-icon', ?string $size = null): string {
        $iconPath = $this->appPath->combine('admin', 'assets/icons') . "/{$iconName}.svg";
        if (!file_exists($iconPath)) {
            return '<!-- Icon not found: ' . SafetyXSS::escapeOutput($iconName, 'html') . ' -->';
        }
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
}