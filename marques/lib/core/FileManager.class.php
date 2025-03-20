<?php
declare(strict_types=1);

namespace Marques\Core;

class FileManager {
    protected string $baseDir;

    /**
     * Konstruktor.
     * @param string $baseDir Basisverzeichnis (z.B. MARQUES_CONTENT_DIR)
     */
    public function __construct(string $baseDir = MARQUES_CONTENT_DIR) {
        $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
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
        // Cache invalidieren: Der AppCache wird informiert, dass sich der Inhalt geändert hat.
        $cacheManager = AppCache::getInstance();
        $cacheManager->delete($filePath);
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
        return @file_get_contents($filePath); // Korrektur: Fehlerunterdrückung bei file_get_contents beibehalten, um Lesefehler zu vermeiden
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
            $cacheManager = AppCache::getInstance();
            $cacheManager->delete($filePath);
            return unlink($filePath);
        }
        return false;
    }

    /**
     * Listet alle Markdown-Dateien im angegebenen relativen Verzeichnis auf.
     *
     * @param string $relativeDir Relatives Verzeichnis (z.B. "blog/2025/03")
     * @return array Liste der Dateinamen (z.B. [ "000-25C.md", "001-2AB.md", ... ])
     */
    public function listFiles(string $relativeDir): array {
        $fullPath = $this->getFullPath($relativeDir);
        if (!is_dir($fullPath)) {
            return [];
        }
        $files = glob($fullPath . DIRECTORY_SEPARATOR . '*.md');
        return $files ? array_map('basename', $files) : [];
    }

    /**
     * Ermittelt den absoluten Pfad aus einem relativen Pfad.
     *
     * @param string $relativePath Relativer Pfad.
     * @return string Absoluter Pfad.
     */
    protected function getFullPath(string $relativePath): string {
        return $this->baseDir . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
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
            return @mkdir($fullPath, 0755, true); // Korrektur: Fehlerunterdrückung bei mkdir beibehalten, da createDirectory oft im Hintergrund läuft
        }
        return true;
    }
}