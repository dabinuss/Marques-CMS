<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;
use RuntimeException;
use JsonException;
use Throwable;

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
        $lockFile = $logFile . '.lock';

        if (!file_exists($logFile) || filesize($logFile) === 0) {
            return null;
        }

        $lockHandle = fopen($lockFile, 'w');
        if (!$lockHandle) {
            throw new RuntimeException("Could not create log rotation lock file.");
        }

        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            throw new RuntimeException("Could not acquire lock for log rotation.");
        }
    
        try {
            $handle = fopen($logFile, 'ab+');
            if (!$handle) {
                throw new RuntimeException("Log-Datei konnte nicht geöffnet werden.");
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
                flock($handle, LOCK_UN); // Keep this lock for file operations
                fclose($handle);
            }
    
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            @unlink($lockFile); // Remove the lock file *in finally*
        }
    
        return $backupPath;
    }
}