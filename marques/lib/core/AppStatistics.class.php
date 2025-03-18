<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Class AppStatistics
 *
 * Sammelt und liefert Basisstatistiken, die im Frontend benötigt werden.
 *
 * @package Marques\Core
 */
class AppStatistics {
    /**
     * Enthält die gesammelten Statistik-Daten.
     *
     * @var array
     */
    protected array $stats = [];

    /**
     * Konstruktor.
     * Initialisiert die Statistik-Daten.
     */
    public function __construct() {
        $this->stats = $this->collectBaseStatistics();
    }

    /**
     * Sammelt Basisstatistiken.
     *
     * @return array
     */
    protected function collectBaseStatistics(): array {
        $stats = [];

        // Beispielhafte Statistiken:
        $stats['pages'] = $this->countFiles(MARQUES_CONTENT_DIR . '/pages');
        $stats['blog_posts'] = $this->countBlogPosts();
        $stats['media_files'] = $this->countMediaFiles();
        $stats['disk_usage'] = $this->getDiskUsage();

        return $stats;
    }

    /**
     * Zählt Dateien in einem Verzeichnis.
     *
     * @param string $dir Verzeichnis
     * @return int Anzahl der Dateien
     */
    protected function countFiles(string $dir): int {
        if (!is_dir($dir)) {
            return 0;
        }
        $files = glob($dir . '/*.md');
        return is_array($files) ? count($files) : 0;
    }

    /**
     * Simuliert das Zählen von Blogbeiträgen.
     *
     * @return int
     */
    protected function countBlogPosts(): int {
        // Hier könnte eine Instanz eines BlogManagers zum Einsatz kommen.
        // Für Demonstrationszwecke wird ein Platzhalterwert zurückgegeben.
        return 42;
    }

    /**
     * Zählt die Anzahl der Mediendateien.
     *
     * @return int
     */
    protected function countMediaFiles(): int {
        $mediaDir = MARQUES_ROOT_DIR . '/assets/media';
        if (!is_dir($mediaDir)) {
            return 0;
        }
        $mediaFiles = glob($mediaDir . '/*');
        $fileCount = 0;
        foreach ($mediaFiles as $file) {
            if (is_file($file)) {
                $fileCount++;
            }
        }
        return $fileCount;
    }

    /**
     * Berechnet die Festplattenbelegung.
     *
     * @return string Formatierte Größe
     */
    protected function getDiskUsage(): string {
        $totalSize = 0;
        $getSize = function($dir) use (&$getSize, &$totalSize) {
            if (!is_dir($dir)) {
                return;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $totalSize += $file->getSize();
            }
        };

        $getSize(MARQUES_CONTENT_DIR);

        if ($totalSize < 1024) {
            return $totalSize . " Bytes";
        } elseif ($totalSize < 1024 * 1024) {
            return round($totalSize / 1024, 2) . " KB";
        } elseif ($totalSize < 1024 * 1024 * 1024) {
            return round($totalSize / (1024 * 1024), 2) . " MB";
        } else {
            return round($totalSize / (1024 * 1024 * 1024), 2) . " GB";
        }
    }

    /**
     * Gibt die gesammelten Statistik-Daten zurück.
     *
     * @return array
     */
    public function getStatistics(): array {
        return $this->stats;
    }
}
