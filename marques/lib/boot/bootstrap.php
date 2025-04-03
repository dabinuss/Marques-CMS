<?php
declare(strict_types=1);

// Definiere den Root-Pfad (anpassen, falls nötig)
define('MARQUES_ROOT_DIR', realpath(__DIR__ . '/../../'));
define('MARQUES_ADMIN_DIR', MARQUES_ROOT_DIR . '/admin');

//var_dump(MARQUES_ADMIN_DIR .'<br><br>'.MARQUES_ROOT_DIR.'<br><br>');

// Konstanten (gemeinsam für beide Bereiche)
define('MARQUES_VERSION', '0.3.0');

// Other
define('MARQUES_SYSTEM_DIR', MARQUES_ROOT_DIR . '/lib');
define('MARQUES_CONFIG_DIR', MARQUES_ROOT_DIR . '/config');
define('MARQUES_CONTENT_DIR', MARQUES_ROOT_DIR . '/content');
define('MARQUES_CACHE_DIR', MARQUES_SYSTEM_DIR . '/cache');
define('MARQUES_THEMES_DIR', MARQUES_ROOT_DIR . '/themes');

// Autoloader laden
require_once MARQUES_ROOT_DIR . '/lib/boot/spl_autoload_register.php';

use Marques\Core\Node;
use Marques\Data\Database\Config as DatabaseConfig;
use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Util\ExceptionHandler;
use Marques\Core\Logger;
use Marques\Service\Content;

// Erstelle den Root-Container und registriere gemeinsame Services
$rootContainer = new Node();

// Zuerst kritische Dienste registrieren, die für Exception-Handling benötigt werden

// Logger registrieren (für Exception-Handler benötigt)
$rootContainer->register(Logger::class, function(Node $container) {
    return new Logger();
});

// Datenbank-Dienste registrieren
$rootContainer->register(\FlatFileDB\FlatFileDatabase::class, function(Node $container) {
    return new \FlatFileDB\FlatFileDatabase(MARQUES_ROOT_DIR . '/data');
});

$rootContainer->register(\FlatFileDB\FlatFileDatabaseHandler::class, function(Node $container) {
    $dbInstance = $container->get(\FlatFileDB\FlatFileDatabase::class);
    return new \FlatFileDB\FlatFileDatabaseHandler($dbInstance);
});

$rootContainer->register(DatabaseHandler::class, function(Node $container) {
    $dbInstance = $container->get(\FlatFileDB\FlatFileDatabase::class);
    $libraryHandler = $container->get(\FlatFileDB\FlatFileDatabaseHandler::class);
    return new DatabaseHandler($dbInstance, $libraryHandler);
});

// Versuche, den Debug-Modus aus der Datenbank zu laden
$debugMode = false; // Standard: Debug-Modus aus (sicherer Standard)

try {
    /** @var DatabaseHandler $dbHandler */
    $dbHandler = $rootContainer->get(DatabaseHandler::class);
    
    // Einstellungen aus der Datenbank laden
    $settings = $dbHandler->table('settings')->where('id', '=', 1)->first();
    if ($settings && isset($settings['debug'])) {
        $debugMode = (bool)$settings['debug'];
    }
} catch (\Exception $e) {
    // Bei Fehler beim Laden der Einstellungen: standard-debugMode verwenden
    // Hier können wir noch nicht loggen, da wir den Exception-Handler erst noch einrichten
    error_log("Konnte Debug-Modus nicht aus Datenbank laden: " . $e->getMessage());
}

// Jetzt den Exception-Handler registrieren
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

// Rest der Container-Registrierungen
$rootContainer->register(DatabaseConfig::class, function(Node $container) {
    $dbHandler = $container->get(DatabaseHandler::class);
    return new DatabaseConfig(
        $dbHandler,
        MARQUES_ROOT_DIR . '/data'
    );
});

$rootContainer->register(\Marques\Core\Path::class, function(Node $container) {
    return new \Marques\Core\Path();
});

$rootContainer->register(\Marques\Util\Helper::class, function(Node $container) {
    return new \Marques\Util\Helper($container->get(DatabaseHandler::class));
});

$rootContainer->register(\Marques\Core\Events::class, function(Node $container) {
    return new \Marques\Core\Events();
});

$rootContainer->register(\Marques\Core\Cache::class, function(Node $container) {
    return new \Marques\Core\Cache();
});

$rootContainer->register(\Marques\Data\FileManager::class, function(Node $container) {
    return new \Marques\Data\FileManager($container->get(\Marques\Core\Cache::class), MARQUES_CONTENT_DIR);
});

$rootContainer->register(\Marques\Service\ThemeManager::class, function(Node $container) {
    return new \Marques\Service\ThemeManager($container->get(DatabaseHandler::class));
});

$rootContainer->register(\Marques\Service\NavigationManager::class, function(Node $container) {
    return new \Marques\Service\NavigationManager($container->get(DatabaseHandler::class));
});

$rootContainer->register(\Marques\Service\User::class, function(Node $container) {
    return new \Marques\Service\User($container->get(DatabaseHandler::class));
});

$rootContainer->register(\Marques\Service\Content::class, function(Node $container) {
    return new \Marques\Service\Content(
        $container->get(DatabaseHandler::class),
        $container->get(\Marques\Data\FileManager::class),
        $container->get(\Marques\Util\Helper::class)
    );
});

$rootContainer->register(\Marques\Http\Router::class, function(Node $container) {
    return new \Marques\Http\Router($container, $container->get(DatabaseHandler::class));
});

$rootContainer->register(\Marques\Core\Template::class, function(Node $container) {
    return new \Marques\Core\Template(
        $container->get(DatabaseHandler::class),
        $container->get(\Marques\Service\ThemeManager::class),
        $container->get(\Marques\Core\Path::class),
        $container->get(\Marques\Core\Cache::class),
        $container->get(\Marques\Util\Helper::class)
    );
});

$rootContainer->register(\Marques\Service\BlogManager::class, function(Node $container) {
    return new \Marques\Service\BlogManager(
        $container->get(DatabaseHandler::class),
        $container->get(\Marques\Data\FileManager::class),
        $container->get(\Marques\Util\Helper::class)
    );
});

// Kritische Initialisierung versuchen
try {
    $rootContainer->get(DatabaseConfig::class);
} catch (\Exception $e) {
    // Diese Exception wird vom neuen Handler aufgefangen werden,
    // aber wir stellen sicher, dass wir einen sauberen Fehler zurückgeben
    throw new \RuntimeException(
        "Ein kritischer Fehler bei der Datenbankinitialisierung ist aufgetreten: " . $e->getMessage(),
        500
    );
}

// Rückgabe des Root-Containers
return $rootContainer;