<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Engine für eine einzelne Tabelle: Insert, Update, Delete, Select, Kompaktierung.
 */
class FlatFileTableEngine
{
    private FlatFileConfig $config;
    private FlatFileFileManager $fileManager;
    private FlatFileIndexBuilder $indexBuilder;
    private FlatFileTransactionLog $transactionLog;
    
    /** @var array<string, array> Einfacher Datensatz-Cache */
    private array $dataCache = [];
    
    /** @var int Maximale Anzahl an Datensätzen im Cache */
    private int $maxCacheSize = 100;
    
    /** @var array<string, array> Schema-Definition für diese Tabelle */
    private array $schema = [];
    
    /**
     * @param FlatFileConfig $config Konfiguration der Tabelle
     */
    public function __construct(FlatFileConfig $config)
    {
        $this->config = $config;
        $this->fileManager = new FlatFileFileManager($config);
        $this->indexBuilder = new FlatFileIndexBuilder($config);
        $this->transactionLog = new FlatFileTransactionLog($config);
    }
    
    /**
     * Gibt die Konfiguration zurück.
     * 
     * @return FlatFileConfig Konfiguration
     */
    public function getConfig(): FlatFileConfig
    {
        return $this->config;
    }
    
    /**
     * Setzt ein Schema für die Tabelle (Validierung).
     * 
     * @param array $requiredFields Liste der Pflichtfelder
     * @param array $fieldTypes Assoziatives Array mit Feldname => Erwarteter Typ
     */
    public function setSchema(array $requiredFields = [], array $fieldTypes = []): void
    {
        $this->schema = [
            'requiredFields' => $requiredFields,
            'fieldTypes'     => $fieldTypes
        ];
    }
    
