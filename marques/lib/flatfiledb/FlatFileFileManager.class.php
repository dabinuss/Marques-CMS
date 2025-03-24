<?php
declare(strict_types=1);

namespace FlatFileDB;

use Generator;
use RuntimeException;
use JsonException;
use Throwable;

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
        $dataFile = $this->config->getDataFile();
    
        // Check if the file exists. If not, create it.
        if (!file_exists($dataFile)) {
            if (!touch($dataFile)) {
                throw new RuntimeException("Data file '$dataFile' does not exist and could not be created.");
            }
        }
    
        // Open file in read/write append mode ('a+b') for stable pointer positioning
        $handle = fopen($dataFile, 'a+b');
        if (!$handle) {
            throw new RuntimeException("Fehler beim Öffnen der Datei '$dataFile'.");
        }
    
        $offset = null;
    
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Konnte keine exklusive Sperre für die Datei erhalten.');
            }
            
            // Sicherstellen, dass der Zeiger am Ende der Datei ist
            fseek($handle, 0, SEEK_END);
            $offset = ftell($handle);
            
            $json = json_encode($record, JSON_THROW_ON_ERROR);
            if (fwrite($handle, $json . "\n") === false) {
                throw new RuntimeException('Fehler beim Schreiben des Datensatzes.');
            }
    
            fflush($handle); // Optional: Daten sofort in die Datei schreiben
    
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

            if (fseek($handle, $offset) !== 0) { // Check for fseek failure
                throw new RuntimeException("Ungültiger Offset in der Datendatei: $offset"); // Improved error message
            }

            if (feof($handle)) { // Check for EOF *before* fgets
                throw new RuntimeException("Ende der Datei erreicht vor Offset: $offset"); // Correct EOF message
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
        $dataFile = $this->config->getDataFile();
        $handle = fopen($dataFile, 'rb');
        
        if (!$handle) {
            throw new RuntimeException("Could not open data file for reading: $dataFile");
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
            throw new RuntimeException("Error during readAllRecords in file $dataFile: " . $e->getMessage(), 0, $e);
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
        $backupFile = $dataFile . '.bak.' . date('YmdHisu');

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
                    if (is_array($decoded) && isset($decoded['id'])) {
                        // Überschreibe vorherige Einträge – so gewinnt der letzte Eintrag
                        $records[$decoded['id']] = $decoded;
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
    
                $newIndex[$id] = $offsetInNewFile;
            }
    
            flock($writeHandle, LOCK_UN);
        } finally {
            fclose($writeHandle);
        }

        // 3. Erstelle ein Backup der alten Datei *VOR* dem Löschen/Umbenennen
        if (!copy($dataFile, $backupFile)) {
            throw new RuntimeException('Failed to create backup during compaction.');
        }
    
        // 4. Ersetze die alte Datei durch die neue.  *Zuerst* löschen, *dann* umbenennen.
        if (!unlink($dataFile)) {
            throw new RuntimeException('Alte Daten-Datei konnte nicht gelöscht werden.');
        }

        if (!rename($tempFile, $dataFile)) {
            // Fehler beim Umbenennen!  Versuche, das Backup wiederherzustellen.
            if (file_exists($backupFile)) { // Check if backup exists *before* rename
                if (!rename($backupFile, $dataFile)) { // Versuche, das Backup wiederherzustellen
                    // KRITISCHER FEHLER:  Sowohl das Umbenennen als auch die Wiederherstellung sind fehlgeschlagen!
                    throw new RuntimeException('CRITICAL: Compaction failed, and backup restoration failed!  Data may be lost.');
                }
            } else {
                // Backup file doesn't exist!
                throw new RuntimeException('CRITICAL: Compaction failed, and backup file does not exist! Data may be lost.');
            }
            throw new RuntimeException('Temporäre Datei konnte nicht umbenannt werden. Wiederherstellung versucht.');
        }

        // Aufräumen: Lösche die Backup-Datei nach erfolgreicher Kompaktierung.
        @unlink($backupFile); // Verwende @, um Warnungen zu unterdrücken, wenn die Datei nicht existiert.

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

    // Generator-based approach (better for large files)
    public function readRecordsGenerator(): Generator
    {
        $dataFile = $this->config->getDataFile(); // Get filename for error message
        $handle = fopen($dataFile, 'rb');

        if (!$handle) {
            throw new RuntimeException("Could not open data file: $dataFile");
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
                    yield $position => $decoded; // Yield the record and its offset
                } catch (JsonException $e) {
                    throw new RuntimeException("Error decoding JSON at offset $position: " . $e->getMessage(), 0, $e);
                }
            }

            flock($handle, LOCK_UN);
        } catch (Throwable $e) {
            throw new RuntimeException("Error during readRecordsGenerator in file $dataFile: " . $e->getMessage(), 0, $e);
        } finally {
            fclose($handle);
        }
    }
}