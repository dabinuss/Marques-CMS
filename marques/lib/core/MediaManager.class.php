<?php
declare(strict_types=1);

/**
 * marques CMS - Media Manager Klasse
 * 
 * Behandelt Medienverwaltung.
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Core;

class MediaManager {
    /**
     * @var array Systemkonfiguration
     */
    private $_config;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $configManager = \Marques\Core\AppConfig::getInstance();
        $this->_config = $configManager->load('system') ?: [];
    }
    
    /**
     * Gibt alle Medien zurück mit Cache-Busting
     * 
     * @return array Liste aller Medien
     */
    public function getAllMedia(): array {
        $mediaDir = MARQUES_ROOT_DIR . '/assets/media';
        $media = [];
        
        if (is_dir($mediaDir)) {
            $files = scandir($mediaDir);
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                $filePath = $mediaDir . '/' . $file;
                
                if (is_file($filePath)) {
                    $fileInfo = pathinfo($filePath);
                    $filesize = $this->formatFileSize(filesize($filePath));
                    $filetype = mime_content_type($filePath);
                    $dimensions = '';
                    
                    // Bei Bildern die Dimensionen ermitteln
                    if (strpos($filetype, 'image/') === 0) {
                        $imageSize = getimagesize($filePath);
                        if ($imageSize) {
                            $dimensions = $imageSize[0] . ' x ' . $imageSize[1] . ' px';
                        }
                    }
                    
                    // Zeitstempel für Cache-Busting
                    $timestamp = filemtime($filePath);
                    
                    $media[] = [
                        'filename' => $file,
                        'filesize' => $filesize,
                        'filetype' => $filetype,
                        'dimensions' => $dimensions,
                        'url' => 'assets/media/' . $file,
                        'timestamp' => $timestamp,
                        'cache_bust_url' => 'assets/media/' . $file . '?v=' . $timestamp
                    ];
                }
            }
        }
        
        return $media;
    }

    /**
     * Löscht eine Mediendatei und bereinigt alle Verweise in Seiteninhalten
     * 
     * @param string $filename Der Dateiname der zu löschenden Datei
     * @return bool Erfolgsstatus
     */
    public function deleteMedia(string $filename): bool {
        $filePath = MARQUES_ROOT_DIR . '/assets/media/' . $filename;
        
        // Prüfen, ob die Datei existiert
        if (!file_exists($filePath)) {
            return false;
        }
        
        // Bei einem Flatfile-CMS die relevanten Inhaltsdateien suchen
        $usageInfo = $this->findMediaUsage($filename);
        
        // Wenn das Medium verwendet wird, Inhalte bereinigen
        if (!empty($usageInfo)) {
            $this->cleanupMediaReferences($filename, $usageInfo);
        }
        
        // Datei löschen
        if (unlink($filePath)) {
            return true;
        }
        
        return false;
    }

    /**
     * Findet alle Verwendungen eines Mediums in Seiteninhalten
     * Angepasst für Flatfile-CMS
     * 
     * @param string $filename Der Dateiname des zu suchenden Mediums
     * @return array Informationen über die Verwendung des Mediums
     */
    public function findMediaUsage($filename) {
        // Wir müssen sowohl mit als auch ohne Domain-Pfad suchen
        $baseMediaUrl = 'assets/media/' . $filename;
        $usageInfo = [];
        
        // Protocol und Domain bestimmen für den absoluten Pfad
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'];
        
        // Basis-URL für Suche ohne "admin"-Verzeichnis
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']); // z.B. /marques/admin
        $baseUrlPath = dirname($scriptPath); // z.B. /marques
        $baseUrlPath = $baseUrlPath === '/' ? '' : $baseUrlPath;
        
        // Absoluter Pfad mit Domain
        $absoluteMediaUrl = $protocol . '://' . $domain . $baseUrlPath . '/' . $baseMediaUrl;
        
        // Mögliche URLs, die wir suchen müssen (relativ und absolut)
        $searchUrls = [
            $baseMediaUrl,
            $absoluteMediaUrl,
            // Auch Varianten mit Cache-Busting Parameter berücksichtigen
            $baseMediaUrl . '?v=',
            $absoluteMediaUrl . '?v='
        ];
        
        // Durchsuche die content-Verzeichnisse nach Verweisen auf das Medium
        $contentDirs = [
            MARQUES_ROOT_DIR . '/content/pages',
            MARQUES_ROOT_DIR . '/content/blog'
        ];
        
        foreach ($contentDirs as $dir) {
            if (is_dir($dir)) {
                foreach ($searchUrls as $searchUrl) {
                    $this->scanDirForMediaUsage($dir, $searchUrl, $usageInfo);
                }
            }
        }
        
        return $usageInfo;
    }
    
    /**
     * Scannt ein Verzeichnis rekursiv nach Verwendungen eines Mediums
     * 
     * @param string $dir Das zu durchsuchende Verzeichnis
     * @param string $mediaUrl Die URL des zu suchenden Mediums
     * @param array &$usageInfo Referenz zum Array für die gefundenen Verwendungen
     */
    private function scanDirForMediaUsage($dir, $mediaUrl, &$usageInfo) {
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                // Rekursiver Aufruf für Unterverzeichnisse
                $this->scanDirForMediaUsage($path, $mediaUrl, $usageInfo);
            } else {
                // Überprüfe, ob es sich um eine JSON oder Markdown-Datei handelt
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                if (in_array($ext, ['json', 'md', 'html'])) {
                    $content = file_get_contents($path);
                    
                    // Prüfen, ob die Datei den Media-Link enthält
                    if (strpos($content, $mediaUrl) !== false) {
                        // Duplikate vermeiden (wenn derselbe Pfad bereits erfasst wurde)
                        $isDuplicate = false;
                        foreach ($usageInfo as $info) {
                            if ($info['path'] === $path) {
                                $isDuplicate = true;
                                break;
                            }
                        }
                        
                        if (!$isDuplicate) {
                            $usageInfo[] = [
                                'path' => $path,
                                'title' => $file
                            ];
                        }
                    }
                }
            }
        }
    }

    /**
     * Bereinigt Verweise auf ein gelöschtes Medium in allen Inhalten
     * Angepasst für Flatfile-CMS
     * 
     * @param string $filename Der Dateiname des gelöschten Mediums
     * @param array $usageInfo Informationen über die Verwendung des Mediums
     * @return bool Erfolgsstatus
     */
    public function cleanupMediaReferences($filename, $usageInfo) {
        // Protocol und Domain bestimmen für den absoluten Pfad
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'];
        
        // Basis-URL für Suche ohne "admin"-Verzeichnis
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']); // z.B. /marques/admin
        $baseUrlPath = dirname($scriptPath); // z.B. /marques
        $baseUrlPath = $baseUrlPath === '/' ? '' : $baseUrlPath;
        
        // Basis und absolute URL
        $baseMediaUrl = 'assets/media/' . $filename;
        $absoluteMediaUrl = $protocol . '://' . $domain . $baseUrlPath . '/' . $baseMediaUrl;
        
        // Muster für verschiedene Arten von URLs (mit und ohne Cache-Busting)
        $patterns = [
            // Relative URLs ohne Parameter
            '/<img[^>]*src=["\']' . preg_quote($baseMediaUrl, '/') . '["\'][^>]*>/i',
            // Relative URLs mit Cache-Busting
            '/<img[^>]*src=["\']' . preg_quote($baseMediaUrl, '/') . '\?v=[0-9]+["\'][^>]*>/i',
            // Absolute URLs ohne Parameter
            '/<img[^>]*src=["\']' . preg_quote($absoluteMediaUrl, '/') . '["\'][^>]*>/i',
            // Absolute URLs mit Cache-Busting
            '/<img[^>]*src=["\']' . preg_quote($absoluteMediaUrl, '/') . '\?v=[0-9]+["\'][^>]*>/i'
        ];
        
        $broken_img_placeholder = '<span class="broken-image">[Bild nicht verfügbar]</span>';
        
        // Iteriere über alle gefundenen Dateien und ersetze die Medienreferenzen
        foreach ($usageInfo as $item) {
            $filePath = $item['path'];
            $content = file_get_contents($filePath);
            $updatedContent = $content;
            
            // Alle Bildmuster-Varianten durchlaufen
            foreach ($patterns as $pattern) {
                $updatedContent = preg_replace($pattern, $broken_img_placeholder, $updatedContent);
            }
            
            if ($updatedContent !== $content) {
                // Nur schreiben, wenn es tatsächlich Änderungen gab
                file_put_contents($filePath, $updatedContent);
            }
        }
        
        return true;
    }
    
    /**
     * Lädt eine Mediendatei hoch
     *
     * @param array $file FILE-Array aus $_FILES
     * @return array|bool Dateiinfo bei Erfolg, false bei Fehler
     */
    public function uploadMedia($file) {
        // Validierung
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        if (!in_array($file['type'], $allowedTypes)) {
            return false;
        }
        
        // Maximale Dateigröße (5 MB)
        $maxFileSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxFileSize) {
            return false;
        }
        
        $uploadDir = MARQUES_ROOT_DIR . '/assets/media';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Sicheren Dateinamen generieren
        $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '', basename($file['name']));
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $filename)) {
            throw new \RuntimeException("Invalid filename: {$filename}");
        }
        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $uniqueName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($filename, PATHINFO_FILENAME)) . '.' . $fileExt;
        
        // Vollständiger Pfad
        $filePath = $uploadDir . '/' . $uniqueName;
        
        // Datei verschieben
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return false;
        }
        
        // Datei-Informationen zurückgeben
        return [
            'filename' => $uniqueName,
            'url' => 'assets/media/' . $uniqueName,
            'filesize' => $this->formatFileSize(filesize($filePath)),
            'filetype' => mime_content_type($filePath)
        ];
    }
    
    /**
     * Formatiert die Dateigröße
     *
     * @param int $bytes Dateigröße in Bytes
     * @return string Formatierte Größe
     */
    private function formatFileSize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' Bytes';
        }
    }
}