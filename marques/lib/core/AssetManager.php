<?php
declare(strict_types=1);

namespace Marques\Core;

use Marques\Filesystem\PathRegistry;
// Anmerkung: PathResolver wird in diesem Code nicht direkt verwendet, aber PathRegistry nutzt es intern.
// use Marques\Filesystem\PathResolver;
use Marques\Core\Cache; // *** Hinzugefügt ***
use RuntimeException;   // *** Hinzugefügt für Fehler ***
use Throwable;          // *** Hinzugefügt für Catch-Blöcke ***

/**
 * AssetManager - Verwaltet Web-Assets wie CSS, JavaScript, etc.
 */
class AssetManager
{
    private array $assets = [
        'css' => [],
        'js' => []
    ];

    private array $assetGroups = [];
    private string $baseUrl;
    private string $version = '1.0';
    private bool $developmentMode;
    private ?PathRegistry $pathRegistry;
    private readonly Cache $cache; // *** Hinzugefügt ***

    // Cache für das *generierte HTML-Tag* der kombinierten Assets (pro Request)
    private array $combinedHtmlCache = []; // *** Umbenannt für Klarheit ***

    /**
     * Konstruktor
     *
     * @param Cache $cache Die zentrale Cache-Instanz.
     * @param string $baseUrl Basis-URL für Assets.
     * @param string $version Versionsnummer für Cache-Busting.
     * @param bool $developmentMode Im Entwicklungsmodus deaktivierte Optimierungen.
     * @param PathRegistry|null $pathRegistry Optionale PathRegistry für Dateisystem-Zugriff.
     */
    public function __construct(
        Cache $cache, // *** Hinzugefügt ***
        string $baseUrl = '',
        string $version = '',
        bool $developmentMode = false,
        ?PathRegistry $pathRegistry = null
    ) {
        $this->cache = $cache; // *** Hinzugefügt ***
        $this->baseUrl = rtrim($baseUrl, '/'); // Sicherstellen, dass kein Slash am Ende ist
        $this->developmentMode = $developmentMode;
        $this->pathRegistry = $pathRegistry;

        // Version setzen (Standard oder übergeben)
        $this->version = !empty($version) ? $version : date('YmdHis'); // Fallback auf Zeitstempel
    }

    /**
     * Löst den physischen Pfad zu einer Asset-Datei auf.
     * Verwendet PathRegistry, wenn verfügbar.
     */
    private function resolveAssetPath(string $path): ?string // *** Rückgabetyp ?string ***
    {
        // Externe URLs nicht auflösen
        if (strpos($path, '://') !== false || strpos($path, '//') === 0) {
            return null; // Signalisiert externe oder nicht auflösbare Ressource
        }

        if ($this->pathRegistry) {
            try {
                // Prüfen, ob der Pfad relativ zum Root ist
                // Annahme: Pfade wie 'css/style.css' sind relativ zum Projekt-Root
                $fullPath = $this->pathRegistry->combine('root', $path);
                if (is_file($fullPath)) { // is_file ist genauer als file_exists
                    return $fullPath;
                }
                // Fallback: Prüfen, ob der Pfad bereits absolut ist
                if (is_file($path)) {
                     return $path;
                }
            } catch (Throwable $e) { // Throwable fängt mehr Fehler ab
                if ($this->developmentMode) {
                    error_log("AssetManager: Fehler bei Asset-Pfadauflösung ('{$path}'): " . $e->getMessage());
                }
                return null; // Konnte nicht aufgelöst werden
            }
        } else {
            // Fallback ohne PathRegistry (weniger sicher)
            $fullPath = $this->getWebRootPath() . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
             if (is_file($fullPath)) {
                 return $fullPath;
             }
        }
        if ($this->developmentMode) {
            error_log("AssetManager: Asset-Datei nicht gefunden oder nicht auflösbar: '{$path}'");
        }
        return null; // Nicht gefunden oder nicht auflösbar
    }

    /**
     * Erzeugt eine Web-URL für ein Asset (relativ zur BaseUrl).
     */
    private function createAssetUrl(string $relativePath): string
    {
        // Stellt sicher, dass der relative Pfad mit / beginnt
        $path = '/' . ltrim($relativePath, '/');

        // Füge baseUrl hinzu, wenn vorhanden
        if (!empty($this->baseUrl)) {
            // Verhindere doppelte baseUrl, falls der Pfad sie schon enthält
            if (str_starts_with($path, $this->baseUrl)) {
                 return $path;
            }
            return $this->baseUrl . $path;
        }

        return $path;
    }

