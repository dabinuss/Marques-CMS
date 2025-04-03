<?php
declare(strict_types=1);

namespace Marques\Data\Database;

use Marques\Data\Database\Handler as DatabaseHandler;
use RuntimeException;
use FlatFileDB\FlatFileDatabase;
use FlatFileDB\FlatFileDatabaseHandler;

/**
 * DatabaseConfig
 *
 * Diese Klasse ist ausschließlich für den Initialisierungsprozess zuständig:
 * Sie erstellt die notwendigen Tabellen und füllt diese mit Default-Daten,
 * falls diese noch nicht vorhanden sind.
 */
class Config {
    /** @var DatabaseHandler */
    private $db;
    private string $baseDir;
    private int $compactionInterval;
    private string $lastCompactionFile;
    private FlatFileDatabase $libraryDatabase;

    // Konstanten für Standardtabellen
    public const TABLE_SETTINGS   = 'settings';
    public const TABLE_NAVIGATION = 'navigation';
    public const TABLE_USER       = 'user';
    public const TABLE_URLMAPPING = 'urlmapping';

    public function __construct(DatabaseHandler $dbHandler, string $baseDir = 'data', int $compactionInterval = 3600) {

        // Falls baseDir relativ ist, an MARQUES_ROOT_DIR anhängen
        if ($baseDir[0] !== '/' && defined('MARQUES_ROOT_DIR')) {
            $baseDir = MARQUES_ROOT_DIR . '/' . $baseDir;
        }
        $this->baseDir = rtrim($baseDir, '/');
        $this->compactionInterval = $compactionInterval;

        $this->db = $dbHandler;
        $this->libraryDatabase = $this->db->getLibraryDatabase();

        $this->lastCompactionFile = $this->baseDir . '/last_compaction.txt';
        $this->initializeTables();
        $this->runCronJob();
    }

    /**
     * Initialisiert alle Standardtabellen mit Default-Daten.
     */
    private function initializeTables(): void {
        $this->initializeSettingsTable();
        $this->initializeNavigationTable();
        $this->initializeUrlMappingTable();
        $this->initializeUserTable();
    }

    private function initializeSettingsTable(): void {
        $defaultSettings = [
            'site_name'           => 'marques CMS',
            'site_description'    => 'Ein leichtgewichtiges, dateibasiertes CMS',
            'site_logo'           => '',
            'site_favicon'        => '',
            'base_url'            => '',
            'timezone'            => 'Europe/Berlin',
            'date_format'         => 'd.m.Y',
            'time_format'         => 'H:i',
            'posts_per_page'      => 10,
            'excerpt_length'      => 150,
            'blog_url_format'     => 'date_slash',
            'debug'               => true,
            'cache_enabled'       => false,
            'version'             => '0.3.0',
            'active_theme'        => 'default',
            'themes_path'         => '',
            'admin_language'      => 'de',
            'admin_email'         => '',
            'contact_email'       => '',
            'contact_phone'       => '',
            'maintenance_mode'    => false,
            'maintenance_message' => 'Die Website wird aktuell gewartet. Bitte versuchen Sie es später erneut.',
            'comments_enabled'    => false,
            'security'            => [
                'max_login_attempts'    => 6,
                'login_attempt_window'  => 600,
                'login_block_duration'  => 600,
            ]
        ];
    
        $schemaSettings = [
            'requiredFields' => [
                'site_name', 'site_description', 'base_url', 'timezone',
                'date_format', 'time_format', 'posts_per_page', 'excerpt_length',
                'blog_url_format', 'debug', 'cache_enabled', 'version', 'active_theme',
                'themes_path', 'admin_language', 'admin_email', 'contact_email',
                'contact_phone', 'maintenance_mode', 'maintenance_message', 'comments_enabled',
                'security'
            ],
            'fieldTypes' => [
                'site_name'           => 'string',
                'site_description'    => 'string',
                'site_logo'           => 'string',
                'site_favicon'        => 'string',
                'base_url'            => 'string',
                'timezone'            => 'string',
                'date_format'         => 'string',
                'time_format'         => 'string',
                'posts_per_page'      => 'int',
                'excerpt_length'      => 'int',
                'blog_url_format'     => 'string',
                'debug'               => 'bool',
                'cache_enabled'       => 'bool',
                'version'             => 'string',
                'active_theme'        => 'string',
                'themes_path'         => 'string',
                'admin_language'      => 'string',
                'admin_email'         => 'string',
                'contact_email'       => 'string',
                'contact_phone'       => 'string',
                'maintenance_mode'    => 'bool',
                'maintenance_message' => 'string',
                'comments_enabled'    => 'bool',
                'security'            => 'array'
            ]
        ];
    
        // Erstelle die Tabelle und prüfe, ob der Datensatz existiert:
        $table = $this->db->createTableWithSchema(self::TABLE_SETTINGS, $schemaSettings['requiredFields'], $schemaSettings['fieldTypes']);
        if ($table->where('id', '=', 1)->first() === null) {
            $defaultSettings['id'] = 1;
            // Vor dem Insert Tabelle erneut auswählen – da der erste Aufruf den Zustand zurücksetzt:
            $table = $this->db->table(self::TABLE_SETTINGS);
            if (!$table->data($defaultSettings)->insert()) {
                throw new RuntimeException("Standarddaten für Tabelle '" . self::TABLE_SETTINGS . "' konnten nicht eingefügt werden.");
            }
        }
    }    

