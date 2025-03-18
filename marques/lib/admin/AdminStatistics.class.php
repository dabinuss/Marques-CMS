<?php
declare(strict_types=1);

namespace Marques\Admin;

use Marques\Core\AppStatistics;

/**
 * Class AdminStatistics
 *
 * Erweitert die AppStatistics um Verwaltungsfunktionen:
 * - Aktualisieren von Statistik-Einstellungen
 * - Löschen von Statistik-Daten
 * - Bereitstellung eines administrativen Überblicks
 * - Sammlung von System- und Serverinformationen
 *
 * @package Marques\Admin
 */
class AdminStatistics extends AppStatistics {
    /**
     * Systeminformationen zur Darstellung im Admin-Bereich
     */
    protected array $systemInfo = [];

    /**
     * Konstruktor.
     * Ruft den Konstruktor der Elternklasse auf und initialisiert Systeminformationen.
     */
    public function __construct() {
        parent::__construct();
        $this->collectSystemInfo();
    }

    /**
     * Sammelt wichtige Systeminformationen für den Admin-Bereich
     */
    protected function collectSystemInfo(): void {
        // PHP-Informationen
        $this->systemInfo['php_version'] = PHP_VERSION;
        $this->systemInfo['php_sapi'] = PHP_SAPI;
        $this->systemInfo['memory_limit'] = ini_get('memory_limit');
        $this->systemInfo['max_execution_time'] = ini_get('max_execution_time');
        $this->systemInfo['post_max_size'] = ini_get('post_max_size');
        $this->systemInfo['upload_max_filesize'] = ini_get('upload_max_filesize');
        
        // Erweiterungen und Module
        $this->systemInfo['loaded_extensions'] = implode(', ', get_loaded_extensions());
        $this->systemInfo['disabled_functions'] = ini_get('disable_functions') ?: 'Keine';
        
        // Server-Informationen
        $this->systemInfo['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unbekannt';
        $this->systemInfo['server_os'] = PHP_OS_FAMILY;
        $this->systemInfo['server_protocol'] = $_SERVER['SERVER_PROTOCOL'] ?? 'Unbekannt';
        
        // Zeiteinstellungen
        $this->systemInfo['timezone'] = date_default_timezone_get();
        $this->systemInfo['server_time'] = date('Y-m-d H:i:s');
        
        // Performance-Indikatoren
        $this->systemInfo['memory_peak_usage'] = $this->formatBytes(memory_get_peak_usage(true));
        $this->systemInfo['opcache_enabled'] = function_exists('opcache_get_status') ? 'Ja' : 'Nein';
        
        // Datenbank-Informationen, falls verfügbar
        if (extension_loaded('mysqli') || extension_loaded('pdo_mysql')) {
            $this->systemInfo['mysql_support'] = 'Verfügbar';
        } else {
            $this->systemInfo['mysql_support'] = 'Nicht verfügbar';
        }
        
        // GD-Bibliothek für Bildverarbeitung
        if (extension_loaded('gd')) {
            $gdInfo = gd_info();
            $this->systemInfo['gd_version'] = $gdInfo['GD Version'] ?? 'Verfügbar';
        } else {
            $this->systemInfo['gd_version'] = 'Nicht verfügbar';
        }
    }
    
    /**
     * Formatiert Bytes in lesbare Größen (KB, MB, GB)
     * 
     * @param int $bytes Anzahl der Bytes
     * @param int $precision Nachkommastellen
     * @return string Formatierte Größenangabe
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
        // Beispielhafte Implementierung:
        // Hier könnte man Einstellungen in einer Datenbank oder Konfigurationsdatei speichern.
        // Für die Demonstration werden die neuen Einstellungen in die Statistik-Daten integriert.
        $this->stats = array_merge($this->stats, $newSettings);
        return true;
    }

    /**
     * Löscht alle gesammelten Statistik-Daten.
     *
     * @return bool True, wenn das Löschen erfolgreich war.
     */
    public function deleteStatistics(): bool {
        // Beispielhafte Löschlogik:
        $this->stats = [];
        return true;
    }

    /**
     * Gibt eine zusammenfassende Übersicht der Statistik-Daten zurück.
     *
     * @return string Formatierte Zusammenfassung
     */
    public function getAdminSummary(): string {
        $summary = "Admin Summary:\n";
        foreach ($this->stats as $key => $value) {
            $summary .= ucfirst($key) . ": " . $value . "\n";
        }
        return $summary;
    }
    
    /**
     * Gibt alle gesammelten Systeminformationen zurück.
     * 
     * @return array Alle System- und Serverinformationen
     */
    public function getSystemInfo(): array {
        return $this->systemInfo;
    }
    
    /**
     * Prüft potenzielle Probleme und gibt Warnungen zurück.
     * 
     * @return array Liste von Warnungen und Empfehlungen
     */
    public function getSystemWarnings(): array {
        $warnings = [];
        
        // Speicherlimit prüfen
        $memoryLimit = $this->convertToBytes($this->systemInfo['memory_limit']);
        if ($memoryLimit < 64 * 1024 * 1024) { // Weniger als 64MB
            $warnings[] = "Niedriges Speicherlimit ({$this->systemInfo['memory_limit']}). Minimum 64MB empfohlen.";
        }
        
        // Max. Ausführungszeit prüfen
        if ((int)$this->systemInfo['max_execution_time'] < 30) {
            $warnings[] = "Kurze maximale Ausführungszeit ({$this->systemInfo['max_execution_time']}s). Für Admin-Operationen mindestens 30s empfohlen.";
        }
        
        // Upload-Größe prüfen
        $uploadMax = $this->convertToBytes($this->systemInfo['upload_max_filesize']);
        if ($uploadMax < 8 * 1024 * 1024) { // Weniger als 8MB
            $warnings[] = "Kleine maximale Upload-Größe ({$this->systemInfo['upload_max_filesize']}). Minimum 8MB empfohlen.";
        }
        
        // PHP-Version prüfen
        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            $warnings[] = "Veraltete PHP-Version. PHP 7.4 oder höher wird empfohlen.";
        }
        
        // OPCache prüfen
        if ($this->systemInfo['opcache_enabled'] === 'Nein') {
            $warnings[] = "OPCache ist nicht aktiviert. Aktivieren Sie OPCache für bessere Performance.";
        }
        
        return $warnings;
    }
    
