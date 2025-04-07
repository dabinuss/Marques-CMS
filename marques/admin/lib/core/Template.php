<?php
declare(strict_types=1);

namespace Admin\Core;

// Kernklassen für Abhängigkeiten
use Marques\Core\Template as AppTemplate;
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
    protected Helper $helper;
    protected Router $adminRouter;
    protected SafetyXSS $safetyXSS; // Sicherheitstools für XSS-Schutz

    // Cache für Dateiexistenz (kann bleiben)
    protected static array $fileExistenceCache = [];
    protected static array $fileExistenceCacheTTL = [];
    const FILE_CACHE_TTL = 60;

    public function __construct(
        DatabaseHandler $dbHandler,
        ThemeManager $themeManager, // Wird evtl. für Assets im Layout gebraucht
        Path $appPath,
        Cache $cache,
        Helper $helper,
        Router $adminRouter,
        SafetyXSS $safetyXSS = null, // Sicherheitstools für XSS-Schutz
        string $templateDir = null // Optionaler Parameter
    ) {
        $this->templateDir = $templateDir ?? $appPath->getPath('admin_template');
        $this->layoutFile = rtrim($this->templateDir, '/') . '/' . $this->layoutFile;
        $this->helper = $helper;
        $this->adminRouter = $adminRouter;
        $this->safetyXSS = $safetyXSS; // Sicherheitstools für XSS-Schutz
    }

    /**
     * Rendert ein Admin-View-Template innerhalb eines Layouts.
     *
     * @param array  $vars        Variablen, die an das AppTemplate übergeben werden.
     * @param string $templateKey Schlüssel/Dateiname des zu rendernden Views (ohne Endung, z.B. 'dashboard').
     *
     * @throws \Exception Falls die View- oder Layout-Datei nicht gefunden wird.
     */
    public function render(array $vars = [], string $templateKey = 'dashboard'): void {
        try {
            $baseVars = [
                'helper' => $this->helper,
                'router' => $this->adminRouter, // Router im AppTemplate verfügbar machen
                'safetyXSS' => $this->safetyXSS, // Sicherheitstools für XSS-Schutz
                'is_admin' => true
            ];
    
            $vars = array_merge($baseVars, $vars);
            $safeVars = $this->sanitizeVariables($vars);
    
            // AppTemplate-Schlüssel validieren
            if (!preg_match('/^[a-z0-9_-]+$/i', $templateKey)) {
                throw new \InvalidArgumentException("Ungültiger AppTemplate-Schlüssel: " . htmlspecialchars($templateKey));
            }
            
            // View-Datei-Pfad erstellen
            $viewFile = rtrim($this->templateDir, '/') . '/' . $templateKey . '.phtml';
    
            // Prüfe, ob View-Datei existiert
            if (!$this->fileExistsCached($viewFile)) {
                throw new \RuntimeException("Admin View-Datei nicht gefunden: " . htmlspecialchars($viewFile));
            }
            
            // Prüfe, ob Layout-Datei existiert
            $layoutExists = $this->fileExistsCached($this->layoutFile);
    
            // Gesamte Ausgabe in einem Buffer halten
            ob_start();
            
            // --- Rendern des spezifischen Views ---
            ob_start();
            try {
                // Variablen für den View extrahieren
                extract($safeVars, EXTR_SKIP);
                
                // View einbinden
                include $viewFile;
                
                $contentForLayout = ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean(); // View-Buffer leeren
                throw new \RuntimeException("Fehler beim Rendern der View '{$templateKey}': " . $e->getMessage(), 0, $e);
            }
    
            // --- Rendern des Layouts, falls vorhanden ---
            if ($layoutExists) {
                try {
                    // Content-Variable für das Layout hinzufügen
                    $safeVars['content'] = $contentForLayout;
                    
                    // Variablen für das Layout extrahieren
                    extract($safeVars, EXTR_SKIP);
                    
                    // Layout einbinden
                    include $this->layoutFile;
                } catch (\Throwable $e) {
                    // Fehler beim Layout-Rendering
                    ob_clean(); // Gesamtbuffer leeren
                    echo $contentForLayout; // Nur den View ohne Layout anzeigen
                    error_log("Fehler beim Rendern des Admin-Layouts: " . $e->getMessage());
                }
            } else {
                // Kein Layout vorhanden, nur den View-Inhalt ausgeben
                echo $contentForLayout;
            }
            
            // Gesamtausgabe ausgeben
            ob_end_flush();
            
        } catch (\Throwable $e) {
            // Alle noch offenen Buffer leeren
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // Fehler weiterwerfen für zentrale Fehlerbehandlung
            throw $e;
        }
    }

    protected function sanitizeVariables(array $vars): array {
        $sanitized = [];
        
        foreach ($vars as $key => $value) {
            // Schlüssel sichern
            $safeKey = is_string($key) ? htmlspecialchars($key, ENT_QUOTES, 'UTF-8') : $key;
            
            // Wert rekursiv sichern
            if (is_array($value)) {
                $sanitized[$safeKey] = $this->sanitizeVariables($value);
            } elseif (is_string($value)) {
                $sanitized[$safeKey] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            } else {
                $sanitized[$safeKey] = $value;
            }
        }
        
        return $sanitized;
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
}