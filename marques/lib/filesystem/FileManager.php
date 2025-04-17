<?php
declare(strict_types=1);

namespace Marques\Data;

use Marques\Core\Cache;
use Marques\Filesystem\PathResolver;

class FileManager {
    protected string $baseDir;
    protected Cache $cache;
    protected array $knownDirectories = [];

    /**
     * Konstruktor.
     * @param Cache $cache Cache-Instanz
     * @param string|array $baseDir Basisverzeichnis oder Array mit benannten Verzeichnissen
     */
    public function __construct(Cache $cache, $baseDir = MARQUES_CONTENT_DIR) {
        // Initialisiere knownDirectories mit Standardwerten
        $this->knownDirectories = [
            'content' => MARQUES_CONTENT_DIR,
            'themes' => MARQUES_THEMES_DIR,
            'admin' => MARQUES_ADMIN_DIR,
            'backend_templates' => MARQUES_ADMIN_DIR . '/templates'
        ];

        if (is_array($baseDir)) {
            // Füge benutzerdefinierte Verzeichnisse hinzu oder überschreibe bestehende
            $this->knownDirectories = array_merge($this->knownDirectories, $baseDir);
            $this->baseDir = $this->knownDirectories['content'] ?? MARQUES_CONTENT_DIR;
        } else {
            $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
        }
        
        $this->cache = $cache;
    }

    /**
     * Ändert das Basisverzeichnis.
     * 
     * @param string $newBaseDir Neues Basisverzeichnis
     * @return self Für Method Chaining
     */
    public function setBaseDir(string $newBaseDir): self {
        $this->baseDir = rtrim($newBaseDir, DIRECTORY_SEPARATOR);
        return $this;
    }

    /**
     * Wechselt zu einem benannten Verzeichnis aus knownDirectories.
     * Falls das Verzeichnis nicht existiert und ein Pfad angegeben ist, wird es hinzugefügt.
     *
     * @param string $directoryKey Schlüssel des Verzeichnisses
     * @param string|null $pathIfNotExists Pfad zum Hinzufügen, falls der Schlüssel nicht existiert
     * @return self Für Method Chaining
     * @throws \InvalidArgumentException Wenn der Schlüssel nicht existiert und kein Pfad angegeben ist
     */
    public function useDirectory(string $directoryKey, ?string $pathIfNotExists = null): self {
        if (!isset($this->knownDirectories[$directoryKey])) {
            if ($pathIfNotExists !== null) {
                $this->addDirectory($directoryKey, $pathIfNotExists);
            } else {
                throw new \InvalidArgumentException("Unbekanntes Verzeichnis: {$directoryKey}");
            }
        }
        $this->baseDir = rtrim($this->knownDirectories[$directoryKey], DIRECTORY_SEPARATOR);
        return $this;
    }

    /**
     * Fügt ein benanntes Verzeichnis zur Liste der bekannten Verzeichnisse hinzu.
     *
     * @param string $key Schlüssel für das Verzeichnis
     * @param string $path Pfad zum Verzeichnis
     * @return self Für Method Chaining
     */
    public function addDirectory(string $key, string $path): self {
        $this->knownDirectories[$key] = rtrim($path, DIRECTORY_SEPARATOR);
        return $this;
    }

    /**
     * Gibt das aktuelle Basisverzeichnis zurück.
     *
     * @return string Das aktuelle Basisverzeichnis
     */
    public function getBaseDir(): string {
        return $this->baseDir;
    }

    /**
     * Gibt alle bekannten Verzeichnisse zurück.
     *
     * @return array Array mit allen bekannten Verzeichnissen
     */
    public function getKnownDirectories(): array {
        return $this->knownDirectories;
    }

    /**
     * Prüft, ob eine Datei in einem bestimmten Verzeichnis existiert.
     *
     * @param string $directoryKey Schlüssel des Verzeichnisses
     * @param string $relativePath Relativer Pfad zur Datei
     * @return bool True, wenn die Datei existiert
     */
    public function existsInDirectory(string $directoryKey, string $relativePath): bool {
        $currentDir = $this->baseDir;
        try {
            $this->useDirectory($directoryKey);
            $exists = $this->exists($relativePath);
            $this->baseDir = $currentDir; // Zurücksetzen
            return $exists;
        } catch (\InvalidArgumentException $e) {
            $this->baseDir = $currentDir; // Zurücksetzen
            return false;
        }
    }

    /**
     * Liest eine Datei aus einem bestimmten Verzeichnis.
     *
     * @param string $directoryKey Schlüssel des Verzeichnisses
     * @param string $relativePath Relativer Pfad zur Datei
     * @return string|null Dateiinhalte oder null, falls nicht lesbar
     */
    public function readFromDirectory(string $directoryKey, string $relativePath): ?string {
        $currentDir = $this->baseDir;
        try {
            $this->useDirectory($directoryKey);
            $content = $this->readFile($relativePath);
            $this->baseDir = $currentDir; // Zurücksetzen
            return $content;
        } catch (\InvalidArgumentException $e) {
            $this->baseDir = $currentDir; // Zurücksetzen
            return null;
        }
    }

