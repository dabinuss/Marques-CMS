<?php
declare(strict_types=1);

// dashboard.php – Content-Datei für das Dashboard im Admin-Bereich

use Marques\Core\AppConfig;
use Marques\Core\Admin;
use Marques\Core\BlogManager;
use Marques\Core\PageManager;
use Marques\Core\MediaManager;
use Marques\Core\CacheManager;
use Marques\Core\Helper;
use Marques\Core\ThemeManager;

// -------------------------
// Datenaufbereitung
// -------------------------

// Systemkonfiguration laden
$configManager = AppConfig::getInstance();
$systemConfig = $configManager->load('system') ?: [];

// Statistiken abrufen
$admin = new Admin();
$stats = $admin->getStatistics();

// Blog-Manager initialisieren
$blogManager = new BlogManager();
$categories  = $blogManager->getCategories();
$tags        = $blogManager->getTags();
$recentPosts = $blogManager->getAllPosts(5, 0);

// Page-Manager initialisieren
$pageManager = new PageManager();
$pages = $pageManager->getAllPages();

// Media-Manager initialisieren
$mediaManager = new MediaManager();
$mediaFiles = $mediaManager->getAllMedia();

// Theme-Manager zum Laden des Template-Pfads für Assets
$themeManager = new ThemeManager();
$templatePath = $themeManager->getThemePath('assets');

// Cache-Daten ermitteln
$cacheManager = new CacheManager();
$numCached = $cacheManager->getCacheFileCount();
$cacheSize = $cacheManager->getCacheSize();
$cacheSizeFormatted = Helper::formatBytes($cacheSize);

// Prüfen, ob das Log-Verzeichnis für Zugriffsstatistiken existiert; falls nicht, anlegen
$statsDir = MARQUES_ROOT_DIR . '/logs/stats';
if (!is_dir($statsDir)) {
    mkdir($statsDir, 0755, true);
}

// Zugriffsstatistiken laden
function loadSiteStatistics(): array {
    $stats = [
        'total_visits'     => 0,
        'visits_today'     => 0,
        'visits_yesterday' => 0,
        'visits_this_week' => 0,
        'visits_this_month'=> 0,
        'top_pages'        => [],
        'top_referrers'    => [],
        'browser_stats'    => [],
        'device_stats'     => [],
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
    for ($i = 0; $i < 30; $i++) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $logFile = $statsDir . '/' . $date . '.log';
        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if ($data) {
                    if (isset($data['time'])) {
                        $hour = (int) date('G', strtotime($data['time']));
                        $stats['hourly_stats'][$hour]++;
                    }
                    if (isset($data['url'])) {
                        $url = parse_url($data['url'], PHP_URL_PATH);
                        if (!isset($stats['top_pages'][$url])) {
                            $stats['top_pages'][$url] = 0;
                        }
                        $stats['top_pages'][$url]++;
                    }
                    if (isset($data['referrer']) && !empty($data['referrer'])) {
                        $referrer = parse_url($data['referrer'], PHP_URL_HOST);
                        if ($referrer) {
                            if (!isset($stats['top_referrers'][$referrer])) {
                                $stats['top_referrers'][$referrer] = 0;
                            }
                            $stats['top_referrers'][$referrer]++;
                        }
                    }
                    if (isset($data['user_agent'])) {
                        // Nutze Helper::formatBytes oder alternative Funktionen falls vorhanden
                        $browser = function_exists('detectBrowser') ? detectBrowser($data['user_agent']) : 'Unknown';
                        if (!isset($stats['browser_stats'][$browser])) {
                            $stats['browser_stats'][$browser] = 0;
                        }
                        $stats['browser_stats'][$browser]++;
                        $device = function_exists('detectDevice') ? detectDevice($data['user_agent']) : 'Unknown';
                        if (!isset($stats['device_stats'][$device])) {
                            $stats['device_stats'][$device] = 0;
                        }
                        $stats['device_stats'][$device]++;
                    }
                }
            }
        }
    }
    arsort($stats['top_pages']);
    $stats['top_pages'] = array_slice($stats['top_pages'], 0, 10, true);
    arsort($stats['top_referrers']);
    $stats['top_referrers'] = array_slice($stats['top_referrers'], 0, 10, true);
    arsort($stats['browser_stats']);
    arsort($stats['device_stats']);
    ksort($stats['daily_stats']);
    return $stats;
}
$siteStats = loadSiteStatistics();

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Aktuelle Seite bestimmen
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
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

// Letzte Aktivitäten erfassen
$recentActivity = [];
foreach ($pages as $pageInfo) {
    if (isset($pageInfo['date_modified']) && !empty($pageInfo['date_modified'])) {
        $recentActivity[] = [
            'type'  => 'page',
            'title' => $pageInfo['title'],
            'date'  => $pageInfo['date_modified'],
            'url'   => 'page-edit.php?id=' . $pageInfo['id'],
            'icon'  => 'file-alt'
        ];
    }
}
foreach ($recentPosts as $post) {
    $recentActivity[] = [
        'type'  => 'post',
        'title' => $post['title'],
        'date'  => $post['date_modified'] ?? $post['date_created'] ?? $post['date'],
        'url'   => 'blog-edit.php?id=' . $post['id'],
        'icon'  => 'blog'
    ];
}
usort($recentActivity, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});
$recentActivity = array_slice($recentActivity, 0, 10);