    /**
     * Löst das *physische* Cache-Verzeichnis für kombinierte Assets auf.
     */
    private function resolveCombinedAssetCacheDir(): ?string // *** Rückgabetyp ?string ***
    {
        if ($this->pathRegistry) {
            try {
                // Wir legen kombinierte Assets in ein Unterverzeichnis 'assets' im Hauptcache ab
                $cacheBase = $this->pathRegistry->getPath('cache');
                $assetCacheDir = $cacheBase . DIRECTORY_SEPARATOR . 'assets';

                // Sicherstellen, dass das Verzeichnis existiert und beschreibbar ist
                if (!is_dir($assetCacheDir)) {
                    if (!@mkdir($assetCacheDir, 0755, true) && !is_dir($assetCacheDir)) {
                         throw new RuntimeException("Konnte Asset-Cache-Verzeichnis nicht erstellen: {$assetCacheDir}");
                    }
                }
                if (!is_writable($assetCacheDir)) {
                     throw new RuntimeException("Asset-Cache-Verzeichnis nicht beschreibbar: {$assetCacheDir}");
                }
                return $assetCacheDir;
            } catch (Throwable $e) {
                error_log("AssetManager: Fehler beim Auflösen/Erstellen des Asset-Cache-Verzeichnisses: " . $e->getMessage());
                return null;
            }
        }

        // Fallback ohne PathRegistry (weniger robust)
        $fallbackDir = $this->getWebRootPath() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'assets';
         if (!is_dir($fallbackDir)) {
            if (!@mkdir($fallbackDir, 0755, true) && !is_dir($fallbackDir)) {
                 error_log("AssetManager: Konnte Fallback-Asset-Cache-Verzeichnis nicht erstellen: {$fallbackDir}");
                 return null;
            }
        }
         if (!is_writable($fallbackDir)) {
             error_log("AssetManager: Fallback-Asset-Cache-Verzeichnis nicht beschreibbar: {$fallbackDir}");
             return null;
         }
        return $fallbackDir;
    }

    /**
     * Fügt ein Asset hinzu.
     *
     * @param string $type Asset-Typ (css, js).
     * @param string $path Pfad/URL zum Asset.
     * @param array $options Zusätzliche Optionen (external, defer, async, version, media, priority, minify, combine, group, type[js]).
     * @return self
     */
    public function add(string $type, string $path, array $options = []): self
    {
        if (!isset($this->assets[$type])) {
            // Unbekannter Asset-Typ? Ignorieren oder Fehler werfen?
            error_log("AssetManager: Unbekannter Asset-Typ '{$type}' hinzugefügt für Pfad '{$path}'.");
            return $this;
        }

        // Eindeutiger Schlüssel für das Asset (Pfad oder Gruppe+Pfad)
        $key = $options['group'] ?? $path;
        // Wenn Gruppe verwendet wird, Schlüssel eindeutiger machen
        if (isset($options['group'])) {
             $key = $options['group'] . '::' . $path;
        }


        // Standardoptionen
        $defaultOptions = [
            'external' => false, // Ist es eine externe URL?
            'defer'    => ($type === 'js'), // JS standardmäßig defer
            'async'    => false, // JS nicht standardmäßig async
            'version'  => true, // Version anhängen (Cache Busting)?
            'media'    => 'all', // CSS Media Query
            'priority' => 10, // Sortierpriorität (niedriger zuerst)
            'minify'   => !$this->developmentMode, // Minifizieren im Produktionsmodus?
            'combine'  => !$this->developmentMode, // Kombinieren im Produktionsmodus?
            // 'group' -> wird direkt verwendet
            // 'type' (für JS, z.B. 'module') -> wird direkt verwendet
        ];

        // Optionen zusammenführen
        $options = array_merge($defaultOptions, $options);

        // Füge Asset zum entsprechenden Typ hinzu
        $this->assets[$type][$key] = [
            'path' => $path,
            'options' => $options
        ];

        // Wenn eine Gruppe angegeben ist, füge es dort ebenfalls hinzu
        if (!empty($options['group'])) {
            $groupName = $options['group'];
            if (!isset($this->assetGroups[$groupName])) {
                $this->assetGroups[$groupName] = ['css' => [], 'js' => []];
            }
             // Stelle sicher, dass der Typ im Gruppenarray existiert
             if (!isset($this->assetGroups[$groupName][$type])) {
                 $this->assetGroups[$groupName][$type] = [];
             }
            $this->assetGroups[$groupName][$type][$key] = [ // Benutze denselben Key
                'path' => $path,
                'options' => $options
            ];
        }

        // Lösche den HTML-Cache für diesen Typ, da sich die Liste geändert hat
        unset($this->combinedHtmlCache[$type]);

        return $this;
    }

