<?php
declare(strict_types=1);

namespace Admin\Core;

use Marques\Core\Statistics as AppStatistics;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Service\User;
use Marques\Service\PageManager;
use Marques\Service\BlogManager;
use Marques\Data\MediaManager;

/**
 * Class Statistics
 *
 * @package Admin
 */
class Statistics extends AppStatistics {
    protected array $systemInfo = [];
    
    protected array $thresholds = [
        'memory_limit' => [
            'min' => 64 * 1024 * 1024,          // Mindestens 64 MB
            'recommended' => 128 * 1024 * 1024,   // Empfohlen 128 MB
        ],
        'max_execution_time' => [
            'min' => 30,                        // Mindestens 30 Sekunden
            'recommended' => 60,                // Empfohlen 60 Sekunden
        ],
        'upload_max_filesize' => [
            'min' => 8 * 1024 * 1024,           // Mindestens 8 MB
            'recommended' => 16 * 1024 * 1024,  // Empfohlen 16 MB
        ],
        'php_version' => [
            'min' => '7.4.0',                   // Mindestens PHP 7.4
            'recommended' => '8.0.0',           // Empfohlen PHP 8.0+
        ],
    ];

    // DI-Abhängigkeiten:
    private DatabaseHandler $dbHandler;
    private User $user;
    private PageManager $pageManager;
    private BlogManager $blogManager;
    private MediaManager $mediaManager;

    protected array $stats = [];
    protected array $warnings = [];

    public function __construct(
        DatabaseHandler $dbHandler,
        User $user,
        PageManager $pageManager,
        BlogManager $blogManager,
        MediaManager $mediaManager,
        array $customThresholds = []
    ) {
        $this->dbHandler = $dbHandler;
        parent::__construct(); // Eltern-Konstruktor ohne Parameter aufrufen
        $this->user = $user;
        $this->pageManager = $pageManager;
        $this->blogManager = $blogManager;
        $this->mediaManager = $mediaManager;
        if (!empty($customThresholds)) {
            $this->updateThresholds($customThresholds);
        }
        $this->collectSystemInfo();
        $this->collectAllStatistics();
        $this->checkSystemWarnings();
    }
    
    /**
     * Aktualisiert die Schwellenwerte für Bewertungen.
     * 
     * @param array $customThresholds Neue Schwellenwerte
     * @return bool Erfolg oder Misserfolg
     */
    public function updateThresholds(array $customThresholds): bool {
        foreach ($customThresholds as $key => $values) {
            if (isset($this->thresholds[$key])) {
                $this->thresholds[$key] = array_merge($this->thresholds[$key], $values);
            } else {
                $this->thresholds[$key] = $values;
            }
        }
        
        $this->collectSystemInfo();
        $this->checkSystemWarnings();
        
        return true;
    }

