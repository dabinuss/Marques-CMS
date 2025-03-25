<?php
declare(strict_types=1);

namespace FlatFileDB;

use RuntimeException;
use JsonException;
use Throwable;

class FlatFileIndexBuilder
{
    private array $indexData = []; // Primary index (id -> offset)
    private array $secondaryIndexes = []; // Field name -> [value -> [recordId]]
    private array $secondaryIndexesDirty = []; // Track which secondary indexes are dirty.  CORRECTED: Now an array
    private FlatFileConfig $config;
    private int $nextId = 1;
    private string $tableName; // Store table name for file naming
    private bool $indexDirty = false;

    public function __construct(FlatFileConfig $config)
    {
        $this->config = $config;
        // Extract table name from data file path
        $this->tableName = $this->getTableNameFromConfig();
        $this->loadIndex();
        $this->nextId = empty($this->indexData) ? 1 : max(array_keys($this->indexData)) + 1;
        $this->loadSecondaryIndexes(); // Load secondary indexes
    }

    public function getCurrentIndex(): array
    {
        return $this->indexData;
    }

    private function getTableNameFromConfig(): string
    {
        $dataFile = $this->config->getDataFile();
        $baseName = basename($dataFile, FlatFileDBConstants::DATA_FILE_EXTENSION);
        // Extract table name, handling potential suffixes like "_data"
        return preg_replace('/_data$/', '', $baseName);
    }

    private function loadIndex(): void
    {
        // ... (Existing loadIndex method, no changes needed here) ...
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

            if ($this->indexData === null) {
                throw new JsonException("Ungültiges Indexdateiformat (json_decode returned null)");
            }

            if (!is_array($this->indexData)) {
                throw new JsonException("Ungültiges Indexdateiformat");
            }

            $this->indexData = array_combine(
                array_map('intval', array_keys($this->indexData)),
                array_values($this->indexData)
            );

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

    public function getNextId(): int
    {
        $lockFile = $this->config->getIndexFile() . '.lock';
        $lockHandle = fopen($lockFile, 'w');
        if (!$lockHandle) { throw new RuntimeException("Could not create index lock file."); }
        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            throw new RuntimeException("Could not acquire lock for index.");
        }

        try {
            $this->loadIndex(); // Index innerhalb des Locks neu laden!
            $id = $this->nextId;
            $this->nextId++;
            $this->indexDirty = true; // CORRECTED:  Use $this->indexDirty
            $this->commitIndex(); // Index innerhalb des Locks speichern!
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            @unlink($lockFile); // Lock-Datei löschen
        }
        return $id;
    }

