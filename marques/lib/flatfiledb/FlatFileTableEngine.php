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

    private array $dataCache = [];
    private int $maxCacheSize = 100;
    private array $schema = [];
    private array $indexedFields = []; // NEW: Keep track of indexed fields

    /**
     * @param FlatFileConfig $config Konfiguration der Tabelle
     */
    public function __construct(FlatFileConfig $config)
    {
        $this->config = $config;
        $this->fileManager = new FlatFileFileManager($config, 9);
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
     * Creates a secondary index on a field.
     *
     * @param string $fieldName The name of the field to index.
     */
    public function createIndex(string $fieldName): void
    {
        $this->indexBuilder->createIndex($fieldName);
        $this->indexedFields[] = $fieldName; // Keep track of indexed fields

        // Build the index initially by scanning all records
        foreach ($this->fileManager->readRecordsGenerator() as $offset => $record) {
            if (isset($record[$fieldName]) && isset($record['id']) && !isset($record['_deleted'])) {
                 $this->indexBuilder->setSecondaryIndex($fieldName, (string)$record[$fieldName], (int)$record['id']);
            }
        }

        $this->indexBuilder->commitSecondaryIndex($fieldName);
    }


    /**
     * Drops a secondary index.
     * @param string $fieldName
     */
    public function dropIndex(string $fieldName): void {
        $this->indexBuilder->dropIndex($fieldName);
        if (($key = array_search($fieldName, $this->indexedFields)) !== false) {
            unset($this->indexedFields[$key]); // Remove from tracked fields
        }
    }

    /**
     * Fügt einen neuen Datensatz ein.
     *
     * @param array $data Datensatzfelder
     * @return int  The new record ID.
     * @throws InvalidArgumentException bei ungültiger ID oder Daten
     * @throws RuntimeException bei Schreibfehlern
     */
    public function insertRecord(array $data): int
    {
        $measurement = FlatFileDBStatistics::measurePerformance(function() use ($data) {
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

            // Update secondary indexes
            $this->updateSecondaryIndexesOnInsert($recordId, $data);

            return $recordId; // Neue ID zurückgeben

        });

        // NEU: Performance-Dauer für INSERT speichern
        FlatFileDBStatistics::recordPerformance('INSERT', $measurement['duration']);

        return $measurement['result'];
    }


    private function updateSecondaryIndexesOnInsert(int $recordId, array $data): void
    {
        foreach ($this->indexedFields as $fieldName) {
            if (isset($data[$fieldName])) {
                $this->indexBuilder->setSecondaryIndex($fieldName, (string)$data[$fieldName], $recordId);
            }
        }
    }


    /**
     * Aktualisiert einen bestehenden Datensatz.
     *
     * @param int $recordId ID des Datensatzes
     * @param array $newData Neue Datensatzfelder
     * @return bool True bei Erfolg, false wenn Datensatz nicht existiert
     * @throws RuntimeException bei Schreibfehlern
     */
    public function updateRecord(int $recordId, array $newData): bool
    {
        $measurement = FlatFileDBStatistics::measurePerformance(function() use ($recordId, $newData) {

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

                // Update secondary indexes
                $this->updateSecondaryIndexesOnUpdate($recordId, $oldData, $newData);

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
        });

        FlatFileDBStatistics::recordPerformance('UPDATE', $measurement['duration']);
    
        return $measurement['result'];
    }


    private function updateSecondaryIndexesOnUpdate(int $recordId, array $oldData, array $newData): void
    {
        foreach ($this->indexedFields as $fieldName) {
            $oldValue = $oldData[$fieldName] ?? null;
            $newValue = $newData[$fieldName] ?? null;

            if ((string)$oldValue !== (string)$newValue) { // Compare as strings
                // Remove old index entry
                if ($oldValue !== null) {
                    $this->indexBuilder->removeSecondaryIndex($fieldName, (string)$oldValue, $recordId);
                }
                // Add new index entry
                if ($newValue !== null) {
                    $this->indexBuilder->setSecondaryIndex($fieldName, (string)$newValue, $recordId);
                }
            }
        }
    }

    /**
     * Löscht einen Datensatz.
     *
     * @param int $recordId ID des Datensatzes
     * @return bool True bei Erfolg, false wenn Datensatz nicht existiert
     * @throws RuntimeException bei Schreibfehlern
     */
    public function deleteRecord(int $recordId): bool
    {
        $measurement = FlatFileDBStatistics::measurePerformance(function() use ($recordId) {

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

                // Update secondary indexes (remove all entries for this record)
                $this->updateSecondaryIndexesOnDelete($recordId, $oldData);

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
        });

        FlatFileDBStatistics::recordPerformance('DELETE', $measurement['duration']);
    
        return $measurement['result'];
    }


    private function updateSecondaryIndexesOnDelete(int $recordId, array $oldData): void
    {
        foreach ($this->indexedFields as $fieldName) {
            if (isset($oldData[$fieldName])) {
                $this->indexBuilder->removeSecondaryIndex($fieldName, (string)$oldData[$fieldName], $recordId);
            }
        }
    }

    /**
     * Liest einen Datensatz.
     *
     * @param int $recordId ID des Datensatzes
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
     * @param array $whereConditions Array of conditions.  Each condition is an array:
     *                              ['field' => 'fieldName', 'operator' => '=', 'value' => 'someValue']
     *                              Supported operators: '=', '!=', '>', '<', '>=', '<='
     * @param int $limit Maximale Anzahl der zurückgegebenen Datensätze (0 = alle)
     * @param int $offset Überspringt die ersten n passenden Datensätze
     * @return array Liste der passenden Datensätze
     */
    public function findRecords(array $whereConditions, int $limit = 0, int $offset = 0, ?int $id = null): array
    {
        $measurement = FlatFileDBStatistics::measurePerformance(function() use ($whereConditions, $limit, $offset, $id) {
    
            $results = [];

            // Direct ID lookup (fastest)
            if ($id !== null) {
                $record = $this->selectRecord($id);
                if ($record !== null && $this->recordMatchesConditions($record, $whereConditions)) {
                    $results[] = $record;
                }
                return $results;
            }

            // 1. Find candidate record IDs using indexes, if possible.
            //    We'll optimize for equality checks on indexed fields.
            $candidateIds = null;

            // Find the first equality condition on an indexed field, if any.
            $indexedEqualityCondition = null;
            foreach ($whereConditions as $condition) {
                if ($condition['operator'] === '=' && in_array($condition['field'], $this->indexedFields)) {
                    $indexedEqualityCondition = $condition;
                    break; // Use the first one we find
                }
            }

            // If we have an indexed equality condition, use it to get initial candidates.
            if ($indexedEqualityCondition) {
                $field = $indexedEqualityCondition['field'];
                $value = (string)$indexedEqualityCondition['value'];
                $candidateIds = $this->indexBuilder->getRecordIdsByFieldValue($field, $value);

                // If the index returns no results, we can return early.
                if (empty($candidateIds)) {
                    return [];
                }
            }


            // 2. Filter based on candidate IDs (if we have any) or a full scan.
            $count = 0;
            $skipped = 0;

            if ($candidateIds !== null) {
                // Use candidate IDs (from index)
                foreach ($candidateIds as $recordId) {
                    if ($offset > 0 && $skipped < $offset) {
                        $skipped++;
                        continue;
                    }

                    $record = $this->selectRecord($recordId); // Efficient lookup by ID

                    // MUST check ALL conditions, even if using an index.
                    if ($record !== null && $this->recordMatchesConditions($record, $whereConditions)) {
                        $results[] = $record;
                        $count++;
                        if ($limit > 0 && $count >= $limit) {
                            break;
                        }
                    }
                }
            } else {
                // Full table scan (no index used, or no equality on indexed field)
                foreach ($this->fileManager->readRecordsGenerator() as $record) {
                    if ($offset > 0 && $skipped < $offset) {
                        $skipped++;
                        continue;
                    }

                    if ($this->recordMatchesConditions($record, $whereConditions)) {
                        $results[] = $record;
                        $count++;
                        if ($limit > 0 && $count >= $limit) {
                            break;
                        }
                    }
                }
            }

            return $results;
        });

        FlatFileDBStatistics::recordPerformance('FIND', $measurement['duration']);
    
        return $measurement['result'];
    }

    /**
     * Fügt mehrere Datensätze ein, ohne bei jedem Datensatz den Index zu committen.
     * Stattdessen werden die Index-Änderungen in einem temporären Puffer gesammelt und
     * nach Abschluss des Bulk-Vorgangs in einem Schritt in den Index übernommen.
     *
     * @param array $records Liste der Datensätze (jeder Datensatz als assoziatives Array)
     * @return array Liste der zurückgegebenen Record-IDs oder Fehlerinformationen pro Datensatz.
    */
    public function bulkInsertRecords(array $records): array
    {
        $measurement = FlatFileDBStatistics::measurePerformance(function() use ($records) {
            $results = [];
            $tempIndex = []; // Puffer für neue Index-Einträge

            foreach ($records as $record) {
                try {
                    // Neuer Datensatz: ID und Metadaten setzen
                    $recordId = $this->indexBuilder->getNextId();
                    $record['id'] = $recordId;
                    $record['created_at'] = time();
                    $record['_deleted'] = false;
                    
                    // Schema-Validierung falls definiert
                    if (!empty($this->schema)) {
                        FlatFileValidator::validateData(
                            $record,
                            $this->schema['requiredFields'] ?? [],
                            $this->schema['fieldTypes'] ?? []
                        );
                    }
                    
                    // Datensatz in Datei anhängen (verwenden persistenter Handles, etc.)
                    $offset = $this->fileManager->appendRecord($record);
                    
                    // Neuer Index-Eintrag wird in den temporären Puffer geschrieben
                    $tempIndex[$recordId] = $offset;
                    
                    // Transaktionslog schreiben und Cache aktualisieren
                    $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_INSERT, (string)$recordId, $record);
                    $this->addToCache((string)$recordId, $record);
                    
                    // Sekundäre Indizes aktualisieren
                    $this->updateSecondaryIndexesOnInsert($recordId, $record);
                    
                    $results[] = $recordId;
                } catch (Throwable $e) {
                    $results[] = ['error' => $e->getMessage()];
                }
            }

            // Bulk-Commit: Den aktuellen Index abrufen und mit den neuen Einträgen zusammenführen.
            $currentIndex = $this->indexBuilder->getCurrentIndex(); // Neuer Methodenteil in der Index-Engine
            $mergedIndex = array_merge($currentIndex, $tempIndex);
            $this->indexBuilder->updateIndex($mergedIndex);

            return $results;
        });

        FlatFileDBStatistics::recordPerformance('BULK_INSERT', $measurement['duration']);
        return $measurement['result'];
    }

    /**
     * Aktualisiert mehrere Datensätze als Bulk-Operation.
     * Es werden alle Index-Änderungen zunächst in einem temporären Puffer gesammelt,
     * und erst am Ende des Bulk-Vorgangs wird der Index einmal komplett aktualisiert.
     *
     * Erwartet ein Array, in dem jedes Element ein assoziatives Array mit den Schlüsseln
     * 'recordId' (int) und 'newData' (array) ist.
     *
     * @param array $updates Liste der Updates.
     * @return array Liste der Ergebnisse (true bei Erfolg oder Fehlermeldungen).
     */
    public function bulkUpdateRecords(array $updates): array
    {
        $measurement = FlatFileDBStatistics::measurePerformance(function() use ($updates) {
            $results = [];
            $tempIndex = []; // Puffer für neue Offsets der aktualisierten Datensätze

            foreach ($updates as $update) {
                if (!isset($update['recordId'], $update['newData'])) {
                    $results[] = ['error' => 'Missing recordId or newData'];
                    continue;
                }
                try {
                    $recordId = (int)$update['recordId'];
                    $oldOffset = $this->indexBuilder->getIndexOffset($recordId);
                    if ($oldOffset === null) {
                        $results[] = false;
                        continue;
                    }
                    $oldData = $this->fileManager->readRecordAtOffset($oldOffset);
                    if (!$oldData || !is_array($oldData) || !isset($oldData['id'])) {
                        $results[] = false;
                        continue;
                    }

                    // Vergleiche, ob Änderungen vorliegen (automatische Felder ignorieren)
                    $fieldsToIgnore = ['updated_at', 'created_at', '_deleted', 'deleted_at'];
                    $filteredOldData = array_diff_key($oldData, array_flip($fieldsToIgnore));
                    $filteredNewData = array_diff_key($update['newData'], array_flip($fieldsToIgnore));

                    if ($filteredOldData == $filteredNewData) {
                        $results[] = true;
                        continue;
                    }

                    // Markiere den alten Datensatz als gelöscht
                    $oldData['_deleted'] = true;
                    $oldData['deleted_at'] = time();
                    $this->fileManager->appendRecord($oldData);

                    // Erstelle den neuen Datensatz
                    $newData = $update['newData'];
                    $newData['id'] = $recordId;
                    $newData['created_at'] = $oldData['created_at'] ?? time();
                    $newData['updated_at'] = time();
                    $newOffset = $this->fileManager->appendRecord($newData);
                    
                    // Pufferung der neuen Offset-Zuordnung
                    $tempIndex[$recordId] = $newOffset;
                    
                    // Loggen, Cache aktualisieren und sekundäre Indizes anpassen
                    $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_UPDATE, (string)$recordId, $newData);
                    $this->addToCache((string)$recordId, $newData);
                    $this->updateSecondaryIndexesOnUpdate($recordId, $oldData, $newData);

                    $results[] = true;
                } catch (Throwable $e) {
                    $results[] = ['error' => $e->getMessage()];
                }
            }
            // Am Ende: Holen des aktuellen Index und Mergen mit den neuen Änderungen
            $currentIndex = $this->indexBuilder->getCurrentIndex();
            $mergedIndex = array_merge($currentIndex, $tempIndex);
            $this->indexBuilder->updateIndex($mergedIndex);

            return $results;
        });

        FlatFileDBStatistics::recordPerformance('BULK_UPDATE', $measurement['duration']);
        return $measurement['result'];
    }

    /**
     * Löscht mehrere Datensätze als Bulk-Operation.
     * Statt bei jedem Löschvorgang sofort den Index anzupassen, werden
     * alle zu löschenden Record-IDs gesammelt und am Ende in einem Schritt aus dem Index entfernt.
     *
     * @param array $recordIds Liste der zu löschenden Record-IDs.
     * @return array Liste der Ergebnisse (true bei Erfolg oder Fehlermeldungen).
     */
    public function bulkDeleteRecords(array $recordIds): array
    {
        $measurement = FlatFileDBStatistics::measurePerformance(function() use ($recordIds) {
            $results = [];
            $deletedIds = []; // Puffer für alle gelöschten Record-IDs

            foreach ($recordIds as $recordId) {
                try {
                    $recordId = (int)$recordId;
                    $oldOffset = $this->indexBuilder->getIndexOffset($recordId);
                    if ($oldOffset === null) {
                        $results[] = false;
                        continue;
                    }
                    $oldData = $this->fileManager->readRecordAtOffset($oldOffset);
                    if (!$oldData || (($oldData['_deleted'] ?? false) === true)) {
                        $results[] = false;
                        continue;
                    }
                    // Schreibe den Löschmarker in die Datei
                    $oldData['_deleted'] = true;
                    $oldData['deleted_at'] = time();
                    $this->fileManager->appendRecord($oldData);
                    
                    // Merke die gelöschte Record-ID zur späteren Index-Anpassung
                    $deletedIds[] = $recordId;
                    
                    $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_DELETE, (string)$recordId);
                    unset($this->dataCache[(string)$recordId]);
                    $this->updateSecondaryIndexesOnDelete($recordId, $oldData);

                    $results[] = true;
                } catch (Throwable $e) {
                    $results[] = ['error' => $e->getMessage()];
                }
            }
            // Nach Abschluss: Aktualisiere den Index, indem alle gelöschten IDs entfernt werden.
            $currentIndex = $this->indexBuilder->getCurrentIndex();
            foreach ($deletedIds as $rid) {
                unset($currentIndex[$rid]);
            }
            $this->indexBuilder->updateIndex($currentIndex);

            return $results;
        });

        FlatFileDBStatistics::recordPerformance('BULK_DELETE', $measurement['duration']);
        return $measurement['result'];
    }

    private function recordMatchesConditions(array $record, array $whereConditions): bool {
        foreach ($whereConditions as $condition) {
            $field = $condition['field'];
            $operator = $condition['operator'];
            $value = $condition['value'];

            if (!isset($record[$field])) {
                return false; // Field doesn't exist in record
            }

            // Type coercion for comparison
            $recordValue = $record[$field];
            if (is_int($recordValue)) {
                $value = (int)$value;  // Cast to int if record value is int
            } elseif (is_float($recordValue)) {
                $value = (float)$value; // Cast to float if record value is float
            } else {
                $value = (string)$value; // Otherwise, cast to string
            }

            switch ($operator) {
                case '=':
                    if ($recordValue != $value) { return false; }
                    break;
                case '!=':
                    if ($recordValue == $value) { return false; }
                    break;
                case '>':
                    if ($recordValue <= $value) { return false; }
                    break;
                case '<':
                    if ($recordValue >= $value) { return false; }
                    break;
                case '>=':
                    if ($recordValue < $value) { return false; }
                    break;
                case '<=':
                    if ($recordValue > $value) { return false; }
                    break;
                default:
                    throw new InvalidArgumentException("Unsupported operator: $operator");
            }
        }
        return true; // All conditions matched
    }

    /**
     * Kompaktiert die Tabelle und baut den Index neu auf.
     */
    public function compactTable(): void
    {
        try {
            $this->commitIndex();
            $this->indexBuilder->commitAllSecondaryIndexes(); // Commit secondary indexes

            $newIndex = [];
            $newSecondaryIndexes = []; // To store new secondary indexes

            $this->fileManager->compactData($newIndex);

            // 1. Rebuild Primary Index (as before)
            $newIndex = array_combine(
                array_map('intval', array_keys($newIndex)),
                array_values($newIndex)
            );
            $this->indexBuilder->updateIndex($newIndex);

            // 2. Rebuild Secondary Indexes
            foreach ($this->indexedFields as $fieldName) {
                $newSecondaryIndexes[$fieldName] = []; // Start with an empty index

                // Iterate through the *new* primary index (after compaction)
                foreach ($newIndex as $recordId => $offset) {
                    $record = $this->fileManager->readRecordAtOffset($offset);
                    // Check if the record has the field and if it's not deleted
                    if (isset($record[$fieldName]) && !empty($record['id']) && empty($record['_deleted'])) {
                        $value = (string)$record[$fieldName]; // Ensure string key
                        // Add to the new secondary index
                        if (!isset($newSecondaryIndexes[$fieldName][$value])) {
                            $newSecondaryIndexes[$fieldName][$value] = [];
                        }
                        $newSecondaryIndexes[$fieldName][$value][] = (int)$record['id']; // Use int ID
                    }
                }

                $this->indexBuilder->updateSecondaryIndex($fieldName, $newSecondaryIndexes[$fieldName]);
            }


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
     * Commits all secondary indexes.  Added for completeness.
     */
    public function commitAllSecondaryIndexes(): void
    {
         $this->indexBuilder->commitAllSecondaryIndexes();
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
        $this->commitAllSecondaryIndexes();

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

        // Secondary index files
        foreach ($this->indexedFields as $fieldName) {
             $indexFile = $this->indexBuilder->getSecondaryIndexFilePath($fieldName); //use the correct method
            $backupIndex = $backupDir . '/' . basename($indexFile) . '.' . $timestamp;
            if (copy($indexFile, $backupIndex)) {
                $backupFiles["index_$fieldName"] = $backupIndex;
            } else {
                throw new RuntimeException("Secondary index file '$indexFile' could not be backed up.");
            }
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

        // Delete secondary index files
        foreach ($this->indexedFields as $fieldName) {
            $this->indexBuilder->dropIndex($fieldName);
        }

        // Setze den internen Index und Cache zurück
        $this->indexBuilder->updateIndex([]);
        $this->clearCache();
        $this->indexedFields = [];
    }

    /**
     * Gibt die Anzahl der aktuell indizierten Datensätze zurück.
     *
     * @return int Anzahl der Datensätze
     */
    public function getRecordCount(): int
    {
        return $this->indexBuilder->count();
    }
}