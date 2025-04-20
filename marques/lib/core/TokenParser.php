<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * TokenParser - Zentrale Klasse für die Verwaltung von Template-Tokens
 */
class TokenParser
{
    // Regex-Patterns als Konstanten für bessere Performance
    private const PATTERN_ASSET_DEF = '/\{asset:([a-z]+)(?:\s+([^\}]*))?\}(.*?)\{\/asset\}/s';
    private const PATTERN_VARIABLE = '/\{var:([a-zA-Z0-9_-]+)\}/';
    private const PATTERN_URL = '/\{url:([a-zA-Z0-9_.-]+)(.*?)\}/';
    private const PATTERN_META = '/\{meta\}(.*?)\{\/meta\}/s';
    private const PATTERN_IMAGE_URL = '/\{image:([^\s\}]+)\}/';

    private const PATTERN_RENDER_BLOCK = '/\{render:block:([a-zA-Z0-9_-]+)\}|{block:([a-zA-Z0-9_-]+)\}/';
    private const PATTERN_RENDER_ASSET = '/\{render:assets:([a-z]+)\}|\{assets:([a-z]+)\}/';
    private const PATTERN_RENDER_INLINE_JS = '/\{render:inline:js\}/';
    private const PATTERN_RENDER_INLINE_CSS = '/\{render:inline:css\}/';
    private const PATTERN_RENDER_META = '/\{render:meta\}/';

    
    private array $blocks = [];
    private array $variables = [];
    private array $meta = [];
    private array $metaByKey = [];
    private ?string $currentBlock = null;
    private ?string $templateDir = null;
    private $templateContext = null;
    private AssetManager $assetManager;
    
    // Cache für bereits verarbeitete Block-Templates
    private array $blockTemplateCache = [];
    
    // Cache für gerenderte Inline-Assets
    private array $renderedInlineAssets = [];
    
    // Debug-Modus Flag
    private bool $isDebugMode;
    
    // Cache für aufgelöste Router-Instanz
    private ?object $resolvedRouter = null;
    private bool $routerResolved = false;
    
    /**
     * Konstruktor
     * 
     * @param AssetManager|null $assetManager Asset-Manager-Instanz
     */
    public function __construct(AssetManager $assetManager = null)
    {
        $this->assetManager = $assetManager ?? new AssetManager();
        $this->isDebugMode = defined('MARQUES_DEBUG') && MARQUES_DEBUG;
    }
    
    /**
     * Startet einen neuen Block mit Output-Capturing
     *
     * @param string $name Block-Name
     * @return void
     */
    public function startBlock(string $name): void
    {
        if ($this->currentBlock !== null) {
            // Beende den aktuellen Block zuerst
            $this->endBlock();
        }
        
        $this->currentBlock = $name;
        ob_start();
    }
    
    /**
     * Beendet den aktuellen Block und speichert seinen Inhalt
     *
     * @return void
     */
    public function endBlock(): void
    {
        if ($this->currentBlock === null) {
            return;
        }
        
        $this->blocks[$this->currentBlock] = ob_get_clean();
        $this->currentBlock = null;
    }
    
    /**
     * Setzt einen Block-Inhalt direkt
     *
     * @param string $name Block-Name
     * @param string $content Block-Inhalt
     * @return void
     */
    public function setBlock(string $name, string $content): void
    {
        $this->blocks[$name] = $content;
        // Cache-Eintrag invalidieren, falls vorhanden
        unset($this->blockTemplateCache[$name]);
        // Renderte Inline-Assets zurücksetzen, falls der Block ein Inline-Asset ist
        if ($name === 'inline_js' || $name === 'inline_css') {
            unset($this->renderedInlineAssets[$name === 'inline_js' ? 'js' : 'css']);
        }
    }
    
