<?php
declare(strict_types=1);

namespace Marques\Core;

use FlatFileDB\FlatFileDatabase;
use RuntimeException;

class DatabaseConfig {
    private FlatFileDatabase $db;
    private string $baseDir;
    private int $compactionInterval;
    private string $lastCompactionFile;
    private ?array $settingsCache = null;

    // Konstanten für Standardtabellen
    private const TABLE_SETTINGS   = 'settings';
    private const TABLE_NAVIGATION = 'navigation';
    private const TABLE_USER       = 'user';
    private const TABLE_URLMAPPING = 'urlmapping';

    public function __construct(string $baseDir = 'data', int $compactionInterval = 3600) {
        if ($baseDir[0] !== '/' && defined('MARQUES_ROOT_DIR')) {
            $baseDir = MARQUES_ROOT_DIR . '/' . $baseDir;
        }
        $this->baseDir = rtrim($baseDir, '/');
        $this->compactionInterval = $compactionInterval;
        $this->db = new FlatFileDatabase($this->baseDir);
        $this->lastCompactionFile = $this->baseDir . '/last_compaction.txt';
        $this->initializeTables();
    }

    // Initialisiert alle Standardtabellen mit Default-Werten
    private function initializeTables(): void {
        $this->initializeSettingsTable();
        $this->initializeNavigationTable();
        $this->initializeUrlMappingTable();
        $this->initializeUserTable();
    }

