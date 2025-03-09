<?php
declare(strict_types=1);

namespace Marques\Core;

class Template extends Core {
    private $_config;
    private $_navManager = null;
    private $templatePath;

    public function __construct(Docker $docker) {
        parent::__construct($docker);
        $this->_config = $this->resolve('config')->load('system') ?: [];
        $themeManager = $this->resolve('theme_manager');
        $this->templatePath = $themeManager->getThemePath('templates');
    }

    public function render(array $data): void {
        $templateName = $data['template'] ?? 'page';

        if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $templateName)) {
            throw new \Exception("Ungültiger Template-Name: " . htmlspecialchars($templateName));
        }

        $templateFile = $this->templatePath . '/' . $templateName . '.tpl.php';
        if (!file_exists($templateFile)) {
                throw new \Exception("Template nicht gefunden: " . $templateName);
        }

        $baseTemplateFile = $this->templatePath . '/base.tpl.php';
        if (!file_exists($baseTemplateFile)) {
                throw new \Exception("Basis-Template nicht gefunden");
        }

        $system_settings = $this->resolve('config')->load('system')?: [];

        if (defined('IS_ADMIN')) {
                if (strpos($system_settings['base_url'], '/admin') === false) {
                    $system_settings['base_url'] = rtrim($system_settings['base_url'], '/') . '/admin';
                }
            } else {
                if (strpos($system_settings['base_url'], '/admin') !== false) {
                    $system_settings['base_url'] = preg_replace('|/admin$|', '', $system_settings['base_url']);
                }
            }

        $data['title'] = $data['title'] ?? '';
        $data['system_settings'] = $system_settings;
        $data['config'] = $this->_config;
        $data['themeManager'] = $this->resolve('theme_manager');
        $data['cacheManager'] = $this->resolve('cache_manager');
        $data['templateFile'] = $templateFile;
        $data['templateName'] = $templateName;

        $tpl = new TemplateVars($data);

        $cacheKey = 'template_' . $templateName;
        $cachedOutput = $this->resolve('cache_manager')->get($cacheKey);

        if ($cachedOutput !== null) {
            echo $cachedOutput;
            return;
        }

        ob_start();
        include $baseTemplateFile;
        $output = ob_get_clean();
        $this->resolve('cache_manager')->set($cacheKey, $output, 3600, ['templates']);
        echo $output;
    }


    public function includePartial($partialName, $data = []) {
      if ($partialName === 'header') {
          $navigationManager = $this->resolve('navigation_manager');
          if(empty($navigationManager->getMenu('main_menu'))){
              $navigationManager->migrateExistingMenu();
          }
          $data['navigation'] = $navigationManager->renderMainMenu();
      }

      $tpl = new TemplateVars($data);

      $partialFile =  $this->templatePath . '/partials/' . $partialName . '.tpl.php';
        if (!file_exists($partialFile)) {

                echo "<!-- Partial nicht gefunden: $partialName -->";
                return;
        }

      include $partialFile;
    }

    public function getNavigationManager() {
        if ($this->_navManager === null) {
            $this->_navManager = $this->resolve('navigation_manager');
        }
        return $this->_navManager;
    }

    public function exists($templateName) {
        $templateFile = $this->templatePath . '/' . $templateName . '.tpl.php';
        if (file_exists($templateFile)) {
            return true;
        }

        return file_exists($templateFile);
    }
}