    /**
     * Fügt einen neuen Datensatz ein.
     * 
     * @param string $recordId ID des Datensatzes
     * @param array $data Datensatzfelder
     * @return bool True bei Erfolg, false wenn ID bereits existiert
     * @throws InvalidArgumentException bei ungültiger ID oder Daten
     * @throws RuntimeException bei Schreibfehlern
     */
    public function insertRecord(array $data): int
    {
        $recordId = $this->indexBuilder->getNextId(); // ID vom IndexBuilder holen
        $data['id'] = $recordId; // Integer-ID verwenden
        $data['created_at'] = time();
        $data['_deleted'] = false;

        if (!empty($this->schema)) { // Schema-Validierung (unverändert)
            FlatFileValidator::validateData($data, $this->schema['requiredFields'] ?? [], $this->schema['fieldTypes'] ?? []);
        }

        $offset = $this->fileManager->appendRecord($data);
        $this->indexBuilder->setIndex($recordId, $offset); // Integer-ID
        $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_INSERT, (string)$recordId, $data); // Log als String
        $this->addToCache((string)$recordId, $data); // Cache-Key als String
        return $recordId; // Neue ID zurückgeben
    }
    
    /**
     * Aktualisiert einen bestehenden Datensatz.
     * 
     * @param string $recordId ID des Datensatzes
     * @param array $newData Neue Datensatzfelder
     * @return bool True bei Erfolg, false wenn Datensatz nicht existiert
     * @throws RuntimeException bei Schreibfehlern
     */
    public function updateRecord(int $recordId, array $newData): bool
    {
        $oldOffset = $this->indexBuilder->getIndexOffset($recordId); // Integer-ID
        if ($oldOffset === null) { return false; }
    
        // Lese den aktuellen Datensatz
        $oldData = $this->fileManager->readRecordAtOffset($oldOffset);
        if (!$oldData || !is_array($oldData) || !isset($oldData['id'])) {
            return false;
        }
    
        // Felder, die automatisch verwaltet werden, werden beim Vergleich ignoriert.
        $fieldsToIgnore = ['updated_at', 'created_at', '_deleted', 'deleted_at'];
        $filteredOldData = array_diff_key($oldData, array_flip($fieldsToIgnore));
        $filteredNewData = array_diff_key($newData, array_flip($fieldsToIgnore));
    
        // Wenn es keine Änderungen gibt, gilt das Update als erfolgreich.
        if ($filteredOldData == $filteredNewData) {
            return true;
        }
    
        try {
            // 1. Markiere den alten Datensatz als gelöscht.
            $oldData['_deleted'] = true;
            $oldData['deleted_at'] = time();
            $this->fileManager->appendRecord($oldData);
    
            // 2. Erstelle den neuen Datensatz.
            $newData['id'] = $recordId;
            $newData['created_at'] = $oldData['created_at'] ?? time();
            $newData['updated_at'] = time();
            $newOffset = $this->fileManager->appendRecord($newData);
            if ($newOffset === false) {
                throw new RuntimeException("Failed to append new data for record $recordId");
            }
    
            $this->indexBuilder->setIndex($recordId, $newOffset);  // Integer ID
            $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_UPDATE, (string)$recordId, $newData); // Log als String
            $this->addToCache((string)$recordId, $newData); // Cache-Key als String
    
            return true;
        } catch (Throwable $e) {
            // Bei einem Fehler: versuche den alten Zustand wiederherzustellen.
            if ($oldOffset !== null && is_array($oldData)) {
                $this->indexBuilder->setIndex($recordId, $oldOffset);
                $oldData['_deleted'] = false;
                unset($oldData['deleted_at']);
                $this->fileManager->appendRecord($oldData);
            }
            throw new RuntimeException("Fehler beim Aktualisieren des Datensatzes $recordId: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Löscht einen Datensatz.
     * 
     * @param string $recordId ID des Datensatzes
     * @return bool True bei Erfolg, false wenn Datensatz nicht existiert
     * @throws RuntimeException bei Schreibfehlern
     */
    public function deleteRecord(int $recordId): bool
    {
        $oldOffset = $this->indexBuilder->getIndexOffset($recordId); // Integer-ID
        if ($oldOffset === null) {  return false; }

        $oldData = null;
        try {
            $oldData = $this->fileManager->readRecordAtOffset($oldOffset);
            if (!$oldData || ($oldData['_deleted'] === true)) {
                return false;
            }

            // 1. Append the deletion marker *FIRST*.
            $oldData['_deleted'] = true;
            $oldData['deleted_at'] = time();
            $deleteOffset = $this->fileManager->appendRecord($oldData);
            if ($deleteOffset === false) { // Check for append failure
                throw new RuntimeException("Failed to append deletion marker for record $recordId");
            }

            $this->indexBuilder->removeIndex($recordId); // Integer-ID
            $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_DELETE, (string)$recordId); // Log als String
            unset($this->dataCache[(string)$recordId]); // Cache-Key als String

            return true;

        } catch (Throwable $e) {
            if ($deleteOffset ?? false) { // Wurde der Löschmarker geschrieben?
                //Wenn ja, Index *nicht* wiederherstellen
                error_log("Deletion failed for record $recordId AFTER appending deletion marker. Index remains removed.");
            } elseif ($oldOffset !== null && is_array($oldData)) {
                //Wenn nein, Index wiederherstellen und den alten Datensatz als nicht gelöscht markieren.
                $this->indexBuilder->setIndex($recordId, $oldOffset);
                $oldData['_deleted'] = false;
                unset($oldData['deleted_at']);
                $this->fileManager->appendRecord($oldData);
            }
            throw new RuntimeException("Fehler beim Löschen des Datensatzes $recordId: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Liest einen Datensatz.
     * 
     * @param string $recordId ID des Datensatzes
     * @return array|null Datensatz oder null wenn nicht gefunden
     */
    public function selectRecord(int $recordId): ?array
    {
        // Zuerst im Cache suchen (mit String-Key)
        if (isset($this->dataCache[(string)$recordId])) {
            return $this->dataCache[(string)$recordId];
        }

        $offset = $this->indexBuilder->getIndexOffset($recordId); // Korrekt: int
        if ($offset === null) {
            return null;
        }

        try {
            $data = $this->fileManager->readRecordAtOffset($offset);
    
            // WICHTIG: Erst prüfen, DANN cachen!
            if (isset($data) && empty($data['_deleted'])) {
                // Im Cache speichern (mit String-Key)
                $this->addToCache((string)$recordId, $data); // Korrektur: Cast zu string
                return $data;
            }
    
            return null; // Explizit null zurückgeben
    
        } catch (Throwable $e) {
            error_log("Fehler beim Lesen des Datensatzes $recordId: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Liest alle aktiven Datensätze (nicht gelöscht) unter Verwendung des Index.
     * 
     * @return array Liste aller Datensätze
     */
    public function selectAllRecords(): array
    {
        $results = [];
        foreach ($this->indexBuilder->getAllKeys() as $recordId) {
            $record = $this->selectRecord($recordId); // $recordId ist int
            if ($record !== null) {
                $results[] = $record;
            }
        }
        return $results;
    }
    
    /**
     * Sucht nach Datensätzen, die bestimmte Kriterien erfüllen.
     * 
     * @param callable $filterFn Filterfunktion, die für jeden Datensatz true/false zurückgibt
     * @param int $limit Maximale Anzahl der zurückgegebenen Datensätze (0 = alle)
     * @param int $offset Überspringt die ersten n passenden Datensätze
     * @return array Liste der passenden Datensätze
     */
    public function findRecords(callable $filterFn, int $limit = 0, int $offset = 0, ?int $id = null): array
    {
        $results = [];
    
        // Direkte ID-Suche (wenn $id gesetzt ist)
        if ($id !== null) {
            $record = $this->selectRecord($id); // selectRecord verwendet den Index
            if ($record !== null) {
                $results[] = $record;
            }
            return $results; // Fertig
        }
    
        $count = 0;
        $skipped = 0;
    
        foreach ($this->fileManager->readRecordsGenerator() as $record) {
    
            // Offset überspringen
            if ($offset > 0 && $skipped < $offset) {
                $skipped++;
                continue; // Nächster Datensatz
            }
    
            // Filterfunktion aufrufen und *danach* Limit prüfen!
            if ($record !== null && $filterFn($record))
            {
                $results[] = $record;
                $count++;
    
                // Limit prüfen
                if ($limit > 0 && $count >= $limit) {
                    break; // Schleife verlassen
                }
            }
        }
    
        return $results;
    }
    
    /**
     * Kompaktiert die Tabelle und baut den Index neu auf.
     */
    public function compactTable(): void
    {
        try {
            $this->commitIndex();

            $newIndex = [];
            $this->fileManager->compactData($newIndex);

            // Schlüssel im neuen Index auf Integer mappen
            $newIndex = array_combine(
                array_map('intval', array_keys($newIndex)),
                array_values($newIndex)
            );

            $this->indexBuilder->updateIndex($newIndex);
            $this->clearCache();
        } catch (Throwable $e) {
            throw new RuntimeException("Fehler bei der Tabellenkompaktierung: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Fügt einen Datensatz zum Cache hinzu.
     * 
     * @param string $recordId ID des Datensatzes
     * @param array $data Datensatz
     */
    private function addToCache(string $recordId, array $data): void // Korrektur: string
    {
        if (count($this->dataCache) >= $this->maxCacheSize) {
            reset($this->dataCache);
            $firstKey = key($this->dataCache);
            if ($firstKey !== null) {
              unset($this->dataCache[$firstKey]);
            }
        }
    
        $this->dataCache[$recordId] = $data; // $recordId ist bereits string
    }
    
    /**
     * Speichert den Index in die Datei.
     */
    public function commitIndex(): void
    {
        $this->indexBuilder->commitIndex();
    }
    
    /**
     * Leert den Cache.
     */
    public function clearCache(): void
    {
        $this->dataCache = [];
    }
    
    /**
     * Setzt die maximale Cache-Größe.
     * 
     * @param int $size Maximale Anzahl der Datensätze im Cache
     */
    public function setCacheSize(int $size): void
    {
        if ($size < 1) {
            throw new InvalidArgumentException("Cache size must be at least 1");
        }
        $this->maxCacheSize = max(1, $size);
        //Verbesserte Schleife
        while (count($this->dataCache) > $this->maxCacheSize) {
            reset($this->dataCache);
            $firstKey = key($this->dataCache);
            if($firstKey === null){
                break; // Verlasse die Schleife, wenn der Schlüssel null ist
            }
            unset($this->dataCache[$firstKey]);
        }
    }
    
    /**
     * Erstellt eine Sicherung der Tabellenkomponenten.
     * 
     * @param string $backupDir Verzeichnis für die Sicherung
     * @return array Pfade zu den gesicherten Dateien
     */
    public function backup(string $backupDir): array
    {
        $backupFiles = [];

        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            throw new RuntimeException("Backup-Verzeichnis konnte nicht erstellt werden.");
        }

        // Zuerst alle Indizes speichern
        $this->commitIndex();

        // Consistent timestamp
        $timestamp = date('YmdHis') . '_' . uniqid();


        // Datendatei sichern
        $dataFile = $this->config->getDataFile();
        $backupData = $backupDir . '/' . basename($dataFile) . '.' . $timestamp;

        if (copy($dataFile, $backupData)) {
            $backupFiles['data'] = $backupData;
        } else {
            throw new RuntimeException("Datendatei konnte nicht gesichert werden.");
        }

        // Indexdatei sichern
        $indexFile = $this->config->getIndexFile();
        $backupIndex = $backupDir . '/' . basename($indexFile) . '.' . $timestamp;

        if (copy($indexFile, $backupIndex)) {
            $backupFiles['index'] = $backupIndex;
        } else {
            throw new RuntimeException("Indexdatei konnte nicht gesichert werden."); // Add exception
        }

        // Log-Datei sichern
        $logFile = $this->config->getLogFile();
        $backupLog = $backupDir . '/' . basename($logFile) . '.' . $timestamp;

        if (copy($logFile, $backupLog)) {
            $backupFiles['log'] = $backupLog;
        } else {
            throw new RuntimeException("Log-Datei konnte nicht gesichert werden."); // Add exception
        }

        return $backupFiles;
    }

    public function clearTable(): void
    {
        // Leere die Datendatei
        if (file_put_contents($this->config->getDataFile(), '') === false) {
            throw new RuntimeException("Daten-Datei konnte nicht geleert werden.");
        }

        // Leere die Index-Datei
        if (file_put_contents($this->config->getIndexFile(), '') === false) {
            throw new RuntimeException("Index-Datei konnte nicht geleert werden.");
        }

        // Leere die Log-Datei
        if (file_put_contents($this->config->getLogFile(), '') === false) {
            throw new RuntimeException("Log-Datei konnte nicht geleert werden.");
        }

        // Setze den internen Index und Cache zurück
        $this->indexBuilder->updateIndex([]);
        $this->clearCache();
    }
}