<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;
use RuntimeException;
use JsonException;
use Throwable;

/**
 * Konfigurationskonstanten für die FlatFile-Datenbank.
 */
class FlatFileDBConstants {
    public const DEFAULT_BASE_DIR    = 'data';
    public const DEFAULT_BACKUP_DIR  = 'data/backups';
    // Hier können ggf. weitere Konstanten definiert werden, z.B. für spezielle Datenverzeichnisse.

    public const LOG_ACTION_INSERT = 'INSERT';
    public const LOG_ACTION_UPDATE = 'UPDATE';
    public const LOG_ACTION_DELETE = 'DELETE';
}

/**
 * Stellt die Konfiguration der Dateien für eine Tabelle bereit.
 */
class FlatFileConfig
{
    private string $dataFile;
    private string $indexFile;
    private string $logFile;
    private bool $autoCommitIndex;
    
    /**
     * @param string $dataFile Pfad zur Datendatei
     * @param string $indexFile Pfad zur Indexdatei
     * @param string $logFile Pfad zur Logdatei (wird weiterhin für Transaktionslogs genutzt)
     * @param bool $autoCommitIndex Ob der Index automatisch gespeichert werden soll
     */
    public function __construct(
        string $dataFile,
        string $indexFile,
        string $logFile,
        bool $autoCommitIndex = false
    ) {
        $this->dataFile = $dataFile;
        $this->indexFile = $indexFile;
        $this->logFile = $logFile;
        $this->autoCommitIndex = $autoCommitIndex;
    }

    public function getDataFile(): string { return $this->dataFile; }
    public function getIndexFile(): string { return $this->indexFile; }
    public function getLogFile(): string { return $this->logFile; }
    public function autoCommitIndex(): bool { return $this->autoCommitIndex; }
}

/**
 * Zentrale Hilfsklasse für Validierungen.
 */
class FlatFileValidator
{
    /**
     * Überprüft, ob eine ID gültig ist (nur Buchstaben, Zahlen, Binde- und Unterstriche)
     * 
     * @param string $recordId Die zu prüfende ID
     * @return bool True wenn gültig, sonst false
     */
    public static function isValidId(string $recordId): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9\-_]+$/', $recordId);
    }
    
    /**
     * Validiert Felder eines Datensatzes anhand eines Schemas
     * 
     * @param array $data Die zu validierenden Daten
     * @param array $requiredFields Liste der Pflichtfelder
     * @param array $fieldTypes Assoziatives Array mit Feldname => Erwarteter Typ
     * @throws InvalidArgumentException wenn Validierung fehlschlägt
     */
    public static function validateData(array $data, array $requiredFields = [], array $fieldTypes = []): void
    {
        // Pflichtfelder prüfen
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new InvalidArgumentException("Fehlendes Pflichtfeld: $field");
            }
        }
        
        // Datentypen prüfen
        foreach ($fieldTypes as $field => $type) {
            if (isset($data[$field])) {
                $validType = match($type) {
                    'string' => is_string($data[$field]),
                    'int', 'integer' => is_int($data[$field]),
                    'float', 'double' => is_float($data[$field]),
                    'bool', 'boolean' => is_bool($data[$field]),
                    'array' => is_array($data[$field]),
                    'numeric' => is_numeric($data[$field]),
                    default => throw new InvalidArgumentException("Unbekannter Typ '$type' für Feld '$field'")
                };
                
                if (!$validType) {
                    throw new InvalidArgumentException("Feld '$field' hat nicht den erwarteten Typ '$type'");
                }
            }
        }
    }
}

/**
 * Verwaltet Index-Einträge: ID -> Byte-Offset
 */
class FlatFileIndexBuilder
{
    /** @var array<string, int> $indexData */
    private array $indexData = [];
    private bool $indexDirty = false;
    private FlatFileConfig $config;
    
    /**
     * @param FlatFileConfig $config Konfiguration der Tabelle
     */
    public function __construct(FlatFileConfig $config)
    {
        $this->config = $config;
        $this->loadIndex();
    }
    
