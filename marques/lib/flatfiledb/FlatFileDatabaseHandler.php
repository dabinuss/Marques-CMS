<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;
use RuntimeException;
use JsonException;
use Throwable; // Import Throwable for broader exception catching

/**
 * Bietet eine Fluent-Interface (Query Builder Stil) für häufige CRUD-Operationen
 * auf einer einzelnen Tabelle einer FlatFileDatabase.
 *
 * Diese Klasse vereinfacht das Erstellen, Lesen, Aktualisieren und Löschen von Datensätzen,
 * indem sie die direkten Aufrufe an die FlatFileTableEngine abstrahiert.
 *
 * **Abgedeckte Operationen:**
 * - Auswahl der Tabelle (`table`)
 * - Filterung (`where`)
 * - Setzen von Daten für Insert/Update (`data`)
 * - Einfügen von einzelnen oder mehreren Datensätzen (`insert`)
 * - Aktualisieren von Datensätzen basierend auf Bedingungen (`update`)
 * - Löschen von Datensätzen basierend auf Bedingungen (`delete`)
 * - Suchen und Abrufen von Datensätzen (`find`, `first`, `exists`, `count`)
 * - Feldauswahl (`select`)
 * - Sortierung (`orderBy`) - Wird in PHP nach dem Abruf durchgeführt!
 * - Limitierung und Paginierung (`limit`, `offset`)
 *
 * **Nicht abgedeckte Operationen (direkt über FlatFileTableEngine):**
 * - Indexverwaltung (`createIndex`, `dropIndex`)
 * - Schema-Management (`setSchema`)
 * - Tabellen-Management (`compactTable`, `clearTable`)
 * - Backup (`backup`)
 * - Cache-Kontrolle (`clearCache`, `setCacheSize`)
 * - Transaktionslog-Zugriff (`readLog`, `rotateLog`)
 *
 * Zugriff auf diese erweiterten Funktionen erfolgt über:
 * `$db->table('tableName')->methodName();`
 */
class FlatFileDatabaseHandler
{
    private ?string $tableName = null;
    private array $conditions = [];
    private ?array $data = null;
    private int $limit = 0; // 0 means no limit
    private int $offset = 0;
    private ?array $orderBy = null; // Format: ['field' => string, 'direction' => 'ASC'|'DESC']
    private array $selectFields = []; // Empty array means select all fields ('*')

    public function __construct(private readonly FlatFileDatabase $db)
    {
    }

    /**
     * Wählt die Tabelle aus und setzt den internen Zustand für eine neue Abfrage zurück.
     *
     * @param string $tableName Der Name der zu verwendenden Tabelle.
     * @return self Fluent Interface.
     * @throws RuntimeException Wenn die Tabelle nicht in der FlatFileDatabase registriert ist.
     */
    public function table(string $tableName): self
    {
        //error_log("Handler table() called for: " . $tableName . " (Object Hash: " . spl_object_hash($this) . ")");
        $this->resetState(); // Reset first
        try {
            $this->db->table($tableName); // Check if table exists/get engine
            $this->tableName = $tableName; // Set table name
            // error_log("Handler table name set to: " . $this->tableName . " (Object Hash: " . spl_object_hash($this) . ")");
        } catch (Throwable $e) {
            error_log("ERROR in Handler table() getting engine for " . $tableName . ": " . $e->getMessage() . " (Object Hash: " . spl_object_hash($this) . ")");
            // Re-throw the exception to maintain original behavior
            throw $e;
        }
        return $this;
    }

