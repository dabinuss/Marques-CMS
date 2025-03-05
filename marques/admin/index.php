<?php
/**
 * marques CMS - Admin-Panel
 * 
 * Haupteinstiegspunkt für das Admin-Panel.
 *
 * @package marques
 * @subpackage admin
 */

// Basispfad definieren
define('MARQUES_ROOT_DIR', dirname(__DIR__));
define('IS_ADMIN', true);

// Bootstrap laden
require_once MARQUES_ROOT_DIR . '/system/core/bootstrap.inc.php';

// Konfiguration laden
$system_config = require MARQUES_CONFIG_DIR . '/system.config.php';

// Admin-Klasse initialisieren
$admin = new \Marques\Core\Admin();
$admin->requireLogin();

// Benutzer-Objekt initialisieren
$user = new \Marques\Core\User();

// Admin-Statistiken holen
$stats = $admin->getStatistics();

// Blog Manager initialisieren für erweiterte Statistiken
$blogManager = new \Marques\Core\BlogManager();
$categories = $blogManager->getCategories();
$tags = $blogManager->getTags();

// Neueste Blog-Beiträge für Dashboard
$recentPosts = $blogManager->getAllPosts(5, 0);

// Page Manager für Seitenstatistiken
$pageManager = new \Marques\Core\PageManager();
$pages = $pageManager->getAllPages();

// Media Manager für Medienstatistiken
$mediaManager = new \Marques\Core\MediaManager();
$mediaFiles = $mediaManager->getAllMedia();

// Prüfen, ob das Zugriffsstatistik-Log-Verzeichnis existiert und erstellen, falls nicht
$statsDir = MARQUES_ROOT_DIR . '/logs/stats';
if (!is_dir($statsDir)) {
    mkdir($statsDir, 0755, true);
}

// Zugriffsstatistiken laden
$siteStats = loadSiteStatistics();

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Aktuelle Seite bestimmen
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$allowed_pages = ['dashboard', 'pages', 'blog', 'media', 'users', 'settings', 'statistics'];

if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

// Seitentitel bestimmen
$page_titles = [
    'dashboard' => 'Dashboard',
    'pages' => 'Seiten verwalten',
    'blog' => 'Blog verwalten',
    'media' => 'Medienbibliothek',
    'users' => 'Benutzer verwalten',
    'settings' => 'Einstellungen',
    'statistics' => 'Zugriffsstatistiken'
];

$page_title = $page_titles[$page] ?? 'Dashboard';

// Letzte Aktivitäten erfassen
$recentActivity = [];

// Letzte geänderte Seiten
foreach ($pages as $index => $pageInfo) {
    if (isset($pageInfo['date_modified']) && !empty($pageInfo['date_modified'])) {
        $recentActivity[] = [
            'type' => 'page',
            'title' => $pageInfo['title'],
            'date' => $pageInfo['date_modified'],
            'url' => 'page-edit.php?id=' . $pageInfo['id'],
            'icon' => 'file-alt'
        ];
    }
}

// Letzte Blog-Beiträge
foreach ($recentPosts as $post) {
    $recentActivity[] = [
        'type' => 'post',
        'title' => $post['title'],
        'date' => $post['date_modified'] ?? $post['date_created'] ?? $post['date'],
        'url' => 'blog-edit.php?id=' . $post['id'],
        'icon' => 'blog'
    ];
}