    /**
     * Fügt Inhalt zu einem bestehenden Block hinzu
     *
     * @param string $name Block-Name
     * @param string $content Hinzuzufügender Inhalt
     * @return void
     */
    public function appendBlock(string $name, string $content): void
    {
        if (isset($this->blocks[$name])) {
            $this->blocks[$name] .= $content;
        } else {
            $this->blocks[$name] = $content;
        }
        
        // Cache-Eintrag invalidieren, falls vorhanden
        unset($this->blockTemplateCache[$name]);
        // Renderte Inline-Assets zurücksetzen, falls der Block ein Inline-Asset ist
        if ($name === 'inline_js' || $name === 'inline_css') {
            unset($this->renderedInlineAssets[$name === 'inline_js' ? 'js' : 'css']);
        }
    }
    
    /**
     * Gibt den Inhalt eines Blocks zurück
     *
     * @param string $name Block-Name
     * @param string $default Standard-Inhalt, falls Block nicht existiert
     * @return string
     */
    public function getBlock(string $name, string $default = ''): string
    {
        return $this->blocks[$name] ?? $default;
    }
    
    /**
     * Prüft, ob ein Block existiert
     *
     * @param string $name Block-Name
     * @return bool
     */
    public function hasBlock(string $name): bool
    {
        return isset($this->blocks[$name]);
    }
    
    /**
     * Setzt eine Variable
     *
     * @param string $name Variablen-Name
     * @param string $value Variablen-Wert
     * @return void
     */
    public function setVariable(string $name, string $value): void
    {
        $this->variables[$name] = $value;
    }
    
    /**
     * Setzt mehrere Variablen auf einmal
     *
     * @param array<string, mixed> $variables Variablen als assoziatives Array
     * @return void
     */
    public function setVariables(array $variables): void
    {
        foreach ($variables as $name => $value) {
            if (is_scalar($value)) {
                $this->variables[$name] = (string)$value;
            }
        }
    }
    
    /**
     * Gibt den Wert einer Variable zurück
     *
     * @param string $name Variablen-Name
     * @param string $default Standard-Wert, falls Variable nicht existiert
     * @return string
     */
    public function getVariable(string $name, string $default = ''): string
    {
        return $this->variables[$name] ?? $default;
    }
    
    /**
     * Fügt ein Meta-Tag hinzu mit Unterstützung für Schlüssel zur Vermeidung von Duplikaten
     *
     * @param array<string, string> $attributes Meta-Tag-Attribute
     * @param string|null $key Optionaler Schlüssel zur Identifikation des Meta-Tags
     * @return void
     */
    public function addMeta(array $attributes, string $key = null): void
    {
        if ($key !== null) {
            // Überschreibe vorhandene Meta-Tags mit gleichem Schlüssel
            $this->metaByKey[$key] = $attributes;
        } else {
            // Füge Meta-Tag ohne Schlüssel hinzu
            $this->meta[] = $attributes;
        }
    }

    /**
     * Entfernt ein Meta-Tag mit einem bestimmten Schlüssel
     *
     * @param string $key Schlüssel des zu entfernenden Meta-Tags
     * @return void
     */
    public function removeMeta(string $key): void
    {
        if (isset($this->metaByKey[$key])) {
            unset($this->metaByKey[$key]);
        }
    }

    /**
     * Prüft, ob ein Meta-Tag mit einem bestimmten Schlüssel existiert
     *
     * @param string $key Schlüssel des Meta-Tags
     * @return bool
     */
    public function hasMeta(string $key): bool
    {
        return isset($this->metaByKey[$key]);
    }
    
    /**
     * Fügt eine CSS-Ressource hinzu
     *
     * @param string $path Pfad zur CSS-Datei
     * @param bool $isExternal Ist es eine externe Ressource?
     * @param array $options Zusätzliche Optionen
     * @return void
     */
    public function addCss(string $path, bool $isExternal = false, array $options = []): void
    {
        $options['external'] = $isExternal;
        $this->assetManager->addCss($path, $isExternal, $options);
    }
    
    /**
     * Fügt eine JavaScript-Ressource hinzu
     *
     * @param string $path Pfad zur JS-Datei
     * @param bool $isExternal Ist es eine externe Ressource?
     * @param bool $defer Defer-Attribut setzen?
     * @param array $options Zusätzliche Optionen
     * @return void
     */
    public function addJs(string $path, bool $isExternal = false, bool $defer = true, array $options = []): void
    {
        $options['external'] = $isExternal;
        $options['defer'] = $defer;
        $this->assetManager->addJs($path, $isExternal, $defer, $options);
    }