    /**
     * Fügt eine Where-Bedingung zur Filterung hinzu.
     * Mehrere `where`-Aufrufe werden mit AND verknüpft.
     *
     * @param string $field Der Feldname.
     * @param string $operator Der Vergleichsoperator (z.B. '=', '!=', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL', '===', '!==').
     * @param mixed $value Der Wert für den Vergleich. Bei 'IN'/'NOT IN' ein Array, bei 'IS NULL'/'IS NOT NULL' wird der Wert ignoriert.
     * @return self Fluent Interface.
     * @throws InvalidArgumentException Wenn Feld oder Operator ungültig sind.
     */
    public function where(string $field, string $operator, mixed $value = null): self
    {
        $trimmedField = trim($field);
        if ($trimmedField === '') {
            throw new InvalidArgumentException("Feldname für 'where' darf nicht leer sein.");
        }
        $trimmedOperator = trim(strtoupper($operator));
        if ($trimmedOperator === '') {
            throw new InvalidArgumentException("Operator für 'where' darf nicht leer sein.");
        }
        // Hier könnte man noch die Operatoren validieren, aber die Engine macht das auch.

        // Bei IS NULL / IS NOT NULL ist der value irrelevant
        if ($trimmedOperator === 'IS NULL' || $trimmedOperator === 'IS NOT NULL') {
            $value = null;
        }

        $this->conditions[] = ['field' => $trimmedField, 'operator' => $trimmedOperator, 'value' => $value];
        return $this;
    }

    /**
     * Setzt die zu verarbeitenden Daten (für Insert oder Update).
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $data
     *        Einzelner Datensatz (assoziatives Array) oder Liste von Datensätzen für Bulk-Insert.
     * @return self Fluent Interface.
     */
    public function data(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Definiert, welche Felder zurückgegeben werden sollen.
     * Wenn nicht aufgerufen, werden alle Felder zurückgegeben (inklusive 'id').
     *
     * @param list<string> $fields Eine Liste der Feldnamen, die ausgewählt werden sollen.
     * @return self Fluent Interface.
     * @throws InvalidArgumentException Wenn $fields keine Liste von Strings ist.
     */
    public function select(array $fields): self
    {
        if (!array_is_list($fields)) {
            throw new InvalidArgumentException("select() erwartet eine Liste (list) von Feldnamen.");
        }
        $validatedFields = [];
        foreach ($fields as $field) {
            if (!is_string($field) || trim($field) === '') {
                throw new InvalidArgumentException("Feldnamen in select() müssen nicht-leere Strings sein.");
            }
            $validatedFields[] = trim($field);
        }
        // Entferne Duplikate
        $this->selectFields = array_unique($validatedFields);
        return $this;
    }

    /**
     * Definiert die Sortierreihenfolge der Ergebnisse.
     * WICHTIG: Die Sortierung erfolgt in PHP *nachdem* die Daten von der Engine abgerufen wurden.
     * Dies kann bei großen Datenmengen ineffizient sein.
     *
     * @param string $field Feldname, nach dem sortiert werden soll.
     * @param string $direction Sortierrichtung ('ASC' oder 'DESC').
     * @return self Fluent Interface.
     * @throws InvalidArgumentException Wenn Feld leer oder Richtung ungültig ist.
     */
    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $trimmedField = trim($field);
        if ($trimmedField === '') {
            throw new InvalidArgumentException("Sortierfeld darf nicht leer sein.");
        }
        $normalizedDirection = strtoupper(trim($direction));
        if (!in_array($normalizedDirection, ['ASC', 'DESC'])) {
            throw new InvalidArgumentException("Ungültige Sortierrichtung: '$direction'. Nur ASC oder DESC erlaubt.");
        }
        $this->orderBy = ['field' => $trimmedField, 'direction' => $normalizedDirection];
        return $this;
    }

    /**
     * Limitiert die Anzahl der zurückgegebenen Datensätze.
     *
     * @param int $count Maximale Anzahl der Datensätze. Muss >= 0 sein.
     * @return self Fluent Interface.
     * @throws InvalidArgumentException Wenn $count negativ ist.
     */
    public function limit(int $count): self
    {
        if ($count < 0) {
            throw new InvalidArgumentException("Limit darf nicht negativ sein.");
        }
        $this->limit = $count;
        return $this;
    }

