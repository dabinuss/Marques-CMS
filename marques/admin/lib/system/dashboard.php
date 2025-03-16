<?php
declare(strict_types=1);

// dashboard.php -- Content-Datei für das Dashboard im Admin-Bereich

use Marques\Core\AppConfig;
use Marques\Core\Admin;
use Marques\Core\BlogManager;
use Marques\Core\PageManager;
use Marques\Core\MediaManager;
use Marques\Core\AppCache;
use Marques\Core\Helper;
use Marques\Core\ThemeManager;

// -------------------------
// Datenaufbereitung
// -------------------------

// Error-Reporting für Debug-Zwecke
if (isset($system_config['debug']) && $system_config['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Systemkonfiguration laden
$configManager = AppConfig::getInstance();
$systemConfig = $configManager->load('system') ?: [];

// Statistiken abrufen
$admin = new Admin();
$stats = $admin->getStatistics();

// Alle Benutzer für die Benutzer-Statistik abrufen
$userManager = isset($system_config['user_manager_class']) ? 
    new $system_config['user_manager_class']() : 
    new \Marques\Core\User();
$allUsers = method_exists($userManager, 'getAllUsers') ? $userManager->getAllUsers() : [];

// Blog-Manager initialisieren
try {
    $blogManager = new BlogManager();
    $categories = $blogManager->getCategories();
    $tags = $blogManager->getTags();
    $recentPosts = $blogManager->getAllPosts(5, 0);
} catch (\Exception $e) {
    error_log("Fehler beim Laden der Blog-Daten: " . $e->getMessage());
    $categories = [];
    $tags = [];
    $recentPosts = [];
}

// Page-Manager initialisieren
try {
    $pageManager = new PageManager();
    $pages = $pageManager->getAllPages();
} catch (\Exception $e) {
    error_log("Fehler beim Laden der Seiten: " . $e->getMessage());
    $pages = [];
}

// Media-Manager initialisieren
try {
    $mediaManager = new MediaManager();
    $mediaFiles = $mediaManager->getAllMedia();
} catch (\Exception $e) {
    error_log("Fehler beim Laden der Mediendateien: " . $e->getMessage());
    $mediaFiles = [];
}

// Theme-Manager zum Laden des Template-Pfads für Assets
try {
    $themeManager = new ThemeManager();
    $templatePath = $themeManager->getThemePath('assets');
} catch (\Exception $e) {
    error_log("Fehler beim Laden des Template-Pfads: " . $e->getMessage());
    $templatePath = 'assets';
}

// Cache-Daten ermitteln
try {
    // Instanziere den Cache (hier als AppCache implementiert)
    $cacheManager = new AppCache();
    $numCached = $cacheManager->getCacheFileCount();
    $cacheSize = $cacheManager->getCacheSize();
    $cacheSizeFormatted = Helper::formatBytes($cacheSize);
    
    // Neue Statistikfunktionen: Gesamte Anfragen, Trefferquote, durchschnittliche Zugriffszeit etc.
    $cacheStats = $cacheManager->getStatistics();
} catch (\Exception $e) {
    error_log("Fehler beim Laden der Cache-Daten: " . $e->getMessage());
    $numCached = 0;
    $cacheSize = 0;
    $cacheSizeFormatted = '0 B';
    $cacheStats = [
        'total_requests' => 0,
        'cache_hits'     => 0,
        'hit_rate'       => 0,
        'avg_access_time'=> 0,
    ];
}

// Prüfen, ob das Log-Verzeichnis für Zugriffsstatistiken existiert; falls nicht, anlegen
$statsDir = MARQUES_ROOT_DIR . '/logs/stats';
if (!is_dir($statsDir)) {
    if (!@mkdir($statsDir, 0755, true)) {
        error_log("Konnte Verzeichnis für Statistiken nicht erstellen: $statsDir");
    }
}

/**
 * Lädt und berechnet die Website-Statistiken
 * 
 * @return array Statistik-Daten
 */
function loadSiteStatistics(): array {
    // Standardwerte für Statistiken
    $stats = [
        'total_visits'     => 0,
        'visits_today'     => 0,
        'visits_yesterday' => 0,
        'visits_this_week' => 0,
        'visits_this_month'=> 0,
        'top_pages'        => [],
        'top_referrers'    => [],
        'browser_stats'    => [],
        'device_stats'     => [
            'Desktop' => 0,
            'Mobile' => 0,
            'Tablet' => 0
        ],
        'hourly_stats'     => array_fill(0, 24, 0),
        'daily_stats'      => []
    ];
    
    $statsDir = MARQUES_ROOT_DIR . '/logs/stats';
    if (!is_dir($statsDir)) {
        return $stats;
    }
    
    $today        = date('Y-m-d');
    $yesterday    = date('Y-m-d', strtotime('-1 day'));
    $startOfWeek  = date('Y-m-d', strtotime('this week'));
    $startOfMonth = date('Y-m-d', strtotime('first day of this month'));
    
    // Funktionen für Gerät- und Browser-Erkennung
    $detectDevice = function($userAgent) {
        $userAgent = strtolower($userAgent);
        if (strpos($userAgent, 'mobile') !== false || strpos($userAgent, 'android') !== false) {
            return (strpos($userAgent, 'tablet') !== false) ? 'Tablet' : 'Mobile';
        }
        return 'Desktop';
    };
    
    $detectBrowser = function($userAgent) {
        $userAgent = strtolower($userAgent);
        if (strpos($userAgent, 'firefox') !== false) return 'Firefox';
        if (strpos($userAgent, 'chrome') !== false) return 'Chrome';
        if (strpos($userAgent, 'safari') !== false) return 'Safari';
        if (strpos($userAgent, 'edge') !== false) return 'Edge';
        if (strpos($userAgent, 'opera') !== false || strpos($userAgent, 'opr') !== false) return 'Opera';
        if (strpos($userAgent, 'msie') !== false || strpos($userAgent, 'trident') !== false) return 'Internet Explorer';
        return 'Andere';
    };
    
    // Statistiken der letzten 30 Tage verarbeiten
    for ($i = 0; $i < 30; $i++) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $logFile = $statsDir . '/' . $date . '.log';
        
        if (file_exists($logFile)) {
            $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                error_log("Konnte Statistik-Datei nicht lesen: $logFile");
                continue;
            }
            
            $dailyCount = count($lines);
            $stats['total_visits'] += $dailyCount;
            $stats['daily_stats'][$date] = $dailyCount;
            
            if ($date === $today) {
                $stats['visits_today'] = $dailyCount;
            } elseif ($date === $yesterday) {
                $stats['visits_yesterday'] = $dailyCount;
            }
            
            if ($date >= $startOfWeek) {
                $stats['visits_this_week'] += $dailyCount;
            }
            
            if ($date >= $startOfMonth) {
                $stats['visits_this_month'] += $dailyCount;
            }
            
            // Detaillierte Statistiken aus den Logzeilen extrahieren
            foreach ($lines as $line) {
                $data = @json_decode($line, true);
                if (!$data) continue;
                
                // Stundenbezogene Statistik
                if (isset($data['time'])) {
                    $hour = (int) date('G', strtotime($data['time']));
                    $stats['hourly_stats'][$hour]++;
                }
                
                // Seiten-Statistik
                if (isset($data['url'])) {
                    $url = parse_url($data['url'], PHP_URL_PATH) ?: '/';
                    if (!isset($stats['top_pages'][$url])) {
                        $stats['top_pages'][$url] = 0;
                    }
                    $stats['top_pages'][$url]++;
                }
                
                // Referrer-Statistik
                if (isset($data['referrer']) && !empty($data['referrer'])) {
                    $referrer = parse_url($data['referrer'], PHP_URL_HOST);
                    if ($referrer) {
                        if (!isset($stats['top_referrers'][$referrer])) {
                            $stats['top_referrers'][$referrer] = 0;
                        }
                        $stats['top_referrers'][$referrer]++;
                    }
                }
                
                // Browser- und Geräte-Statistik
                if (isset($data['user_agent'])) {
                    $browser = $detectBrowser($data['user_agent']);
                    if (!isset($stats['browser_stats'][$browser])) {
                        $stats['browser_stats'][$browser] = 0;
                    }
                    $stats['browser_stats'][$browser]++;
                    
                    $device = $detectDevice($data['user_agent']);
                    if (!isset($stats['device_stats'][$device])) {
                        $stats['device_stats'][$device] = 0;
                    }
                    $stats['device_stats'][$device]++;
                }
            }
        } else {
            // Stelle sicher, dass wir einen Eintrag für jeden Tag haben (für Charts)
            $stats['daily_stats'][$date] = 0;
        }
    }
    
    // Sortieren und aufbereiten der Ergebnisse
    arsort($stats['top_pages']);
    $stats['top_pages'] = array_slice($stats['top_pages'], 0, 10, true);
    
    arsort($stats['top_referrers']);
    $stats['top_referrers'] = array_slice($stats['top_referrers'], 0, 10, true);
    
    arsort($stats['browser_stats']);
    arsort($stats['device_stats']);
    
    // Daily stats chronologisch sortieren für Charts
    ksort($stats['daily_stats']);
    
    return $stats;
}