    private function initializeNavigationTable(): void {
        $defaultNavigation = [
            [
                'menu_type' => 'main_menu',
                'title'     => 'Startseite',
                'url'       => '/',
                'target'    => '_self',
                'order'     => 1
            ],
            [
                'menu_type' => 'main_menu',
                'title'     => 'Blog',
                'url'       => 'blog-list',
                'target'    => '_self',
                'order'     => 2
            ],
            [
                'menu_type' => 'main_menu',
                'title'     => 'Über uns',
                'url'       => 'about',
                'target'    => '_self',
                'order'     => 3
            ],
            [
                'menu_type' => 'main_menu',
                'title'     => 'Kontakt',
                'url'       => 'contact',
                'target'    => '_self',
                'order'     => 4
            ]
        ];

        $this->db->createTableWithSchema(self::TABLE_NAVIGATION, [], []);

        foreach ($defaultNavigation as $index => $record) {
            $recordId = $index + 1;

            // *** KORREKTUR: Hole für JEDE Iteration eine frische Handler-Instanz für die Prüfung ***
            $checkTable = $this->db->table(self::TABLE_NAVIGATION);
            if ($checkTable->where('id', '=', $recordId)->first() === null) {
                $record['id'] = $recordId;

                // *** KORREKTUR: Hole auch für den Insert eine frische Instanz ***
                // (Obwohl der vorherige Code das schon tat, ist es hier expliziter)
                $insertTable = $this->db->table(self::TABLE_NAVIGATION);
                if (!$insertTable->data($record)->insert()) {
                    // Beachte: Der Fehlertext verwendet jetzt den Konstanten-Namen
                    throw new RuntimeException("Standarddaten für Tabelle '" . self::TABLE_NAVIGATION . "' konnten nicht eingefügt werden.");
                }
            }
        }
    }