    /**
     * Sammelt wichtige Systeminformationen für den Admin-Bereich.
     */
    protected function collectSystemInfo(): void {
        // PHP-Informationen
        $this->addSystemInfo('php_version', PHP_VERSION, $this->rateValue('php_version', PHP_VERSION));
        $this->addSystemInfo('php_sapi', PHP_SAPI);
        $this->addSystemInfo('memory_limit', ini_get('memory_limit'), 
                            $this->rateValue('memory_limit', ini_get('memory_limit')));
        $this->addSystemInfo('max_execution_time', ini_get('max_execution_time'), 
                            $this->rateValue('max_execution_time', ini_get('max_execution_time')));
        $this->addSystemInfo('post_max_size', ini_get('post_max_size'));
        $this->addSystemInfo('upload_max_filesize', ini_get('upload_max_filesize'), 
                            $this->rateValue('upload_max_filesize', ini_get('upload_max_filesize')));
        
        // Erweiterungen und Module
        $extensions = get_loaded_extensions();
        if (!is_array($extensions)) {
            $extensions = []; // Fallback, falls kein Array zurückgegeben wird.
        }
        $this->addSystemInfo('loaded_extensions', safe_implode(', ', $extensions));
        $this->addSystemInfo('disabled_functions', ini_get('disable_functions') ?: 'Keine');
        
        // Server-Informationen
        $this->addSystemInfo('server_software', $_SERVER['SERVER_SOFTWARE'] ?? 'Unbekannt');
        $this->addSystemInfo('server_os', PHP_OS_FAMILY);
        $this->addSystemInfo('server_protocol', $_SERVER['SERVER_PROTOCOL'] ?? 'Unbekannt');
        
        // Zeiteinstellungen
        $this->addSystemInfo('timezone', date_default_timezone_get());
        $this->addSystemInfo('server_time', date('Y-m-d H:i:s'));
        
        // Performance-Indikatoren
        $this->addSystemInfo('memory_peak_usage', $this->formatBytes(memory_get_peak_usage(true)));
        
        $opcacheEnabled = function_exists('opcache_get_status') ? 'Ja' : 'Nein';
        $this->addSystemInfo('opcache_enabled', $opcacheEnabled, 
                           ($opcacheEnabled === 'Nein') ? 'nicht optimal' : 'gut');
        
        // Datenbank-Informationen
        $this->addSystemInfo('mysql_support', 
                           (extension_loaded('mysqli') || extension_loaded('pdo_mysql')) ? 'Verfügbar' : 'Nicht verfügbar');
        
        // GD-Bibliothek für Bildverarbeitung
        $this->addSystemInfo('gd_version', 
                           extension_loaded('gd') ? (gd_info()['GD Version'] ?? 'Verfügbar') : 'Nicht verfügbar');
    }

    /**
     * Hilfsmethode zum Hinzufügen von Systeminformationen
     * 
     * @param string $key Schlüssel der Information
     * @param mixed $value Wert der Information
     * @param string $rating Optionale Bewertung
     */
    protected function addSystemInfo(string $key, $value, string $rating = ''): void {
        $this->systemInfo[$key] = [
            'value' => $value,
            'rating' => $rating
        ];
    }

    /**
     * Generische Methode zur Bewertung von Werten
     * 
     * @param string $type Typ des zu bewertenden Werts
     * @param mixed $value Wert zur Bewertung
     * @return string Bewertung
     */
    protected function rateValue(string $type, $value): string {
        if (!isset($this->thresholds[$type])) {
            return '';
        }
        
        switch ($type) {
            case 'memory_limit':
            case 'upload_max_filesize':
                $bytes = $this->convertToBytes((string)$value);
                if ($bytes < $this->thresholds[$type]['min']) {
                    return 'nicht optimal';
                } elseif ($bytes < $this->thresholds[$type]['recommended']) {
                    return 'so lala';
                }
                return 'gut';
                
            case 'max_execution_time':
                $seconds = (int)$value;
                if ($seconds < $this->thresholds[$type]['min']) {
                    return 'nicht optimal';
                } elseif ($seconds < $this->thresholds[$type]['recommended']) {
                    return 'so lala';
                }
                return 'gut';
                
            case 'php_version':
                if (version_compare($value, $this->thresholds[$type]['min'], '<')) {
                    return 'nicht optimal';
                } elseif (version_compare($value, $this->thresholds[$type]['recommended'], '<')) {
                    return 'so lala';
                }
                return 'gut';
                
            default:
                return '';
        }
    }

    /**
     * Formatiert Bytes in lesbare Größen (KB, MB, GB).
     * 
     * @param int $bytes Anzahl der Bytes.
     * @param int $precision Nachkommastellen.
     * @return string Formatierte Größenangabe.
     */
    protected function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Aktualisiert Einstellungen und/oder Statistik-Daten.
     *
     * @param array $newSettings Neue Einstellungen, die übernommen werden sollen.
     * @return bool True, wenn die Aktualisierung erfolgreich war.
     */
    public function updateSettings(array $newSettings): bool {
        $this->stats = array_merge($this->stats, $newSettings);
        return true;
    }