    /**
     * Sammelt alle Asset-Definitionen aus einem Template-String
     * ohne sie zu rendern
     *
     * @param string $content Template-Inhalt mit Tokens
     * @return void
     */
    public function collectAssets(string $content): void
    {
        // Debug-Logging
        if ($this->isDebugMode) {
            error_log("🔍 collectAssets wird aufgerufen. Content-Länge: " . strlen($content));
        }
        
        // Schnellprüfung, ob überhaupt Assets enthalten sind
        if (strpos($content, '{asset:') === false) {
            return;
        }
        
        $matches = [];
        $result = preg_match_all(
            self::PATTERN_ASSET_DEF,
            $content,
            $matches,
            PREG_SET_ORDER
        );
        
        if ($this->isDebugMode) {
            error_log("🔍 Gefundene Assets: " . count($matches));
        }
        
        foreach ($matches as $i => $match) {
            $type = $match[1];
            $options = isset($match[2]) ? $this->parseAttributeString($match[2]) : [];
            $assetContent = trim($match[3]);
            
            if ($this->isDebugMode) {
                error_log("🔍 Asset #$i - Typ: $type, Inhalt: " . substr($assetContent, 0, 30) . "...");
            }
            
            // Inline-Assets speziell behandeln
            if (isset($options['inline']) && $options['inline'] === 'true') {
                if ($type === 'js') {
                    $this->blocks['inline_js'] = ($this->blocks['inline_js'] ?? '') . "\n" . $assetContent;
                    // Renderten Cache invalidieren
                    unset($this->renderedInlineAssets['js']);
                    
                    // Type:module für Inline-JS speichern
                    if (isset($options['type']) && $options['type'] === 'module') {
                        $this->blocks['inline_js_type'] = 'module';
                    }
                    
                    if ($this->isDebugMode) error_log("🔍 Inline-JS hinzugefügt" . (isset($options['type']) ? " mit type:" . $options['type'] : ""));
                } elseif ($type === 'css') {
                    $this->blocks['inline_css'] = ($this->blocks['inline_css'] ?? '') . "\n" . $assetContent;
                    // Renderten Cache invalidieren
                    unset($this->renderedInlineAssets['css']);
                    if ($this->isDebugMode) error_log("🔍 Inline-CSS hinzugefügt");
                }
            } else {
                // Normale Asset-Verarbeitung
                if ($type === 'css') {
                    $this->assetManager->addCss($assetContent, isset($options['external']) && $options['external'] === 'true', $options);
                    if ($this->isDebugMode) error_log("🔍 CSS hinzugefügt: $assetContent");
                } elseif ($type === 'js') {
                    $this->assetManager->addJs(
                        $assetContent, 
                        isset($options['external']) && $options['external'] === 'true', 
                        isset($options['defer']) ? $options['defer'] === 'true' : true, 
                        $options
                    );
                    if ($this->isDebugMode) error_log("🔍 JS hinzugefügt: $assetContent" . (isset($options['type']) ? " mit type:" . $options['type'] : ""));
                }
            }
        }
        
        if ($this->isDebugMode) {
            error_log("🔍 collectAssets abgeschlossen");
        }
    }
    
    /**
     * Ersetzt alle Token-Varianten in einem Template-String
     * Optimiert für einen einzigen Durchlauf durch den Content
     *
     * @param string $content Template-Inhalt mit Tokens
     * @return string Verarbeiteter Inhalt mit ersetzten Tokens
     */
    public function parseTokens(string $content): string
    {
        // Vorverarbeitung: Blöcke und Assets sammeln, ohne zu rendern
        $this->collectAssets($content);
        
        // URL-Tokens verarbeiten
        $content = $this->parseUrlTokens($content);

        $content = $this->replaceImageUrlTokens($content);
        
        // Asset-Definitionen aus dem Content entfernen
        $content = preg_replace(self::PATTERN_ASSET_DEF, '', $content);
        
        // Block-Render-Tokens ersetzen
        $content = $this->replaceBlockRenderTokens($content);
        
        // Asset-Render-Tokens ersetzen
        $content = $this->replaceAssetRenderTokens($content);
        
        // Variablen-Tokens ersetzen
        $content = $this->replaceVariableTokens($content);
        
        // Meta-Tokens ersetzen
        $content = $this->replaceMetaTokens($content);

        if (strpos($content, '{render:meta}') !== false) {
            $content = preg_replace(self::PATTERN_RENDER_META, $this->renderMeta(), $content);
        }
        
        // Inline-Assets rendern
        $content = $this->renderInline($content);
        
        return $content;
    }
    
