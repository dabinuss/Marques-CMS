<?php
declare(strict_types=1);

namespace Admin\Controller;

use Marques\Service\PageManager;
use Marques\Util\Helper;
use Marques\Service\VersionManager; // Fehlender Import

use Admin\Core\Template;

class PageController
{
    private Template $adminTemplate;
    private PageManager $pageManager;
    private Helper $helper;

    public function __construct(Template $adminTemplate, PageManager $pageManager, Helper $helper) {
        $this->adminTemplate = $adminTemplate;
        $this->pageManager = $pageManager;
        $this->helper = $helper;
    }

    /**
     * Erstellt oder aktualisiert eine Seite
     *
     * @param array $pageData Seiten-Daten
     * @return bool True bei Erfolg
     */
    public function savePage(array $pageData): bool {
        if (empty($pageData['id'])) {
            // Neue Seite - ID aus Titel generieren
            if (empty($pageData['title'])) {
                return false; // Fehler: Kein Titel für neue Seite angegeben
            }
            $pageData['id'] = $this->pageManager->generateSlug($pageData['title']); // Korrigiert
        }
        
        // Sicherheitscheck
        if (!$this->isValidFileId($pageData['id'])) {
            return false;
        }
        
        $pagesDir = MARQUES_CONTENT_DIR . '/pages';
        
        // Stelle sicher, dass das Verzeichnis existiert
        if (!is_dir($pagesDir) && !mkdir($pagesDir, 0755, true)) {
            return false; // Konnte Verzeichnis nicht erstellen
        }
        
        $file = $pagesDir . '/' . $pageData['id'] . '.md';
        
        // Wenn Datei existiert, zuvor eine Version erstellen
        if (file_exists($file) && is_readable($file)) {
            // Version erstellen
            try {
                $versionManager = new VersionManager();
                $currentUsername = isset($_SESSION['marques_user']) ? $_SESSION['marques_user']['username'] : 'system';
                $versionManager->createVersion('pages', $pageData['id'], file_get_contents($file), $currentUsername);
            } catch (\Exception $e) {
                // Fehlgeschlagene Versionierung sollte nicht das Speichern verhindern
                // Optional: Logging
            }
        }
        
        // Frontmatter vorbereiten
        $frontmatter = [
            'title' => $pageData['title'] ?? '',
            'description' => $pageData['description'] ?? '',
            'template' => $pageData['template'] ?? 'page',
            'featured_image' => $pageData['featured_image'] ?? ''
        ];
        
        // Datumsfelder vorbereiten
        if (file_exists($file) && is_readable($file)) {
            try {
                // Bestehendes Erstellungsdatum beibehalten
                $existingPage = $this->pageManager->getPage($pageData['id']); // Korrigiert
                $frontmatter['date_created'] = $existingPage['date_created'] ?? date('Y-m-d');
            } catch (\Exception $e) {
                $frontmatter['date_created'] = date('Y-m-d');
            }
        } else {
            // Neues Erstellungsdatum
            $frontmatter['date_created'] = date('Y-m-d');
        }
        
        // Änderungsdatum aktualisieren
        $frontmatter['date_modified'] = date('Y-m-d');
        
        // Verbesserte Frontmatter-Erstellung mit Escape für Sonderzeichen
        $yamlContent = '';
        foreach ($frontmatter as $key => $value) {
            // Behandle leere und komplexe Werte korrekt
            if (empty($value)) {
                $yamlContent .= $key . ": \"\"\n";
            } elseif (preg_match('/[\r\n:"\'\\\\]/', $value)) {
                // Für Werte mit Sonderzeichen: als mehrzeiliger Block oder escaped
                $escaped = str_replace('"', '\"', $value);
                $yamlContent .= $key . ": \"" . $escaped . "\"\n";
            } else {
                $yamlContent .= $key . ": " . $value . "\n";
            }
        }
        
        // Inhalt zusammensetzen
        $content = "---\n" . $yamlContent . "---\n\n" . ($pageData['content'] ?? '');
        
        // Stelle sicher, dass die Datei schreibbar ist
        if (file_exists($file) && !is_writable($file)) {
            chmod($file, 0644); // Versuche Schreibberechtigung zu setzen
        }
        
        // Atomares Schreiben mit temporärer Datei für bessere Fehlerbehandlung
        $tempFile = tempnam(sys_get_temp_dir(), 'marques_');
        if ($tempFile === false) {
            return false;
        }
        
        if (file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            return false;
        }
        
        if (!rename($tempFile, $file)) {
            @unlink($tempFile);
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
    public function deletePage(string $id): bool {
        // Sicherheitscheck
        if (strpos($id, '/') !== false || strpos($id, '\\') !== false) {
            return false;
        }
        
        $file = MARQUES_CONTENT_DIR . '/pages/' . $id . '.md';
        
        if (!file_exists($file)) {
            return false;
        }
        
        // Optional: Sicherungskopie erstellen
        $backupDir = MARQUES_CONTENT_DIR . '/versions/pages';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $backupFile = $backupDir . '/' . $id . '_' . date('YmdHis') . '.md';
        copy($file, $backupFile);
        
        // Datei löschen
        return unlink($file);
    }

    /**
     * Sicherheitsüberprüfung für Datei-IDs
     */
    private function isValidFileId(string $id): bool {
        return preg_match('/^[a-z0-9_-]+$/i', $id) && 
               strpos($id, '/') === false && 
               strpos($id, '\\') === false;
    }

}