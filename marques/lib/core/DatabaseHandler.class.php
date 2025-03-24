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
     * Setzt den Tabellenkontext, auf dem weitere Operationen ausgeführt werden sollen.
     *
     * @param string $tableName
     * @return self
     */
    public function useTable(string $tableName): self {
        $this->currentTable = $this->config->getTable($tableName);
        return $this;
    }

    /**
     * Liefert den aktuell gesetzten Tabellenkontext.
     *
     * @return mixed
     * @throws \Exception wenn kein Tabellenkontext gesetzt ist.
     */
    public function getCurrentTable() {
        if ($this->currentTable === null) {
            throw new \Exception("Kein Tabellenkontext gesetzt. Bitte rufe useTable('tablename') auf.");
        }
        return $this->currentTable;
    }

    /**
     * Sucht einen einzelnen Datensatz anhand der Record-ID.
     *
     * @param int $recordId
     * @return array|null
     */
    public function findRecord(int $recordId) {
        return $this->getCurrentTable()->selectRecord($recordId);
    }

    /**
     * Liefert alle Datensätze der aktuellen Tabelle.
     *
     * @return array
     */
    public function getAllRecords(): array {
        return $this->getCurrentTable()->selectAllRecords();
    }

    /**
     * Aktualisiert einen Datensatz in der aktuellen Tabelle.
     *
     * @param int $recordId
     * @param array $data
     * @return bool
     */
    public function updateRecord(int $recordId, array $data): bool {
        return $this->getCurrentTable()->updateRecord($recordId, $data);
    }

    /**
     * Fügt einen neuen Datensatz in der aktuellen Tabelle ein.
     * Falls $recordId übergeben wird, wird er ignoriert, da das System eine neue ID generiert.
     *
     * @param array $data
     * @param int|null $recordId (wird ignoriert)
     * @return int Neue ID des eingefügten Datensatzes
     */
    public function insertRecord(array $data, ?int $recordId = null) {
        // $recordId wird ignoriert – die ID wird intern generiert.
        return $this->getCurrentTable()->insertRecord($data);
    }

    /**
     * Löscht einen Datensatz in der aktuellen Tabelle.
     *
     * @param int $recordId
     * @return bool
     */
    public function deleteRecord(int $recordId): bool {
        return (bool)$this->getCurrentTable()->deleteRecord($recordId);
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