    /**
     * Lädt den Index aus der Datei.
     * Bei Problemen mit dem Format wird ein Backup der defekten Datei erstellt und ein leeres Index-Array verwendet.
     */
    private function loadIndex(): void
    {
        $indexFile = $this->config->getIndexFile();
        
        if (!file_exists($indexFile)) {
            $indexDir = dirname($indexFile);
            if (!is_dir($indexDir) && !mkdir($indexDir, 0755, true)) {
                throw new RuntimeException("Index-Verzeichnis '$indexDir' konnte nicht erstellt werden.");
            }
            $this->indexData = [];
            return;
        }
        
        try {
            $content = file_get_contents($indexFile);
            if ($content === false) {
                throw new RuntimeException("Indexdatei konnte nicht gelesen werden.");
            }
            
            $this->indexData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($this->indexData)) {
                throw new JsonException("Ungültiges Indexdateiformat");
            }
        } catch (JsonException $e) {
            // Bei einem Fehler im JSON-Format erstellen wir ein Backup und setzen den Index zurück
            $backupFile = $indexFile . '.corrupted.' . time();
            if (file_exists($indexFile)) {
                copy($indexFile, $backupFile);
            }
            $this->indexData = [];
        }
    }
    
    /**
     * Speichert den Index in die Datei.
     * 
     * @throws RuntimeException wenn die Index-Datei nicht geschrieben werden kann
     */
    public function commitIndex(): void
    {
        if ($this->indexDirty) {
            $indexFile = $this->config->getIndexFile();
            $tmpFile = $indexFile . '.tmp';
            
            try {
                // Atomares Schreiben mit temporärer Datei
                $encoded = json_encode($this->indexData, JSON_THROW_ON_ERROR);
                $result = file_put_contents($tmpFile, $encoded, LOCK_EX);
                
                if ($result === false) {
                    throw new RuntimeException("Index-Datei konnte nicht geschrieben werden.");
                }
                
                if (!rename($tmpFile, $indexFile)) {
                    throw new RuntimeException("Temporäre Indexdatei konnte nicht umbenannt werden.");
                }
                
                $this->indexDirty = false;
            } catch (Throwable $e) {
                if (file_exists($tmpFile)) {
                    @unlink($tmpFile);
                }
                throw new RuntimeException("Fehler beim Speichern des Index: " . $e->getMessage(), 0, $e);
            }
        }
    }
    
    /**
     * Setzt einen Index-Eintrag.
     * 
     * @param string $recordId ID des Datensatzes
     * @param int $offset Byte-Offset in der Datendatei
     */
    public function setIndex(string $recordId, int $offset): void
    {
        $this->indexData[(string)$recordId] = $offset;
        $this->indexDirty = true;
        if ($this->config->autoCommitIndex()) {
            $this->commitIndex();
        }
    }
    
    /**
     * Entfernt einen Index-Eintrag.
     * 
     * @param string $recordId ID des Datensatzes
     */
    public function removeIndex(string $recordId): void
    {
        unset($this->indexData[(string)$recordId]);
        $this->indexDirty = true;
        if ($this->config->autoCommitIndex()) {
            $this->commitIndex();
        }
    }
    
    /**
     * Gibt den Byte-Offset eines Datensatzes zurück.
     * 
     * @param string $recordId ID des Datensatzes
     * @return int|null Byte-Offset oder null wenn nicht gefunden
     */
    public function getIndexOffset(string $recordId): ?int
    {
        return $this->indexData[$recordId] ?? null;
    }
    
    /**
     * Gibt alle IDs im Index zurück.
     * 
     * @return string[] Liste aller IDs
     */
    public function getAllKeys(): array
    {
        return array_keys($this->indexData);
    }
    
    /**
     * Prüft, ob eine ID im Index existiert.
     * 
     * @param string $recordId ID des Datensatzes
     * @return bool True wenn vorhanden, sonst false
     */
    public function hasKey(string $recordId): bool
    {
        return isset($this->indexData[$recordId]);
    }
    
    /**
     * Gibt die Anzahl der Index-Einträge zurück.
     * 
     * @return int Anzahl der Einträge
     */
    public function count(): int
    {
        return count($this->indexData);
    }

    /**
     * Aktualisiert den gesamten Index.
     *
     * @param array<string, int> $newIndex Das neue Index-Array.
     */
    public function updateIndex(array $newIndex): void
    {
        $this->indexData = $newIndex;
        $this->indexDirty = true;
        if ($this->config->autoCommitIndex()) {
            $this->commitIndex();
        }
    }
}

/**
 * Zeichnet alle Änderungen in einer Log-Datei für Transaktionen auf.
 * (Hinweis: Anstatt eines separaten Loggers wird hier ausschließlich über Exceptions gesteuert.)
 */
class FlatFileTransactionLog
{
    private FlatFileConfig $config;
    
