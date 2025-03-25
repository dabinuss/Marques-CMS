<?php
declare(strict_types=1);

namespace Marques\Core;

class DatabaseHandler {
    private static ?DatabaseHandler $instance = null;
    private DatabaseConfig $config;
    private $currentTable = null;

    public function __construct() {
        $this->config = new DatabaseConfig();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new DatabaseHandler();
        }
        return self::$instance;
    }

    /**
     * Setzt den globalen Tabellenkontext.
     *
     * @param string $tableName
     * @return self
     */
    public function useTable(string $tableName): self {
        $this->currentTable = $this->config->getTable($tableName);
        return $this;
    }

    /**
     * Liefert den aktuellen Tabellenkontext, optional überschrieben durch $tableName.
     *
     * @param string|null $tableName
     * @return mixed
     * @throws \Exception wenn kein Tabellenkontext gefunden wurde.
     */
    private function getTable(?string $tableName = null) {
        if ($tableName !== null) {
            return $this->config->getTable($tableName);
        }
        
        if ($this->currentTable === null) {
            throw new \Exception("Kein Tabellenkontext gesetzt. Bitte rufe useTable('tablename') auf oder übergebe einen Tabellennamen.");
        }
        
        return $this->currentTable;
    }

    /**
     * Überprüft, ob es sich bei den Daten um Bulk-Daten handelt.
     *
     * @param array $data
     * @return bool
     */
    private function isBulkData(array $data): bool {
        return !empty($data) && is_array(reset($data));
    }

    /**
     * Fügt einen neuen Datensatz ein oder führt eine Bulk-Insert-Operation aus.
     *
     * @param array $data
     * @param int|null $recordId Wird ignoriert, wenn Bulk-Daten vorliegen.
     * @param string|null $tableName Optionaler Tabellenname
     * @return mixed Neue ID oder Ergebnis der Bulk-Operation
     */
    public function insertRecord(array $data, ?int $recordId = null, ?string $tableName = null) {
        if ($this->isBulkData($data)) {
            return $this->bulkInsertRecords($data, $tableName);
        }
        
        return $this->getTable($tableName)->insertRecord($data);
    }

    /**
     * Führt einen Bulk-Insert durch.
     *
     * @param array $data
     * @param string|null $tableName
     * @return mixed
     */
    public function bulkInsertRecords(array $data, ?string $tableName = null) {
        return $this->getTable($tableName)->bulkInsertRecords($data);
    }

    /**
     * Aktualisiert einen Datensatz oder führt eine Bulk-Update-Operation aus.
     *
     * @param int $recordId
     * @param array $data
     * @param string|null $tableName
     * @return bool
     */
    public function updateRecord(int $recordId, array $data, ?string $tableName = null): bool {
        if ($this->isBulkData($data)) {
            return $this->bulkUpdateRecords($data, $tableName);
        }
        
        return $this->getTable($tableName)->updateRecord($recordId, $data);
    }

    /**
     * Führt einen Bulk-Update durch.
     *
     * @param array $data
     * @param string|null $tableName
     * @return bool
     */
    public function bulkUpdateRecords(array $data, ?string $tableName = null): bool {
        return $this->getTable($tableName)->bulkUpdateRecords($data);
    }

    /**
     * Löscht einen Datensatz oder führt eine Bulk-Delete-Operation durch.
     *
     * @param int|array $recordId Einzelne ID oder Array von IDs
     * @param string|null $tableName
     * @return bool
     */
    public function deleteRecord($recordId, ?string $tableName = null): bool {
        if (is_array($recordId)) {
            return $this->bulkDeleteRecords($recordId, $tableName);
        }
        
        return (bool)$this->getTable($tableName)->deleteRecord($recordId);
    }

    /**
     * Führt einen Bulk-Delete durch.
     *
     * @param array $recordIds
     * @param string|null $tableName
     * @return bool
     */
    public function bulkDeleteRecords(array $recordIds, ?string $tableName = null): bool {
        return $this->getTable($tableName)->bulkDeleteRecords($recordIds);
    }

    /**
     * Liefert eine Einstellung anhand eines Schlüssels.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getSetting(string $key, $default = null) {
        return $this->config->getSetting($key, $default);
    }

    /**
     * Setzt eine Einstellung.
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function setSetting(string $key, $value): bool {
        return $this->config->setSetting($key, $value);
    }

    /**
     * Setzt mehrere Einstellungen.
     *
     * @param array $settings
     * @return bool
     */
    public function setMultipleSettings(array $settings): bool {
        $result = true;
        foreach ($settings as $key => $value) {
            $result = $this->setSetting($key, $value) && $result;
        }
        return $result;
    }

    /**
     * Führt den Cronjob aus.
     */
    public function runCronJob(): void {
        $this->config->runCronJob();
    }

    /**
     * Liefert alle Einstellungen.
     *
     * @return array
     */
    public function getAllSettings(): array {
        return $this->config->getAllSettings();
    }
    
    /**
     * Speichert Einstellungen (Stub, immer true).
     *
     * @return bool
     */
    public function saveSettings(): bool {
        return true;
    }
}