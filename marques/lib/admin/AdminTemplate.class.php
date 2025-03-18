<?php
declare(strict_types=1);

namespace Marques\Admin;

use Marques\Core\AppTemplate;
use Marques\Core\AppPath;
use Marques\Core\AppCache;

class AdminTemplate extends AppTemplate {
    /**
     * Basisverzeichnis für Content-Dateien im Adminbereich.
     */
    protected string $contentBase;

    /**
     * Statischer Cache für file_exists-Ergebnisse.
     * Mit TTL zur Vermeidung veralteter Einträge bei langlebigen Prozessen.
     */
    protected static array $fileExistenceCache = [];
    protected static array $fileExistenceCacheTTL = [];
    protected const FILE_CACHE_TTL = 1; // 1 Sekunde TTL

    /**
     * Flag, ob der Cache aktiviert ist.
     */
    protected bool $cacheEnabled = false;

    /**
     * Setzt, ob der Cache aktiviert werden soll.
     *
     * @param bool $enabled
     */
    public function setCacheEnabled(bool $enabled): void {
        $this->cacheEnabled = $enabled;
    }

    public function __construct() {
        parent::__construct();
        $appPath = AppPath::getInstance();
        // Überschreibe den Template-Pfad für den Adminbereich
        $this->templatePath = $appPath->getPath('admin') . '/lib/templates/';
        // Setze den Content-Pfad für Admin-Templates
        $this->contentBase = $appPath->getPath('admin') . '/lib/';
    }

    /**
     * Prüft mittels internem Cache, ob eine Datei existiert.
     * Berücksichtigt TTL für den Cache-Eintrag.
     * 
     * @param string $filePath
     * @return bool
     */
    protected function fileExistsCached(string $filePath): bool {
        // Cache deaktiviert? Dann direkt prüfen.
        if (!$this->cacheEnabled) {
            return file_exists($filePath);
        }
        
        $now = time();
        
        // Prüfe, ob der Cache-Eintrag abgelaufen ist
        if (isset(self::$fileExistenceCache[$filePath]) && 
            isset(self::$fileExistenceCacheTTL[$filePath]) && 
            $now < self::$fileExistenceCacheTTL[$filePath]) {
            return self::$fileExistenceCache[$filePath];
        }
        
        // Cache aktualisieren
        self::$fileExistenceCache[$filePath] = file_exists($filePath);
        self::$fileExistenceCacheTTL[$filePath] = $now + self::FILE_CACHE_TTL;
        
        return self::$fileExistenceCache[$filePath];
    }

    /**
     * Inkludiert eine Template-Datei und weist aus dem übergebenen Array nur
     * Variablen zu, deren Schlüssel gültige PHP-Variablennamen sind.
     *
     * Diese Methode wird vor allem für das Layout genutzt.
     *
     * @param string $templateFile Pfad zur Template-Datei
     * @param array  $vars         Array mit Variablen
     */
    protected function safeInclude(string $templateFile, array $vars): void {
        foreach ($vars as $key => $value) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                ${$key} = $value;
            }
        }
        include $templateFile;
    }

    /**
     * Sicherere Alternative zu extract(), die nur gültige Variablennamen akzeptiert
     * und bestimmte reservierte Namen nicht überschreibt.
     *
     * @param array $vars Array mit zu extrahierenden Variablen
     * @return array Assoziatives Array mit den extrahierten Variablen
     */
    protected function safeExtract(array $vars): array {
        $result = [];
        $reservedNames = ['this', 'GLOBALS', '_SERVER', '_GET', '_POST', 
                          '_FILES', '_COOKIE', '_SESSION', '_REQUEST', 
                          '_ENV', 'contentFile', 'vars', 'result', 
                          'reservedNames', 'key', 'value'];
        
        foreach ($vars as $key => $value) {
            // Prüfe, ob es ein gültiger Variablenname ist und nicht reserviert
            if (is_string($key) && 
                preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key) && 
                !in_array($key, $reservedNames)) {
                ${$key} = $value;
                $result[$key] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Rendert ein Admin-Template, das aus einem Content- und einem Layout-File besteht.
     *
     * Variablen, die im Content-Template definiert werden, stehen automatisch im Layout-Template
     * zur Verfügung, ohne explizite Zuweisung zum $vars-Array.
     *
     * @param array  $vars     Variablen, die an das Template übergeben werden.
     * @param string $template Relativer Pfad zum Content-Template (Default: /lib/dashboard).
     *
     * @throws \Exception Falls die Content- oder Layout-Datei nicht gefunden wird.
     */
    public function render(array $vars = [], string $template = '/lib/dashboard'): void {
        // Admin-spezifische Kennzeichnung
        $vars['is_admin'] = true;

        // Ermittlung der Pfade:
        // Content-Datei, z. B. "/lib/dashboard.php"
        $contentFile = $this->contentBase . $template . '.php';
        // Layout-Datei, z. B. "dashboard.phtml" (Basename des Templates)
        $layoutFile  = $this->templatePath . '/' . basename($template) . '.phtml';

        if (!$this->fileExistsCached($contentFile)) {
            throw new \Exception("Content-Datei nicht gefunden: {$contentFile}");
        }
        if (!$this->fileExistsCached($layoutFile)) {
            throw new \Exception("Layout-Datei nicht gefunden: {$layoutFile}");
        }

        // Verwende den AppCache, um den finalen Output zu cachen.
        $cacheManager = AppCache::getInstance();
        
        // Effizienterer Cache-Key für bessere Performance
        $varsHash = md5(json_encode(array_map(function($item) {
            if (is_object($item)) {
                return get_class($item) . spl_object_id($item);
            }
            return $item;
        }, $vars), JSON_PARTIAL_OUTPUT_ON_ERROR));
        
        $cacheKey = 'admin_template_' . md5($template . '_' . $varsHash);
        
        // Nur cachen, wenn Cache aktiviert ist
        if ($this->cacheEnabled && ($cachedOutput = $cacheManager->get($cacheKey))) {
            echo $cachedOutput;
            return;
        }

        // Content rendern in einem isolierten Bereich
        ob_start();
        $renderContent = function() use ($contentFile, $vars) {
            // Sichere Variablen-Extrahierung ohne extract()
            foreach ($vars as $key => $value) {
                if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                    ${$key} = $value;
                }
            }
            
            // Führe die Content-Datei aus
            include $contentFile;
            
            // Gib alle definierten Variablen zurück
            return get_defined_vars();
        };
        
        // Führe die Content-Datei aus und sammle alle Variablen
        $contentVars = $renderContent();
        $content = ob_get_clean();

        // Entferne Variablen, die wir nicht in das Layout übertragen möchten
        unset($contentVars['contentFile']);
        unset($contentVars['this']);
        
        // Füge den gerenderten Content und alle extrahierten Variablen dem $vars-Array hinzu
        $vars = array_merge($vars, $contentVars);
        $vars['content'] = $content;

        // Jetzt das Layout rendern - alle Variablen aus Content sind verfügbar
        ob_start();
        $this->safeInclude($layoutFile, $vars);
        $output = ob_get_clean();

        // Cache nur setzen, wenn aktiviert
        if ($this->cacheEnabled) {
            $cacheManager->set($cacheKey, $output, 3600, ['templates']);
        }
        echo $output;
    }
}