    /**
     * Ersetzt Block-Render-Tokens im Content
     *
     * @param string $content Template-Inhalt
     * @return string Verarbeiteter Inhalt
     */
    private function replaceBlockRenderTokens(string $content): string
    {
        // Schnellprüfung, ob überhaupt Block-Render-Tokens enthalten sind
        if (strpos($content, '{render:block:') === false && strpos($content, '{block:') === false) {
            return $content;
        }
        
        return preg_replace_callback(
            self::PATTERN_RENDER_BLOCK,
            function($matches) {
                $blockName = $matches[1] ?? $matches[2];
                return $this->resolveBlockContent($blockName);
            },
            $content
        );
    }
    
    /**
     * Ersetzt Asset-Render-Tokens im Content
     *
     * @param string $content Template-Inhalt
     * @return string Verarbeiteter Inhalt
     */
    private function replaceAssetRenderTokens(string $content): string
    {
        // Schnellprüfung für Asset-Render-Tokens
        $hasSpecificAssets = strpos($content, '{render:assets:') !== false || strpos($content, '{assets:') !== false;
        
        if (!$hasSpecificAssets) {
            return $content;
        }
        
        // Spezifische Asset-Typen rendern
        return preg_replace_callback(
            self::PATTERN_RENDER_ASSET,
            function($matches) {
                $type = $matches[1] ?? $matches[2];
                return $this->assetManager->render($type);
            },
            $content
        );
    }
    
    /**
     * Ersetzt Variablen-Tokens im Content
     *
     * @param string $content Template-Inhalt
     * @return string Verarbeiteter Inhalt
     */
    private function replaceVariableTokens(string $content): string
    {
        // Schnellprüfung, ob überhaupt Variablen-Tokens enthalten sind
        if (strpos($content, '{var:') === false) {
            return $content;
        }
        
        return preg_replace_callback(
            self::PATTERN_VARIABLE,
            function($matches) {
                $varName = $matches[1];
                return $this->variables[$varName] ?? '';
            },
            $content
        );
    }

    /**
     * Ersetzt Bild-URL-Tokens im Content
     *
     * @param string $content Template-Inhalt
     * @return string Verarbeiteter Inhalt
     */
    private function replaceImageUrlTokens(string $content): string
    {
        // Schnellprüfung, ob überhaupt Bild-URL-Tokens enthalten sind
        if (strpos($content, '{image:') === false) {
            return $content;
        }
        
        return preg_replace_callback(
            self::PATTERN_IMAGE_URL,
            function($matches) {
                $path = $matches[1];
                
                // Prüfen, ob es sich um eine externe URL handelt
                $isExternal = strpos($path, '://') !== false || strpos($path, '//') === 0;
                
                // Wenn nicht extern, füge die Basis-URL hinzu
                if (!$isExternal) {
                    $baseUrl = $this->assetManager->getBaseUrl();
                    if ($baseUrl) {
                        $path = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
                    }
                    
                    // Version anhängen, wenn verfügbar
                    $version = $this->assetManager->getVersion();
                    if ($version) {
                        $separator = (strpos($path, '?') !== false) ? '&' : '?';
                        $path .= $separator . 'v=' . $version;
                    }
                }
                
                return htmlspecialchars($path, ENT_QUOTES);
            },
            $content
        );
    }
    
