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
        SafetyXSS $safetyXSS, // Sicherheitstools für XSS-Schutz
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
            // Stelle die Grundvariablen sicher, besonders username und safetyXSS
            $baseVars = [
                'helper' => $this->helper,
                'router' => $this->adminRouter, // Router im AppTemplate verfügbar machen
                'safetyXSS' => $this->safetyXSS, // Sicherheitstools für XSS-Schutz SIND JETZT ESSENTIELL!
                'is_admin' => true,
                'username' => $vars['username'] ?? 'Gast', // Standardwert für username
                'user' => $vars['user'] ?? null,
                // Füge hier weitere globale Admin-Template-Variablen hinzu, falls nötig
            ];

            // Füge die aktuellen Benutzerdaten hinzu, wenn wir eingeloggt sind
            if (isset($_SESSION['marques_user'])) {
                // Session-Daten für Benutzer verwenden
                $userData = $_SESSION['marques_user'];
                if (!isset($vars['username'])) {
                    $baseVars['username'] = $userData['username'] ?? 'Admin';
                }
                if (!isset($vars['user'])) {
                    $baseVars['user'] = $userData; // Das gesamte User-Array aus der Session
                }
            }

            // Mische Basisvariablen mit den übergebenen Variablen
            // $vars überschreibt $baseVars bei gleichen Schlüsseln
            $templateVars = array_merge($baseVars, $vars);

            // Sanity check für den Template-Key
            if (!preg_match('/^[a-z0-9_-]+$/i', $templateKey)) {
                throw new \InvalidArgumentException("Ungültiger AppTemplate-Schlüssel: " . htmlspecialchars($templateKey));
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
                extract($templateVars, EXTR_SKIP);

                // Wir fangen die Ausgabe für den View ab
                ob_start();
                include $viewFile; // Hier MUSS das Escaping mit $safetyXSS->escapeOutput() erfolgen!
                $contentForLayout = ob_get_clean();
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
                    // Füge den gerenderten View-Inhalt als 'content'-Variable für das Layout hinzu
                    // WICHTIG: $contentForLayout kann HTML enthalten und darf hier NICHT escaped werden!
                    $templateVars['content'] = $contentForLayout;

                    // Variablen für das Layout extrahieren (KEIN globales Escaping mehr hier!)
                    extract($templateVars, EXTR_SKIP);

                    // Wir fangen die Layout-Ausgabe ab
                    ob_start();
                    include $this->layoutFile; // Hier MUSS das Escaping mit $safetyXSS->escapeOutput() erfolgen!
                    $finalOutput = ob_get_clean();

                    // Jetzt erst geben wir die finale Ausgabe aus
                    echo $finalOutput;
                } catch (\Throwable $e) {
                    // Bei Fehlern im Layout-Rendering nur den View zeigen (als Fallback)
                    if (ob_get_level() > 0) {
                        ob_end_clean(); // Layout-Buffer leeren
                    }
                    // Gib nur den bereits gerenderten View-Inhalt aus
                    echo $contentForLayout;
                    // Logge den Layout-Fehler
                    error_log("Fehler beim Rendern des Admin-Layouts ({$this->layoutFile}): " . $e->getMessage());
                    // Optional: Eine sichtbare Fehlermeldung hinzufügen, wenn im Debug-Modus
                    // if ($this->helper->getConfig()['debug'] ?? false) {
                    //     echo "<p style='color:red; font-weight:bold;'>Layout-Rendering-Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
                    // }
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
}