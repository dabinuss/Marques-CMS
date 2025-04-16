<?php
declare(strict_types=1);

namespace Admin\Core;

// Kernklassen für Abhängigkeiten
use Marques\Core\Template as AppTemplate;
use Marques\Core\TokenParser;
use Marques\Core\Path;
use Marques\Core\Cache;
use Marques\Util\Helper;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Service\ThemeManager;
use Marques\Util\SafetyXSS; // Für sichere Ausgabe
use Admin\Http\Router;

class Template extends AppTemplate {

    protected string $templateDir; // Verzeichnis für .phtml Views
    protected string $layoutFile = 'layout.phtml'; // Standard-Layout-Datei
    protected Router $adminRouter;
    protected SafetyXSS $safetyXSS; // Sicherheitstools für XSS-Schutz

    // Cache für Dateiexistenz (kann bleiben)
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
        string $templateDir = null
    ) {
        // Speichere $appPath in der geerbten Eigenschaft, damit sie initialisiert ist
        parent::__construct($dbHandler, $themeManager, $appPath, $cache, $helper, $tokenParser);
        
        // Initialisiere deine eigenen Eigenschaften
        $this->templateDir = $templateDir ?? $appPath->getPath('admin_template');
        $this->layoutFile = rtrim($this->templateDir, '/') . '/' . $this->layoutFile;
        $this->adminRouter = $adminRouter;
        $this->safetyXSS = $safetyXSS;
        $this->tokenParser = $tokenParser;

        $this->tokenParser->setTemplateDir($this->templateDir);
        $this->tokenParser->setTemplateContext($this);

        $this->tokenParser->getAssetManager()->setBaseUrl($this->helper->getSiteUrl('admin'));
        
        // Admin-spezifische Standard-Variablen setzen
        $this->setAdminVariables();
    }
    
    /**
     * Setzt admin-spezifische Standardvariablen
     */
    protected function setAdminVariables(): void
    {
        $this->tokenParser->setVariables([
            'admin_url' => $this->helper->getSiteUrl('admin'),
            'assets_url' => $this->helper->getSiteUrl('admin/assets'),
            'is_admin' => 'true',
            'site_name' => $this->_config['site_name'] ?? 'Marques CMS Admin',
            'admin_version' => defined('MARQUES_VERSION') ? MARQUES_VERSION : '1.0',
        ]);
        
        // Standard CSS und JS hinzufügen
        $this->tokenParser->addCss($this->helper->getSiteUrl('admin/assets/css/marques-panel-style.css'));
        $this->tokenParser->addCss('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', true);
        $this->tokenParser->addJs('https://cdn.jsdelivr.net/npm/chart.js', true);
        $this->tokenParser->addJs($this->helper->getSiteUrl('admin/assets/js/admin-script.js'));
    }

    /**
     * Rendert ein Admin-View-Template innerhalb eines Layouts.
     *
     * @param array  $vars        Variablen, die an das Template übergeben werden.
     * @param string $templateKey Schlüssel/Dateiname des zu rendernden Views (ohne Endung, z.B. 'dashboard').
     *
     * @throws \Exception Falls die View- oder Layout-Datei nicht gefunden wird.
     */
    public function render(array $vars = [], string $templateKey = 'dashboard'): void {
        try {
            // Stelle die Grundvariablen sicher, besonders username und safetyXSS
            $baseVars = [
                'helper' => $this->helper,
                'router' => $this->adminRouter, // Router im Template verfügbar machen
                'safetyXSS' => $this->safetyXSS, // Sicherheitstools für XSS-Schutz SIND JETZT ESSENTIELL!
                'is_admin' => true,
                'username' => $vars['username'] ?? 'Gast', // Standardwert für username
                'user' => $vars['user'] ?? null,
                // Referenz auf das Template
                'template' => $this,
                // Referenz auf TokenParser
                'tokenParser' => $this->tokenParser
            ];

            // Füge die aktuellen Benutzerdaten hinzu, wenn wir eingeloggt sind
            $baseVars['marques_user'] = isset($_SESSION['marques_user']) ? $_SESSION['marques_user'] : ['username' => 'Admin'];

            // Falls kein separater 'username'-Eintrag vorhanden, wähle den Wert aus "marques_user"
            if (!isset($vars['username'])) {
                $baseVars['username'] = $baseVars['marques_user']['username'] ?? 'Admin';
            }
            
            // Falls kein separater "user"-Eintrag vorhanden, übernimm den kompletten Inhalt
            if (!isset($vars['user'])) {
                $baseVars['user'] = $baseVars['marques_user'];
            }

            // Mische Basisvariablen mit den übergebenen Variablen
            // $vars überschreibt $baseVars bei gleichen Schlüsseln
            $templateVars = array_merge($baseVars, $vars);
            
            // Variablen auch als Tokens verfügbar machen
            foreach ($templateVars as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $this->tokenParser->setVariable($key, (string)$value);
                }
            }

            // Sanity check für den Template-Key
            if (!preg_match('/^[a-z0-9_-]+$/i', $templateKey)) {
                throw new \InvalidArgumentException("Ungültiger Template-Schlüssel: " . htmlspecialchars($templateKey));
            }

            // View-Datei-Pfad erstellen
            $viewFile = rtrim($this->templateDir, '/') . '/' . $templateKey . '.phtml';

            // Prüfe, ob View-Datei existiert (mit Cache)
            if (!$this->fileExistsCached($viewFile)) {
                throw new \RuntimeException("Admin View-Datei nicht gefunden: " . htmlspecialchars($viewFile));
            }

            // Prüfe, ob Layout-Datei existiert (mit Cache)
            $layoutExists = $this->fileExistsCached($this->layoutFile);

            // --- Rendern des spezifischen Views ---
            $contentForLayout = '';
            try {
                // Variablen für den View extrahieren (KEIN globales Escaping mehr hier!)
                // $templateVars enthält jetzt die Originaldaten.
                foreach ($templateVars as $key => $value) {
                    // Überprüfe, ob der Schlüssel ein valider Variablenname ist
                    if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $key)) {
                        ${$key} = $value;
                    }
                }

                // Starte Content-Block für den Hauptinhalt
                $this->tokenParser->startBlock('content');
                
                // View-Datei einbinden - hier kann weiterer Output erzeugt werden
                include $viewFile;
                
                // Content-Block beenden
                $this->tokenParser->endBlock();
                
                // Content für Fallback speichern (falls kein Layout existiert)
                $contentForLayout = $this->tokenParser->getBlock('content');
                
            } catch (\Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean(); // View-Buffer leeren bei Fehler
                }
                // Werfe eine spezifischere Exception
                throw new \RuntimeException("Fehler beim Rendern der View '{$templateKey}': " . $e->getMessage(), 0, $e);
            }

            // --- Rendern des Layouts, falls vorhanden ---
            if ($layoutExists) {
                try {
                    // Lade den Layout-Inhalt
                    $layoutContent = $this->getCachedFileContent($this->layoutFile);
                    
                    // Verarbeite alle Tokens im Layout
                    $finalOutput = $this->tokenParser->parseTokens($layoutContent);
                    
                    // Ausgabe
                    echo $finalOutput;
                    
                } catch (\Throwable $e) {
                    // Bei Fehlern im Layout-Rendering nur den View zeigen (als Fallback)
                    echo $contentForLayout;
                    // Logge den Layout-Fehler
                    error_log("Fehler beim Rendern des Admin-Layouts ({$this->layoutFile}): " . $e->getMessage());
                }
            } else {
                // Kein Layout vorhanden, nur den View-Inhalt ausgeben
                echo $contentForLayout;
            }

        } catch (\Throwable $e) {
            // Fehler weiterwerfen für die zentrale Fehlerbehandlung (ExceptionHandler)
            // Loggen erfolgt bereits im ExceptionHandler
            throw $e;
        }
    }

    /**
     * Prüft mittels internem Cache, ob eine Datei existiert.
     */
    protected function fileExistsCached(string $filePath): bool {
        $now = time();
        
        if (isset(self::$fileExistenceCache[$filePath]) &&
            isset(self::$fileExistenceCacheTTL[$filePath]) &&
            $now < self::$fileExistenceCacheTTL[$filePath]) {
            return self::$fileExistenceCache[$filePath];
        }
        
        self::$fileExistenceCache[$filePath] = file_exists($filePath);
        self::$fileExistenceCacheTTL[$filePath] = $now + self::FILE_CACHE_TTL;
        
        return self::$fileExistenceCache[$filePath];
    }

    /**
     * Liest den Inhalt einer Datei mit Cache
     */
    private function getCachedFileContent(string $filePath): string {
        $currentMTime = filemtime($filePath);
        // Cache-Key basiert auf dem Dateipfad
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
     * Gibt das Template-Verzeichnis zurück
     * 
     * @return string
     */
    public function getTemplateDir(): string
    {
        return $this->templateDir;
    }

    /**
     * Gibt alle aktuellen Template-Variablen zurück
     * 
     * @return array
     */
    public function getTemplateVars(): array
    {
        // Hier müsstest du alle Variablen zurückgeben, die im Template verfügbar sein sollen
        return [
            'helper' => $this->helper,
            'safetyXSS' => $this->safetyXSS ?? null,
            'router' => $this->adminRouter ?? null,
            // Weitere Variablen, die in Blocks verfügbar sein sollen
        ];
    }
}