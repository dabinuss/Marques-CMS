<?php
/**
 * marques CMS - Blog Manager Klasse
 * 
 * Verwaltet Blog-Beiträge.
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Core;

class BlogManager {
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
     * Gibt eine Liste aller Blog-Beiträge zurück
     *
     * @param int $limit Maximale Anzahl der Beiträge (0 = alle)
     * @param int $offset Offset für Paginierung
     * @param string $category Optionale Kategorie-Filterung
     * @return array Blog-Beiträge mit Metadaten
     */
    public function getAllPosts($limit = 0, $offset = 0, $category = '') {
        $posts = [];
        $blogDir = MARCES_CONTENT_DIR . '/blog';
        
        if (!is_dir($blogDir)) {
            if (!mkdir($blogDir, 0755, true)) {
                return $posts;
            }
        }
        
        $files = glob($blogDir . '/*.md');
        
        // Nach Datum sortieren (absteigend)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // Offset anwenden
        if ($offset > 0) {
            $files = array_slice($files, $offset);
        }
        
        // Limit anwenden, wenn gesetzt
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }
        
        foreach ($files as $file) {
            $filename = basename($file, '.md');
            $content = file_get_contents($file);
            $metadata = $this->extractFrontmatter($content);
            
            // Kategorie-Filter anwenden
            if (!empty($category) && (!isset($metadata['categories']) || !in_array($category, explode(',', $metadata['categories'])))) {
                continue;
            }
            
            // Slug extrahieren (nach dem letzten "-")
            $pos = strrpos($filename, "-");
            $slug = substr($filename, $pos + 1);
            
            // Date extrahieren (Format: YYYY-MM-DD-slug)
            $dateParts = explode('-', $filename);
            $date = '';
            if (count($dateParts) >= 3) {
                $date = $dateParts[0] . '-' . $dateParts[1] . '-' . $dateParts[2];
            }
            
            $posts[] = [
                'id' => $filename,
                'slug' => $slug,
                'title' => $metadata['title'] ?? $slug,
                'date' => $date,
                'date_created' => $metadata['date_created'] ?? $date,
                'date_modified' => $metadata['date_modified'] ?? $date,
                'author' => $metadata['author'] ?? 'Unbekannt',
                'excerpt' => $metadata['excerpt'] ?? $this->generateExcerpt($content),
                'categories' => isset($metadata['categories']) ? explode(',', $metadata['categories']) : [],
                'tags' => isset($metadata['tags']) ? explode(',', $metadata['tags']) : [],
                'featured_image' => $metadata['featured_image'] ?? '',
                'status' => $metadata['status'] ?? 'published'
            ];
        }
        
        return $posts;
    }
    
    /**
     * Gibt einen einzelnen Blog-Beitrag zurück
     *
     * @param string $id Beitrags-ID (Dateiname ohne .md)
     * @return array|null Beitrag mit Metadaten und Inhalt
     */
    public function getPost($id) {
        // Sicherheitscheck
        if (strpos($id, '/') !== false || strpos($id, '\\') !== false) {
            return null;
        }
        
        $file = MARCES_CONTENT_DIR . '/blog/' . $id . '.md';
        
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
        
        // Slug extrahieren (nach dem letzten "-")
        $pos = strrpos($id, "-");
        $slug = substr($id, $pos + 1);
        
        // Date extrahieren (Format: YYYY-MM-DD-slug)
        $dateParts = explode('-', $id);
        $date = '';
        if (count($dateParts) >= 3) {
            $date = $dateParts[0] . '-' . $dateParts[1] . '-' . $dateParts[2];
        }
        
        return [
            'id' => $id,
            'slug' => $slug,
            'title' => $metadata['title'] ?? $slug,
            'date' => $date,
            'date_created' => $metadata['date_created'] ?? $date,
            'date_modified' => $metadata['date_modified'] ?? $date,
            'author' => $metadata['author'] ?? 'Unbekannt',
            'excerpt' => $metadata['excerpt'] ?? $this->generateExcerpt($body),
            'categories' => isset($metadata['categories']) ? explode(',', $metadata['categories']) : [],
            'tags' => isset($metadata['tags']) ? explode(',', $metadata['tags']) : [],
            'featured_image' => $metadata['featured_image'] ?? '',
            'status' => $metadata['status'] ?? 'published',
            'content' => $body
        ];
    }
    
    /**
     * Erstellt oder aktualisiert einen Blog-Beitrag
     *
     * @param array $postData Beitrags-Daten
     * @return bool|string True oder neue ID bei Erfolg, False bei Fehler
     */
    public function savePost($postData) {
        // VersionManager initialisieren
        $versionManager = new VersionManager();
        $currentUsername = isset($_SESSION['marques_user']) ? $_SESSION['marques_user']['username'] : 'system';
        
        // Verzeichnis erstellen, falls nicht vorhanden
        $blogDir = MARCES_CONTENT_DIR . '/blog';
        if (!is_dir($blogDir)) {
            if (!mkdir($blogDir, 0755, true)) {
                return false;
            }
        }
        
        // Slug generieren, wenn keiner vorhanden ist
        if (empty($postData['slug'])) {
            $postData['slug'] = $this->generateSlug($postData['title']);
        }
        
        // Datum vorbereiten
        $date = isset($postData['date']) && !empty($postData['date']) ? 
                $postData['date'] : date('Y-m-d');
        
        // Datum überprüfen und validieren
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        
        // Neue ID generieren (Format: YYYY-MM-DD-slug)
        $newId = $date . '-' . $postData['slug'];
        
        // Prüfen, ob es sich um eine Aktualisierung handelt
        $isUpdate = !empty($postData['id']);
        $oldFile = null;
        
        if ($isUpdate) {
            $oldFile = MARCES_CONTENT_DIR . '/blog/' . $postData['id'] . '.md';
            
            // Versions-Backup erstellen, wenn Datei existiert
            if (file_exists($oldFile)) {
                $versionManager->createVersion('blog', $postData['id'], file_get_contents($oldFile), $currentUsername);
            }
            
            // Bei Änderung des Slugs/Datums die alte Datei löschen
            if ($postData['id'] !== $newId && file_exists($oldFile)) {
                unlink($oldFile);
            }
        }
        
        // Neue Datei-Pfad
        $file = MARCES_CONTENT_DIR . '/blog/' . $newId . '.md';
        
        // Frontmatter vorbereiten
        $frontmatter = [
            'title' => $postData['title'] ?? '',
            'excerpt' => $postData['excerpt'] ?? '',
            'author' => $postData['author'] ?? $currentUsername,
            'categories' => is_array($postData['categories']) ? implode(',', $postData['categories']) : $postData['categories'],
            'tags' => is_array($postData['tags']) ? implode(',', $postData['tags']) : $postData['tags'],
            'featured_image' => $postData['featured_image'] ?? '',
            'status' => $postData['status'] ?? 'published'
        ];
        
        // Datumsfelder vorbereiten
        if ($isUpdate && file_exists($oldFile)) {
            // Bestehendes Erstellungsdatum beibehalten
            $existingPost = $this->getPost($postData['id']);
            $frontmatter['date_created'] = $existingPost['date_created'] ?? $date;
        } else {
            // Neues Erstellungsdatum
            $frontmatter['date_created'] = $date;
        }
        
        // Änderungsdatum aktualisieren
        $frontmatter['date_modified'] = date('Y-m-d');
        
        // Frontmatter in YAML konvertieren
        $yamlContent = '';
        foreach ($frontmatter as $key => $value) {
            if (!empty($value)) { // Leere Werte überspringen
                $yamlContent .= $key . ': "' . str_replace('"', '\"', $value) . "\"\n";
            }
        }
        
        // Inhalt zusammensetzen
        $content = "---\n" . $yamlContent . "---\n\n";
        // Füge den Content nur hinzu, wenn er existiert
        if (isset($postData['content'])) {
            $content .= $postData['content'];
        } else {
            // Wenn kein neuer Inhalt vorhanden ist, den alten beibehalten
            if ($isUpdate && file_exists($oldFile)) {
                $existingPost = $this->getPost($postData['id']);
                if (isset($existingPost['content'])) {
                    $content .= $existingPost['content'];
                }
            }
        }
        
        // Datei speichern
        if (file_put_contents($file, $content) === false) {
            return false;
        }
        
        // ID zurückgeben für Weiterleitung
        return $newId;
    }
    
    /**
     * Löscht einen Blog-Beitrag
     *
     * @param string $id Beitrags-ID
     * @return bool True bei Erfolg
     */
    public function deletePost($id) {
        // Sicherheitscheck
        if (strpos($id, '/') !== false || strpos($id, '\\') !== false) {
            return false;
        }
        
        $file = MARCES_CONTENT_DIR . '/blog/' . $id . '.md';
        
        if (!file_exists($file)) {
            return false;
        }
        
        // Versions-Backup erstellen
        $versionManager = new VersionManager();
        $currentUsername = isset($_SESSION['marques_user']) ? $_SESSION['marques_user']['username'] : 'system';
        $versionManager->createVersion('blog', $id, file_get_contents($file), $currentUsername . ' (vor Löschung)');
        
        // Datei löschen
        return unlink($file);
    }
    
    /**
     * Gibt alle Kategorien im Blog zurück
     *
     * @return array Kategorien mit Anzahl der Beiträge
     */
    public function getCategories() {
        // Kategoriekatalog laden
        $catalogFile = MARCES_CONFIG_DIR . '/categories.json';
        $catalogCategories = [];
        
        if (file_exists($catalogFile)) {
            $catalogCategories = json_decode(file_get_contents($catalogFile), true) ?: [];
        }
        
        // Kategorien aus Posts zählen
        $posts = $this->getAllPosts();
        $categories = [];
        
        // Erst alle Kategorien aus dem Katalog initialisieren
        foreach ($catalogCategories as $categoryName) {
            $categories[$categoryName] = 0;
        }
        
        // Dann die Zählung aus den Blog-Posts durchführen
        foreach ($posts as $post) {
            foreach ($post['categories'] as $category) {
                $category = trim($category);
                if (!empty($category)) {
                    if (!isset($categories[$category])) {
                        $categories[$category] = 0;
                    }
                    $categories[$category]++;
                }
            }
        }
        
        // Nach Kategorie-Namen sortieren
        ksort($categories);
        
        return $categories;
    }
    
    /**
     * Gibt alle Tags im Blog zurück
     *
     * @return array Tags mit Anzahl der Beiträge
     */
    public function getTags() {
        // Tag-Katalog laden
        $catalogFile = MARCES_CONFIG_DIR . '/tags.json';
        $catalogTags = [];
        
        if (file_exists($catalogFile)) {
            $catalogTags = json_decode(file_get_contents($catalogFile), true) ?: [];
        }
        
        // Tags aus Posts zählen
        $posts = $this->getAllPosts();
        $tags = [];
        
        // Erst alle Tags aus dem Katalog initialisieren
        foreach ($catalogTags as $tagName) {
            $tags[$tagName] = 0;
        }
        
        // Dann die Zählung aus den Blog-Posts durchführen
        foreach ($posts as $post) {
            foreach ($post['tags'] as $tag) {
                $tag = trim($tag);
                if (!empty($tag)) {
                    if (!isset($tags[$tag])) {
                        $tags[$tag] = 0;
                    }
                    $tags[$tag]++;
                }
            }
        }
        
        // Nach Tag-Namen sortieren
        ksort($tags);
        
        return $tags;
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
    
    /**
     * Generiert ein Exzerpt aus dem Inhalt
     *
     * @param string $content Inhalt
     * @param int $length Maximale Länge des Exzerpts
     * @return string Exzerpt
     */
    private function generateExcerpt($content, $length = 150) {
        // Markdown/HTML entfernen
        $text = strip_tags($content);
        
        // Auf maximale Länge kürzen
        if (strlen($text) > $length) {
            $text = substr($text, 0, $length) . '...';
        }
        
        return $text;
    }

    /**
     * Prüft, ob ein Blog-Beitrag mit der angegebenen ID existiert
     *
     * @param int $id Die Beitrags-ID
     * @return bool True, wenn der Beitrag existiert
     */
    public function postExistsById($id) {
        // Implementiere Logik, um Blog-Posts durch ID zu finden
        // Dies könnte eine Datei mit Metadaten sein oder eine Datenbank-Abfrage
        
        // Einfache Implementierung: Suche alle Posts und prüfe auf ID
        $posts = $this->getAllPosts();
        foreach ($posts as $post) {
            if (isset($post['id']) && $post['id'] == $id) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Holt einen Blog-Beitrag anhand seiner ID
     *
     * @param int $id Die Beitrags-ID
     * @return array|null Der Blog-Beitrag oder null, wenn nicht gefunden
     */
    public function getPostById($id) {
        $posts = $this->getAllPosts();
        foreach ($posts as $post) {
            if (isset($post['id']) && $post['id'] == $id) {
                return $post;
            }
        }
        
        return null;
    }

    /**
     * Holt einen Blog-Beitrag anhand eines Dateimuster-Patterns
     *
     * @param string $pattern Das Dateimuster (z.B. "2023-03-*-slug")
     * @return array|null Der Blog-Beitrag oder null, wenn nicht gefunden
     */
    public function getPostByPattern($pattern) {
        $files = glob(MARCES_CONTENT_DIR . '/blog/' . $pattern . '.md');
        if (!empty($files)) {
            // Ersten gefundenen Post verwenden
            $fileInfo = pathinfo($files[0]);
            $postId = $fileInfo['filename']; // Ohne .md-Erweiterung
            return $this->getPost($postId);
        }
        return null;
    }

    /**
     * Holt einen Blog-Beitrag anhand seines Slugs
     *
     * @param string $slug Der Blog-Post-Slug
     * @return array|null Der Blog-Beitrag oder null, wenn nicht gefunden
     */
    public function getPostBySlug($slug) {
        $files = glob(MARCES_CONTENT_DIR . '/blog/*-' . $slug . '.md');
        if (!empty($files)) {
            // Ersten gefundenen Post verwenden
            $fileInfo = pathinfo($files[0]);
            $postId = $fileInfo['filename']; // Ohne .md-Erweiterung
            return $this->getPost($postId);
        }
        return null;
    }

    /**
     * Fügt eine neue Kategorie hinzu
     * 
     * @param string $categoryName Name der Kategorie
     * @return bool Erfolg
     */
    public function addCategory($categoryName) {
        // Kategoriedatei laden
        $catalogFile = MARCES_CONFIG_DIR . '/categories.json';
        $categories = [];
        
        if (file_exists($catalogFile)) {
            $categories = json_decode(file_get_contents($catalogFile), true) ?: [];
        }
        
        // Prüfen, ob Kategorie bereits existiert
        if (in_array($categoryName, $categories)) {
            return false;
        }
        
        // Kategorie hinzufügen
        $categories[] = $categoryName;
        
        // Speichern
        if (file_put_contents($catalogFile, json_encode($categories, JSON_PRETTY_PRINT)) === false) {
            return false;
        }
        
        return true;
    }

    /**
     * Benennt eine Kategorie um
     * 
     * @param string $oldName Alter Kategoriename
     * @param string $newName Neuer Kategoriename
     * @return bool Erfolg
     */
    public function renameCategory($oldName, $newName) {
        if ($oldName === $newName) {
            return true; // Nichts zu tun
        }
        
        // Kategoriedatei laden
        $catalogFile = MARCES_CONFIG_DIR . '/categories.json';
        $categories = [];
        
        if (file_exists($catalogFile)) {
            $categories = json_decode(file_get_contents($catalogFile), true) ?: [];
        }
        
        // Prüfen, ob alte Kategorie existiert
        $oldIndex = array_search($oldName, $categories);
        if ($oldIndex === false) {
            return false;
        }
        
        // Prüfen, ob neue Kategorie bereits existiert
        if (in_array($newName, $categories)) {
            return false;
        }
        
        // Kategorie umbenennen
        $categories[$oldIndex] = $newName;
        
        // Speichern
        if (file_put_contents($catalogFile, json_encode($categories, JSON_PRETTY_PRINT)) === false) {
            return false;
        }
        
        // Alle Beiträge laden und Kategorie umbenennen
        $posts = $this->getAllPosts();
        
        foreach ($posts as $post) {
            $postCategories = $post['categories'];
            $index = array_search($oldName, $postCategories);
            
            if ($index !== false) {
                // Kategorie im Post aktualisieren
                $postCategories[$index] = $newName;
                
                // Post mit neuen Kategorien speichern
                $post['categories'] = $postCategories;
                // Sicherstellen, dass wir den vollständigen Post haben, bevor wir speichern
                $fullPost = $this->getPost($post['id']);
                if ($fullPost) {
                    // Nur die Kategorien aktualisieren
                    $fullPost['categories'] = $postCategories;
                    $this->savePost($fullPost);
                }
            }
        }
        
        return true;
    }

    /**
     * Löscht eine Kategorie
     * 
     * @param string $categoryName Name der zu löschenden Kategorie
     * @return bool Erfolg
     */
    public function deleteCategory($categoryName) {
        // Kategoriedatei laden
        $catalogFile = MARCES_CONFIG_DIR . '/categories.json';
        $categories = [];
        
        if (file_exists($catalogFile)) {
            $categories = json_decode(file_get_contents($catalogFile), true) ?: [];
        }
        
        // Prüfen, ob Kategorie existiert
        $index = array_search($categoryName, $categories);
        if ($index === false) {
            return false;
        }
        
        // Kategorie entfernen
        unset($categories[$index]);
        $categories = array_values($categories); // Indizes neu anordnen
        
        // Speichern
        if (file_put_contents($catalogFile, json_encode($categories, JSON_PRETTY_PRINT)) === false) {
            return false;
        }
        
        // Alle Beiträge laden und Kategorie entfernen
        $posts = $this->getAllPosts();
        
        foreach ($posts as $post) {
            $postCategories = $post['categories'];
            $index = array_search($categoryName, $postCategories);
            
            if ($index !== false) {
                // Kategorie aus dem Post entfernen
                unset($postCategories[$index]);
                $postCategories = array_values($postCategories); // Indizes neu anordnen
                
                // Post mit aktualisierten Kategorien speichern
                $post['categories'] = $postCategories;
                $this->savePost($post);
            }
        }
        
        return true;
    }

    /**
     * Fügt einen neuen Tag hinzu
     * 
     * @param string $tagName Name des Tags
     * @return bool Erfolg
     */
    public function addTag($tagName) {
        // Tag-Datei laden
        $catalogFile = MARCES_CONFIG_DIR . '/tags.json';
        $tags = [];
        
        if (file_exists($catalogFile)) {
            $tags = json_decode(file_get_contents($catalogFile), true) ?: [];
        }
        
        // Prüfen, ob Tag bereits existiert
        if (in_array($tagName, $tags)) {
            return false;
        }
        
        // Tag hinzufügen
        $tags[] = $tagName;
        
        // Speichern
        if (file_put_contents($catalogFile, json_encode($tags, JSON_PRETTY_PRINT)) === false) {
            return false;
        }
        
        return true;
    }

    /**
     * Benennt einen Tag um
     * 
     * @param string $oldName Alter Tagname
     * @param string $newName Neuer Tagname
     * @return bool Erfolg
     */
    public function renameTag($oldName, $newName) {
        if ($oldName === $newName) {
            return true; // Nichts zu tun
        }
        
        // Tag-Datei laden
        $catalogFile = MARCES_CONFIG_DIR . '/tags.json';
        $tags = [];
        
        if (file_exists($catalogFile)) {
            $tags = json_decode(file_get_contents($catalogFile), true) ?: [];
        }
        
        // Prüfen, ob alter Tag existiert
        $oldIndex = array_search($oldName, $tags);
        if ($oldIndex === false) {
            return false;
        }
        
        // Prüfen, ob neuer Tag bereits existiert
        if (in_array($newName, $tags)) {
            return false;
        }
        
        // Tag umbenennen
        $tags[$oldIndex] = $newName;
        
        // Speichern
        if (file_put_contents($catalogFile, json_encode($tags, JSON_PRETTY_PRINT)) === false) {
            return false;
        }
        
        // Alle Beiträge laden und Tag umbenennen
        $posts = $this->getAllPosts();
        
        foreach ($posts as $post) {
            $postTags = $post['tags'];
            $index = array_search($oldName, $postTags);
            
            if ($index !== false) {
                // Tag im Post aktualisieren
                $postTags[$index] = $newName;
                
                // Post mit neuen Tags speichern
                $post['tags'] = $postTags;
                $this->savePost($post);
            }
        }
        
        return true;
    }

    /**
     * Löscht einen Tag
     * 
     * @param string $tagName Name des zu löschenden Tags
     * @return bool Erfolg
     */
    public function deleteTag($tagName) {
        // Tag-Datei laden
        $catalogFile = MARCES_CONFIG_DIR . '/tags.json';
        $tags = [];
        
        if (file_exists($catalogFile)) {
            $tags = json_decode(file_get_contents($catalogFile), true) ?: [];
        }
        
        // Prüfen, ob Tag existiert
        $index = array_search($tagName, $tags);
        if ($index === false) {
            return false;
        }
        
        // Tag entfernen
        unset($tags[$index]);
        $tags = array_values($tags); // Indizes neu anordnen
        
        // Speichern
        if (file_put_contents($catalogFile, json_encode($tags, JSON_PRETTY_PRINT)) === false) {
            return false;
        }
        
        // Alle Beiträge laden und Tag entfernen
        $posts = $this->getAllPosts();
        
        foreach ($posts as $post) {
            $postTags = $post['tags'];
            $index = array_search($tagName, $postTags);
            
            if ($index !== false) {
                // Tag aus dem Post entfernen
                unset($postTags[$index]);
                $postTags = array_values($postTags); // Indizes neu anordnen
                
                // Post mit aktualisierten Tags speichern
                $post['tags'] = $postTags;
                $this->savePost($post);
            }
        }
        
        return true;
    }

    /**
     * Initialisiert die Konfigurationsdateien für Tags und Kategorien
     */
    public function initCatalogFiles() {
        $categoriesFile = MARCES_CONFIG_DIR . '/categories.json';
        $tagsFile = MARCES_CONFIG_DIR . '/tags.json';
        
        // Kategoriedatei initialisieren, wenn sie nicht existiert
        if (!file_exists($categoriesFile)) {
            file_put_contents($categoriesFile, json_encode([], JSON_PRETTY_PRINT));
        }
        
        // Tag-Datei initialisieren, wenn sie nicht existiert
        if (!file_exists($tagsFile)) {
            file_put_contents($tagsFile, json_encode([], JSON_PRETTY_PRINT));
        }
    }
}