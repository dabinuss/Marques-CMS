<?php
declare(strict_types=1);

namespace Admin\Core;

use Marques\Core\Template as AppTemplate;
use Marques\Core\TokenParser;
use Marques\Filesystem\PathRegistry;
use Marques\Core\Cache;
use Marques\Filesystem\FileManager;
use Marques\Util\Helper;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Service\ThemeManager;
use Marques\Util\SafetyXSS;
use Admin\Http\Router;

/**
 * Back‑office template engine: extends front‑end template handling with admin‑specific layout and routing.
 */
class Template extends AppTemplate
{
    protected string   $templateDir;
    protected string   $layoutFile;
    protected Router   $adminRouter;
    protected SafetyXSS $safetyXSS;

    /**
     * @param DatabaseHandler  $dbHandler   Database abstraction
     * @param ThemeManager     $themeManager Active theme handler
     * @param PathRegistry     $appPath
     * @param Cache            $cache        Cache implementation
     * @param Helper           $helper       Generic utility helper
     * @param Router           $adminRouter  Admin router
     * @param SafetyXSS        $safetyXSS    XSS sanitizer
     * @param TokenParser      $tokenParser  Template token parser
     * @param FileManager      $fileManager  Filesystem abstraction
     * @param string|null      $templateDir  Custom admin template directory
     */
    public function __construct(
        DatabaseHandler $dbHandler,
        ThemeManager $themeManager,
        PathRegistry $appPath,
        Cache $cache,
        Helper $helper,
        Router $adminRouter,
        SafetyXSS $safetyXSS,
        TokenParser $tokenParser,
        FileManager $fileManager,
        ?string $templateDir = null
    ) {
        parent::__construct($dbHandler, $themeManager, $appPath, $cache, $helper, $tokenParser, $fileManager);

        $this->templateDir = $templateDir ?: MARQUES_ADMIN_DIR . '/lib/templates';
        $this->layoutFile  = $this->templateDir . '/layout.phtml';

        $this->fileManager->addDirectory('backend_templates', $this->templateDir);
        $this->fileManager->useDirectory('backend_templates');

        $this->adminRouter = $adminRouter;
        $this->safetyXSS   = $safetyXSS;
        $this->tokenParser = $tokenParser;

        $this->tokenParser->setTemplateDir($this->templateDir);
        $this->tokenParser->setTemplateContext($this);
        $this->tokenParser->getAssetManager()->setBaseUrl($this->helper->getSiteUrl('admin'));

        $this->setAdminVariables();
    }

    /**
     * Adds admin‑specific default variables.
     */
    protected function setAdminVariables(): void
    {
        parent::setStandardVariables();
        $this->tokenParser->setVariables([
            'admin_url'     => $this->helper->getSiteUrl('admin'),
            'assets_url'    => $this->helper->getSiteUrl('admin/assets'),
            'is_admin'      => 'true',
            'site_name'     => $this->_config['site_name'] ?? 'Marques CMS Admin',
            'admin_version' => defined('MARQUES_VERSION') ? MARQUES_VERSION : '1.0',
        ]);
    }

    /**
     * Renders an admin template, applying the admin layout if available.
     *
     * @param array<string,mixed> $vars
     */
    public function render(array $vars = [], string $templateKey = 'dashboard'): void
    {
        $baseVars = [
            'helper'      => $this->helper,
            'router'      => $this->adminRouter,
            'safetyXSS'   => $this->safetyXSS,
            'is_admin'    => true,
            'username'    => $vars['username'] ?? 'Guest',
            'user'        => $vars['user'] ?? null,
            'template'    => $this,
            'tokenParser' => $this->tokenParser,
        ];
    
        $baseVars['marques_user'] = $_SESSION['marques_user'] ?? ['username' => 'Admin'];
        $baseVars['username']     = $baseVars['marques_user']['username'] ?? 'Admin';
        $baseVars['user']         = $baseVars['marques_user'];
    
        $templateVars = array_merge($baseVars, $vars);
    
        $this->registerTemplateVars($templateVars);
    
        if (!preg_match('/^[a-z0-9_-]+$/i', $templateKey)) {
            throw new \InvalidArgumentException('Invalid template key: ' . htmlspecialchars($templateKey));
        }
    
        $viewFile = $this->templateDir . '/' . $templateKey . '.phtml';
        if (!file_exists($viewFile)) {
            throw new \RuntimeException('Admin view file not found: ' . htmlspecialchars($viewFile));
        }
    
        $layoutExists     = file_exists($this->layoutFile);
        $layoutContent    = $layoutExists ? file_get_contents($this->layoutFile) : '';
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
            throw new \RuntimeException(
                "Error rendering view '{$templateKey}': " . $e->getMessage(),
                0,
                $e
            );
        }
    
        if ($layoutExists) {
            try {
                // WICHTIGE ÄNDERUNG: Hier wird das Layout noch einmal auf Tokens geparst.
                $finalOutput = str_replace('{render:block:content}', $contentForLayout, $layoutContent);
                $finalOutput = $this->tokenParser->parseTokens($finalOutput);
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
     * Returns the absolute admin template directory.
     */
    public function getTemplateDir(): string
    {
        return $this->templateDir;
    }

    /**
     * Returns a reduced set of template‑related variables useful for sub‑views.
     *
     * @return array<string,mixed>
     */
    public function getTemplateVars(): array
    {
        return [
            'helper'    => $this->helper,
            'safetyXSS' => $this->safetyXSS,
            'router'    => $this->adminRouter,
        ];
    }
}