    public function commitIndex(): void
    {
        
        if ($this->indexDirty) {
            $indexFile = $this->config->getIndexFile();
            $tmpFile = $indexFile . '.tmp'; // Use a temporary file

            try {
                // Write to the temporary file
                $encoded = json_encode($this->indexData, JSON_THROW_ON_ERROR | JSON_NUMERIC_CHECK);
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

    public function setIndex(int $recordId, int $offset): void
    {
         // ... (Existing setIndex method, no changes needed here) ...
        $this->indexData[$recordId] = $offset;
        $this->indexDirty = true;
        $this->commitIndex();
    }

    public function removeIndex(int $recordId): void
    {
        // ... (Existing removeIndex method, no changes needed here) ...
        unset($this->indexData[$recordId]);
        $this->indexDirty = true;
        $this->commitIndex();
    }

    public function getIndexOffset(int $recordId): ?int
    {
        // ... (Existing getIndexOffset method, no changes needed here) ...
        return $this->indexData[$recordId] ?? null;
    }

    public function getAllKeys(): array
    {
        // ... (Existing getAllKeys method, no changes needed here) ...
        return array_keys($this->indexData); // Gibt Integer-Schlüssel zurück
    }

    public function hasKey(int $recordId): bool
    {
        // ... (Existing hasKey method, no changes needed here) ...
        return isset($this->indexData[$recordId]);
    }

    public function count(): int
    {
        // ... (Existing count method, no changes needed here) ...
        return count($this->indexData);
    }

     public function updateIndex(array $newIndex): void
    {
        // ... (Existing updateIndex method, no changes needed here) ...
        $this->indexData = array_combine(
            array_map('intval', array_keys($newIndex)),
            array_values($newIndex)
        );
        $this->indexDirty = true;
        $this->commitIndex();
    }


    // --- Secondary Index Methods ---

    public function getSecondaryIndexFilePath(string $fieldName): string
    {
        return "{$this->config->getDataFile()}_index_{$fieldName}" . FlatFileDBConstants::INDEX_FILE_EXTENSION;
    }

    private function loadSecondaryIndexes(): void
    {
        // No explicit loading here; lazy-load on demand
        $this->secondaryIndexes = [];
        $this->secondaryIndexesDirty = [];  // Already correctly initialized as an array
    }


     public function createIndex(string $fieldName): void
    {
        if (isset($this->secondaryIndexes[$fieldName])) {
            return; // Index already exists
        }

        $this->secondaryIndexes[$fieldName] = [];
        $this->secondaryIndexesDirty[$fieldName] = true; // Correct: Setting the array element
        $this->commitSecondaryIndex($fieldName); // Create the empty index file
    }


    private function loadSecondaryIndex(string $fieldName): void
    {
       $indexFile = $this->getSecondaryIndexFilePath($fieldName);

        if (!file_exists($indexFile)) {
            $this->secondaryIndexes[$fieldName] = []; // Empty index
            return;
        }

        $handle = fopen($indexFile, 'rb');
        if (!$handle) {
            throw new RuntimeException("Secondary index file '$indexFile' could not be opened.");
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Could not acquire lock for secondary index file '$indexFile'.");
            }

            $content = '';
            while (!feof($handle)) {
                $content .= fread($handle, 8192);
            }

            if ($content === '') {
                $this->secondaryIndexes[$fieldName] = []; // Empty file
                return;
            }

            $indexData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if ($indexData === null || !is_array($indexData)) {
                throw new JsonException("Invalid secondary index file format for '$indexFile'.");
            }

            // Ensure keys are strings and values are arrays of integers
            $validatedIndexData = [];
            foreach ($indexData as $value => $ids) {
                if (!is_array($ids)) {
                    throw new JsonException("Invalid secondary index file format for '$indexFile' (values must be arrays).");
                }
                $validatedIndexData[(string)$value] = array_map('intval', $ids);
            }

            $this->secondaryIndexes[$fieldName] = $validatedIndexData;

        } catch (JsonException $e) {
            $backupFile = $indexFile . '.corrupted.' . time();
            if (file_exists($indexFile)) {
               if(!rename($indexFile, $backupFile)){
                    throw new RuntimeException("Fehler beim Laden des Index: " . $e->getMessage() . ".  Ein Backup der beschädigten Datei konnte nicht erstellt werden.", 0, $e);
                }
            }
            $this->secondaryIndexes[$fieldName] = [];
            throw new RuntimeException("Error loading secondary index '$fieldName': " . $e->getMessage() . ".  A backup of the corrupted file was created.", 0, $e);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }


    public function commitSecondaryIndex(string $fieldName): void
    {
        if (!isset($this->secondaryIndexesDirty[$fieldName]) || !$this->secondaryIndexesDirty[$fieldName]) {
            return;
        }
        if (!isset($this->secondaryIndexes[$fieldName])){
            $this->loadSecondaryIndex($fieldName);
        }

        $indexFile = $this->getSecondaryIndexFilePath($fieldName);
        $tmpFile = $indexFile . '.tmp';

        try {
            $encoded = json_encode($this->secondaryIndexes[$fieldName], JSON_THROW_ON_ERROR | JSON_NUMERIC_CHECK);
            $result = file_put_contents($tmpFile, $encoded);

            if ($result === false) {
                throw new RuntimeException("Secondary index file '$indexFile' could not be written.");
            }

            if (!rename($tmpFile, $indexFile)) {
                throw new RuntimeException("Temporary secondary index file '$tmpFile' could not be renamed to '$indexFile'.");
            }

            $this->secondaryIndexesDirty[$fieldName] = false; // Correct: Setting the array element
        } catch (Throwable $e) {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
            throw new RuntimeException("Error saving secondary index '$fieldName': " . $e->getMessage(), 0, $e);
        }
    }


    public function setSecondaryIndex(string $fieldName, string $value, int $recordId): void
    {
        if (!isset($this->secondaryIndexes[$fieldName])) {
            $this->loadSecondaryIndex($fieldName);
        }

        // Ensure the value exists as a key, and it's an array
        if (!isset($this->secondaryIndexes[$fieldName][$value])) {
            $this->secondaryIndexes[$fieldName][$value] = [];
        }

        // Add the recordId to the array, if not already exists.
        if (!in_array($recordId, $this->secondaryIndexes[$fieldName][$value], true)) {
            $this->secondaryIndexes[$fieldName][$value][] = $recordId;
            $this->secondaryIndexesDirty[$fieldName] = true;  // Correct: Setting the array element
            $this->commitSecondaryIndex($fieldName); // Commit after each change
        }
    }

    public function removeSecondaryIndex(string $fieldName, string $value, int $recordId): void
    {
        if (!isset($this->secondaryIndexes[$fieldName])) {
            $this->loadSecondaryIndex($fieldName);
        }

        if (isset($this->secondaryIndexes[$fieldName][$value])) {
            $index = array_search($recordId, $this->secondaryIndexes[$fieldName][$value], true);
            if ($index !== false) {
                array_splice($this->secondaryIndexes[$fieldName][$value], $index, 1);
                // If the array is now empty, remove the key
                if (empty($this->secondaryIndexes[$fieldName][$value])) {
                    unset($this->secondaryIndexes[$fieldName][$value]);
                }
                $this->secondaryIndexesDirty[$fieldName] = true; // Correct: Setting the array element
                $this->commitSecondaryIndex($fieldName); // Commit after each change
            }
        }
    }


    public function getRecordIdsByFieldValue(string $fieldName, string $value): array
    {
        if (!isset($this->secondaryIndexes[$fieldName])) {
            $this->loadSecondaryIndex($fieldName);
        }

        return $this->secondaryIndexes[$fieldName][$value] ?? [];
    }

    public function removeAllSecondaryIndexesForRecord(int $recordId): void
    {
        foreach (array_keys($this->secondaryIndexes) as $fieldName) {
            $this->removeRecordFromSecondaryIndex($fieldName, $recordId);
        }
    }

    private function removeRecordFromSecondaryIndex(string $fieldName, int $recordId): void
    {
        // No need to load - it will be lazy-loaded if needed.
        if(isset($this->secondaryIndexes[$fieldName])){
            foreach ($this->secondaryIndexes[$fieldName] as $value => $ids) {
                $index = array_search($recordId, $ids, true);
                if ($index !== false) {
                    array_splice($ids, $index, 1);
                    if (empty($ids)) {
                        unset($this->secondaryIndexes[$fieldName][$value]);
                    }
                    $this->secondaryIndexes[$fieldName][$value] = $ids; // Reassign
                    $this->secondaryIndexesDirty[$fieldName] = true; // Correct: Setting the array element
                    $this->commitSecondaryIndex($fieldName); // Commit after each change
                }
            }
        }
    }


    public function commitAllSecondaryIndexes(): void
    {
        foreach (array_keys($this->secondaryIndexesDirty) as $fieldName) { // Corrected: Use array_keys
            $this->commitSecondaryIndex($fieldName);
        }
    }

    public function updateSecondaryIndex(string $fieldName, array $newIndex): void {

        $validatedIndexData = [];
        foreach ($newIndex as $value => $ids) {
            if (!is_array($ids)) {
                continue; // Skip invalid entries
            }
            $validatedIndexData[(string)$value] = array_map('intval', $ids);
        }

        $this->secondaryIndexes[$fieldName] = $validatedIndexData;
        $this->secondaryIndexesDirty[$fieldName] = true; // Correct: Setting the array element
        $this->commitSecondaryIndex($fieldName);
    }

    public function dropIndex(string $fieldName): void
    {
        $indexFile = $this->getSecondaryIndexFilePath($fieldName);
        if (file_exists($indexFile)) {
            if (!unlink($indexFile)) {
                throw new RuntimeException("Could not delete secondary index file: " . $indexFile);
            }
        }
        unset($this->secondaryIndexes[$fieldName]);
        unset($this->secondaryIndexesDirty[$fieldName]); // Correct: Unsetting the array element
    }
}