    // addCss und addJs bleiben wie zuvor, rufen add() auf
    public function addCss(string $path, bool $isExternal = false, array $options = []): self
    {
        $options['external'] = $isExternal;
        return $this->add('css', $path, $options);
    }
    public function addJs(string $path, bool $isExternal = false, bool $defer = true, array $options = []): self
    {
        $options['external'] = $isExternal;
        $options['defer'] = $defer;
        return $this->add('js', $path, $options);
    }


    /**
     * Generiert HTML für alle Assets eines bestimmten Typs.
     *
     * @param string $type Asset-Typ (css, js).
     * @return string Generiertes HTML.
     */
    public function render(string $type): string
    {
        if (!isset($this->assets[$type]) || empty($this->assets[$type])) {
            return '';
        }

        // Prüfen, ob wir bereits HTML im In-Memory Cache haben (für diesen Request)
        if (isset($this->combinedHtmlCache[$type])) {
            return $this->combinedHtmlCache[$type];
        }

        // Sortiere Assets nach Priorität
        $assetsToRender = $this->assets[$type];
        uasort($assetsToRender, function ($a, $b) {
            return ($a['options']['priority'] ?? 10) <=> ($b['options']['priority'] ?? 10); // PHP 7+ Spaceship Operator
        });

        // Trennen in Assets, die kombiniert werden sollen, und solche, die einzeln bleiben
        $combinableAssets = [];
        $individualAssets = [];

        foreach ($assetsToRender as $key => $asset) {
            // Einzeln wenn: extern, oder 'combine'=false explizit gesetzt
            if ($asset['options']['external'] || !$asset['options']['combine']) {
                $individualAssets[$key] = $asset;
            } else {
                $combinableAssets[$key] = $asset;
            }
        }

        $output = '';

        // Rendere kombinierte Assets (wenn mehr als 0 und Kombinieren generell erlaubt)
        if (!$this->developmentMode && !empty($combinableAssets)) {
            $output .= $this->renderCombinedAssets($type, $combinableAssets);
        } else {
            // Im Dev-Modus oder wenn nichts zu kombinieren ist, rendere einzeln
            foreach ($combinableAssets as $asset) {
                $output .= $this->renderSingleAsset($type, $asset);
            }
        }

        // Rendere die individuellen Assets
        foreach ($individualAssets as $asset) {
            $output .= $this->renderSingleAsset($type, $asset);
        }

        // Speichere das generierte HTML im In-Memory Cache für diesen Request
        $this->combinedHtmlCache[$type] = $output;

        return $output;
    }