// Zugriffsstatistiken laden mit Fehlerbehandlung
try {
    $siteStats = loadSiteStatistics();
} catch (\Exception $e) {
    error_log("Fehler beim Laden der Zugriffsstatistiken: " . $e->getMessage());
    // Standardwerte für Statistiken
    $siteStats = [
        'total_visits'     => 0,
        'visits_today'     => 0,
        'visits_yesterday' => 0,
        'visits_this_week' => 0,
        'visits_this_month'=> 0,
        'top_pages'        => [],
        'top_referrers'    => [],
        'browser_stats'    => [],
        'device_stats'     => ['Desktop' => 0, 'Mobile' => 0, 'Tablet' => 0],
        'hourly_stats'     => array_fill(0, 24, 0),
        'daily_stats'      => []
    ];
}

// CSRF-Token generieren, falls nicht vorhanden
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Aktuelle Seite bestimmen
$page = isset($_GET['page']) ? htmlspecialchars($_GET['page']) : 'dashboard';
$allowed_pages = ['dashboard', 'pages', 'blog', 'media', 'users', 'settings', 'statistics'];
if (!in_array($page, $allowed_pages, true)) {
    $page = 'dashboard';
}

$page_titles = [
    'dashboard'  => 'Dashboard',
    'pages'      => 'Seiten verwalten',
    'blog'       => 'Blog verwalten',
    'media'      => 'Medienbibliothek',
    'users'      => 'Benutzer verwalten',
    'settings'   => 'Einstellungen',
    'statistics' => 'Zugriffsstatistiken'
];
$page_title = $page_titles[$page] ?? 'Dashboard';