    /**
     * Ersetzt Meta-Tokens im Content
     *
     * @param string $content Template-Inhalt
     * @return string Verarbeiteter Inhalt
     */
    private function replaceMetaTokens(string $content): string
    {
        // Schnellprüfung, ob überhaupt Meta-Tokens enthalten sind
        if (strpos($content, '{meta}') === false) {
            return $content;
        }
        
        return preg_replace_callback(
            self::PATTERN_META,
            function($matches) {
                // Meta-Tags aus dem Content extrahieren
                $metaContent = $matches[1];
                
                // Parse die Meta-Tags mit einem regulären Ausdruck
                preg_match_all('/<meta\s+(.*?)>/i', $metaContent, $metaMatches);
                
                foreach ($metaMatches[1] as $metaAttributeString) {
                    $attributes = [];
                    preg_match_all('/([a-zA-Z0-9_:.-]+)=("|\')([^\\2]*?)\\2/i', $metaAttributeString, $attrMatches);
                    
                    for ($i = 0; $i < count($attrMatches[0]); $i++) {
                        $name = $attrMatches[1][$i];
                        $value = $attrMatches[3][$i];
                        $attributes[$name] = $value;
                    }
                    
                    if (!empty($attributes)) {
                        // Verwende name oder property als Schlüssel, falls vorhanden
                        $key = $attributes['name'] ?? $attributes['property'] ?? null;
                        if ($key) {
                            $this->metaByKey[$key] = $attributes;
                        } else {
                            $this->meta[] = $attributes;
                        }
                    }
                }
                
                return ''; // Entferne den Meta-Block aus dem Content
            },
            $content
        );
    }
    