    /**
     * Schreibt eine Datei an den relativen Pfad.
     *
     * @param string $relativePath Relativer Pfad (z.B. "blog/2025/03/000-25C.md")
     * @param string $content Inhalt der Datei
     * @return bool True bei Erfolg, sonst false.
     * @throws \RuntimeException Falls das Zielverzeichnis nicht erstellt werden kann.
     */
    public function writeFile(string $relativePath, string $content): bool {
        $filePath = $this->getFullPath($relativePath);
        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Konnte Verzeichnis nicht erstellen: $dir");
        }
        if (file_put_contents($filePath, $content) === false) {
            return false;
        }
        // Cache invalidieren über die injizierte Instanz
        $this->cache->delete($relativePath);
        return true;
    }

    /**
     * Liest den Inhalt einer Datei am relativen Pfad.
     *
     * @param string $relativePath Relativer Pfad zur Datei.
     * @return string|null Dateiinhalte oder null, falls nicht lesbar.
     */
    public function readFile(string $relativePath): ?string {
        $filePath = $this->getFullPath($relativePath);
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }
        return file_get_contents($filePath);
    }

    /**
     * Löscht eine Datei.
     *
     * @param string $relativePath Relativer Pfad zur Datei.
     * @return bool True bei Erfolg, sonst false.
     */
    public function deleteFile(string $relativePath): bool {
        $filePath = $this->getFullPath($relativePath);
        if (file_exists($filePath)) {
            $this->cache->delete(md5($filePath));
            return unlink($filePath);
        }
        return false;
    }

    /**
     * Listet alle Dateien im angegebenen relativen Verzeichnis auf.
     *
     * @param string $relativeDir Relatives Verzeichnis (z.B. "blog/2025/03")
     * @param string $extension Optional: Dateierweiterung (Standard: "md")
     * @return array Liste der Dateinamen (z.B. [ "000-25C.md", "001-2AB.md", ... ])
     */
    public function listFiles(string $relativeDir, string $extension = "md"): array {
        $fullPath = $this->getFullPath($relativeDir);
        if (!is_dir($fullPath)) {
            return [];
        }
        $files = glob($fullPath . DIRECTORY_SEPARATOR . '*.' . ltrim($extension, '.'));
        return $files ? array_map('basename', $files) : [];
    }

    /**
     * Listet alle Dateien in einem bestimmten Verzeichnis auf.
     *
     * @param string $directoryKey Schlüssel des Verzeichnisses
     * @param string $relativeDir Relatives Verzeichnis
     * @param string $extension Dateierweiterung
     * @return array Liste der Dateinamen
     */
    public function listFilesInDirectory(string $directoryKey, string $relativeDir, string $extension = "md"): array {
        $currentDir = $this->baseDir;
        try {
            $this->useDirectory($directoryKey);
            $files = $this->listFiles($relativeDir, $extension);
            $this->baseDir = $currentDir; // Zurücksetzen
            return $files;
        } catch (\InvalidArgumentException $e) {
            $this->baseDir = $currentDir; // Zurücksetzen
            return [];
        }
    }

    /**
     * Ermittelt den absoluten Pfad aus einem relativen Pfad.
     *
     * @param string $path Relativer oder absoluter Pfad
     * @return string Absoluter Pfad
     */
    public function getFullPath(string $path): string
    {
        if (strpos($path, $this->baseDir) === 0 && realpath($path) !== false) {
            return PathResolver::resolve($this->baseDir, substr($path, strlen($this->baseDir)));
        }
        return PathResolver::resolve($this->baseDir, $path);
    }

    /**
     * Sucht Dateien anhand eines Glob-Patterns.
     *
     * @param string $pattern Glob-Pattern (z.B. "blog/+/+/+.md")
     * @return array Liste der gefundenen Dateipfade
     */
    public function glob(string $pattern): array {
        $fullPattern = $this->getFullPath($pattern);
        $files = glob($fullPattern);
        return $files ?: [];
    }

    /**
     * Prüft, ob eine Datei am relativen Pfad existiert.
     *
     * @param string $relativePath Relativer Pfad zur Datei.
     * @return bool True, wenn die Datei existiert.
     */
    public function exists(string $relativePath): bool {
        return file_exists($this->getFullPath($relativePath));
    }

    /**
     * Erstellt ein Verzeichnis, falls es nicht existiert.
     *
     * @param string $dir Absoluter oder relativer Pfad zum Verzeichnis.
     * @return bool True bei Erfolg, sonst false.
     */
    public function createDirectory(string $dir): bool {
        $fullPath = $this->getFullPath($dir);
        if (!is_dir($fullPath)) {
            return mkdir($fullPath, 0755, true);
        }
        return true;
    }
}