// Letzte Aktivitäten erfassen mit besserer Fehlerbehandlung
$recentActivity = [];

if (!empty($pages) && is_array($pages)) {
    foreach ($pages as $pageInfo) {
        if (isset($pageInfo['date_modified']) && !empty($pageInfo['date_modified'])) {
            $recentActivity[] = [
                'type'  => 'page',
                'title' => $pageInfo['title'] ?? 'Unbenannte Seite',
                'date'  => $pageInfo['date_modified'],
                'url'   => Helper::appQueryParam('page=page-edit&id=' . ($pageInfo['id'] ?? 0)),
                'icon'  => 'file-alt'
            ];
        }
    }
}

if (!empty($recentPosts) && is_array($recentPosts)) {
    foreach ($recentPosts as $post) {
        if (!isset($post['title'])) continue;
        
        $recentActivity[] = [
            'type'  => 'post',
            'title' => $post['title'],
            'date'  => $post['date_modified'] ?? $post['date_created'] ?? $post['date'] ?? date('Y-m-d H:i:s'),
            'url'   => Helper::appQueryParam('page=blog-edit&id=' . ($post['id'] ?? 0)),
            'icon'  => 'blog'
        ];
    }
}

// Aktivitäten nach Datum sortieren
usort($recentActivity, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});
$recentActivity = array_slice($recentActivity, 0, 10);

// Daten für Charts vorbereiten
$days = 14; // 14 Tage anzeigen für das Besucherdiagramm
$dailyData = [];
$labels = [];

// Besucherstatistik für die letzten 14 Tage vorbereiten
if (!empty($siteStats['daily_stats'])) {
    $dailyStats = $siteStats['daily_stats'];
    // Sicherstellen, dass wir in chronologischer Reihenfolge ausgeben
    ksort($dailyStats);
    
    // Nur die letzten 14 Tage nehmen
    $dailyStats = array_slice($dailyStats, -$days, $days, true);
    
    foreach ($dailyStats as $date => $count) {
        $labels[] = date('d.m', strtotime($date));
        $dailyData[] = $count;
    }
}