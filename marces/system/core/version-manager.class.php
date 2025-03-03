<?php
/**
 * marces CMS - Version Manager Klasse
 * 
 * Verwaltet Versionen von Inhaltsänderungen.
 *
 * @package marces
 * @subpackage core
 */

namespace Marces\Core;

class VersionManager {
    /**
     * @var string Verzeichnis für Versionen
     */
    private $_versionsDir;
    
    /**
     * @var int Maximale Anzahl von Versionen pro Inhalt
     */
    private $_maxVersions;
    
    /**
     * Konstruktor
     * 
     * @param int $maxVersions Maximale Anzahl von Versionen (Standard: 10)
     */
    public function __construct($maxVersions = 10) {
        $this->_versionsDir = MARCES_ROOT_DIR . '/content/versions';
        $this->_maxVersions = $maxVersions;
        
        // Verzeichnisstruktur sicherstellen
        $this->ensureVersionsDirectory();
    }
    
    /**
     * Stellt sicher, dass das Versionsverzeichnis existiert
     */
    public function ensureVersionsDirectory() {
        if (!is_dir($this->_versionsDir)) {
            mkdir($this->_versionsDir, 0755, true);
        }
        
        // Unterverzeichnisse für verschiedene Inhaltstypen
        $contentTypes = ['pages', 'blog', 'media'];
        foreach ($contentTypes as $type) {
            $dir = $this->_versionsDir . '/' . $type;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Erstellt eine Version einer Datei
     * 
     * @param string $contentType Inhaltstyp (pages, blog, media)
     * @param string $id ID/Slug des Inhalts
     * @param string $content Der aktuelle Inhalt, der versioniert werden soll
     * @param string $username Benutzername des Editors
     * @return bool|string False bei Fehler, sonst Versions-ID
     */
    public function createVersion($contentType, $id, $content, $username = 'system') {
        if (!in_array($contentType, ['pages', 'blog', 'media'])) {
            return false;
        }
        
        $versionDir = $this->_versionsDir . '/' . $contentType . '/' . $id;
        
        // Verzeichnis für diesen Inhalt erstellen, falls nicht vorhanden
        if (!is_dir($versionDir)) {
            if (!mkdir($versionDir, 0755, true)) {
                return false;
            }
        }
        
        // Timestamp für diese Version
        $timestamp = time();
        $versionId = date('YmdHis', $timestamp);
        
        // Metadaten zur Version
        $metadata = [
            'version_id' => $versionId,
            'timestamp' => $timestamp,
            'username' => $username,
            'date' => date('Y-m-d H:i:s', $timestamp)
        ];
        
        // Metadaten-Datei erstellen
        $metadataFile = $versionDir . '/' . $versionId . '.json';
        if (file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT)) === false) {
            return false;
        }
        
        // Inhalts-Datei erstellen
        $contentFile = $versionDir . '/' . $versionId . '.content';
        if (file_put_contents($contentFile, $content) === false) {
            // Bereinigen, wenn Inhalt nicht geschrieben werden konnte
            unlink($metadataFile);
            return false;
        }
        
        // Alte Versionen aufräumen, wenn zu viele vorhanden sind
        $this->pruneOldVersions($contentType, $id);
        
        return $versionId;
    }
    
