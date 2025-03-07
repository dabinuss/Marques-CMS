<?php
declare(strict_types=1);

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
     * @var FileManager
     */
    protected FileManager $fileManager;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $configManager = ConfigManager::getInstance();
        $this->_config = $configManager->load('system') ?: [];
        $this->fileManager = new FileManager(MARQUES_CONTENT_DIR);
    }
    
    /**
     * Gibt eine Liste aller Blog-Beiträge zurück
     *
     * @param int $limit Maximale Anzahl der Beiträge (0 = alle)
     * @param int $offset Offset für Paginierung
     * @param string $category Optionale Kategorie-Filterung
     * @return array Blog-Beiträge mit Metadaten
     */
    public function getAllPosts(int $limit = 0, int $offset = 0, string $category = ''): array {
        $posts = [];
        // Suche in der neuen Ordnerstruktur:
        $files = $this->fileManager->glob('blog/*/*/*.md');
        
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        if ($offset > 0) {
            $files = array_slice($files, $offset);
        }
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }
        
        foreach ($files as $file) {
            $filename = basename($file, '.md'); // z. B. "000-25C"
            $content = $this->fileManager->readFile($file);
            $metadata = $this->extractFrontmatter($content);
            
            // Kategorie-Filter anwenden
            if (!empty($category) && (!isset($metadata['categories']) || !in_array($category, explode(',', $metadata['categories'])))) {
                continue;
            }
            
            // Da in unserem System die interne ID zugleich der Dateiname ist,
            // wird der Slug idealerweise im Frontmatter (bzw. im URL-Mapping) festgelegt.
            $slug = $metadata['slug'] ?? '';
            $date = $metadata['date'] ?? '';
            
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
        if (strpos($id, '/') !== false || strpos($id, '\\') !== false) {
            return null;
        }
        // Berechne den Pfad anhand der neuen Ordnerstruktur
        $filePath = $this->getFilePathFromId($id);
        if (!$this->fileManager->exists($filePath)) {
            return null;
        }
        
        $content = $this->fileManager->readFile($filePath);
        $partsContent = preg_split('/[\r\n]*---[\r\n]+/', $content, 3);
        $metadata = [];
        $body = '';
        if (count($partsContent) === 3) {
            $metadata = $this->extractFrontmatter($content);
            $body = $partsContent[2];
        } else {
            $body = $content;
        }
        
        $slug = $metadata['slug'] ?? '';
        $date = $metadata['date'] ?? '';
        
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
        $versionManager = new VersionManager();
        $currentUsername = isset($_SESSION['marques_user']) ? $_SESSION['marques_user']['username'] : 'system';
        
        if (!$this->fileManager->exists('blog')) {
            $this->fileManager->createDirectory('blog');
        }
        
        if (empty($postData['slug'])) {
            $postData['slug'] = $this->generateSlug($postData['title']);
        }
        
        $date = isset($postData['date']) && !empty($postData['date']) ? $postData['date'] : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        
        // Neue interne ID im Format "000-25C" erzeugen, wenn nicht bereits vorhanden
        if (empty($postData['id'])) {
            $newId = $this->generateNewId($postData, $date);
        } else {
            $newId = $postData['id'];
        }
        
        $parts = explode('-', $newId);
        $yearMonth = $parts[1]; // z. B. "25C"
        $year = substr($yearMonth, 0, 2);
        $month = substr($yearMonth, 2, 1);
        
        $filePath = 'blog/' . $year . '/' . $month . '/' . $newId . '.md';
        $this->fileManager->createDirectory('blog/' . $year . '/' . $month);
        
        $frontmatter = [
            'title'          => $postData['title'] ?? '',
            'excerpt'        => $postData['excerpt'] ?? '',
            'author'         => $postData['author'] ?? $currentUsername,
            'categories'     => is_array($postData['categories']) ? implode(',', $postData['categories']) : $postData['categories'],
            'tags'           => is_array($postData['tags']) ? implode(',', $postData['tags']) : $postData['tags'],
            'featured_image' => $postData['featured_image'] ?? '',
            'status'         => $postData['status'] ?? 'published',
            'slug'           => $postData['slug']
        ];
        
        if (!empty($postData['id']) && $this->getPost($postData['id'])) {
            $existingPost = $this->getPost($postData['id']);
            $frontmatter['date_created'] = $existingPost['date_created'] ?? $date;
        } else {
            $frontmatter['date_created'] = $date;
        }
        $frontmatter['date_modified'] = date('Y-m-d');
        $frontmatter['date'] = $date;
        
        $yamlContent = "";
        foreach ($frontmatter as $key => $value) {
            if (!empty($value)) {
                $yamlContent .= $key . ': "' . str_replace('"', '\"', $value) . "\"\n";
            }
        }
        
        $content = "---\n" . $yamlContent . "---\n\n";
        if (isset($postData['content'])) {
            $content .= $postData['content'];
        } else if (!empty($postData['id']) && $this->getPost($postData['id'])) {
            $existingPost = $this->getPost($postData['id']);
            if (isset($existingPost['content'])) {
                $content .= $existingPost['content'];
            }
        }
        
        if (!empty($postData['id'])) {
            $oldFilePath = $this->getFilePathFromId($postData['id']);
            if ($oldFilePath && $postData['id'] !== $newId && $this->fileManager->exists($oldFilePath)) {
                $versionManager->createVersion('blog', $postData['id'], $this->fileManager->readFile($oldFilePath), $currentUsername);
                $this->fileManager->deleteFile($oldFilePath);
            } elseif ($this->fileManager->exists($oldFilePath)) {
                $versionManager->createVersion('blog', $postData['id'], $this->fileManager->readFile($oldFilePath), $currentUsername);
            }
        }
        
        if (!$this->fileManager->writeFile($filePath, $content)) {
            return false;
        }
        
        // Rückgabe der internen ID – das URL-Mapping (id → slug) wird vom Router über den ConfigManager verwaltet.
        return $newId;
    }

    private function getFilePathFromId(string $id): ?string {
        $parts = explode('-', $id);
        if (count($parts) !== 2) {
            return null;
        }
        $yearMonth = $parts[1];
        $year = substr($yearMonth, 0, 2);
        $month = substr($yearMonth, 2, 1);
        return 'blog/' . $year . '/' . $month . '/' . $id . '.md';
    }
    
    /**
     * Löscht einen Blog-Beitrag
     *
     * @param string $id Beitrags-ID
     * @return bool True bei Erfolg
     */
    public function deletePost($id) {
        if (strpos($id, '/') !== false || strpos($id, '\\') !== false) {
            return false;
        }
        
        $filePath = $this->getFilePathFromId($id);
        if (!$this->fileManager->exists($filePath)) {
            return false;
        }
        
        $versionManager = new VersionManager();
        $currentUsername = isset($_SESSION['marques_user']) ? $_SESSION['marques_user']['username'] : 'system';
        $versionManager->createVersion('blog', $id, $this->fileManager->readFile($filePath), $currentUsername . ' (vor Löschung)');
        
        return $this->fileManager->deleteFile($filePath);
    }
    
    /**
     * Gibt alle Kategorien im Blog zurück
     *
     * @return array Kategorien mit Anzahl der Beiträge
     */
    public function getCategories() {
        // ConfigManager verwenden
        $configManager = ConfigManager::getInstance();
        $catalogCategories = $configManager->load('categories') ?: [];
        
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
        // ConfigManager verwenden
        $configManager = ConfigManager::getInstance();
        $catalogTags = $configManager->load('tags') ?: [];
        
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
    private function extractFrontmatter($content): array {
        if (!is_string($content) || trim($content) === '') {
            return [];
        }
        
        $parts = preg_split('/[\r\n]*---[\r\n]+/', $content, 3);
        
        if (count($parts) !== 3) {
            return [];
        }
        
        $frontmatter = $parts[1];
        $metadata = [];
        
        $lines = explode("\n", $frontmatter);
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
    private function generateSlug($text): string {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }
    
    /**
     * Generiert ein Exzerpt aus dem Inhalt
     *
     * @param string $content Inhalt
     * @param int $length Maximale Länge des Exzerpts
     * @return string Exzerpt
     */
    private function generateExcerpt($content, $length = 150): string {
        if (!is_string($content)) {
            return '';
        }
        $text = strip_tags($content);
        if (strlen($text) > $length) {
            $text = substr($text, 0, $length) . '...';
        }
        return $text;
    }

    /**
     * Erzeugt eine neue interne ID im Format "000-25C".
     */
    private function generateNewId(array $postData, string $date): string {
        $configManager = ConfigManager::getInstance();
        $counter = $configManager->get('blog', 'id_counter', 0);
        $counter++;
        $configManager->set('blog', 'id_counter', $counter);
        $yearTwoDigit = substr($date, 2, 2);
        $monthNum = (int)substr($date, 5, 2);
        $monthLetter = chr(64 + $monthNum);
        return sprintf("%03d", $counter) . '-' . $yearTwoDigit . $monthLetter;
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
        $files = glob(MARQUES_CONTENT_DIR . '/blog/' . $pattern . '.md');
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
        $files = glob(MARQUES_CONTENT_DIR . '/blog/*-' . $slug . '.md');
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
        // ConfigManager verwenden
        $configManager = ConfigManager::getInstance();
        $categories = $configManager->load('categories') ?: [];
        
        // Prüfen, ob Kategorie bereits existiert
        if (in_array($categoryName, $categories)) {
            return false;
        }
        
        // Kategorie hinzufügen
        $categories[] = $categoryName;
        
        // Speichern
        return $configManager->save('categories', $categories);
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
        
        // ConfigManager verwenden
        $configManager = ConfigManager::getInstance();
        $categories = $configManager->load('categories') ?: [];
        
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
        if (!$configManager->save('categories', $categories)) {
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
        // ConfigManager verwenden
        $configManager = ConfigManager::getInstance();
        $categories = $configManager->load('categories') ?: [];
        
        // Prüfen, ob Kategorie existiert
        $index = array_search($categoryName, $categories);
        if ($index === false) {
            return false;
        }
        
        // Kategorie entfernen
        unset($categories[$index]);
        $categories = array_values($categories); // Indizes neu anordnen
        
        // Speichern
        if (!$configManager->save('categories', $categories)) {
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
        // ConfigManager verwenden
        $configManager = ConfigManager::getInstance();
        $tags = $configManager->load('tags') ?: [];
        
        // Prüfen, ob Tag bereits existiert
        if (in_array($tagName, $tags)) {
            return false;
        }
        
        // Tag hinzufügen
        $tags[] = $tagName;
        
        // Speichern
        return $configManager->save('tags', $tags);
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
        
        // ConfigManager verwenden
        $configManager = ConfigManager::getInstance();
        $tags = $configManager->load('tags') ?: [];
        
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
        if (!$configManager->save('tags', $tags)) {
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
        // ConfigManager verwenden
        $configManager = ConfigManager::getInstance();
        $tags = $configManager->load('tags') ?: [];
        
        // Prüfen, ob Tag existiert
        $index = array_search($tagName, $tags);
        if ($index === false) {
            return false;
        }
        
        // Tag entfernen
        unset($tags[$index]);
        $tags = array_values($tags); // Indizes neu anordnen
        
        // Speichern
        if (!$configManager->save('tags', $tags)) {
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
        $configManager = ConfigManager::getInstance();
        
        // Kategoriedatei initialisieren, wenn sie nicht existiert
        $categories = $configManager->load('categories');
        if (empty($categories)) {
            $configManager->save('categories', []);
        }
        
        // Tag-Datei initialisieren, wenn sie nicht existiert
        $tags = $configManager->load('tags');
        if (empty($tags)) {
            $configManager->save('tags', []);
        }
    }
}