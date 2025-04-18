<?php
declare(strict_types=1);

namespace Admin\Controller;

// Benötigte Klassen importieren
use Admin\Core\Template;
use Admin\Core\Statistics;
use Admin\Http\Router;
use Marques\Util\Helper;
use Marques\Http\Request;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Service\PageManager;
use Marques\Service\BlogManager;
use Marques\Core\Cache;
use Marques\Service\User;
use Marques\Core\Logger; 
use Marques\Filesystem\PathRegistry;

class DashboardController
{
    private Template $template;
    private Statistics $adminStats;
    private Router $adminRouter;
    private Helper $helper;
    private DatabaseHandler $dbHandler;
    private PageManager $pageManager;
    private BlogManager $blogManager;
    private Cache $cacheManager;
    private User $userManager; 
    private Logger $logger;
    private array $systemConfig = [];
    private PathRegistry $pathRegistry;

    public function __construct(
        Template $template,
        Statistics $adminStats,
        Router $adminRouter,
        Helper $helper,
        DatabaseHandler $dbHandler,
        PageManager $pageManager,
        BlogManager $blogManager,
        Cache $cacheManager,
        User $user,
        Logger $logger,
        PathRegistry $pathRegistry
    )
    {
        $this->template = $template;
        $this->adminStats = $adminStats;
        $this->helper = $helper;
        $this->adminRouter = $adminRouter;
        $this->dbHandler = $dbHandler;
        $this->pageManager = $pageManager;
        $this->blogManager = $blogManager;
        $this->cacheManager = $cacheManager;
        $this->userManager = $user; // Speichern das User-Objekt
        $this->logger = $logger;
        $this->pathRegistry = $pathRegistry;

        // Lade System-Config einmal im Konstruktor (oder bei Bedarf)
        try {
            $this->systemConfig = $this->dbHandler->table('settings')->where('id', '=', 1)->first() ?: [];
        } catch (\Exception $e) {
            $this->logger->error("DashboardController: Konnte Settings nicht laden.", ['exception' => $e]);
            $this->systemConfig = []; // Fallback
        }
    }

    /**
     * Zeigt das Admin-Dashboard an.
     */
    public function index(Request $request, array $params): void
    {
        $isDebug = $this->systemConfig['debug'] ?? false;

        $statsSummary = "Statistiken konnten nicht geladen werden.";
        $systemInfo = [];
        try {
            $statsSummary = $this->adminStats->getAdminSummary();
            $systemInfo   = $this->adminStats->getSystemInfoArray();
        } catch (\Exception $e) {
            $this->logger->error("Fehler beim Laden der Admin-Statistiken.", ['exception' => $e]);
        }

        $siteStats = $this->loadSiteStatisticsInternal();

        $recentActivity = $this->buildRecentActivityInternal();

        $numCached = 0;
        $cacheSize = 0;
        $cacheStats = ['total_requests' => 0, 'cache_hits' => 0, 'hit_rate' => 0, 'avg_access_time' => 0];
        try {
            $numCached    = $this->cacheManager->getCacheFileCount();
            $cacheSize    = $this->cacheManager->getCacheSize();
            $cacheStats   = $this->cacheManager->getStatistics();
        } catch (\Exception $e) {
            $this->logger->error("Fehler beim Laden der Cache-Daten.", ['exception' => $e]);
        }
        $cacheSizeFormatted = $this->helper->formatBytes($cacheSize);

        $recentPostsForTable = [];
        try {
            $recentPostsForTable = $this->blogManager->getAllPosts(5, 0); // Beispiel: letzer Parameter für mehr Details?
        } catch (\Exception $e) {
             $this->logger->error("Fehler beim Laden der neuesten Posts für Tabelle.", ['exception' => $e]);
        }

        list($chartLabels, $chartData) = $this->prepareChartData($siteStats);

        $loggedInUsername = $this->userManager->getCurrentDisplayName(); // Annahme: Methode existiert

        $lastLoginTimestamp = isset($_SESSION['marques_user']) && isset($_SESSION['marques_user']['last_login']) 
        ? $_SESSION['marques_user']['last_login'] 
        : time();

        // 2. Daten für das Template zusammenstellen
        $viewData = [
            'page_title'          => 'Dashboard',
            'system_config'       => $this->systemConfig, // System-Config übergeben (für Debug/Maintenance Anzeige etc.)
            'username'            => $loggedInUsername,
            'lastLoginTimestamp'  => $lastLoginTimestamp,
            'statsSummary'        => $statsSummary,
            'systemInfo'          => $systemInfo,
            'siteStats'           => $siteStats, // Rohe Statistikdaten, falls im Template noch benötigt
            'recentActivity'      => $recentActivity,
            'recentPostsForTable' => $recentPostsForTable, // Für die Tabelle der letzten Posts
            'numCached'           => $numCached,
            'cacheSizeFormatted'  => $cacheSizeFormatted,
            'cacheStats'          => $cacheStats, // Detaillierte Cache-Statistiken
            'chartLabels'         => $chartLabels,
            'chartData'           => $chartData,
            'helper'              => $this->helper, // Helper für URLs etc. im Template
            'Service'             => null, // Übergabe nicht mehr nötig, wenn Logik im Controller ist
            'dbHandler'           => null, // Übergabe nicht mehr nötig

        ];

        // 3. Template rendern und Daten übergeben
        $this->template->render($viewData, 'dashboard'); // rendert views/dashboard.phtml
    }