    /**
     * Überspringt eine bestimmte Anzahl von Datensätzen (für Paginierung).
     *
     * @param int $skip Anzahl der zu überspringenden Datensätze. Muss >= 0 sein.
     * @return self Fluent Interface.
     * @throws InvalidArgumentException Wenn $skip negativ ist.
     */
    public function offset(int $skip): self
    {
        if ($skip < 0) {
            throw new InvalidArgumentException("Offset darf nicht negativ sein.");
        }
        $this->offset = $skip;
        return $this;
    }

    /**
     * Führt einen Insert aus.
     *
     * Falls $data eine Liste (mehrere Datensätze) ist, wird bulkInsertRecords verwendet,
     * ansonsten insertRecord.
     *
     * @return int|array<int, int|array{'error': string}> Neue Record-ID (Einzelinsert) oder Ergebnisarray (Bulk).
     *         Im Bulk-Ergebnis kann statt der ID ein Array `['error' => Fehlermeldung]` stehen.
     * @throws RuntimeException Wenn die Tabelle nicht ausgewählt oder keine Daten übergeben wurden, oder bei Engine-Fehlern.
     * @throws JsonException Bei JSON-Fehlern in der Engine.
     * @throws InvalidArgumentException Bei Validierungsfehlern in der Engine.
     */
    public function insert(): int|array
    {
        $this->ensureTableSelected();
        $this->ensureDataProvided("Insert");

        $engine = $this->db->table($this->tableName); // Holt die Engine (wirft Fehler, wenn nicht registriert)

        // Unterscheiden, ob es sich um einen Bulk-Insert handelt:
        // Die Engine-Methoden werfen Exceptions bei Fehlern.
        $result = array_is_list($this->data)
            ? $engine->bulkInsertRecords($this->data)
            : $engine->insertRecord($this->data);

        $this->resetState();
        return $result;
    }

    /**
     * Führt ein Update aus.
     *
     * Sucht mittels findRecords() alle betroffenen Datensätze anhand der Where-Bedingungen.
     * Bei einem Treffer wird ein Einzelupdate ausgeführt, bei mehreren ein Bulk-Update.
     *
     * @return bool True, wenn mindestens ein Datensatz erfolgreich aktualisiert wurde (oder keine Änderung nötig war), sonst false.
     * @throws RuntimeException Wenn die Tabelle nicht ausgewählt oder keine Daten übergeben wurden, oder bei Engine-Fehlern.
     * @throws JsonException Bei JSON-Fehlern in der Engine.
     * @throws InvalidArgumentException Bei Validierungsfehlern in der Engine.
     */
    public function update(): bool
    {
        $this->ensureTableSelected();
        $this->ensureDataProvided("Update");

        $engine = $this->db->table($this->tableName);

        // 1. Finde betroffene Datensätze (ohne Limit/Offset des Handlers!)
        $records = $engine->findRecords($this->conditions, 0, 0); // Finde *alle* passenden

        if (empty($records)) {
            $this->resetState();
            return false; // Nichts zu aktualisieren
        }

        $success = false; // Standardmäßig nicht erfolgreich

        try {
            if (count($records) === 1) {
                // Einzelupdate
                $recordId = reset($records)['id'];
                $updateResult = $engine->updateRecord($recordId, $this->data);
                // updateRecord gibt true zurück, wenn geändert, false wenn nicht gefunden oder keine Änderung.
                $success = $updateResult;
            } else {
                // Bulk-Update
                $updates = array_map(
                    fn($record) => ['recordId' => $record['id'], 'newData' => $this->data],
                    $records
                );

                $bulkResult = $engine->bulkUpdateRecords($updates);
                // Prüfe, ob *mindestens ein* Update erfolgreich war oder keine Änderung nötig war
                $success = $this->processBulkUpdateResult($bulkResult);
            }
        } catch (Throwable $e) {
            // Fange Fehler von updateRecord/bulkUpdateRecords ab und werfe sie weiter
            $this->resetState(); // Zustand trotzdem zurücksetzen
            throw $e;
        }

        $this->resetState();
        return $success;
    }