    /**
     * Löst Block-Inhalte auf - mit Caching und verbesserter Fehlerbehandlung
     *
     * @param string $blockName Block-Name
     * @return string Block-Inhalt oder Leerer String
     */
    private function resolveBlockContent(string $blockName): string
    {
        // Cache-Check als erste Priorität
        if (isset($this->blockTemplateCache[$blockName])) {
            return $this->blockTemplateCache[$blockName];
        }
        
        // Direkter Block-Inhalt als zweite Priorität
        if (isset($this->blocks[$blockName])) {
            return $this->blocks[$blockName];
        }
        
        // Strikte Validierung des Block-Namens
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $blockName)) {
            return $this->debugInfo("Block '$blockName' hat ungültigen Namen");
        }
        
        // Wenn wir ein Template-Verzeichnis haben, versuche das Block-Template zu laden
        if ($this->templateDir !== null) {
            // Pfad zum Block-Template
            $blockPath = rtrim($this->templateDir, '/') . '/block/' . $blockName . '.phtml';
            
            // Path-Sicherheit mit realpath
            $realBlockPath = realpath($blockPath);
            $realBlocksDir = realpath(rtrim($this->templateDir, '/') . '/block');
            
            if ($realBlockPath && $realBlocksDir && strpos($realBlockPath, $realBlocksDir) === 0 && file_exists($realBlockPath)) {
                $obLevel = ob_get_level();
                try {
                    // Output-Buffering starten
                    ob_start();
                    
                    // Variable-Extraktion für das Template
                    if ($this->templateContext && method_exists($this->templateContext, 'getTemplateVars')) {
                        $vars = $this->templateContext->getTemplateVars();
                        extract($vars, EXTR_SKIP);
                    }
                    
                    // Token-Parser auch im Block-Template verfügbar machen
                    $tokenParser = $this;
                    
                    // Block-Template einbinden
                    include $realBlockPath;
                    
                    // Inhalt speichern
                    $blockContent = ob_get_clean();
                    $this->blocks[$blockName] = $blockContent;
                    $this->blockTemplateCache[$blockName] = $blockContent;
                    
                    // Assets aus dem Block-Inhalt sammeln
                    $this->collectAssets($blockContent);
                    
                    return $blockContent;
                } catch (\Throwable $e) {
                    // Sicherstellen, dass alle Output-Buffer geschlossen werden
                    while (ob_get_level() > $obLevel) {
                        ob_end_clean();
                    }
                    
                    return $this->debugInfo("Fehler beim Laden von Block '$blockName': " . $e->getMessage());
                }
            }
        }
        
        return $this->debugInfo("Block '$blockName' nicht gefunden");
    }
    
    /**
     * Generiert Debug-Info basierend auf Debug-Flag
     */
    private function debugInfo(string $message): string
    {
        return $this->isDebugMode ? "<!-- $message -->" : '';
    }
    
    /**
     * Verarbeitet URL-Tokens: {url:route param="value"}
     */
    private function parseUrlTokens(string $content): string
    {
        // Schnellprüfung, ob überhaupt URL-Tokens enthalten sind
        if (strpos($content, '{url:') === false) {
            return $content;
        }
        
        // Router einmalig auflösen
        $router = $this->getResolvedRouter();
        
        return preg_replace_callback(self::PATTERN_URL, function ($matches) use ($router) {
            $routeName   = $matches[1];
            $paramString = trim($matches[2]);
    
            $params = [];
            if ($paramString !== '') {
                // Neue Regex für attribute:value Format
                preg_match_all(
                    '/([a-zA-Z0-9_]+):([^\s}]+)(?:\s|$)/',
                    $paramString,
                    $paramMatches,
                    PREG_SET_ORDER
                );
    
                foreach ($paramMatches as $param) {
                    $params[$param[1]] = $param[2];
                }
            }
    
            if (!$router) {
                return $this->debugInfo("Ungültiger Router-Kontext für Route: {$routeName}") ?: '#invalid-url';
            }
    
            try {
                $url = $router->generateUrl($routeName, $params);
                return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } catch (\Throwable $e) {
                return $this->debugInfo("Router-Fehler: " . $e->getMessage()) ?: '#invalid-url';
            }
        }, $content);
    }
    
    /**
     * Löst den Router aus dem Template-Kontext auf (einmalig)
     *
     * @return object|null
     */
    private function getResolvedRouter()
    {
        if (!$this->routerResolved) {
            $this->resolvedRouter = null;
            if (
                $this->templateContext &&
                method_exists($this->templateContext, 'getTemplateVars')
            ) {
                $vars = $this->templateContext->getTemplateVars();
                if (isset($vars['router']) && method_exists($vars['router'], 'generateUrl')) {
                    $this->resolvedRouter = $vars['router'];
                }
            }
            $this->routerResolved = true;
        }
        
        return $this->resolvedRouter;
    }
    
    /**
     * Parst einen Attribut-String in ein Array mit optimierter DOMDocument-Methode
     *
     * @param string $attrString Attribut-String im Format name="value" name2="value2"
     * @return array Assoziatives Array mit Attributen
     */
    private function parseAttributeString(string $attrString): array
    {
        // Neue Attribut-Syntax verwenden (attribute:value)
        $attributes = [];
        $pattern = '/([a-zA-Z0-9_-]+):([^\s}]+)(?:\s|$)/';
        
        if (preg_match_all($pattern, $attrString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[$match[1]] = $match[2];
            }
        }
        
        return $attributes;
    }
    
    /**
     * Rendert Inline-JS und CSS mit Caching
     *
     * @param string $content Template-Inhalt
     * @return string Verarbeiteter Inhalt
     */
    public function renderInline(string $content): string
    {
        // Schnellprüfung für Inline-Tokens
        $hasInlineJs = strpos($content, '{render:inline:js}') !== false;
        $hasInlineCss = strpos($content, '{render:inline:css}') !== false;
        
        if (!$hasInlineJs && !$hasInlineCss) {
            return $content;
        }
        
        // Inline-JS rendern mit Caching
        if ($hasInlineJs) {
            $content = preg_replace_callback(
                self::PATTERN_RENDER_INLINE_JS,
                function() {
                    if (!isset($this->renderedInlineAssets['js']) && isset($this->blocks['inline_js'])) {
                        $attributes = '';
                        if (defined('CSP_NONCE')) {
                            $attributes .= ' nonce="' . CSP_NONCE . '"';
                        }
                        
                        // Prüfen auf type:module Option
                        if (isset($this->blocks['inline_js_type']) && $this->blocks['inline_js_type'] === 'module') {
                            $attributes .= ' type="module"';
                        }
                        
                        $this->renderedInlineAssets['js'] = '<script' . $attributes . '>' . 
                                                          $this->blocks['inline_js'] . 
                                                          '</script>';
                    }
                    return $this->renderedInlineAssets['js'] ?? '';
                },
                $content
            );
        }
        
        // Inline-CSS rendern mit Caching
        if ($hasInlineCss) {
            $content = preg_replace_callback(
                self::PATTERN_RENDER_INLINE_CSS,
                function() {
                    if (!isset($this->renderedInlineAssets['css']) && isset($this->blocks['inline_css'])) {
                        $attributes = '';
                        if (defined('CSP_NONCE')) {
                            $attributes .= ' nonce="' . CSP_NONCE . '"';
                        }
                        $this->renderedInlineAssets['css'] = '<style' . $attributes . '>' . 
                                                           $this->blocks['inline_css'] . 
                                                           '</style>';
                    }
                    return $this->renderedInlineAssets['css'] ?? '';
                },
                $content
            );
        }
        
        return $content;
    }

    private function renderMeta(): string
    {
        $output = '';
        
        // Zuerst die Meta-Tags ohne Schlüssel ausgeben
        foreach ($this->meta as $meta) {
            $output .= $this->renderSingleMetaTag($meta);
        }
        
        // Dann die Meta-Tags mit Schlüssel ausgeben
        foreach ($this->metaByKey as $key => $meta) {
            $output .= $this->renderSingleMetaTag($meta);
        }
        
        return $output;
    }

    /**
     * Rendert ein einzelnes Meta-Tag
     *
     * @param array $attributes Meta-Tag-Attribute
     * @return string HTML für ein Meta-Tag
     */
    private function renderSingleMetaTag(array $attributes): string
    {
        $output = '<meta';
        foreach ($attributes as $attr => $value) {
            $output .= ' ' . $attr . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        }
        $output .= '>' . PHP_EOL;
        return $output;
    }
    
    /**
     * Setzt das Template-Verzeichnis
     */
    public function setTemplateDir(string $dir): void
    {
        $this->templateDir = $dir;
    }
    
    /**
     * Setzt den Template-Kontext
     */
    public function setTemplateContext($template): void
    {
        $this->templateContext = $template;
        $this->routerResolved = false; // Router muss neu aufgelöst werden
    }
    
    /**
     * Gibt den Asset-Manager zurück
     */
    public function getAssetManager(): AssetManager
    {
        return $this->assetManager;
    }
    
    /**
     * Setzt den Asset-Manager
     */
    public function setAssetManager(AssetManager $assetManager): void
    {
        $this->assetManager = $assetManager;
    }
    
    /**
     * Gibt alle registrierten Blöcke zurück
     */
    public function getAllBlocks(): array
    {
        return $this->blocks;
    }
    
    /**
     * Gibt alle registrierten Variablen zurück
     */
    public function getAllVariables(): array
    {
        return $this->variables;
    }
    
    /**
     * Gibt alle registrierten Meta-Tags zurück
     */
    public function getAllMeta(): array
    {
        return [
            'regular' => $this->meta,
            'keyed' => $this->metaByKey
        ];
    }
    
    /**
     * Leert alle Caches
     */
    public function clearCache(): void
    {
        $this->blockTemplateCache = [];
        $this->renderedInlineAssets = [];
        $this->routerResolved = false;
    }
    
    // Die folgenden Methoden bleiben für Kompatibilität erhalten, nutzen aber die optimierte Implementierung
    
    public function parseBlockRenderTokens(string $content): string
    {
        return $this->replaceBlockRenderTokens($content);
    }
    
    public function renderAssets(string $content): string
    {
        $content = $this->replaceAssetRenderTokens($content);
        $content = $this->renderInline($content);
        return $content;
    }
    
    // Diese Methoden bleiben für Kompatibilität erhalten, sind aber intern vereinfacht
    public function parseAssetDefinitions(string $content): string { 
        $this->collectAssets($content); 
        return $content; 
    }
    
    public function parseVariableTokens(string $content): string { 
        return $this->replaceVariableTokens($content); 
    }
    
    public function parseMetaTokens(string $content): string { 
        return $this->replaceMetaTokens($content); 
    }
}