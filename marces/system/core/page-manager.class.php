<?php
/**
 * marces CMS - Page Manager Klasse
 * 
 * Behandelt Seitenverwaltung.
 *
 * @package marces
 * @subpackage core
 */

namespace Marces\Core;

class PageManager {
    /**
     * @var array Systemkonfiguration
     */
    private $_config;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $this->_config = require MARCES_CONFIG_DIR . '/system.config.php';
    }
    
    /**
     * Gibt eine Liste aller Seiten zurück
     *
     * @return array Seiten mit Metadaten
     */
    public function getAllPages() {
        $pages = [];
        $pagesDir = MARCES_CONTENT_DIR . '/pages';
        
        if (!is_dir($pagesDir)) {
            return $pages;
        }
        
        $files = glob($pagesDir . '/*.md');
        
        foreach ($files as $file) {
            $filename = basename($file, '.md');
            $content = file_get_contents($file);
            $metadata = $this->extractFrontmatter($content);
            
            $pages[] = [
                'id' => $filename,
                'title' => $metadata['title'] ?? $filename,
                'date_created' => $metadata['date_created'] ?? '',
                'date_modified' => $metadata['date_modified'] ?? '',
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
    public function getPage($id) {
        // Sicherheitscheck
        if (strpos($id, '/') !== false || strpos($id, '\\') !== false) {
            return null;
        }
        
        $file = MARCES_CONTENT_DIR . '/pages/' . $id . '.md';
        
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
     * Erstellt oder aktualisiert eine Seite
     *
     * @param array $pageData Seiten-Daten
     * @return bool True bei Erfolg
     */
    public function savePage($pageData) {
        if (empty($pageData['id'])) {
            // Neue Seite - ID aus Titel generieren
            $pageData['id'] = $this->generateSlug($pageData['title']);
        }
        
        // Sicherheitscheck
        if (strpos($pageData['id'], '/') !== false || strpos($pageData['id'], '\\') !== false) {
            return false;
        }
        
        $file = MARCES_CONTENT_DIR . '/pages/' . $pageData['id'] . '.md';
        
        // Wenn Datei existiert, zuvor eine Version erstellen
        if (file_exists($file)) {
            // Version erstellen
            $versionManager = new VersionManager();
            $currentUsername = isset($_SESSION['marces_user']) ? $_SESSION['marces_user']['username'] : 'system';
            $versionManager->createVersion('pages', $pageData['id'], file_get_contents($file), $currentUsername);
        }
        
        // Frontmatter vorbereiten
        $frontmatter = [
            'title' => $pageData['title'] ?? '',
            'description' => $pageData['description'] ?? '',
            'template' => $pageData['template'] ?? 'page',
            'featured_image' => $pageData['featured_image'] ?? ''
        ];
        
        // Datumsfelder vorbereiten
        if (file_exists($file)) {
            // Bestehendes Erstellungsdatum beibehalten
            $existingPage = $this->getPage($pageData['id']);
            $frontmatter['date_created'] = $existingPage['date_created'] ?? date('Y-m-d');
        } else {
            // Neues Erstellungsdatum
            $frontmatter['date_created'] = date('Y-m-d');
        }
        
        // Änderungsdatum aktualisieren
        $frontmatter['date_modified'] = date('Y-m-d');
        
        // Frontmatter in YAML konvertieren
        $yamlContent = '';
        foreach ($frontmatter as $key => $value) {
            $yamlContent .= $key . ': "' . str_replace('"', '\"', $value) . "\"\n";
        }
        
        // Inhalt zusammensetzen
        $content = "---\n" . $yamlContent . "---\n\n" . $pageData['content'];
        
        // Datei speichern
        if (file_put_contents($file, $content) === false) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Löscht eine Seite
     *
     * @param string $id Seiten-ID
     * @return bool True bei Erfolg
     */
    public function deletePage($id) {
        // Sicherheitscheck
        if (strpos($id, '/') !== false || strpos($id, '\\') !== false) {
            return false;
        }
        
        $file = MARCES_CONTENT_DIR . '/pages/' . $id . '.md';
        
        if (!file_exists($file)) {
            return false;
        }
        
        // Optional: Sicherungskopie erstellen
        $backupDir = MARCES_CONTENT_DIR . '/versions/pages';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $backupFile = $backupDir . '/' . $id . '_' . date('YmdHis') . '.md';
        copy($file, $backupFile);
        
        // Datei löschen
        return unlink($file);
    }
    
    /**
     * Extrahiert Frontmatter aus Dateiinhalt
     *
     * @param string $content Dateiinhalt
     * @return array Frontmatter-Daten
     */
    private function extractFrontmatter($content) {
        $parts = preg_split('/[\r\n]*---[\r\n]+/', $content, 3);
        
        if (count($parts) !== 3) {
            return [];
        }
        
        $frontmatter = $parts[1];
        $metadata = [];
        
        // YAML-Parser
        $lines = explode("\n", $frontmatter);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line)) {
                continue;
            }
            
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                
                // Anführungszeichen entfernen
                if (preg_match('/^["\'](.*)["\'"]$/', $value, $stringMatches)) {
                    $value = $stringMatches[1];
                }
                
                $metadata[$key] = $value;
            }
        }
        
        return $metadata;
    }
    
    /**
     * Generiert einen Slug aus einem String
     *
     * @param string $text Eingangstext
     * @return string Slug
     */
    public function generateSlug($text) {
        // Transliteration (Umlaute etc. umwandeln)
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        // Alles in Kleinbuchstaben umwandeln
        $text = strtolower($text);
        // Alle nicht-alphanumerischen Zeichen durch Bindestriche ersetzen
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        // Führende und nachfolgende Bindestriche entfernen
        $text = trim($text, '-');
        
        return $text;
    }
}