    /**
     * Rendert ein einzelnes Asset als HTML-Tag.
     */
    private function renderSingleAsset(string $type, array $asset): string
    {
        $path = $asset['path'];
        $options = $asset['options'];
        $htmlPath = $path; // Pfad für das HTML-Tag

        // Versionierung für lokale Dateien hinzufügen
        if (!$options['external'] && $options['version']) {
            $physicalPath = $this->resolveAssetPath($path);
            if ($physicalPath && is_file($physicalPath)) {
                 // Verwende filemtime für Cache-Busting, wenn Version nicht gesetzt
                 $versionSuffix = !empty($this->version) ? $this->version : filemtime($physicalPath);
                 $separator = str_contains($htmlPath, '?') ? '&' : '?'; // PHP 8+ str_contains
                 $htmlPath .= $separator . 'v=' . $versionSuffix;
            } elseif (!empty($this->version)) {
                 // Fallback auf globale Version, wenn Datei nicht auflösbar
                 $separator = str_contains($htmlPath, '?') ? '&' : '?';
                 $htmlPath .= $separator . 'v=' . $this->version;
            }

        }

        // Volle URL erstellen, wenn nicht extern und noch nicht absolut
        if (!$options['external'] && !str_contains($htmlPath, '://') && !str_starts_with($htmlPath, '//')) {
             $htmlPath = $this->createAssetUrl($htmlPath);
        }


        // HTML generieren
        switch ($type) {
            case 'css':
                $media = !empty($options['media']) ? ' media="' . htmlspecialchars($options['media']) . '"' : '';
                return sprintf(
                    '<link rel="stylesheet" href="%s"%s>' . PHP_EOL,
                    htmlspecialchars($htmlPath, ENT_QUOTES, 'UTF-8'),
                    $media
                );

            case 'js':
                $attributes = [];
                if (!empty($options['defer'])) $attributes[] = 'defer';
                if (!empty($options['async'])) $attributes[] = 'async';
                if (!empty($options['type'])) $attributes[] = 'type="' . htmlspecialchars($options['type']) . '"';
                if (!empty($options['integrity']) && !empty($options['crossorigin'])) {
                    $attributes[] = 'integrity="' . htmlspecialchars($options['integrity']) . '"';
                    $attributes[] = 'crossorigin="' . htmlspecialchars($options['crossorigin']) . '"';
                }
                if (defined('CSP_NONCE') && CSP_NONCE) { // Prüfen ob Nonce existiert und nicht leer ist
                    $attributes[] = 'nonce="' . htmlspecialchars(CSP_NONCE) . '"';
                }
                $attrString = !empty($attributes) ? ' ' . implode(' ', $attributes) : '';

                return sprintf(
                    '<script src="%s"%s></script>' . PHP_EOL,
                    htmlspecialchars($htmlPath, ENT_QUOTES, 'UTF-8'),
                    $attrString
                );
        }

        return ''; // Sollte nicht passieren
    }

