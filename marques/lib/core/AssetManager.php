<?php
declare(strict_types=1);

namespace Marques\Core;

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
    
    // Cache für kombinierte Assets
    private array $combinedCache = [];
    
    /**
     * Konstruktor
     * 
     * @param string $baseUrl Basis-URL für Assets
     * @param string $version Versionsnummer für Cache-Busting
     * @param bool $developmentMode Im Entwicklungsmodus deaktivierte Optimierungen
     */
    public function __construct(string $baseUrl = '', string $version = '', bool $developmentMode = false)
    {
        $this->baseUrl = $baseUrl;
        $this->developmentMode = $developmentMode;
        
        if (!empty($version)) {
            $this->version = $version;
        }
    }
    
    /**
     * Fügt ein Asset hinzu
     * 
     * @param string $type Asset-Typ (css, js)
     * @param string $path Pfad zum Asset
     * @param array $options Zusätzliche Optionen
     * @return self
     */
    public function add(string $type, string $path, array $options = []): self
    {
        $key = $options['group'] ?? $path;
        
        // Standardoptionen
        $defaultOptions = [
            'external' => false,
            'defer' => ($type === 'js'),
            'async' => false,
            'version' => true,
            'media' => 'all',
            'priority' => 10,
            'minify' => !$this->developmentMode,  // Standard: minifizieren in Produktion
            'combine' => !$this->developmentMode  // Standard: kombinieren in Produktion
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        // Füge Asset zum entsprechenden Typ hinzu
        $this->assets[$type][$key] = [
            'path' => $path,
            'options' => $options
        ];
        
        // Wenn eine Gruppe angegeben ist, füge es dort ebenfalls hinzu
        if (!empty($options['group'])) {
            $this->assetGroups[$options['group']][$type][$key] = [
                'path' => $path,
                'options' => $options
            ];
        }
        
        // Lösche den Kombinierungs-Cache für diesen Typ
        if (isset($this->combinedCache[$type])) {
            unset($this->combinedCache[$type]);
        }
        
        return $this;
    }
    
    /**
     * Fügt ein CSS-Asset hinzu
     * 
     * @param string $path Pfad zur CSS-Datei
     * @param bool $isExternal Ist es eine externe Ressource?
     * @param array $options Zusätzliche Optionen
     * @return self
     */
    public function addCss(string $path, bool $isExternal = false, array $options = []): self
    {
        $options['external'] = $isExternal;
        return $this->add('css', $path, $options);
    }
    
    /**
     * Fügt ein JavaScript-Asset hinzu
     * 
     * @param string $path Pfad zur JS-Datei
     * @param bool $isExternal Ist es eine externe Ressource?
     * @param bool $defer Defer-Attribut setzen?
     * @param array $options Zusätzliche Optionen
     * @return self
     */
    public function addJs(string $path, bool $isExternal = false, bool $defer = true, array $options = []): self
    {
        $options['external'] = $isExternal;
        $options['defer'] = $defer;
        return $this->add('js', $path, $options);
    }
    
    /**
     * Generiert HTML für alle Assets eines bestimmten Typs
     * 
     * @param string $type Asset-Typ (css, js)
     * @return string Generiertes HTML
     */
    public function render(string $type): string
    {
        if (!isset($this->assets[$type])) {
            return '';
        }
        
        // Prüfen, ob wir Assets kombinieren können
        $canCombine = !$this->developmentMode;
        
        // Sortiere Assets nach Priorität
        $assets = $this->assets[$type];
        uasort($assets, function($a, $b) {
            return ($a['options']['priority'] ?? 10) - ($b['options']['priority'] ?? 10);
        });
        
        // Trennen in externe und interne Assets
        $externalAssets = [];
        $internalAssets = [];
        
        foreach ($assets as $key => $asset) {
            if ($asset['options']['external'] || !$asset['options']['combine']) {
                $externalAssets[$key] = $asset;
            } else {
                $internalAssets[$key] = $asset;
            }
        }
        
        $output = '';
        
        // Kombiniere interne Assets, wenn möglich
        if ($canCombine && !empty($internalAssets)) {
            $output .= $this->renderCombinedAssets($type, $internalAssets);
        } else {
            // Rendere jedes interne Asset einzeln
            foreach ($internalAssets as $asset) {
                $output .= $this->renderSingleAsset($type, $asset);
            }
        }
        
        // Rendere externe Assets immer einzeln
        foreach ($externalAssets as $asset) {
            $output .= $this->renderSingleAsset($type, $asset);
        }
        
        return $output;
    }
    
    /**
     * Rendert ein einzelnes Asset
     * 
     * @param string $type Asset-Typ
     * @param array $asset Asset-Daten
     * @return string HTML-Ausgabe
     */
    private function renderSingleAsset(string $type, array $asset): string
    {
        $path = $asset['path'];
        $options = $asset['options'];
        
        // Versionierung hinzufügen, wenn aktiviert und nicht extern
        if (!$options['external'] && $options['version'] && !empty($this->version)) {
            $path = $this->addVersionToPath($path);
        }
        
        // Volle URL erstellen, wenn nicht extern und keine absolute URL
        if (!$options['external'] && strpos($path, '://') === false && strpos($path, '//') !== 0) {
            // Normalisiere den Pfad, um doppelte Präfixe zu vermeiden
            $baseUrl = rtrim($this->baseUrl, '/');
            $path = '/' . ltrim($path, '/');
            
            // Prüfe, ob der Pfad bereits mit baseUrl beginnt
            if (!empty($baseUrl) && strpos($path, $baseUrl) !== 0) {
                $path = $baseUrl . $path;
            }
        }
        
        $output = '';
        
        switch ($type) {
            case 'css':
                $media = isset($options['media']) ? ' media="' . $options['media'] . '"' : '';
                $output .= sprintf(
                    '<link rel="stylesheet" href="%s"%s>' . PHP_EOL,
                    $path,
                    $media
                );
                break;
                
            case 'js':
                $attributes = '';
                if (!empty($options['defer']) && $options['defer']) {
                    $attributes .= ' defer';
                }
                if (!empty($options['async']) && $options['async']) {
                    $attributes .= ' async';
                }
                if (!empty($options['type'])) {
                    $attributes .= ' type="' . $options['type'] . '"';
                }
                if (isset($options['integrity']) && isset($options['crossorigin'])) {
                    $attributes .= ' integrity="' . $options['integrity'] . '" crossorigin="' . $options['crossorigin'] . '"';
                }
                
                // CSP Nonce hinzufügen, wenn definiert
                if (defined('CSP_NONCE')) {
                    $attributes .= ' nonce="' . CSP_NONCE . '"';
                }
                
                $output .= sprintf(
                    '<script src="%s"%s></script>' . PHP_EOL,
                    $path,
                    $attributes
                );
                break;
        }
        
        return $output;
    }
    
    /**
     * Rendert kombinierte Assets
     * 
     * @param string $type Asset-Typ
     * @param array $assets Assets zum Kombinieren
     * @return string HTML-Ausgabe
     */
    private function renderCombinedAssets(string $type, array $assets): string
    {
        if (empty($assets)) {
            return '';
        }
        
        // Wenn wir bereits einen Cache für diesen Typ haben
        if (isset($this->combinedCache[$type])) {
            return $this->combinedCache[$type];
        }
        
        // Finde gemeinsame Optionen
        $commonOptions = $this->findCommonOptions($assets);
        
        // Erstelle einen eindeutigen Dateinamen basierend auf den kombinierten Pfaden und der Version
        $paths = array_column(array_column($assets, 'path'), 'path');
        $filenameBase = 'combined-' . md5(implode('|', $paths) . $this->version);
        
        $combinedFilename = $filenameBase . '.' . $type;
        $combinedPath = 'cache/' . $combinedFilename;
        $fullPath = rtrim($this->baseUrl, '/') . '/' . ltrim($combinedPath, '/');
        
        // Prüfe, ob die kombinierte Datei existiert
        $webRootPath = $this->getWebRootPath();
        $physicalPath = $webRootPath . '/' . $combinedPath;
        
        // Cache-Verzeichnis erstellen, falls es nicht existiert
        $cacheDir = dirname($physicalPath);
        if (!is_dir($cacheDir) && is_writable(dirname($cacheDir))) {
            mkdir($cacheDir, 0755, true);
        }
        
        // Prüfen, ob wir eine neue kombinierte Datei erstellen müssen
        $needsRebuild = !file_exists($physicalPath);
        
        if (!$needsRebuild) {
            // Prüfen, ob eine der Quelldateien neuer ist als die kombinierte Datei
            $combinedTime = filemtime($physicalPath);
            
            foreach ($assets as $asset) {
                if (!$asset['options']['external']) {
                    $assetPath = $webRootPath . '/' . ltrim($asset['path'], '/');
                    if (file_exists($assetPath) && filemtime($assetPath) > $combinedTime) {
                        $needsRebuild = true;
                        break;
                    }
                }
            }
        }
        
        // Kombinierte Datei erstellen, falls nötig
        if ($needsRebuild && is_writable($cacheDir)) {
            $combinedContent = '';
            
            foreach ($assets as $asset) {
                if (!$asset['options']['external']) {
                    $assetPath = $webRootPath . '/' . ltrim($asset['path'], '/');
                    
                    if (file_exists($assetPath)) {
                        $content = file_get_contents($assetPath);
                        
                        // Minifizieren, falls gewünscht
                        if ($asset['options']['minify']) {
                            if ($type === 'css') {
                                $content = $this->minifyCss($content);
                            } else if ($type === 'js') {
                                $content = $this->minifyJs($content);
                            }
                        }
                        
                        // Bei CSS: Pfade korrigieren
                        if ($type === 'css') {
                            $assetDir = dirname($asset['path']);
                            $content = $this->fixCssPaths($content, $assetDir, 'cache');
                        }
                        
                        $combinedContent .= "/* {$asset['path']} */\n" . $content . "\n\n";
                    }
                }
            }
            
            // Speichern der kombinierten Datei
            file_put_contents($physicalPath, $combinedContent);
        }
        
        // HTML für die kombinierte Datei erzeugen
        $output = '';
    
        if ($type === 'css') {
            $media = isset($commonOptions['media']) ? ' media="' . $commonOptions['media'] . '"' : '';
            $output = sprintf(
                '<link rel="stylesheet" href="%s"%s>' . PHP_EOL,
                $fullPath,
                $media
            );
        } else if ($type === 'js') {
            $attributes = '';
            if (!empty($commonOptions['defer']) && $commonOptions['defer']) {
                $attributes .= ' defer';
            }
            if (!empty($commonOptions['async']) && $commonOptions['async']) {
                $attributes .= ' async';
            }
            
            // CSP Nonce hinzufügen, wenn definiert
            if (defined('CSP_NONCE')) {
                $attributes .= ' nonce="' . CSP_NONCE . '"';
            }
            
            $output = sprintf(
                '<script src="%s"%s></script>' . PHP_EOL,
                $fullPath,
                $attributes
            );
        }
        
        // Cache für zukünftige Aufrufe
        $this->combinedCache[$type] = $output;
        
        return $output;
    }
    
    /**
     * Findet gemeinsame Optionen in einer Sammlung von Assets
     * 
     * @param array $assets Assets-Array
     * @return array Gemeinsame Optionen
     */
    private function findCommonOptions(array $assets): array
    {
        if (empty($assets)) {
            return [];
        }
        
        // Beginne mit den Optionen des ersten Assets
        $commonOptions = reset($assets)['options'];
        
        // Filtere Optionen, die über alle Assets gleich sind
        foreach ($assets as $asset) {
            foreach ($commonOptions as $key => $value) {
                if (!isset($asset['options'][$key]) || $asset['options'][$key] !== $value) {
                    unset($commonOptions[$key]);
                }
            }
        }
        
        return $commonOptions;
    }
    
    /**
     * Generiert HTML für eine Gruppe von Assets
     * 
     * @param string $group Gruppenname
     * @param string $type Asset-Typ (optional, wenn alle Typen gerendert werden sollen)
     * @return string Generiertes HTML
     */
    public function renderGroup(string $group, string $type = ''): string
    {
        if (!isset($this->assetGroups[$group])) {
            return '';
        }
        
        $output = '';
        
        if (!empty($type)) {
            // Einen spezifischen Typ rendern
            if (isset($this->assetGroups[$group][$type])) {
                $groupAssets = $this->assetGroups[$group][$type];
                
                // Temporär die Assets ersetzen
                $originalAssets = $this->assets[$type] ?? [];
                $this->assets[$type] = $groupAssets;
                
                // Rendern
                $output = $this->render($type);
                
                // Original-Assets wiederherstellen
                $this->assets[$type] = $originalAssets;
            }
        } else {
            // Alle Typen in der Gruppe rendern
            foreach ($this->assetGroups[$group] as $assetType => $groupAssets) {
                // Temporär die Assets ersetzen
                $originalAssets = $this->assets[$assetType] ?? [];
                $this->assets[$assetType] = $groupAssets;
                
                // Rendern
                $output .= $this->render($assetType);
                
                // Original-Assets wiederherstellen
                $this->assets[$assetType] = $originalAssets;
            }
        }
        
        return $output;
    }
    
    /**
     * Fügt eine Versionsnummer zu einem Pfad hinzu
     * 
     * @param string $path Asset-Pfad
     * @return string Versionierter Pfad
     */
    private function addVersionToPath(string $path): string
    {
        $separator = (strpos($path, '?') !== false) ? '&' : '?';
        return $path . $separator . 'v=' . $this->version;
    }
    
    /**
     * Gibt den Webroot-Pfad zurück
     * 
     * @return string Absoluter Pfad zum Webroot
     */
    private function getWebRootPath(): string
    {
        // Implementierung abhängig von deinem System
        // Beispiel:
        return defined('DOCUMENT_ROOT') ? DOCUMENT_ROOT : $_SERVER['DOCUMENT_ROOT'];
    }
    
    /**
     * Setzt die Basis-URL für Assets
     * 
     * @param string $baseUrl Basis-URL
     * @return self
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }
    
    /**
     * Setzt die Versionsnummer für Cache-Busting
     * 
     * @param string $version Versionsnummer
     * @return self
     */
    public function setVersion(string $version): self
    {
        $this->version = $version;
        $this->combinedCache = []; // Lösche Cache bei Versionsänderung
        return $this;
    }
    
    /**
     * Setzt den Entwicklungsmodus
     * 
     * @param bool $mode Entwicklungsmodus aktivieren/deaktivieren
     * @return self
     */
    public function setDevelopmentMode(bool $mode): self
    {
        $this->developmentMode = $mode;
        $this->combinedCache = []; // Lösche Cache bei Modusänderung
        return $this;
    }
    
    /**
     * Gibt alle registrierten Assets zurück
     * 
     * @return array
     */
    public function getAllAssets(): array
    {
        return $this->assets;
    }
    
    /**
     * Gibt alle registrierten Asset-Gruppen zurück
     * 
     * @return array
     */
    public function getAllGroups(): array
    {
        return $this->assetGroups;
    }
    
    /**
     * Minimiert CSS-Inhalt
     * 
     * @param string $content CSS-Inhalt
     * @return string Minimiertes CSS
     */
    private function minifyCss(string $content): string
    {
        // Entferne Kommentare
        $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);
        // Entferne Tabs, Spaces, Newlines und andere Whitespaces
        $content = preg_replace('/\s+/', ' ', $content);
        // Entferne unnötige Whitespaces um Operatoren
        $content = preg_replace('/\s*([{}:;,])\s*/', '$1', $content);
        // Entferne führende und abschließende Whitespaces
        $content = preg_replace('/\s*\/\*\s*/', '/*', $content);
        $content = preg_replace('/\s*\*\/\s*/', '*/', $content);
        // Entferne Nullen vor Dezimalpunkten
        $content = preg_replace('/(:|\s)0\.(\d+)/', '$1.$2', $content);
        // Entferne 0px, 0em, etc.
        $content = preg_replace('/(:|\s)0(px|em|ex|pt|pc|cm|mm|in|%)/i', '${1}0', $content);
        // Ersetze #ffffff mit #fff
        $content = preg_replace('/#([a-f0-9])\1([a-f0-9])\2([a-f0-9])\3/i', '#$1$2$3', $content);
        
        return trim($content);
    }
    
    /**
     * Minimiert JavaScript-Inhalt
     * 
     * @param string $content JavaScript-Inhalt
     * @return string Minimiertes JavaScript
     */
    private function minifyJs(string $content): string
    {
        // Eine einfache Minimierung - für Produktion besser externe Bibliotheken verwenden
        // Entferne Kommentare
        $content = preg_replace('/(?:\/\*(?:[\s\S]*?)\*\/)|(?:\/\/.*)/', '', $content);
        // Entferne Whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        // Entferne Whitespace vor/nach Operatoren
        $content = preg_replace('/\s*([=\+\-\*\/\(\)\{\}\[\];:,<>])\s*/', '$1', $content);
        
        return trim($content);
    }
    
    /**
     * Korrigiert relative Pfade in CSS-Dateien
     * 
     * @param string $content CSS-Inhalt
     * @param string $originalDir Originaler Verzeichnispfad
     * @param string $newDir Neuer Verzeichnispfad
     * @return string CSS mit korrigierten Pfaden
     */
    private function fixCssPaths(string $content, string $originalDir, string $newDir): string
    {
        // Relative Pfade in url() finden und anpassen
        return preg_replace_callback(
            '/url\([\'"]?([^\'")]+)[\'"]?\)/i',
            function($matches) use ($originalDir, $newDir) {
                $url = $matches[1];
                
                // Ignoriere absolute URLs, data-URLs oder URLs mit Protokoll
                if (strpos($url, '://') !== false || strpos($url, 'data:') === 0 || strpos($url, '#') === 0) {
                    return $matches[0];
                }
                
                // Berechne den relativen Pfad vom neuen zum alten Verzeichnis
                $relPath = $this->getRelativePath($newDir, $originalDir);
                
                // Kombiniere den relativen Pfad mit der URL
                $newUrl = rtrim($relPath, '/') . '/' . ltrim($url, '/');
                
                return 'url("' . $newUrl . '")';
            },
            $content
        );
    }
    
    /**
     * Berechnet einen relativen Pfad zwischen zwei Verzeichnissen
     * 
     * @param string $from Quellverzeichnis
     * @param string $to Zielverzeichnis
     * @return string Relativer Pfad
     */
    private function getRelativePath(string $from, string $to): string
    {
        // Normalisiere Verzeichnispfade
        $from = explode('/', trim($from, '/'));
        $to = explode('/', trim($to, '/'));
        
        // Entferne gemeinsame Teile
        $length = min(count($from), count($to));
        for ($i = 0; $i < $length; $i++) {
            if ($from[$i] !== $to[$i]) {
                break;
            }
        }
        
        // Baue relativen Pfad auf
        $up = array_fill(0, count($from) - $i, '..');
        $down = array_slice($to, $i);
        
        $path = array_merge($up, $down);
        
        return implode('/', $path);
    }

    /**
     * Gibt die Basis-URL zurück
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Gibt die aktuelle Version zurück
     */
    public function getVersion(): string
    {
        return $this->version;
    }
}