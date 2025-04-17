<?php
/**
 * Verbesserter PSR-4-konformer PHP-Autoloader mit angepasster Namenskonvention
 *
 * Eine sichere und robuste Implementierung eines PSR-4-konformen Autoloaders
 * mit konfigurierbaren Namespace-Mappings, verbesserter Fehlerbehandlung und
 * Schutz gegen Path-Traversal-Angriffe.
 * 
 * Spezielle Namenskonvention:
 * - Ordnernamen werden kleingeschrieben (z.B. user/, admin/)
 * - Dateinamen bleiben in CamelCase (z.B. Profile.php, Auth.php)
 */

namespace Marques\Core;

class Autoloader
{
    /** @var array Mapping von Namespaces zu Verzeichnissen */
    private $namespaceMap = [];
    
    /** @var bool Flag für das Logging von Fehlern */
    private $enableLogging = false;
    
    /** @var string Pfad zur Log-Datei */
    private $logFile = '';
    
    /** @var array Cache für bereits aufgelöste Klassenpfade */
    private static $classCache = [];
    
    /**
     * Konstruktor
     * 
     * @param array $namespaceMap Mapping von Namespaces zu Verzeichnissen
     * @param array $options Optionale Konfigurationsparameter
     */
    public function __construct(array $namespaceMap = [], array $options = [])
    {
        $this->namespaceMap = $namespaceMap;
        
        // Konfigurationsoptionen verarbeiten
        if (isset($options['logging']) && $options['logging'] === true) {
            $this->enableLogging = true;
            $this->logFile = $options['logFile'] ?? __DIR__ . '/autoloader.log';
        }
    }
    
    /**
     * Registriert diesen Autoloader beim SPL-Autoloader-Stack.
     * 
     * @return bool Erfolg der Registrierung.
     */
    public function register(): bool
    {
        return spl_autoload_register([$this, 'loadClass']);
    }
    
    /**
     * Entfernt diesen Autoloader vom SPL-Autoloader-Stack.
     * 
     * @return bool Erfolg der Entfernung.
     */
    public function unregister(): bool
    {
        return spl_autoload_unregister([$this, 'loadClass']);
    }
    
    /**
     * Fügt ein Namespace-Mapping hinzu.
     * 
     * @param string $namespace Namespace-Präfix.
     * @param string $baseDir Basis-Verzeichnis.
     * @return $this
     */
    public function addNamespace(string $namespace, string $baseDir)
    {
        // Sicherstellen, dass der Namespace mit einem Backslash endet.
        $namespace = trim($namespace, '\\') . '\\';
        
        // Sicherstellen, dass das Verzeichnis mit einem Slash endet.
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        
        // Verzeichnispfad normalisieren.
        $baseDir = $this->normalizePath($baseDir);
        
        // Mapping hinzufügen.
        $this->namespaceMap[$namespace] = $baseDir;
        
        return $this;
    }
    
    /**
     * Lädt eine Klasse.
     * 
     * @param string $className Vollständiger Klassenname mit Namespace.
     * @return bool Erfolg des Ladens.
     */
    public function loadClass(string $className): bool
    {
        // Überprüfen, ob die Klasse bereits geladen wurde.
        if (isset(self::$classCache[$className])) {
            if (self::$classCache[$className] !== false) {
                require_once self::$classCache[$className];
                return true;
            }
            return false;
        }
        
        // Nach passendem Namespace suchen.
        foreach ($this->namespaceMap as $namespace => $baseDir) {
            // Prüfen, ob der Klassenname mit dem Namespace-Präfix beginnt.
            if (strpos($className, $namespace) === 0) {
                // Relativen Klassenpfad extrahieren.
                $relativeClass = substr($className, strlen($namespace));
                
                // Versuchen, die Datei zu laden.
                $filePath = $this->getFilePath($baseDir, $relativeClass);
                
                if ($filePath !== false) {
                    // Datei im Cache speichern und laden.
                    self::$classCache[$className] = $filePath;
                    require_once $filePath;
                    return true;
                }
            }
        }
        
        // Klasse nicht gefunden – im Cache speichern, um zukünftige Lookups zu vermeiden.
        self::$classCache[$className] = false;
        
        if ($this->enableLogging) {
            $this->log("Klasse nicht gefunden: $className");
        }
        
        return false;
    }
    