    /**
     * Rendert kombinierte Assets.
     * Nutzt die Cache-Klasse für den Inhalt und generiert eine physische Datei.
     */
    private function renderCombinedAssets(string $type, array $assets): string
    {
        if (empty($assets)) {
            return '';
        }

        // 1. Cache-Verzeichnis ermitteln (für die physische Datei)
        $physicalCacheDir = $this->resolveCombinedAssetCacheDir();
        if ($physicalCacheDir === null) {
            // Wenn kein Cache-Verzeichnis verfügbar ist, rendere einzeln als Fallback
            $output = '';
            foreach ($assets as $asset) {
                $output .= $this->renderSingleAsset($type, $asset);
            }
            return $output;
        }

        // 2. Eindeutigen Hash für diese Asset-Kombination erstellen
        $paths = array_column($assets, 'path'); // Nur die Pfade extrahieren
        sort($paths); // Sortieren für Konsistenz
        $optionsSignature = json_encode(array_column($assets, 'options')); // Optionen berücksichtigen? (Minify etc.)
        $hashBase = implode('|', $paths) . $this->version . $optionsSignature;
        $fileHash = md5($hashBase);

        // 3. Dateinamen und Pfade definieren
        $combinedFilename = "combined-{$fileHash}.{$type}";
        $physicalFilePath = $physicalCacheDir . DIRECTORY_SEPARATOR . $combinedFilename;
        // Relativer Pfad für die URL (relativ zum Web-Root)
        $relativeWebPath = 'cache/assets/' . $combinedFilename; // Annahme: cache/assets ist unter Web-Root erreichbar

        // 4. Content-Cache-Schlüssel definieren
        $contentCacheKey = "asset_content_combined_{$type}_{$fileHash}";

        // 5. Prüfen, ob die physische Datei existiert und aktuell ist
        $physicalFileExists = is_file($physicalFilePath);
        $needsRebuild = !$physicalFileExists;

        if ($physicalFileExists) {
            $combinedFileTime = @filemtime($physicalFilePath);
            if ($combinedFileTime === false) {
                 $needsRebuild = true; // Fehler beim Lesen der Zeit
            } else {
                // Prüfe Zeitstempel der Quelldateien
                foreach ($assets as $asset) {
                    if (!$asset['options']['external']) {
                        $sourcePath = $this->resolveAssetPath($asset['path']);
                        if ($sourcePath && is_file($sourcePath)) {
                            $sourceTime = @filemtime($sourcePath);
                            if ($sourceTime === false || $sourceTime > $combinedFileTime) {
                                $needsRebuild = true;
                                break;
                            }
                        } else {
                             // Quelldatei nicht gefunden? -> Neu bauen erzwingen oder Fehler?
                             $needsRebuild = true; // Besser neu bauen
                             if ($this->developmentMode) {
                                 error_log("AssetManager: Quelldatei für Kombinierung nicht gefunden: '{$asset['path']}'");
                             }
                             break;
                        }
                    }
                }
            }
        }

        // 6. Kombinierten Inhalt holen oder generieren
        $combinedContent = null;
        if ($needsRebuild) {
            // Versuche zuerst aus dem Cache zu lesen
            $combinedContent = $this->cache->get($contentCacheKey);

            if ($combinedContent === null) {
                // Cache Miss: Inhalt generieren
                $contentParts = [];
                $hasErrors = false;
                foreach ($assets as $asset) {
                    if (!$asset['options']['external']) {
                        $sourcePath = $this->resolveAssetPath($asset['path']);
                        if ($sourcePath && is_readable($sourcePath)) {
                            $content = file_get_contents($sourcePath);
                            if ($content === false) {
                                 error_log("AssetManager: Konnte Inhalt von Quelldatei nicht lesen: '{$sourcePath}'");
                                 $hasErrors = true;
                                 continue; // Nächstes Asset
                            }

                            // Minifizieren, falls gewünscht
                            if ($asset['options']['minify']) {
                                if ($type === 'css') {
                                    $content = $this->minifyCss($content);
                                } elseif ($type === 'js') {
                                    $content = $this->minifyJs($content);
                                }
                            }

                            // Bei CSS: Pfade korrigieren
                            if ($type === 'css') {
                                $sourceWebDir = dirname($asset['path']); // Relativer Web-Pfad
                                $targetWebDir = dirname($relativeWebPath); // Relativer Web-Pfad
                                $content = $this->fixCssPaths($content, $sourceWebDir, $targetWebDir);
                            }

                            $contentParts[] = "/* Source: {$asset['path']} */\n" . $content;
                        } else {
                             // Fehler wurde schon oben geloggt
                             $hasErrors = true;
                        }
                    }
                } // End foreach assets

                // Nur speichern, wenn keine Fehler aufgetreten sind
                if (!$hasErrors) {
                     $combinedContent = implode("\n\n", $contentParts);
                     // Im Cache speichern
                     $this->cache->set(
                         $contentCacheKey,
                         $combinedContent,
                         Cache::ASSET_TTL, // Lange TTL
                         ['assets', 'assets_combined', 'assets_' . $type]
                     );
                } else {
                     // Wenn Fehler auftraten, nicht cachen und Fallback auf einzelne Assets
                     error_log("AssetManager: Fehler beim Generieren des kombinierten Assets {$type}. Rendere einzeln.");
                     $output = '';
                     foreach ($assets as $asset) {
                         $output .= $this->renderSingleAsset($type, $asset);
                     }
                     return $output;
                }

            } // End if $combinedContent === null (Cache Miss)

            // 7. Physische Datei schreiben (mit dem Inhalt aus Cache oder frisch generiert)
            if ($combinedContent !== null) {
                 // Verwende atomares Schreiben für die physische Datei
                 $tempFile = $physicalCacheDir . DIRECTORY_SEPARATOR . 'comb_' . bin2hex(random_bytes(6)) . '.tmp';
                 if (@file_put_contents($tempFile, $combinedContent, LOCK_EX) !== false) {
                     @chmod($tempFile, 0644);
                     if (!@rename($tempFile, $physicalFilePath)) {
                         @unlink($tempFile);
                         error_log("AssetManager: Fehler beim Umbenennen der kombinierten Asset-Datei: {$physicalFilePath}");
                         // Fallback: Direkt schreiben
                         if (@file_put_contents($physicalFilePath, $combinedContent, LOCK_EX) === false) {
                              error_log("AssetManager: Fallback-Fehler beim Schreiben der kombinierten Asset-Datei: {$physicalFilePath}");
                              // Wenn auch das fehlschlägt, Fallback auf einzelne Assets
                               $output = '';
                               foreach ($assets as $asset) {
                                   $output .= $this->renderSingleAsset($type, $asset);
                               }
                               return $output;
                         }
                     }
                 } else {
                     error_log("AssetManager: Fehler beim Schreiben der temporären kombinierten Asset-Datei: {$tempFile}");
                     if(file_exists($tempFile)) @unlink($tempFile);
                     // Fallback auf einzelne Assets
                     $output = '';
                     foreach ($assets as $asset) {
                         $output .= $this->renderSingleAsset($type, $asset);
                     }
                     return $output;
                 }
            } else {
                 // Sollte nicht passieren, wenn $hasErrors korrekt behandelt wird, aber zur Sicherheit
                 error_log("AssetManager: Kein kombinierter Inhalt zum Schreiben verfügbar für {$type}. Rendere einzeln.");
                 $output = '';
                 foreach ($assets as $asset) {
                     $output .= $this->renderSingleAsset($type, $asset);
                 }
                 return $output;
            }

        } // End if $needsRebuild

        // 8. HTML für die (jetzt existierende) kombinierte physische Datei erzeugen
        // Finde gemeinsame Optionen für das HTML-Tag (z.B. media, defer, async)
        $commonOptions = $this->findCommonOptions($assets);
        $htmlPath = $this->createAssetUrl($relativeWebPath);

        // Version anhängen (an die kombinierte Datei)
         $separator = str_contains($htmlPath, '?') ? '&' : '?';
         $htmlPath .= $separator . 'v=' . $this->version; // Globale Version reicht hier

        switch ($type) {
            case 'css':
                $media = !empty($commonOptions['media']) ? ' media="' . htmlspecialchars($commonOptions['media']) . '"' : '';
                return sprintf(
                    '<link rel="stylesheet" href="%s"%s>' . PHP_EOL,
                     htmlspecialchars($htmlPath, ENT_QUOTES, 'UTF-8'),
                    $media
                );

            case 'js':
                $attributes = [];
                if (!empty($commonOptions['defer'])) $attributes[] = 'defer'; // Nur wenn *alle* defer haben
                if (!empty($commonOptions['async'])) $attributes[] = 'async'; // Nur wenn *alle* async haben
                // 'type' muss bei allen gleich sein, sonst nicht setzen
                if (!empty($commonOptions['type'])) $attributes[] = 'type="' . htmlspecialchars($commonOptions['type']) . '"';
                // Integrity/Crossorigin bei kombinierten Assets nicht sinnvoll
                if (defined('CSP_NONCE') && CSP_NONCE) {
                    $attributes[] = 'nonce="' . htmlspecialchars(CSP_NONCE) . '"';
                }
                $attrString = !empty($attributes) ? ' ' . implode(' ', $attributes) : '';

                return sprintf(
                    '<script src="%s"%s></script>' . PHP_EOL,
                     htmlspecialchars($htmlPath, ENT_QUOTES, 'UTF-8'),
                    $attrString
                );
        }

        return ''; // Sollte nicht passieren
    }


