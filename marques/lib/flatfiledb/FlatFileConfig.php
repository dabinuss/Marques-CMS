<?php
declare(strict_types=1);

namespace FlatFileDB;

/**
 * Stellt die Konfiguration der Dateien für eine Tabelle bereit.
 */
class FlatFileConfig
{
    private string $dataFile;
    private string $indexFile;
    private string $logFile;

    
    /**
     * @param string $dataFile Pfad zur Datendatei
     * @param string $indexFile Pfad zur Indexdatei
     * @param string $logFile Pfad zur Logdatei (wird weiterhin für Transaktionslogs genutzt)
     */
    public function __construct(
        string $dataFile,
        string $indexFile,
        string $logFile,
    ) {
        $this->dataFile = $dataFile;
        $this->indexFile = $indexFile;
        $this->logFile = $logFile;
    }

    public function getDataFile(): string { return $this->dataFile; }
    public function getIndexFile(): string { return $this->indexFile; }
    public function getLogFile(): string { return $this->logFile; }
}