    /**
     * Konvertiert Größenangaben wie "128M" in Bytes
     * 
     * @param string $size Größenangabe (z.B. "128M", "1G")
     * @return int Anzahl der Bytes
     */
    protected function convertToBytes(string $size): int {
        $unit = strtoupper(substr($size, -1));
        $value = (int)$size;
        
        switch ($unit) {
            case 'G':
                $value *= 1024;
                // Durchlauf gewollt
            case 'M':
                $value *= 1024;
                // Durchlauf gewollt
            case 'K':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Generiert HTML für die Anzeige der Systeminformationen
     * 
     * @return string HTML zur Anzeige im Admin-Bereich
     */
    public function renderSystemInfoHTML(): string {
        $warnings = $this->getSystemWarnings();
        $html = '';
        
        // Warnungen anzeigen, falls vorhanden
        if (!empty($warnings)) {
            $html .= '<div class="system-warnings">';
            $html .= '<h3>System-Warnungen</h3>';
            $html .= '<ul>';
            foreach ($warnings as $warning) {
                $html .= '<li>' . htmlspecialchars($warning) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }
        
        // Systeminformationen in Gruppen anzeigen
        $html .= '<div class="system-info">';
        $html .= '<h3>PHP-Informationen</h3>';
        $html .= '<table class="info-table">';
        $html .= $this->renderTableRow('PHP-Version', $this->systemInfo['php_version']);
        $html .= $this->renderTableRow('PHP SAPI', $this->systemInfo['php_sapi']);
        $html .= $this->renderTableRow('Speicherlimit', $this->systemInfo['memory_limit']);
        $html .= $this->renderTableRow('Max. Ausführungszeit', $this->systemInfo['max_execution_time'] . ' Sekunden');
        $html .= $this->renderTableRow('Max. POST-Größe', $this->systemInfo['post_max_size']);
        $html .= $this->renderTableRow('Max. Upload-Größe', $this->systemInfo['upload_max_filesize']);
        $html .= '</table>';
        
        $html .= '<h3>Server-Informationen</h3>';
        $html .= '<table class="info-table">';
        $html .= $this->renderTableRow('Server-Software', $this->systemInfo['server_software']);
        $html .= $this->renderTableRow('Betriebssystem', $this->systemInfo['server_os']);
        $html .= $this->renderTableRow('Protokoll', $this->systemInfo['server_protocol']);
        $html .= $this->renderTableRow('Zeitzone', $this->systemInfo['timezone']);
        $html .= $this->renderTableRow('Serverzeit', $this->systemInfo['server_time']);
        $html .= '</table>';
        
        $html .= '<h3>Module und Performance</h3>';
        $html .= '<table class="info-table">';
        $html .= $this->renderTableRow('MySQL-Unterstützung', $this->systemInfo['mysql_support']);
        $html .= $this->renderTableRow('GD-Bibliothek', $this->systemInfo['gd_version']);
        $html .= $this->renderTableRow('OPCache aktiviert', $this->systemInfo['opcache_enabled']);
        $html .= $this->renderTableRow('Spitzennutzung Speicher', $this->systemInfo['memory_peak_usage']);
        $html .= '</table>';
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Hilfsmethode zum Erstellen einer Tabellenzeile
     * 
     * @param string $label Beschriftung
     * @param string $value Wert
     * @return string HTML für die Tabellenzeile
     */
    protected function renderTableRow(string $label, string $value): string {
        return '<tr><th>' . htmlspecialchars($label) . ':</th><td>' . 
               htmlspecialchars($value) . '</td></tr>';
    }
}