<?php
declare(strict_types=1);

/**
 * marques CMS - Content Klasse
 * 
 * Behandelt das Laden und Parsen von Inhalten.
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Core;

class Content {
    /**
     * @var array Content-Cache
     */
    private $_cache = [];
    
    /**
     * @var ConfigManager 
     */
    private $_configManager;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $this->_configManager = ConfigManager::getInstance();
    }
    
    /**
     * Holt eine Seite anhand des Pfads
     *
     * @param string $path Seitenpfad
     * @return array Seitendaten
     * @throws NotFoundException Wenn die Seite nicht gefunden wird
     */
    public function getPage(string $path): array {
        if (empty($path)) {
            throw new \InvalidArgumentException("Ungültiger Seitenpfad");
        }
        
        // Prüfen, ob Seite im Cache ist
        if (isset($this->_cache[$path])) {
            return $this->_cache[$path];
        }
        
        // Route-Parameter aus globaler Variable holen
        $params = isset($GLOBALS['route']['params']) ? $GLOBALS['route']['params'] : [];
        
        // Debug-Ausgabe für Fehlersuche
        error_log("getPage aufgerufen mit path: " . $path);
        error_log("Route-Parameter: " . print_r($params, true));
        
        // Bestimmen, ob es sich um einen Blog-Beitrag oder eine spezielle Blog-Seite handelt
        if ($path === 'blog') {
            // Parameter explizit übergeben
            return $this->getBlogPost($path, $params);
        } elseif ($path === 'blog-index' || $path === 'blog-category' || $path === 'blog-archive') {
            return $this->getBlogList($path);
        }
        
        // Reguläre Seite
        $filePath = MARQUES_CONTENT_DIR . '/pages/' . $path . '.md';
        
        // Prüfen, ob Datei existiert
        if (!file_exists($filePath)) {
            throw new NotFoundException("Seite nicht gefunden: " . $path);
        }
        
        // Prüfen, ob Datei lesbar ist
        if (!is_readable($filePath)) {
            throw new \Marques\Core\PermissionException("Keine Leserechte für: " . $path);
        }
        
        try {
            // Datei lesen und parsen
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new \Exception("Fehler beim Lesen der Datei: " . $path);
            }
            
            $pageData = $this->parseContentFile($content);
        } catch (\Exception $e) {
            throw new \Exception("Fehler beim Parsen der Inhalte: " . $e->getMessage());
        }
        
        // Template-Info hinzufügen, falls nicht gesetzt
        if (!isset($pageData['template'])) {
            $pageData['template'] = 'page';
        }
        
        // Pfad zu Daten hinzufügen
        $pageData['path'] = $path;
        
        // Ergebnis cachen
        $this->_cache[$path] = $pageData;
        
        return $pageData;
    }
    
    /**
     * Holt einen Blog-Beitrag
     *
     * @param string $path Blog-Beitragspfad
     * @param array $params Route-Parameter
     * @return array Blog-Beitragsdaten
     * @throws NotFoundException Wenn der Blog-Beitrag nicht gefunden wird
     */
    private function getBlogPost($path, $params = []) {
        // Blog-Manager initialisieren
        $blogManager = new BlogManager();
        $systemConfig = $this->_configManager->load('system') ?: [];
        $blogUrlFormat = $systemConfig['blog_url_format'] ?? 'date_slash';
        
        // Debug-Ausgabe für Fehlersuche
        error_log("getBlogPost() aufgerufen mit path: " . $path);
        error_log("Parameter: " . print_r($params, true));
        
        // Wenn es sich um einen einzelnen Blog-Beitrag handelt
        if ($path === 'blog') {
            // Parameter direkt verwenden, nicht von globalen Variablen abhängig
            $post = null;
            
            // Je nach URL-Format unterschiedliche Logik anwenden
            switch ($blogUrlFormat) {
                case 'date_slash':
                case 'date_dash':
                    if (isset($params['year'], $params['month'], $params['day'], $params['slug'])) {
                        // Blog-Post-ID erstellen (YYYY-MM-DD-slug)
                        $postId = $params['year'] . '-' . $params['month'] . '-' . $params['day'] . '-' . $params['slug'];
                        error_log("Suche nach Blog-Post mit ID: " . $postId);
                        $post = $blogManager->getPost($postId);
                    }
                    break;
                    
                case 'year_month':
                    if (isset($params['year'], $params['month'], $params['slug'])) {
                        // Suche nach dem ersten Post mit passendem Jahr, Monat und Slug
                        $pattern = $params['year'] . '-' . $params['month'] . '-*-' . $params['slug'];
                        error_log("Suche nach Blog-Post mit Pattern: " . $pattern);
                        $post = $blogManager->getPostByPattern($pattern);
                    }
                    break;
                    
                case 'numeric':
                    if (isset($params['id'])) {
                        // Suche nach Post mit übereinstimmender ID
                        error_log("Suche nach Blog-Post mit ID: " . $params['id']);
                        $post = $blogManager->getPostById($params['id']);
                    } elseif (isset($params['year'], $params['month'], $params['day'], $params['slug'])) {
                        $postId = $params['year'] . '-' . $params['month'] . '-' . $params['day'] . '-' . $params['slug'];
                        error_log("Suche nach Blog-Post mit ID: " . $postId);
                        $post = $blogManager->getPost($postId);
                    }
                    break;
                    
                case 'post_name':
                    if (isset($params['slug'])) {
                        // Suche nach Post mit übereinstimmendem Slug
                        error_log("Suche nach Blog-Post mit Slug: " . $params['slug']);
                        $post = $blogManager->getPostBySlug($params['slug']);
                    }
                    break;
                    
                default:
                    // Standard: date_slash Format
                    if (isset($params['year'], $params['month'], $params['day'], $params['slug'])) {
                        $postId = $params['year'] . '-' . $params['month'] . '-' . $params['day'] . '-' . $params['slug'];
                        error_log("Suche nach Blog-Post mit ID: " . $postId);
                        $post = $blogManager->getPost($postId);
                    }
            }
            
            if (!$post) {
                error_log("Blog-Post nicht gefunden! Parameter: " . print_r($params, true));
                throw new NotFoundException("Blog-Beitrag nicht gefunden");
            }
            
            error_log("Blog-Post gefunden: " . print_r($post['title'], true));
            
            // Daten für Template vorbereiten
            $pageData = [
                'title' => $post['title'],
                'content' => $post['content'],
                'description' => $post['excerpt'] ?? '',
                'date_created' => $post['date_created'] ?? $post['date'] ?? '',
                'date_modified' => $post['date_modified'] ?? $post['date'] ?? '',
                'template' => 'blog-post',
                'path' => $path,
                'params' => $params,
                'post' => $post
            ];
            
            return $pageData;
        }
        
        error_log("Ungültiger Blog-Pfad: " . $path . ", Parameter: " . print_r($params, true));
        throw new NotFoundException("Ungültiger Blog-Pfad");
    }

    /**
     * Holt eine Blog-Übersichtsseite (Liste, Kategorie, Archiv)
     *
     * @param string $path Blog-Listenpfad
     * @return array Blog-Listendaten
     */
    private function getBlogList($path) {
        $params = $GLOBALS['route']['params'] ?? [];
        $query = $GLOBALS['route']['query'] ?? [];
        
        $title = 'Blog';
        $description = 'Alle Blog-Beiträge';
        
        // Kategorie-Filter
        if ($path === 'blog-category' && isset($params['category'])) {
            $title = 'Blog - Kategorie: ' . htmlspecialchars($params['category']);
            $description = 'Blog-Beiträge in der Kategorie ' . htmlspecialchars($params['category']);
        }
        
        // Archiv-Filter
        if ($path === 'blog-archive' && isset($params['year'], $params['month'])) {
            $month_name = date('F', mktime(0, 0, 0, (int)$params['month'], 1, (int)$params['year']));
            $title = 'Blog - Archiv: ' . $month_name . ' ' . $params['year'];
            $description = 'Blog-Beiträge aus ' . $month_name . ' ' . $params['year'];
        }
        
        $pageData = [
            'title' => $title,
            'content' => '',
            'description' => $description,
            'template' => 'blog-list',
            'path' => $path,
            'params' => $params,
            'query' => $query
        ];
        
        return $pageData;
    }
    
    /**
     * Parst eine Inhaltsdatei
     *
     * @param string $content Inhalt der Datei
     * @return array Geparste Inhaltsdaten
     */
    private function parseContentFile($content) {
        // Frontmatter und Inhalt aufteilen
        $parts = preg_split('/[\r\n]*---[\r\n]+/', $content, 3);
        
        // Frontmatter und Inhalt extrahieren
        $frontmatter = '';
        $body = '';
        
        if (count($parts) === 3) {
            // Datei hat Frontmatter
            $frontmatter = $parts[1];
            $body = $parts[2];
        } else {
            // Kein Frontmatter
            $body = $content;
        }
        
        // Frontmatter parsen (YAML)
        $data = [];
        if (!empty($frontmatter)) {
            $data = $this->parseYaml($frontmatter);
        }
        
        // Inhalt zu Daten hinzufügen
        $data['content'] = $this->parseMarkdown($body);
        $data['content_raw'] = $body;
        
        return $data;
    }
    
    /**
     * Parst YAML-Frontmatter
     *
     * @param string $yaml YAML-String
     * @return array Geparste YAML-Daten
     */
    private function parseYaml($yaml) {
        // Einfacher YAML-Parser für Frontmatter
        $lines = explode("\n", $yaml);
        $data = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Leere Zeilen überspringen
            if (empty($line)) {
                continue;
            }
            
            // Key-Value-Paare abgleichen
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                
                // Anführungszeichen aus String-Werten entfernen
                if (preg_match('/^[\'"](.*)[\'""]$/', $value, $stringMatches)) {
                    $value = $stringMatches[1];
                }
                
                // Arrays behandeln
                if (preg_match('/^\[([^]]*)\]$/', $value, $arrayMatches)) {
                    $arrayString = $arrayMatches[1];
                    $arrayItems = explode(',', $arrayString);
                    $value = array_map('trim', $arrayItems);
                }
                
                $data[$key] = $value;
            }
        }
        
        return $data;
    }
    
    /**
     * Parst Markdown-Inhalt
     *
     * @param string $markdown Markdown-String
     * @return string HTML-Inhalt
     */
    private function parseMarkdown($markdown) {
        // Hinweis: In einer realen Implementierung würden Sie eine Bibliothek wie Parsedown verwenden
        // Dies ist eine verbesserte, aber immer noch einfache Implementierung
        
        $html = $markdown;
        
        // Code-Blöcke vor der Verarbeitung schützen
        $codeBlocks = [];
        $html = preg_replace_callback('/```(.+?)```/s', function($matches) use (&$codeBlocks) {
            $placeholder = '___CODE_BLOCK_' . count($codeBlocks) . '___';
            $codeBlocks[] = $matches[1];
            return $placeholder;
        }, $html);
        
        // Überschriften
        $html = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $html);
        $html = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^#### (.*?)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^##### (.*?)$/m', '<h5>$1</h5>', $html);
        $html = preg_replace('/^###### (.*?)$/m', '<h6>$1</h6>', $html);
        
        // Listen
        $html = preg_replace('/^(\*|\-|\+) (.*?)$/m', '<li>$2</li>', $html);
        $html = preg_replace('/(<li>.*?<\/li>\n)+/s', '<ul>$0</ul>', $html);
        
        $html = preg_replace('/^[0-9]+\. (.*?)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*?<\/li>\n)+/s', '<ol>$0</ol>', $html);
        
        // Links
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);
        
        // Bilder
        $html = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1">', $html);
        
        // Horizontale Linie
        $html = preg_replace('/^(\-{3,}|\*{3,}|_{3,})$/m', '<hr>', $html);
        
        // Inline-Formatierung
        $html = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $html);
        $html = preg_replace('/`(.*?)`/s', '<code>$1</code>', $html);
        
        // Absätze (komplexe Logik, um mit Listen und anderen Block-Elementen umzugehen)
        $html = preg_replace('/(?<!<\/h[1-6]>|<\/li>|<hr>)\n\n(?!<h[1-6]|<ul|<ol|<li|<hr>)/', '</p><p>', $html);
        // Absätze um den gesamten Inhalt herum, wenn nötig
        if (!preg_match('/^<[ho]/', $html)) {
            $html = '<p>' . $html;
        }
        if (!preg_match('/<\/[^>]+>$/', $html)) {
            $html .= '</p>';
        }
        
        // Code-Blöcke wiederherstellen
        $html = preg_replace_callback('/___CODE_BLOCK_(\d+)___/', function($matches) use ($codeBlocks) {
            $index = (int)$matches[1];
            return '<pre><code>' . htmlspecialchars($codeBlocks[$index]) . '</code></pre>';
        }, $html);
        
        return $html;
    }
}