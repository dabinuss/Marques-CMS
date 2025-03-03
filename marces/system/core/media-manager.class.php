<?php
/**
 * marces CMS - Media Manager Klasse
 * 
 * Behandelt Medienverwaltung.
 *
 * @package marces
 * @subpackage core
 */

namespace Marces\Core;

class MediaManager {
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
     * Gibt alle Medien zurück mit Cache-Busting
     * 
     * @return array Liste aller Medien
     */
    public function getAllMedia() {
        $mediaDir = MARCES_ROOT_DIR . '/assets/media';
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
                    $filesize = $this->formatFilesize(filesize($filePath));
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
    public function deleteMedia($filename) {
        $filePath = MARCES_ROOT_DIR . '/assets/media/' . $filename;
        
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
        $mediaUrl = 'assets/media/' . $filename;
        $usageInfo = [];
        
        // Durchsuche die content-Verzeichnisse nach Verweisen auf das Medium
        $contentDirs = [
            MARCES_ROOT_DIR . '/content/pages',
            MARCES_ROOT_DIR . '/content/blog'
        ];
        
        foreach ($contentDirs as $dir) {
            if (is_dir($dir)) {
                $this->scanDirForMediaUsage($dir, $mediaUrl, $usageInfo);
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
                        $usageInfo[] = [
                            'path' => $path,
                            'title' => $file
                        ];
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
        $mediaUrl = 'assets/media/' . $filename;
        $mediaPattern = '/<img[^>]*src=["\']([^"\']*' . preg_quote($mediaUrl, '/') . ')[^"\']*["\'][^>]*>/i';
        $broken_img_placeholder = '<span class="broken-image">[Bild nicht verfügbar]</span>';
        
        // Iteriere über alle gefundenen Dateien und ersetze die Medienreferenzen
        foreach ($usageInfo as $item) {
            $filePath = $item['path'];
            $content = file_get_contents($filePath);
            
            // Alle Bilder mit dem Quellpfad ersetzen
            $updatedContent = preg_replace($mediaPattern, $broken_img_placeholder, $content);
            
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
        
        $uploadDir = MARCES_ROOT_DIR . '/assets/media';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Sicheren Dateinamen generieren
        $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '', basename($file['name']));
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