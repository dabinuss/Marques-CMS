<?php
declare(strict_types=1);

// Definiere den Root-Pfad (anpassen, falls nötig)
define('MARQUES_ROOT_DIR', realpath(__DIR__ . '/../../'));
define('MARQUES_ADMIN_DIR', MARQUES_ROOT_DIR . '/admin/');

// Konstanten (gemeinsam für beide Bereiche)
define('MARQUES_VERSION', '0.3.0');

// Other
define('MARQUES_SYSTEM_DIR', MARQUES_ROOT_DIR . '/lib/');
define('MARQUES_CONTENT_DIR', MARQUES_ROOT_DIR . '/content/');
define('MARQUES_CACHE_DIR', MARQUES_SYSTEM_DIR . '/cache/');
define('MARQUES_THEMES_DIR', MARQUES_ROOT_DIR . '/themes/');

// ---------------------------------------------------------------------------
// SAFE IMPLODE

if (!function_exists('safe_implode')) {
    function safe_implode(string $glue, $array): string {
        if (!is_array($array)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[1] ?? $trace[0];
            $file = $caller['file'] ?? 'unbekannte Datei';
            $line = $caller['line'] ?? 'unbekannte Zeile';
            throw new Exception("safe_implode erwartet ein Array, aber " . gettype($array) . " übergeben in $file, Zeile $line.");
        }
        return implode($glue, $array);
    }
}

// ---------------------------------------------------------------------------
// Autoloader integrieren
// Passe den Pfad zur Autoloader-Datei an, falls er anders liegt
require_once MARQUES_SYSTEM_DIR . '/boot/Autoloader.php';

use Marques\Core\Autoloader;

// Definiere das Mapping der Namespaces zu den entsprechenden Basis-Verzeichnissen
$namespaceMap = [
    'Marques\\'        => MARQUES_ROOT_DIR . '/lib/',
    'Admin\\' => MARQUES_ROOT_DIR . '/admin/lib/',
    'FlatFileDB\\'     => MARQUES_SYSTEM_DIR . '/flatfiledb/'
];

// Autoloader instanziieren und registrieren
$autoloader = new Autoloader($namespaceMap, [
    'logging' => true,
    'logFile' => MARQUES_ROOT_DIR . '/logs/autoloader.log'
]);
$autoloader->register();

// ---------------------------------------------------------------------------
// Nun folgt der Rest deiner Bootstrap-Konfiguration

use Marques\Core\Node;
use Marques\Data\Database\Config as DatabaseConfig;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Util\ExceptionHandler;
use Marques\Core\Logger;
use Marques\Filesystem\PathRegistry;
use Marques\Core\Statistics;
use Marques\Core\Cache;
use Marques\Filesystem\FileManager;

// Erstelle den Root-Container und registriere gemeinsame Services
$rootContainer = new Node();

$rootContainer->register(PathRegistry::class, function () {
    return new PathRegistry();          // keine Abhängigkeiten
});

// Logger registrieren (für Exception-Handler benötigt)
$rootContainer->register(Logger::class, function (Node $c) {
    return new Logger($c->get(PathRegistry::class));
});

// FlatFileDatabase-Instanz registrieren
$rootContainer->register(\FlatFileDB\FlatFileDatabase::class, function(Node $container) {
    return new \FlatFileDB\FlatFileDatabase(MARQUES_ROOT_DIR . '/data');
});

$rootContainer->register(Statistics::class, function (Node $c) {
    return new Statistics($c->get(PathRegistry::class));
});

// FlatFileDatabaseHandler-Instanz registrieren
$rootContainer->register(\FlatFileDB\FlatFileDatabaseHandler::class, function(Node $container) {
    $dbInstance = $container->get(\FlatFileDB\FlatFileDatabase::class);
    return new \FlatFileDB\FlatFileDatabaseHandler($dbInstance);
});

// Eigenen DatabaseHandler registrieren
$rootContainer->register(DatabaseHandler::class, function(Node $container) {
    $dbInstance = $container->get(\FlatFileDB\FlatFileDatabase::class);
    $libraryHandler = $container->get(\FlatFileDB\FlatFileDatabaseHandler::class);
    return new DatabaseHandler($dbInstance, $libraryHandler);
});

// Versuche, den Debug-Modus zu bestimmen (Default: true während Entwicklung)
$debugMode = true; // Während der Entwicklung standardmäßig auf true setzen

// Exception-Handler registrieren
try {
    $logger = $rootContainer->get(Logger::class);
    $exceptionHandler = new ExceptionHandler($debugMode, $logger);
    $exceptionHandler->register();
} catch (\Exception $e) {
    // Fallback, wenn Logger nicht verfügbar
    $exceptionHandler = new ExceptionHandler($debugMode);
    $exceptionHandler->register();
    error_log("Fehler beim Einrichten des Exception-Handlers: " . $e->getMessage());
}

// Ergänzen mit:
$rootContainer->register(ExceptionHandler::class, function() use ($exceptionHandler) {
    return $exceptionHandler;
});

