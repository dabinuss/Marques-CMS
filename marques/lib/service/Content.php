<?php
declare(strict_types=1);

namespace Marques\Service;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Util\SafetyXSS;
use Marques\Util\Helper;
use Marques\Data\FileManager;
use Marques\Service\BlogManager;

class Content {
    private array $_cache = [];
    private DatabaseHandler $dbHandler;
    private FileManager $fileManager;
    private Helper $helper;

    public function __construct(DatabaseHandler $dbHandler, FileManager $fileManager, Helper $helper) {
        $this->dbHandler = $dbHandler;
        $this->fileManager = $fileManager;
        $this->helper = $helper;
    }
    
    /**
     * Holt eine Seite anhand des Pfads
     *
     * @param string $path Seitenpfad
     * @return array Seitendaten
     * @throws \RuntimeException Wenn die Seite nicht gefunden wird
     */
    public function getPage(string $path = '', array $routeParams = []): array {
        // Verbesserte Fehlerbehandlung und Pfadnormalisierung
        if (empty($path)) {
            $path = 'home'; // Standardwert
        }
        
        // Normalisiere den Pfad - entferne führende/nachfolgende Schrägstriche
        $path = trim($path, '/');
        
        error_log("getPage aufgerufen mit normalisiertem path: " . $path);
        error_log("Route-Parameter: " . print_r($routeParams, true));
        
        // Aus Cache zurückgeben, wenn verfügbar
        if (isset($this->_cache[$path])) {
            error_log("Seite aus Cache zurückgegeben: " . $path);
            return $this->_cache[$path];
        }
    
        // Spezielle Seitentypen handhaben
        if ($path === 'blog') {
            return $this->getBlogPost($path, $routeParams);
        } elseif (in_array($path, ['blog-list', 'blog-category', 'blog-archive'])) {
            return $this->getBlogList($path, $routeParams);
        }
        
        // Stelle sicher, dass das pages-Verzeichnis existiert
        $pagesDir = MARQUES_CONTENT_DIR . '/pages';
        if (!is_dir($pagesDir)) {
            if (!mkdir($pagesDir, 0755, true)) {
                error_log("Fehler: Verzeichnis konnte nicht erstellt werden: " . $pagesDir);
                throw new \RuntimeException("Verzeichnis für Seiten konnte nicht erstellt werden.", 500);
            }
        }
        
        // Dateipfad bestimmen mit verbesserter Fehlererkennung
        $filePath = $pagesDir . '/' . $path . '.md';
        error_log("Suche Datei: " . $filePath);
        
        if (!file_exists($filePath)) {
            error_log("Datei nicht gefunden: " . $filePath);
            
            // Für die Startseite: Erstelle eine Standardseite
            if ($path === 'home') {
                $this->createDefaultHomePage();
                
                // Nach dem Erstellen erneut prüfen
                if (!file_exists($filePath)) {
                    error_log("Fehler: Home-Seite konnte nicht erstellt werden");
                    throw new \RuntimeException("Startseite nicht gefunden und konnte nicht erstellt werden.", 404);
                } else {
                    error_log("Standard-Home-Seite wurde erstellt");
                }
            } else {
                throw new \RuntimeException("Seite nicht gefunden: " . SafetyXSS::escapeOutput($path, 'html'), 404);
            }
        }
        
        if (!is_readable($filePath)) {
            error_log("Keine Leserechte für: " . $filePath);
            throw new \RuntimeException("Keine Leserechte für: " . SafetyXSS::escapeOutput($path, 'html'), 403);
        }
        
        try {
            $content = file_get_contents($filePath);
            if ($content === false) {
                error_log("Fehler beim Lesen der Datei: " . $filePath);
                throw new \RuntimeException("Fehler beim Lesen der Datei: " . $path);
            }
            
            error_log("Datei erfolgreich gelesen, Parsing...");
            $pageData = $this->parseContentFile($content);
            
            // Setze Standardwerte
            if (!isset($pageData['template'])) {
                $pageData['template'] = 'page';
            }
            
            $pageData['path'] = $path;
            $this->_cache[$path] = $pageData;
            
            error_log("Seite erfolgreich verarbeitet: " . $path);
            return $pageData;
            
        } catch (\Exception $e) {
            error_log("Fehler beim Verarbeiten der Seite: " . $e->getMessage());
            throw new \RuntimeException("Fehler beim Parsen der Inhalte: " . $e->getMessage(), 500);
        }
    }

