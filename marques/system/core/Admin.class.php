<?php
declare(strict_types=1);

/**
 * marques CMS - Admin Klasse
 * 
 * Behandelt Admin-Funktionalitäten und Berechtigungen.
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Core;

class Admin {
    /**
     * @var User Benutzer-Objekt
     */
    private $_user;
    
    /**
     * @var array Systemkonfiguration
     */
    private $_config;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $this->_user = new User();
        $configManager = \Marques\Core\AppConfig::getInstance();
        $this->_config = $configManager->load('system') ?: [];
    }
    
    /**
     * Prüft, ob Zugriff auf den Admin-Bereich erlaubt ist
     *
     * @return bool True wenn Zugriff erlaubt
     */
    public function checkAccess(): bool {
        return $this->_user->isLoggedIn();
    }
    
    /**
     * Stellt sicher, dass der Benutzer eingeloggt ist
     * Leitet zur Login-Seite weiter, wenn nicht
     */
    public function requireLogin(): void {
        // Prüfe, ob die aktuelle Seite bereits die Login-Seite ist
        $currentPage = $_GET['page'] ?? 'dashboard';
        
        if (!$this->checkAccess()) {
            if ($currentPage !== 'login') {
                header('Location: index.php?page=login');
                exit;
            }
        } elseif ($currentPage === 'login') {
            // Wenn bereits eingeloggt und auf der Login-Seite, zum Dashboard weiterleiten
            header('Location: index.php?page=dashboard');
            exit;
        }
    }
    
    /**
     * Stellt sicher, dass der Benutzer Admin-Berechtigungen hat
     * Zeigt eine Fehlermeldung an, wenn nicht
     *
     * @return bool True wenn erfolgreich
     */
    public function requireAdmin(): bool {
        if (!$this->_user->isAdmin()) {
            echo "Zugriff verweigert: Administrator-Berechtigungen erforderlich";
            return false;
        }
        return true;
    }
    
    /**
     * Gibt Systemstatistiken zurück
     *
     * @return array Statistiken
     */
    public function getStatistics(): array {
        // Zähle Blog-Beiträge
        $blogManager = new BlogManager();
        $blogPosts = $blogManager->getAllPosts();
        
        $stats = [
            'pages' => $this->_countFiles(MARQUES_CONTENT_DIR . '/pages'),
            'blog_posts' => count($blogPosts),
            'media_files' => $this->_countMediaFiles(),
            'categories' => count($blogManager->getCategories()),
            'disk_usage' => $this->_getDiskUsage(),
            'php_version' => PHP_VERSION,
            'marques_version' => MARQUES_VERSION
        ];
        
        return $stats;
    }
    
    /**
     * Zählt Dateien in einem Verzeichnis
     *
     * @param string $dir Verzeichnispfad
     * @return int Anzahl der Dateien
     */
    private function _countFiles($dir): int {
        if (!is_dir($dir)) {
            return 0;
        }
        
        $files = glob($dir . '/*.md');
        return is_array($files) ? count($files) : 0;
    }

    /**
     * Zählt die Anzahl der Mediendateien
     *
     * @return int Anzahl der Mediendateien
     */
    private function _countMediaFiles() {
        $mediaDir = MARQUES_ROOT_DIR . '/assets/media';
        
        if (!is_dir($mediaDir)) {
            return 0;
        }
        
        $mediaFiles = glob($mediaDir . '/*');
        
        // Verzeichnisse ausschließen, nur Dateien zählen
        $fileCount = 0;
        foreach ($mediaFiles as $file) {
            if (is_file($file)) {
                $fileCount++;
            }
        }
        
        return $fileCount;
    }
    
    /**
     * Gibt die Festplattenbelegung des CMS zurück
     *
     * @return string Formatierte Größe
     */
    private function _getDiskUsage() {
        $totalSize = 0;
        
        // Funktion zum Berechnen der Verzeichnisgröße
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
        
        // Hauptverzeichnisse durchlaufen
        $getSize(MARQUES_CONTENT_DIR);
        
        // Größe formatieren
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
}