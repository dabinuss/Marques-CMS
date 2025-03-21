<?php
declare(strict_types=1);

namespace FlatFileDB;

use RuntimeException;
use JsonException;
use Throwable;

/**
 * Verwaltet Index-Einträge: ID -> Byte-Offset
 */
class FlatFileIndexBuilder
{
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

        $handle = fopen($indexFile, 'rb'); // Open for reading
        if (!$handle) {
            throw new RuntimeException("Indexdatei konnte nicht geöffnet werden.");
        }

        try {
            if (!flock($handle, LOCK_SH)) { // Shared lock for reading
                throw new RuntimeException("Konnte keine Lesesperre für die Indexdatei erhalten.");
            }

            $content = '';
            while (!feof($handle)) {
                $content .= fread($handle, 8192); // Read in chunks
            }

            if ($content === '') {
                $this->indexData = []; // Empty file is valid
                return;
            }

            $this->indexData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            // Check for null *before* is_array
            if ($this->indexData === null) {
                throw new JsonException("Ungültiges Indexdateiformat (json_decode returned null)");
            }

            if (!is_array($this->indexData)) {
                throw new JsonException("Ungültiges Indexdateiformat");
            }


        } catch (JsonException $e) {
            // Bei einem Fehler im JSON-Format erstellen wir ein Backup und setzen den Index zurück
            $backupFile = $indexFile . '.corrupted.' . time();
            if (file_exists($indexFile)) {
                // Attempt atomic rename, but handle failure.
                if(!rename($indexFile, $backupFile)){
                    throw new RuntimeException("Fehler beim Laden des Index: " . $e->getMessage() . ".  Ein Backup der beschädigten Datei konnte nicht erstellt werden.", 0, $e);
                }
            }
            $this->indexData = [];
             throw new RuntimeException("Fehler beim Laden des Index: " . $e->getMessage() . ".  Ein Backup der beschädigten Datei wurde erstellt.", 0, $e); // Re-throw with more context
        } finally {
            flock($handle, LOCK_UN); // Release the lock
            fclose($handle);
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
            $tmpFile = $indexFile . '.tmp'; // Use a temporary file

            try {
                // Write to the temporary file
                $encoded = json_encode($this->indexData, JSON_THROW_ON_ERROR);
                $result = file_put_contents($tmpFile, $encoded); // No LOCK_EX needed here

                if ($result === false) {
                    throw new RuntimeException("Index-Datei konnte nicht geschrieben werden.");
                }

                // Atomically replace the old index file
                if (!rename($tmpFile, $indexFile)) {
                    throw new RuntimeException("Temporäre Indexdatei konnte nicht umbenannt werden.");
                }

                $this->indexDirty = false;
            } catch (Throwable $e) {
                // Cleanup:  Delete the temp file if something went wrong.
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
        // Immer speichern
        $this->commitIndex();
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
        // Immer speichern
        $this->commitIndex();
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
        //Immer speichern
        $this->commitIndex();
    }
}