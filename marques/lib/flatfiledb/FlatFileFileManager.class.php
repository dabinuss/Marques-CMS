<?php
declare(strict_types=1);

namespace FlatFileDB;

use Generator;
use RuntimeException;
use JsonException;
use Throwable;

/**
 * Liest und schreibt Datensätze in einer JSON-Lines-Datei (Append-Only) mit Komprimierung.
 */
class FlatFileFileManager
{
    private FlatFileConfig $config;
    private $compressionLevel;

    private $readHandle = null;
    private ?int $readHandleMTime = null;
    private $writeHandle = null;
    private ?int $writeHandleMTime = null;

    /**
     * @param FlatFileConfig $config Konfiguration der Tabelle
     * @param int $compressionLevel Kompressionslevel (0-9, 0 = keine, 9 = max)
     */
    public function __construct(FlatFileConfig $config, int $compressionLevel = 6)
    {
        $this->config = $config;
        $this->compressionLevel = $compressionLevel;
        $dataFile = $this->config->getDataFile();
        $dataDir = dirname($dataFile);

        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true)) {
            throw new RuntimeException("Daten-Verzeichnis '$dataDir' konnte nicht erstellt werden.");
        }
        if (!file_exists($dataFile)) {
            touch($dataFile);
        }
    }

    private function getReadHandle()
    {
        $dataFile = $this->config->getDataFile();
        clearstatcache(true, $dataFile);
        $currentMTime = filemtime($dataFile);
        if ($this->readHandle !== null && $this->readHandleMTime === $currentMTime) {
            return $this->readHandle;
        }
        if ($this->readHandle !== null) {
            fclose($this->readHandle);
        }
        $handle = fopen($dataFile, 'rb');
        if (!$handle) {
            throw new RuntimeException("Could not open data file for reading: $dataFile");
        }
        $this->readHandle = $handle;
        $this->readHandleMTime = $currentMTime;
        return $handle;
    }

    // Neue Methode: Liefert einen persistenten Schreib-Handle
    private function getWriteHandle()
    {
        $dataFile = $this->config->getDataFile();
        clearstatcache(true, $dataFile);
        $currentMTime = filemtime($dataFile);
        if ($this->writeHandle !== null && $this->writeHandleMTime === $currentMTime) {
            return $this->writeHandle;
        }
        if ($this->writeHandle !== null) {
            fclose($this->writeHandle);
        }
        $handle = fopen($dataFile, 'a+b'); // Lese-/Schreibmodus im Append-Modus
        if (!$handle) {
            throw new RuntimeException("Fehler beim Öffnen der Datei '$dataFile' für Schreibzugriffe.");
        }
        $this->writeHandle = $handle;
        $this->writeHandleMTime = $currentMTime;
        return $handle;
    }

    public function __destruct()
    {
        if ($this->readHandle !== null) {
            fclose($this->readHandle);
        }
        if ($this->writeHandle !== null) {
            fclose($this->writeHandle);
        }
    }

    /**
     * Hängt einen Datensatz an das Datei-Ende an und gibt dessen Byte-Offset zurück.
     *
     * @param array $record Der zu speichernde Datensatz
     * @return int Byte-Offset des Datensatzes (unkomprimiert)
     * @throws RuntimeException wenn der Datensatz nicht geschrieben werden kann
     */
    public function appendRecord(array $record): int
    {
        // Vorberechnung außerhalb des Locks
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        $compressed = gzencode($json . "\n", $this->compressionLevel);
        if ($compressed === false) {
            throw new RuntimeException('Fehler beim Komprimieren des Datensatzes.');
        }

        $handle = $this->getWriteHandle();

        // Exklusiver Lock – nur während des Schreibens
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Konnte keine exklusive Sperre für die Datei erhalten.');
        }
        fseek($handle, 0, SEEK_END);
        $offset = ftell($handle);

        if (fwrite($handle, $compressed) === false) {
            flock($handle, LOCK_UN);
            throw new RuntimeException('Fehler beim Schreiben des Datensatzes.');
        }
        fflush($handle);
        flock($handle, LOCK_UN);

        return $offset;
    }

    /**
     * Liest einen Datensatz ab einem bestimmten unkomprimierten Offset mit effizienter Dekompression.
     *
     * @param int $offset Byte-Offset in der Datei (unkomprimiert)
     * @return array Der gelesene Datensatz
     * @throws RuntimeException Bei Fehlern beim Lesen oder Dekodieren
     */
    public function readRecordAtOffset(int $offset): array
    {
        $handle = $this->getReadHandle();
        
        try {
            // Shared Lock mit besserer Fehlerbehandlung
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre für die Datei erhalten.");
            }

            // Seek mit präziserer Fehlerbehandlung
            if (fseek($handle, $offset) === -1) {
                throw new RuntimeException("Ungültiger Offset in der Datendatei: $offset");
            }

            $compressedBuffer = '';
            $windowSize = 8192; // Verdoppelte Fenstergröße für effizientere Lesevorgänge
            $maxReadAttempts = 10; // Maximale Anzahl von Leseversuchs

            for ($attempt = 0; $attempt < $maxReadAttempts; $attempt++) {
                $chunk = gzread($handle, $windowSize);
                
                if ($chunk === false) {
                    throw new RuntimeException("Fehler beim Lesen der komprimierten Daten ab Offset: $offset");
                }

                $compressedBuffer .= $chunk;
                
                // Optimierte Dekomprimierung mit Pufferung
                $decompressed = @gzdecode($compressedBuffer);
                
                if ($decompressed !== false) {
                    $lines = explode("\n", $decompressed, 2);
                    
                    if (!empty($lines[0])) {
                        try {
                            // Strikte JSON-Dekodierung mit Fehlerbehandlung
                            return json_decode(trim($lines[0]), true, 512, JSON_THROW_ON_ERROR);
                        } catch (JsonException $e) {
                            throw new RuntimeException("Fehler beim Dekodieren des JSON-Datensatzes: " . $e->getMessage(), 0, $e);
                        }
                    }
                }

                // Exit-Bedingung, wenn kein weiterer Inhalt
                if (feof($handle)) {
                    break;
                }
            }

            throw new RuntimeException("Kein gültiger Datensatz gefunden ab Offset: $offset");
        
        } finally {
            // Sicherstellen, dass der Lock immer aufgehoben wird
            flock($handle, LOCK_UN);
        }
    }

    /**
     * Liest alle Datensätze aus der Datei.
     *
     * @return array<int, array> Liste aller Datensätze mit ihren Offsets (unkomprimiert)
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

            $compressedBuffer = ''; // GEÄNDERT: Buffer für komprimierte Daten
            $offset = 0;

            while (!feof($handle)) {
                $chunk = gzread($handle, 8192);  // GEÄNDERT: gzread für komprimierte Daten
                if ($chunk === false) {
                    break; // Fehler beim Lesen, Schleife verlassen
                }
                $compressedBuffer .= $chunk;


                // Versuche, vollständige Datensätze aus dem Buffer zu extrahieren
                while (true) { // Innere Schleife zum Extrahieren mehrerer Datensätze
                    $decompressed = @gzdecode($compressedBuffer); // GEÄNDERT: Dekomprimierung
                    if ($decompressed === false) {
                        break; // Nicht genug Daten zum Dekomprimieren, äußere Schleife fortsetzen
                    }

                    try {
                        // Versuche, alle JSON-Objekte zu parsen
                        $lines = explode("\n", rtrim($decompressed, "\n"));
                        $lastCompleteLine = '';
                        $completeRecords = [];


                        foreach($lines as $line) {
                            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                            if ($decoded !== null) {
                                $completeRecords[] = $decoded; // Füge den Datensatz hinzu
                                $lastCompleteLine = $line;
                            }
                        }

                        if(empty($completeRecords)){
                            break; // Keine vollständigen Datensätze gefunden.
                        }

                        // Berechne den neuen Offset NACH dem letzten vollständigen Datensatz
                        $bytesConsumed = strlen(gzencode($lastCompleteLine . "\n", $this->compressionLevel)); // Länge des *komprimierten* Datensatzes
                        $compressedBuffer = substr($compressedBuffer, $bytesConsumed); // Entferne den verarbeiteten Teil
                        foreach($completeRecords as $record){
                            $result[$offset] = $record; // Füge den Datensatz mit dem *unkomprimierten* Offset hinzu
                            $offset += strlen(json_encode($record, JSON_THROW_ON_ERROR) . "\n"); // Inkrementiere den Offset um die Länge des *unkomprimierten* Datensatzes.
                        }

                    } catch (JsonException $e) {
                        // Kein vollständiges JSON, Schleife verlassen
                        break;
                    }
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
     * nur der letzte Eintrag übernommen wird.  ... (Rest der Methode ist unten)
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

            $compressedBuffer = ''; // GEÄNDERT: Buffer für komprimierte Daten

            while (!feof($readHandle)) {
                $chunk = gzread($readHandle, 8192); // GEÄNDERT: gzread
                if ($chunk === false) {
                    break;
                }
                $compressedBuffer .= $chunk;

                // Versuche, vollständige Datensätze aus dem Buffer zu extrahieren (wie in readAllRecords)
                while(true){
                    $decompressed = @gzdecode($compressedBuffer); //GEÄNDERT
                    if($decompressed === false){
                        break;
                    }

                    try {
                        $lines = explode("\n", rtrim($decompressed, "\n"));
                        $lastCompleteLine = '';
                        $completeRecords = [];

                        foreach($lines as $line){
                            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                            if (is_array($decoded) && isset($decoded['id'])) {
                                // Überschreibe vorherige Einträge – so gewinnt der letzte Eintrag
                                $records[$decoded['id']] = $decoded;
                                $completeRecords[] = $decoded;
                                $lastCompleteLine = $line;
                            }
                        }

                        if(empty($completeRecords)){
                            break;
                        }

                        $bytesConsumed = strlen(gzencode($lastCompleteLine . "\n", $this->compressionLevel));
                        $compressedBuffer = substr($compressedBuffer, $bytesConsumed);

                    } catch (JsonException $e) {
                        // Kein vollständiges JSON, Schleife verlassen
                        break;
                    }

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

                $offsetInNewFile = ftell($writeHandle); // Offset *vor* dem Schreiben
                $encoded = json_encode($record, JSON_THROW_ON_ERROR);
                $compressed = gzencode($encoded . "\n", $this->compressionLevel); // GEÄNDERT: Komprimierung
                if (fwrite($writeHandle, $compressed) === false) { // GEÄNDERT: Schreibe komprimierte Daten
                    throw new RuntimeException('Fehler beim Schreiben während der Kompaktierung.');
                }

                $newIndex[$id] = $offsetInNewFile; // Speichere den *unkomprimierten* Offset
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

            $compressedBuffer = ''; // GEÄNDERT: Buffer für komprimierte Daten
            $offset = 0;

            while (!feof($handle)) {
                $chunk = gzread($handle, 8192); // GEÄNDERT: gzread
                if ($chunk === false) {
                    break;
                }
                $compressedBuffer .= $chunk;

                // Extrahiere Datensätze (wie in readAllRecords)
                while(true){
                    $decompressed = @gzdecode($compressedBuffer);  //GEÄNDERT
                    if($decompressed === false){
                        break;
                    }

                    try{
                        $lines = explode("\n", rtrim($decompressed, "\n"));
                        $lastCompleteLine = '';
                        $completeRecords = [];

                        foreach($lines as $line){
                            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                            if($decoded !== null){
                                $completeRecords[] = $decoded;
                                $lastCompleteLine = $line;
                            }
                        }

                        if(empty($completeRecords)){
                            break;
                        }


                        $bytesConsumed = strlen(gzencode($lastCompleteLine . "\n", $this->compressionLevel));
                        $compressedBuffer = substr($compressedBuffer, $bytesConsumed);
                        foreach($completeRecords as $record){
                            yield $offset => $record; // Yield *unkomprimierten* offset
                            $offset += strlen(json_encode($record, JSON_THROW_ON_ERROR) . "\n");
                        }

                    } catch (JsonException $e) {
                        break;
                    }
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