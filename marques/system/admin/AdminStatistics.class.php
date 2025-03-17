<?php
declare(strict_types=1);

namespace Marques\Admin;

use Marques\Core\AppStatistics;

/**
 * Class AdminStatistics
 *
 * Erweitert die AppStatistics um Verwaltungsfunktionen:
 * - Aktualisieren von Statistik-Einstellungen
 * - Löschen von Statistik-Daten
 * - Bereitstellung eines administrativen Überblicks
 *
 * @package Marques\Admin
 */
class AdminStatistics extends AppStatistics {

    /**
     * Konstruktor.
     * Ruft den Konstruktor der Elternklasse auf.
     */
    public function __construct() {
        parent::__construct();
    }

    /**
     * Aktualisiert Einstellungen und/oder Statistik-Daten.
     *
     * @param array $newSettings Neue Einstellungen, die übernommen werden sollen.
     * @return bool True, wenn die Aktualisierung erfolgreich war.
     */
    public function updateSettings(array $newSettings): bool {
        // Beispielhafte Implementierung:
        // Hier könnte man Einstellungen in einer Datenbank oder Konfigurationsdatei speichern.
        // Für die Demonstration werden die neuen Einstellungen in die Statistik-Daten integriert.
        $this->stats = array_merge($this->stats, $newSettings);
        return true;
    }

    /**
     * Löscht alle gesammelten Statistik-Daten.
     *
     * @return bool True, wenn das Löschen erfolgreich war.
     */
    public function deleteStatistics(): bool {
        // Beispielhafte Löschlogik:
        $this->stats = [];
        return true;
    }

    /**
     * Gibt eine zusammenfassende Übersicht der Statistik-Daten zurück.
     *
     * @return string Formatierte Zusammenfassung
     */
    public function getAdminSummary(): string {
        $summary = "Admin Summary:\n";
        foreach ($this->stats as $key => $value) {
            $summary .= ucfirst($key) . ": " . $value . "\n";
        }
        return $summary;
    }
}
