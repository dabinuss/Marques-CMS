<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * TokenParser - Zentrale Klasse für die Verwaltung von Template-Tokens
 */
class TokenParser
{
    private array $blocks = [];
    private array $variables = [];
    private array $meta = [];

    private ?string $currentBlock = null;
    private ?string $templateDir = null;
    private $templateContext = null;
    private AssetManager $assetManager;
    
    /**
     * Konstruktor
     * 
     * @param AssetManager|null $assetManager Asset-Manager-Instanz
     */
    public function __construct(AssetManager $assetManager = null)
    {
        $this->assetManager = $assetManager ?? new AssetManager();
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
     * @param array<string, string> $variables Variablen als assoziatives Array
     * @return void
     */
    public function setVariables(array $variables): void
    {
        foreach ($variables as $name => $value) {
            if (is_string($value) || is_numeric($value)) {
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
     * Fügt ein Meta-Tag hinzu
     *
     * @param array<string, string> $attributes Meta-Tag-Attribute
     * @return void
     */
    public function addMeta(array $attributes): void
    {
        $this->meta[] = $attributes;
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
     * @return string Inhalt ohne Asset-Definitionen
     */
    public function collectAssets(string $content): void
    {
        error_log("🔍 collectAssets wird aufgerufen. Content-Länge: " . strlen($content));
        
        $matches = [];
        $result = preg_match_all(
            '/\{asset:([a-z]+)(?:\s+([^\}]*))?\}(.*?)\{\/asset\}/s',
            $content,
            $matches,
            PREG_SET_ORDER
        );
        
        error_log("🔍 Gefundene Assets: " . count($matches));
        
        foreach ($matches as $i => $match) {
            $type = $match[1];
            $options = isset($match[2]) ? $this->parseAttributeString($match[2]) : [];
            $assetContent = trim($match[3]);
            
            error_log("🔍 Asset #$i - Typ: $type, Inhalt: " . substr($assetContent, 0, 30) . "...");
            
            // Inline-Assets speziell behandeln
            if (isset($options['inline']) && $options['inline'] === 'true') {
                if ($type === 'js') {
                    $this->blocks['inline_js'] = ($this->blocks['inline_js'] ?? '') . "\n" . $assetContent;
                    error_log("🔍 Inline-JS hinzugefügt");
                } elseif ($type === 'css') {
                    $this->blocks['inline_css'] = ($this->blocks['inline_css'] ?? '') . "\n" . $assetContent;
                    error_log("🔍 Inline-CSS hinzugefügt");
                }
            } else {
                // Normale Asset-Verarbeitung
                if ($type === 'css') {
                    $this->assetManager->addCss($assetContent, isset($options['external']) && $options['external'] === 'true', $options);
                    error_log("🔍 CSS hinzugefügt: $assetContent");
                } elseif ($type === 'js') {
                    $this->assetManager->addJs(
                        $assetContent, 
                        isset($options['external']) && $options['external'] === 'true', 
                        isset($options['defer']) ? $options['defer'] === 'true' : true, 
                        $options
                    );
                    error_log("🔍 JS hinzugefügt: $assetContent");
                }
            }
        }
        
        error_log("🔍 collectAssets abgeschlossen");
    }
    
    /**
     * Ersetzt alle Token-Varianten in einem Template-String
     *
     * @param string $content Template-Inhalt mit Tokens
     * @return string Verarbeiteter Inhalt mit ersetzten Tokens
     */
public function parseTokens(string $content): string
{
    $content = $this->parseUrlTokens($content);
    $content = $this->parseAssetDefinitions($content);
    $content = $this->parseBlockDefinitions($content);
    $content = $this->parseRenderTokens($content);
    $content = $this->parseVariableTokens($content);
    $content = $this->parseMetaTokens($content);
    $content = $this->parseResourceTokensWithoutRendering($content);
    $content = $this->renderInline($content);
    
    return $content;
}

    /**
     * Verarbeitet nur Block-Render-Tokens: {render:block:name}
     */
    private function parseBlockRenderTokens(string $content): string
    {
        // Blöcke rendern: {render:block:name}
        $content = preg_replace_callback(
            '/\{render:block:([a-zA-Z0-9_-]+)\}/',
            function($matches) {
                $blockName = $matches[1];
                
                if (isset($this->blocks[$blockName])) {
                    return $this->blocks[$blockName];
                }
                
                // Versuche, ein Block-Template zu laden
                if ($this->templateDir !== null) {
                    try {
                        $blockPath = rtrim($this->templateDir, '/') . '/block/' . $blockName . '.phtml';
                        
                        // Sicherheitsprüfungen...
                        $realBlockPath = realpath($blockPath);
                        $realBlocksDir = realpath(rtrim($this->templateDir, '/') . '/block');
                        
                        if ($realBlockPath && $realBlocksDir && strpos($realBlockPath, $realBlocksDir) === 0 && file_exists($realBlockPath)) {
                            ob_start();
                            
                            // Variable-Extraktion...
                            if ($this->templateContext && method_exists($this->templateContext, 'getTemplateVars')) {
                                $vars = $this->templateContext->getTemplateVars();
                                extract($vars, EXTR_SKIP);
                            }
                            
                            // Block-Template einbinden
                            include $realBlockPath;
                            
                            // Inhalt speichern
                            $blockContent = ob_get_clean();
                            $this->blocks[$blockName] = $blockContent;
                            
                            // Sammle Assets aus dem Block-Inhalt
                            $this->collectAssets($blockContent);
                            
                            return $blockContent;
                        }
                    } catch (\Exception $e) {
                        if (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                    }
                }
                
                // Debug-Info im Entwicklungsmodus
                if (defined('MARQUES_DEBUG') && MARQUES_DEBUG) {
                    return "<!-- Block '$blockName' nicht gefunden -->";
                }
                
                return '';
            },
            $content
        );
        
        // Abwärtskompatibilität: {block:name}
        $content = preg_replace_callback(
            '/\{block:([a-zA-Z0-9_-]+)\}/',
            function($matches) {
                $blockName = $matches[1];
                
                if (isset($this->blocks[$blockName])) {
                    return $this->blocks[$blockName];
                }
                
                // Rest wie oben...
                return '';
            },
            $content
        );
        
        return $content;
    }

    /**
     * Verarbeitet alte Ressourcen-Tokens für Abwärtskompatibilität,
     * ohne das tatsächliche Rendering durchzuführen
     */
    private function parseResourceTokensWithoutRendering(string $content): string
    {
        // Alte {src:css}...{src:end} Format entfernen
        $content = preg_replace('/\{src:css\}(.*?)\{src:end\}/s', '', $content);
        
        // Alte {src:js}...{src:end} Format entfernen
        $content = preg_replace('/\{src:js\}(.*?)\{src:end\}/s', '', $content);
        
        // Alte {src:meta}...{src:end} Format durch Meta-Tags ersetzen
        $content = preg_replace_callback('/\{src:meta\}(.*?)\{src:end\}/s', function() {
            $output = '';
            foreach ($this->meta as $meta) {
                $output .= '<meta';
                foreach ($meta as $attr => $value) {
                    $output .= ' ' . $attr . '="' . $value . '"';
                }
                $output .= '>' . PHP_EOL;
            }
            return $output;
        }, $content);
        
        return $content;
    }

    /**
     * Rendert nur Asset-Rendering-Tokens
     */
    public function renderAssets(string $content): string
    {
        // 1. Assets rendern: {render:assets:type}
        $content = preg_replace_callback(
            '/\{render:assets:([a-z]+)\}/',
            function($matches) {
                $type = $matches[1];
                return $this->assetManager->render($type);
            },
            $content
        );
        
        // 2. Alle Assets rendern: {render:assets}
        $content = preg_replace_callback(
            '/\{render:assets\}/',
            function() {
                return $this->assetManager->render('css') . $this->assetManager->render('js');
            },
            $content
        );
        
        // 3. Inline-Assets rendern
        $content = $this->renderInlineAssets($content);
        
        // 4. Abwärtskompatibilität: {assets:type}
        $content = preg_replace_callback(
            '/\{assets:([a-z]+)\}/',
            function($matches) {
                $type = $matches[1];
                return $this->assetManager->render($type);
            },
            $content
        );
        
        return $content;
    }

    /**
     * Rendert Inline-Assets in einem Template-String
     *
     * @param string $content Template-Inhalt mit Inline-Tokens
     * @return string Verarbeiteter Inhalt
     */
    public function renderInline(string $content): string
    {
        // Inline-JS rendern
        $content = preg_replace_callback(
            '/\{render:inline:js\}/',
            function() {
                if (isset($this->blocks['inline_js'])) {
                    $attributes = '';
                    // CSP Nonce hinzufügen, wenn vorhanden
                    if (defined('CSP_NONCE')) {
                        $attributes .= ' nonce="' . CSP_NONCE . '"';
                    }
                    return '<script' . $attributes . '>' . $this->blocks['inline_js'] . '</script>';
                }
                return '';
            },
            $content
        );
        
        // Inline-CSS rendern
        $content = preg_replace_callback(
            '/\{render:inline:css\}/',
            function() {
                if (isset($this->blocks['inline_css'])) {
                    $attributes = '';
                    // CSP Nonce hinzufügen, wenn vorhanden
                    if (defined('CSP_NONCE')) {
                        $attributes .= ' nonce="' . CSP_NONCE . '"';
                    }
                    return '<style' . $attributes . '>' . $this->blocks['inline_css'] . '</style>';
                }
                return '';
            },
            $content
        );
        
        return $content;
    }

    /**
     * Verarbeitet Asset-Definitionen: {asset:type}...{/asset}
     */
    private function parseAssetDefinitions(string $content): string
    {
        return preg_replace_callback(
            '/\{asset:([a-z]+)(?:\s+([^\}]*))?\}(.*?)\{\/asset\}/s',
            function($matches) {
                $type = $matches[1];
                $options = isset($matches[2]) ? $this->parseAttributeString($matches[2]) : [];
                $content = trim($matches[3]);
                
                // Inline-Assets speziell behandeln
                if (isset($options['inline']) && $options['inline'] === 'true') {
                    if ($type === 'js') {
                        // Speichere in einem speziellen Block für Inline-JS
                        $this->blocks['inline_js'] = ($this->blocks['inline_js'] ?? '') . "\n" . $content;
                        return '';
                    } elseif ($type === 'css') {
                        // Speichere in einem speziellen Block für Inline-CSS
                        $this->blocks['inline_css'] = ($this->blocks['inline_css'] ?? '') . "\n" . $content;
                        return '';
                    }
                }
                
                // Normale Asset-Verarbeitung (keine Änderung)
                if ($type === 'css') {
                    $this->assetManager->addCss($content, isset($options['external']) && $options['external'] === 'true', $options);
                } elseif ($type === 'js') {
                    $this->assetManager->addJs(
                        $content, 
                        isset($options['external']) && $options['external'] === 'true', 
                        isset($options['defer']) && $options['defer'] === 'true', 
                        $options
                    );
                }
                
                return '';
            },
            $content
        );
    }

    private function renderInlineAssets(string $content): string
    {
        // Render für Inline-JS
        $content = preg_replace_callback(
            '/\{render:inline:js\}/',
            function() {
                if (isset($this->blocks['inline_js'])) {
                    $attributes = '';
                    // CSP Nonce hinzufügen, wenn vorhanden
                    if (defined('CSP_NONCE')) {
                        $attributes .= ' nonce="' . CSP_NONCE . '"';
                    }
                    return '<script' . $attributes . '>' . $this->blocks['inline_js'] . '</script>';
                }
                return '';
            },
            $content
        );
        
        // Render für Inline-CSS
        $content = preg_replace_callback(
            '/\{render:inline:css\}/',
            function() {
                if (isset($this->blocks['inline_css'])) {
                    $attributes = '';
                    // CSP Nonce hinzufügen, wenn vorhanden
                    if (defined('CSP_NONCE')) {
                        $attributes .= ' nonce="' . CSP_NONCE . '"';
                    }
                    return '<style' . $attributes . '>' . $this->blocks['inline_css'] . '</style>';
                }
                return '';
            },
            $content
        );
        
        return $content;
    }

    /**
     * Verarbeitet Block-Definitionen: {block:name}...{/block}
     */
    private function parseBlockDefinitions(string $content): string
    {
        return preg_replace_callback(
            '/\{block:([a-zA-Z0-9_-]+)\}(.*?)\{\/block\}/s',
            function($matches) {
                $blockName = $matches[1];
                $blockContent = $matches[2];
                
                // Block speichern
                $this->blocks[$blockName] = $blockContent;
                
                // Optional: Bei manchen Blöcken willst du vielleicht den Inhalt behalten
                if (in_array($blockName, ['content', 'main'])) {
                    return $blockContent;
                }
                
                return '';
            },
            $content
        );
    }

    /**
     * Verarbeitet Render-Tokens: {render:type:name}
     */
    private function parseRenderTokens(string $content): string
    {
        // 1. Assets rendern: {render:assets:type}
        $content = preg_replace_callback(
            '/\{render:assets:([a-z]+)\}/',
            function($matches) {
                $type = $matches[1];
                return $this->assetManager->render($type);
            },
            $content
        );
        
        // 2. Alle Assets rendern: {render:assets}
        $content = preg_replace_callback(
            '/\{render:assets\}/',
            function() {
                return $this->assetManager->render('css') . $this->assetManager->render('js');
            },
            $content
        );
        
        // 3. Blöcke rendern: {render:block:name}
        $content = preg_replace_callback(
            '/\{render:block:([a-zA-Z0-9_-]+)\}/',
            function($matches) {
                $blockName = $matches[1];
                
                if (isset($this->blocks[$blockName])) {
                    return $this->blocks[$blockName];
                }
                
                // Versuche, ein Block-Template zu laden
                if ($this->templateDir !== null) {
                    try {
                        $blockPath = rtrim($this->templateDir, '/') . '/block/' . $blockName . '.phtml';
                        
                        // Sicherheitsprüfungen...
                        $realBlockPath = realpath($blockPath);
                        $realBlocksDir = realpath(rtrim($this->templateDir, '/') . '/block');
                        
                        if ($realBlockPath && $realBlocksDir && strpos($realBlockPath, $realBlocksDir) === 0 && file_exists($realBlockPath)) {
                            ob_start();
                            
                            // Variable-Extraktion...
                            if ($this->templateContext && method_exists($this->templateContext, 'getTemplateVars')) {
                                $vars = $this->templateContext->getTemplateVars();
                                extract($vars, EXTR_SKIP);
                            }
                            
                            // Block-Template einbinden
                            include $realBlockPath;
                            
                            // Inhalt speichern
                            $blockContent = ob_get_clean();
                            $this->blocks[$blockName] = $blockContent;
                            
                            return $blockContent;
                        }
                    } catch (\Exception $e) {
                        if (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                    }
                }
                
                // Debug-Info im Entwicklungsmodus
                if (defined('MARQUES_DEBUG') && MARQUES_DEBUG) {
                    return "<!-- Block '$blockName' nicht gefunden -->";
                }
                
                return '';
            },
            $content
        );
        
        // 4. Abwärtskompatibilität: {assets:type}
        $content = preg_replace_callback(
            '/\{assets:([a-z]+)\}/',
            function($matches) {
                $type = $matches[1];
                return $this->assetManager->render($type);
            },
            $content
        );
        
        // 5. Abwärtskompatibilität: {block:name}
        $content = preg_replace_callback(
            '/\{block:([a-zA-Z0-9_-]+)\}/',
            function($matches) {
                $blockName = $matches[1];
                
                if (isset($this->blocks[$blockName])) {
                    return $this->blocks[$blockName];
                }
                
                // Rest wie oben...
                return '';
            },
            $content
        );
        
        return $content;
    }

    public function setTemplateDir(string $dir): void
    {
        $this->templateDir = $dir;
    }
    
    public function setTemplateContext($template): void
    {
        $this->templateContext = $template;
    }
    
    /**
     * Gibt den Asset-Manager zurück
     *
     * @return AssetManager
     */
    public function getAssetManager(): AssetManager
    {
        return $this->assetManager;
    }
    
    /**
     * Setzt den Asset-Manager
     *
     * @param AssetManager $assetManager
     * @return void
     */
    public function setAssetManager(AssetManager $assetManager): void
    {
        $this->assetManager = $assetManager;
    }
    
    /**
     * Verarbeitet Block-Tokens
     *
     * @param string $content
     * @return string
     */
    private function parseBlockTokens(string $content): string
    {
        return preg_replace_callback('/\{block:([a-zA-Z0-9_-]+)\}/', function($matches) {
            $blockName = $matches[1];
            
            if (isset($this->blocks[$blockName])) {
                return $this->blocks[$blockName];
            }
            
            // Strikte Validierung des Block-Namens
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $blockName)) {
                return '';
            }
            
            // Wenn wir ein Template-Verzeichnis haben, versuche das Block-Template zu laden
            if ($this->templateDir !== null) {
                try {
                    // Pfad zum Block-Template
                    $blockPath = rtrim($this->templateDir, '/') . '/block/' . $blockName . '.phtml';
                    
                    // Path-Sicherheit mit realpath
                    $realBlockPath = realpath($blockPath);
                    $realBlocksDir = realpath(rtrim($this->templateDir, '/') . '/block');
                    
                    if ($realBlockPath && $realBlocksDir && strpos($realBlockPath, $realBlocksDir) === 0 && file_exists($realBlockPath)) {
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
                        
                        return $blockContent;
                    }
                } catch (\Exception $e) {
                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                }
            }
            
            // Debug-Info im Entwicklungsmodus
            if (defined('MARQUES_DEBUG') && MARQUES_DEBUG) {
                return "<!-- Block '$blockName' nicht gefunden -->";
            }
            
            return '';
        }, $content);
    }
    
    /**
     * Verarbeitet Variablen-Tokens
     *
     * @param string $content
     * @return string
     */
    private function parseVariableTokens(string $content): string
    {
        return preg_replace_callback('/\{var:([a-zA-Z0-9_-]+)\}/', function($matches) {
            $varName = $matches[1];
            return $this->variables[$varName] ?? '';
        }, $content);
    }
    
    /**
     * Verarbeitet Asset-Tokens
     *
     * @param string $content
     * @return string
     */
    private function parseAssetTokens(string $content): string
    {
        // 1. Asset-Definitionen verarbeiten: {asset:type [options]}...{/asset}
        $content = preg_replace_callback(
            '/\{asset:([a-z]+)(?:\s+([^\}]*))?\}(.*?)\{\/asset\}/s',
            function($matches) {
                $type = $matches[1];
                $options = isset($matches[2]) ? $this->parseAttributeString($matches[2]) : [];
                $path = trim($matches[3]);
                
                // Asset zum Manager hinzufügen
                $isExternal = isset($options['external']) && $options['external'] === 'true';
                if ($type === 'css') {
                    $this->assetManager->addCss($path, $isExternal, $options);
                } elseif ($type === 'js') {
                    $defer = isset($options['defer']) ? $options['defer'] === 'true' : true;
                    $this->assetManager->addJs($path, $isExternal, $defer, $options);
                }
                
                return '';
            },
            $content
        );
        
        // 2. Explizites Asset-Rendering: {render:assets:type}
        $content = preg_replace_callback(
            '/\{render:assets:([a-z]+)\}/',
            function($matches) {
                $type = $matches[1];
                return $this->assetManager->render($type);
            },
            $content
        );
        
        // 3. Generelles Asset-Rendering: {render:assets}
        $content = preg_replace_callback(
            '/\{render:assets\}/',
            function() {
                return $this->assetManager->render('css') . $this->assetManager->render('js');
            },
            $content
        );
        
        // 4. Abwärtskompatibilität: {assets:type}
        $content = preg_replace_callback(
            '/\{assets:([a-z]+)\}/',
            function($matches) {
                $type = $matches[1];
                return $this->assetManager->render($type);
            },
            $content
        );
        
        return $content;
    }

    /**
     * Fügt inline JavaScript hinzu
     */
    private function addInlineJs(string $code): void
    {
        $this->blocks['inline_js'] = ($this->blocks['inline_js'] ?? '') . "\n" . $code;
    }

    /**
     * Fügt inline CSS hinzu
     */
    private function addInlineCss(string $code): void
    {
        $this->blocks['inline_css'] = ($this->blocks['inline_css'] ?? '') . "\n" . $code;
    }
    
    /**
     * Verarbeitet Meta-Tokens
     *
     * @param string $content
     * @return string
     */
    private function parseMetaTokens(string $content): string
    {
        // Format: {meta}...{/meta}
        $pattern = '/\{meta\}(.*?)\{\/meta\}/s';
        
        return preg_replace_callback($pattern, function() {
            $output = '';
            foreach ($this->meta as $meta) {
                $output .= '<meta';
                foreach ($meta as $attr => $value) {
                    $output .= ' ' . $attr . '="' . $value . '"';
                }
                $output .= '>' . PHP_EOL;
            }
            return $output;
        }, $content);
    }
    
    /**
     * Verarbeitet alte Ressourcen-Tokens für Abwärtskompatibilität
     *
     * @param string $content
     * @return string
     */
    private function parseResourceTokens(string $content): string
    {
        // Alte {src:css}...{src:end} Format ersetzen
        $content = preg_replace_callback('/\{src:css\}(.*?)\{src:end\}/s', function() {
            return $this->assetManager->render('css');
        }, $content);
        
        // Alte {src:js}...{src:end} Format ersetzen
        $content = preg_replace_callback('/\{src:js\}(.*?)\{src:end\}/s', function() {
            return $this->assetManager->render('js');
        }, $content);
        
        // Alte {src:meta}...{src:end} Format ersetzen
        $content = preg_replace_callback('/\{src:meta\}(.*?)\{src:end\}/s', function() {
            $output = '';
            foreach ($this->meta as $meta) {
                $output .= '<meta';
                foreach ($meta as $attr => $value) {
                    $output .= ' ' . $attr . '="' . $value . '"';
                }
                $output .= '>' . PHP_EOL;
            }
            return $output;
        }, $content);
        
        return $content;
    }
    
    /**
     * Parst einen Attribut-String in ein Array
     *
     * @param string $attrString Attribut-String im Format name="value" name2="value2"
     * @return array Assoziatives Array mit Attributen
     */
    private function parseAttributeString(string $attrString): array
    {
        $attributes = [];
        $pattern = '/([a-zA-Z0-9_-]+)=(["\'])(.*?)\2/';
        
        if (preg_match_all($pattern, $attrString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[$match[1]] = $match[3];
            }
        }
        
        return $attributes;
    }

    private function parseUrlTokens(string $content): string
    {
        return preg_replace_callback('/\{url:([a-zA-Z0-9_.-]+)(.*?)\}/', function ($matches) {
            $routeName   = $matches[1];
            $paramString = trim($matches[2]);
    
            $params = [];
            if ($paramString !== '') {
                preg_match_all(
                    '/([a-zA-Z0-9_]+)\s*=\s*"([^"]*)"|([a-zA-Z0-9_]+)\s*=\s*\'([^\']*)\'/',
                    $paramString,
                    $paramMatches,
                    PREG_SET_ORDER
                );
    
                foreach ($paramMatches as $param) {
                    $key = $param[1] ?: $param[3];
                    $val = $param[2] ?? $param[4] ?? '';
                    $params[$key] = $val;
                }
            }
    
            $router = null;
            if (
                $this->templateContext &&
                method_exists($this->templateContext, 'getTemplateVars')
            ) {
                $vars = $this->templateContext->getTemplateVars();
                if (isset($vars['router']) && method_exists($vars['router'], 'generateUrl')) {
                    $router = $vars['router'];
                }
            }
    
            if (!$router) {
                return defined('MARQUES_DEBUG') && MARQUES_DEBUG
                    ? "<!-- invalid router context for route: {$routeName} -->"
                    : '#invalid-url';
            }
    
            try {
                $url = $router->generateUrl($routeName, $params);
                return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } catch (\Throwable $e) {
                return defined('MARQUES_DEBUG') && MARQUES_DEBUG
                    ? '<!-- router error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' -->'
                    : '#invalid-url';
            }
        }, $content);
    }
    
    /**
     * Gibt alle registrierten Blöcke zurück
     *
     * @return array<string, string>
     */
    public function getAllBlocks(): array
    {
        return $this->blocks;
    }
    
    /**
     * Gibt alle registrierten Variablen zurück
     *
     * @return array<string, string>
     */
    public function getAllVariables(): array
    {
        return $this->variables;
    }
    
    /**
     * Gibt alle registrierten Meta-Tags zurück
     *
     * @return array
     */
    public function getAllMeta(): array
    {
        return $this->meta;
    }
}