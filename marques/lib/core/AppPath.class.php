<?php
declare(strict_types=1);

namespace Marques\Core;

class AppPath
{
    /**
     * Die einzige Instanz der Klasse.
     *
     * @var AppPath|null
     */
    private static ?AppPath $instance = null;

    /**
     * Array mit allen wichtigen Pfaden des Projekts.
     *
     * @var array
     */
    protected array $paths = [];

    /**
     * Privater Konstruktor: Initialisiert die Pfade.
     */
    private function __construct()
    {
        $this->initializePaths();
    }

    /**
     * Gibt die einzige Instanz von AppPath zurück.
     *
     * @return AppPath
     */
    public static function getInstance(): AppPath
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialisiert die Standardpfade des Projekts.
     *
     * Hier wird der Root-Pfad anhand der Projektstruktur bestimmt.
     */
    protected function initializePaths(): void
    {
        // Angenommen, der Root-Ordner liegt zwei Ebenen oberhalb dieser Klasse:
        $root = dirname(__DIR__, 2);
        if (!$root || !is_dir($root)) {
            throw new \Exception("Der Root-Pfad konnte nicht ermittelt werden.");
        }
        
        $this->paths = [
            'root'      => $root,
            'core'      => $root . '/lib/core',
            'logs'      => $root . '/logs',
            'config'    => $root . '/config',
            'content'   => $root . '/content',
            'templates' => $root . '/templates',
            'cache'     => $root . '/lib/cache',
            'admin'     => $root . '/admin',
            'themes'    => $root . '/themes',
        ];
    }

    /**
     * Gibt den Pfad zu einem definierten Schlüssel zurück.
     *
     * @param string $key Der Name des Pfads (z.B. "content", "system").
     * @return string
     * @throws \Exception Falls der Pfad nicht definiert ist.
     */
    public function getPath(string $key): string
    {
        if (!isset($this->paths[$key])) {
            throw new \Exception("Pfad für Schlüssel '{$key}' ist nicht definiert.");
        }
        return $this->paths[$key];
    }

    /**
     * Kombiniert einen Basis-Pfad mit einem relativen Unterpfad.
     *
     * Dabei werden potenzielle Sicherheitslücken (z.B. Directory-Traversal) minimiert.
     *
     * @param string $baseKey Schlüssel des Basis-Pfads.
     * @param string $subPath Relativer Unterpfad.
     * @return string Kombinierter Pfad.
     * @throws \Exception Falls der kombinierte Pfad außerhalb des Root-Verzeichnisses liegt
     *                    oder der Unterpfad ungültige Zeichen enthält.
     */
    public function combine(string $baseKey, string $subPath): string {
        // Hole den Basis-Pfad
        $basePath = $this->getPath($baseKey);
        
        // Validierung: Überprüfe, ob der Unterpfad nur erlaubte Zeichen enthält.
        if (!$this->isValidPath($subPath)) {
            throw new \Exception("Ungültiger Unterpfad: '{$subPath}'.");
        }
        
        // Verwende unseren sicheren Resolver, um den Pfad zusammenzusetzen
        $combined = $this->safeResolve($basePath, $subPath);
        
        return $combined;
    }

    /**
     * Prüft, ob ein Dateipfad bzw. Ordner existiert.
     *
     * @param string $key Basis-Pfad-Schlüssel.
     * @param string $subPath Optionaler relativer Unterpfad.
     * @return bool
     */
    public function exists(string $key, string $subPath = ''): bool
    {
        $path = $subPath ? $this->combine($key, $subPath) : $this->getPath($key);
        return file_exists($path);
    }

    /**
     * Setzt die Berechtigungen (chmod) für einen bestimmten Pfad.
     *
     * @param string $key Basis-Pfad-Schlüssel.
     * @param string $subPath Relativer Unterpfad.
     * @param int $permissions Die zu setzenden Berechtigungen (z.B. 0755).
     * @return bool
     */
    public function setPermissions(string $key, string $subPath, int $permissions): bool
    {
        $path = $this->combine($key, $subPath);
        return chmod($path, $permissions);
    }

    /**
     * Validiert einen Pfad-String anhand eines Regex.
     *
     * @param string $path Zu validierender Pfad.
     * @return bool
     */
    public function isValidPath(string $path): bool
    {
        // Erlaubt alphanumerische Zeichen, Bindestriche, Unterstriche, Schrägstriche und Punkte.
        return preg_match('/^[a-zA-Z0-9\-\_\/\.]*$/', $path) === 1;
    }