    /**
     * Führt ein Delete aus.
     *
     * Sucht mittels findRecords() alle betroffenen Datensätze anhand der Where-Bedingungen und löscht diese.
     * Bei mehreren Treffern wird bulkDeleteRecords verwendet.
     *
     * @return bool True, wenn mindestens ein Datensatz erfolgreich gelöscht wurde, sonst false.
     * @throws RuntimeException Wenn die Tabelle nicht ausgewählt wurde oder bei Engine-Fehlern.
     * @throws JsonException Bei JSON-Fehlern in der Engine.
     * @throws InvalidArgumentException Bei Validierungsfehlern in der Engine.
     */
    public function delete(): bool
    {
        $this->ensureTableSelected();
        $engine = $this->db->table($this->tableName);

        // 1. Finde betroffene Datensätze (ohne Limit/Offset des Handlers!)
        $records = $engine->findRecords($this->conditions, 0, 0); // Finde *alle* passenden

        if (empty($records)) {
            $this->resetState();
            return false; // Nichts zu löschen
        }

        $success = false; // Standardmäßig nicht erfolgreich

        try {
            if (count($records) === 1) {
                // Einzelnes Löschen
                $recordId = reset($records)['id'];
                $deleteResult = $engine->deleteRecord($recordId);
                // deleteRecord gibt true zurück, wenn gelöscht, false wenn nicht gefunden.
                $success = $deleteResult;
            } else {
                // Bulk-Löschen
                $ids = array_column($records, 'id');
                $bulkResult = $engine->bulkDeleteRecords($ids);
                // Prüfe, ob *mindestens ein* Löschvorgang erfolgreich war
                $success = $this->processBulkDeleteResult($bulkResult);
            }
        } catch (Throwable $e) {
            // Fange Fehler von deleteRecord/bulkDeleteRecords ab und werfe sie weiter
            $this->resetState(); // Zustand trotzdem zurücksetzen
            throw $e;
        }

        $this->resetState();
        return $success;
    }

    /**
     * Führt eine Suche aus und gibt die gefundenen Datensätze zurück.
     * Berücksichtigt `where`, `orderBy`, `limit`, `offset` und `select`.
     *
     * @return list<array<string, mixed>> Die gefundenen Datensätze (leere Liste, wenn nichts gefunden).
     * @throws RuntimeException Wenn die Tabelle nicht ausgewählt wurde oder bei Engine-Fehlern.
     * @throws InvalidArgumentException Bei ungültigen Bedingungen in der Engine.
     */
    public function find(): array
    {
        $this->ensureTableSelected();
        $engine = $this->db->table($this->tableName);

        // 1. Hole Datensätze von der Engine unter Berücksichtigung von Limit/Offset
        // Die Engine wirft Exceptions bei Fehlern (z.B. ungültige Bedingungen)
        $records = $engine->findRecords($this->conditions, $this->limit, $this->offset);

        // 2. Sortiere die Ergebnisse (in PHP), falls orderBy gesetzt ist
        if ($this->orderBy !== null && !empty($records)) {
            $field = $this->orderBy['field'];
            $direction = $this->orderBy['direction'] === 'ASC' ? 1 : -1;

            usort($records, function ($a, $b) use ($field, $direction) {
                // Extrahiere Werte, behandle nicht vorhandene Felder als null
                $valA = $a[$field] ?? null;
                $valB = $b[$field] ?? null;

                // Vergleich mit null-Handling und Typ-Sensitivität (spaceship operator)
                return ($valA <=> $valB) * $direction;
            });
        }

        // 3. Wähle spezifische Felder aus (in PHP), falls selectFields gesetzt ist
        if (!empty($this->selectFields) && !empty($records)) {
            $selectedRecords = [];
            // Erstelle ein Set der angeforderten Felder für schnellen Check
            $requestedFieldsSet = array_flip($this->selectFields);

            foreach ($records as $record) {
                $selectedRecord = [];
                foreach ($this->selectFields as $selectField) {
                    // Füge nur die explizit angeforderten Felder hinzu
                    if (array_key_exists($selectField, $record)) {
                        $selectedRecord[$selectField] = $record[$selectField];
                    } else {
                        $selectedRecord[$selectField] = null; // Feld existiert nicht im Datensatz
                    }
                }

                // Füge 'id' immer hinzu, wenn es existiert und nicht bereits Teil der Auswahl ist
                // (um sicherzustellen, dass die ID verfügbar ist, es sei denn, der Benutzer hat sie explizit abgewählt)
                // Dies ist eine häufige Anforderung, die ID immer zu haben.
                if (isset($record['id']) && !isset($requestedFieldsSet['id'])) {
                    $selectedRecord['id'] = $record['id'];
                }

                $selectedRecords[] = $selectedRecord;
            }
            $records = $selectedRecords; // Überschreibe mit den gefilterten Datensätzen
        }

        $this->resetState();
        return $records;
    }