    /**
     * Konvertiert relativen Klassennamen in einen Dateipfad.
     * 
     * @param string $baseDir Basis-Verzeichnis.
     * @param string $relativeClass Relativer Klassenname.
     * @return string|false Dateipfad oder false bei Fehler.
     */
    protected function getFilePath(string $baseDir, string $relativeClass)
    {
        $parts     = explode('\\', $relativeClass);
        $className = array_pop($parts);
    
        // Unterordner laut Konvention kleingeschrieben
        $dirPath = $parts
            ? strtolower(implode(DIRECTORY_SEPARATOR, $parts)) . DIRECTORY_SEPARATOR
            : '';
    
        $candidate          = $baseDir . $dirPath . $className . '.php';
        $normalizedPath     = $this->normalizePath($candidate);
        $normalizedBaseDir  = $this->normalizePath($baseDir);
    
        // Path‑Traversal‑Check
        if (strpos($normalizedPath, $normalizedBaseDir) !== 0) {
            if ($this->enableLogging) {
                $this->log("Path‑Traversal‑Versuch erkannt: $candidate");
            }
            return false;
        }
    
        return file_exists($normalizedPath) ? $normalizedPath : false;
    }
    
    /**
     * Normalisiert einen Dateipfad.
     * 
     * @param string $path Dateipfad.
     * @return string Normalisierter Pfad.
     */
    protected function normalizePath(string $path): string
    {
        $originalPath = $path;
        $resolvedPath = realpath($path);
        
        if ($resolvedPath !== false) {
            return $resolvedPath;
        }
        
        // Fallback-Normalisierung, falls realpath fehlschlägt.
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $originalPath);
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $absolutes = [];
        
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        
        $resolvedPath = implode(DIRECTORY_SEPARATOR, $absolutes);
        
        // Falls der ursprüngliche Pfad mit einem Slash begann, diesen wieder hinzufügen.
        if (strpos($originalPath, DIRECTORY_SEPARATOR) === 0) {
            $resolvedPath = DIRECTORY_SEPARATOR . $resolvedPath;
        }
        
        return $resolvedPath;
    }
    
    /**
     * Schreibt eine Nachricht ins Log.
     * 
     * @param string $message Nachricht.
     * @return void
     */
    protected function log(string $message): void
    {
        if (!$this->enableLogging) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] $message" . PHP_EOL;
        
        try {
            file_put_contents($this->logFile, $entry, FILE_APPEND);
        } catch (\Exception $e) {
            // Logging-Fehler werden stillschweigend ignoriert, um den Ablauf nicht zu unterbrechen.
        }
    }
    
    /**
     * Leert den Klassenpfad-Cache.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$classCache = [];
    }
}

// Beispiel für die Verwendung des verbesserten Autoloaders:
/*
// Konfigurationsdatei laden
$config = require __DIR__ . '/config/autoloader.php';

// Autoloader instanziieren
$autoloader = new \Marques\Core\Autoloader($config['namespaceMap'], [
    'logging' => true,
    'logFile' => __DIR__ . '/logs/autoloader.log'
]);

// Autoloader registrieren
$autoloader->register();

// Alternativ: Namespaces einzeln hinzufügen
$autoloader = new \Marques\Core\Autoloader([], ['logging' => true]);
$autoloader->addNamespace('Marques', __DIR__ . '/lib')
           ->addNamespace('Marques\\Admin', __DIR__ . '/admin/lib')
           ->addNamespace('FlatFileDB', __DIR__ . '/lib/flatfiledb')
           ->register();
*/

// Beispieldatei für: config/autoloader.php
/*
return [
    'namespaceMap' => [
        'Marques'         => __DIR__ . '/../lib',
        'Marques\\Admin'  => __DIR__ . '/../admin/lib',
        'FlatFileDB'      => __DIR__ . '/../lib/flatfiledb'
    ]
];
*/