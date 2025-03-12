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
    /**
     * Gibt eine Liste aller Blog-Beiträge zurück
     *
     * @param int $limit Maximale Anzahl der Beiträge (0 = alle)
     * @param int $offset Offset für Paginierung
     * @param string $category Optionale Kategorie-Filterung
     * @return array Blog-Beiträge mit Metadaten
     */
    /**
     * Gibt eine Liste aller Blog-Beiträge zurück
     *
     * @param int $limit Maximale Anzahl der Beiträge (0 = alle)
     * @param int $offset Offset für Paginierung
     * @param string $category Optionale Kategorie-Filterung
     * @return array Blog-Beiträge mit Metadaten
     */
    public function getAllPosts(int $limit = 0, int $offset = 0, string $category = ''): array {


        /*
        $testGlobPath = MARQUES_ROOT_DIR . '/content/blog'; // TEST: Direkter Pfad, *ANPASSEN*, falls nötig

        marques_debug("--- TEST GLOB() ---");
        marques_debug("Test-Pfad VOR glob() Aufruf: ".$testGlobPath, true); // DEBUG: Pfad VOR glob() ausgeben!

        $testGlobResult = glob($testGlobPath . '/*', GLOB_ONLYDIR); // TEST: Einfaches glob('*')
        marques_debug("Ergebnis von glob() TEST: ".$testGlobResult, true);
        marques_debug("--- ENDE TEST GLOB() ---");
        */


        $posts = [];
        $blogDir = MARQUES_CONTENT_DIR . '/blog';
        if (!is_dir($blogDir)) {
            marques_debug("Blog-Verzeichnis existiert nicht: " . $blogDir); // DEBUG
            return $posts;
        }

        $files = [];
        $yearDirs = glob($blogDir . '/*', GLOB_ONLYDIR); // Finde *alle* Ordner direkt unterhalb von content/blog/
        // marques_debug("Jahr-Verzeichnisse gefunden:" . $yearDirs); // DEBUG
        if ($yearDirs) {
            foreach ($yearDirs as $yearDir) {
                $monthDirs = glob($yearDir . '/[A-M]', GLOB_ONLYDIR);
                // marques_debug("Monats-Verzeichnisse in " . $yearDir . ":" . $monthDirs); // DEBUG
                if ($monthDirs) {
                    foreach ($monthDirs as $monthDir) {
                        $postFiles = glob($monthDir . '/*.md');
                        // marques_debug("Post-Dateien in " . $monthDir . ": " . $postFiles); // DEBUG
                        if ($postFiles) {
                            $files = array_merge($files, $postFiles);
                        }
                    }
                }
            }
        }

        // marques_debug("Anzahl der gefundenen Dateien vor Paginierung/Limitierung: " . count($files)); // DEBUG

        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        if ($offset > 0) {
            $files = array_slice($files, $offset);
        }
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }

        // marques_debug("Anzahl der Dateien nach Paginierung/Limitierung: " . count($files)); // DEBUG
        // marques_debug("Verwendete Dateien (Pfade): " . $files); // DEBUG

        foreach ($files as $file) {
            $relativePath = str_replace(MARQUES_CONTENT_DIR . DIRECTORY_SEPARATOR, '', $file);
            $filename = basename($file, '.md');
            $content = $this->fileManager->readFile($relativePath);
            $metadata = $this->extractFrontmatter($content);

            if (!empty($category) && (!isset($metadata['categories']) || !in_array($category, explode(',', $metadata['categories'])))) {
                continue;
            }

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
                'excerpt' => $metadata['excerpt'] ?? $this->generateExcerpt($this->getBodyContent($content)), // Korrigiert!
                'categories' => isset($metadata['categories']) ? explode(',', $metadata['categories']) : [],
                'tags' => isset($metadata['tags']) ? explode(',', $metadata['tags']) : [],
                'featured_image' => $metadata['featured_image'] ?? '',
                'status' => $metadata['status'] ?? 'published'
            ];
        }

        // marques_debug("Erstelltes $posts Array (vor Rückgabe): " . $posts); // DEBUG
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
            'excerpt' => $metadata['excerpt'] ?? $this->generateExcerpt($body), // Korrektur: Excerpt vom Body-Content generieren
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
            'title' => $postData['title'] ?? '',
            'excerpt' => $postData['excerpt'] ?? '',
            'author' => $postData['author'] ?? $currentUsername,
            'categories' => is_array($postData['categories']) ? implode(',', $postData['categories']) : $postData['categories'],
            'tags' => is_array($postData['tags']) ? implode(',', $postData['tags']) : $postData['tags'],
            'featured_image' => $postData['featured_image'] ?? '',
            'status' => $postData['status'] ?? 'published',
            'slug' => $postData['slug']
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

        $this->updatePostUrlMapping($newId, $postData['slug']); // Korrektur: URL-Mapping nach dem Speichern aktualisieren

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
     * Aktualisiert das URL-Mapping für einen Blog-Beitrag.
     *
     * @param string $postId Interne Beitrags-ID
     * @param string $slug Slug des Beitrags
     * @return bool Erfolgreich aktualisiert oder nicht.
     */
    private function updatePostUrlMapping(string $postId, string $slug): bool {
        $configManager = ConfigManager::getInstance();
        $urlMapping = $configManager->loadUrlMapping() ?: []; // Bestehendes Mapping laden oder leeres Array
        $newPath = 'blog/' . $slug; // URL-Pfad basierend auf Slug (anpassbar)

        // Pfad normalisieren, um führende/doppelte Slashes zu vermeiden
        $newPath = trim($newPath, '/');

        // Prüfen, ob der neue Pfad bereits für einen anderen Beitrag verwendet wird
        foreach ($urlMapping as $id => $path) {
            if ($id !== $postId && $path === $newPath) {
                // Pfad ist bereits vergeben -> Konflikt, Mapping nicht erstellen
                error_log("URL-Mapping-Konflikt: Pfad '$newPath' bereits für Beitrag '$id' vergeben. Mapping für '$postId' nicht aktualisiert.");
                return false; // Mapping nicht aktualisiert
            }
        }

        if (isset($urlMapping[$postId]) && $urlMapping[$postId] === $newPath) {
            return true; // Kein Update nötig, Mapping ist bereits aktuell
        }

        // Mapping hinzufügen oder aktualisieren
        $urlMapping[$postId] = $newPath;

        if ($configManager->updateUrlMapping($urlMapping)) {
            // Erfolg beim Speichern des Mappings
            return true;
        } else {
            // Fehler beim Speichern des Mappings
            error_log("Fehler beim Aktualisieren des URL-Mappings für Beitrag '$postId'.");
            return false;
        }
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

        $postId = $id; // Beitrags-ID für URL-Mapping-Löschung

        $versionManager = new VersionManager();
        $currentUsername = isset($_SESSION['marques_user']) ? $_SESSION['marques_user']['username'] : 'system';
        $versionManager->createVersion('blog', $id, $this->fileManager->readFile($filePath), $currentUsername . ' (vor Löschung)');

        // URL-Mapping Eintrag entfernen
        $this->deletePostUrlMapping($postId); // Neu: URL-Mapping Eintrag löschen

        return $this->fileManager->deleteFile($filePath);
    }

    /**
    * Löscht den URL-Mapping Eintrag für einen Blog-Beitrag.
    *
    * @param string $postId Interne Beitrags-ID
    * @return bool Erfolg
    */
    private function deletePostUrlMapping(string $postId): bool {
        $configManager = ConfigManager::getInstance();
        $urlMapping = $configManager->loadUrlMapping() ?: [];

        unset($urlMapping[$postId]); // Eintrag aus dem Array entfernen

        return $configManager->updateUrlMapping($urlMapping); // Aktualisiertes Mapping speichern
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
            if (isset($post['categories']) && is_array($post['categories'])) { // Korrektur: Prüfen, ob 'categories' existiert und ein Array ist
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
            if (isset($post['tags']) && is_array($post['tags'])) { // Korrektur: Prüfen, ob 'tags' existiert und ein Array ist
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
            if (preg_match('/^([^:]+):\s*(?:"([^"]*)"|([^"]*))$/', $line, $matches)) { // Korrektur: Regex für optionale Anführungszeichen
                $key = trim($matches[1]);
                $value = trim($matches[2] !== "" ? $matches[2] : $matches[3]); // Wert aus Gruppe 2 oder 3 nehmen
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
        return $configManager->save('categories', array_values(array_unique($categories))); // Korrektur: Doppelte Einträge entfernen und Indizes neu ordnen
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
        if (!$configManager->save('categories', array_values(array_unique($categories)))) { // Korrektur: Doppelte Einträge entfernen und Indizes neu ordnen
            return false;
        }

        // Alle Beiträge laden und Kategorie umbenennen
        $posts = $this->getAllPosts();

        foreach ($posts as $post) {
            $postCategories = $post['categories'];
            if (is_array($postCategories)) { // Sicherheitshalber prüfen, ob Kategorien ein Array sind
                $index = array_search($oldName, $postCategories, true);

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
        if (!$configManager->save('categories', array_values(array_unique($categories)))) { // Korrektur: Doppelte Einträge entfernen und Indizes neu ordnen
            return false;
        }

        // Alle Beiträge laden und Kategorie entfernen
        $posts = $this->getAllPosts();

        foreach ($posts as $post) {
            $postCategories = $post['categories'];
            if (is_array($postCategories)) { // Sicherheitshalber prüfen, ob Kategorien ein Array sind
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
        return $configManager->save('tags', array_values(array_unique($tags))); // Korrektur: Doppelte Einträge entfernen und Indizes neu ordnen
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
        if (!$configManager->save('tags', array_values(array_unique($tags)))) { // Korrektur: Doppelte Einträge entfernen und Indizes neu ordnen
            return false;
        }

        // Alle Beiträge laden und Tag umbenennen
        $posts = $this->getAllPosts();

        foreach ($posts as $post) {
            $postTags = $post['tags'];
            if (is_array($postTags)) { // Sicherheitshalber prüfen, ob Tags ein Array sind
                $index = array_search($oldName, $postTags, true);

                if ($index !== false) {
                    // Tag im Post aktualisieren
                    $postTags[$index] = $newName;

                    // Post mit neuen Tags speichern
                    $post['tags'] = $postTags;
                    $this->savePost($post);
                }
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
        if (!$configManager->save('tags', array_values(array_unique($tags)))) { // Korrektur: Doppelte Einträge entfernen und Indizes neu ordnen
            return false;
        }

        // Alle Beiträge laden und Tag entfernen
        $posts = $this->getAllPosts();

        foreach ($posts as $post) {
            $postTags = $post['tags'];
            if (is_array($postTags)) { // Sicherheitshalber prüfen, ob Tags ein Array sind
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

    /**
     * Holt nur den Body-Content (Inhalt nach dem Frontmatter) aus einem Blogpost-Content-String.
     *
     * @param string $content Der gesamte Blogpost-Content (mit Frontmatter)
     * @return string Der Body-Content
     */
    private function getBodyContent(string $content): string {
        $parts = preg_split('/[\r\n]*---[\r\n]+/', $content, 3);
        if (count($parts) === 3) {
            return $parts[2]; // Body-Content ist der dritte Teil
        }
        return $content; // Fallback: Gesamten Content zurückgeben, wenn kein Frontmatter gefunden
    }
}