    private function initializeSettingsTable(): void {
        // Ein einzelner Datensatz (Record-ID = 1)
        $defaultSettings = [
            'site_name'           => 'marques CMS',
            'site_description'    => 'Ein leichtgewichtiges, dateibasiertes CMS',
            'site_logo'           => '',
            'site_favicon'        => '',
            'base_url'            => 'https://faktenfront.de',
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
            'themes_path'         => '/homepages/38/d4298831632/htdocs/marques/themes',
            'admin_language'      => 'de',
            'security'            => [
                'max_login_attempts'    => 6,
                'login_attempt_window'  => 600,
                'login_block_duration'  => 600,
            ],
            'admin_email'         => '',
            'contact_email'       => '',
            'contact_phone'       => '',
            'maintenance_mode'    => false,
            'maintenance_message' => 'Die Website wird aktuell gewartet. Bitte versuchen Sie es später erneut.',
            'comments_enabled'    => false
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

        // Record-ID als int (hier: 1)
        $this->initializeTableWithSchema(self::TABLE_SETTINGS, $defaultSettings, 1, $schemaSettings);
    }

    private function initializeNavigationTable(): void {
        // Mehrere Navigationseinträge, jeweils als eigener Datensatz
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

        $schemaNavigation = [
            'requiredFields' => ['menu_type', 'url', 'order'],
            'fieldTypes' => [
                'menu_type' => 'string',
                'title'     => 'string',
                'url'       => 'string',
                'target'    => 'string',
                'order'     => 'int'
            ]
        ];

        $this->db->registerTable(self::TABLE_NAVIGATION);
        $table = $this->db->table(self::TABLE_NAVIGATION);

        foreach ($defaultNavigation as $index => $record) {
            // Record-ID als int: 1, 2, 3, ...
            $recordId = $index + 1;
            if ($table->selectRecord($recordId) === null) {
                $record['id'] = $recordId;
                if (!$table->insertRecord($record, $recordId)) {
                    throw new RuntimeException("Standarddaten für Tabelle '".self::TABLE_NAVIGATION."' konnten nicht eingefügt werden.");
                }
            }
        }
    }

    private function initializeUrlMappingTable(): void {
        // Mehrere URL-Mapping-Einträge, jeweils als eigener Datensatz
        $defaultUrlMappingRoutes = [
            [
                'method'  => 'GET',
                'pattern' => '/',
                'handler' => '',
                'options' => ''  // Leerer String statt Array
            ],
            [
                'method'  => 'GET',
                'pattern' => '/blog/{year}/{month}/{day}/{slug}',
                'handler' => 'Marques\\Controller\\BlogController@show',
                'options' => '{"params":{"year":"[0-9]{4}","month":"(0[1-9]|1[0-2])","day":"(0[1-9]|[12][0-9]|3[01])","slug":"[a-z0-9-]+"},"schema":{"year":{"type":"integer","min":2000},"month":{"type":"integer","min":1,"max":12},"day":{"type":"integer","min":1,"max":31},"slug":{"type":"string","pattern":"/^[a-z0-9-]+$/"}}}'
            ],
            [
                'method'  => 'GET',
                'pattern' => '/blog-list',
                'handler' => '',
                'options' => ''  // Leerer String
            ],
            [
                'method'  => 'GET',
                'pattern' => '/blog-category/{category}',
                'handler' => '',
                'options' => '{"params":{"category":"[a-z0-9-]+"},"schema":{"category":{"type":"string","pattern":"/^[a-z0-9-]+$/"}}}'
            ],
            [
                'method'  => 'GET',
                'pattern' => '/blog-archive/{year}/{month}',
                'handler' => '',
                'options' => '{"params":{"year":"[0-9]{4}","month":"(0[1-9]|1[0-2])"},"schema":{"year":{"type":"integer","min":2000},"month":{"type":"integer","min":1,"max":12}}}'
            ],
            [
                'method'  => 'GET',
                'pattern' => '/{page}',
                'handler' => 'Marques\\Core\\PageManager@getPage',
                'options' => '{"params":{"page":"[a-z0-9-]+"},"schema":{"page":{"type":"string","pattern":"/^[a-z0-9-]+$/"}}}'
            ]
        ];

        $schemaUrlMapping = [
            'requiredFields' => ['method', 'pattern'],
            'fieldTypes' => [
                'method'  => 'string',
                'pattern' => 'string',
                'handler' => 'string',
                'options' => 'string'
            ]
        ];

        $this->db->registerTable(self::TABLE_URLMAPPING);
        $table = $this->db->table(self::TABLE_URLMAPPING);

        foreach ($defaultUrlMappingRoutes as $index => $record) {
            // Record-ID als int: 1, 2, 3, ...
            $recordId = $index + 1;
            if ($table->selectRecord($recordId) === null) {
                $record['id'] = $recordId;
                if (!$table->insertRecord($record, $recordId)) {
                    throw new RuntimeException("Standarddaten für Tabelle '".self::TABLE_URLMAPPING."' konnten nicht eingefügt werden.");
                }
            }
        }
    }

    private function initializeUserTable(): void {
        // Mehrere User-Einträge, hier zunächst nur der "admin"
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

        $this->db->registerTable(self::TABLE_USER);
        $table = $this->db->table(self::TABLE_USER);

        foreach ($defaultUser as $index => $record) {
            // Record-ID als int: 1, 2, 3, ...
            $recordId = $index + 1;
            if ($table->selectRecord($recordId) === null) {
                $record['id'] = $recordId;
                if (!$table->insertRecord($record, $recordId)) {
                    throw new RuntimeException("Standarddaten für Tabelle '".self::TABLE_USER."' konnten nicht eingefügt werden.");
                }
            }
        }
    }

    /**
     * Initialisiert einen einzelnen Datensatz in einer Tabelle mithilfe eines Schemas.
     * Hier wird davon ausgegangen, dass $defaultData ein assoziatives Array für einen einzelnen Datensatz ist.
     */
    private function initializeTableWithSchema(string $tableName, array $defaultData, int $recordId, array $schema): void {
        $this->db->registerTable($tableName);
        $table = $this->db->table($tableName);
        $table->setSchema($schema['requiredFields'] ?? [], $schema['fieldTypes'] ?? []);
        if ($table->selectRecord($recordId) === null) {
            $defaultData['id'] = $recordId;
            if (!$table->insertRecord($defaultData, $recordId)) {
                throw new RuntimeException("Standarddaten für Tabelle '{$tableName}' konnten nicht eingefügt werden.");
            }
        }
    }

    public function runCronJob(): void {
        $lastCompaction = $this->getLastCompactionTime();
        if (time() - $lastCompaction >= $this->compactionInterval) {
            $this->db->compactAllTables();
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

    public function getSetting(string $key, $default = null) {
        $table = $this->db->table(self::TABLE_SETTINGS);
        // Hier verwenden wir nun die Record-ID 1 für die Settings
        $this->settingsCache = $table->selectRecord(1) ?? [];
        return array_key_exists($key, $this->settingsCache) ? $this->settingsCache[$key] : $default;
    }

    public function getAllSettings(): array {
        $table = $this->db->table(self::TABLE_SETTINGS);
        $record = $table->selectRecord(1);
        return is_array($record) ? $record : [];
    }

    public function setSetting(string $key, $value): bool {
        $table = $this->db->table(self::TABLE_SETTINGS);
        $record = $table->selectRecord(1) ?? [];
        $record[$key] = $value;
        if (isset($record['id'])) {
            $result = $table->updateRecord(1, $record);
        } else {
            $record['id'] = 1;
            $result = $table->insertRecord($record, 1);
        }
        if ($result) {
            $this->settingsCache = $record;
        }
        return $result;
    }

    public function getTable(string $tableName) {
        return $this->db->table($tableName);
    }
}