// Nach Datum sortieren (neueste zuerst)
usort($recentActivity, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// Auf die letzten 10 Aktivitäten beschränken
$recentActivity = array_slice($recentActivity, 0, 10);

// Systemzustand prüfen
$systemHealth = [
    'debug_mode' => $system_config['debug'] ?? false,
    'maintenance_mode' => $system_config['maintenance_mode'] ?? false,
    'cache_enabled' => $system_config['cache_enabled'] ?? false,
    'write_permissions' => [
        'content' => is_writable(MARQUES_CONTENT_DIR),
        'config' => is_writable(MARQUES_CONFIG_DIR),
        'assets' => is_writable(MARQUES_ROOT_DIR . '/assets/media')
    ]
];

/**
 * Lädt die Zugriffsstatistiken aus den Log-Dateien
 */
function loadSiteStatistics() {
    $stats = [
        'total_visits' => 0,
        'visits_today' => 0,
        'visits_yesterday' => 0,
        'visits_this_week' => 0,
        'visits_this_month' => 0,
        'top_pages' => [],
        'top_referrers' => [],
        'browser_stats' => [],
        'device_stats' => [],
        'hourly_stats' => array_fill(0, 24, 0),
        'daily_stats' => []
    ];
    
    $statsDir = MARQUES_ROOT_DIR . '/logs/stats';
    if (!is_dir($statsDir)) {
        return $stats;
    }

    // Die letzten 30 Tage auswerten
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $startOfWeek = date('Y-m-d', strtotime('this week'));
    $startOfMonth = date('Y-m-d', strtotime('first day of this month'));
    
    // Zugriffsprotokolle der letzten 30 Tage durchsuchen
    for ($i = 0; $i < 30; $i++) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $logFile = $statsDir . '/' . $date . '.log';
        
        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $dailyCount = count($lines);
            
            // Gesamtbesuche
            $stats['total_visits'] += $dailyCount;
            
            // Tägliche Statistiken für Diagramm
            $stats['daily_stats'][$date] = $dailyCount;
            
            // Besuche heute/gestern/diese Woche/diesen Monat
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
            
            // Detaillierte Auswertung der Protokollzeilen
            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if ($data) {
                    // Stündliche Statistiken sammeln
                    if (isset($data['time'])) {
                        $hour = (int)date('G', strtotime($data['time']));
                        $stats['hourly_stats'][$hour]++;
                    }
                    
                    // Top-Seiten sammeln
                    if (isset($data['url'])) {
                        $url = parse_url($data['url'], PHP_URL_PATH);
                        if (!isset($stats['top_pages'][$url])) {
                            $stats['top_pages'][$url] = 0;
                        }
                        $stats['top_pages'][$url]++;
                    }
                    
                    // Referrer sammeln
                    if (isset($data['referrer']) && !empty($data['referrer'])) {
                        $referrer = parse_url($data['referrer'], PHP_URL_HOST);
                        if ($referrer) {
                            if (!isset($stats['top_referrers'][$referrer])) {
                                $stats['top_referrers'][$referrer] = 0;
                            }
                            $stats['top_referrers'][$referrer]++;
                        }
                    }
                    
                    // Browser-Statistiken
                    if (isset($data['user_agent'])) {
                        $browser = detectBrowser($data['user_agent']);
                        if (!isset($stats['browser_stats'][$browser])) {
                            $stats['browser_stats'][$browser] = 0;
                        }
                        $stats['browser_stats'][$browser]++;
                        
                        // Gerätetyp
                        $device = detectDevice($data['user_agent']);
                        if (!isset($stats['device_stats'][$device])) {
                            $stats['device_stats'][$device] = 0;
                        }
                        $stats['device_stats'][$device]++;
                    }
                }
            }
        }
    }
    
    // Top-Seiten sortieren und begrenzen
    arsort($stats['top_pages']);
    $stats['top_pages'] = array_slice($stats['top_pages'], 0, 10, true);
    
    // Top-Referrer sortieren und begrenzen
    arsort($stats['top_referrers']);
    $stats['top_referrers'] = array_slice($stats['top_referrers'], 0, 10, true);
    
    // Browser-Statistiken sortieren
    arsort($stats['browser_stats']);
    
    // Geräte-Statistiken sortieren
    arsort($stats['device_stats']);
    
    // Tägliche Statistiken sortieren
    ksort($stats['daily_stats']);
    
    return $stats;
}

/**
 * Erkennt den Browser aus dem User-Agent
 */
function detectBrowser($userAgent) {
    if (stripos($userAgent, 'MSIE') !== false || stripos($userAgent, 'Trident') !== false) {
        return 'Internet Explorer';
    } elseif (stripos($userAgent, 'Edg') !== false) {
        return 'Edge';
    } elseif (stripos($userAgent, 'Firefox') !== false) {
        return 'Firefox';
    } elseif (stripos($userAgent, 'Chrome') !== false) {
        return 'Chrome';
    } elseif (stripos($userAgent, 'Safari') !== false) {
        return 'Safari';
    } elseif (stripos($userAgent, 'Opera') !== false || stripos($userAgent, 'OPR') !== false) {
        return 'Opera';
    } else {
        return 'Sonstige';
    }
}

/**
 * Erkennt den Gerätetyp aus dem User-Agent
 */