    /**
     * Lädt und berechnet die Website-Statistiken.
     * (Angepasste Logik aus der globalen Funktion)
     */
    private function loadSiteStatisticsInternal(): array {
        $stats = [
            'total_visits'     => 0, 'visits_today'     => 0, 'visits_yesterday' => 0,
            'visits_this_week' => 0, 'visits_this_month'=> 0, 'top_pages'        => [],
            'top_referrers'    => [], 'browser_stats'    => [],
            'device_stats'     => ['Desktop' => 0, 'Mobile' => 0, 'Tablet' => 0],
            'hourly_stats'     => array_fill(0, 24, 0), 'daily_stats'      => []
        ];
        $statsDir = $this->pathRegistry->combine('logs', 'stats');

        if (!is_dir($statsDir)) {
             if (!@mkdir($statsDir, 0755, true) && !is_dir($statsDir)) {
                  $this->logger->log("warning", "Statistikverzeichnis konnte nicht erstellt/gefunden werden: $statsDir");
                  return $stats;
             }
        }

        $today        = date('Y-m-d');
        $yesterday    = date('Y-m-d', strtotime('-1 day'));
        $startOfWeek  = date('Y-m-d', strtotime('monday this week')); // Korrekter Wochenstart
        $startOfMonth = date('Y-m-d', strtotime('first day of this month'));

        // Helfer-Funktionen (als Closures oder private Methoden)
        $detectDevice = function($userAgent) { /* ... Logik wie vorher ... */ };
        $detectBrowser = function($userAgent) { /* ... Logik wie vorher ... */ };

        // Lese Logs der letzten 30 Tage
        for ($i = 0; $i < 30; $i++) {
            $date    = date('Y-m-d', strtotime("-$i days"));
            $logFile = $statsDir . '/' . $date . '.log';
            $stats['daily_stats'][$date] = 0; // Initialisieren für den Tag

            if (file_exists($logFile)) {
                $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines === false) {
                    $this->logger->log("warning", "Konnte Statistikdatei nicht lesen: $logFile");
                    continue;
                }

                $dailyCount = count($lines);
                $stats['total_visits'] += $dailyCount;
                $stats['daily_stats'][$date] = $dailyCount; // Update mit tatsächlicher Zahl

                if ($date === $today) $stats['visits_today'] = $dailyCount;
                elseif ($date === $yesterday) $stats['visits_yesterday'] = $dailyCount;
                if ($date >= $startOfWeek) $stats['visits_this_week'] += $dailyCount;
                if ($date >= $startOfMonth) $stats['visits_this_month'] += $dailyCount;

                foreach ($lines as $line) {
                    $data = @json_decode($line, true);
                    if (!$data) continue;

                    // Zeitliche Verteilung
                    if (isset($data['time'])) {
                         try {
                             $dt = new \DateTime($data['time']);
                             $hour = (int) $dt->format('G');
                             $stats['hourly_stats'][$hour]++;
                         } catch (\Exception $e) { /* Zeit ungültig */ }
                    }
                    // Top Pages
                    if (isset($data['url'])) {
                        $url = parse_url($data['url'], PHP_URL_PATH) ?: '/';
                        $stats['top_pages'][$url] = ($stats['top_pages'][$url] ?? 0) + 1;
                    }
                    // Top Referrer
                    if (!empty($data['referrer'])) {
                        $referrer = parse_url($data['referrer'], PHP_URL_HOST);
                        if ($referrer && strpos($data['referrer'], $this->helper->getBaseUrl()) === false) { // Eigene Seite ausschließen
                            $stats['top_referrers'][$referrer] = ($stats['top_referrers'][$referrer] ?? 0) + 1;
                        }
                    }
                    // Browser & Device
                    if (isset($data['user_agent'])) {
                        $browser = $detectBrowser($data['user_agent']);
                        $stats['browser_stats'][$browser] = ($stats['browser_stats'][$browser] ?? 0) + 1;
                        $device = $detectDevice($data['user_agent']);
                        $stats['device_stats'][$device] = ($stats['device_stats'][$device] ?? 0) + 1;
                    }
                } // end foreach $lines
            } // end if file_exists
        } // end for $i

        // Sortieren und limitieren der Top-Listen
        arsort($stats['top_pages']);
        $stats['top_pages'] = array_slice($stats['top_pages'], 0, 10, true);
        arsort($stats['top_referrers']);
        $stats['top_referrers'] = array_slice($stats['top_referrers'], 0, 10, true);
        arsort($stats['browser_stats']);
        arsort($stats['device_stats']);
        ksort($stats['daily_stats']); // Stelle sicher, dass Tage sortiert sind