    /**
     * Erstellt eine Standard-Home-Seite, wenn keine existiert
     */
    private function createDefaultHomePage(): void {
        // Stellen Sie sicher, dass das Verzeichnis existiert
        $dir = MARQUES_CONTENT_DIR . '/pages';
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("Fehler: Konnte Verzeichnis nicht erstellen: " . $dir);
                return;
            }
        }
        
        // Inhalt für die Standard-Home-Seite
        $content = <<<'MARKDOWN'
---
title: "Willkommen"
description: "Startseite des marques CMS"
template: "page"
date_created: "2023-08-01"
date_modified: "2023-08-01"
---

# Willkommen bei marques CMS

Dies ist die automatisch erstellte Startseite Ihres marques CMS. Sie können diesen Inhalt im Admin-Bereich bearbeiten.

## Erste Schritte

1. Melden Sie sich im Admin-Bereich an
2. Bearbeiten Sie diese Seite
3. Erstellen Sie weitere Inhalte
4. Konfigurieren Sie Ihr Theme

Viel Erfolg mit Ihrem neuen CMS!
MARKDOWN;
        
        // Datei schreiben
        $file = $dir . '/home.md';
        if (file_put_contents($file, $content) === false) {
            error_log("Fehler: Konnte Standard-Home-Seite nicht erstellen: " . $file);
        } else {
            error_log("Info: Standard-Home-Seite wurde erstellt: " . $file);
        }
    }
    
    /**
     * Holt einen Blog-Beitrag
     *
     * @param string $path Blog-Beitragspfad
     * @param array $params Route-Parameter
     * @return array Blog-Beitragsdaten
     * @throws \RuntimeException Wenn der Blog-Beitrag nicht gefunden wird
     */
    private function getBlogPost(string $path, array $params = []): array {
        // Hier wird nun die injizierte FileManager-Instanz verwendet, statt new FileManager() aufzurufen.
        $blogManager = new BlogManager($this->dbHandler, $this->fileManager, $this->helper);
        
        // Debug-Ausgabe für Fehlersuche
        error_log("getBlogPost() aufgerufen mit path: " . $path);
        error_log("Parameter: " . print_r($params, true));
        
        $post = null;
        $systemConfig = $this->dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];
        $blogUrlFormat = $systemConfig['blog_url_format'] ?? 'date_slash';
        
        switch ($blogUrlFormat) {
            case 'date_slash':
            case 'date_dash':
                if (isset($params['year'], $params['month'], $params['day'], $params['slug'])) {
                    $postId = $params['year'] . '-' . $params['month'] . '-' . $params['day'] . '-' . $params['slug'];
                    error_log("Suche nach Blog-Post mit ID: " . $postId);
                    $post = $blogManager->getPost($postId);
                }
                break;
            case 'year_month':
                if (isset($params['year'], $params['month'], $params['slug'])) {
                    $pattern = $params['year'] . '-' . $params['month'] . '-*-' . $params['slug'];
                    error_log("Suche nach Blog-Post mit Pattern: " . $pattern);
                    $post = $blogManager->getPostByPattern($pattern);
                }
                break;
            case 'numeric':
                if (isset($params['id'])) {
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
                    error_log("Suche nach Blog-Post mit Slug: " . $params['slug']);
                    $post = $blogManager->getPostBySlug($params['slug']);
                }
                break;
            default:
                if (isset($params['year'], $params['month'], $params['day'], $params['slug'])) {
                    $postId = $params['year'] . '-' . $params['month'] . '-' . $params['day'] . '-' . $params['slug'];
                    error_log("Suche nach Blog-Post mit ID: " . $postId);
                    $post = $blogManager->getPost($postId);
                }
        }
        
        if (!$post) {
            error_log("Blog-Post nicht gefunden! Parameter: " . print_r($params, true));
            throw new \RuntimeException("Blog-Beitrag nicht gefunden", 404);
        }
        
        error_log("Blog-Post gefunden: " . print_r($post['title'], true));
        
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

    /**
     * Holt eine Blog-Übersichtsseite (Liste, Kategorie, Archiv)
     *
     * @param string $path Blog-Listenpfad
     * @param array $params Route-Parameter
     * @return array Blog-Listendaten
     */
    private function getBlogList(string $path, array $params = []): array {
        $query = []; 
        $title = 'Blog';
        $description = 'Alle Blog-Beiträge';

        if ($path === 'blog-category' && isset($params['category'])) {
            $title = 'Blog - Kategorie: ' . htmlspecialchars($params['category']);
            $description = 'Blog-Beiträge in der Kategorie ' . htmlspecialchars($params['category']);
        }
        if ($path === 'blog-archive' && isset($params['year'], $params['month'])) {
            $month_name = date('F', mktime(0, 0, 0, (int)$params['month'], 1, (int)$params['year']));
            $title = 'Blog - Archiv: ' . $month_name . ' ' . $params['year'];
            $description = 'Blog-Beiträge aus ' . $month_name . ' ' . $params['year'];
        }
        
        $pageData = [
            'title'       => $title,
            'content'     => '',
            'description' => $description,
            'template'    => 'blog-list',
            'path'        => $path,
            'params'      => $params,
            'query'       => $query
        ];
        
        return $pageData;
    }
    
    /**
     * Parst eine Inhaltsdatei.
     *
     * @param string $content Inhalt der Datei
     * @return array Geparste Inhaltsdaten
     */
    private function parseContentFile($content) {
        $parts = preg_split('/[\r\n]*---[\r\n]+/', $content, 3);
        $frontmatter = '';
        $body = '';
        if (count($parts) === 3) {
            $frontmatter = $parts[1];
            $body = $parts[2];
        } else {
            $body = $content;
        }
        $data = [];
        if (!empty($frontmatter)) {
            $data = $this->parseYaml($frontmatter);
        }
        $data['content'] = $this->parseMarkdown($body);
        $data['content_raw'] = $body;
        return $data;
    }
    
    /**
     * Parst YAML-Frontmatter.
     *
     * @param string $yaml YAML-String
     * @return array Geparste YAML-Daten
     */
    private function parseYaml($yaml): array {
        $lines = explode("\n", $yaml);
        $data = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                if (preg_match('/^["\'](.*)["\']$/', $value, $stringMatches)) {
                    $value = $stringMatches[1];
                }
                $data[$key] = $value;
            }
        }
        return $data;
    }
    
    /**
     * Parst Markdown-Inhalt.
     *
     * @param string $markdown Markdown-String
     * @return string HTML-Inhalt
     */
    private function parseMarkdown($markdown) {
        $html = $markdown;
        $codeBlocks = [];
        $html = preg_replace_callback('/```(.+?)```/s', function($matches) use (&$codeBlocks) {
            $placeholder = '___CODE_BLOCK_' . count($codeBlocks) . '___';
            $codeBlocks[] = $matches[1];
            return $placeholder;
        }, $html);
        
        $html = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $html);
        $html = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^#### (.*?)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^##### (.*?)$/m', '<h5>$1</h5>', $html);
        $html = preg_replace('/^###### (.*?)$/m', '<h6>$1</h6>', $html);
        
        $html = preg_replace('/^(\*|\-|\+) (.*?)$/m', '<li>$2</li>', $html);
        $html = preg_replace('/(<li>.*?<\/li>\n)+/s', '<ul>$0</ul>', $html);
        
        $html = preg_replace('/^[0-9]+\. (.*?)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*?<\/li>\n)+/s', '<ol>$0</ol>', $html);
        
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);
        $html = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1">', $html);
        $html = preg_replace('/^(\-{3,}|\*{3,}|_{3,})$/m', '<hr>', $html);
        $html = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $html);
        $html = preg_replace('/`(.*?)`/s', '<code>$1</code>', $html);
        $html = preg_replace('/(?<!<\/h[1-6]>|<\/li>|<hr>)\n\n(?!<h[1-6]|<ul|<ol|<li|<hr>)/', '</p><p>', $html);
        if (!preg_match('/^<[ho]/', $html)) {
            $html = '<p>' . $html;
        }
        if (!preg_match('/<\/[^>]+>$/', $html)) {
            $html .= '</p>';
        }
        $html = preg_replace_callback('/___CODE_BLOCK_(\d+)___/', function($matches) use ($codeBlocks) {
            $index = (int)$matches[1];
            return '<pre><code>' . htmlspecialchars($codeBlocks[$index]) . '</code></pre>';
        }, $html);
        
        return $html;
    }
}