    /**
     * @param FlatFileConfig $config Konfiguration der Tabelle
     */
    public function __construct(FlatFileConfig $config)
    {
        $this->config = $config;

        $logFile = $this->config->getLogFile();
        $logDir = dirname($logFile);
        
        // Verzeichnis erstellen falls erforderlich
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true)) {
            throw new RuntimeException("Log-Verzeichnis '$logDir' konnte nicht erstellt werden.");
        }
        
        if (!file_exists($logFile)) {
            touch($logFile);
        }
    }
    
    /**
     * Schreibt einen Eintrag ins Transaktionslog.
     * 
     * @param string $action Die Aktion (INSERT, UPDATE, DELETE)
     * @param string $recordId ID des betroffenen Datensatzes
     * @param array|null $data Optionale Datensatzfelder
     * @throws InvalidArgumentException wenn die ID ungültig ist
     * @throws RuntimeException wenn das Log nicht geschrieben werden kann
     */
    public function writeLog(string $action, string $recordId, ?array $data = null): void
    {
        if (!FlatFileValidator::isValidId($recordId)) {
            throw new InvalidArgumentException('Ungültige Datensatz-ID im Log.');
        }
        
        $entry = [
            'timestamp' => microtime(true),
            'action'    => $action,
            'recordId'  => $recordId,
            'data'      => $data,
        ];
        
        $handle = fopen($this->config->getLogFile(), 'ab');
        if (!$handle) {
            throw new RuntimeException("Log-Datei konnte nicht geöffnet werden.");
        }
        
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Konnte keine exklusive Sperre für die Log-Datei erhalten.");
            }
            
            $encoded = json_encode($entry, JSON_THROW_ON_ERROR);
            if (fwrite($handle, $encoded . "\n") === false) {
                throw new RuntimeException("Fehler beim Schreiben des Log-Eintrags.");
            }
            
            flock($handle, LOCK_UN);
        } catch (Throwable $e) {
            throw new RuntimeException("Fehler beim Schreiben des Transaktionslogs: " . $e->getMessage(), 0, $e);
        } finally {
            fclose($handle);
        }
    }
    
    /**
     * Liest das Transaktionslog.
     * 
     * @param int $limit Maximale Anzahl der zurückgegebenen Einträge (0 = alle)
     * @param int $offset Überspringt die ersten n Einträge
     * @return array Liste der Log-Einträge
     */
    public function readLog(int $limit = 0, int $offset = 0): array
    {
        $logFile = $this->config->getLogFile();
        $entries = [];
        $count = 0;
        $skipped = 0;
        
        if (!file_exists($logFile)) {
            return $entries;
        }
        
        $handle = fopen($logFile, 'rb');
        if (!$handle) {
            throw new RuntimeException("Log-Datei konnte nicht geöffnet werden.");
        }
        
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre für die Log-Datei erhalten.");
            }
            
            while (($line = fgets($handle)) !== false) {
                if ($offset > 0 && $skipped < $offset) {
                    $skipped++;
                    continue;
                }
                
                try {
                    $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $entries[] = $entry;
                    $count++;
                    
                    if ($limit > 0 && $count >= $limit) {
                        break;
                    }
                } catch (JsonException $e) {
                    // Fehlerhafte Zeile überspringen
                    continue;
                }
            }
            
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        
        return $entries;
    }
    
    /**
     * Rotiert das Transaktionslog (erstellt Backup und leert Log).
     * 
     * @param string|null $backupDir Optionales Verzeichnis für das Backup
     * @return string|null Pfad zur Backup-Datei oder null wenn kein Backup erstellt
     */
    public function rotateLog(?string $backupDir = null): ?string
    {
        $logFile = $this->config->getLogFile();
        
        if (!file_exists($logFile) || filesize($logFile) === 0) {
            return null;
        }
        
        $handle = fopen($logFile, 'ab+');
        if (!$handle) {
            throw new RuntimeException("Log-Datei konnte nicht geöffnet werden.");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException("Konnte keine exklusive Sperre für die Log-Datei erhalten.");
        }

        try {
            $backupPath = null;

            if ($backupDir !== null) {
                if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
                    throw new RuntimeException("Backup-Verzeichnis konnte nicht erstellt werden.");
                }
                
                $timestamp = date('YmdHis');
                $backupPath = $backupDir . '/' . basename($logFile) . '.' . $timestamp;
                
                if (!copy($logFile, $backupPath)) {
                    throw new RuntimeException("Log-Backup konnte nicht erstellt werden.");
                }
            }

            ftruncate($handle, 0);
            rewind($handle);
   
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        
        return $backupPath;
    }
}

/**
 * Liest und schreibt Datensätze in einer JSON-Lines-Datei (Append-Only).
 */
class FlatFileFileManager
{
    private FlatFileConfig $config;
    