        return $stats;
    }

     /**
      * Stellt die Liste der letzten Aktivitäten zusammen.
      */
     private function buildRecentActivityInternal(): array {
         $recentActivity = [];
         $limit = 10; // Max. Anzahl Aktivitäten

         try {
             // Letzte geänderte Seiten holen
             $pages = $this->pageManager->getAllPages();
             foreach ($pages as $pageInfo) {
                 if (isset($pageInfo['date_modified']) && !empty($pageInfo['date_modified'])) {
                     $recentActivity[] = [
                         'type'  => 'page',
                         'title' => $pageInfo['title'] ?? 'Unbenannte Seite',
                         'date'  => $pageInfo['date_modified'],
                         'url' => $this->adminRouter->getAdminUrl('admin.pages.edit', ['id' => $pageInfo['id'] ?? 0]),
                         'icon'  => 'file-alt', // Font Awesome Klasse
                         'message' => 'Seite bearbeitet' // Beispiel Nachricht
                     ];
                 }
             }

             // Letzte geänderte/erstellte Blog Posts holen
             // Annahme: getAllPosts unterstützt Sortierung nach date_modified oder date_created
             $posts = $this->blogManager->getAllPosts($limit, 0, 'date_modified'); // Oder 'date_created'
             foreach ($posts as $post) {
                 $date = $post['date_modified'] ?? $post['date_created'] ?? $post['date'] ?? null;
                 if ($date && isset($post['title'])) {
                     $recentActivity[] = [
                         'type'  => 'post', // Oder 'blog'
                         'title' => $post['title'],
                         'date'  => $date,
                         'url'   => $this->adminRouter->getAdminUrl('admin.blog.edit', ['id' => $post['id'] ?? 0]),
                         'icon'  => 'blog', // Font Awesome Klasse
                         'message' => 'Beitrag bearbeitet/erstellt' // Beispiel Nachricht
                     ];
                 }
             }

            // Hier könnten weitere Aktivitäten hinzugefügt werden (neue User, Kommentare, Löschungen etc.)
            // Beispiel: Neue User (falls User-Objekt das unterstützt)
            /*
            $newUsers = $this->userManager->getRecentUsers($limit);
            foreach($newUsers as $newUser) {
                 $recentActivity[] = [
                     'type' => 'user',
                     'title'=> $newUser['display_name'] ?? $newUser['username'],
                     'date' => $newUser['created_at'], // Annahme
                     'url'  => $this->helper->getAdminUrl('admin.users.edit', ['id' => $newUser['id']]),
                     'icon' => 'user-plus',
                     'message' => 'Neuer Benutzer registriert'
                 ];
            }
            */

         } catch (\Exception $e) {
             $this->logger->error("Fehler beim Erstellen der letzten Aktivitäten: " . $e->getMessage());
         }

         // Nach Datum sortieren (neueste zuerst)
         usort($recentActivity, function($a, $b) {
             // Stellt sicher, dass beide Daten gültige Zeitstempel sind
             $timeA = isset($a['date']) ? strtotime((string)$a['date']) : 0;
             $timeB = isset($b['date']) ? strtotime((string)$b['date']) : 0;
             return $timeB <=> $timeA; // Neueste zuerst
         });

         // Auf Limit kürzen
         return array_slice($recentActivity, 0, $limit);
     }

    /**
     * Bereitet Daten für das Besucher-Chart vor.
     */
    private function prepareChartData(array $siteStats): array {
        $days = 14; // Zeige 14 Tage im Chart an
        $dailyData = [];
        $labels = [];
        if (!empty($siteStats['daily_stats'])) {
            $dailyStats = $siteStats['daily_stats'];
            ksort($dailyStats);
            $dailyStats = array_slice($dailyStats, -$days, $days, true); // Letzte $days Tage
            foreach ($dailyStats as $date => $count) {
                $labels[]   = date('d.m', strtotime($date));
                $dailyData[] = $count;
            }
        }
        // Stelle sicher, dass immer $days Labels/Datenpunkte vorhanden sind, auch wenn 0
        $missingDays = $days - count($labels);
        if ($missingDays > 0 && count($labels) > 0) {
            $firstDate = strtotime(str_replace('.', '-', $labels[0]) . '.' . date('Y')); // Errate Jahr
            for ($i = 1; $i <= $missingDays; $i++) {
                array_unshift($labels, date('d.m', strtotime("-$i day", $firstDate)));
                array_unshift($dailyData, 0);
            }
        } elseif (empty($labels)) { // Wenn gar keine Daten da sind
             for ($i = $days - 1; $i >= 0; $i--) {
                 $labels[] = date('d.m', strtotime("-$i day"));
                 $dailyData[] = 0;
             }
        }

        return [$labels, $dailyData];
    }
}