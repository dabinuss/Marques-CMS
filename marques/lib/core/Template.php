<?php
declare(strict_types=1);

namespace Marques\Core;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Filesystem\FileManager;
use Marques\Util\SafetyXSS;
use Marques\Util\Helper;
use Marques\Util\TemplateVars;
use Marques\Service\NavigationManager;
use Marques\Service\ThemeManager;
use Marques\Filesystem\PathRegistry;

/**
 * Core template engine: renders front‑end views, manages assets, caching and helper utilities.
 */
class Template
{
    private const CACHE_TTL = 3600;           // seconds
    private const CACHE_TAG = 'templates';    // cache tag for invalidation

    /** @var array<string,mixed> */
    protected array $_config = [];

    protected ?NavigationManager $_navManager = null;

    protected string        $templatePath;
    protected PathRegistry  $appPath;
    protected Cache         $cache;
    protected DatabaseHandler $dbHandler;
    protected ThemeManager  $themeManager;
    protected Helper        $helper;
    protected TokenParser   $tokenParser;
    protected AssetManager  $assetManager;
    protected FileManager   $fileManager;

    /** @var array<string,bool> */
    private static array $fileStatCache = [];

    /** @var array<string,array<string,mixed>> */
    private static array $systemSettingsCache = [];

    /**
     * @param DatabaseHandler $dbHandler   Database abstraction
     * @param ThemeManager    $themeManager Active theme handler
     * @param PathRegistry    $appPath      Application base path
     * @param Cache           $cache        Cache implementation
     * @param Helper          $helper       Generic utility helper
     * @param TokenParser     $tokenParser  Template token parser
     * @param FileManager     $fileManager  Filesystem abstraction
     */
    public function __construct(
        DatabaseHandler $dbHandler,
        ThemeManager $themeManager,
        PathRegistry $appPath,
        Cache $cache,
        Helper $helper,
        TokenParser $tokenParser,
        FileManager $fileManager
    ) {
        $this->dbHandler     = $dbHandler;
        $this->themeManager  = $themeManager;
        $this->templatePath  = $themeManager->getThemePath('templates');
        $this->appPath       = $appPath;
        $this->cache         = $cache;
        $this->helper        = $helper;
        $this->tokenParser   = $tokenParser;
        $this->assetManager  = $tokenParser->getAssetManager();
        $this->fileManager   = $fileManager;

        $this->_config = $this->dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];

        $this->assetManager->setBaseUrl($this->helper->getSiteUrl());
        $this->fileManager->addDirectory('frontend_templates', $this->templatePath);
        $this->fileManager->useDirectory('frontend_templates');