    private function initializeUrlMappingTable(): void {
        // Definiere hier Standardrouten für das Frontend
        $defaultFrontendRoutes = [
            [
                'method'  => 'GET',
                'pattern' => '/',
                'handler' => '', // Default Content Handler für 'home'
                'options' => ['name' => 'home'] // Optionen als Array
            ],
            [
                'method'  => 'GET',
                'pattern' => '/blog',
                'handler' => '', // Default Content Handler für 'blog-list'
                'options' => ['name' => 'blog.list']
            ],
            [
                'method'  => 'GET',
                'pattern' => '/blog/{slug}',
                'handler' => '', // Default Content Handler für 'blog-post'
                'options' => [
                    'name' => 'blog.show',
                    'params' => ['slug' => '[a-z0-9\-]+'] // Regex für Parameter
                ]
            ],
            [
                'method'  => 'GET',
                'pattern' => '/blog/category/{category}',
                'handler' => '', // Default Content Handler für 'blog-category'
                'options' => [
                    'name' => 'blog.category',
                    'params' => ['category' => '[a-z0-9\-]+']
                ]
            ],
             [
                 'method'  => 'GET',
                 'pattern' => '/blog/archive/{year}/{month}',
                 'handler' => '', // Default Content Handler für 'blog-archive'
                 'options' => [
                     'name' => 'blog.archive',
                     'params' => ['year' => '\d{4}', 'month' => '\d{2}']
                 ]
             ],
            // Füge hier weitere essentielle Frontend-Routen hinzu
        ];

        // Schema Definition - mit 'regex'-Feld
        $schemaUrlMapping = [
            // ID wird von FlatFileDB automatisch verwaltet
            'requiredFields' => ['method', 'pattern', 'regex'], // Regex ist jetzt Pflicht
            'fieldTypes' => [
                'method'  => 'string',
                'pattern' => 'string',
                'handler' => 'string', // Kann leer sein
                'options' => 'string', // Speichert JSON
                'regex'   => 'string'  // Speichert den kompilierten Regex
            ]
        ];

        // Erstelle Tabelle mit Schema
        $this->db->createTableWithSchema(self::TABLE_URLMAPPING, $schemaUrlMapping['requiredFields'], $schemaUrlMapping['fieldTypes']);

        // Helper-Funktion (oder temporärer Router) zum Kompilieren der Regex
        $compileRegex = function(string $pattern, array $options = []): string {
             // Einfache Implementierung, ähnlich wie im Router
             $regex = preg_replace_callback('#\{(\w+)(?::([^}]+))?\}#', function ($matches) use ($options) {
                 $paramName = $matches[1];
                 $paramPattern = $matches[2] ?? ($options['params'][$paramName] ?? '[^/]+');
                 return '(?P<' . $paramName . '>' . $paramPattern . ')';
             }, $pattern);
             return '#^' . $regex . '$#u'; // u-Modifikator für UTF-8
        };


        // Füge Default-Routen ein, falls sie noch nicht existieren
        foreach ($defaultFrontendRoutes as $recordData) {
            $checkTable = $this->db->table(self::TABLE_URLMAPPING);
            $existing = $checkTable->where('method', '=', $recordData['method'])
                                   ->where('pattern', '=', $recordData['pattern'])
                                   ->first();

            if ($existing === null) {
                // Bereite den Datensatz für die DB vor
                $dbRecord = [
                    'method'  => $recordData['method'],
                    'pattern' => $recordData['pattern'],
                    'handler' => $recordData['handler'] ?? '',
                    // Kompiliere Regex und füge sie hinzu
                    'regex'   => $compileRegex($recordData['pattern'], $recordData['options'] ?? []),
                    // Optionen als JSON speichern
                    'options' => json_encode($recordData['options'] ?? [])
                ];

                $insertTable = $this->db->table(self::TABLE_URLMAPPING);
                if (!$insertTable->data($dbRecord)->insert()) {
                    error_log("Standardroute für '" . $dbRecord['pattern'] . "' konnte nicht eingefügt werden.");
                }
            }
            // Optional: Bestehende Default-Routen aktualisieren, falls sich Regex oder Optionen ändern?
            // else if ($existing['regex'] !== $compileRegex(...) || $existing['options'] !== json_encode(...)) {
            //    $updateTable = $this->db->table(...)->where('id', '=', $existing['id'])->update(...);
            // }
        }
    }

    private function initializeUserTable(): void {
        $defaultUser = [
            [
                'username'     => 'admin',
                'password'     => '',
                'display_name' => 'Administrator',
                'role'         => 'admin',
                'email'        => '',
                'created'      => 1741205694,
                'last_login'   => 0,
                'first_login'  => true
            ]
        ];

        $schemaUser = [
            'requiredFields' => ['username', 'password', 'role'],
            'fieldTypes' => [
                'username'     => 'string',
                'password'     => 'string',
                'display_name' => 'string',
                'role'         => 'string',
                'email'        => 'string',
                'created'      => 'int',
                'last_login'   => 'int',
                'first_login'  => 'boolean'
            ]
        ];

        $this->db->createTableWithSchema(self::TABLE_USER, $schemaUser['requiredFields'], $schemaUser['fieldTypes']);

        foreach ($defaultUser as $index => $record) {
            $recordId = $index + 1;

            // *** KORREKTUR: Frische Instanz für die Prüfung ***
            $checkTable = $this->db->table(self::TABLE_USER);
            if ($checkTable->where('id', '=', $recordId)->first() === null) {
                $record['id'] = $recordId;

                // *** KORREKTUR: Frische Instanz für den Insert ***
                $insertTable = $this->db->table(self::TABLE_USER);
                if (!$insertTable->data($record)->insert()) {
                    throw new RuntimeException("Standarddaten für Tabelle '" . self::TABLE_USER . "' konnten nicht eingefügt werden.");
                }
            }
        }
    }

    /**
     * Führt einen Cronjob aus, z. B. zur Kompaktierung der Tabellen.
     * Diese Methode kann sowohl beim Setup als auch im laufenden Betrieb genutzt werden.
     */
    public function runCronJob(): void {
        $lastCompaction = $this->getLastCompactionTime();
        if (time() - $lastCompaction >= $this->compactionInterval) {
            // Verwende die gespeicherte Instanz
            $this->libraryDatabase->compactAllTables();
            $this->updateLastCompactionTime();
        }
    }

    private function getLastCompactionTime(): int {
        if (file_exists($this->lastCompactionFile)) {
            return (int) file_get_contents($this->lastCompactionFile);
        }
        return 0;
    }

    private function updateLastCompactionTime(): void {
        file_put_contents($this->lastCompactionFile, time());
    }
}
