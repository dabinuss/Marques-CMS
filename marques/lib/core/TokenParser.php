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
     * Ersetzt alle Token-Varianten in einem Template-String
     *
     * @param string $content Template-Inhalt mit Tokens
     * @return string Verarbeiteter Inhalt mit ersetzten Tokens
     */
    public function parseTokens(string $content): string
    {
        // 1. Block-Tokens ersetzen: {block:name}
        $content = $this->parseBlockTokens($content);
        
        // 2. Variablen-Tokens ersetzen: {var:name}
        $content = $this->parseVariableTokens($content);
        
        // 3. Asset-Tokens ersetzen: {asset:type}...{/asset}
        $content = $this->parseAssetTokens($content);
        
        // 4. Meta-Tokens ersetzen: {meta}...{/meta}
        $content = $this->parseMetaTokens($content);
        
        // 5. Alte Ressourcen-Tokens für Abwärtskompatibilität: {src:type}...{src:end}
        $content = $this->parseResourceTokens($content);
        
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
        // Format: {asset:type [options]}...{/asset}
        $pattern = '/\{asset:([a-z]+)(?:\s+([^\}]*))?\}(.*?)\{\/asset\}/s';
        
        return preg_replace_callback($pattern, function($matches) {
            $type = $matches[1];
            $options = isset($matches[2]) ? $this->parseAttributeString($matches[2]) : [];
            
            // Gruppierungsoptionen verarbeiten
            if (isset($options['group'])) {
                return $this->assetManager->renderGroup($options['group'], $type);
            }
            
            // Einzelnes Asset rendern
            return $this->assetManager->render($type);
        }, $content);
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