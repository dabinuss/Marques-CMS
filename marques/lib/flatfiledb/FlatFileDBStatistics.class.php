<?php
declare(strict_types=1);

namespace FlatFileDB;

class FlatFileDBStatistics
{
    private FlatFileDatabase $database;

    /**
     * @var array Static property to store performance metrics.
     */
    private static array $performanceMetrics = [];
    

    public function __construct(FlatFileDatabase $database)
    {
        $this->database = $database;
    }

    /**
     * Ermittelt Statistikdaten für eine einzelne Tabelle.
     *
     * @param string $tableName Name der Tabelle
     * @return array Statistikdaten (Anzahl Datensätze, Dateigrößen)
     * @throws \RuntimeException wenn die Tabelle nicht registriert ist.
     */
    public function getTableStatistics(string $tableName): array
    {
        if (!$this->database->hasTable($tableName)) {
            throw new \RuntimeException("Tabelle '$tableName' wurde nicht registriert.");
        }

        $tableEngine = $this->database->table($tableName);
        $config = $tableEngine->getConfig();
        
        // Anzahl der Datensätze über den Index ermitteln
        $recordCount = $tableEngine->getRecordCount();
        
        // Dateigrößen abrufen (unter der Annahme, dass die Dateien existieren)
        $dataFileSize  = file_exists($config->getDataFile()) ? filesize($config->getDataFile()) : 0;
        $indexFileSize = file_exists($config->getIndexFile()) ? filesize($config->getIndexFile()) : 0;
        $logFileSize   = file_exists($config->getLogFile()) ? filesize($config->getLogFile()) : 0;

        return [
            'record_count'   => $recordCount,
            'data_file_size' => $dataFileSize,
            'index_file_size'=> $indexFileSize,
            'log_file_size'  => $logFileSize,
        ];
    }

    /**
     * Ermittelt Statistikdaten für alle registrierten Tabellen.
     *
     * @return array Array mit Statistikdaten pro Tabelle.
     */
    public function getOverallStatistics(): array
    {
        $stats = [];
        foreach ($this->database->getTableNames() as $tableName) {
            $stats[$tableName] = $this->getTableStatistics($tableName);
        }
        return $stats;
    }

    /**
     * Führt eine übergebene Operation aus und misst dabei die Ausführungszeit.
     *
     * @param callable $operation Eine Funktion, deren Ausführung gemessen werden soll.
     * @return array Array mit dem Ergebnis der Operation und der benötigten Zeit (in Sekunden).
     */
    public static function measurePerformance(callable $operation): array
    {
        $start = microtime(true);
        $result = $operation();
        $end = microtime(true);
        $duration = $end - $start;
        return [
            'result'   => $result,
            'duration' => $duration,
        ];
    }

    /**
     * NEU: Speichert die Dauer einer Aktion.
     *
     * @param string $action Der Aktionsname (z.B. 'INSERT', 'UPDATE', etc.)
     * @param float $duration Dauer in Sekunden
     */
    public static function recordPerformance(string $action, float $duration): void
    {
        if (!isset(self::$performanceMetrics[$action])) {
            self::$performanceMetrics[$action] = [];
        }
        self::$performanceMetrics[$action][] = $duration;
    }

    /**
     * NEU: Gibt die gesammelten Performance-Metriken zurück.
     *
     * @return array Array mit den Metriken pro Aktion
     */
    public static function getPerformanceMetrics(): array
    {
        return self::$performanceMetrics;
    }

    /**
     * NEU: Setzt die Performance-Metriken zurück.
     */
    public static function resetPerformanceMetrics(): void
    {
        self::$performanceMetrics = [];
    }
}