    /**
     * Führt eine Suche aus und gibt den ersten gefundenen Datensatz zurück.
     * Berücksichtigt `where`, `orderBy` und `select`.
     *
     * @return array<string, mixed>|null Der erste gefundene Datensatz oder null.
     * @throws RuntimeException Bei Engine-Fehlern.
     * @throws InvalidArgumentException Bei ungültigen Bedingungen in der Engine.
     */
    public function first(): ?array
    {
        $this->limit(1); // Setze Limit auf 1
        $results = $this->find(); // find() ruft resetState() auf
        return $results[0] ?? null;
    }

    /**
     * Prüft, ob mindestens ein Datensatz die `where`-Bedingungen erfüllt.
     * Optimiert die Abfrage, indem nur die Existenz geprüft wird.
     *
     * @return bool True, wenn mindestens ein passender Datensatz existiert, sonst false.
     * @throws RuntimeException Bei Engine-Fehlern.
     * @throws InvalidArgumentException Bei ungültigen Bedingungen in der Engine.
     */
    public function exists(): bool
    {
        // Direkt die internen Eigenschaften setzen, um die Abfrage zu optimieren
        $this->selectFields = ['id']; // Wähle nur die ID (minimal)
        $this->limit = 1;             // Es reicht, einen Treffer zu finden
        $this->offset = 0;            // Offset ist irrelevant
        $this->orderBy = null;        // Sortierung ist irrelevant

        // Führe die Suche aus. find() ruft am Ende resetState() auf.
        $results = $this->find();

        // Das Ergebnis der Existenzprüfung zurückgeben
        return !empty($results);
    }


    /**
     * Zählt die Anzahl der Datensätze, die die `where`-Bedingungen erfüllen.
     * ACHTUNG: Lädt potenziell alle passenden Datensätze in den Speicher, nur um sie zu zählen!
     * Dies kann bei sehr vielen Treffern ineffizient sein.
     * Ignoriert `limit`, `offset`, `orderBy` und `select` des Handlers.
     *
     * @return int Die Anzahl der passenden Datensätze.
     * @throws RuntimeException Bei Engine-Fehlern.
     * @throws InvalidArgumentException Bei ungültigen Bedingungen in der Engine.
     */
    public function count(): int
    {
        $this->ensureTableSelected();
        $engine = $this->db->table($this->tableName);

        // Führe findRecords ohne Limit/Offset aus, um alle Treffer zu bekommen
        // Wir brauchen keine Sortierung oder Feldauswahl zum Zählen.
        $records = $engine->findRecords($this->conditions, 0, 0);

        // Der Zustand muss hier zurückgesetzt werden, da count() eine Endoperation ist.
        $count = count($records);
        $this->resetState();
        return $count;
    }


