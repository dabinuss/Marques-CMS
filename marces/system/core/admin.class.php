<?php
/**
 * marces CMS - Admin Klasse
 * 
 * Behandelt Admin-Funktionalitäten und Berechtigungen.
 *
 * @package marces
 * @subpackage core
 */

namespace Marces\Core;

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
        $this->_config = require MARCES_CONFIG_DIR . '/system.config.php';
    }
    
    /**
     * Prüft, ob Zugriff auf den Admin-Bereich erlaubt ist
     *
     * @return bool True wenn Zugriff erlaubt
     */
    public function checkAccess() {
        return $this->_user->isLoggedIn();
    }
    
    /**
     * Stellt sicher, dass der Benutzer eingeloggt ist
     * Leitet zur Login-Seite weiter, wenn nicht
     */
    public function requireLogin() {
        if (!$this->checkAccess()) {
            header('Location: login.php');
            exit;
        }
    }
    
    /**
     * Stellt sicher, dass der Benutzer Admin-Berechtigungen hat
     * Zeigt eine Fehlermeldung an, wenn nicht
     *
     * @return bool True wenn erfolgreich
     */
    public function requireAdmin() {
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
    public function getStatistics() {
        // Zähle Blog-Beiträge
        $blogManager = new \Marces\Core\BlogManager();
        $blogPosts = $blogManager->getAllPosts();
        
        $stats = [
            'pages' => $this->_countFiles(MARCES_CONTENT_DIR . '/pages'),
            'blog_posts' => count($blogPosts),
            'media_files' => $this->_countMediaFiles(),
            'categories' => count($blogManager->getCategories()),
            'disk_usage' => $this->_getDiskUsage(),
            'php_version' => PHP_VERSION,
            'marces_version' => MARCES_VERSION
        ];
        
        return $stats;
    }
    
    /**
     * Zählt Dateien in einem Verzeichnis
     *
     * @param string $dir Verzeichnispfad
     * @return int Anzahl der Dateien
     */
    private function _countFiles($dir) {
        if (!is_dir($dir)) {
            return 0;
        }
        
        $files = glob($dir . '/*.md');
        return count($files);
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
        $getSize(MARCES_CONTENT_DIR);
        
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