    /**
     * Gibt alle registrierten Pfade zurück.
     *
     * @return array
     */
    public function getAllPaths(): array
    {
        return $this->paths;
    }

    /**
     * Überprüft und bereitet einen Pfad (Verzeichnis oder Datei) vor.
     *
     * @param string $path Schlüssel für den Pfad
     * @param string $type 'directory' oder 'file'
     * @param int $permissions
     * @return string
     */
    public function preparePath(string $path, string $type = 'directory', int $permissions = 0755): string
    {
        // Validierung des Typs
        if (!in_array($type, ['directory', 'file'], true)) {
            throw new \InvalidArgumentException("Ungültiger Typ '{$type}'. Erlaubt sind 'directory' oder 'file'.");
        }
    
        // Bestimme den vollständigen Pfad anhand des übergebenen Schlüssels
        $fullPath = $this->getPath($path);
        
        // Bestimme das Zielverzeichnis:
        // - Bei "directory" wird der vollständige Pfad als Verzeichnis interpretiert.
        // - Bei "file" wird das übergeordnete Verzeichnis (dirname) genutzt.
        $directory = ($type === 'directory') ? $fullPath : dirname($fullPath);
        
        // Verzeichnis erstellen, falls es nicht existiert
        if (!is_dir($directory) && !mkdir($directory, $permissions, true)) {
            throw new \RuntimeException("Das Verzeichnis '{$directory}' konnte nicht erstellt werden.");
        }
        
        // Überprüfe, ob das Verzeichnis beschreibbar ist
        if (!is_writable($directory)) {
            throw new \RuntimeException("Das Verzeichnis '{$directory}' ist nicht beschreibbar.");
        }
        
        // Bei Dateityp: Datei erstellen (falls sie nicht existiert), Berechtigungen setzen und Schreibbarkeit prüfen
        if ($type === 'file') {
            if (!file_exists($fullPath)) {
                if (false === touch($fullPath)) {
                    throw new \RuntimeException("Die Datei '{$fullPath}' konnte nicht erstellt werden.");
                }
            }
            
            if (!chmod($fullPath, $permissions)) {
                throw new \RuntimeException("Die Berechtigungen für die Datei '{$fullPath}' konnten nicht gesetzt werden.");
            }
            
            if (!is_writable($fullPath)) {
                throw new \RuntimeException("Die Datei '{$fullPath}' ist nicht beschreibbar.");
            }
        }
        
        return $fullPath;
    }

    /**
     * Löst einen relativen Pfad sicher auf, ohne realpath() zu verwenden.
     *
     * Dabei wird der Pfad in seine Komponenten zerlegt, '.' und '..'
     * werden korrekt interpretiert und es wird überprüft, dass der
     * resultierende Pfad im Basisverzeichnis verbleibt.
     *
     * @param string $base Der absolute Basis-Pfad (ohne nachgestellten Schrägstrich)
     * @param string $relative Der relative Pfad, der aufgelöst werden soll
     * @return string Der aufgelöste absolute Pfad
     * @throws \Exception Falls der Pfad versucht, das Basisverzeichnis zu verlassen.
     */
    protected function safeResolve(string $base, string $relative): string {
        // Wenn der relative Pfad leer ist, gebe einfach den Basis-Pfad zurück.
        if (trim($relative) === '') {
            return rtrim($base, DIRECTORY_SEPARATOR);
        }
        
        // Entferne potenzielle Null-Bytes (Sicherheitsmaßnahme)
        $relative = str_replace("\0", '', $relative);
        
        // Entferne führende Schrägstriche, damit der Pfad wirklich relativ ist
        $relative = ltrim($relative, '/\\');
        
        // Zerlege den relativen Pfad in seine Segmente
        $parts = preg_split('#[\\\\/]+#', $relative);
        $safeParts = [];
        
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (count($safeParts) > 0) {
                    array_pop($safeParts);
                } else {
                    throw new \Exception("Ungültiger Pfad: Versuch, außerhalb des Basisverzeichnisses zu gelangen.");
                }
            } else {
                $safeParts[] = $part;
            }
        }
        
        $normalizedRelative = implode(DIRECTORY_SEPARATOR, $safeParts);
        $resolved = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedRelative;
        
        $baseNormalized = rtrim($base, DIRECTORY_SEPARATOR);
        if (strncmp($resolved, $baseNormalized, strlen($baseNormalized)) !== 0) {
            throw new \Exception("Sicherheitsverstoß: Der aufgelöste Pfad verlässt das Basisverzeichnis.");
        }
        
        return $resolved;
    }
}
