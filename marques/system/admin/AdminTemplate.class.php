<?php
namespace Marques\Admin;

class AdminTemplate
{
    /**
     * Standardpfad zum Template, falls kein spezieller Pfad übergeben wird.
     */
    protected string $defaultBase;

    public function __construct()
    {
        // Setze den Default-Basis-Pfad, z.B. für das Dashboard
        $this->defaultBase = MARQUES_ROOT_DIR . '/admin/pages/';
    }

    /**
     * Rendert das Template, indem es die übergebenen Variablen extrahiert und anschließend
     * die Content- und Layout-Dateien einbindet.
     *
     * @param array       $vars Assoziatives Array mit Variablen, die im Template verfügbar sein sollen.
     * @param string|null $base Optionaler Basis-Pfad zum Template (ohne Endung).
     */
    public function render(array $vars = [], ?string $base = null): void
    {
        // Extrahiere die übergebenen Variablen in den lokalen Scope
        extract($vars);

        // Verwende den übergebenen Basis-Pfad oder den Default
        $base = $this->defaultBase . $base ?? $this->defaultBase . 'dashboard';

        // Definiere den Pfad für die Content- und Layout-Dateien
        $contentFile = $base . '.php';
        $layoutFile  = $base . '.ctpl';

        // Prüfe, ob beide Dateien existieren
        if (!file_exists($contentFile)) {
            throw new \Exception("Content-Datei nicht gefunden: " . $contentFile);
        }
        if (!file_exists($layoutFile)) {
            throw new \Exception("Layout-Datei nicht gefunden: " . $layoutFile);
        }

        // Zuerst den Content laden (Output Buffering verwenden)
        ob_start();
        include $contentFile;
        $content = ob_get_clean();

        // Im Layout wird der Inhalt in der Variable $content verfügbar sein.
        include $layoutFile;
    }
}
