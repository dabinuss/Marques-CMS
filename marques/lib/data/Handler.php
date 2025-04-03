<?php
declare(strict_types=1);

namespace Marques\Data\Database;

use FlatFileDB\FlatFileDatabase;
use FlatFileDB\FlatFileDatabaseHandler;

/**
 * DatabaseHandler (Minimaler Proxy)
 *
 * Dient als zentraler Einstiegspunkt und Proxy zum FlatFileDatabaseHandler der Bibliothek.
 * Stellt die Methode `table()` bereit, um die Fluent-API der Bibliothek zu starten.
 * Enthält KEINE eigenen CRUD- oder CMS-spezifischen Methoden mehr.
 */
class Handler {

    private FlatFileDatabase $db; 
    private FlatFileDatabaseHandler $libraryHandler;

    /**
     * Konstruktor - Erwartet die initialisierte DB und den Handler.
     *
     * @param FlatFileDatabase $db Die initialisierte Datenbank-Instanz.
     * @param FlatFileDatabaseHandler $handler Der initialisierte Handler der Bibliothek.
     */
    public function __construct(FlatFileDatabase $db, FlatFileDatabaseHandler $handler) {
        $this->db = $db;
        $this->libraryHandler = $handler;
    }

    /**
     * Startet eine Operation auf einer spezifischen Tabelle.
     *
     * @param string $tableName
     * @return FlatFileDatabaseHandler
     * @throws \InvalidArgumentException
     */
    public function table(string $tableName): FlatFileDatabaseHandler {

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
            throw new \InvalidArgumentException("Invalid table name");
        }
        if (!$this->db->hasTable($tableName)) {
            throw new \InvalidArgumentException("Tabelle '{$tableName}' ist nicht bei der Datenbank registriert.");
        }
        $handler = clone $this->libraryHandler;
        return $handler->table($tableName);
    }

    /**
     * Erstellt (falls noch nicht vorhanden) eine Tabelle und setzt optional ein Schema.
     *
     * @param string $tableName Name der Tabelle
     * @param array $requiredFields Pflichtfelder
     * @param array $fieldTypes Feldtypen
     * @return FlatFileDatabaseHandler Die konfigurierte Tabelleninstanz
     */
    public function createTableWithSchema(string $tableName, array $requiredFields, array $fieldTypes): FlatFileDatabaseHandler {
        if (!$this->db->hasTable($tableName)) {
            $this->db->registerTable($tableName);
        }
        $table = $this->table($tableName);
        if (method_exists($table, 'setSchema')) {
            $table->setSchema($requiredFields, $fieldTypes);
        }
        return $table;
    }

    /**
     * Gibt die zugrundeliegende FlatFileDatabase-Instanz zurück.
     * Nötig für Operationen, die nicht über den Handler laufen (z.B. globale Wartung).
     *
     * @return FlatFileDatabase
     */
    public function getLibraryDatabase(): FlatFileDatabase {
        return $this->db;
    }

     /**
      * Gibt die zugrundeliegende FlatFileDatabaseHandler-Instanz zurück.
      * Nützlich, falls der Handler direkt benötigt wird, ohne `table()` aufzurufen
      * (selten notwendig bei diesem Ansatz).
      *
      * @return FlatFileDatabaseHandler
      */
     public function getLibraryHandler(): FlatFileDatabaseHandler {
         return $this->libraryHandler;
     }
}