    /**
     * Holt alle Versionen eines Inhalts
     * 
     * @param string $contentType Inhaltstyp
     * @param string $id ID/Slug des Inhalts
     * @return array Versions-Metadaten, sortiert nach Datum (neuste zuerst)
     */
    public function getVersions($contentType, $id) {
        $versionDir = $this->_versionsDir . '/' . $contentType . '/' . $id;
        $versions = [];
        
        if (!is_dir($versionDir)) {
            return $versions;
        }
        
        // JSON-Metadatendateien finden
        $files = glob($versionDir . '/*.json');
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data !== null) {
                $versions[] = $data;
            }
        }
        
        // Nach Timestamp absteigend sortieren (neueste zuerst)
        usort($versions, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return $versions;
    }
    
    /**
     * Holt den Inhalt einer spezifischen Version
     * 
     * @param string $contentType Inhaltstyp
     * @param string $id ID/Slug des Inhalts
     * @param string $versionId Versions-ID
     * @return string|false Inhalt der Version oder false bei Fehler
     */
    public function getVersionContent($contentType, $id, $versionId) {
        $contentFile = $this->_versionsDir . '/' . $contentType . '/' . $id . '/' . $versionId . '.content';
        
        if (!file_exists($contentFile)) {
            return false;
        }
        
        return file_get_contents($contentFile);
    }
    
    /**
     * Stellt eine Version wieder her
     * 
     * @param string $contentType Inhaltstyp
     * @param string $id ID/Slug des Inhalts
     * @param string $versionId Versions-ID
     * @param string $username Benutzername für die neue Version
     * @return bool Erfolg der Wiederherstellung
     */
    public function restoreVersion($contentType, $id, $versionId, $username = 'system') {
        // Inhalt der Version abrufen
        $content = $this->getVersionContent($contentType, $id, $versionId);
        
        if ($content === false) {
            return false;
        }
        
        // Zielpfad für die Wiederherstellung
        $targetPath = $this->getContentPath($contentType, $id);
        
        if ($targetPath === false) {
            return false;
        }
        
        // Aktuelle Version sichern, bevor wir überschreiben
        $currentContent = file_exists($targetPath) ? file_get_contents($targetPath) : '';
        $this->createVersion($contentType, $id, $currentContent, $username . ' (vor Wiederherstellung)');
        
        // Version wiederherstellen
        if (file_put_contents($targetPath, $content) === false) {
            return false;
        }
        
        // Neue Version der wiederhergestellten Datei erstellen
        $this->createVersion($contentType, $id, $content, $username . ' (Wiederherstellung von ' . $versionId . ')');
        
        return true;
    }
    
    /**
     * Löscht eine bestimmte Version
     * 
     * @param string $contentType Inhaltstyp
     * @param string $id ID/Slug des Inhalts
     * @param string $versionId Versions-ID
     * @return bool Erfolg der Löschung
     */
    public function deleteVersion($contentType, $id, $versionId) {
        $metadataFile = $this->_versionsDir . '/' . $contentType . '/' . $id . '/' . $versionId . '.json';
        $contentFile = $this->_versionsDir . '/' . $contentType . '/' . $id . '/' . $versionId . '.content';
        
        $success = true;
        
        if (file_exists($metadataFile)) {
            $success = $success && unlink($metadataFile);
        }
        
        if (file_exists($contentFile)) {
            $success = $success && unlink($contentFile);
        }
        
        return $success;
    }
    
    /**
     * Entfernt alte Versionen, wenn mehr als die maximale Anzahl vorhanden sind
     * 
     * @param string $contentType Inhaltstyp
     * @param string $id ID/Slug des Inhalts
     */
    private function pruneOldVersions($contentType, $id) {
        $versions = $this->getVersions($contentType, $id);
        
        if (count($versions) <= $this->_maxVersions) {
            return;
        }
        
        // Nur die überzähligen Versionen löschen
        $versionsToDelete = array_slice($versions, $this->_maxVersions);
        
        foreach ($versionsToDelete as $version) {
            $this->deleteVersion($contentType, $id, $version['version_id']);
        }
    }
    
    /**
     * Liefert den Pfad zur Inhaltsdatei
     * 
     * @param string $contentType Inhaltstyp
     * @param string $id ID/Slug des Inhalts
     * @return string|false Pfad zur Datei oder false bei ungültigem Typ
     */
    private function getContentPath($contentType, $id) {
        switch ($contentType) {
            case 'pages':
                return MARCES_CONTENT_DIR . '/pages/' . $id . '.md';
            case 'blog':
                // Hier müsstest du die korrekte Pfadlogik für Blog-Beiträge implementieren
                // z.B. Suche nach Dateien, die mit dem ID/Slug enden
                $files = glob(MARCES_CONTENT_DIR . '/blog/*-' . $id . '.md');
                return !empty($files) ? $files[0] : false;
            case 'media':
                // Für Medien müsstest du einen angemessenen Pfad zurückgeben
                return MARCES_ROOT_DIR . '/assets/media/' . $id;
            default:
                return false;
        }
    }
    
    /**
     * Erstellt eine Diff zwischen zwei Versionen
     * 
     * @param string $oldContent Älterer Inhalt
     * @param string $newContent Neuerer Inhalt
     * @return array Array mit Unterschieden
     */
    public function createDiff($oldContent, $newContent) {
        // Einfache Zeilen-basierte Diff-Implementierung
        $oldLines = explode("\n", $oldContent);
        $newLines = explode("\n", $newContent);
        
        $diff = [];
        $maxLines = max(count($oldLines), count($newLines));
        
        for ($i = 0; $i < $maxLines; $i++) {
            $oldLine = $i < count($oldLines) ? $oldLines[$i] : '';
            $newLine = $i < count($newLines) ? $newLines[$i] : '';
            
            if ($oldLine !== $newLine) {
                $diff[] = [
                    'line' => $i + 1,
                    'old' => $oldLine,
                    'new' => $newLine,
                    'type' => empty($oldLine) ? 'added' : (empty($newLine) ? 'removed' : 'changed')
                ];
            }
        }
        
        return $diff;
    }
}