    // findCommonOptions bleibt wie zuvor
    private function findCommonOptions(array $assets): array
    {
        if (empty($assets)) {
            return [];
        }
        $firstAssetOptions = reset($assets)['options'];
        $commonOptions = $firstAssetOptions;

        foreach ($assets as $asset) {
            // Gehe durch die bisher als gemeinsam identifizierten Optionen
            foreach (array_keys($commonOptions) as $key) {
                // Wenn Option im aktuellen Asset fehlt oder anders ist, entferne sie aus commonOptions
                if (!isset($asset['options'][$key]) || $asset['options'][$key] !== $commonOptions[$key]) {
                    unset($commonOptions[$key]);
                }
            }
            // Wenn keine gemeinsamen Optionen mehr übrig sind, abbrechen
            if (empty($commonOptions)) {
                break;
            }
        }
        return $commonOptions;
    }

    // renderGroup bleibt wie zuvor
    public function renderGroup(string $group, string $type = ''): string
    {
        if (!isset($this->assetGroups[$group])) {
            return '';
        }

        $output = '';
        $typesToRender = [];

        if (!empty($type)) {
            if (isset($this->assetGroups[$group][$type])) {
                $typesToRender[$type] = $this->assetGroups[$group][$type];
            }
        } else {
            $typesToRender = $this->assetGroups[$group];
        }

        // Original-Assets sichern
        $originalAssetsBackup = $this->assets;
        $originalHtmlCacheBackup = $this->combinedHtmlCache;

        foreach ($typesToRender as $assetType => $groupAssets) {
             if (!empty($groupAssets)) {
                  // Temporär die globalen Assets für diesen Typ durch die Gruppen-Assets ersetzen
                  $this->assets[$assetType] = $groupAssets;
                  // HTML-Cache für diesen Typ leeren, um Neurenderung zu erzwingen
                  unset($this->combinedHtmlCache[$assetType]);
                  // Rendern
                  $output .= $this->render($assetType);
             }
        }

        // Original-Assets und HTML-Cache wiederherstellen
        $this->assets = $originalAssetsBackup;
        $this->combinedHtmlCache = $originalHtmlCacheBackup;


        return $output;
    }

