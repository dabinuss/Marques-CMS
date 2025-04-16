<?php
declare(strict_types=1);

namespace Admin\Core;

use Marques\Core\Template as AppTemplate;
use Marques\Core\TokenParser;
use Marques\Core\Path;
use Marques\Core\Cache;
use Marques\Util\Helper;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Service\ThemeManager;
use Marques\Util\SafetyXSS;
use Admin\Http\Router;

/**
 * Admin Template class for admin view rendering and layout management.
 */
class Template extends AppTemplate {

    protected string $templateDir;
    protected string $layoutFile = 'layout.phtml';
    protected Router $adminRouter;
    protected SafetyXSS $safetyXSS;

    protected static array $fileExistenceCache = [];
    protected static array $fileExistenceCacheTTL = [];
    const FILE_CACHE_TTL = 60;
    protected static array $fileContentCache = [];

    public function __construct(
        DatabaseHandler $dbHandler,
        ThemeManager $themeManager,
        Path $appPath,
        Cache $cache,
        Helper $helper,
        Router $adminRouter,
        SafetyXSS $safetyXSS,
        TokenParser $tokenParser,
        ?string $templateDir = null
    ) {
        parent::__construct($dbHandler, $themeManager, $appPath, $cache, $helper, $tokenParser);
        $this->templateDir = $templateDir ?? $appPath->getPath('admin_template');
        $this->layoutFile = rtrim($this->templateDir, '/') . '/' . basename($this->layoutFile);
        $this->adminRouter = $adminRouter;
        $this->safetyXSS = $safetyXSS;
        $this->tokenParser = $tokenParser;
        $this->tokenParser->setTemplateDir($this->templateDir);
        $this->tokenParser->setTemplateContext($this);
        $this->tokenParser->getAssetManager()->setBaseUrl($this->helper->getSiteUrl('admin'));
        $this->setAdminVariables();
    }
    
    /**
     * Sets admin-specific default variables.
     */
    protected function setAdminVariables(): void
    {
        parent::setStandardVariables();
        $this->tokenParser->setVariables([
            'admin_url' => $this->helper->getSiteUrl('admin'),
            'assets_url' => $this->helper->getSiteUrl('admin/assets'),
            'is_admin' => 'true',
            'site_name' => $this->_config['site_name'] ?? 'Marques CMS Admin',
            'admin_version' => defined('MARQUES_VERSION') ? MARQUES_VERSION : '1.0',
        ]);
    }

    /**
     * Renders an admin view template with layout.
     */
    public function render(array $vars = [], string $templateKey = 'dashboard'): void {
        $baseVars = [
            'helper' => $this->helper,
            'router' => $this->adminRouter,
            'safetyXSS' => $this->safetyXSS,
            'is_admin' => true,
            'username' => $vars['username'] ?? 'Gast',
            'user' => $vars['user'] ?? null,
            'template' => $this,
            'tokenParser' => $this->tokenParser
        ];

        $baseVars['marques_user'] = $_SESSION['marques_user'] ?? ['username' => 'Admin'];
        $baseVars['username'] = $baseVars['marques_user']['username'] ?? 'Admin';
        $baseVars['user'] = $baseVars['marques_user'];

        $templateVars = array_merge($baseVars, $vars);

        $this->registerTemplateVars($templateVars);

        if (!preg_match('/^[a-z0-9_-]+$/i', $templateKey)) {
            throw new \InvalidArgumentException("Invalid template key: " . htmlspecialchars($templateKey));
        }

        $viewFile = rtrim($this->templateDir, '/') . '/' . $templateKey . '.phtml';

        if (!$this->fileExistsCached($viewFile)) {
            throw new \RuntimeException("Admin view file not found: " . htmlspecialchars($viewFile));
        }

        $layoutExists = $this->fileExistsCached($this->layoutFile);
        $layoutContent = $layoutExists ? $this->getCachedFileContent($this->layoutFile) : '';

        $this->collectAssetsForTemplates($layoutExists ? $this->layoutFile : '', $viewFile);

        $contentForLayout = '';
        try {
            foreach ($templateVars as $key => $value) {
                if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $key)) {
                    ${$key} = $value;
                }
            }
            ob_start();
            include $viewFile;
            $viewContent = ob_get_clean();
            $processedViewContent = $this->tokenParser->parseTokens($viewContent);
            $this->tokenParser->setBlock('content', $processedViewContent);
            $contentForLayout = $processedViewContent;
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw new \RuntimeException("Error rendering view '{$templateKey}': " . $e->getMessage(), 0, $e);
        }

        if ($layoutExists) {
            try {
                $finalOutput = $this->tokenParser->parseTokens($layoutContent);
                $finalOutput = $this->tokenParser->renderInline($finalOutput);
                echo $finalOutput;
            } catch (\Throwable $e) {
                echo $contentForLayout;
                error_log("Admin layout rendering error ({$this->layoutFile}): " . $e->getMessage());
            }
        } else {
            echo $contentForLayout;
        }
    }

    /**
     * Checks file existence with static cache.
     */
    protected function fileExistsCached(string $filePath): bool {
        $now = time();
        if (isset(self::$fileExistenceCache[$filePath], self::$fileExistenceCacheTTL[$filePath]) &&
            $now < self::$fileExistenceCacheTTL[$filePath]) {
            return self::$fileExistenceCache[$filePath];
        }
        self::$fileExistenceCache[$filePath] = file_exists($filePath);
        self::$fileExistenceCacheTTL[$filePath] = $now + self::FILE_CACHE_TTL;
        return self::$fileExistenceCache[$filePath];
    }

    /**
     * Returns file content with static cache.
     */
    private function getCachedFileContent(string $filePath): string {
        $currentMTime = filemtime($filePath);
        if (isset(self::$fileContentCache[$filePath])) {
            [$cachedContent, $cachedMTime] = self::$fileContentCache[$filePath];
            if ($cachedMTime === $currentMTime) {
                return $cachedContent;
            }
        }
        $content = file_get_contents($filePath);
        self::$fileContentCache[$filePath] = [$content, $currentMTime];
        return $content;
    }

    /**
     * Returns the template directory.
     */
    public function getTemplateDir(): string
    {
        return $this->templateDir;
    }

    /**
     * Returns all current template variables.
     */
    public function getTemplateVars(): array
    {
        return [
            'helper' => $this->helper,
            'safetyXSS' => $this->safetyXSS ?? null,
            'router' => $this->adminRouter ?? null,
        ];
    }
}