    /**
     * Löscht alle gesammelten Statistik-Daten.
     *
     * @return bool True, wenn das Löschen erfolgreich war.
     */
    public function deleteStatistics(): bool {
        $this->stats = [];
        return true;
    }

    /**
     * Sammelt zusätzliche Statistik-Daten aus verschiedenen Bereichen.
     *
     * @return array Zusätzliche Statistiken.
     */
    protected function collectAdditionalStatistics(): array {
        $additionalStats = [];

        // Benutzerstatistik
        $additionalStats['total_users'] = count($this->user->getAllUsers());

        // Seitenstatistik
        $additionalStats['total_pages'] = count($this->pageManager->getAllPages());

        // Blog-Posts Statistik
        $additionalStats['total_blog_posts'] = count($this->blogManager->getAllPosts());

        // Mediendateien Statistik
        $additionalStats['total_media'] = count($this->mediaManager->getAllMedia());

        // Server Load Average (falls verfügbar)
        $load = sys_getloadavg();
        $additionalStats['server_load'] = is_array($load) ? safe_implode(', ', $load) : 'n/a';

        return $additionalStats;
    }

    /**
     * Vereinigt Basisstatistiken und zusätzliche Statistiken.
     */
    protected function collectAllStatistics(): void {
        $this->stats = array_merge($this->stats, $this->collectAdditionalStatistics());
    }

    /**
     * Gibt eine zusammenfassende Übersicht der Statistik-Daten zurück.
     *
     * @return string Formatierte Zusammenfassung.
     */
    public function getAdminSummary(): string {
        $summary = "Admin Summary:\n";
        foreach ($this->stats as $key => $value) {
            if (is_array($value)) {
                $summary .= ucfirst(str_replace('_', ' ', $key)) . ":\n";
                foreach ($value as $subKey => $subValue) {
                    $summary .= "  " . ucfirst(str_replace('_', ' ', $subKey)) . ": " . $subValue . "\n";
                }
            } else {
                $summary .= ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
            }
        }
        return $summary;
    }
    
    /**
     * Gibt alle gesammelten Systeminformationen zurück.
     * 
     * @return array Alle System- und Serverinformationen.
     */
    public function getSystemInfo(): array {
        return $this->systemInfo;
    }
    
    /**
     * Prüft potenzielle Probleme und speichert Warnungen.
     */
    protected function checkSystemWarnings(): void {
        $this->warnings = [];
        
        $this->checkThresholdWarning(
            'memory_limit', 
            $this->systemInfo['memory_limit']['value'], 
            "Niedriges Speicherlimit ({$this->systemInfo['memory_limit']['value']}). Minimum {$this->formatBytes($this->thresholds['memory_limit']['min'])} empfohlen."
        );
        
        $this->checkThresholdWarning(
            'max_execution_time', 
            $this->systemInfo['max_execution_time']['value'], 
            "Kurze maximale Ausführungszeit ({$this->systemInfo['max_execution_time']['value']}s). Für Admin-Operationen mindestens {$this->thresholds['max_execution_time']['min']}s empfohlen."
        );
        
        $this->checkThresholdWarning(
            'upload_max_filesize', 
            $this->systemInfo['upload_max_filesize']['value'], 
            "Kleine maximale Upload-Größe ({$this->systemInfo['upload_max_filesize']['value']}). Minimum {$this->formatBytes($this->thresholds['upload_max_filesize']['min'])} empfohlen."
        );
        
        $this->checkThresholdWarning(
            'php_version', 
            PHP_VERSION, 
            "Veraltete PHP-Version. PHP {$this->thresholds['php_version']['min']} oder höher wird empfohlen."
        );
        
        if ($this->systemInfo['opcache_enabled']['value'] === 'Nein') {
            $this->warnings[] = "OPCache ist nicht aktiviert. Aktivieren Sie OPCache für bessere Performance.";
        }
    }
    