    /**
     * @param FlatFileConfig $config Konfiguration der Tabelle
     */
    public function __construct(FlatFileConfig $config)
    {
        $this->config = $config;
        $dataFile = $this->config->getDataFile();
        $dataDir = dirname($dataFile);
        
        // Verzeichnis erstellen falls erforderlich
        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true)) {
            throw new RuntimeException("Daten-Verzeichnis '$dataDir' konnte nicht erstellt werden.");
        }
        
        if (!file_exists($dataFile)) {
            touch($dataFile);
        }
    }
    
    /**
     * Hängt einen Datensatz an das Datei-Ende an und gibt dessen Byte-Offset zurück.
     * 
     * @param array $record Der zu speichernde Datensatz
     * @return int Byte-Offset des Datensatzes
     * @throws RuntimeException wenn der Datensatz nicht geschrieben werden kann
     */
    public function appendRecord(array $record): int
    {
        $handle = fopen($this->config->getDataFile(), 'ab');
        if (!$handle) {
            throw new RuntimeException('Daten-Datei konnte nicht geöffnet werden.');
        }
        
        $offset = null;
        
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Konnte keine exklusive Sperre für die Datei erhalten.');
            }
            
            $offset = ftell($handle);
            $json = json_encode($record, JSON_THROW_ON_ERROR);
            
            if (fwrite($handle, $json . "\n") === false) {
                throw new RuntimeException('Fehler beim Schreiben des Datensatzes.');
            }
            
            flock($handle, LOCK_UN);
        } catch (Throwable $e) {
            throw new RuntimeException("Fehler beim Anhängen eines Datensatzes: " . $e->getMessage(), 0, $e);
        } finally {
            fclose($handle);
        }
        
        return $offset;
    }
    
    /**
     * Liest eine Zeile ab einem bestimmten Byte-Offset.
     * 
     * @param int $offset Byte-Offset in der Datei
     * @return array|null Der gelesene Datensatz oder null bei Fehler
     */
    public function readRecordAtOffset(int $offset): ?array
    {
        $handle = fopen($this->config->getDataFile(), 'rb');
        if (!$handle) {
            throw new RuntimeException("Datendatei konnte nicht zum Lesen geöffnet werden");
        }
        
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre für die Datei erhalten.");
            }
            
            if (fseek($handle, $offset) !== 0) {
                throw new RuntimeException("Ungültiger Offset in der Datendatei: $offset");
            }
            
            $line = fgets($handle);
            if ($line === false) {
                throw new RuntimeException("Konnte keine Daten vom angegebenen Offset lesen: $offset");
            }
            
            flock($handle, LOCK_UN);
            
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($decoded === null) {
                    throw new RuntimeException("Invalid JSON data at offset: $offset");
                }
                return $decoded;

            } catch (JsonException $e) {
                throw new RuntimeException("Error reading record at offset $offset: " . $e->getMessage(), 0, $e);
            }
        } finally {
            fclose($handle);
        }
    }
    
    /**
     * Liest alle Datensätze aus der Datei.
     * 
     * @return array<int, array> Liste aller Datensätze mit ihren Offsets
     */
    public function readAllRecords(): array
    {
        $result = [];
        $handle = fopen($this->config->getDataFile(), 'rb');
        
        if (!$handle) {
            return $result;
        }
        
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre für die Datei erhalten.");
            }
            
            while (!feof($handle)) {
                $position = ftell($handle);
                $line = fgets($handle);
                
                if ($line === false) {
                    break;
                }
                
                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $result[$position] = $decoded;
                } catch (JsonException $e) {
                    throw new RuntimeException("Error decoding JSON at offset $position: " . $e->getMessage(), 0, $e);
                }
            }
            
            flock($handle, LOCK_UN);
        } catch (Throwable $e) {
            throw new RuntimeException("Error during readAllRecords: " . $e->getMessage(), 0, $e); 
        } finally {
            fclose($handle);
        }
        
        return $result;
    }
    
    /**
     * Kompaktiert die Datei, indem alle Datensätze eingelesen und pro ID
     * nur der letzte Eintrag übernommen wird. Wird der letzte Eintrag als gelöscht markiert,
     * so wird die ID nicht in die neue Datei geschrieben.
     *
     * @param array &$newIndex Referenz auf das neue Index-Array
     * @return array Das neue Index-Array
     * @throws RuntimeException wenn die Kompaktierung fehlschlägt
     */
    public function compactData(array &$newIndex): array
    {
        $newIndex = [];
        $dataFile = $this->config->getDataFile();
        $tempFile = $dataFile . '.tmp';
        
        // 1. Alle Zeilen einlesen und pro ID nur den letzten Eintrag speichern
        $records = [];
        $readHandle = fopen($dataFile, 'rb');
        if (!$readHandle) {
            throw new RuntimeException('Fehler beim Öffnen der Daten-Datei.');
        }
        
        try {
            if (!flock($readHandle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre für die Datei erhalten.");
            }
            
            while (($line = fgets($readHandle)) !== false) {
                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded) && isset($decoded['id']) && FlatFileValidator::isValidId($decoded['id'])) {
                        // Überschreibe vorherige Einträge – so gewinnt der letzte Eintrag
                        $records[(string)$decoded['id']] = $decoded;
                    }
                } catch (JsonException $e) {
                    // Ungültige Zeile überspringen
                    continue;
                }
            }
            
            flock($readHandle, LOCK_UN);
        } finally {
            fclose($readHandle);
        }
        
        // 2. Schreibe nur die aktiven (nicht gelöschten) Datensätze in die temporäre Datei
        $writeHandle = fopen($tempFile, 'wb');
        if (!$writeHandle) {
            throw new RuntimeException('Fehler beim Öffnen der temporären Datei.');
        }
        
        try {
            if (!flock($writeHandle, LOCK_EX)) {
                throw new RuntimeException("Konnte keine Schreibsperre für die temporäre Datei erhalten.");
            }
            
            foreach ($records as $id => $record) {
                // Überspringe den Datensatz, wenn er als gelöscht markiert ist
                if (!empty($record['_deleted'])) {
                    continue;
                }
                
                $offsetInNewFile = ftell($writeHandle);
                $encoded = json_encode($record, JSON_THROW_ON_ERROR);
                if (fwrite($writeHandle, $encoded . "\n") === false) {
                    throw new RuntimeException('Fehler beim Schreiben während der Kompaktierung.');
                }
                
                $newIndex[(string)$id] = $offsetInNewFile;
            }
            
            flock($writeHandle, LOCK_UN);
        } finally {
            fclose($writeHandle);
        }
        
        // 3. Erstelle ein Backup der alten Datei
        $backupFile = $dataFile . '.bak.' . time();
        if (!copy($dataFile, $backupFile)) {
            throw new RuntimeException('Failed to create backup during compaction.');
        }
        
        // 4. Ersetze die alte Datei durch die neue
        if (!unlink($dataFile)) {
            throw new RuntimeException('Alte Daten-Datei konnte nicht gelöscht werden.');
        }
        
        if (!rename($tempFile, $dataFile)) {
            if (file_exists($backupFile)) {
                copy($backupFile, $dataFile);
            }
            throw new RuntimeException('Temporäre Datei konnte nicht umbenannt werden. Wiederherstellung versucht.');
        }
        
        return $newIndex;
    }

    
    /**
     * Erstellt ein Backup der Datendatei.
     * 
     * @param string $backupDir Verzeichnis für das Backup
     * @return string Pfad zur Backup-Datei
     */
    public function backupData(string $backupDir): string
    {
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            throw new RuntimeException("Backup-Verzeichnis konnte nicht erstellt werden.");
        }
        
        $dataFile = $this->config->getDataFile();
        $timestamp = date('YmdHis');
        $backupFile = $backupDir . '/' . basename($dataFile) . '.' . $timestamp;
        
        if (!copy($dataFile, $backupFile)) {
            throw new RuntimeException("Datei-Backup konnte nicht erstellt werden.");
        }
        
        return $backupFile;
    }
}

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
    public function insertRecord(string $recordId, array $data): bool
    {
        if (!FlatFileValidator::isValidId($recordId)) {
            throw new InvalidArgumentException("Ungültige ID: $recordId");
        }
        
        // Schema-Validierung wenn definiert
        if (!empty($this->schema)) {
            FlatFileValidator::validateData(
                $data, 
                $this->schema['requiredFields'] ?? [], 
                $this->schema['fieldTypes'] ?? []
            );
        }
        
        // Prüfen ob Datensatz bereits existiert
        if ($this->indexBuilder->hasKey($recordId)) {
            return false;
        }
        
        try {
            $data['id'] = (string)$recordId;
            $data['created_at'] = time();
            $data['_deleted'] = false;
            $offset = $this->fileManager->appendRecord($data);
            $this->indexBuilder->setIndex($recordId, $offset);
            $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_INSERT, $recordId, $data);
            
            // Im Cache speichern
            $this->addToCache($recordId, $data);
            
            return true;
        } catch (Throwable $e) {
            throw new RuntimeException("Fehler beim Einfügen des Datensatzes $recordId: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Aktualisiert einen bestehenden Datensatz.
     * 
     * @param string $recordId ID des Datensatzes
     * @param array $newData Neue Datensatzfelder
     * @return bool True bei Erfolg, false wenn Datensatz nicht existiert
     * @throws RuntimeException bei Schreibfehlern
     */
    public function updateRecord(string $recordId, array $newData): bool
    {
        $oldOffset = $this->indexBuilder->getIndexOffset($recordId);
        if ($oldOffset === null) {
            return false;
        }
        
        // Schema-Validierung wenn definiert
        if (!empty($this->schema)) {
            FlatFileValidator::validateData(
                $newData, 
                $this->schema['requiredFields'] ?? [], 
                $this->schema['fieldTypes'] ?? []
            );
        }
        
        try {
            $oldData = $this->fileManager->readRecordAtOffset($oldOffset);
            if (!$oldData || !is_array($oldData) || !isset($oldData['id'])) { // isValidId removed
                return false;
            }
            
            // Alten Datensatz als gelöscht markieren
            $oldData['_deleted'] = true;
            $this->fileManager->appendRecord($oldData);
            
            // Neuen Datensatz anfügen mit Beibehaltung der ursprünglichen Metadaten
            $newData['id'] = $recordId;
            $newData['created_at'] = $oldData['created_at'] ?? time();
            $newData['updated_at'] = time();
            
            $offset = $this->fileManager->appendRecord($newData);
            $this->indexBuilder->setIndex($recordId, $offset);
            $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_UPDATE, $recordId, $newData);
            
            // Cache aktualisieren
            $this->addToCache($recordId, $newData);
            
            return true;
        } catch (Throwable $e) {
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
    public function deleteRecord(string $recordId): bool
    {
        $recordId = (string)$recordId;
        $oldOffset = $this->indexBuilder->getIndexOffset($recordId);
        if ($oldOffset === null) {
            error_log("DEBUG: ID '$recordId' nicht im Index gefunden.");
            return false;
        }
        
        try {
            $oldData = $this->fileManager->readRecordAtOffset($oldOffset);
            if (!$oldData || isset($oldData['_deleted'])) { // Check if already deleted
                return false;
            }
            
            $oldData['_deleted'] = true;
            $oldData['deleted_at'] = time();
            $this->fileManager->appendRecord($oldData);
            $this->indexBuilder->removeIndex($recordId);
            $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_DELETE, $recordId);
            
            // Aus Cache entfernen
            unset($this->dataCache[$recordId]);
            
            return true;
        } catch (Throwable $e) {
            error_log("Fehler beim Löschen des Datensatzes '$recordId': " . $e->getMessage());
            throw new RuntimeException("Fehler beim Löschen des Datensatzes $recordId: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Liest einen Datensatz.
     * 
     * @param string $recordId ID des Datensatzes
     * @return array|null Datensatz oder null wenn nicht gefunden
     */
    public function selectRecord(string|int $recordId): ?array
    {
        $recordId = (string)$recordId;

        // Zuerst im Cache suchen
        if (isset($this->dataCache[$recordId])) {
            return $this->dataCache[$recordId];
        }
        
        $offset = $this->indexBuilder->getIndexOffset($recordId);
        if ($offset === null) {
            return null;
        }
        
        try {
            $data = $this->fileManager->readRecordAtOffset($offset);
            
            if (isset($data) && empty($data['_deleted'])) {
                // Im Cache speichern
                $this->addToCache($recordId, $data);
                return $data;
            }
            
            return null;
        } catch (Throwable $e) {
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
        $allKeys = $this->indexBuilder->getAllKeys();
        
        foreach ($allKeys as $recordId) {
            $recordId = (string)$recordId;
            $record = $this->selectRecord($recordId);
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
    public function findRecords(callable $filterFn, int $limit = 0, int $offset = 0): array
    {
        $results = [];
        $count = 0;
        $skipped = 0;
        $allKeys = $this->indexBuilder->getAllKeys();
        
        foreach ($allKeys as $recordId) {
            $record = $this->selectRecord($recordId);
            
            if ($record !== null && $filterFn($record)) {
                if ($offset > 0 && $skipped < $offset) {
                    $skipped++;
                    continue;
                }
                
                $results[] = $record;
                $count++;
                
                if ($limit > 0 && $count >= $limit) {
                    break;
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
            // Vor der Kompaktierung die Indizes speichern
            $this->commitIndex();
            
            // Backup erstellen
            $backupDir = dirname($this->config->getDataFile()) . '/backups';
            $this->fileManager->backupData($backupDir);
            
            $newIndex = [];
            $this->fileManager->compactData($newIndex);
            
            // Index-Datei aktualisieren
            $result = file_put_contents(
                $this->config->getIndexFile(),
                json_encode($newIndex, JSON_THROW_ON_ERROR)
            );
            
            if ($result === false) {
                throw new RuntimeException("Index-Datei konnte nicht geschrieben werden.");
            }
            
            // Neuinitialisierung des IndexBuilders
            // $this->indexBuilder = new FlatFileIndexBuilder($this->config);
            $this->indexBuilder->updateIndex($newIndex);
            
            // Cache leeren
            $this->dataCache = [];
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
    private function addToCache(string $recordId, array $data): void
    {
        if (count($this->dataCache) >= $this->maxCacheSize) {
            reset($this->dataCache);
            $firstKey = key($this->dataCache);
            unset($this->dataCache[$firstKey]);
        }
        
        $this->dataCache[$recordId] = $data;
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
        $this->maxCacheSize = max(1, $size);
        while (count($this->dataCache) > $this->maxCacheSize) {
            reset($this->dataCache);
            $firstKey = key($this->dataCache);
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
        
        // Datendatei sichern
        $dataFile = $this->config->getDataFile();
        $timestamp = date('YmdHis');
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

/**
 * Hauptklasse zur Verwaltung mehrerer Tabellen.
 */
class FlatFileDatabase
{
    private string $baseDir;
    /** @var array<string, FlatFileTableEngine> */
    private array $tables = [];
    private bool $autoCommitIndex;
    private string $logFile;
    
    /**
     * @param string $baseDir Basisverzeichnis für die Datenbankdateien (Standard: FlatFileDBConstants::DEFAULT_BASE_DIR)
     * @param bool $autoCommitIndex Ob der Index automatisch gespeichert werden soll
     */
    public function __construct(string $baseDir = FlatFileDBConstants::DEFAULT_BASE_DIR, bool $autoCommitIndex = false)
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->autoCommitIndex = $autoCommitIndex;
        $this->logFile = "{$this->baseDir}/database.log";
        
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0755, true)) {
            throw new RuntimeException("Datenbank-Verzeichnis '{$this->baseDir}' konnte nicht erstellt werden.");
        }
    }
    
    /**
     * Registriert eine Tabelle und erzeugt die zugehörige Engine.
     * 
     * @param string $tableName Name der Tabelle
     * @throws InvalidArgumentException wenn der Tabellenname ungültig ist
     */
    public function registerTable(string $tableName)
    {
        if (!FlatFileValidator::isValidId($tableName)) {
            throw new InvalidArgumentException("Tabellenname '$tableName' ist ungültig.");
        }
        
        $dataFile  = "{$this->baseDir}/{$tableName}_data.jsonl";
        $indexFile = "{$this->baseDir}/{$tableName}_index.json";
        $logFile   = "{$this->baseDir}/{$tableName}_log.jsonl";
        
        $config = new FlatFileConfig($dataFile, $indexFile, $logFile, $this->autoCommitIndex);
        $this->tables[$tableName] = new FlatFileTableEngine($config);
        return $this->tables[$tableName];
    }
    
    /**
     * Gibt die Engine für eine Tabelle zurück.
     * 
     * @param string $tableName Name der Tabelle
     * @return FlatFileTableEngine Engine für die angegebene Tabelle
     * @throws RuntimeException wenn die Tabelle nicht registriert ist
     */
    public function table(string $tableName): FlatFileTableEngine
    {
        if (!isset($this->tables[$tableName])) {
            throw new RuntimeException("Tabelle '$tableName' wurde nicht registriert.");
        }
        
        return $this->tables[$tableName];
    }
    
    /**
     * Prüft, ob eine Tabelle registriert ist.
     * 
     * @param string $tableName Name der Tabelle
     * @return bool True wenn registriert, sonst false
     */
    public function hasTable(string $tableName): bool
    {
        return isset($this->tables[$tableName]);
    }
    
    /**
     * Registriert mehrere Tabellen.
     * 
     * @param array $tableNames Liste der Tabellennamen
     */
    public function registerTables(array $tableNames): void
    {
        foreach ($tableNames as $table) {
            $this->registerTable($table);
        }
    }
    
    /**
     * Kommittiert alle Index-Dateien.
     */
    public function commitAllIndexes(): void
    {
        foreach ($this->tables as $engine) {
            $engine->commitIndex();
        }
    }
    
    /**
     * Kompaktiert alle Tabellen.
     * 
     * @return array Status der Kompaktierung für jede Tabelle
     */
    public function compactAllTables(): array
    {
        $results = [];
        
        foreach ($this->tables as $tableName => $engine) {
            try {
                $engine->compactTable();
                $results[$tableName] = true;
            } catch (Throwable $e) {
                $results[$tableName] = false;
            }
        }
        
        return $results;
    }
    
    /**
     * Leert alle Caches.
     */
    public function clearAllCaches(): void
    {
        foreach ($this->tables as $engine) {
            $engine->clearCache();
        }
    }
    
    /**
     * Gibt die Namen aller registrierten Tabellen zurück.
     * 
     * @return array Liste der Tabellennamen
     */
    public function getTableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Erstellt ein Backup aller Tabellen.
     *
     * @param string $backupDir Verzeichnis für die Sicherungen
     * @return array<string, array> Status der Backups für jede Tabelle
     */
    public function createBackup(string $backupDir): array
    {
        $results = [];

        foreach ($this->tables as $tableName => $engine) {
            try {
                $backupFiles = $engine->backup($backupDir);
                $results[$tableName] = $backupFiles;
            } catch (Throwable $e) {
                $results[$tableName] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function clearDatabase(): void
    {
        foreach ($this->tables as $tableName => $engine) {
            $engine->clearTable();
        }
    }
}

// ===========================================================================
// Beispielhafte Verwendung mit ausführlicher Dokumentation:
// ===========================================================================

/*

// 1. Datenbank-Instanz erstellen:
//    Hier wird ein neues Datenbankobjekt erzeugt, wobei das Basisverzeichnis über die Konstanten
//    definiert werden kann. Der Parameter $autoCommitIndex gibt an, ob der Index nach jedem
//    Schreibvorgang automatisch in die Datei übernommen werden soll.
$db = new FlatFileDatabase(FlatFileDBConstants::DEFAULT_BASE_DIR, false);

// 2. Tabellen registrieren:
//    Mehrere Tabellen können registriert werden. Dies erzeugt intern für jede Tabelle
//    eine eigene Engine, die für CRUD-Operationen, Backups und Kompaktierung zuständig ist.
$db->registerTables(['users', 'products']);

// 3. Schema für die 'users'-Tabelle setzen:
//    Hier definieren wir, welche Felder zwingend vorhanden sein müssen (requiredFields) und welche
//    Datentypen für bestimmte Felder erwartet werden.
$db->table('users')->setSchema(
    ['name', 'email'],                  // Pflichtfelder
    ['name' => 'string', 'email' => 'string', 'age' => 'int'] // Erwartete Datentypen
);

// 4. Datensätze einfügen:
//    Mit insertRecord wird ein neuer Datensatz erstellt. Der erste Parameter ist die eindeutige ID.
//    Wird versucht, einen Datensatz mit einer bereits existierenden ID einzufügen, wird false zurückgegeben.
$db->table('users')->insertRecord('user123', [
    'name'  => 'Alice Johnson',
    'email' => 'alice@example.com',
    'age'   => 32
]);

$db->table('products')->insertRecord('prod001', [
    'title' => 'Laptop',
    'price' => 999,
    'stock' => 10
]);

// 5. Änderungen an den Index-Dateien übernehmen:
//    Nachdem mehrere Schreibvorgänge erfolgt sind, können alle Index-Dateien in einem Schritt
//    committet werden.
$db->commitAllIndexes();

// 6. Datensatz auslesen:
//    Der selectRecord-Aufruf liefert den Datensatz mit der angegebenen ID zurück. Falls dieser im
//    internen Cache liegt, wird er direkt aus diesem zurückgegeben.
$userData = $db->table('users')->selectRecord('user123');
var_dump($userData);

// 7. Alle Datensätze einer Tabelle auslesen:
//    Mit selectAllRecords werden alle aktiven (nicht gelöschten) Datensätze der Tabelle zurückgegeben.
$allProducts = $db->table('products')->selectAllRecords();

// 8. Datensätze anhand eines Filters suchen:
//    Die findRecords-Methode erlaubt es, mithilfe einer Callback-Funktion gezielt nach Datensätzen zu
//    suchen, die bestimmte Kriterien erfüllen.
$expensiveProducts = $db->table('products')->findRecords(
    function($record) {
        return $record['price'] > 500;
    },
    10, // Limit: maximal 10 Treffer
    0   // Offset: beginne ab dem ersten Treffer
);

// 9. Tabelle kompaktieren:
//    Durch die Kompaktierung werden gelöschte Datensätze entfernt und die Daten neu geschrieben.
//    Dabei wird der Index neu aufgebaut.
$db->table('users')->compactTable();

// 10. Backup der Datenbank erstellen:
//     Hier wird ein Backup aller Tabellen erstellt. Die Backups werden im angegebenen Verzeichnis
//     abgelegt (Standard: im Basisverzeichnis unter "backups/YYYYMMDDHHMMSS").
$backupResults = $db->createBackup(FlatFileDBConstants::DEFAULT_BACKUP_DIR);

*/