<?php
declare(strict_types=1);

/* -----------------------------------------------------------------
 * 0) Root‑Pfad ermitteln (mit Fallback)
 * ----------------------------------------------------------------- */
$root = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
define('MARQUES_ROOT_DIR', $root);   // if‑Abfrage entfernt

/* -----------------------------------------------------------------
 * 1) Basiskonstanten
 * ----------------------------------------------------------------- */
define('MARQUES_SYSTEM_DIR', MARQUES_ROOT_DIR . '/lib');   //  <‑‑ wieder da
define('MARQUES_ADMIN_DIR', MARQUES_ROOT_DIR . '/admin');
define('MARQUES_VERSION',  '0.3.0');

/* -----------------------------------------------------------------
 * 2) SAFE_IMPLDODE: Sicheres Implodieren von Arrays
 * ----------------------------------------------------------------- */
 if (!function_exists('safe_implode')) {
    function safe_implode(string $glue, $array): string {
        if (!is_array($array)) {
            $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[1] ?? $trace[0];
            $file   = $caller['file'] ?? 'unbekannte Datei';
            $line   = $caller['line'] ?? 'unbekannte Zeile';
            throw new \Exception(           //  <-- Backslash
                "safe_implode erwartet ein Array, aber "
                . gettype($array) . " übergeben in $file, Zeile $line."
            );
        }
        return implode($glue, $array);
    }
}

/* -----------------------------------------------------------------
 * 3) Autoloader laden
 * ----------------------------------------------------------------- */
require_once MARQUES_SYSTEM_DIR . '/boot/Autoloader.php';  //  Fix

use Marques\Core\Autoloader;

$namespaceMap = [
    'Marques\\'    => MARQUES_SYSTEM_DIR . '/',            //  Fix
    'Admin\\'      => MARQUES_ADMIN_DIR . '/lib/',         //  kein doppelter Slash
    'FlatFileDB\\' => MARQUES_SYSTEM_DIR . '/flatfiledb/', //  Fix
];

$autoloader = new Autoloader($namespaceMap, [
    'logging' => true,
    'logFile' => MARQUES_ROOT_DIR . '/logs/autoloader.log',
]);
$autoloader->register();

/* -----------------------------------------------------------------
 * 4) PathRegistry initialisieren + Legacy-Konstanten spiegeln
 * ----------------------------------------------------------------- */
$paths = new Marques\Filesystem\PathRegistry();

foreach ([
    'admin'   => 'MARQUES_ADMIN_DIR',
    'content' => 'MARQUES_CONTENT_DIR',
    'themes'  => 'MARQUES_THEMES_DIR',
    'cache'   => 'MARQUES_CACHE_DIR',
] as $key => $const) {
    if (!defined($const)) {
        define($const, rtrim($paths->getPath($key), '/') . '/');
    }
}

// ---------------------------------------------------------------------------

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

$rootContainer->register(PathRegistry::class, static fn () => $paths);

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
    return new \Marques\Filesystem\FileManager(
        $c->get(\Marques\Core\Cache::class),
        $c->get(PathRegistry::class)          // <‑‑ neue Dependency
    );
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
    
    return new \Marques\Core\AssetManager(
        $container->get(\Marques\Core\Cache::class), 
        $baseUrl, 
        $version, 
        $devMode, 
        $container->get(\Marques\Filesystem\PathRegistry::class));
});

$rootContainer->register(\Marques\Core\TokenParser::class, function(Node $container) {
    // Hole die benötigten Abhängigkeiten (Cache und AssetManager) aus dem Container
    $cache = $container->get(\Marques\Core\Cache::class);
    $assetManager = $container->get(\Marques\Core\AssetManager::class);

    // Erstelle TokenParser mit den Abhängigkeiten
    return new \Marques\Core\TokenParser($cache, $assetManager);
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
