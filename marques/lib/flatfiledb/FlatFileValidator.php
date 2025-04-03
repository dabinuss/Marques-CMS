<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;

/**
 * Zentrale Hilfsklasse für Validierungen.
 */
class FlatFileValidator
{
    /**
     * Überprüft, ob eine ID gültig ist (nur Buchstaben, Zahlen, Binde- und Unterstriche)
     * 
     * @param string $recordId Die zu prüfende ID
     * @return bool True wenn gültig, sonst false
     */
    public static function isValidId(string $recordId): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9\-_]+$/', $recordId);
    }
    
    /**
     * Validiert Felder eines Datensatzes anhand eines Schemas
     * 
     * @param array $data Die zu validierenden Daten
     * @param array $requiredFields Liste der Pflichtfelder
     * @param array $fieldTypes Assoziatives Array mit Feldname => Erwarteter Typ
     * @throws InvalidArgumentException wenn Validierung fehlschlägt
     */
    public static function validateData(array $data, array $requiredFields = [], array $fieldTypes = []): void
    {
        // Pflichtfelder prüfen
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new InvalidArgumentException("Fehlendes Pflichtfeld: $field");
            }
        }
        
        // Datentypen prüfen
        foreach ($fieldTypes as $field => $type) {
            if (isset($data[$field])) {
                $validType = match($type) {
                    'string' => is_string($data[$field]),
                    'int', 'integer' => is_int($data[$field]),
                    'float', 'double' => is_float($data[$field]),
                    'bool', 'boolean' => is_bool($data[$field]),
                    'array' => is_array($data[$field]),
                    'numeric' => is_numeric($data[$field]),
                    default => throw new InvalidArgumentException("Unbekannter Typ '$type' für Feld '$field'")
                };

                if ($type === 'int' && is_string($data[$field]) && !ctype_digit($data[$field])) {
                    throw new InvalidArgumentException("Feld '$field' muss eine ganze Zahl sein (string representation).");
                }
              
                // Cast and compare (for actual integers)
                if ($type === 'int' && (!is_int($data[$field]) || (int)$data[$field] !== $data[$field])) {
                    throw new InvalidArgumentException("Feld '$field' muss eine ganze Zahl sein.");
                }

                if (!$validType) {
                    throw new InvalidArgumentException("Feld '$field' hat nicht den erwarteten Typ '$type'");
                }
            }
        }
    }
}