    /**
     * Prüft das Ergebnis einer Bulk-Update-Operation.
     * Gibt true zurück, wenn mindestens eine Operation erfolgreich war oder 'no_change' meldete.
     *
     * @param array<int, bool|string|array{'error': string}> $bulkResult Das Ergebnis von `bulkUpdateRecords`.
     * @return bool True, wenn mindestens ein Update erfolgreich war oder keine Änderung nötig war.
     */
    private function processBulkUpdateResult(array $bulkResult): bool
    {
        foreach ($bulkResult as $res) {
            if ($res === true || $res === 'no_change') {
                return true; // Mindestens ein Erfolg (oder keine Änderung) reicht
            }
            if (is_array($res) && isset($res['error'])) {
                // Fehler loggen? Hier nicht, die Engine loggt schon.
                continue; // Fehler ignorieren für die Erfolgsprüfung
            }
            // false bedeutet 'nicht gefunden', was kein Erfolg ist.
        }
        return false; // Kein einziger Erfolg oder 'no_change'
    }

    /**
     * Prüft das Ergebnis einer Bulk-Delete-Operation.
     * Gibt true zurück, wenn mindestens eine Operation erfolgreich war.
     *
     * @param array<int, bool|array{'error': string}> $bulkResult Das Ergebnis von `bulkDeleteRecords`.
     * @return bool True, wenn mindestens ein Löschvorgang erfolgreich war.
     */
    private function processBulkDeleteResult(array $bulkResult): bool
    {
        foreach ($bulkResult as $res) {
            if ($res === true) {
                return true; // Mindestens ein Erfolg reicht
            }
            if (is_array($res) && isset($res['error'])) {
                continue; // Fehler ignorieren
            }
            // false bedeutet 'nicht gefunden'
        }
        return false; // Kein einziger Erfolg
    }

    /**
     * Stellt sicher, dass eine Tabelle ausgewählt wurde.
     *
     * @throws RuntimeException Wenn keine Tabelle ausgewählt ist.
     */
    private function ensureTableSelected(): void
    {
        if ($this->tableName === null) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $message = "Handler ensureTableSelected() FAILED. tableName is null. (Object Hash: " . spl_object_hash($this) . "). Backtrace: " . print_r($backtrace, true);
            error_log($message);
            trigger_error($message, E_USER_ERROR);
            throw new RuntimeException("Es muss zuerst eine Tabelle mit table() ausgewählt werden. (tableName ist null)");
        } else {
            $message = "Handler ensureTableSelected() OK. tableName is: " . $this->tableName . " (Object Hash: " . spl_object_hash($this) . ")";
            // error_log($message);
        }
    }
    

    /**
     * Stellt sicher, dass Daten für Insert/Update vorhanden sind.
     *
     * @param string $operation Name der Operation für die Fehlermeldung.
     * @throws RuntimeException Wenn keine Daten übergeben wurden oder die Daten leer sind.
     */
    private function ensureDataProvided(string $operation): void
    {
        if ($this->data === null) {
            throw new RuntimeException("Für das {$operation} müssen über data() Daten übergeben werden.");
        }
        if (!is_array($this->data) || empty($this->data)) {
            throw new RuntimeException("Für das {$operation} müssen über data() nicht-leere Daten (Array) übergeben werden.");
        }
    }

    /**
     * Setzt den internen Zustand (Tabelle, Bedingungen, Daten, Limit, Offset, OrderBy, Select) zurück.
     * Wird nach jeder abgeschlossenen Operation (insert, update, delete, find, first, exists, count) aufgerufen.
     */
    private function resetState(): void
    {
        $this->conditions = [];
        $this->data = null;
        $this->limit = 0;
        $this->offset = 0;
        $this->orderBy = null;
        $this->selectFields = []; // Leeres Array bedeutet 'alle Felder'
    }
}