    /**
     * Prüft, ob ein Wert unter dem Schwellenwert liegt und fügt ggf. eine Warnung hinzu
     * 
     * @param string $type Typ des zu prüfenden Werts
     * @param mixed $value Zu prüfender Wert
     * @param string $warningMessage Warnmeldung, die hinzugefügt werden soll
     */
    protected function checkThresholdWarning(string $type, $value, string $warningMessage): void {
        if (!isset($this->thresholds[$type])) {
            return;
        }
        
        $isBelow = false;
        
        switch ($type) {
            case 'memory_limit':
            case 'upload_max_filesize':
                $isBelow = $this->convertToBytes((string)$value) < $this->thresholds[$type]['min'];
                break;
                
            case 'max_execution_time':
                $isBelow = (int)$value < $this->thresholds[$type]['min'];
                break;
                
            case 'php_version':
                $isBelow = version_compare($value, $this->thresholds[$type]['min'], '<');
                break;
        }
        
        if ($isBelow) {
            $this->warnings[] = $warningMessage;
        }
    }
    
    /**
     * Gibt alle gesammelten Warnungen zurück
     * 
     * @return array Liste von Warnungen und Empfehlungen.
     */
    public function getSystemWarnings(): array {
        return $this->warnings;
    }
    
    /**
     * Konvertiert Größenangaben wie "128M" in Bytes.
     * 
     * @param string $size Größenangabe (z.B. "128M", "1G").
     * @return int Anzahl der Bytes.
     */
    protected function convertToBytes(string $size): int {
        $unit = strtoupper(substr($size, -1));
        $value = (int)$size;
        
        if (!is_numeric($unit)) {
            switch ($unit) {
                case 'G':
                    $value *= 1024;
                case 'M':
                    $value *= 1024;
                case 'K':
                    $value *= 1024;
            }
        }
        
        return $value;
    }
    
    /**
     * Gibt alle Systeminformationen gruppiert zurück.
     * 
     * @return array Systeminformationen in Gruppen organisiert.
     */
    public function getSystemInfoArray(): array {
        $data = [];
        
        // PHP-Informationen
        $data['PHP-Informationen'] = [
            'PHP-Version'         => $this->systemInfo['php_version'],
            'PHP SAPI'            => $this->systemInfo['php_sapi'],
            'Speicherlimit'       => $this->systemInfo['memory_limit'],
            'Max. Ausführungszeit'=> ['value' => $this->systemInfo['max_execution_time']['value'] . ' Sekunden', 'rating' => $this->systemInfo['max_execution_time']['rating']],
            'Max. POST-Größe'     => $this->systemInfo['post_max_size'],
            'Max. Upload-Größe'   => $this->systemInfo['upload_max_filesize'],
        ];
    
        // Server-Informationen
        $data['Server-Informationen'] = [
            'Server-Software' => $this->systemInfo['server_software'],
            'Betriebssystem'  => $this->systemInfo['server_os'],
            'Protokoll'       => $this->systemInfo['server_protocol'],
            'Zeitzone'        => $this->systemInfo['timezone'],
            'Serverzeit'      => $this->systemInfo['server_time'],
        ];
    
        // Module und Performance
        $data['Module und Performance'] = [
            'MySQL-Unterstützung' => $this->systemInfo['mysql_support'],
            'GD-Bibliothek'       => $this->systemInfo['gd_version'],
            'OPCache aktiviert'   => $this->systemInfo['opcache_enabled'],
            'Spitzennutzung Speicher' => $this->systemInfo['memory_peak_usage'],
        ];
    
        return $data;
    }
    
    /**
     * Gibt die aktuellen Schwellenwerte zurück
     * 
     * @return array Aktuell konfigurierte Schwellenwerte
     */
    public function getThresholds(): array {
        return $this->thresholds;
    }
}