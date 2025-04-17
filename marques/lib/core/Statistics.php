<?php
declare(strict_types=1);

namespace Marques\Core;

use Marques\Filesystem\PathRegistry;
use Marques\Filesystem\PathResolver;

class Statistics {

    protected array $stats = [];
    private ?PathRegistry $paths = null;

    /**
     * Konstruktor.
     * Initialisiert die Statistik-Daten.
     */
    public function __construct(?PathRegistry $paths = null)
    {
        $this->paths = $paths;
        $this->stats = $this->collectBaseStatistics();
    }

    /**
     * Sammelt Basisstatistiken.
     *
     * @return array
     */
    protected function collectBaseStatistics(): array
    {
        $content = $this->paths
            ? $this->paths->getPath('content')
            : MARQUES_CONTENT_DIR;

        return [
            'pages'       => $this->countFiles($content . '/pages'),
            'blog_posts'  => $this->countBlogPosts(),
            'media_files' => $this->countMediaFiles(),
            'disk_usage'  => $this->getDiskUsage()
        ];
    }

    /**
     * Zählt Dateien in einem Verzeichnis.
     *
     * @param string $dir Verzeichnis.
     * @return int Anzahl der Dateien.
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
        // Beispielhafte Implementierung – hier sollte echte Logik stehen.
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
     * @return string Formatierte Größe.
     */
    protected function getDiskUsage(): string
    {
        // Basisverzeichnis über PathRegistry (Fallback auf Konstante)
        $baseDir = $this->paths
            ? $this->paths->getPath('content')
            : (defined('MARQUES_CONTENT_DIR') ? MARQUES_CONTENT_DIR : __DIR__);
    
        $totalSize = 0;
    
        // Traversal‑sicher normalisieren
        $root = \Marques\Filesystem\PathResolver::resolve($baseDir, '');
    
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
    
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalSize += $file->getSize();
            }
        }
    
        // Menschliche Formatierung
        if ($totalSize < 1024) {
            return $totalSize . ' Bytes';
        }
        if ($totalSize < 1_048_576) { // 1024 * 1024
            return round($totalSize / 1024, 2) . ' KB';
        }
        if ($totalSize < 1_073_741_824) { // 1024 * 1024 * 1024
            return round($totalSize / 1_048_576, 2) . ' MB';
        }
        return round($totalSize / 1_073_741_824, 2) . ' GB';
    }

    /**
     * Gibt die gesammelten Statistik-Daten zurück.
     *
     * @return array
     */
    public function getStatistics(): array {
        return $this->stats;
    }

    /**
     * Liefert zusätzliche Statistiken, die für das Frontend nützlich sein könnten.
     *
     * @return array Frontend-spezifische Statistiken.
     */
    public function getFrontendStatistics(): array {
        $frontendStats = $this->stats; // Basisstatistiken

        return $frontendStats;
    }
}