        $this->setStandardVariables();
    }

    /**
     * Adds common template variables available on every page.
     */
    protected function setStandardVariables(): void
    {
        $this->tokenParser->setVariables([
            'site_name'        => $this->_config['site_name']        ?? 'Marques CMS',
            'site_description' => $this->_config['site_description'] ?? '',
            'base_url'         => $this->helper->getBaseUrl(),
            'year'             => date('Y'),
            'version'          => defined('MARQUES_VERSION') ? MARQUES_VERSION : '1.0',
        ]);
    }

    /**
     * Registers scalar values from the provided array as template variables.
     *
     * @param array<string,mixed> $vars
     */
    protected function registerTemplateVars(array $vars): void
    {
        foreach ($vars as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $this->tokenParser->setVariable($key, (string) $value);
            }
        }
    }

    /**
     * Parses multiple template files to register their asset references (one‑time per request).
     */
    protected function collectAssetsForTemplates(string ...$files): void
    {
        static $seen = [];
        foreach ($files as $file) {
            if ($file && !isset($seen[$file]) && $this->fileExistsCached($file)) {
                $content = $this->fileManager->readFile($file);
                if ($content !== null) {
                    $this->tokenParser->collectAssets($content);
                    $seen[$file] = true;
                }
            }
        }
    }

    /**
     * Resolves a URL relative to the active theme directory.
     */
    public function themeUrl(string $path = ''): string
    {
        return $this->themeManager->getThemeAssetsUrl($path);
    }

    /** @see TokenParser::startBlock() */
    public function startBlock(string $name): void
    {
        $this->tokenParser->startBlock($name);
    }

    /** @see TokenParser::endBlock() */
    public function endBlock(): void
    {
        $this->tokenParser->endBlock();
    }

    /** @see TokenParser::setBlock() */
    public function setBlock(string $name, string $content): void
    {
        $this->tokenParser->setBlock($name, $content);
    }

    /** @see TokenParser::getBlock() */
    public function getBlock(string $name, string $default = ''): string
    {
        return $this->tokenParser->getBlock($name, $default);
    }

    /** @see TokenParser::hasBlock() */
    public function hasBlock(string $name): bool
    {
        return $this->tokenParser->hasBlock($name);
    }

    /** @see TokenParser::setVariable() */
    public function setVariable(string $name, string $value): void
    {
        $this->tokenParser->setVariable($name, $value);
    }

    /** @see TokenParser::setVariables() */
    public function setVariables(array $variables): void
    {
        $this->tokenParser->setVariables($variables);
    }

    /** @see TokenParser::addCss() */
    public function addCss(string $path, bool $isExternal = false): void
    {
        $this->tokenParser->addCss($path, $isExternal);
    }

    /** @see TokenParser::addJs() */
    public function addJs(string $path, bool $isExternal = false, bool $defer = true): void
    {
        $this->tokenParser->addJs($path, $isExternal, $defer);
    }

    /**
     * Renders a full page template, applying the base layout and caching when possible.
     *
     * @param array<string,mixed> $data
     */
    public function render(array $data): void
    {
        $templateName = $data['template'] ?? 'page';

        if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $templateName)) {
            throw new \InvalidArgumentException('Invalid template name: ' . SafetyXSS::escapeOutput($templateName, 'html'));
        }

        $templateFile     = "{$this->templatePath}/{$templateName}.phtml";
        $baseTemplateFile = "{$this->templatePath}/base.phtml";

        if (!$this->fileExistsCached($templateFile)) {
            throw new \RuntimeException("Template not found: {$templateName} ({$this->templatePath})");
        }
        if (!$this->fileExistsCached($baseTemplateFile)) {
            throw new \RuntimeException("Base template not found: {$baseTemplateFile} ({$this->templatePath})");
        }

        $viewData = array_merge(
            [
                'helper'       => $this->helper,
                'themeManager' => $this->themeManager,
                'templateFile' => $templateFile,
                'templateName' => $templateName,
                'config'       => $this->_config,
                'template'     => $this,
                'tokenParser'  => $this->tokenParser,
            ],
            $data
        );

        $this->registerTemplateVars($viewData);

        $tpl      = new TemplateVars($this->cache, $viewData);
        $cacheKey = 'template_' . $tpl->templateName . '_' . ($tpl->id ?? md5($tpl->content ?? 'default'));
        $cached   = $this->cache->get($cacheKey);

        if ($cached !== null) {
            echo $cached;
            return;
        }

        ob_start();
        include $this->fileManager->getFullPath($templateFile);
        $content = ob_get_clean();

        if (!$this->tokenParser->hasBlock('content')) {
            $this->tokenParser->setBlock('content', $content);
        }

        $baseMarkup = $this->fileManager->readFile($baseTemplateFile);
        if ($baseMarkup === null) {
            throw new \RuntimeException("Base template could not be read: {$baseTemplateFile} ({$this->templatePath})");
        }

        $output = $this->tokenParser->parseTokens($baseMarkup);

        $this->cache->set($cacheKey, $output, self::CACHE_TTL, [self::CACHE_TAG]);
        echo $output;
    }

    /**
     * Includes a partial template inside the current rendering context.
     *
     * @param array<string,mixed> $data
     */
    public function includePartial(string $partialName, array $data = []): void
    {
        if (!isset($data['system_settings'])) {
            $data['system_settings'] = $this->prepareSystemSettings();
        }
        $data['template']    = $this;
        $data['tokenParser'] = $this->tokenParser;

        $partialFile = "{$this->templatePath}/partials/{$partialName}.phtml";
        if (!$this->fileExistsCached($partialFile)) {
            throw new \RuntimeException("Partial template not found: {$partialFile} ({$this->templatePath})");
        }

        include $this->fileManager->getFullPath($partialFile);
    }

    /**
     * Provides an instance of the NavigationManager using lazy initialization.
     */
    public function getNavigationManager(): NavigationManager
    {
        return $this->_navManager ??= new NavigationManager($this->dbHandler);
    }

    /**
     * Validates if a template file exists.
     */
    public function exists(string $templateName): bool
    {
        $templateFile = "{$this->templatePath}/{$templateName}.phtml";
        if (!$this->fileExistsCached($templateFile)) {
            throw new \RuntimeException("Template does not exist: {$templateFile} ({$this->templatePath})");
        }

        return true;
    }

    /**
     * Renders an SVG icon with optional sizing and custom class injection.
     */
    public function renderIcon(string $iconName, string $customClass = 'stat-icon', ?string $size = null): string
    {
        $iconPath = $this->appPath->combine('admin', 'assets/icons') . "/{$iconName}.svg";
        if (!$this->fileExistsCached($iconPath)) {
            return '<!-- Icon not found: ' . SafetyXSS::escapeOutput($iconName, 'html') . ' -->';
        }

        $svg = $this->fileManager->readFile($iconPath);
        if ($svg === null) {
            return '<!-- Icon file unreadable: ' . SafetyXSS::escapeOutput($iconName, 'html') . ' -->';
        }

        $svg = preg_replace('/\s+(width|height)="[^"]*"/i', '', $svg);
        $classPos = stripos($svg, 'class="');
        if ($classPos !== false) {
            $svg = substr_replace($svg, $customClass . ' ', $classPos + 7, 0);
        } else {
            $svg = preg_replace('/<svg\b/i', '<svg class="' . $customClass . '"', $svg, 1);
        }

        if ($size !== null) {
            $styleAttr = 'style="width:' . $size . '; height:' . $size . ';"';
            if (strpos($svg, 'style="') !== false) {
                $svg = preg_replace('/<svg\s[^>]*style="/i', '$0 width:' . $size . '; height:' . $size . ';', $svg, 1);
            } else {
                $svg = preg_replace('/<svg\b/i', '<svg ' . $styleAttr, $svg, 1);
            }
        }

        return $svg;
    }

    /**
     * Returns prepared system settings for the current request, cached per context.
     *
     * @return array<string,mixed>
     */
    private function prepareSystemSettings(): array
    {
        $cacheKey = defined('IS_ADMIN') ? 'admin' : 'frontend';
        if (isset(self::$systemSettingsCache[$cacheKey])) {
            return self::$systemSettingsCache[$cacheKey];
        }

        $settings = $this->_config;

        if (defined('IS_ADMIN')) {
            if (!str_contains($settings['base_url'], '/admin')) {
                $settings['base_url'] = rtrim($settings['base_url'], '/') . '/admin';
            }
        } else {
            if (str_ends_with($settings['base_url'], '/admin')) {
                $settings['base_url'] = preg_replace('|/admin$|', '', $settings['base_url']);
            }
        }

        return self::$systemSettingsCache[$cacheKey] = $settings;
    }

    /**
     * Checks a file path with an in‑memory stat cache.
     */
    private function fileExistsCached(string $path): bool
    {
        return self::$fileStatCache[$path] ??= $this->fileManager->exists($path);
    }
}