// Konfiguration initialisieren
$rootContainer->register(DatabaseConfig::class, function(Node $container) {
    $dbHandler = $container->get(DatabaseHandler::class);
    return new DatabaseConfig(
        $dbHandler,
        MARQUES_ROOT_DIR . '/data'
    );
});

// Jetzt, wo die Konfiguration verfügbar ist, versuche Debug-Einstellungen zu laden
try {
    $config = $rootContainer->get(DatabaseConfig::class);
    $dbHandler = $rootContainer->get(DatabaseHandler::class);
    
    // Einstellungen aus der Datenbank laden
    $settings = $dbHandler->table('settings')->where('id', '=', 1)->first();
    if ($settings && isset($settings['debug'])) {
        $debugMode = (bool)$settings['debug'];
        // Exception-Handler aktualisieren mit dem neuen Debug-Modus
        $exceptionHandler = $rootContainer->get(ExceptionHandler::class);
        if (method_exists($exceptionHandler, 'setDebugMode')) {
            $exceptionHandler->setDebugMode($debugMode);
        }
    }
} catch (\Exception $e) {
    error_log("Konnte Debug-Modus nicht aus Datenbank laden: " . $e->getMessage());
}

// Rest der Container-Registrierungen
$rootContainer->register(\Marques\Filesystem\PathRegistry::class, function(Node $c) {
    return new \Marques\Filesystem\PathRegistry();
});

$rootContainer->register(\Marques\Util\Helper::class, function(Node $container) {
    return new \Marques\Util\Helper($container->get(DatabaseHandler::class));
});

$rootContainer->register(\Marques\Core\Events::class, function(Node $container) {
    return new \Marques\Core\Events();
});

$rootContainer->register(Cache::class, function (Node $c) {
    return new Cache($c->get(PathRegistry::class));
});

$rootContainer->register(\Marques\Filesystem\FileManager::class, function (Node $c) {
    $cache  = $c->get(\Marques\Core\Cache::class);

    $knownDirectories  = [
        'content'            => MARQUES_CONTENT_DIR,
        'themes'             => MARQUES_THEMES_DIR,
        'admin'              => MARQUES_ADMIN_DIR,
        'backend_templates'  => MARQUES_ADMIN_DIR . '/templates',
    ];

    return new \Marques\Filesystem\FileManager($cache, $knownDirectories);
});

$rootContainer->register(\Marques\Service\ThemeManager::class, function (Node $c) {
    return new \Marques\Service\ThemeManager(
        $c->get(DatabaseHandler::class),
        $c->get(PathRegistry::class),
        $c->get(\Marques\Filesystem\FileManager::class)
    );
});

$rootContainer->register(\Marques\Service\NavigationManager::class, function(Node $container) {
    return new \Marques\Service\NavigationManager($container->get(DatabaseHandler::class));
});

$rootContainer->register(\Marques\Service\User::class, function(Node $container) {
    return new \Marques\Service\User(
        $container->get(DatabaseHandler::class),
        $container->get(PathRegistry::class),
    );
});

$rootContainer->register(\Marques\Service\Content::class, function(Node $container) {
    return new \Marques\Service\Content(
        $container->get(DatabaseHandler::class),
        $container->get(\Marques\Filesystem\FileManager::class),
        $container->get(\Marques\Util\Helper::class),
        $container->get(\Marques\Filesystem\PathRegistry::class),
    );
});

$rootContainer->register(\Marques\Http\Router::class, function(Node $container) {
    return new \Marques\Http\Router($container, $container->get(DatabaseHandler::class));
});

$rootContainer->register(\Marques\Core\AssetManager::class, function(Node $container) {
    $helper = $container->get(\Marques\Util\Helper::class);
    $baseUrl = $helper->getSiteUrl();
    $version = MARQUES_VERSION;
    $devMode = true; // TODO: Standardmäßig auf true setzen, kann später angepasst werden
    
    return new \Marques\Core\AssetManager($baseUrl, $version, $devMode);
});

$rootContainer->register(\Marques\Core\TokenParser::class, function(Node $container) {
    return new \Marques\Core\TokenParser(
        $container->get(\Marques\Core\AssetManager::class)
    );
});

$rootContainer->register(\Marques\Core\Template::class, function(Node $container) {
    return new \Marques\Core\Template(
        $container->get(DatabaseHandler::class),
        $container->get(\Marques\Service\ThemeManager::class),
        $container->get(\Marques\Filesystem\PathRegistry::class),
        $container->get(\Marques\Core\Cache::class),
        $container->get(\Marques\Util\Helper::class),
        $container->get(\Marques\Core\TokenParser::class),
        $container->get(\Marques\Filesystem\FileManager::class),
    );
});

$rootContainer->register(\Marques\Service\BlogManager::class, function(Node $container) {
    return new \Marques\Service\BlogManager(
        $container->get(DatabaseHandler::class),
        $container->get(\Marques\Filesystem\FileManager::class),
        $container->get(\Marques\Util\Helper::class),
        $container->get(\Marques\Filesystem\PathRegistry::class),
    );
});

// Rückgabe des Root-Containers
return $rootContainer;
