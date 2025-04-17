<?php
declare(strict_types=1);

/**
 * marques CMS - Page Manager Klasse
 * 
 * Behandelt Seitenverwaltung.
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Service;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Filesystem\{PathRegistry, PathResolver};
use Marques\Filesystem\FileManager;

class PageManager {
    private array $_config;
    private DatabaseHandler $dbHandler;
    private PathRegistry $paths;
    private FileManager $fileManager;

    public function __construct(   
        DatabaseHandler $dbHandler,
        PathRegistry   $paths,
        FileManager $fileManager
    ) {
        $this->dbHandler = $dbHandler;
        $this->paths     = $paths;
        $this->fileManager = $fileManager;

        try {
            $this->_config = $this->dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];
        } catch (\Exception $e) {
            $this->_config = [];
            // Optional: Logging des Fehlers
        }
    }
    
    /**
     * Gibt eine Liste aller Seiten zurück
     *
     * @return array Seiten mit Metadaten
     */
    public function getAllPages(): array {
        $pages = [];
        $pagesDir = $this->paths->combine('content', 'pages');
        
        $pagesDir = 'pages';
        if (!$this->fileManager->exists($pagesDir)) {
            $this->fileManager->createDirectory($pagesDir);
        }
        
        $files = $this->fileManager->listFiles($pagesDir, 'md');
        
        if (!is_array($files)) {
            return $pages; // Schutz vor glob() Fehler
        }
        
        foreach ($files as $file) {
            if (!is_readable($file)) continue; // Überspringe nicht lesbare Dateien
            
            $filename = basename($file, '.md');
            $content = $this->fileManager->readFile("pages/$filename.md");
            
            if ($content === false) continue; // Überspringe bei Lesefehlern
            
            $metadata = $this->extractFrontmatter($content);
            
            $pages[] = [
                'id' => $filename,
                'title' => $metadata['title'] ?? $filename,
                'date_created' => $metadata['date_created'] ?? date('Y-m-d'),
                'date_modified' => $metadata['date_modified'] ?? date('Y-m-d'),
                'path' => $filename
            ];
        }
        
        // Nach Titel sortieren
        usort($pages, function($a, $b) {
            return strcasecmp($a['title'], $b['title']);
        });
        
        return $pages;
    }

    /**
     * Gibt eine einzelne Seite zurück
     *
     * @param string $id Seiten-ID (Dateiname ohne .md)
     * @return array|null Seite mit Metadaten und Inhalt
     */
    public function getPage(string $id): ?array {
        // Sicherheitscheck
        if (strpos($id, '/') !== false || strpos($id, '\\') !== false) {
            return null;
        }
        
        $file = $this->paths->combine('content', 'pages/' . $id . '.md');
        
        if (!file_exists($file)) {
            return null;
        }
        
        $content = file_get_contents($file);
        
        // Frontmatter und Inhalt trennen
        $parts = preg_split('/[\r\n]*---[\r\n]+/', $content, 3);
        
        $metadata = [];
        $body = '';
        
        if (count($parts) === 3) {
            // Datei hat Frontmatter
            $metadata = $this->extractFrontmatter($content);
            $body = $parts[2];
        } else {
            // Kein Frontmatter
            $body = $content;
        }
        
        return [
            'id' => $id,
            'title' => $metadata['title'] ?? $id,
            'description' => $metadata['description'] ?? '',
            'date_created' => $metadata['date_created'] ?? date('Y-m-d'),
            'date_modified' => $metadata['date_modified'] ?? date('Y-m-d'),
            'template' => $metadata['template'] ?? 'page',
            'featured_image' => $metadata['featured_image'] ?? '',
            'content' => $body
        ];
    }
    
    /**
     * Extrahiert Frontmatter aus Dateiinhalt
     *
     * @param string $content Dateiinhalt
     * @return array Frontmatter-Daten
     */
    private function extractFrontmatter($content): array {
        if (!is_string($content) || trim($content) === '') {
            return [];
        }
        
        // Prüfen, ob Frontmatter vorhanden ist (zwischen --- Markierungen)
        $pattern = '/^---[\r\n]+(.*?)[\r\n]+---[\r\n]+/s';
        if (!preg_match($pattern, $content, $matches)) {
            return [];
        }
        
        $frontmatter = $matches[1];
        $metadata = [];
        
        $lines = explode("\n", $frontmatter);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) { // Überspringe leere Zeilen und Kommentare
                continue;
            }
            
            // Verbesserte Schlüssel-Wert-Extraktion mit Fehlerbehandlung für verschiedene YAML-Formate
            if (preg_match('/^([^:]+):(?:\s*[\'"]?(.*?)[\'"]?)?$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = isset($matches[2]) ? trim($matches[2]) : '';
                
                // Entfernen von umschließenden Anführungszeichen, falls vorhanden
                if (preg_match('/^[\'"](.*)[\'"]\s*$/', $value, $stringMatches)) {
                    $value = $stringMatches[1];
                }
                
                $metadata[$key] = $value;
            }
        }
        
        return $metadata;
    }

    /**
     * Sicherheitsüberprüfung für Datei-IDs
     */
    private function isValidFileId(string $id): bool {
        return preg_match('/^[a-z0-9_-]+$/i', $id) && 
               strpos($id, '/') === false && 
               strpos($id, '\\') === false;
    }
    
    /**
     * Generiert einen Slug aus einem String
     *
     * @param string $text Eingangstext
     * @return string Slug
     */
    public function generateSlug($text): string {
        if (!is_string($text) || empty($text)) {
            return 'unnamed-' . substr(md5(uniqid()), 0, 8);
        }
        
        // Transliteration (Umlaute etc. umwandeln) mit Fallback
        try {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        } catch (\Exception $e) {
            // Fallback für Server ohne intl-Extension
            $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        }
        
        // Alles in Kleinbuchstaben umwandeln
        $text = strtolower($text);
        // Alle nicht-alphanumerischen Zeichen durch Bindestriche ersetzen
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        // Führende und nachfolgende Bindestriche entfernen
        $text = trim($text, '-');
        
        // Fallback für leeren Slug
        if (empty($text)) {
            return 'page-' . substr(md5(uniqid()), 0, 8);
        }
        
        return $text;
    }
}