function detectDevice($userAgent) {
    if (stripos($userAgent, 'Mobile') !== false || stripos($userAgent, 'Android') !== false) {
        return 'Mobil';
    } elseif (stripos($userAgent, 'Tablet') !== false || stripos($userAgent, 'iPad') !== false) {
        return 'Tablet';
    } else {
        return 'Desktop';
    }
}

?>
<!DOCTYPE html>
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Admin-Panel - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Chart.js für Statistik-Diagramme -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="admin-layout">
        
        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title"><?php echo htmlspecialchars($page_title); ?></h2>
                
                <div class="admin-actions">
                    <?php if ($page === 'pages'): ?>
                    <a href="page-edit.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-plus"></i></span>
                        Neue Seite
                    </a>
                    <?php elseif ($page === 'blog'): ?>
                    <a href="blog-edit.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-plus"></i></span>
                        Neuer Beitrag
                    </a>
                    <?php elseif ($page === 'media'): ?>
                    <a href="media.php?action=upload" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-upload"></i></span>
                        Hochladen
                    </a>
                    <?php endif; ?>
                    
                    <a href="../" class="admin-button" target="_blank">
                        <span class="admin-button-icon"><i class="fas fa-external-link-alt"></i></span>
                        Website ansehen
                    </a>
                </div>
            </div>
            
            <?php if ($page === 'dashboard'): ?>
                
                <!-- 1. Reihe: Welcome | Quick Actions -->
                <div class="admin-dashboard-row">
                    <!-- Linke Spalte: Willkommensbereich mit Hinweisen -->
                    <div class="admin-dashboard-column">
                        <div class="admin-welcome">
                            <h3>Willkommen, <?php echo htmlspecialchars($user->getCurrentDisplayName()); ?>!</h3>
                            <p>
                                Heute ist <?php echo date('l, d. F Y', time()); ?>. 
                                Sie haben sich zuletzt am <?php echo date('d.m.Y \u\m H:i', $_SESSION['marques_user']['last_login']); ?> Uhr angemeldet.
                            </p>
                            
                            <?php if($systemHealth['maintenance_mode']): ?>
                            <div class="admin-alert error">
                                <i class="fas fa-exclamation-triangle"></i> Die Website befindet sich im Wartungsmodus und ist für normale Besucher nicht erreichbar.
                            </div>
                            <?php endif; ?>
                            
                            <?php if($systemHealth['debug_mode']): ?>
                            <div class="admin-alert warning">
                                <i class="fas fa-bug"></i> Der Debug-Modus ist aktiviert. Für die Produktivumgebung sollte dieser deaktiviert werden.
                            </div>
                            <?php endif; ?>
                            
                            <?php foreach($systemHealth['write_permissions'] as $dir => $writable): ?>
                                <?php if(!$writable): ?>
                                <div class="admin-alert error">
                                    <i class="fas fa-lock"></i> Keine Schreibberechtigung für <?php echo htmlspecialchars($dir); ?>-Verzeichnis!
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Rechte Spalte: Schnellzugriff -->
                    <div class="admin-dashboard-column">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h3><i class="fas fa-bolt"></i> Schnellzugriff</h3>
                            </div>
                            <div class="admin-card-content">
                                <div class="admin-quick-actions">
                                    <a href="page-edit.php" class="admin-quick-action">
                                        <i class="fas fa-file-alt"></i>
                                        <span>Neue Seite</span>
                                    </a>
                                    <a href="blog-edit.php" class="admin-quick-action">
                                        <i class="fas fa-blog"></i>
                                        <span>Neuer Beitrag</span>
                                    </a>
                                    <a href="media.php?action=upload" class="admin-quick-action">
                                        <i class="fas fa-upload"></i>
                                        <span>Medien hochladen</span>
                                    </a>
                                    <a href="settings.php" class="admin-quick-action">
                                        <i class="fas fa-cog"></i>
                                        <span>Einstellungen</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 2. Reihe: Statistik-Karten | Besuchs-Diagramm -->
                <div class="admin-dashboard-row">
                    <!-- Linke Spalte: Alle Statistik-Karten -->
                    <div class="admin-dashboard-column">
                        <!-- Besuchsstatistik-Karten -->
                        <div class="admin-stats">
                            <div class="admin-stat-card">
                                <div class="admin-stat-icon"><i class="fas fa-chart-line"></i></div>
                                <div class="admin-stat-title">Besuche heute</div>
                                <div class="admin-stat-value"><?php echo number_format($siteStats['visits_today']); ?></div>
                                <div class="admin-stat-trend">
                                    <?php 
                                    $change = $siteStats['visits_yesterday'] > 0 ? 
                                        (($siteStats['visits_today'] - $siteStats['visits_yesterday']) / $siteStats['visits_yesterday'] * 100) : 
                                        100;
                                    $icon = $change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                    $class = $change >= 0 ? 'positive' : 'negative';
                                    ?>
                                    <span class="<?php echo $class; ?>">
                                        <i class="fas <?php echo $icon; ?>"></i> 
                                        <?php echo abs(round($change)); ?>% vs. gestern
                                    </span>
                                </div>
                            </div>
                            
                            <div class="admin-stat-card">
                                <div class="admin-stat-icon"><i class="fas fa-users"></i></div>
                                <div class="admin-stat-title">Besuche Woche</div>
                                <div class="admin-stat-value"><?php echo number_format($siteStats['visits_this_week']); ?></div>
                                <div class="admin-stat-action">
                                    <a href="?page=statistics" class="admin-stat-link">Details <i class="fas fa-chevron-right"></i></a>
                                </div>
                            </div>
                            
                            <div class="admin-stat-card">
                                <div class="admin-stat-icon"><i class="fas fa-file-alt"></i></div>
                                <div class="admin-stat-title">Seiten</div>
                                <div class="admin-stat-value"><?php echo $stats['pages']; ?></div>
                                <div class="admin-stat-action">
                                    <a href="pages.php">Verwalten <i class="fas fa-chevron-right"></i></a>
                                </div>
                            </div>
                            
                            <div class="admin-stat-card">
                                <div class="admin-stat-icon"><i class="fas fa-blog"></i></div>
                                <div class="admin-stat-title">Blog-Beiträge</div>
                                <div class="admin-stat-value"><?php echo $stats['blog_posts']; ?></div>
                                <div class="admin-stat-action">
                                    <a href="blog.php">Verwalten <i class="fas fa-chevron-right"></i></a>
                                </div>
                            </div>
                            
                            <div class="admin-stat-card">
                                <div class="admin-stat-icon"><i class="fas fa-images"></i></div>
                                <div class="admin-stat-title">Mediendateien</div>
                                <div class="admin-stat-value"><?php echo $stats['media_files']; ?></div>
                                <div class="admin-stat-action">
                                    <a href="media.php">Verwalten <i class="fas fa-chevron-right"></i></a>
                                </div>
                            </div>
                            
                            <div class="admin-stat-card">
                                <div class="admin-stat-icon"><i class="fas fa-users"></i></div>
                                <div class="admin-stat-title">Benutzer</div>
                                <div class="admin-stat-value"><?php echo count($allUsers ?? []); ?></div>
                                <div class="admin-stat-action">
                                    <a href="users.php">Verwalten <i class="fas fa-chevron-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Rechte Spalte: Besuchs-Diagramm -->
                    <div class="admin-dashboard-column">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h3><i class="fas fa-chart-bar"></i> Besucherstatistik (letzte 14 Tage)</h3>
                            </div>
                            <div class="admin-card-content">
                                <canvas id="visitsChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 3. Reihe: Neueste Aktivitäten | Neueste Blog-Beiträge -->
                <div class="admin-dashboard-row">
                    <!-- Linke Spalte: Neueste Aktivitäten -->
                    <div class="admin-dashboard-column">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h3><i class="fas fa-history"></i> Neueste Aktivitäten</h3>
                            </div>
                            <div class="admin-card-content">
                                <?php if (empty($recentActivity)): ?>
                                    <p class="admin-no-data">Keine kürzlichen Aktivitäten gefunden.</p>
                                <?php else: ?>
                                    <ul class="admin-activity-list">
                                        <?php foreach ($recentActivity as $activity): ?>
                                            <li class="admin-activity-item">
                                                <span class="admin-activity-icon">
                                                    <i class="fas fa-<?php echo $activity['icon']; ?>"></i>
                                                </span>
                                                <div class="admin-activity-details">
                                                    <a href="<?php echo $activity['url']; ?>" class="admin-activity-title">
                                                        <?php echo htmlspecialchars($activity['title']); ?>
                                                    </a>
                                                    <span class="admin-activity-date">
                                                        <?php echo date('d.m.Y H:i', strtotime($activity['date'])); ?>
                                                    </span>
                                                </div>
                                                <span class="admin-activity-type">
                                                    <?php echo $activity['type'] === 'page' ? 'Seite' : 'Beitrag'; ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Rechte Spalte: Neueste Blog-Beiträge -->
                    <div class="admin-dashboard-column">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h3><i class="fas fa-newspaper"></i> Neueste Blog-Beiträge</h3>
                            </div>
                            <div class="admin-card-content">
                                <?php if (empty($recentPosts)): ?>
                                    <p class="admin-no-data">Keine Blog-Beiträge vorhanden.</p>
                                <?php else: ?>
                                    <table class="admin-table admin-table-mini">
                                        <thead>
                                            <tr>
                                                <th>Titel</th>
                                                <th>Datum</th>
                                                <th>Status</th>
                                                <th>Aktionen</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentPosts as $post): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($post['title']); ?></td>
                                                    <td><?php echo date('d.m.Y', strtotime($post['date'])); ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $post['status']; ?>">
                                                            <?php echo ucfirst($post['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="admin-table-actions">
                                                        <a href="blog-edit.php?id=<?php echo $post['id']; ?>" class="admin-table-action" title="Bearbeiten">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="../blog/<?php echo $post['slug']; ?>" target="_blank" class="admin-table-action" title="Ansehen">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <div class="admin-card-footer">
                                        <a href="blog.php" class="admin-button-small">Alle Blog-Beiträge anzeigen</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 4. Reihe: Zusätzliche Inhalte (Top-Seiten, Gerätenutzung, Systeminfo) -->
                <div class="admin-dashboard-row">
                    <!-- Linke Spalte: Systeminfo -->
                    <div class="admin-dashboard-column">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h3><i class="fas fa-server"></i> Systeminformationen</h3>
                            </div>
                            <div class="admin-card-content">
                                <table class="admin-system-info">
                                    <tr>
                                        <td><i class="fas fa-code"></i> PHP-Version:</td>
                                        <td><?php echo $stats['php_version']; ?></td>
                                    </tr>
                                    <tr>
                                        <td><i class="fas fa-tag"></i> marques CMS-Version:</td>
                                        <td><?php echo $stats['marques_version']; ?></td>
                                    </tr>
                                    <tr>
                                        <td><i class="fas fa-hdd"></i> Speichernutzung:</td>
                                        <td><?php echo $stats['disk_usage']; ?></td>
                                    </tr>
                                    <tr>
                                        <td><i class="fas fa-clock"></i> Serverzeit:</td>
                                        <td><?php echo date('d.m.Y H:i:s'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><i class="fas fa-sitemap"></i> Kategorien/Tags:</td>
                                        <td><?php echo count($categories); ?> / <?php echo count($tags); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Mittlere Spalte: Top-Seiten -->
                    <div class="admin-dashboard-column">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h3><i class="fas fa-star"></i> Beliebteste Seiten</h3>
                            </div>
                            <div class="admin-card-content">
                                <?php if (empty($siteStats['top_pages'])): ?>
                                    <p class="admin-no-data">Keine Daten verfügbar.</p>
                                <?php else: ?>
                                    <ul class="admin-stats-list">
                                        <?php 
                                        $i = 1;
                                        foreach ($siteStats['top_pages'] as $url => $visits): 
                                            if ($i > 5) break; // Nur die Top 5 anzeigen
                                        ?>
                                            <li class="admin-stats-item">
                                                <span class="admin-stats-rank"><?php echo $i++; ?></span>
                                                <div class="admin-stats-details">
                                                    <span class="admin-stats-title"><?php echo htmlspecialchars($url == '/' ? 'Startseite' : $url); ?></span>
                                                    <div class="admin-stats-bar-container">
                                                        <div class="admin-stats-bar" style="width: <?php echo min(100, ($visits / max($siteStats['top_pages']) * 100)); ?>%"></div>
                                                    </div>
                                                </div>
                                                <span class="admin-stats-value"><?php echo number_format($visits); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <div class="admin-card-footer">
                                        <a href="?page=statistics" class="admin-button-small">Alle Statistiken anzeigen</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Rechte Spalte: Gerätenutzung -->
                    <div class="admin-dashboard-column">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h3><i class="fas fa-mobile-alt"></i> Gerätenutzung</h3>
                            </div>
                            <div class="admin-card-content chart-container">
                                <?php if (empty($siteStats['device_stats'])): ?>
                                    <p class="admin-no-data">Keine Daten verfügbar.</p>
                                <?php else: ?>
                                    <canvas id="deviceChart"></canvas>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($page === 'statistics'): ?>
                <!-- Erweiterte Statistikseite (code bleibt unverändert) -->
                <div class="admin-welcome">
                    <h3>Website-Statistiken</h3>
                    <p>Hier sehen Sie detaillierte Zugriffsstatistiken für Ihre Website.</p>
                </div>
                
                <!-- Rest des Statistik-Codes (unverändert) -->
                <!-- Statistik-Übersicht -->
                <div class="admin-stats">
                    <div class="admin-stat-card">
                        <div class="admin-stat-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="admin-stat-title">Besuche heute</div>
                        <div class="admin-stat-value"><?php echo number_format($siteStats['visits_today']); ?></div>
                    </div>
                    
                    <div class="admin-stat-card">
                        <div class="admin-stat-icon"><i class="fas fa-chart-bar"></i></div>
                        <div class="admin-stat-title">Besuche gestern</div>
                        <div class="admin-stat-value"><?php echo number_format($siteStats['visits_yesterday']); ?></div>
                    </div>
                    
                    <div class="admin-stat-card">
                        <div class="admin-stat-icon"><i class="fas fa-calendar-week"></i></div>
                        <div class="admin-stat-title">Besuche diese Woche</div>
                        <div class="admin-stat-value"><?php echo number_format($siteStats['visits_this_week']); ?></div>
                    </div>
                    
                    <div class="admin-stat-card">
                        <div class="admin-stat-icon"><i class="fas fa-calendar-alt"></i></div>
                        <div class="admin-stat-title">Besuche diesen Monat</div>
                        <div class="admin-stat-value"><?php echo number_format($siteStats['visits_this_month']); ?></div>
                    </div>
                </div>
                
                <!-- Hauptdiagramm - Tägliche Besuche -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3><i class="fas fa-chart-area"></i> Besuchsentwicklung (30 Tage)</h3>
                    </div>
                    <div class="admin-card-content">
                        <canvas id="visitsHistoryChart" height="120"></canvas>
                    </div>
                </div>
                
                <!-- Statistiken pro Tageszeit -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3><i class="fas fa-clock"></i> Besuche nach Tageszeit</h3>
                    </div>
                    <div class="admin-card-content">
                        <canvas id="hourlyVisitsChart" height="100"></canvas>
                    </div>
                </div>
                
                <!-- Geräte und Browser -->
                <div class="admin-dashboard-row">
                    <div class="admin-dashboard-column">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h3><i class="fas fa-mobile-alt"></i> Zugriffe nach Gerät</h3>
                            </div>
                            <div class="admin-card-content chart-container">
                                <?php if (empty($siteStats['device_stats'])): ?>
                                    <p class="admin-no-data">Keine Daten verfügbar.</p>
                                <?php else: ?>
                                    <canvas id="fullDeviceChart"></canvas>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="admin-dashboard-column">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h3><i class="fas fa-globe"></i> Zugriffe nach Browser</h3>
                            </div>
                            <div class="admin-card-content chart-container">
                                <?php if (empty($siteStats['browser_stats'])): ?>
                                    <p class="admin-no-data">Keine Daten verfügbar.</p>
                                <?php else: ?>
                                    <canvas id="browserChart"></canvas>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Detaillierte Zugriffsstatistiken -->
                <div class="admin-dashboard-row">
                    <div class="admin-dashboard-column">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h3><i class="fas fa-star"></i> Top 10 Seiten</h3>
                            </div>
                            <div class="admin-card-content">
                                <?php if (empty($siteStats['top_pages'])): ?>
                                    <p class="admin-no-data">Keine Daten verfügbar.</p>
                                <?php else: ?>
                                    <ul class="admin-stats-list">
                                        <?php 
                                        $i = 1;
                                        foreach ($siteStats['top_pages'] as $url => $visits): 
                                        ?>
                                            <li class="admin-stats-item">
                                                <span class="admin-stats-rank"><?php echo $i++; ?></span>
                                                <div class="admin-stats-details">
                                                    <span class="admin-stats-title"><?php echo htmlspecialchars($url == '/' ? 'Startseite' : $url); ?></span>
                                                    <div class="admin-stats-bar-container">
                                                        <div class="admin-stats-bar" style="width: <?php echo min(100, ($visits / max($siteStats['top_pages']) * 100)); ?>%"></div>
                                                    </div>
                                                </div>
                                                <span class="admin-stats-value"><?php echo number_format($visits); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="admin-dashboard-column">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h3><i class="fas fa-link"></i> Top 10 Referrer</h3>
                            </div>
                            <div class="admin-card-content">
                                <?php if (empty($siteStats['top_referrers'])): ?>
                                    <p class="admin-no-data">Keine Daten verfügbar.</p>
                                <?php else: ?>
                                    <ul class="admin-stats-list">
                                        <?php 
                                        $i = 1;
                                        foreach ($siteStats['top_referrers'] as $referrer => $visits): 
                                        ?>
                                            <li class="admin-stats-item">
                                                <span class="admin-stats-rank"><?php echo $i++; ?></span>
                                                <div class="admin-stats-details">
                                                    <span class="admin-stats-title"><?php echo htmlspecialchars($referrer); ?></span>
                                                    <div class="admin-stats-bar-container">
                                                        <div class="admin-stats-bar" style="width: <?php echo min(100, ($visits / max($siteStats['top_referrers']) * 100)); ?>%"></div>
                                                    </div>
                                                </div>
                                                <span class="admin-stats-value"><?php echo number_format($visits); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($page === 'pages'): ?>
                <script>window.location.href = 'pages.php';</script>
                <p>Sie werden zur Seiten-Verwaltung weitergeleitet...</p>
                <p><a href="pages.php">Klicken Sie hier, wenn Sie nicht automatisch weitergeleitet werden.</a></p>

            <?php elseif ($page === 'blog'): ?>
                <script>window.location.href = 'blog.php';</script>
                <p>Sie werden zur Blog-Verwaltung weitergeleitet...</p>
                <p><a href="blog.php">Klicken Sie hier, wenn Sie nicht automatisch weitergeleitet werden.</a></p>

            <?php elseif ($page === 'media'): ?>
                <script>window.location.href = 'media.php';</script>
                <p>Sie werden zur Medien-Verwaltung weitergeleitet...</p>
                <p><a href="media.php">Klicken Sie hier, wenn Sie nicht automatisch weitergeleitet werden.</a></p>

            <?php elseif ($page === 'users' && $user->isAdmin()): ?>
                <script>window.location.href = 'users.php';</script>
                <p>Sie werden zur Benutzer-Verwaltung weitergeleitet...</p>
                <p><a href="users.php">Klicken Sie hier, wenn Sie nicht automatisch weitergeleitet werden.</a></p>

            <?php elseif ($page === 'settings' && $user->isAdmin()): ?>
                <script>window.location.href = 'settings.php';</script>
                <p>Sie werden zu den Einstellungen weitergeleitet...</p>
                <p><a href="settings.php">Klicken Sie hier, wenn Sie nicht automatisch weitergeleitet werden.</a></p>
            <?php endif; ?>
        </main>
    </div>

    <!-- JavaScript für Charts -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Charts nur einrichten, wenn Chart.js geladen ist
            if (typeof Chart !== 'undefined') {
                <?php if ($page === 'dashboard'): ?>
                // Besuchsdiagramm (letzte 14 Tage)
                const visitCtx = document.getElementById('visitsChart').getContext('2d');
                
                const visitData = <?php 
                    $days = 14; // 14 Tage anzeigen
                    $dailyData = [];
                    $labels = [];
                    
                    for ($i = $days - 1; $i >= 0; $i--) {
                        $date = date('Y-m-d', strtotime("-$i days"));
                        $labels[] = date('d.m', strtotime($date));
                        $dailyData[] = $siteStats['daily_stats'][$date] ?? 0;
                    }
                    
                    echo json_encode($dailyData);
                ?>;
                
                const visitLabels = <?php echo json_encode($labels); ?>;
                
                new Chart(visitCtx, {
                    type: 'line',
                    data: {
                        labels: visitLabels,
                        datasets: [{
                            label: 'Besuche',
                            data: visitData,
                            borderColor: '#4a6fa5',
                            backgroundColor: 'rgba(74, 111, 165, 0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2,
                            pointRadius: 3,
                            pointBackgroundColor: '#4a6fa5'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                callbacks: {
                                    label: function(context) {
                                        return 'Besuche: ' + context.parsed.y;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                });
                
                // Geräte-Diagramm
                <?php if (!empty($siteStats['device_stats'])): ?>
                const deviceCtx = document.getElementById('deviceChart').getContext('2d');
                
                const deviceData = <?php echo json_encode(array_values($siteStats['device_stats'])); ?>;
                const deviceLabels = <?php echo json_encode(array_keys($siteStats['device_stats'])); ?>;
                
                new Chart(deviceCtx, {
                    type: 'doughnut',
                    data: {
                        labels: deviceLabels,
                        datasets: [{
                            data: deviceData,
                            backgroundColor: [
                                '#4a6fa5',
                                '#6c757d',
                                '#28a745'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        },
                        cutout: '60%'
                    }
                });
                <?php endif; ?>
                
                <?php elseif ($page === 'statistics'): ?>
                // Ausführliche Statistikseite - Besuchsentwicklung der letzten 30 Tage
                const historyCtx = document.getElementById('visitsHistoryChart').getContext('2d');
                
                const historyData = <?php 
                    $days = 30; // 30 Tage anzeigen
                    $historyDailyData = [];
                    $historyLabels = [];
                    
                    for ($i = $days - 1; $i >= 0; $i--) {
                        $date = date('Y-m-d', strtotime("-$i days"));
                        $historyLabels[] = date('d.m', strtotime($date));
                        $historyDailyData[] = $siteStats['daily_stats'][$date] ?? 0;
                    }
                    
                    echo json_encode($historyDailyData);
                ?>;
                
                const historyLabels = <?php echo json_encode($historyLabels); ?>;
                
                new Chart(historyCtx, {
                    type: 'bar',
                    data: {
                        labels: historyLabels,
                        datasets: [{
                            label: 'Besuche',
                            data: historyData,
                            backgroundColor: 'rgba(74, 111, 165, 0.7)',
                            borderColor: '#4a6fa5',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                });
                
                // Stündliche Besuche
                const hourlyCtx = document.getElementById('hourlyVisitsChart').getContext('2d');
                
                const hourlyData = <?php echo json_encode(array_values($siteStats['hourly_stats'])); ?>;
                const hourlyLabels = <?php 
                    $hourLabels = [];
                    for ($i = 0; $i < 24; $i++) {
                        $hourLabels[] = sprintf('%02d:00', $i);
                    }
                    echo json_encode($hourLabels);
                ?>;
                
                new Chart(hourlyCtx, {
                    type: 'line',
                    data: {
                        labels: hourlyLabels,
                        datasets: [{
                            label: 'Besuche',
                            data: hourlyData,
                            borderColor: '#4a6fa5',
                            backgroundColor: 'rgba(74, 111, 165, 0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2,
                            pointRadius: 3,
                            pointBackgroundColor: '#4a6fa5'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                });
                
                // Vollständige Gerätestatistik
                <?php if (!empty($siteStats['device_stats'])): ?>
                const fullDeviceCtx = document.getElementById('fullDeviceChart').getContext('2d');
                
                const fullDeviceData = <?php echo json_encode(array_values($siteStats['device_stats'])); ?>;
                const fullDeviceLabels = <?php echo json_encode(array_keys($siteStats['device_stats'])); ?>;
                
                new Chart(fullDeviceCtx, {
                    type: 'pie',
                    data: {
                        labels: fullDeviceLabels,
                        datasets: [{
                            data: fullDeviceData,
                            backgroundColor: [
                                '#4a6fa5',
                                '#6c757d',
                                '#28a745'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right'
                            }
                        }
                    }
                });
                <?php endif; ?>
                
                // Browser-Statistik
                <?php if (!empty($siteStats['browser_stats'])): ?>
                const browserCtx = document.getElementById('browserChart').getContext('2d');
                
                const browserData = <?php echo json_encode(array_values($siteStats['browser_stats'])); ?>;
                const browserLabels = <?php echo json_encode(array_keys($siteStats['browser_stats'])); ?>;
                
                new Chart(browserCtx, {
                    type: 'pie',
                    data: {
                        labels: browserLabels,
                        datasets: [{
                            data: browserData,
                            backgroundColor: [
                                '#4a6fa5',
                                '#28a745',
                                '#ffc107',
                                '#dc3545',
                                '#6c757d',
                                '#17a2b8'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right'
                            }
                        }
                    }
                });
                <?php endif; ?>
                <?php endif; ?>
            }
        });
    </script>
</body>
</html>