    // addVersionToPath wird jetzt in renderSingleAsset und renderCombinedAssets gehandhabt
    // private function addVersionToPath(string $path): string { ... } // Entfernt

    // getWebRootPath bleibt wie zuvor (als Fallback)
    private function getWebRootPath(): string
    {
        // Versuche Document Root zu verwenden
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        if ($docRoot && is_dir($docRoot)) {
            return rtrim($docRoot, '/\\');
        }
        // Fallback: Annahme basierend auf dem Skriptpfad (weniger zuverlässig)
        $scriptDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
        // Versuche ein bekanntes Verzeichnis (z.B. 'public', 'htdocs') zu finden
        // Diese Logik ist sehr anwendungsspezifisch und sollte angepasst werden.
        // Einfacher Fallback:
        return $scriptDir;
    }

    // setBaseUrl bleibt wie zuvor
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }

    // setVersion bleibt wie zuvor
    public function setVersion(string $version): self
    {
        $this->version = $version;
        $this->combinedHtmlCache = []; // Lösche HTML-Cache bei Versionsänderung
        // Hinweis: Der Content-Cache wird durch den Key (der die Version enthält) invalidiert.
        return $this;
    }

    // setDevelopmentMode bleibt wie zuvor
    public function setDevelopmentMode(bool $mode): self
    {
        if ($this->developmentMode !== $mode) {
             $this->developmentMode = $mode;
             $this->combinedHtmlCache = []; // Lösche HTML-Cache bei Modusänderung
        }
        return $this;
    }

    // getAllAssets und getAllGroups bleiben wie zuvor
    public function getAllAssets(): array { return $this->assets; }
    public function getAllGroups(): array { return $this->assetGroups; }

    // minifyCss und minifyJs bleiben wie zuvor
    private function minifyCss(string $content): string { /* ... unverändert ... */
        // Entferne Kommentare
        $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);
        // Entferne Tabs, Spaces, Newlines und andere Whitespaces am Anfang/Ende von Zeilen und um {}:;,
        $content = preg_replace('/^\s+/m', '', $content); // Leerzeichen am Zeilenanfang
        $content = preg_replace('/\s+$/m', '', $content); // Leerzeichen am Zeilenende
        $content = preg_replace('/\s*([{}:;,])\s*/', '$1', $content); // Leerzeichen um {}:;,
        $content = preg_replace('/\s+/', ' ', $content); // Mehrere Leerzeichen/Newlines zu einem Leerzeichen
        // Entferne letztes Semikolon in einem Block
        $content = preg_replace('/;}/', '}', $content);
        // Entferne Nullen vor Dezimalpunkten
        $content = preg_replace('/(:|\s)0\.(\d+)/', '$1.$2', $content);
        // Entferne 0px, 0em, etc.
        $content = preg_replace('/(:|\s)0(px|em|ex|%|pt|pc|in|cm|mm|rem|vw|vh|vmin|vmax)/i', '${1}0', $content);
        // Ersetze #rrggbb mit #rgb wenn möglich
        $content = preg_replace('/#([a-f0-9])\1([a-f0-9])\2([a-f0-9])\3/i', '#$1$2$3', $content);

        return trim($content);
     }
    private function minifyJs(string $content): string { /* ... unverändert ... */
        // Eine sehr einfache Minimierung - für Produktion besser externe Bibliotheken verwenden
        // Entferne einzeilige Kommentare (vorsichtig mit URLs in Strings)
        $content = preg_replace('~//.*(?=[\n\r]|\?>)~', '', $content);
        // Entferne mehrzeilige Kommentare
        $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*\/!', '', $content);
         // Entferne überflüssigen Whitespace (vorsichtig, kann Code brechen)
         // Ersetze mehrere Leerzeichen/Tabs/Newlines durch ein einzelnes Leerzeichen
         $content = preg_replace('/\s+/', ' ', $content);
         // Entferne Leerzeichen um bestimmte Operatoren (sehr riskant ohne Parser)
         // $content = preg_replace('/\s*([;,=\{\}\(\)\[\]\+\-\*\/%<>!?:&|^~])\s*/', '$1', $content);
         // Entferne führende/abschließende Leerzeichen
         $content = trim($content);
        return $content;
     }

    // fixCssPaths bleibt strukturell gleich, nutzt aber getRelativePath
    private function fixCssPaths(string $content, string $sourceWebDir, string $targetWebDir): string
    {
        return preg_replace_callback(
            '/url\([\'"]?([^\'")]+)[\'"]?\)/i',
            function($matches) use ($sourceWebDir, $targetWebDir) {
                $url = trim($matches[1]);

                // Ignoriere absolute URLs, data-URLs, # oder leere URLs
                if (empty($url) || str_contains($url, '://') || str_starts_with($url, 'data:') || str_starts_with($url, '#') || str_starts_with($url, '/')) {
                    return $matches[0]; // Behalte Original bei
                }

                try {
                    // Berechne den relativen Pfad vom Zielverzeichnis zum Quellverzeichnis
                    $relPath = $this->getRelativePath($targetWebDir, $sourceWebDir);
                    // Kombiniere den relativen Pfad mit der URL aus der CSS-Datei
                    $newUrl = rtrim($relPath, '/') . '/' . $url;
                    // Normalisiere den Pfad (entferne ../ etc.) - einfache Normalisierung
                    $newUrl = $this->normalizePath($newUrl);

                    return 'url("' . $newUrl . '")';
                } catch (Throwable $e) {
                    if ($this->developmentMode) {
                        error_log("AssetManager: Fehler bei CSS-Pfadkorrektur für '{$url}': " . $e->getMessage());
                    }
                    return $matches[0]; // Behalte Original bei Fehler
                }
            },
            $content
        );
    }

    // getRelativePath bleibt wie zuvor
    private function getRelativePath(string $from, string $to): string
    {
        $from = preg_replace('![\\/]+!', '/', trim($from, '/'));
        $to   = preg_replace('![\\/]+!', '/', trim($to, '/'));

        $fromParts = $from === '' ? [] : explode('/', $from);
        $toParts   = $to === '' ? [] : explode('/', $to);

        $commonPrefixLength = 0;
        $maxLen = min(count($fromParts), count($toParts));
        for ($i = 0; $i < $maxLen; $i++) {
            if ($fromParts[$i] !== $toParts[$i]) {
                break;
            }
            $commonPrefixLength++;
        }

        $upCount = count($fromParts) - $commonPrefixLength;
        $downParts = array_slice($toParts, $commonPrefixLength);

        if ($upCount === 0 && empty($downParts)) {
            return '.'; // Gleiches Verzeichnis
        }

        $up = array_fill(0, $upCount, '..');
        $pathParts = array_merge($up, $downParts);

        return implode('/', $pathParts);
    }

    /**
     * Einfache Pfad-Normalisierung (entfernt . und .. Segmente).
     */
     private function normalizePath(string $path): string {
         $parts = [];
         $path = preg_replace('![\\/]+!', '/', $path); // Vereinheitliche Slashes
         $segments = explode('/', $path);

         foreach ($segments as $segment) {
             if ($segment === '.' || $segment === '') {
                 continue;
             }
             if ($segment === '..') {
                 if (!empty($parts)) {
                      array_pop($parts); // Gehe ein Verzeichnis hoch, wenn möglich
                 }
             } else {
                 $parts[] = $segment;
             }
         }
         // Füge führenden Slash hinzu, wenn der Originalpfad damit begann und der Pfad nicht leer ist
         $prefix = (str_starts_with($path, '/') && !empty($parts)) ? '/' : '';
         return $prefix . implode('/', $parts);
     }


    // getBaseUrl und getVersion bleiben wie zuvor
    public function getBaseUrl(): string { return $this->baseUrl; }
    public function getVersion(): string { return $this->version; }
}