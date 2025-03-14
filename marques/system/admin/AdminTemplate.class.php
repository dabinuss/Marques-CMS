<?php
namespace Marques\Admin;

use Marques\Core\AppPath;

class AdminTemplate
{
    /**
     * Standardpfad zum Template, falls kein spezieller Pfad übergeben wird.
     */
    protected string $templateBase;

    /**
     * Standardpfad zum Content der Templates, falls kein spezieller Pfad übergeben wird.
     */
    protected string $contentBase;

    public function __construct()
    {
        // Basisverzeichnis für Admin-Templates
        $appPath = AppPath::getInstance();
        $this->contentBase = $appPath->getPath('admin') . '/lib/';
        $this->templateBase = $appPath->getPath('admin') . '/lib/templates/';
    }

    /**
     * Rendert das Admin-Template
     *
     * @param array  $vars     Variablen für Template
     * @param string $template Relativer Pfad zum Template (vom Router übergeben)
     *
     * @throws \Exception
     */
    public function render(array $vars = [], string $template = '/system/dashboard'): void
    {
        extract($vars);

        $contentFile = $this->contentBase . $template . '.php';
        $layoutFile  = $this->templateBase . basename($template) . '.phtml';

        if (!file_exists($contentFile)) {
            throw new \Exception("Content-Datei nicht gefunden: {$contentFile}");
        }

        if (!file_exists($layoutFile)) {
            throw new \Exception("Layout-Datei nicht gefunden: {$layoutFile}");
        }

        ob_start();
        include $contentFile;
        $content = ob_get_clean();

        include $layoutFile;
    }
}
